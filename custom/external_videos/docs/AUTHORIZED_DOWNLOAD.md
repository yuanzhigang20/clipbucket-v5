# Authorized external video download/import module

Backup before deployment: `/root/backups/clipbucket-v5/auth-download-20260630030928`

## Purpose
Adds an authorization-gated path from external embed metadata to ClipBucket-hosted video import.

Default behavior remains metadata-only:
- crawler/import creates `status=pending`
- `authorized_download=true` by current operator policy
- `download_status=none`
- no automatic downloads

## Database
Migration: `custom/external_videos/migrations/002_authorized_download.sql`

Adds to `cb_external_videos`:
- `authorized_download`
- `download_status`: `none`, `queued`, `downloading`, `downloaded`, `imported`, `failed`
- `local_file_path`
- `clipbucket_video_id`
- `download_error`
- `download_attempts`
- `reviewed_by`
- `reviewed_at`

Adds log table:
- `cb_external_video_download_logs`

## Admin review
Page: `/admin_area/external_videos.php`

Actions:
- publish external embed
- mark authorized for download
- queue authorized download
- reject
- mark broken
- bulk publish/authorize/queue/reject/broken

Queue guardrails:
- only `authorized_download=1` can queue
- `rejected`, `broken`, `removed` cannot queue
- `source_license` and `review_note` are optional; queueing is gated by `authorized_download=1`.

## Worker
Worker: `custom/external_videos/workers/external_download_worker.php`
Host cron wrapper: `custom/external_videos/workers/run_external_download_worker.sh`
Cron: every 5 minutes.

Worker behavior:
- runs inside container as `containeruser`, not root
- scans limited batches (`--limit`, cron uses 2)
- downloads only queued + authorized rows
- only accepts http/https source URLs
- curl protocol is restricted to http/https
- saves to `/var/media_import_queue` (symlink to non-web `runtime/media_import_queue`)
- validates video extensions/mime: mp4, webm, mov, mkv
- blocks script/executable/html/php-like extensions
- max 3 attempts via `download_attempts < 3`
- logs to DB table and `runtime/media_import_queue/worker.log`

## Import flow
After a successful download, the worker imports through ClipBucket's existing `Upload::submit_upload()` and `VideoConversionQueue::insert()` path, then writes `clipbucket_video_id` and `download_status=imported`.

It does not bypass ClipBucket conversion/security checks and does not manually fabricate final hosted-video files.

## Frontend
`external_video.php` behavior:
- pending/non-published: 404
- published external-only: iframe embed
- imported: redirects to ClipBucket native `watch_video.php?v=<videoid>`

## Rollback
Code rollback:
```bash
cd /opt
mv /opt/clipbucket-v5 /opt/clipbucket-v5.failed.$(date +%Y%m%d%H%M%S)
tar -xzf /root/backups/clipbucket-v5/auth-download-20260630030928/clipbucket-code.tar.gz -C /opt
cd /opt/clipbucket-v5 && docker compose up -d
```

DB rollback:
```bash
gunzip -c /root/backups/clipbucket-v5/auth-download-20260630030928/clipbucket-db.sql.gz | docker exec -i clipbucket-v5 mysql -uclipbucket -p clipbucket
```
Use the DB password from `/root/deploy-notes/clipbucket-v5-credentials.txt`; do not paste it into logs.

Cron rollback:
```bash
crontab -l | grep -v 'external_download_worker.php' | crontab -
```

## Detail-page direct download discovery update

Migration: `custom/external_videos/migrations/003_download_url.sql`

Adds:
- `cb_external_videos.download_url`

Worker behavior after this update:
- For authorized queued rows, if `download_url` is filled, validate it as a direct video file URL.
- If `download_url` is empty, fetch the public detail/source page and inspect only ordinary `<a href="...">` links that look like download links.
- Accept only direct `http/https` URLs ending in/serving `mp4`, `webm`, `mov`, or `mkv`.
- Reject and log `m3u8`/HLS playlists, encrypted API responses, button-only JavaScript handlers, HTML pages, VIP/login-only flows, and any non-direct link.
- This does not click browser buttons, decrypt app payloads, parse HLS segments, or bypass login/VIP/paywall/hotlink/DRM protections.

Admin review page now exposes optional `download_url` for manually supplied direct-file URLs. The global gate still applies: only `authorized_download=1` plus queued rows are processed.


## Default authorization policy update

Migration: `custom/external_videos/migrations/004_authorized_default_true.sql`

New external-video records now default to `authorized_download=1`. This does not auto-download by itself; rows still require `download_status=queued` before the Worker runs.
