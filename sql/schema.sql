-- ================================================
-- Multi-WordPress Management Dashboard
-- Database Schema
-- ================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------
-- Table: users
-- ------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username`     VARCHAR(60) NOT NULL UNIQUE,
  `email`        VARCHAR(120) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name`    VARCHAR(120) NOT NULL,
  `role`         ENUM('admin','writer') NOT NULL DEFAULT 'writer',
  `avatar_url`   VARCHAR(500) DEFAULT NULL,
  `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default admin user: admin / admin123
INSERT INTO `users` (`username`, `email`, `password_hash`, `full_name`, `role`) VALUES
('admin', 'admin@example.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin');

-- ------------------------------------------------
-- Table: wp_sites
-- ------------------------------------------------
CREATE TABLE IF NOT EXISTS `wp_sites` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`          VARCHAR(120) NOT NULL,
  `url`           VARCHAR(500) NOT NULL,
  `api_base_url`  VARCHAR(500) NOT NULL COMMENT 'e.g. https://mysite.com/wp-json/wp/v2',
  `app_username`  VARCHAR(120) NOT NULL COMMENT 'WordPress username for API',
  `app_password`  TEXT NOT NULL COMMENT 'AES-encrypted application password',
  `default_category` INT DEFAULT NULL COMMENT 'WP category ID',
  `status`        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `added_by`      INT UNSIGNED NOT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`added_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------
-- Table: wp_author_map
-- Mapping platform user → WP author per site
-- ------------------------------------------------
CREATE TABLE IF NOT EXISTS `wp_author_map` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`        INT UNSIGNED NOT NULL,
  `site_id`        INT UNSIGNED NOT NULL,
  `wp_author_id`   INT UNSIGNED NOT NULL COMMENT 'Author ID in WordPress',
  `wp_author_name` VARCHAR(120) NOT NULL COMMENT 'Author display name in WP',
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_user_site` (`user_id`, `site_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`site_id`) REFERENCES `wp_sites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------
-- Table: posts
-- ------------------------------------------------
CREATE TABLE IF NOT EXISTS `posts` (
  `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title`             VARCHAR(500) NOT NULL,
  `content`           LONGTEXT NOT NULL,
  `excerpt`           TEXT DEFAULT NULL,
  `slug`              VARCHAR(300) DEFAULT NULL,
  `status`            ENUM('draft','pending_publish','published','failed','scheduled') NOT NULL DEFAULT 'draft',
  `author_id`         INT UNSIGNED NOT NULL,
  `site_id`           INT UNSIGNED DEFAULT NULL,
  `wp_post_id`        INT UNSIGNED DEFAULT NULL COMMENT 'Post ID in WordPress after publish',
  `wp_post_url`       VARCHAR(500) DEFAULT NULL,
  `categories`        TEXT DEFAULT NULL COMMENT 'JSON array of WP category IDs',
  `tags`              TEXT DEFAULT NULL COMMENT 'JSON array of tag names',
  `featured_image_url` VARCHAR(500) DEFAULT NULL,
  `featured_image_wp_id` INT UNSIGNED DEFAULT NULL COMMENT 'WP Media ID after upload',
  `error_message`     TEXT DEFAULT NULL,
  `published_at`      DATETIME DEFAULT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`author_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`site_id`)   REFERENCES `wp_sites`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------
-- Table: post_schedules
-- ------------------------------------------------
CREATE TABLE IF NOT EXISTS `post_schedules` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `post_id`       INT UNSIGNED NOT NULL,
  `schedule_type` ENUM('once','hourly','daily','weekly','monthly','custom') NOT NULL DEFAULT 'once',
  `scheduled_at`  DATETIME NOT NULL COMMENT 'Next publish datetime',
  `interval_hours` INT UNSIGNED DEFAULT NULL COMMENT 'For custom: hours between runs',
  `day_of_week`   TINYINT DEFAULT NULL COMMENT '0=Sun..6=Sat for weekly',
  `day_of_month`  TINYINT DEFAULT NULL COMMENT '1-31 for monthly',
  `recur_until`   DATETIME DEFAULT NULL COMMENT 'Stop recurring after this date',
  `recur_count`   INT DEFAULT NULL COMMENT 'Max recurrences (NULL = unlimited)',
  `run_count`     INT NOT NULL DEFAULT 0 COMMENT 'How many times it has run',
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`post_id`) REFERENCES `posts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------
-- Table: activity_log
-- ------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `action`     VARCHAR(100) NOT NULL,
  `details`    TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
