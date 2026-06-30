ALTER TABLE cb_external_videos
  ADD COLUMN IF NOT EXISTS download_url VARCHAR(2048) NULL AFTER embed_url,
  ADD INDEX IF NOT EXISTS idx_external_download_url (download_url(191));
