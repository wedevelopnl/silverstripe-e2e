-- SapphireTest runs each test class against a throwaway database named
-- `ss_tmpdb_<random>`, which it CREATEs and DROPs itself. The mysql image only
-- grants the application user privileges on the single MYSQL_DATABASE, so without
-- this grant every database-backed test fails with "Access denied ... to database
-- 'ss_tmpdb_...'". The `\_` escapes the underscore to a literal so the pattern
-- matches the real temp-db names; `%` stays a wildcard for the random suffix.
-- Runs only on first initialisation of a fresh data volume (CI and clean checkouts).
GRANT ALL PRIVILEGES ON `ss\_tmpdb%`.* TO 'silverstripe'@'%';
FLUSH PRIVILEGES;
