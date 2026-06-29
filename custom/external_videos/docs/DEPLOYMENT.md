# External Videos module

Backup taken before module work:
`/root/backups/clipbucket-v5/external-module-20260629151516`

## Purpose
Adds a standalone `external_videos` layer for embed-only external videos without changing ClipBucket hosted upload/transcode/playback tables.

## Tables
- `cb_external_videos`
- `cb_external_video_providers`

Migration:
`custom/external_videos/migrations/001_create_external_videos.sql`

## Admin
`/admin_area/external_videos.php`

Features:
- import URL as pending
- add/edit metadata
- status filtering
- provider filtering
- bulk status update
- iframe preview
- source/license/review notes

## Frontend
- Feed: `/external_feed.php`
- Detail: `/external_video.php?slug=<slug>`
- Widget: `/external_videos_widget.php`
- Sitemap extension: `/external_sitemap.php`

## Security
- No video file downloading.
- `embed_url` only accepts http/https.
- javascript:, data:, file: are rejected.
- iframe domains must be in `cb_external_video_providers` active whitelist.
- iframe uses sandbox/referrerpolicy/allowfullscreen.
- Imported videos default to `pending` and `is_18_plus=1`.

## Rollback
Restore full backup:
```bash
cd /opt
mv /opt/clipbucket-v5 /opt/clipbucket-v5.failed.$(date +%Y%m%d%H%M%S)
tar -xzf /root/backups/clipbucket-v5/external-module-20260629151516/clipbucket-code.tar.gz -C /opt
cd /opt/clipbucket-v5 && docker compose up -d
```

Rollback DB only:
```bash
gunzip -c /root/backups/clipbucket-v5/external-module-20260629151516/clipbucket-db.sql.gz | docker exec -i clipbucket-v5 mysql -uclipbucket -p clipbucket
```
Use the DB password from `/root/deploy-notes/clipbucket-v5-credentials.txt`; do not paste it into logs.
