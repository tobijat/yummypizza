-- Same two tables as the original app. Safe to run more than once.

CREATE TABLE IF NOT EXISTS location (
    id INT NOT NULL AUTO_INCREMENT,
    unique_location CHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    grade TINYINT NOT NULL,
    latitude DOUBLE NOT NULL,
    longitude DOUBLE NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY unique_location (unique_location)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visit (
    id INT NOT NULL AUTO_INCREMENT,
    location INT NOT NULL,
    `date` DATE NULL,
    description TEXT NULL,
    quality TINYINT NOT NULL,
    price TINYINT NOT NULL,
    unique_visit CHAR(64) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY unique_visit (unique_visit),
    KEY visit_location (location)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
