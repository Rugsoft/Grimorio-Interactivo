<?php
declare(strict_types=1);

/**
 * migrate-spec12.php — Sonda de migración SPEC-12 sobre SQLite persistente
 * (uso ÚNICO: aplicar la columna users.avatar y BORRAR del servidor).
 *
 * Patrón de probe-env.php: canal de emergencia cuando el hosting no
 * ofrece CLI ni phpMyAdmin sobre el fichero SQLite. Aplica la migración
 * de sql/12_user_panel.sql conforme a su contrato idempotente:
 * la guardia previa por PRAGMA table_info decide si el ALTER procede,
 * de modo que re-ejecutar la sonda NUNCA falla ni muta fila alguna.
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): PDO nativo, sin librerías.
 *   - AGENTS.md 6.1: jamás expone trazas crudas; salida JSON controlada.
 *
 * ⚠️ BORRAR ESTE FICHERO DEL SERVIDOR tras el diagnóstico (la guía de
 * despliegue lo prohíbe en producción: es llave de esquema).
 */

require_once __DIR__ . '/env.php';

header('Content-Type: application/json; charset=utf-8');

/** Lee si la columna `avatar` ya vive en la tabla `users` (vía PRAGMA clásica). */
function avatarColumnCount(PDO $pdo): int
{
    $columns = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC);
    $matches = array_filter($columns, static fn (array $column): bool => $column['name'] === 'avatar');

    return count($matches);
}

// Guardia previa: sin DSN materializado (o en memoria volátil) no se
// toca la base — el fallo sería borrar todo en cada petición.
//
// DOBLE CANAL del DSN (hallazgo de la primera corrida real): env.php
// del despliegue InfinityFree usa `define('GRIMORIO_DB_DSN', ...)`
// porque el sandbox tiene `putenv` en disable_functions (la llamada
// muere en silencio — verificado con la sonda del entorno);
// Connection.php resuelve PRIMERO la constante y solo recurre al
// getenv como fallback. La sonda respeta la misma precedencia.
$dsn = defined('GRIMORIO_DB_DSN') ? constant('GRIMORIO_DB_DSN') : getenv('GRIMORIO_DB_DSN');
if (!is_string($dsn) || $dsn === '' || str_contains($dsn, ':memory:')) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'DSN no materializado: revisa env.php y $projectRoot. La base NO fue tocada.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $report = [
        'dsn'          => 'sqlite (persistente)',
        'columnaAntes' => avatarColumnCount($pdo),
    ];

    // La migración conforme al contrato del guion sql/12_user_panel.sql:
    // la guardia evita el ALTER si la columna ya vive (idempotente).
    if ($report['columnaAntes'] === 0) {
        $pdo->exec('ALTER TABLE users ADD COLUMN avatar TEXT NULL');
        $report['migracion'] = 'ALTER aplicado: columna avatar añadida a users';
    } else {
        $report['migracion'] = 'La base ya porta la columna: nada que hacer (idempotente)';
    }

    $report['columnaDespues']   = avatarColumnCount($pdo);
    $report['usuarios']         = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $report['avataresVestidos'] = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE avatar IS NOT NULL')->fetchColumn();
    $report['success']          = $report['columnaDespues'] === 1;

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $migrationFailure) {
    // Sin trazas crudas: el detalle mínimo para diagnosticar y nada más.
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Fallo de migración controlado: la base NO quedó corrupta.',
        'detalle' => $migrationFailure->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
