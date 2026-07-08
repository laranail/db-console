-- Minimal-privilege DBConsole admin account for MariaDB — NOT root.
-- Same shape as the MySQL admin (the docs recommend this for production).

CREATE USER IF NOT EXISTS 'db_console_admin'@'%' IDENTIFIED BY 'admin-secret-change-me';

GRANT CREATE, DROP, ALTER, INDEX, REFERENCES ON *.* TO 'db_console_admin'@'%';
GRANT CREATE USER ON *.* TO 'db_console_admin'@'%';
GRANT RELOAD ON *.* TO 'db_console_admin'@'%';

GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES,
      CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, CREATE VIEW, SHOW VIEW,
      CREATE ROUTINE, ALTER ROUTINE, EVENT, TRIGGER
  ON *.* TO 'db_console_admin'@'%' WITH GRANT OPTION;

FLUSH PRIVILEGES;
