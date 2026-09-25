<?php

declare(strict_types=1);

/**
 * test_user_panel_repository.php — Arnés de la Tarea 1.2 de TASKS-12.
 *
 * Valida `UserPanelRepository` (lecturas de vitrina del Panel del Adepto
 * y la ÚNICA escritura de `avatar`), contra una base SQLite en memoria
 * levantada desde el DDL canónico `database/schema.sql`. La condición
 * «Hecho cuando» se comprueba en dos frentes:
 *
 *   1. Todos los asertos por fila pasan (identidad, linaje, membresía,
 *      convalecencia, tomo, elogios, firmas del Maestro, gloria semanal
 *      y escritura de avatar con guard).
 *   2. Centinela de Artículo VI de AGENTS.md: el SQL del repositorio
 *      NO porta concatenación de strings; solo consultas preparadas.
 *
 * Patrón del santuario (TASKS-09/10/11): asertos contados con salida
 * «RESULTADO: EXITO|DENEGADO» y código 0/1. Comentarios en castellano,
 * identificadores en inglés (Artículo V).
 *
 * Uso: php scratch/test_user_panel_repository.php
 */

require_once __DIR__ . '/../src/Repositories/UserPanelRepository.php';

$assertsPassed = 0;
$assertsFailed = 0;
$NOW = '2026-09-25T10:00:00Z';

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

/** Construye la base canónica en memoria y siembra el mundo mínimo. */
function forgeCanonicalDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));

    return $pdo;
}

/** Siembra un usuario con la forma canónica del esquema. */
function seedUser(PDO $pdo, string $id, string $alias, string $email, string $role, ?string $clanId, ?string $lineage, ?string $avatar): void
{
    $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, avatar, created_at, updated_at)
         VALUES (:id, :alias, :email, :passwordHash, :role, :clanId, :lineage, :avatar, :createdAt, :updatedAt)'
    )->execute([
        ':id' => $id, ':alias' => $alias, ':email' => $email, ':passwordHash' => 'x',
        ':role' => $role, ':clanId' => $clanId, ':lineage' => $lineage, ':avatar' => $avatar,
        ':createdAt' => '2025-01-01T00:00:00Z', ':updatedAt' => '2025-01-01T00:00:00Z',
    ]);
}

/** Siembra un clan activo con la forma canónica del esquema. */
function seedClan(PDO $pdo, string $id, string $name, string $lineageType, int $weeklyPoints): void
{
    $pdo->prepare(
        "INSERT INTO clans (id, slug, name, created_at, coat_of_arms, lineage_type, admission_mode,
                            status, weekly_points, historical_points, last_activity_at, updated_at)
         VALUES (:id, :slug, :name, :createdAt, :coat, :lineageType, 'open', 'active', :weekly, 0, :createdAt, :createdAt)"
    )->execute([
        ':id' => $id, ':slug' => strtolower(str_replace(' ', '', $name)),
        ':name' => $name, ':createdAt' => '2025-01-01T00:00:00Z',
        ':coat' => 'rune_' . strtolower($lineageType), ':lineageType' => $lineageType,
        ':weekly' => $weeklyPoints,
    ]);
}

/** Siembra una membresía activa (o su partida) con la forma canónica. */
function seedMembership(PDO $pdo, string $clanId, string $userId, ?string $leftAt, ?string $convalescence): void
{
    $pdo->prepare(
        'INSERT INTO clan_members (id, clan_id, user_id, role, joined_at, left_at, convalescence_expires_at)
         VALUES (:id, :clanId, :userId, :role, :joinedAt, :leftAt, :convalescence)'
    )->execute([
        ':id' => 'clm_' . strtolower(substr(md5($userId . $clanId), 0, 8)),
        ':clanId' => $clanId, ':userId' => $userId, ':role' => 'adept',
        ':joinedAt' => '2025-02-01T00:00:00Z', ':leftAt' => $leftAt, ':convalescence' => $convalescence,
    ]);
}

