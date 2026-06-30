<?php
const THIS_PAGE = 'all_videos';
require_once dirname(__FILE__, 2) . '/includes/admin_config.php';
User::getInstance()->hasPermissionOrRedirect('video_moderation', true);
pages::getInstance()->page_redir();

/* Generating breadcrumb */
global $breadcrumb;
$breadcrumb[0] = ['title' => 'Videos', 'url' => ''];
$breadcrumb[1] = ['title' => 'All Videos', 'url' => DirPath::getUrl('admin_area') . 'all_videos.php'];

function av_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function av_db(): mysqli {
    static $conn = null;
    if ($conn instanceof mysqli) return $conn;
    // Prefer ClipBucket's already-configured database constants. Do not rely on web-server env vars.
    if (defined('DBHOST') && defined('DBUSER') && defined('DBPASS') && defined('DBNAME')) {
        $conn = new mysqli(DBHOST, DBUSER, DBPASS, DBNAME);
    } else {
        $conn = new mysqli('127.0.0.1', 'clipbucket', getenv('MYSQL_PASSWORD') ?: '', 'clipbucket', 3306);
    }
    if ($conn->connect_errno) {
        http_response_code(500);
        die('Database connection failed');
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}
function av_param(string $k, string $default=''): string { return trim((string)($_GET[$k] ?? $default)); }
function av_count(string $sql): int { $r=av_db()->query($sql); if(!$r) return 0; $row=$r->fetch_row(); return (int)($row[0] ?? 0); }

$q = av_param('q');
$type = av_param('type', 'all');
$status = av_param('status');
$provider = av_param('provider');
$limit = min(300, max(20, (int)($_GET['limit'] ?? 120)));

$native = [];
if ($type === 'all' || $type === 'native') {
    $where = [];
    if ($q !== '') { $safe = '%' . av_db()->real_escape_string($q) . '%'; $where[] = "(title LIKE '$safe' OR description LIKE '$safe' OR file_name LIKE '$safe')"; }
    if ($status !== '') { $safe = av_db()->real_escape_string($status); $where[] = "status='$safe'"; }
    $sql = "SELECT videoid,title,description,date_added,status,active,file_name,file_type,file_directory,default_thumb,views,userid,username FROM " . tbl('video') . ($where ? ' WHERE '.implode(' AND ', $where) : '') . " ORDER BY videoid DESC LIMIT " . (int)$limit;
    if ($res = av_db()->query($sql)) while ($row = $res->fetch_assoc()) $native[] = $row;
}

$external = [];
if ($type === 'all' || $type === 'external') {
    $where = [];
    if ($q !== '') { $safe = '%' . av_db()->real_escape_string($q) . '%'; $where[] = "(title LIKE '$safe' OR description LIKE '$safe' OR source_url LIKE '$safe' OR provider LIKE '$safe')"; }
    if ($status !== '') { $safe = av_db()->real_escape_string($status); $where[] = "status='$safe'"; }
    if ($provider !== '') { $safe = '%' . av_db()->real_escape_string($provider) . '%'; $where[] = "provider LIKE '$safe'"; }
    $sql = "SELECT id,title,description,thumbnail_url,source_url,embed_url,provider,status,authorized_download,download_status,clipbucket_video_id,download_error,created_at,updated_at FROM cb_external_videos" . ($where ? ' WHERE '.implode(' AND ', $where) : '') . " ORDER BY id DESC LIMIT " . (int)$limit;
    if ($res = av_db()->query($sql)) while ($row = $res->fetch_assoc()) $external[] = $row;
}
$nativeCount = av_count('SELECT COUNT(*) FROM '.tbl('video'));
$externalCount = av_count('SELECT COUNT(*) FROM cb_external_videos');
?><!doctype html>
<html><head><meta charset="utf-8"><title>All Videos</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;margin:24px;background:#f6f6f6;color:#222}.bar,.card,table{background:#fff;border:1px solid #ddd}.bar,.card{padding:14px;margin-bottom:16px}table{width:100%;border-collapse:collapse;font-size:13px}td,th{border:1px solid #ddd;padding:8px;text-align:left;vertical-align:top}th{background:#f1f1f1}.thumb{width:120px;max-height:80px;object-fit:cover;background:#eee}.badge{display:inline-block;padding:2px 7px;border-radius:10px;background:#eee;margin:1px;font-size:12px}.native{background:#dff0ff}.external{background:#fff1cc}.ok{background:#dff7e8}.bad{background:#ffe0e0}.queued{background:#e8e8ff}.small{font-size:12px;color:#666}input,select{padding:8px;margin:4px 8px 4px 0}.btn{display:inline-block;padding:8px 12px;background:#333;color:#fff;text-decoration:none;border:0}.nav a{margin-right:12px}.title{font-weight:bold}.desc{max-width:360px;white-space:normal}.url{max-width:300px;word-break:break-all}
</style></head><body>
<h1>All Videos</h1>
<div class="bar nav">
  <a href="/admin_area/">Admin Home</a>
  <a href="/admin_area/video_manager.php">Native Video Manager</a>
  <a href="/admin_area/external_videos.php">External Videos</a>
  <span class="badge native">native <?=av_h($nativeCount)?></span>
  <span class="badge external">external <?=av_h($externalCount)?></span>
</div>
<form class="bar" method="get">
  <input name="q" placeholder="Search title/url/file" value="<?=av_h($q)?>">
  <select name="type"><option value="all" <?=$type==='all'?'selected':''?>>all types</option><option value="native" <?=$type==='native'?'selected':''?>>native only</option><option value="external" <?=$type==='external'?'selected':''?>>external only</option></select>
  <input name="status" placeholder="status" value="<?=av_h($status)?>">
  <input name="provider" placeholder="provider e.g. nnyy.in" value="<?=av_h($provider)?>">
  <input name="limit" type="number" min="20" max="300" value="<?=av_h($limit)?>">
  <button class="btn">Filter</button>
</form>

<div class="card"><h2>Native ClipBucket Videos</h2>
<table><tr><th>Type</th><th>ID</th><th>Title</th><th>Status</th><th>File</th><th>Views/User</th><th>Actions</th></tr>
<?php if (!$native): ?><tr><td colspan="7">No native videos found.</td></tr><?php endif; ?>
<?php foreach($native as $v): ?>
<tr>
<td><span class="badge native">native</span></td>
<td><?= (int)$v['videoid'] ?></td>
<td><div class="title"><?= av_h($v['title']) ?></div><div class="small"><?= av_h(mb_substr((string)$v['description'],0,160)) ?></div></td>
<td><span class="badge <?=($v['active']==='yes'?'ok':'bad')?>">active <?=av_h($v['active'])?></span><br><span class="badge queued"><?=av_h($v['status'])?></span></td>
<td><?=av_h($v['file_name'])?>.<?=av_h($v['file_type'])?><br><span class="small"><?=av_h($v['file_directory'])?></span></td>
<td><?= (int)$v['views'] ?><br><span class="small"><?=av_h($v['username'] ?: $v['userid'])?></span></td>
<td><a href="/admin_area/edit_video.php?video=<?= (int)$v['videoid'] ?>">edit</a> · <a target="_blank" href="/watch_video.php?v=<?= (int)$v['videoid'] ?>">view</a></td>
</tr>
<?php endforeach; ?>
</table></div>

<div class="card"><h2>External / Crawled Videos</h2>
<table><tr><th>Type</th><th>ID</th><th>Preview</th><th>Title</th><th>Status</th><th>Provider / Source</th><th>Download</th><th>Actions</th></tr>
<?php if (!$external): ?><tr><td colspan="8">No external videos found.</td></tr><?php endif; ?>
<?php foreach($external as $v): ?>
<tr>
<td><span class="badge external">external</span></td>
<td><?= (int)$v['id'] ?></td>
<td><?php if($v['thumbnail_url']): ?><img class="thumb" src="<?=av_h($v['thumbnail_url'])?>" alt=""><?php endif; ?></td>
<td><div class="title"><?= av_h($v['title']) ?></div><div class="small desc"><?= av_h(mb_substr((string)$v['description'],0,180)) ?></div></td>
<td><span class="badge"><?=av_h($v['status'])?></span></td>
<td><span class="badge"><?=av_h($v['provider'])?></span><br><div class="url"><a target="_blank" href="<?=av_h($v['source_url'])?>">source</a></div></td>
<td><span class="badge <?=((int)$v['authorized_download']?'ok':'bad')?>">auth <?=((int)$v['authorized_download']?'yes':'no')?></span><br><span class="badge queued"><?=av_h($v['download_status'])?></span><?php if($v['clipbucket_video_id']): ?><br><a target="_blank" href="/watch_video.php?v=<?=(int)$v['clipbucket_video_id']?>">native #<?=(int)$v['clipbucket_video_id']?></a><?php endif; ?><?php if($v['download_error']): ?><div class="small bad"><?=av_h(mb_substr($v['download_error'],0,160))?></div><?php endif; ?></td>
<td><a href="/admin_area/external_videos.php?edit=<?=(int)$v['id']?>">review/edit</a><?php if($v['clipbucket_video_id']): ?> · <a target="_blank" href="/watch_video.php?v=<?=(int)$v['clipbucket_video_id']?>">view native</a><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</table></div>
</body></html>
