<?php
require __DIR__.'/../custom/external_videos/lib/external_videos_lib.php';
header('Content-Type: application/xml; charset=utf-8');
$host='http://'.($_SERVER['HTTP_HOST']??'162.35.166.53');
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
foreach(ev_list(['published'=>1,'imported_only'=>1,'limit'=>100]) as $v){
  echo '<url><loc>'.ev_h($host.'/external_video.php?slug='.rawurlencode($v['slug'])).'</loc><lastmod>'.ev_h(substr($v['updated_at'],0,10)).'</lastmod></url>' . "\n";
}
echo "</urlset>\n";
