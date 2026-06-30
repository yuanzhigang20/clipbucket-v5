ALTER TABLE cb_external_videos
  MODIFY COLUMN authorized_download TINYINT(1) NOT NULL DEFAULT 1;
