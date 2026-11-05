-- Ejecutar una vez como administrador de PostgreSQL después de importar proyecto.sql.
-- La contraseña se solicita interactivamente; no se guarda en este archivo.
\set ON_ERROR_STOP on
SELECT 'CREATE ROLE eps_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT'
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'eps_app')
\gexec
ALTER ROLE eps_app WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT;
\password eps_app
GRANT CONNECT ON DATABASE eps_villavicencio TO eps_app;
\connect eps_villavicencio
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO eps_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO eps_app;
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO eps_app;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO eps_app;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public
    GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO eps_app;
