ALTER TABLE coaches MODIFY COLUMN club_position ENUM('coach', 'delegate', 'admin') NOT NULL DEFAULT 'coach';
