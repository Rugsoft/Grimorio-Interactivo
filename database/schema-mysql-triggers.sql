-- =====================================================================
-- schema-mysql-triggers.sql — ANEXO OPCIONAL del gemelo dialectal
-- (SPEC-13): blindaje DDL de la inmutabilidad de la Bitácora.
--
-- ⚠️  NO IMPORTAR en anfitriones que nieguen el privilegio TRIGGER:
--     el plan gratuito de InfinityFree no lo concede a su usuario de
--     base y la importación moriría con «#1142 - TRIGGER comando
--     denegado». En ese sandbox la inmutabilidad RF-08.1 queda
--     garantizada por la CAPA DE APLICACIÓN: ningún código de src/
--     ejecuta UPDATE ni DELETE sobre audit_log (verificación estática
--     de la SPEC-13; únicos escritores: AuditService::record,
--     AuthService y AvatarService, todos por INSERT).
--
-- Cuándo importar: MySQL propio, VPS o planes completos que concedan
-- el privilegio TRIGGER. Ejecutar DESPUÉS de schema-mysql.sql (la
-- tabla audit_log debe existir). En phpMyAdmin, cada trigger va en
-- UNA sentencia independiente con cuerpo de una sola instrucción
-- (sin BEGIN...END, hallazgo del delimitador — SPEC-13).
--
-- Equivalencia dialectal: son los gemelos de los triggers
-- trg_audit_log_no_update / trg_audit_log_no_delete de
-- database/schema.sql (RAISE(ABORT) SQLite) con el mensaje solemne
-- idéntico (RF-08.1, RNF-02, Art. III).
-- =====================================================================

CREATE TRIGGER trg_audit_log_no_update
BEFORE UPDATE ON audit_log
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La Bitácora de Auditoría Arcana es inmutable: los veredictos jamás se alteran.';

CREATE TRIGGER trg_audit_log_no_delete
BEFORE DELETE ON audit_log
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La Bitácora de Auditoría Arcana es inmutable: los veredictos jamás se borran.';
