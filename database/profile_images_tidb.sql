-- Run once against the production TiDB database before uploading profile photos.
-- The API stores profile photos here so they survive Render service restarts.

CREATE TABLE IF NOT EXISTS profile_images (
    owner_type VARCHAR(16) NOT NULL,
    owner_id BIGINT UNSIGNED NOT NULL,
    mime_type VARCHAR(64) NOT NULL,
    image_data MEDIUMBLOB NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (owner_type, owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
