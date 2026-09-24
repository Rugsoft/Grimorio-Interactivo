<?php

/**
 * test_clans_lineages_schema.php — Verificación de la Tarea 1.1 de TASKS-07.
 *
 * Valida contra el criterio «Hecho cuando» de
 * specs/07-clans-lineages.tasks.md:
 *   «La ejecución del script SQL sobre SQLite crea las 5 tablas sin errores
 *    de sintaxis y los índices de unicidad impiden insertar dos membresías
 *    activas simultáneas para un mismo user_id.»
 *
 * Estrategia: se levanta una base SQLite efímera en memoria y se aplica la
 * secuencia canónica real del santuario —database/schema.sql y
 * database/seeds.sql—, comprobando después el contrato estructural y
 * funcional del dominio de SPEC-07.
 *
 * Nota de evolución: el dominio de SPEC-07 se declaraba antes en la migración
 * sql/07_clans_lineages_schema.sql; hoy `database/schema.sql` es el DDL
 * canónico completo (un esquema repartido dejaba sin tablas a toda base
 * levantada solo con el esquema raíz). Aquel script subsiste como vía de
 * ascenso para bases legadas y su existencia se comprueba en la Fase 0.
 *
 * Fases:
 *   [0] Superficie: los tres scripts SQL existen.
 *   [1] La secuencia completa se ejecuta sin errores SQL.
 *   [2] Las 5 tablas del dominio de SPEC-07 existen.
 *   [3] `clans` conserva las columnas de SPEC-01 y gana las de SPEC-07.
 *   [4] Los índices exigidos existen; `idx_active_member` es ÚNICO y PARCIAL
 *       e `idx_clans_name_reserved` reserva el Nombre Canónico a perpetuidad.
 *   [5] El índice condicional impide dos membresías activas simultáneas.
 *   [6] Cerrada la membresía (`left_at`), el mismo usuario puede volver a
 *       afiliarse: el índice es CONDICIONAL, no plano (RF-01.6, Art. III).
 *   [7] Integridad referencial a `users(id)` y `clans(id)`.
 *   [8] Protección del Nombre Canónico incluso con el clan archivado (RF-05.4).
 *   [9] `daily_simulator_tracker` impone un único acumulado por día (RF-03.2).
 *  [10] El patrimonio del santuario queda intacto y sin dependencias externas.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sin librerías externas.
 *   - Artículo V: identificadores en inglés snake_case; narrativa en castellano.
 *
 * Uso: php scratch/test_clans_lineages_schema.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

declare(strict_types=1);

$assertsPassed = 0;
$assertsFailed = 0;

/** Aserta una condición y registra el resultado en la bitácora de consola. */
function assertCondition(bool $condition, string $description): void
{
    global $assertsPassed, $assertsFailed;
    if ($condition) {
        $assertsPassed++;
        echo "  [PASA] {$description}\n";
    } else {
        $assertsFailed++;
        echo "  [FALLA] {$description}\n";
    }
}

/** Normaliza espacios en blanco para comparar el DDL almacenado por SQLite. */
function normalizeSql(string $sql): string
{
    return (string) preg_replace('/\s+/', ' ', trim($sql));
}

echo "== VERIFICACION TAREA 1.1: Esquema DDL de Clanes, Linajes y Dominio ==\n\n";

$projectRoot = dirname(__DIR__);
$schemaPath = $projectRoot . '/database/schema.sql';
$seedsPath = $projectRoot . '/database/seeds.sql';
$migrationPath = $projectRoot . '/sql/07_clans_lineages_schema.sql';

// --- FASE 0: Superficie de los scripts SQL ---
echo "FASE 0: Superficie de los scripts SQL\n";
assertCondition(file_exists($schemaPath), 'Existe el esquema raiz database/schema.sql');
assertCondition(file_exists($seedsPath), 'Existen las semillas database/seeds.sql');
assertCondition(file_exists($migrationPath), 'Existe la migracion sql/07_clans_lineages_schema.sql');
assertCondition(
    file_exists(__DIR__ . '/../sql/07_retire_domain_points.sql'),
    'Existe la migracion sql/07_retire_domain_points.sql (un solo contador de gloria)'
);

