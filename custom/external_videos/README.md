# External Videos module

Purpose: add a standalone external video layer that can ingest crawler metadata, automatically download public video assets, and import them into ClipBucket's hosted upload/transcode/playback flow.

Tables:
- `cb_external_videos`: external metadata, review state, download/import state.
- `cb_external_video_providers`: legacy iframe/embed provider list; collector imports are no longer blocked by this whitelist.
- `cb_external_video_download_logs`: background worker download/import logs.

Security:
- `source_url`, `embed_url`, and `download_url` must be http/https.
- New collector/import records default to `status=pending`, `is_18_plus=1`, and `download_status=queued`.
- Background downloads accept public direct video files (`mp4`, `webm`, `mov`, `mkv`) and public unencrypted HLS `.m3u8` manifests.
- Blocks javascript:, data:, file:, unknown protocols, script/executable/html/php-like extensions, encrypted HLS, and non-public login/VIP/paywall/DRM-only sources.
- HLS manifests are remuxed to MP4 with ffmpeg, then imported through ClipBucket's normal `Upload::submit_upload()` and `VideoConversionQueue::insert()` path.
- `authorized_download` remains a legacy compatibility field and is not a worker gate.

Rollback:
- Restore DB from backup, or run `DROP TABLE cb_external_videos; DROP TABLE cb_external_video_providers; DROP TABLE cb_external_video_download_logs;` after exporting any wanted data.
