-- Creates the test database next to the main one (the main one comes from MYSQL_DATABASE).
CREATE DATABASE IF NOT EXISTS sheets_sync_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON sheets_sync_test.* TO 'sheets'@'%';
FLUSH PRIVILEGES;