if (!file_exists($migrationPath)) {
    echo "\nRESULTADO: DENEGADO — falta la migracion de SPEC-07 (fase roja del TDD).\n";
    exit(1);
}

// Base de datos SQLite efímera en memoria: jamás contamina el santuario real.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON;');

// --- FASE 1: Ejecución de la secuencia canónica completa ---
echo "\nFASE 1: Ejecucion de la secuencia canonica (schema + seeds)\n";
$executionOk = true;
try {
    $pdo->exec((string) file_get_contents($schemaPath));
    $pdo->exec((string) file_get_contents($seedsPath));
} catch (PDOException $e) {
    $executionOk = false;
    echo '  [FALLA] La secuencia SQL lanzo excepcion: ' . $e->getMessage() . "\n";
}
assertCondition($executionOk, 'schema.sql + seeds.sql se ejecutan sin errores de sintaxis');

if (!$executionOk) {
    echo "\nRESULTADO: DENEGADO — el DDL canonico no se aplica limpiamente.\n";
    exit(1);
}

// --- FASE 2: Las 5 tablas del dominio de SPEC-07 ---
echo "\nFASE 2: Las cinco tablas del dominio de SPEC-07\n";
$existingTables = $pdo->query(
    "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
)->fetchAll(PDO::FETCH_COLUMN);

foreach (['clans', 'clan_members', 'clan_applications', 'weekly_cycles', 'daily_simulator_tracker'] as $requiredTable) {
    assertCondition(
        in_array($requiredTable, $existingTables, true),
        "Existe la tabla '{$requiredTable}'"
    );
}

// --- FASE 3: La tabla `clans` conserva SPEC-01 y gana SPEC-07 ---
echo "\nFASE 3: Columnas de la tabla `clans` (sin regresion de SPEC-01)\n";
$clanColumns = array_column($pdo->query('PRAGMA table_info(clans)')->fetchAll(PDO::FETCH_ASSOC), 'name');

// Columnas vivas de SPEC-01 (ClanController / clansPreviewView.js dependen de ellas).
foreach (['id', 'slug', 'name', 'motto', 'created_at'] as $legacyColumn) {
    assertCondition(in_array($legacyColumn, $clanColumns, true), "Conserva la columna de SPEC-01 '{$legacyColumn}'");
}

// Un solo contador de gloria (Tarea 2.6): `domain_points` medía lo mismo que
// `weekly_points` y carecía de escritor, así que quedó retirada del plano.
assertCondition(
    in_array('domain_points', $clanColumns, true) === false,
    'La columna vestigial domain_points ha sido retirada: un solo contador semanal'
);
assertCondition(
    in_array('weekly_points', $clanColumns, true) && in_array('historical_points', $clanColumns, true),
    'Quedan los dos contadores canónicos de SPEC-07, uno por concepto (RF-03, RF-04.3)'
);

// Columnas nuevas aportadas por el dominio de SPEC-07.
foreach ([
    'coat_of_arms', 'lineage_type', 'admission_mode', 'status', 'patriarch_id',
    'weekly_points', 'historical_points', 'last_activity_at', 'updated_at',
] as $newColumn) {
    assertCondition(in_array($newColumn, $clanColumns, true), "Incorpora la columna de SPEC-07 '{$newColumn}'");
}

// Relleno temporal de las filas preexistentes (linaje fundacional).
$legacyClan = $pdo->query(
    "SELECT created_at, last_activity_at, updated_at, lineage_type, status FROM clans WHERE id = 'cln_primordial'"
)->fetch(PDO::FETCH_ASSOC);
assertCondition(
    $legacyClan !== false && $legacyClan['last_activity_at'] === $legacyClan['created_at'],
    'El linaje fundacional hereda su last_activity_at de la fecha de genesis'
);
assertCondition(
    $legacyClan !== false && $legacyClan['lineage_type'] === 'primordialFlame' && $legacyClan['status'] === 'active',
    'El linaje fundacional queda en Linaje de la Llama Primordial y estado activo'
);

