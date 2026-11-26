-- Revierte 021_cambio_password.sql.
SET NAMES utf8mb4;
ALTER TABLE usuarios DROP COLUMN debe_cambiar_password;
