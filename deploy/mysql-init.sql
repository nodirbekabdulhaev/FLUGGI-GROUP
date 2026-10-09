-- Выполняется при первом запуске MySQL в docker-compose.yml (разработка)
CREATE DATABASE IF NOT EXISTS fluggi_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON fluggi_test.* TO 'fluggi'@'%';
FLUSH PRIVILEGES;