// --- FASE 4: Índices exigidos y naturaleza del índice condicional ---
echo "\nFASE 4: Indices exigidos por el plan 2.1\n";
$indexRows = $pdo->query(
    "SELECT name, tbl_name, sql FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL"
)->fetchAll(PDO::FETCH_ASSOC);
$indexNames = array_column($indexRows, 'name');
$indexSqlByClanMembers = '';
foreach ($indexRows as $indexRow) {
    if ($indexRow['tbl_name'] === 'clan_members') {
        $indexSqlByClanMembers .= ' ' . normalizeSql((string) $indexRow['sql']);
    }
}

foreach (['idx_active_member', 'idx_clans_name_reserved', 'idx_clans_leaderboard', 'idx_clans_historical', 'idx_applications_user', 'idx_member_history_ethics'] as $requiredIndex) {
    assertCondition(in_array($requiredIndex, $indexNames, true), "Existe el indice '{$requiredIndex}'");
}

// La reserva perpetua del Nombre Canonico (RF-05.4) ha de ser UNICA y global.
$reservedNameIndex = null;
foreach ($pdo->query('PRAGMA index_list(clans)')->fetchAll(PDO::FETCH_ASSOC) as $indexListRow) {
    if ($indexListRow['name'] === 'idx_clans_name_reserved') {
        $reservedNameIndex = $indexListRow;
    }
}
assertCondition(
    $reservedNameIndex !== null && (int) $reservedNameIndex['unique'] === 1,
    'El indice idx_clans_name_reserved es UNICO (Nombre Canonico reservado a perpetuidad)'
);

// El indice de pertenencia unica debe ser UNICO y PARCIAL (WHERE left_at IS NULL).
$activeMemberIndex = null;
foreach ($pdo->query('PRAGMA index_list(clan_members)')->fetchAll(PDO::FETCH_ASSOC) as $indexListRow) {
    if ($indexListRow['name'] === 'idx_active_member') {
        $activeMemberIndex = $indexListRow;
    }
}
assertCondition(
    $activeMemberIndex !== null && (int) $activeMemberIndex['unique'] === 1,
    'El indice idx_active_member es UNICO'
);
assertCondition(
    $activeMemberIndex !== null && (int) $activeMemberIndex['partial'] === 1,
    'El indice idx_active_member es PARCIAL (no plena unicidad de historial)'
);
assertCondition(
    str_contains(strtolower($indexSqlByClanMembers), 'where left_at is null'),
    'El indice idx_active_member acota su unicidad a las filas con left_at IS NULL'
);

// --- FASE 5: Pertenencia única simultánea (criterio «Hecho cuando») ---
echo "\nFASE 5: El indice condicional impide dos membresias activas simultaneas\n";
$now = '2026-09-14T12:00:00Z';
$pdo->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
            VALUES ('usr_adepto_uno', 'Adepto Uno', 'uno@arcano.arc', 'x', 'editor', 'cln_primordial', '{$now}', '{$now}')");
