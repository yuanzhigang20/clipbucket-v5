# Automatic external video download/import module

Backup before the original deployment: `/root/backups/clipbucket-v5/auth-download-20260630030928`

## Purpose

Adds a background path from external/crawler metadata to ClipBucket-hosted video import. Collector imports no longer require provider-domain whitelist approval or manual `authorized_download` review before downloading.

Default behavior:
- crawler/import creates `status=pending`
- `download_status=queued`
- `authorized_download=1` is kept as a legacy compatibility field only
- the worker automatically attempts eligible queued downloads

The downloader still only handles public, directly accessible video assets. It does not bypass login, VIP, paywall, hotlink, DRM, encrypted HLS, API encryption, or browser-only button flows.

## Database

Base migration: `custom/external_videos/migrations/002_authorized_download.sql`

Adds to `cb_external_videos`:
- `authorized_download` (legacy compatibility; no longer a worker gate)
- `download_status`: `none`, `queued`, `downloading`, `downloaded`, `imported`, `failed`
- `local_file_path`
- `clipbucket_video_id`
- `download_error`
- `download_attempts`
- `reviewed_by`
- `reviewed_at`

Adds log table:
- `cb_external_video_download_logs`

Direct URL migration: `custom/external_videos/migrations/003_download_url.sql`

Adds:
- `cb_external_videos.download_url`

Default authorization migration: `custom/external_videos/migrations/004_authorized_default_true.sql`

Auto-queue/no-authorization migration: `custom/external_videos/migrations/005_auto_queue_no_authorization.sql`

This migration:
- keeps `authorized_download` defaulting to `1` for legacy code paths
- changes `download_status` default to `queued`
- backfills existing eligible `download_status=none` rows to `queued`
- adds a queue index that no longer depends on `authorized_download`

## Admin review

Page: `/admin_area/external_videos.php`

Actions:
- import a URL as pending metadata and automatically queue download
- save metadata
- publish external embed
- queue/requeue download
- reject
- mark broken
- bulk publish/queue/reject/broken

Queue guardrails:
- `authorized_download` is not required to queue or process downloads
- `rejected`, `broken`, and `removed` videos cannot queue/download
- `source_license` and `review_note` remain optional legacy/review notes

## Worker

Worker: `custom/external_videos/workers/external_download_worker.php`
Host cron wrapper: `custom/external_videos/workers/run_external_download_worker.sh`
Cron: every 5 minutes.

Worker behavior:
- runs inside container as `containeruser`, not root
- scans limited batches (`--limit`, cron uses 2)
- downloads queued rows regardless of legacy `authorized_download`
- only accepts http/https source and download URLs
- curl protocol is restricted to http/https
- saves to `/var/media_import_queue` (symlink to non-web `runtime/media_import_queue`)
- validates video extensions/mime: mp4, webm, mov, mkv, m3u8
- blocks script/executable/html/php-like extensions
- max 3 attempts via `download_attempts < 3`
- skips rejected/broken/removed rows
- logs to DB table and `runtime/media_import_queue/worker.log`

## Detail-page direct download discovery

For queued rows:
- If `download_url` is filled, validate it as a direct video or HLS URL.
- If `download_url` is empty, fetch the public detail/source page and inspect ordinary `<a href="...">` links that look like download links.
- If no matching link is found, scan the public page text for an absolute `.m3u8` URL.
- Accept only direct `http/https` URLs ending in `mp4`, `webm`, `mov`, `mkv`, or `m3u8`.
- Reject encrypted API responses, button-only JavaScript handlers, HTML pages, VIP/login-only flows, and any non-direct link.

This does not click browser buttons, decrypt app payloads, parse private APIs, or bypass login/VIP/paywall/hotlink/DRM protections.

## Public HLS/m3u8 ingest

Worker accepts public unencrypted HLS `.m3u8` URLs in `download_url` or discovered in public detail-page HTML/resource text.

Safety rules:
- only `http/https`
- manifest must be publicly fetchable
- manifest must contain `#EXTM3U`
- encrypted HLS (`#EXT-X-KEY` with non-`NONE` method) is refused
- no login/VIP/paywall/DRM/hotlink bypass
- no API payload decryption or browser click simulation
- ffmpeg remuxes public HLS to mp4 under `/var/media_import_queue`, then imports via ClipBucket `Upload::submit_upload()` and `VideoConversionQueue::insert()`

Verified on 2026-06-30: external video ID 14 used public unencrypted HLS manifest, downloaded/remuxed to MP4 (~88 MB), and imported into ClipBucket native video ID 2 with status `Waiting` for normal conversion queue processing.

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
