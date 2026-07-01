<?php
/**
 * External-video downloader/importer.
 * CLI only. Intended to run as a non-root user inside the ClipBucket container.
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit("CLI only\n"); }
if (function_exists('posix_geteuid') && posix_geteuid() === 0) { fwrite(STDERR, "Refusing to run as root\n"); exit(2); }
const THIS_PAGE = 'external_download_worker';
$in_bg_cron = true;
require_once dirname(__DIR__, 3) . '/upload/includes/admin_config.php';
require_once __DIR__ . '/../lib/external_videos_lib.php';

$opts = getopt('', ['limit::','download-only','import-only','keep-temp','dry-run']);
$limit = max(1, min(5, (int)($opts['limit'] ?? 2)));
$downloadOnly = array_key_exists('download-only', $opts);
$importOnly = array_key_exists('import-only', $opts);
$keepTemp = array_key_exists('keep-temp', $opts);
$dryRun = array_key_exists('dry-run', $opts);
$queueDir = getenv('EXTERNAL_VIDEO_QUEUE_DIR') ?: '/var/media_import_queue';
$allowedExt = ['mp4','webm','mov','mkv','m3u8'];
$blockedExt = ['php','phtml','phar','html','htm','js','sh','pl','py','cgi','exe','bin'];

function evw_db(): mysqli { return ev_db(); }
function evw_log(int $id, string $level, string $msg, array $ctx=[]): void {
    $safeCtx = $ctx ? json_encode($ctx, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : null;
    $st = evw_db()->prepare('INSERT INTO cb_external_video_download_logs (external_video_id, level, message, context) VALUES (?,?,?,?)');
    $st->bind_param('isss', $id, $level, $msg, $safeCtx);
    $st->execute();
    echo '['.date('c')."] [$level] #$id $msg\n";
}
function evw_fail(array $v, string $msg): void {
    $id = (int)$v['id'];
    $st = evw_db()->prepare("UPDATE cb_external_videos SET download_status='failed', download_error=?, updated_at=NOW() WHERE id=?");
    $st->bind_param('si', $msg, $id);
    $st->execute();
    evw_log($id, 'error', $msg);
}
function evw_safe_source(string $url): bool { return ev_safe_url($url); }
function evw_allowed_download_url(string $url, array $allowedExt, array $blockedExt): array {
    if (!ev_safe_url($url)) return [false, 'Only http/https download URLs are allowed'];
    $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?: '');
    if (!in_array($scheme, ['http','https'], true)) return [false, 'Unsafe download URL protocol'];
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, $blockedExt, true)) return [false, 'Blocked file extension in download URL: '.$ext];
    if (!in_array($ext, $allowedExt, true)) return [false, 'Download URL is not an allowed direct video file: '.($ext ?: 'no extension')];
    return [true, $ext];
}
function evw_absolute_url(string $base, string $href): string {
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($href === '') return '';
    if (preg_match('~^https?://~i', $href)) return $href;
    if (str_starts_with($href, '//')) return (parse_url($base, PHP_URL_SCHEME) ?: 'https') . ':' . $href;
    $p = parse_url($base);
    if (!$p || empty($p['scheme']) || empty($p['host'])) return '';
    $root = $p['scheme'].'://'.$p['host'].(!empty($p['port'])?':'.$p['port']:'');
    if (str_starts_with($href, '/')) return $root.$href;
    $dir = preg_replace('~/[^/]*$~', '/', $p['path'] ?? '/');
    return $root.$dir.$href;
}
function evw_discover_download_url(array $v, array $allowedExt, array $blockedExt): array {
    $id = (int)$v['id'];
    if (!empty($v['download_url'])) {
        [$ok, $reason] = evw_allowed_download_url($v['download_url'], $allowedExt, $blockedExt);
        if ($ok) return [true, $v['download_url'], 'stored download_url'];
        evw_log($id, 'warning', 'Stored download_url rejected', ['reason'=>$reason]);
    }
    $detail = (string)($v['source_url'] ?? '');
    if (!ev_safe_url($detail)) return [false, '', 'Invalid detail/source URL'];
    $cmd = 'curl --fail --location --proto =http,https --max-redirs 3 --connect-timeout 15 --max-time 35 --range 0-1048575 --silent --show-error '.escapeshellarg($detail).' 2>&1';
    exec($cmd, $out, $code);
    if ($code !== 0) return [false, '', 'Unable to fetch detail page for download-link discovery'];
    $html = implode("\n", $out);
    $candidates = [];
    if (preg_match_all('~<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $href = evw_absolute_url($detail, $m[1]);
            $text = trim(strip_tags($m[2]));
            if ($href === '') continue;
            $looksDownload = preg_match('~下载|download|\.mp4(?:\?|$)|\.webm(?:\?|$)|\.mov(?:\?|$)|\.mkv(?:\?|$)|\.m3u8(?:\?|$)~i', $text.' '.$href);
            if (!$looksDownload) continue;
            [$ok, $reason] = evw_allowed_download_url($href, $allowedExt, $blockedExt);
            if ($ok) $candidates[] = [$href, $text ?: 'direct video link'];
            else evw_log($id, 'warning', 'Rejected discovered download candidate', ['url'=>$href, 'reason'=>$reason]);
        }
    }
    if (!$candidates) {
        if (preg_match('~https?://[^\"\'\s<>]+\.m3u8(?:\?[^\"\'\s<>]*)?~i', $html, $hm)) {
            $hls = html_entity_decode($hm[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            [$ok, $reason] = evw_allowed_download_url($hls, $allowedExt, $blockedExt);
            if ($ok) { $candidates[] = [$hls, 'public HLS manifest URL in detail page']; }
            else { return [false, '', $reason]; }
        } else {
            return [false, '', 'No public direct mp4/webm/mov/mkv/m3u8 download link found on detail page'];
        }
    }
    $url = $candidates[0][0];
    $st = evw_db()->prepare('UPDATE cb_external_videos SET download_url=?, updated_at=NOW() WHERE id=?');
    $st->bind_param('si', $url, $id);
    $st->execute();
    return [true, $url, 'discovered from detail page: '.$candidates[0][1]];
}

function evw_validate_hls_manifest(string $url, int $id): array {
    if (!ev_safe_url($url)) return [false, 'Invalid HLS URL'];
    $cmd = 'curl --fail --location --proto =http,https --max-redirs 3 --connect-timeout 15 --max-time 30 --range 0-1048576 --silent --show-error '.escapeshellarg($url).' 2>&1';
    exec($cmd, $out, $code);
    if ($code !== 0) return [false, 'Unable to fetch HLS manifest'];
    $manifest = implode("\n", $out);
    if (!str_contains($manifest, '#EXTM3U')) return [false, 'HLS URL did not return an m3u8 manifest'];
    if (preg_match('~#EXT-X-KEY\s*:\s*METHOD=(?!NONE)~i', $manifest)) return [false, 'Encrypted HLS manifests are not accepted'];
    if (preg_match('~URI=["\']?([^"\',\s]+)~i', $manifest, $m) && !str_starts_with($m[1], 'data:')) {
        evw_log($id, 'warning', 'HLS manifest references a key or external URI; refusing unless METHOD=NONE');
    }
    return [true, 'public unencrypted HLS manifest'];
}
function evw_download_hls_to_mp4(array $v, string $downloadUrl, string $queueDir, bool $dryRun=false): ?array {
    $id=(int)$v['id'];
    [$ok,$reason] = evw_validate_hls_manifest($downloadUrl, $id);
    if (!$ok) { evw_fail($v, $reason); return null; }
    if ($dryRun) return ['path'=>$queueDir.'/dry-run.mp4','ext'=>'mp4'];
    $final = $queueDir.'/'.evw_slug_file($v['slug'], $id, 'mp4');
    $tmp = $queueDir.'/.'.$id.'-'.bin2hex(random_bytes(4)).'.mp4';
    $referer = (string)($v['source_url'] ?? '');
    $cmd = 'ffmpeg -hide_banner -nostdin -y -protocol_whitelist file,http,https,tcp,tls,crypto -allowed_extensions ALL -headers '.escapeshellarg('Referer: '.$referer."\r\n").' -i '.escapeshellarg($downloadUrl).' -map 0:v:0? -map 0:a:0? -c copy -movflags +faststart -t 00:30:00 '.escapeshellarg($tmp).' 2>&1';
    exec($cmd, $out, $code);
    if ($code !== 0 || !is_file($tmp) || filesize($tmp) < 1024) { @unlink($tmp); evw_fail($v, 'FFmpeg HLS ingest failed'); evw_log($id, 'error', 'FFmpeg HLS ingest failed', ['tail'=>implode("\n", array_slice($out, -12))]); return null; }
    rename($tmp, $final);
    chmod($final, 0640);
    $st=evw_db()->prepare("UPDATE cb_external_videos SET download_status='downloaded', local_file_path=?, download_url=?, download_error=NULL, updated_at=NOW() WHERE id=?");
    $st->bind_param('ssi',$final,$downloadUrl,$id); $st->execute();
    evw_log($id, 'info', 'Downloaded HLS stream to MP4', ['path'=>$final, 'bytes'=>filesize($final)]);
    return ['path'=>$final,'ext'=>'mp4'];
}

function evw_slug_file(string $slug, int $id, string $ext): string {
    $base = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $slug ?: ('external-'.$id));
    $base = trim($base, '-') ?: ('external-'.$id);
    return $id.'-'.$base.'.'.$ext;
}
function evw_probe_ext(string $path, string $fallbackUrl, array $allowedExt, array $blockedExt): array {
    $ext = strtolower(pathinfo(parse_url($fallbackUrl, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    $fileOut = trim((string)shell_exec('file -b --mime-type '.escapeshellarg($path).' 2>/dev/null'));
    $map = ['video/mp4'=>'mp4','video/webm'=>'webm','video/quicktime'=>'mov','video/x-matroska'=>'mkv','application/octet-stream'=>''];
    if (isset($map[$fileOut]) && $map[$fileOut] !== '') { $ext = $map[$fileOut]; }
    if (in_array($ext, $blockedExt, true)) return [false, $ext, 'Blocked file extension: '.$ext];
    if (!in_array($ext, $allowedExt, true)) return [false, $ext, 'Unsupported or unsafe video type: '.($ext ?: $fileOut ?: 'unknown')];
    return [true, $ext, $fileOut];
}
function evw_download(array $v, string $queueDir, array $allowedExt, array $blockedExt, bool $dryRun=false): ?array {
    $id=(int)$v['id'];
    [$found, $downloadUrl, $why] = evw_discover_download_url($v, $allowedExt, $blockedExt);
    if (!$found) { evw_fail($v, $why); return null; }
    if (!is_dir($queueDir) && !$dryRun) { mkdir($queueDir, 0750, true); }
    if (!is_writable($queueDir) && !$dryRun) { evw_fail($v, 'Queue directory is not writable: '.$queueDir); return null; }
    evw_log($id, 'info', 'Starting external video download', ['download_url'=>$downloadUrl, 'source'=>$why]);
    $downloadExt = strtolower(pathinfo(parse_url($downloadUrl, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    if ($downloadExt === 'm3u8') { return evw_download_hls_to_mp4($v, $downloadUrl, $queueDir, $dryRun); }
    if ($dryRun) return ['path'=>$queueDir.'/dry-run.mp4','ext'=>'mp4'];
    $tmp = $queueDir.'/.'.$id.'-'.bin2hex(random_bytes(4)).'.download';
    $referer = (string)($v['source_url'] ?? '');
    $cmd = 'curl --fail --location --proto =http,https --max-redirs 3 --connect-timeout 20 --speed-limit 1024 --speed-time 30 --limit-rate 2m --max-time 1800 --referer '.escapeshellarg($referer).' --output '.escapeshellarg($tmp).' '.escapeshellarg($downloadUrl).' 2>&1';
    exec($cmd, $out, $code);
    if ($code !== 0 || !is_file($tmp) || filesize($tmp) < 1024) { @unlink($tmp); evw_fail($v, 'Download failed or empty response from direct download URL'); return null; }
    [$ok,$ext,$probe] = evw_probe_ext($tmp, $downloadUrl, $allowedExt, $blockedExt);
    if (!$ok) { @unlink($tmp); evw_fail($v, $probe); return null; }
    $final = $queueDir.'/'.evw_slug_file($v['slug'], $id, $ext);
    rename($tmp, $final);
    chmod($final, 0640);
    $st=evw_db()->prepare("UPDATE cb_external_videos SET download_status='downloaded', local_file_path=?, download_url=?, download_error=NULL, updated_at=NOW() WHERE id=?");
    $st->bind_param('ssi',$final,$downloadUrl,$id); $st->execute();
    evw_log($id, 'info', 'Downloaded file', ['path'=>$final, 'mime'=>$probe, 'bytes'=>filesize($final)]);
    return ['path'=>$final,'ext'=>$ext];
}
function evw_import_to_clipbucket(array $v, bool $keepTemp=false, bool $dryRun=false): bool {
    $id=(int)$v['id']; $path=(string)$v['local_file_path'];
    if (!user_id()) { @userquery::getInstance()->login_as_user(1); }
    if (!user_id()) { evw_fail($v, 'Unable to initialize ClipBucket admin upload context'); return false; }
    if (!$path || !is_file($path)) { evw_fail($v, 'Downloaded file missing for import'); return false; }
    evw_log($id, 'info', 'Importing through ClipBucket upload/conversion queue');
    if ($dryRun) return true;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, get_vid_extensions(), true)) { evw_fail($v, 'ClipBucket does not allow extension: '.$ext); return false; }
    $fileKey = time() . RandomString(5);
    $fileDirectory = create_dated_folder();
    $category = !empty($v['category_id']) ? [(int)$v['category_id']] : [];
    $array = [
        'title' => $v['title'],
        'description' => $v['description'] ?: ('Imported from external source: '.$v['provider']),
        'tags' => $v['tags'] ?: 'external, imported',
        'tags_video' => $v['tags'] ?: 'external, imported',
        'tags_genre' => '',
        'tags_actors' => '',
        'tags_producer' => '',
        'tags_director' => '',
        'tags_crew' => '',
        'category' => $category ?: [1],
        'file_name' => $fileKey,
        'file_type' => $ext,
        'file_directory' => $fileDirectory,
        'userid' => 1,
        'allow_comments' => 'yes',
        'comment_voting' => 'yes',
        'allow_rating' => 'yes',
        'allow_embedding' => 'yes',
        'broadcast' => 'public',
        'age_restriction' => 18,
        'video_password' => '',
        'country' => '',
        'location' => '',
        'external_rate' => 0,
        'external_ratings' => 0,
        'tracks' => []
    ];
    $vid = Upload::getInstance()->submit_upload($array);
    if (!$vid || error()) { evw_fail($v, 'ClipBucket submit_upload failed: '.strip_tags(json_encode(error()))); return false; }
    $tempFile = DirPath::get('temp') . $fileKey . '.' . $ext;
    if (!copy($path, $tempFile)) { evw_fail($v, 'Failed to copy file into ClipBucket temp queue'); return false; }
    create_dated_folder(DirPath::get('logs'));
    $logFile = DirPath::get('logs') . $fileDirectory . DIRECTORY_SEPARATOR . $fileKey . '.log';
    if (!is_dir(dirname($logFile))) { mkdir(dirname($logFile), 0755, true); }
    $log = new SLog($logFile);
    $log->newSection('External video import');
    $log->writeLine(date('Y-m-d H:i:s').' - External video ID '.$id.' imported to conversion queue');
    VideoConversionQueue::insert((int)$vid);
    $st=evw_db()->prepare("UPDATE cb_external_videos SET download_status='imported', clipbucket_video_id=?, download_error=NULL, updated_at=NOW() WHERE id=?");
    $st->bind_param('ii',$vid,$id); $st->execute();
    evw_log($id, 'info', 'Imported to ClipBucket conversion queue', ['clipbucket_video_id'=>$vid]);
    if (!$keepTemp) { @unlink($path); }
    return true;
}

if (!$downloadOnly) {
    $rs = evw_db()->query("SELECT * FROM cb_external_videos WHERE download_status='downloaded' AND clipbucket_video_id IS NULL AND status NOT IN ('rejected','broken','removed') ORDER BY updated_at ASC LIMIT ".$limit);
    while ($v=$rs->fetch_assoc()) { evw_import_to_clipbucket($v, $keepTemp, $dryRun); }
}
if (!$importOnly) {
    $sql = "SELECT * FROM cb_external_videos WHERE download_status='queued' AND status NOT IN ('rejected','broken','removed') AND download_attempts < 3 ORDER BY reviewed_at ASC, updated_at ASC LIMIT ".$limit;
    $rs = evw_db()->query($sql);
    while ($v=$rs->fetch_assoc()) {
        $id=(int)$v['id'];
        evw_db()->query("UPDATE cb_external_videos SET download_status='downloading', download_attempts=download_attempts+1, updated_at=NOW() WHERE id=".$id." AND download_status='queued'");
        $fresh = ev_get($id); if (!$fresh || $fresh['download_status'] !== 'downloading') continue;
        $dl = evw_download($fresh, $queueDir, $allowedExt, $blockedExt, $dryRun);
        if ($dl && !$downloadOnly) { $fresh = ev_get($id); if ($fresh) evw_import_to_clipbucket($fresh, $keepTemp, $dryRun); }
    }
}
