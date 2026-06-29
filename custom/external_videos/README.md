# External Videos module

Purpose: add a standalone external embed video layer without altering ClipBucket hosted upload/transcode/playback tables.

Tables:
- `cb_external_videos`: external metadata and review state.
- `cb_external_video_providers`: iframe/embed domain whitelist.

Security:
- No external video file downloading.
- `embed_url` must be http/https and pass provider/domain whitelist.
- Blocks javascript:, data:, file:, and unknown protocols.
- Default external video status is `pending` and `is_18_plus=1`.

Rollback:
- Restore DB from backup, or run `DROP TABLE cb_external_videos; DROP TABLE cb_external_video_providers;` after exporting any wanted data.
