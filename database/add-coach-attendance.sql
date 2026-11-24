CREATE TABLE coach_attendance (
  trainer_id INT UNSIGNED NOT NULL,
  training_date DATE NOT NULL,
  present BOOLEAN NOT NULL,
  coach_id INT UNSIGNED NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (trainer_id, training_date),
  INDEX (training_date),
  FOREIGN KEY (trainer_id) REFERENCES coaches(id),
  FOREIGN KEY (coach_id) REFERENCES coaches(id)
) ENGINE=InnoDB;

ALTER TABLE activity_log ADD COLUMN trainer_name VARCHAR(150) NULL;
