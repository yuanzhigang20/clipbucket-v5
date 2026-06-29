CREATE TABLE IF NOT EXISTS cb_external_video_providers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider VARCHAR(80) NOT NULL,
  domain VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_external_provider_domain (domain),
  KEY idx_external_provider_active (is_active),
  KEY idx_external_provider_name (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cb_external_videos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL,
  description TEXT NULL,
  thumbnail_url TEXT NULL,
  source_url VARCHAR(2048) NOT NULL,
  embed_url VARCHAR(2048) NOT NULL,
  provider VARCHAR(80) NOT NULL,
  author VARCHAR(255) NULL,
  duration INT UNSIGNED NULL,
  tags TEXT NULL,
  category_id INT UNSIGNED NULL,
  status ENUM('pending','published','rejected','broken','removed') NOT NULL DEFAULT 'pending',
  is_18_plus TINYINT(1) NOT NULL DEFAULT 1,
  source_license TEXT NULL,
  review_note TEXT NULL,
  view_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  like_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_checked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_external_videos_source_url (source_url(768)),
  UNIQUE KEY uq_external_videos_slug (slug),
  KEY idx_external_videos_status_created (status, created_at),
  KEY idx_external_videos_provider_status (provider, status),
  KEY idx_external_videos_category_status (category_id, status),
  FULLTEXT KEY ft_external_videos_search (title, description, tags, author)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cb_external_video_providers (provider, domain, is_active) VALUES
('youtube', 'youtube.com', 1),
('youtube', 'www.youtube.com', 1),
('youtube', 'm.youtube.com', 1),
('youtube', 'youtu.be', 1),
('youtube', 'youtube-nocookie.com', 1),
('youtube', 'www.youtube-nocookie.com', 1),
('vimeo', 'vimeo.com', 1),
('vimeo', 'player.vimeo.com', 1);
