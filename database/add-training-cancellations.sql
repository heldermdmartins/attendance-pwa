CREATE TABLE training_status (
  training_date DATE PRIMARY KEY,
  cancellation_reason VARCHAR(500) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
