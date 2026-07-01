ALTER TABLE cb_external_videos
  MODIFY COLUMN authorized_download TINYINT(1) NOT NULL DEFAULT 1,
  MODIFY COLUMN download_status ENUM('none','queued','downloading','downloaded','imported','failed') NOT NULL DEFAULT 'queued',
  ADD INDEX IF NOT EXISTS idx_external_download_queue_auto (download_status, status, updated_at);

UPDATE cb_external_videos
SET authorized_download = 1,
    download_status = 'queued',
    updated_at = NOW()
WHERE download_status = 'none'
  AND clipbucket_video_id IS NULL
  AND status NOT IN ('rejected','broken','removed');