$pdo->exec("INSERT INTO clans (id, slug, name, motto, created_at)
            VALUES ('cln_mareas', 'mareas-celestiales', 'Mareas Celestiales', 'Fluye lo eterno', '{$now}')");

$pdo->exec("INSERT INTO clan_members (id, clan_id, user_id, role, joined_at)
            VALUES ('clm_uno', 'cln_primordial', 'usr_adepto_uno', 'adept', '{$now}')");
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM clan_members WHERE user_id = 'usr_adepto_uno' AND left_at IS NULL")->fetchColumn() === 1,
    'Se registra la primera membresia activa del adepto'
);

$duplicateBlocked = false;
try {
    $pdo->exec("INSERT INTO clan_members (id, clan_id, user_id, role, joined_at)
                VALUES ('clm_dos', 'cln_mareas', 'usr_adepto_uno', 'adept', '{$now}')");
} catch (PDOException $e) {
    $duplicateBlocked = true;
}
assertCondition($duplicateBlocked, 'Una segunda membresia ACTIVA del mismo user_id es rechazada por el indice unico');

// --- FASE 6: El índice es condicional, no plano (historial legítimo) ---
echo "\nFASE 6: El historial cerrado no bloquea una nueva afiliacion (Art. III)\n";
$pdo->exec("UPDATE clan_members SET left_at = '2026-08-01T00:00:00Z',
            convalescence_expires_at = '2026-08-15T00:00:00Z' WHERE id = 'clm_uno'");
$reaffiliationAllowed = true;
try {
    $pdo->exec("INSERT INTO clan_members (id, clan_id, user_id, role, joined_at)
                VALUES ('clm_tres', 'cln_mareas', 'usr_adepto_uno', 'adept', '2026-09-14T13:00:00Z')");
} catch (PDOException $e) {
    $reaffiliationAllowed = false;
    echo '  [FALLA] La reafiliacion lanzo excepcion: ' . $e->getMessage() . "\n";
}
assertCondition(
    $reaffiliationAllowed,
    'Cerrada la membresia previa (left_at), el mismo user_id puede afiliarse de nuevo'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM clan_members WHERE user_id = 'usr_adepto_uno'")->fetchColumn() === 2,
    'El historial de membresia se preserva integro (dos registros: uno cerrado, uno activo)'
);

// --- FASE 7: Integridad referencial ---
echo "\nFASE 7: Integridad referencial a users(id) y clans(id)\n";
$orphanUserBlocked = false;
try {
    $pdo->exec("INSERT INTO clan_members (id, clan_id, user_id, role, joined_at)
                VALUES ('clm_huerfano', 'cln_mareas', 'usr_inexistente', 'adept', '{$now}')");
} catch (PDOException $e) {
    $orphanUserBlocked = true;
}
assertCondition($orphanUserBlocked, 'La FK clan_members.user_id -> users.id rechaza adeptos huerfanos');

$orphanClanBlocked = false;
try {
    $pdo->exec("INSERT INTO clan_members (id, clan_id, user_id, role, joined_at)
                VALUES ('clm_huerfano2', 'cln_inexistente', 'usr_adepto_uno', 'patriarch', '{$now}')");
} catch (PDOException $e) {
    $orphanClanBlocked = true;
}
assertCondition($orphanClanBlocked, 'La FK clan_members.clan_id -> clans.id rechaza clanes inexistentes');

// El rol canónico queda acotado por CHECK (RF-01.3).
$invalidRoleBlocked = false;
try {
    $pdo->exec("INSERT INTO clan_members (id, clan_id, user_id, role, joined_at)
                VALUES ('clm_rol', 'cln_mareas', 'usr_adepto_uno', 'rey', '{$now}')");
} catch (PDOException $e) {
    $invalidRoleBlocked = true;
}
assertCondition($invalidRoleBlocked, 'La restriccion CHECK rechaza roles fuera del canon patriarch/adept');

// --- FASE 8: Protección del Nombre Canónico (RF-05.4) ---
echo "\nFASE 8: El Nombre Canonico es unico, incluso con el clan archivado\n";
$pdo->exec("UPDATE clans SET status = 'archived' WHERE id = 'cln_mareas'");
$usurpationBlocked = false;
try {
    $pdo->exec("INSERT INTO clans (id, slug, name, motto, created_at)
                VALUES ('cln_usurpador', 'mareas-celestiales-ii', 'Mareas Celestiales', 'Lema usurpado', '{$now}')");
} catch (PDOException $e) {
    $usurpationBlocked = true;
}
assertCondition($usurpationBlocked, 'Un clan nuevo no puede reutilizar el nombre de un clan archivado (RF-05.4)');

// --- FASE 9: Techo diario del simulador (RF-03.2, RNF-02) ---
echo "\nFASE 9: Acumulado diario unico por adepto y clan\n";
$pdo->exec("INSERT INTO daily_simulator_tracker (id, user_id, clan_id, cycle_date, points_awarded)
            VALUES ('dsk_uno', 'usr_adepto_uno', 'cln_mareas', '2026-09-14', 10)");
$dailyDuplicateBlocked = false;
try {
    $pdo->exec("INSERT INTO daily_simulator_tracker (id, user_id, clan_id, cycle_date, points_awarded)
                VALUES ('dsk_dos', 'usr_adepto_uno', 'cln_mareas', '2026-09-14', 10)");
} catch (PDOException $e) {
    $dailyDuplicateBlocked = true;
}
assertCondition(
    $dailyDuplicateBlocked,
    'UNIQUE(user_id, clan_id, cycle_date) impide duplicar el acumulado diario del simulador'
);

// El corte a las 00:00:00 UTC se materializa como una nueva fila por fecha.
$nextDayAllowed = true;
try {
    $pdo->exec("INSERT INTO daily_simulator_tracker (id, user_id, clan_id, cycle_date, points_awarded)
                VALUES ('dsk_tres', 'usr_adepto_uno', 'cln_mareas', '2026-09-15', 10)");
} catch (PDOException $e) {
    $nextDayAllowed = false;
}
assertCondition($nextDayAllowed, 'El dia UTC siguiente abre un nuevo acumulado (reinicio a las 00:00:00 UTC)');

// --- FASE 10: Patrimonio inviolable y Dogma Vanilla ---
echo "\nFASE 10: Patrimonio inviolable y ausencia de dependencias externas\n";
// El DDL canónico y la vía de ascenso de SPEC-07 jamás destruyen patrimonio.
// (La migración de membresía sql/07_membership_single_source.sql queda fuera
// de este cotejo: su paso de relajación copia los valores antes de soltar y
// reconstruir la columna, procedimiento documentado en su cabecera.)
$destructive = false;
foreach ([$schemaPath, $migrationPath] as $ddlPath) {
    $normalizedDdl = strtolower(normalizeSql((string) file_get_contents($ddlPath)));
    foreach (['drop table', 'drop index', 'delete from', 'truncate'] as $destructiveStatement) {
        if (str_contains($normalizedDdl, $destructiveStatement)) {
            $destructive = true;
        }
    }
}
assertCondition(
    !$destructive,
    'El DDL canonico no contiene sentencias destructivas (DROP/DELETE/TRUNCATE) sobre el patrimonio'
);
// Se ejerce también la columna `users.clan_id` anulable (RF-01.2).
$pdo->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
            VALUES ('usr_intacto', 'Adepto Intacto', 'intacto@arcano.arc', 'x', 'editor', NULL, '{$now}', '{$now}')");
$pdo->exec("INSERT INTO clan_members (id, clan_id, user_id, role, joined_at)
            VALUES ('clm_intacto', 'cln_primordial', 'usr_intacto', 'adept', '{$now}')");
$clanCount = (int) $pdo->query('SELECT COUNT(*) FROM clans')->fetchColumn();
$memberCount = (int) $pdo->query('SELECT COUNT(*) FROM clan_members')->fetchColumn();
assertCondition($clanCount >= 2 && $memberCount >= 3, 'Las tablas admiten escritura normal tras migrar (sin bloqueos)');

// --- Resumen final ---
echo "\n== RESUMEN ==\n";
echo "Asertos superados: {$assertsPassed}\n";
echo "Asertos fallidos:  {$assertsFailed}\n";

if ($assertsFailed === 0) {
    echo "\nRESULTADO: EXITO — La Tarea 1.1 cumple su criterio 'Hecho cuando'.\n";
    exit(0);
}

echo "\nRESULTADO: DENEGADO — Corregir los asertos marcados con [FALLA].\n";
exit(1);
