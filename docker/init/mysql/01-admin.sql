-- Minimal-privilege DBConsole admin account for MySQL — NOT root.
--
-- This is the exact shape the docs recommend for production: enough to
-- create/drop databases and accounts and manage grants, and nothing more.
-- doctor inspects this account's own grants and fails on a root-like one.

CREATE USER IF NOT EXISTS 'db_console_admin'@'%' IDENTIFIED BY 'admin-secret-change-me';

GRANT CREATE, DROP, ALTER, INDEX, REFERENCES ON *.* TO 'db_console_admin'@'%';
GRANT CREATE USER ON *.* TO 'db_console_admin'@'%';
GRANT RELOAD ON *.* TO 'db_console_admin'@'%';

-- GRANT OPTION lets the admin pass on the specific privileges it holds when
-- it runs GRANT for managed accounts. It is scoped to exactly the privileges
-- above — the admin cannot grant SUPER/FILE/etc. it does not itself hold.
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES,
      CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, CREATE VIEW, SHOW VIEW,
      CREATE ROUTINE, ALTER ROUTINE, EVENT, TRIGGER
  ON *.* TO 'db_console_admin'@'%' WITH GRANT OPTION;

FLUSH PRIVILEGES;
