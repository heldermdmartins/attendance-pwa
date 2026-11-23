CREATE TABLE activity_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_name VARCHAR(254) NOT NULL,
  event_type ENUM('login', 'attendance_marked', 'attendance_unmarked') NOT NULL,
  occurred_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
