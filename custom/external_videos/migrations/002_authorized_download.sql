ALTER TABLE cb_external_videos
  ADD COLUMN IF NOT EXISTS authorized_download TINYINT(1) NOT NULL DEFAULT 0 AFTER review_note,
  ADD COLUMN IF NOT EXISTS download_status ENUM('none','queued','downloading','downloaded','imported','failed') NOT NULL DEFAULT 'none' AFTER authorized_download,
  ADD COLUMN IF NOT EXISTS local_file_path VARCHAR(1024) NULL AFTER download_status,
  ADD COLUMN IF NOT EXISTS clipbucket_video_id BIGINT UNSIGNED NULL AFTER local_file_path,
  ADD COLUMN IF NOT EXISTS download_error TEXT NULL AFTER clipbucket_video_id,
  ADD COLUMN IF NOT EXISTS download_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER download_error,
  ADD COLUMN IF NOT EXISTS reviewed_by INT UNSIGNED NULL AFTER download_attempts,
  ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL AFTER reviewed_by,
  ADD INDEX IF NOT EXISTS idx_external_download_queue (download_status, authorized_download, status, updated_at),
  ADD INDEX IF NOT EXISTS idx_external_clipbucket_video (clipbucket_video_id);

CREATE TABLE IF NOT EXISTS cb_external_video_download_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  external_video_id BIGINT UNSIGNED NOT NULL,
  level ENUM('info','warning','error') NOT NULL DEFAULT 'info',
  message TEXT NOT NULL,
  context TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_external_video_download_logs_video (external_video_id, created_at),
  KEY idx_external_video_download_logs_level (level, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