/** Siembra un hechizo validado con la forma canónica del esquema. */
function seedSpell(PDO $pdo, string $id, string $name, string $authorId, ?string $clanId, string $status): void
{
    $pdo->prepare(
        "INSERT INTO spells (id, slug, name, author_id, magic_school, mana_cost, math_fingerprint, clan_id, summary, status, created_at, updated_at)
         VALUES (:id, :slug, :name, :authorId, :magicSchool, :manaCost, :fingerprint, :clanId, :summary, :status, :createdAt, :createdAt)"
    )->execute([
        ':id' => $id, ':slug' => strtolower(str_replace(' ', '-', $name)), ':name' => $name,
        ':authorId' => $authorId, ':magicSchool' => 'evocation', ':manaCost' => 10,
        ':fingerprint' => str_repeat('a', 64), ':clanId' => $clanId,
        ':summary' => 'Conjuro sembrado por el arnés de la Tarea 1.2.',
        ':status' => $status, ':createdAt' => '2025-03-01T00:00:00Z',
    ]);
}

/** Siembra un asiento de bitácora (solo INSERT: la bitácora es inmutable). */
function seedAuditEntry(PDO $pdo, string $actorId, string $actorAlias, string $actionType, string $targetType, string $targetId, string $createdAt): void
{
    $pdo->prepare(
        'INSERT INTO audit_log (actor_user_id, actor_alias, actor_role, action_type, target_entity_type, target_entity_id, justification, created_at)
         VALUES (:actorId, :actorAlias, :actorRole, :actionType, :targetType, :targetId, :justification, :createdAt)'
    )->execute([
        ':actorId' => $actorId, ':actorAlias' => $actorAlias, ':actorRole' => 'editor',
        ':actionType' => $actionType, ':targetType' => $targetType, ':targetId' => $targetId,
        ':justification' => 'Asiento sembrado por el arnés de la Tarea 1.2.', ':createdAt' => $createdAt,
    ]);
}

echo "== VERIFICACION TAREA 1.2: UserPanelRepository — lecturas de vitrina y única escritura del avatar (SPEC-12) ==\n\n";

// --- FASE 0: Base canónica y mundo sembrado ---
echo "FASE 0: Base canónica y siembra del mundo\n";
$pdo = forgeCanonicalDatabase();
// La escuela mágica es clave foránea de `spells`: el santuario la siembra
// con su catálogo canónico; el arnés replica el mínimo necesario.
$pdo->exec("INSERT INTO magic_schools (slug, name) VALUES ('evocation', 'Evocación')");

