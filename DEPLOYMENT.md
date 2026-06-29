# ClipBucket V5 deployment

Host: 162.35.166.53
Install directory: /opt/clipbucket-v5
Public URL: http://162.35.166.53/
Runtime: Docker Compose, service `clipbucket`
Image: oxygenz/clipbucket-v5:latest
Container: clipbucket-v5

Credentials are stored on the server only: /root/deploy-notes/clipbucket-v5-credentials.txt
Do not commit or print database/admin passwords.

## Commands
Start: docker compose up -d
Stop: docker compose stop
Restart: docker compose restart clipbucket
Logs: docker logs -f clipbucket-v5
Shell: docker exec -it clipbucket-v5 bash

## Data
Application source/theme: /opt/clipbucket-v5
MySQL bind mount: /opt/clipbucket-v5/runtime/mysql
Uploads/cache are under /opt/clipbucket-v5/upload/files and related ClipBucket directories.

## Rollback
MediaCMS was backed up under /root/backups/mediacms/uninstall-*/ and moved out before ClipBucket took port 80.
ClipBucket theme backup is under /root/backups/clipbucket-v5/theme-*/.
To revert theme files, copy files from that backup back into upload/styles/cb_28/layout/ and restart the container.

## External metadata crawler

Installed outside the web root:

```bash
/opt/clipbucket-crawler
```

Purpose: collect public metadata only for approved external video sources, never download video files, never bypass login/paywall/permissions, and insert rows into `cb_external_videos` as `status=pending` for human review.

Runtime commands:

```bash
cd /opt/clipbucket-crawler
python3 run.py test-db
python3 run.py import-url "https://www.youtube.com/watch?v=dQw4w9WgXcQ"
python3 run.py crawl --start-url "https://www.youtube.com/videos" --limit 100
python3 run.py crawl-config --limit 50
python3 run.py check-broken --limit 200
```

Cron jobs installed on the host:

```cron
0 2 * * * cd /opt/clipbucket-crawler && /usr/bin/python3 run.py crawl-config --limit 50 >> /opt/clipbucket-crawler/logs/cron-crawl.log 2>&1
0 8 * * * cd /opt/clipbucket-crawler && /usr/bin/python3 run.py check-broken --limit 200 >> /opt/clipbucket-crawler/logs/cron-broken.log 2>&1
```

Crawler logs:

```bash
/opt/clipbucket-crawler/logs/crawler.log
/opt/clipbucket-crawler/logs/cron-crawl.log
/opt/clipbucket-crawler/logs/cron-broken.log
```

DB log table:

```sql
SELECT * FROM crawler_logs ORDER BY id DESC LIMIT 50;
```

DB security:
- Runtime DB account is `clipcrawler@localhost`.
- It has only required privileges on `cb_external_videos`, `cb_external_video_providers`, and `crawler_logs`.
- DB password lives in `/opt/clipbucket-crawler/.env` and `/root/deploy-notes/clipbucket-crawler-db-password.txt` only; do not print or commit it.
- `.env` is outside the web root and chmod `600`.

Crawler frontend contract:
- New imports default to `status=pending` and `is_18_plus=1`.
- Frontend/feed/sitemap read only `published` external videos.
- `rejected`, `broken`, and `removed` are not displayed or indexed.
- Broken check uses HTTP status plus provider metadata checks with yt-dlp `download=False` for YouTube/Vimeo.

