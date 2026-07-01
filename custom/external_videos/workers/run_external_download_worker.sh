#!/bin/sh
set -eu
cd /opt/clipbucket-v5
LOG=/srv/http/clipbucket/runtime/media_import_queue/worker.log
# Run several one-item download workers concurrently. Each worker atomically claims a queued row.
# Then import downloaded files in one batch to ClipBucket's normal conversion queue.
/usr/bin/docker exec -u containeruser clipbucket-v5 sh -lc "
  php /srv/http/clipbucket/custom/external_videos/workers/external_download_worker.php --download-only --limit=1 >> $LOG 2>&1 &
  php /srv/http/clipbucket/custom/external_videos/workers/external_download_worker.php --download-only --limit=1 >> $LOG 2>&1 &
  php /srv/http/clipbucket/custom/external_videos/workers/external_download_worker.php --download-only --limit=1 >> $LOG 2>&1 &
  php /srv/http/clipbucket/custom/external_videos/workers/external_download_worker.php --download-only --limit=1 >> $LOG 2>&1 &
  wait
  php /srv/http/clipbucket/custom/external_videos/workers/external_download_worker.php --import-only --limit=5 >> $LOG 2>&1
"
