ALTER TABLE coaches MODIFY COLUMN username VARCHAR(254) NOT NULL;
UPDATE coaches SET username = TRIM(email), email = TRIM(email) WHERE TRIM(email) <> '';
