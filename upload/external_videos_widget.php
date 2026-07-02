<?php
const THIS_PAGE = 'external_videos_widget';
require __DIR__.'/includes/config.inc.php';
require_once __DIR__.'/../custom/external_videos/lib/external_videos_lib.php';
header('Content-Type:text/html; charset=utf-8');
$items=ev_list(['published'=>1,'q'=>$_GET['q']??'','limit'=>(int)($_GET['limit']??12)]);
if(!$items)exit;
?><section class="oc-external-widget"><h2><?=ev_h(lang('oc_latest_videos'))?> <a href="/external_feed.php"><?=ev_h(lang('oc_view_all'))?></a></h2><div class="oc-external-grid"><?php foreach($items as $v): ?><article class="oc-external-card"><a href="/external_video.php?slug=<?=rawurlencode($v['slug'])?>"><img src="<?=ev_h($v['thumbnail_url'])?>" alt="<?=ev_h($v['title'])?>"><h3><?=ev_h($v['title'])?></h3><p><?=number_format((int)$v['view_count'])?> <?=ev_h(lang('oc_views'))?> · <?=ev_h($v['provider'])?></p></a></article><?php endforeach;?></div></section>
