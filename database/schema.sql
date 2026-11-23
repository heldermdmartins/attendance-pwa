CREATE TABLE coaches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(254) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(150) NOT NULL DEFAULT '',
  email VARCHAR(254) NOT NULL DEFAULT '',
  phone VARCHAR(32) NOT NULL DEFAULT '',
  role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
  club_position ENUM('coach', 'delegate', 'admin') NOT NULL DEFAULT 'coach',
  active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE athletes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  details JSON NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance (
  athlete_id INT UNSIGNED NOT NULL,
  training_date DATE NOT NULL,
  present BOOLEAN NOT NULL,
  coach_id INT UNSIGNED NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (athlete_id, training_date),
  FOREIGN KEY (athlete_id) REFERENCES athletes(id),
  FOREIGN KEY (coach_id) REFERENCES coaches(id)
) ENGINE=InnoDB;

CREATE TABLE login_limits (
  address VARBINARY(16) PRIMARY KEY,
  attempts INT UNSIGNED NOT NULL,
  expires_at BIGINT UNSIGNED NOT NULL,
  INDEX (expires_at)
) ENGINE=InnoDB;
CREATE TABLE training_status (
  training_date DATE PRIMARY KEY,
  cancellation_reason VARCHAR(500) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE activity_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_name VARCHAR(254) NOT NULL,
  event_type ENUM('login', 'attendance_marked', 'attendance_unmarked') NOT NULL,
  occurred_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
