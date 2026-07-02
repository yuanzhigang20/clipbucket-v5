-- Internationalization keys for external video portal UI
INSERT INTO cb_languages (language_name, language_code, language_active, language_default)
SELECT '简体中文', 'zh-CN', 'yes', 'no'
WHERE NOT EXISTS (SELECT 1 FROM cb_languages WHERE language_code IN ('zh-CN','zh'));

SET @zh_id := (SELECT language_id FROM cb_languages WHERE language_code IN ('zh-CN','zh') ORDER BY FIELD(language_code, 'zh-CN', 'zh') LIMIT 1);

INSERT IGNORE INTO cb_languages_keys (language_key) VALUES
('oc_home'),('oc_recommended'),('oc_all_videos'),('oc_latest_videos'),('oc_most_viewed'),('oc_trending'),('oc_categories'),('oc_tags'),('oc_long_videos'),('oc_hd_videos'),('oc_view_all'),('oc_hosted_videos'),('oc_videos'),('oc_search_results_for'),('oc_search_meta_description'),('oc_feed_meta_description'),('oc_videos_found'),('oc_page_of'),('oc_search_placeholder'),('oc_search_videos'),('oc_search'),('oc_clear'),('oc_views'),('oc_no_videos_found'),('oc_try_different_keyword'),('oc_video_pagination'),('oc_previous'),('oc_next'),('oc_open_video_stream'),('oc_source'),('oc_report'),('oc_dmca_complaint'),('oc_content_removal'),('oc_dmca'),('oc_language');

INSERT INTO cb_languages_translations (id_language_key, language_id, translation)
SELECT k.id_language_key, 1, e.translation FROM cb_languages_keys k JOIN (
SELECT 'oc_home' k,'Home' translation UNION ALL
SELECT 'oc_recommended','Recommended' UNION ALL
SELECT 'oc_all_videos','All Videos' UNION ALL
SELECT 'oc_latest_videos','Latest Videos' UNION ALL
SELECT 'oc_most_viewed','Most Viewed' UNION ALL
SELECT 'oc_trending','Trending' UNION ALL
SELECT 'oc_categories','Categories' UNION ALL
SELECT 'oc_tags','Tags' UNION ALL
SELECT 'oc_long_videos','Long Videos' UNION ALL
SELECT 'oc_hd_videos','HD Videos' UNION ALL
SELECT 'oc_view_all','View All' UNION ALL
SELECT 'oc_hosted_videos','Hosted Videos' UNION ALL
SELECT 'oc_videos','Videos' UNION ALL
SELECT 'oc_search_results_for','Search results for “%s”' UNION ALL
SELECT 'oc_search_meta_description','Search published videos for %s.' UNION ALL
SELECT 'oc_feed_meta_description','Published videos.' UNION ALL
SELECT 'oc_videos_found','%s videos found' UNION ALL
SELECT 'oc_page_of','page %s of %s' UNION ALL
SELECT 'oc_search_placeholder','Search videos, tags, providers...' UNION ALL
SELECT 'oc_search_videos','Search videos' UNION ALL
SELECT 'oc_search','Search' UNION ALL
SELECT 'oc_clear','Clear' UNION ALL
SELECT 'oc_views','views' UNION ALL
SELECT 'oc_no_videos_found','No videos found' UNION ALL
SELECT 'oc_try_different_keyword','Try a different keyword or clear the search.' UNION ALL
SELECT 'oc_video_pagination','Video pagination' UNION ALL
SELECT 'oc_previous','Previous' UNION ALL
SELECT 'oc_next','Next' UNION ALL
SELECT 'oc_open_video_stream','Open video stream' UNION ALL
SELECT 'oc_source','Source' UNION ALL
SELECT 'oc_report','Report' UNION ALL
SELECT 'oc_dmca_complaint','DMCA / Copyright Complaint' UNION ALL
SELECT 'oc_content_removal','Content Removal' UNION ALL
SELECT 'oc_dmca','DMCA' UNION ALL
SELECT 'oc_language','Language'
) e ON e.k = k.language_key
ON DUPLICATE KEY UPDATE translation=VALUES(translation);

INSERT INTO cb_languages_translations (id_language_key, language_id, translation)
SELECT k.id_language_key, @zh_id, z.translation FROM cb_languages_keys k JOIN (
SELECT 'oc_home' k,'首页' translation UNION ALL
SELECT 'oc_recommended','推荐' UNION ALL
SELECT 'oc_all_videos','全部视频' UNION ALL
SELECT 'oc_latest_videos','最新视频' UNION ALL
SELECT 'oc_most_viewed','最多观看' UNION ALL
SELECT 'oc_trending','热门' UNION ALL
SELECT 'oc_categories','分类' UNION ALL
SELECT 'oc_tags','标签' UNION ALL
SELECT 'oc_long_videos','长视频' UNION ALL
SELECT 'oc_hd_videos','高清视频' UNION ALL
SELECT 'oc_view_all','查看全部' UNION ALL
SELECT 'oc_hosted_videos','站内视频' UNION ALL
SELECT 'oc_videos','视频' UNION ALL
SELECT 'oc_search_results_for','“%s”的搜索结果' UNION ALL
SELECT 'oc_search_meta_description','搜索与 %s 相关的已发布视频。' UNION ALL
SELECT 'oc_feed_meta_description','已发布视频。' UNION ALL
SELECT 'oc_videos_found','找到 %s 个视频' UNION ALL
SELECT 'oc_page_of','第 %s 页，共 %s 页' UNION ALL
SELECT 'oc_search_placeholder','搜索视频、标签、来源...' UNION ALL
SELECT 'oc_search_videos','搜索视频' UNION ALL
SELECT 'oc_search','搜索' UNION ALL
SELECT 'oc_clear','清除' UNION ALL
SELECT 'oc_views','次观看' UNION ALL
SELECT 'oc_no_videos_found','没有找到视频' UNION ALL
SELECT 'oc_try_different_keyword','请尝试其他关键词，或清除搜索条件。' UNION ALL
SELECT 'oc_video_pagination','视频分页' UNION ALL
SELECT 'oc_previous','上一页' UNION ALL
SELECT 'oc_next','下一页' UNION ALL
SELECT 'oc_open_video_stream','打开视频流' UNION ALL
SELECT 'oc_source','来源' UNION ALL
SELECT 'oc_report','举报' UNION ALL
SELECT 'oc_dmca_complaint','DMCA / 版权投诉' UNION ALL
SELECT 'oc_content_removal','内容移除' UNION ALL
SELECT 'oc_dmca','DMCA' UNION ALL
SELECT 'oc_language','语言'
) z ON z.k = k.language_key
WHERE @zh_id IS NOT NULL
ON DUPLICATE KEY UPDATE translation=VALUES(translation);