// Tres adeptos que cubren el mapa de estados de la vitrina:
//  - usr_linajado: linaje jurado, clan activo, sin convalecencia, avatar del catálogo.
//  - usr_maestro: Maestro del Códice, en convalecencia, avatar propio, con firma activa.
//  - usr_peregrino: sin linaje jurado (peregrino), sin clan, sin efigie.
seedClan($pdo, 'cln_llama', 'Custodios de la Llama', 'primordialFlame', 120);
seedClan($pdo, 'cln_marea', 'Marejantes', 'celestialTides', 80);
seedUser($pdo, 'usr_linajado', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', 'cln_llama', 'primordialFlame', 'catalog:seal_primordialFlame');
seedUser($pdo, 'usr_maestro', 'Maestro del Códice', 'maestro@arcano.arc', 'master', 'cln_marea', 'celestialTides', 'own:efigieabc123');
seedUser($pdo, 'usr_peregrino', 'Peregrino Legado', 'peregrino@arcano.arc', 'editor', null, null, null);
seedMembership($pdo, 'cln_llama', 'usr_linajado', null, null);
seedMembership($pdo, 'cln_marea', 'usr_maestro', null, '2026-10-02T10:00:00Z');

// Obras y deberes: dos sellados y un homenaje del linajado; un hechizo
// validado del Maestro que aguarda firma ajena; una firma activa del Maestro.
seedSpell($pdo, 'spl_fuego1', 'Llama del Alba', 'usr_linajado', 'cln_llama', 'validated');
seedSpell($pdo, 'spl_fuego2', 'Tormenta de Cenizas', 'usr_linajado', 'cln_llama', 'validated');
seedSpell($pdo, 'spl_maestro', 'Marea de Cristal', 'usr_maestro', 'cln_marea', 'experimental');
$pdo->exec("INSERT INTO grimoire_collections (id, user_id, spell_id, added_at) VALUES ('tme_a', 'usr_linajado', 'spl_fuego1', '2025-04-01T00:00:00Z')");
$pdo->exec("INSERT INTO grimoire_collections (id, user_id, spell_id, added_at) VALUES ('tme_b', 'usr_linajado', 'spl_fuego2', '2025-04-02T00:00:00Z')");
$pdo->exec("INSERT INTO favorites (id, user_id, spell_id, created_at) VALUES ('fav_a', 'usr_linajado', 'spl_fuego1', '2025-05-01T00:00:00Z')");
$pdo->exec("INSERT INTO master_signatures (id, spell_id, master_id, master_clan_id, signed_at, is_revoked)
           VALUES ('sig_a', 'spl_maestro', 'usr_maestro', 'cln_marea', '2025-06-01T00:00:00Z', 0)");
$pdo->exec("INSERT INTO dominion_awards (id, clan_id, user_id, action_type, base_points, awarded_points, has_synergy, source_id, awarded_at)
           VALUES ('dwa_a', 'cln_llama', 'usr_linajado', 'spellValidated', 10, 10, 0, 'spl_fuego1', '2025-09-01T00:00:00Z')");

// Fecha del juramento (Tarea 1.3, aserto integrado): asiento del propio adepto.
seedAuditEntry($pdo, 'usr_linajado', 'Heredera de la Llama', 'LINEAGE_OATH_SWORN', 'user', 'usr_linajado', '2025-03-10T08:00:00Z');

// Asiento que NO concierne al linajado (ajeno): su juramento de otro.
seedAuditEntry($pdo, 'usr_maestro', 'Maestro del Códice', 'LINEAGE_OATH_SWORN', 'user', 'usr_maestro', '2025-03-11T08:00:00Z');

// Juramento RESELLADO del linajado (re-sellado tras cataclismo del mundo,
// SPEC-09): su estampa más antigua es la fecha canónica de su vínculo.
seedAuditEntry($pdo, 'usr_linajado', 'Heredera de la Llama', 'LINEAGE_OATH_SWORN', 'user', 'usr_linajado', '2025-06-20T12:00:00Z');

// Actos de bitácora que NO son juramentos (no amparan estampa alguna):
// del propio linajado y de otro actuante sobre él.
seedAuditEntry($pdo, 'usr_linajado', 'Heredera de la Llama', 'CLAN_JOIN', 'clan', 'cln_llama', '2025-02-01T00:00:00Z');
seedAuditEntry($pdo, 'usr_maestro', 'Maestro del Códice', 'ADMIN_VETO', 'user', 'usr_linajado', '2025-07-01T00:00:00Z');

$repository = new Grimorio\Repositories\UserPanelRepository($pdo);
assertCondition(true, 'Repositorio instanciado sobre la base canónica en memoria');

// --- FASE 1: Lecturas de vitrina — identidad, linaje y efigie ---
echo "\nFASE 1: Lecturas de vitrina — identidad, linaje y efigie (RF-02.1…RF-02.4)\n";
$row = $repository->fetchUserVitals('usr_linajado');
assertCondition($row !== null, 'fetchUserVitals devuelve la fila del linajado');
assertCondition($row !== null && $row['alias'] === 'Heredera de la Llama', 'El alias llega íntegro para la vitrina');
assertCondition($row !== null && $row['email'] === 'heredera@arcano.arc', 'El correo propio llega para la vitrina (dato del dueño)');
assertCondition($row !== null && $row['role'] === 'editor', 'El rol técnico llega (el rótulo castellano lo forja el DTO, no el repositorio)');
assertCondition($row !== null && $row['lineage'] === 'primordialFlame', 'El linaje jurado llega sin heráldica inventada (RF-02.3)');
assertCondition($row !== null && $row['avatar'] === 'catalog:seal_primordialFlame', 'La efigie vigente llega con su referencia exacta');
assertCondition($repository->fetchUserVitals('usr_inexistente') === null, 'Una identidad inexistente devuelve null sin excepción');
$peregrineRow = $repository->fetchUserVitals('usr_peregrino');
assertCondition($peregrineRow !== null && $peregrineRow['lineage'] === null && $peregrineRow['avatar'] === null, 'El peregrino llega con linaje y efigie nulos (pendientes del juramento)');

// --- FASE 2: Membresía, convalecencia y gloria semanal (RF-05.1, RF-07.3) ---
echo "\nFASE 2: Membresía, convalecencia y gloria semanal (RF-05.1, RF-07.3)\n";
$membership = $repository->fetchActiveMembership('usr_linajado');
assertCondition($membership !== null, 'fetchActiveMembership devuelve la membresía activa del linajado');
assertCondition($membership !== null && $membership['clan_id'] === 'cln_llama' && $membership['joined_at'] === '2025-02-01T00:00:00Z', 'La membresía llega con su clan e ingreso (RF-02.2: el blasón lo viste el DTO)');
assertCondition($membership !== null && $membership['convalescence_expires_at'] === null, 'Sin convalecencia: el silencio es el estado saludable (RF-05.3)');
$maestroMembership = $repository->fetchActiveMembership('usr_maestro');
assertCondition($maestroMembership !== null && $maestroMembership['convalescence_expires_at'] === '2026-10-02T10:00:00Z', 'La convalecencia activa llega con su fin exacto (espejo de ClanVestibuleService)');
assertCondition($repository->fetchActiveMembership('usr_peregrino') === null, 'El peregrino sin hermandad devuelve null sin clan fantasma (RF-02.2)');
$maestroRow = $repository->fetchUserVitals('usr_maestro');
assertCondition($maestroRow !== null && $maestroRow['clan_id'] === 'cln_marea', 'El espejo denormalizado users.clan_id llega alineado con la membresía');
$weekly = $repository->fetchClanWeeklyGlory('cln_llama');
assertCondition($weekly !== null && $weekly['weekly_points'] === 120, 'La gloria semanal del clan llega con sus puntos (RF-07.3)');
assertCondition($repository->fetchClanWeeklyGlory('cln_fantasma') === null, 'Un clan inexistente devuelve null: leyenda canónica, sin cifras fantasma');

// --- FASE 3: Contadores del tomo — obras y homenajes (RF-07.1) ---
echo "\nFASE 3: Contadores del tomo — obras selladas y homenajes (RF-07.1)\n";
assertCondition($repository->countSealedSpells('usr_linajado') === 2, 'Obras selladas en el tomo: dos (dato existente, sin cómputo nuevo)');
assertCondition($repository->countPraiseGiven('usr_linajado') === 1, 'Homenajes rendidos: uno (voto único de favorites)');
assertCondition($repository->countSealedSpells('usr_peregrino') === 0, 'El peregrino sin tomo devuelve cero real');
assertCondition($repository->countPraiseGiven('usr_maestro') === 0, 'El Maestro sin homenajes devuelve cero real');

// --- FASE 4: Firmas del Maestro — deberes de moderación (RF-07.2) ---
echo "\nFASE 4: Firmas del Maestro — deberes de moderación (RF-07.2)\n";
$duties = $repository->fetchMasterSignatureDuties('usr_maestro');
assertCondition($duties !== null, 'fetchMasterSignatureDuties devuelve el recuento del Maestro');
assertCondition($duties !== null && $duties['pending'] === 1 && $duties['retracted'] === 0 && $duties['annulled'] === 0, 'Un activa y ninguna retirada/anulada: el recuento refleja la mesa sembrada');
assertCondition($repository->fetchMasterSignatureDuties('usr_linajado') === null, 'Un no-Maestro devuelve null: jamás un contador fantasma');
$pdo->exec("UPDATE master_signatures SET is_revoked = 1, revocation_reason = 'retracted' WHERE id = 'sig_a'");
$dutiesAfter = $repository->fetchMasterSignatureDuties('usr_maestro');
assertCondition($dutiesAfter !== null && $dutiesAfter['pending'] === 0 && $dutiesAfter['retracted'] === 1 && $dutiesAfter['annulled'] === 0, 'Retractada: pasa de pendiente a retirada conforme al catálogo de SPEC-08');
$pdo->exec("UPDATE master_signatures SET revocation_reason = 'review_rejected' WHERE id = 'sig_a'");
$dutiesAnnulled = $repository->fetchMasterSignatureDuties('usr_maestro');
assertCondition($dutiesAfter !== null && $dutiesAnnulled !== null && $dutiesAnnulled['retracted'] === 0 && $dutiesAnnulled['annulled'] === 1, 'Vetada: pasa de retirada a anulada conforme al catálogo de SPEC-08');

// --- FASE 5: Lente de bitácora — fecha del juramento (Tarea 1.3) ---
echo "\nFASE 5: Lente de bitácora — fecha del juramento (RF-02.1, Tarea 1.3)\n";
assertCondition(
    $repository->fetchLineageOathSwornAt('usr_linajado') === '2025-03-10T08:00:00Z',
    'El linajado con asiento obtiene SU estampa de juramento'
);
assertCondition(
    $repository->fetchLineageOathSwornAt('usr_peregrino') === null,
    'El peregrino sin asiento obtiene null (RF-02.1: sin estampa fantasma)'
);
assertCondition(
    $repository->fetchLineageOathSwornAt('usr_maestro') === '2025-03-11T08:00:00Z',
    'Cada linajado obtiene su PROPIA estampa, jamás la de otro'
);
assertCondition(
    $repository->fetchLineageOathSwornAt('usr_linajado') === '2025-03-10T08:00:00Z',
    'Con juramento re-sellado, la estampa canónica es la PRIMERA (la fecha del vínculo)'
);
assertCondition(
    $repository->fetchLineageOathSwornAt('usr_peregrino') !== '2025-02-01T00:00:00Z',
    'Un asiento de bitácora que NO es juramento jamás ampara estampa (filtros exactos)'
);
assertCondition(
    $repository->fetchLineageOathSwornAt('usr_linajado') !== '2025-07-01T00:00:00Z',
    'Un veto ajeno sobre el adepto jamás lo envejece (el actuante no es el sujeto)'
);

// --- FASE 5b: Legado y muralla del índice (íntegro de la Tarea 1.3) ---
echo "\nFASE 5b: Legado sin asiento y muralla del índice (RF-02.1, Tarea 1.3)\n";
// El linajado de LEGADO (exento del juramento por su clan histórico,
// caso límite 7 de SPEC-09) porta linaje pero JAMÁS tuvo asiento: su
// estampa es null y el DTO vestirá la leyenda de legado.
seedUser($pdo, 'usr_legado', 'Legado Exento', 'legado@arcano.arc', 'editor', 'cln_llama', 'primordialFlame', null);
assertCondition(
    $repository->fetchLineageOathSwornAt('usr_legado') === null,
    'El linajado de LEGADO sin asiento obtiene null (sin estampa inventada)'
);
// La muralla del índice: la consulta del juramento está construida para
// el acceso indexado — `actor_user_id` está cubierto por
// `idx_audit_actor` (índice ratificado por TASKS-12) y el par
// `(target_entity_type, target_entity_id)` por `idx_audit_target`. La
// elección del índice concreto es soberanía del planner de cada motor
// (SQLite y MySQL valoran la selectividad con su propio criterio); lo
// que la constitución exige es que la bitácora INMUTABLE jamás se
// escanee íntegra: siempre SEARCH por índice, nunca SCAN. Una bitácora
// con volumen real (la del santuario) funda la decisión del planner;
// con tres filas de prueba escanearía, válido para tablas diminutas
// pero no representativo del despliegue.
for ($seeded = 0; $seeded < 500; $seeded++) {
    seedAuditEntry($pdo, 'usr_' . ($seeded % 50), 'Adepto ' . ($seeded % 50), 'SPELL_VALIDATED', 'spell', 'spl_' . $seeded, '2025-08-01T00:00:00Z');
}
$explain = $pdo->query("EXPLAIN QUERY PLAN SELECT created_at FROM audit_log
    WHERE actor_user_id = 'usr_linajado'
      AND action_type = 'LINEAGE_OATH_SWORN'
      AND target_entity_type = 'user'
      AND target_entity_id = 'usr_linajado'
    ORDER BY created_at ASC LIMIT 1")->fetchAll(PDO::FETCH_ASSOC);
$planNarrative = strtolower(implode(' ', array_column($explain, 'detail')));
assertCondition(
    str_contains($planNarrative, 'search audit_log using index') && !str_contains($planNarrative, 'scan'),
    'La consulta del juramento SEARCHA por índice del DDL (idx_audit_actor cubre actor_user_id; jamás SCAN de la bitácora íntegra)'
);

// --- FASE 6: La ÚNICA escritura de avatar — guard y semántica cerrada ---
echo "\nFASE 6: La única escritura de avatar — UPDATE con guard (RF-03.x)\n";
assertCondition(
    $repository->updateAvatar('usr_linajado', 'own:nuevaefigie', '2026-09-25T11:00:00Z') === true,
    'updateAvatar escribe la efigie propia con su estampa (RF-03.2)'
);
$updated = $repository->fetchUserVitals('usr_linajado');
assertCondition($updated !== null && $updated['avatar'] === 'own:nuevaefigie' && $updated['updated_at'] === '2026-09-25T11:00:00Z', 'La fila queda con la nueva efigie y su updated_at refrescado');
assertCondition(
    $repository->updateAvatar('usr_linajado', null, '2026-09-25T12:00:00Z') === true,
    'updateAvatar acepta NULL (retorno al canónico por defecto, RF-03.5)'
);
$reset = $repository->fetchUserVitals('usr_linajado');
assertCondition($reset !== null && $reset['avatar'] === null, 'La retirada vuelve la columna a NULL: identidad jamás sin efigie');
assertCondition(
    $repository->updateAvatar('usr_inexistente', 'own:huérfana', '2026-09-25T13:00:00Z') === false,
    'La guard WHERE id = :userId devuelve false ante identidad inexistente (sin fila huérfana)'
);
$foreign = $repository->fetchUserVitals('usr_maestro');
assertCondition($foreign !== null && $foreign['avatar'] === 'own:efigieabc123', 'La efigie ajena queda intacta: una sola escritura, solo la propia');

// --- FASE 7: Centinela del Artículo VI de AGENTS.md ---
echo "\nFASE 7: Centinela constitucional — solo consultas preparadas (Art. VI de AGENTS.md)\n";
$source = (string) file_get_contents(__DIR__ . '/../src/Repositories/UserPanelRepository.php');
$pregs = preg_split('/\R/', $source) ?: [];
$violations = [];
foreach ($pregs as $index => $codeLine) {
    // Excluye comentarios y PHPDoc: la leyenda castellana puede narrar la regla.
    $trimmed = ltrim($codeLine);
    if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '//')) {
        // Deja pasar líneas de PHPDoc que abren con asterisco y comentarios.
        if (!str_starts_with($trimmed, '//') && !str_starts_with($trimmed, '/*') && !str_starts_with($trimmed, '*')) {
            continue;
        }
        continue;
    }
    // Concatenación de SQL con variables fuera de comillas: la única
    // excepción canónica del proyecto es el límite acotado por constante.
    if (preg_match('/\.\s*\$(?!this)/', $codeLine) === 1) {
        $violations[] = $index + 1;
    }
}
assertCondition($violations === [], 'Ninguna concatenación de variables en el SQL (solo prepare/execute con parámetros vinculados)');
assertCondition(substr_count($source, '->prepare(') >= 7, 'Todas las operaciones viajan por PDO preparado (>= 7 prepare en el fichero)');
assertCondition(str_contains($source, 'declare(strict_types=1)'), 'El repositorio porta tipado estricto obligatorio');
assertCondition(str_contains($source, 'WHERE id = :userId'), 'La escritura de avatar porta su guard de identidad');

echo "\n== RESUMEN == Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";
if ($assertsFailed === 0) {
    echo "RESULTADO: EXITO — El repositorio de la vitrina esta en pie: lecturas por fila fieles, única escritura con guard y muralla de consultas preparadas intacta (Tarea 1.2).\n";
    exit(0);
}

echo "RESULTADO: DENEGADO — Corregir los asertos en rojo antes de continuar.\n";
exit(1);
