-- ============================================================
-- Открытый сигнал — схема БД
-- MySQL / MariaDB, utf8mb4
--
-- Использование (fresh install):
--   mysql -u root -e "CREATE DATABASE fsb_portal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
--   mysql -u root fsb_portal < schema.sql
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── ПОЛЬЗОВАТЕЛИ АДМИНКИ ───────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id INT(11) NOT NULL AUTO_INCREMENT,
  login VARCHAR(50) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super','duty') NOT NULL DEFAULT 'duty',
  last_name VARCHAR(100) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  middle_name VARCHAR(100) DEFAULT '',
  created_at DATETIME NOT NULL,
  last_active DATETIME DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY login (login),
  KEY role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ОБРАЩЕНИЯ ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS appeals (
  id INT(11) NOT NULL AUTO_INCREMENT,
  appeal_id VARCHAR(30) NOT NULL,
  subject VARCHAR(300) NOT NULL,
  category VARCHAR(100) NOT NULL,
  priority ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low',
  status ENUM('new','process','review','done','rejected') NOT NULL DEFAULT 'new',
  is_anon TINYINT(1) NOT NULL DEFAULT 1,
  contact_json TEXT DEFAULT NULL,
  organ VARCHAR(300) DEFAULT '',
  location VARCHAR(300) DEFAULT '',
  event_date DATE DEFAULT NULL,
  message TEXT NOT NULL,
  ip_address VARCHAR(45) DEFAULT '',
  user_agent TEXT DEFAULT NULL,
  device_info VARCHAR(200) DEFAULT '',
  geo_country VARCHAR(100) DEFAULT '',
  geo_region VARCHAR(100) DEFAULT '',
  geo_city VARCHAR(100) DEFAULT '',
  geo_isp VARCHAR(200) DEFAULT '',
  geo_proxy TINYINT(1) DEFAULT 0,
  geo_hosting TINYINT(1) DEFAULT 0,
  spam_score INT(11) DEFAULT 0,
  spam_flags TEXT DEFAULT NULL,
  source ENUM('site','telegram') NOT NULL DEFAULT 'site',
  sender_chat_id BIGINT DEFAULT NULL,
  note TEXT DEFAULT '',
  assigned_to INT(11) DEFAULT NULL,
  closed_by INT(11) DEFAULT NULL,
  closed_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY appeal_id (appeal_id),
  KEY status (status),
  KEY category (category),
  KEY priority (priority),
  KEY created_at (created_at),
  KEY assigned_to (assigned_to),
  KEY closed_by (closed_by),
  KEY sender_chat_id (sender_chat_id),
  KEY source (source),
  CONSTRAINT appeals_ibfk_1 FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT appeals_ibfk_2 FOREIGN KEY (closed_by)   REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ФАЙЛЫ ОБРАЩЕНИЙ ────────────────────────────────────
CREATE TABLE IF NOT EXISTS appeal_files (
  id INT(11) NOT NULL AUTO_INCREMENT,
  appeal_db_id INT(11) NOT NULL,
  filename VARCHAR(300) NOT NULL,
  filesize BIGINT(20) DEFAULT 0,
  av_status VARCHAR(20) DEFAULT 'unknown',
  av_detail TEXT DEFAULT NULL,
  uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY appeal_db_id (appeal_db_id),
  CONSTRAINT appeal_files_ibfk_1 FOREIGN KEY (appeal_db_id) REFERENCES appeals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── КОММЕНТАРИИ ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS comments (
  id INT(11) NOT NULL AUTO_INCREMENT,
  appeal_db_id INT(11) NOT NULL,
  user_id INT(11) NOT NULL,
  text TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY appeal_db_id (appeal_db_id),
  KEY user_id (user_id),
  CONSTRAINT comments_ibfk_1 FOREIGN KEY (appeal_db_id) REFERENCES appeals (id) ON DELETE CASCADE,
  CONSTRAINT comments_ibfk_2 FOREIGN KEY (user_id)      REFERENCES users (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── УПОМИНАНИЯ В КОММЕНТАРИЯХ (@username) ──────────────
CREATE TABLE IF NOT EXISTS comment_mentions (
  id INT(11) NOT NULL AUTO_INCREMENT,
  comment_id INT(11) NOT NULL,
  user_id INT(11) NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY comment_id (comment_id),
  KEY user_id (user_id),
  KEY is_read (is_read),
  CONSTRAINT comment_mentions_ibfk_1 FOREIGN KEY (comment_id) REFERENCES comments (id) ON DELETE CASCADE,
  CONSTRAINT comment_mentions_ibfk_2 FOREIGN KEY (user_id)    REFERENCES users (id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ИСТОРИЯ ИЗМЕНЕНИЙ ОБРАЩЕНИЙ ────────────────────────
CREATE TABLE IF NOT EXISTS appeal_history (
  id INT(11) NOT NULL AUTO_INCREMENT,
  appeal_db_id INT(11) NOT NULL,
  user_id INT(11) DEFAULT NULL,
  field VARCHAR(64) NOT NULL,
  old_value TEXT DEFAULT NULL,
  new_value TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY appeal_db_id (appeal_db_id),
  KEY user_id (user_id),
  KEY created_at (created_at),
  CONSTRAINT appeal_history_ibfk_1 FOREIGN KEY (appeal_db_id) REFERENCES appeals (id) ON DELETE CASCADE,
  CONSTRAINT appeal_history_ibfk_2 FOREIGN KEY (user_id)      REFERENCES users (id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── СВЯЗАННЫЕ ОБРАЩЕНИЯ ────────────────────────────────
CREATE TABLE IF NOT EXISTS linked_appeals (
  id INT(11) NOT NULL AUTO_INCREMENT,
  appeal_id_a VARCHAR(30) NOT NULL,
  appeal_id_b VARCHAR(30) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_pair (appeal_id_a, appeal_id_b),
  KEY appeal_id_a (appeal_id_a),
  KEY appeal_id_b (appeal_id_b)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ЛОГ АКТИВНОСТИ ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS activity_log (
  id INT(11) NOT NULL AUTO_INCREMENT,
  user_id INT(11) DEFAULT NULL,
  action VARCHAR(50) NOT NULL,
  appeal_id VARCHAR(30) DEFAULT '',
  detail TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY user_id (user_id),
  KEY created_at (created_at),
  CONSTRAINT activity_log_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ЛОГ ДОСТУПА К ФАЙЛАМ ───────────────────────────────
CREATE TABLE IF NOT EXISTS file_access_log (
  id INT(11) NOT NULL AUTO_INCREMENT,
  user_id INT(11) DEFAULT NULL,
  appeal_id VARCHAR(30) NOT NULL,
  filename VARCHAR(300) NOT NULL,
  accessed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY user_id (user_id),
  KEY appeal_id (appeal_id),
  KEY accessed_at (accessed_at),
  CONSTRAINT file_access_log_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ПОПЫТКИ ВХОДА ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS login_attempts (
  id INT(11) NOT NULL AUTO_INCREMENT,
  login VARCHAR(50) NOT NULL,
  success TINYINT(1) DEFAULT 0,
  attempt_time DATETIME NOT NULL,
  ip VARCHAR(45) DEFAULT '',
  PRIMARY KEY (id),
  KEY login (login),
  KEY attempt_time (attempt_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ПОПЫТКИ ОТПРАВКИ ФОРМЫ (rate limit) ────────────────
CREATE TABLE IF NOT EXISTS submit_attempts (
  id INT(11) NOT NULL AUTO_INCREMENT,
  ip VARCHAR(45) NOT NULL,
  attempt_time DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ip (ip),
  KEY attempt_time (attempt_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── КЭШ GEOIP ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ip_cache (
  ip VARCHAR(45) NOT NULL,
  data TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── TELEGRAM-БОТ: пользователи ─────────────────────────
CREATE TABLE IF NOT EXISTS bot_users (
  id INT(11) NOT NULL AUTO_INCREMENT,
  chat_id BIGINT NOT NULL,
  username VARCHAR(64) DEFAULT NULL,
  language_code VARCHAR(10) DEFAULT NULL,
  role ENUM('sender','operator') NOT NULL DEFAULT 'sender',
  user_id INT(11) DEFAULT NULL,
  notify TINYINT(1) NOT NULL DEFAULT 1,
  banned TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY chat_id (chat_id),
  KEY role (role),
  KEY user_id (user_id),
  CONSTRAINT bot_users_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── TELEGRAM-БОТ: rate-limit отправок ──────────────────
CREATE TABLE IF NOT EXISTS bot_submit_attempts (
  id INT(11) NOT NULL AUTO_INCREMENT,
  chat_id BIGINT NOT NULL,
  attempt_time DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY chat_id (chat_id),
  KEY attempt_time (attempt_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── TELEGRAM-БОТ: состояния диалогов ───────────────────
CREATE TABLE IF NOT EXISTS bot_states (
  chat_id BIGINT NOT NULL,
  state VARCHAR(64) NOT NULL DEFAULT '',
  data TEXT DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (chat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
