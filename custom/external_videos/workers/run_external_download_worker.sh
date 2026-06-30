#!/bin/sh
set -eu
cd /opt/clipbucket-v5
/usr/bin/docker exec -u containeruser clipbucket-v5 sh -lc "php /srv/http/clipbucket/custom/external_videos/workers/external_download_worker.php --limit=2 >> /srv/http/clipbucket/runtime/media_import_queue/worker.log 2>&1"
