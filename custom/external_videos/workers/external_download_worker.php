<?php
/**
 * Authorized external-video downloader/importer.
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
$allowedExt = ['mp4','webm','mov','mkv'];
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
    if (!evw_safe_source($v['source_url'])) { evw_fail($v, 'Only http/https source URLs are allowed'); return null; }
    if (!is_dir($queueDir) && !$dryRun) { mkdir($queueDir, 0750, true); }
    if (!is_writable($queueDir) && !$dryRun) { evw_fail($v, 'Queue directory is not writable: '.$queueDir); return null; }
    evw_log($id, 'info', 'Starting authorized download');
    if ($dryRun) return ['path'=>$queueDir.'/dry-run.mp4','ext'=>'mp4'];
    $tmp = $queueDir.'/.'.$id.'-'.bin2hex(random_bytes(4)).'.download';
    $cmd = 'curl --fail --location --proto =http,https --max-redirs 3 --connect-timeout 20 --speed-limit 1024 --speed-time 30 --limit-rate 2m --max-time 1800 --output '.escapeshellarg($tmp).' '.escapeshellarg($v['source_url']).' 2>&1';
    exec($cmd, $out, $code);
    if ($code !== 0 || !is_file($tmp) || filesize($tmp) < 1024) { @unlink($tmp); evw_fail($v, 'Download failed or empty response'); return null; }
    [$ok,$ext,$why] = evw_probe_ext($tmp, $v['source_url'], $allowedExt, $blockedExt);
    if (!$ok) { @unlink($tmp); evw_fail($v, $why); return null; }
    $final = $queueDir.'/'.evw_slug_file($v['slug'], $id, $ext);
    rename($tmp, $final);
    chmod($final, 0640);
    $st=evw_db()->prepare("UPDATE cb_external_videos SET download_status='downloaded', local_file_path=?, download_error=NULL, updated_at=NOW() WHERE id=?");
    $st->bind_param('si',$final,$id); $st->execute();
    evw_log($id, 'info', 'Downloaded file', ['path'=>$final, 'mime'=>$why, 'bytes'=>filesize($final)]);
    return ['path'=>$final,'ext'=>$ext];
}
function evw_import_to_clipbucket(array $v, bool $keepTemp=false, bool $dryRun=false): bool {
    $id=(int)$v['id']; $path=(string)$v['local_file_path'];
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
        'description' => $v['description'] ?: ('Imported from authorized source: '.$v['provider']),
        'tags' => $v['tags'] ?: 'external, imported',
        'category' => $category,
        'file_name' => $fileKey,
        'file_type' => $ext,
        'file_directory' => $fileDirectory,
        'userid' => 1,
        'allow_comments' => 'yes',
        'comment_voting' => 'yes',
        'allow_rating' => 'yes',
        'allow_embedding' => 'yes',
        'broadcast' => 'public'
    ];
    $vid = Upload::getInstance()->submit_upload($array);
    if (!$vid || error()) { evw_fail($v, 'ClipBucket submit_upload failed'); return false; }
    $tempFile = DirPath::get('temp') . $fileKey . '.' . $ext;
    if (!copy($path, $tempFile)) { evw_fail($v, 'Failed to copy file into ClipBucket temp queue'); return false; }
    create_dated_folder(DirPath::get('logs'));
    $logFile = DirPath::get('logs') . $fileDirectory . DIRECTORY_SEPARATOR . $fileKey . '.log';
    if (!is_dir(dirname($logFile))) { mkdir(dirname($logFile), 0755, true); }
    $log = new SLog($logFile);
    $log->newSection('Authorized external video import');
    $log->writeLine(date('Y-m-d H:i:s').' - External video ID '.$id.' imported to conversion queue');
    VideoConversionQueue::insert((int)$vid);
    $st=evw_db()->prepare("UPDATE cb_external_videos SET download_status='imported', clipbucket_video_id=?, download_error=NULL, updated_at=NOW() WHERE id=?");
    $st->bind_param('ii',$vid,$id); $st->execute();
    evw_log($id, 'info', 'Imported to ClipBucket conversion queue', ['clipbucket_video_id'=>$vid]);
    if (!$keepTemp) { @unlink($path); }
    return true;
}

if (!$downloadOnly) {
    $rs = evw_db()->query("SELECT * FROM cb_external_videos WHERE download_status='downloaded' AND authorized_download=1 AND clipbucket_video_id IS NULL ORDER BY updated_at ASC LIMIT ".$limit);
    while ($v=$rs->fetch_assoc()) { evw_import_to_clipbucket($v, $keepTemp, $dryRun); }
}
if (!$importOnly) {
    $sql = "SELECT * FROM cb_external_videos WHERE download_status='queued' AND authorized_download=1 AND status NOT IN ('rejected','broken','removed') AND download_attempts < 3 ORDER BY reviewed_at ASC, updated_at ASC LIMIT ".$limit;
    $rs = evw_db()->query($sql);
    while ($v=$rs->fetch_assoc()) {
        $id=(int)$v['id'];
        evw_db()->query("UPDATE cb_external_videos SET download_status='downloading', download_attempts=download_attempts+1, updated_at=NOW() WHERE id=".$id." AND download_status='queued'");
        $fresh = ev_get($id); if (!$fresh || $fresh['download_status'] !== 'downloading') continue;
        $dl = evw_download($fresh, $queueDir, $allowedExt, $blockedExt, $dryRun);
        if ($dl && !$downloadOnly) { $fresh = ev_get($id); if ($fresh) evw_import_to_clipbucket($fresh, $keepTemp, $dryRun); }
    }
}
