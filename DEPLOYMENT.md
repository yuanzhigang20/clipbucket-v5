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
