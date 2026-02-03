CREATE TABLE IF NOT EXISTS `PREFIX_baal_logs` (
  `id_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NULL,
  `session_id` INT UNSIGNED NULL,
  `employee` VARCHAR(255) NULL,
  `git_branch` VARCHAR(80) NULL,
  `git_head` VARCHAR(64) NULL,
  `action` VARCHAR(50) NOT NULL,
  `object_type` VARCHAR(80) NULL,
  `object_id` INT UNSIGNED NULL,
  `parent_id` INT UNSIGNED NULL,
  `controller` VARCHAR(80) NULL,
  `http_method` VARCHAR(10) NULL,
  `request_uri` TEXT NULL,
  `ip` VARCHAR(45) NULL,
  `details` TEXT NULL,
  `changes_json` MEDIUMTEXT NULL,
  `created_at` DATETIME NOT NULL,
  `group_last_at` DATETIME NULL,
  PRIMARY KEY (`id_log`),
  INDEX `idx_parent` (`parent_id`),
  INDEX `idx_empid` (`employee_id`),
  INDEX `idx_sess` (`session_id`),
  INDEX `idx_obj_action` (`object_type`,`object_id`,`action`,`parent_id`),
  INDEX `idx_group_last` (`group_last_at`),
  INDEX `idx_admin_throttle` (`employee_id`,`object_type`,`action`,`controller`,`created_at`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_baal_git_commits` (
  `id_git` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `commit_hash` VARCHAR(64) NOT NULL,
  `author_name` VARCHAR(255) NOT NULL,
  `author_email` VARCHAR(255) NOT NULL,
  `commit_date` VARCHAR(64) NOT NULL,
  `commit_message` TEXT NOT NULL,
  `synced_at` DATETIME NOT NULL,
  PRIMARY KEY (`id_git`),
  UNIQUE KEY `uniq_hash` (`commit_hash`),
  INDEX `idx_author` (`author_name`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS `PREFIX_baal_employee_sessions` (
  `id_session` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `employee` VARCHAR(255) NULL,
  `login_at` DATETIME NOT NULL,
  `last_activity` DATETIME NULL,
  `logout_at` DATETIME NULL,
  `duration_sec` INT UNSIGNED NULL,
  `ip` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `actions_count` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id_session`),
  INDEX `idx_emp` (`employee_id`),
  INDEX `idx_login` (`login_at`),
  INDEX `idx_last_activity` (`last_activity`),
  INDEX `idx_logout` (`logout_at`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4;
