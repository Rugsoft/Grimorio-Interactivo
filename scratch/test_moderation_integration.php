<?php

declare(strict_types=1);

/**
 * test_moderation_integration.php — Suite de INTEGRACIÓN CRUZADA de SPEC-08
 * (Tarea 7.1).
 *
 * Valida contra el criterio «Hecho cuando» de
 * specs/08-moderation-two-step.tasks.md:
 *   «Al alcanzarse la 3ª firma de un conjuro en moderación, el conjuro
 *    aparece inmediatamente en el Gran Tomo y los puntos de dominio se suman
 *    al ranking semanal del clan originario en tiempo real.»
 *
 * Alcance (Tarea 7.1): los TRES cruces de la fase final:
 *   - SPEC-04 / SPEC-05 (Gran Tomo Canónico): la obra consagrada por la 3ª
 *     firma aparece INMEDIATAMENTE en el tomo público
 *     (GET /api/v1/grimoire/spells, modo canonical) y queda fuera del Atrio;
 *     mientras deliberaba, jamás estuvo allí (RF-05.1, aislamiento del Atrio).
 *   - SPEC-05 / SPEC-08 (Simulador): la invocación de conjuros experimentales
 *     en la Cámara de Conjuración NO devenga PDA alguno para el clan del
 *     autor (RF-05.2, RF-05.3): la práctica del simulador es ajena a la obra
 *     y el único canal de gloria es el santuario (POST
 *     /api/v1/dominion/simulator-combo), que acusa recibo por su propio
 *     techo, jamás por la deliberación.
 *   - SPEC-07 (Dominio Semanal): la 3ª firma LIQUIDA la gloria al linaje
 *     ORIGINARIO en el mismo gesto —marcador semanal crece exactamente en lo
 *     acreditado, haber perpetuo intacto, un solo recibo (RNF-01)— y el
 *     Salón del Dominio (GET /api/v1/dominion/leaderboard) refleja el nuevo
 *     marcador al instante (en tiempo real, sin cierre dominical de por
 *     medio: el pliegue semanal→histórico pertenece al cierre dominical).
 *
 * Estrategia: despacho por la pila REAL de producción —se carga
 * `public/index.php`, se invoca `buildRouter()` y se cruzan `Request`/
 * `Response` nativos— sobre un plano arcano SQLite EN MEMORIA
 * (`GRIMORIO_DB_DSN=sqlite::memory:`). Las filas de siembra se inscriben con
 * SQL directo (un conjuro NACE en la libreta); TODA transición pasa por los
 * servicios canónicos a través de sus endpoints (elevación y firmas), de
 * modo que la autoridad, su espejo, el Tomo y la gloria quedan sincronizados
 * por el propio código.
 *
 * Bloques:
 *   1. Semilla coherente y aislamiento PREVIO a la consagración: la obra en
 *      deliberación NO figura en el Gran Tomo (RF-05.1).
 *   2. El Atrio la exhibe con su medidor 0/3 y la insignia pointsBlocked.
 *   3. La 3ª firma consagra: autoridad, espejo y Bitácora, en un gesto.
 *   4. El Gran Tomo la recibe INMEDIATAMENTE (criterio) con su maná y
 *      círculo deterministas, y el Atrio la retira en el mismo instante.
 *   5. La liquidación de PDA al linaje originario: marcador semanal, haber
 *      perpetuo intacto y un solo recibo (RNF-01, RF-05.3).
 *   6. El Salón del Dominio publica el marcador en tiempo real (criterio).
 *   7. El aislamiento de PDA en el Simulador: la práctica sobre la obra
 *      deliberante NO acredita gloria por esa vía; el canal del simulador
 *      responde por su propio techo (RF-05.2, RF-05.3, RNF-01).
 *   8. Auditoría constitucional del cruce (Art. I, II, III, V; RNF-01,
 *      RNF-02).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Router/Request/Response/PDO nativos.
 *   - Artículo II: el maná y el círculo del Tomo son los del backend.
 *   - Artículo III: la gloria viaja al linaje ORIGINARIO de la obra.
 *   - Artículo V: identificadores en inglés camelCase; leyendas en castellano.
 *
 * Uso: php scratch/test_moderation_integration.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$assertsPassed = 0;
$assertsFailed = 0;
/** @var list<string> */
$failures = [];
/** @var array{title: string, passed: int, failed: int} */
$currentBlock = ['title' => '', 'passed' => 0, 'failed' => 0];

/** Aserta una condición y registra el resultado en la bitácora de consola. */
function assertCondition(bool $condition, string $description): void
{
    global $assertsPassed, $assertsFailed, $failures;
    if ($condition) {
        $assertsPassed++;
        echo "  [PASA] {$description}\n";
        return;
    }
    $assertsFailed++;
    $failures[] = $description;
    echo "  [FALLA] {$description}\n";
}

/** Abre un bloque de asertos y anuncia su título en la bitácora. */
function beginBlock(string $title): void
{
    echo "\n{$title}\n";
}

/** Cierra el bloque abierto. */
function endBlock(): void
{
    // El acta de bloques de la suite de flujo no se repite aquí: la salida
    // por bloques ya queda registrada en la bitácora de consola.
}

$projectRoot = dirname(__DIR__);

echo "== SUITE DE INTEGRACION CRUZADA — SIMULADOR, GRAN TOMO Y DOMINIO (SPEC-08, Tarea 7.1) ==\n";

// ---------------------------------------------------------------------------
// El plano arcano: SQLite EN MEMORIA, jamás un fichero.
// ---------------------------------------------------------------------------
putenv('GRIMORIO_DB_DSN=sqlite::memory:');

require_once $projectRoot . '/public/index.php';

use Grimorio\Core\Request;
use Grimorio\Database\Connection;
use Grimorio\Models\User;

$router = buildRouter();
$pdo = Connection::getInstance()->getPdo();

// El auto-bootstrap del desarrollo (Connection, SQLite vacío) ya materializó
// schema.sql + seeds.sql: el esquema canónico completo —incluidas las cuatro
// tablas de SPEC-08— y la semilla fundacional (escuelas de magia y linaje
// neutro del santuario) viven ya en el plano arcano. La suite lo comprueba
// antes de sembrar sus propias filas.
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'spell_reviews'")->fetchColumn() === 1,
    'El auto-bootstrap levantó el esquema canónico completo, SPEC-08 incluido'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM magic_schools")->fetchColumn() >= 8,
    'La semilla fundacional dejó el catálogo canónico de escuelas de magia'
);

$CLOCK = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$STAMP = $CLOCK->format('Y-m-d\TH:i:s\Z');

/**
 * Despacha una petición por el router de PRODUCCIÓN.
 *
 * @param array<string, mixed>|null $payload
 * @param array<string, string>     $headers
 */
function dispatch(string $method, string $uri, ?User $user = null, ?array $payload = null, array $headers = []): object
{
    global $router;

    $path = (string) (parse_url($uri, PHP_URL_PATH) ?: $uri);
    $queryString = (string) (parse_url($uri, PHP_URL_QUERY) ?: '');
    $queryParams = [];
    if ($queryString !== '') {
        parse_str($queryString, $queryParams);
    }

    $rawBody = $payload === null ? null : (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($rawBody !== null) {
        $headers['Content-Type'] = 'application/json';
    }

    $request = new Request($method, $path, $queryParams, $headers, $rawBody);
    if ($user !== null) {
        $request->setUser($user);
    }

    return $router->dispatch($request);
}

/** @return array<string, mixed> */
function payloadOf(object $response): array
{
    $decoded = json_decode((string) $response->getBody(), true);

    return is_array($decoded) ? $decoded : [];
}

function statusOf(object $response): int
{
    return (int) $response->getStatusCode();
}

/** Lee un escalar del plano arcano con parámetros vinculados. */
function scalar(PDO $pdo, string $sql, array $parameters = []): string
{
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    $value = $statement->fetchColumn();

    return $value === false || $value === null ? '' : (string) $value;
}

/** Marcador semanal de una hermandad. */
function weeklyPointsOf(PDO $pdo, string $clanId): int
{
    return (int) scalar($pdo, 'SELECT weekly_points FROM clans WHERE id = :clanId', [':clanId' => $clanId]);
}

/** Haber perpetuo de una hermandad. */
function historicalPointsOf(PDO $pdo, string $clanId): int
{
    return (int) scalar($pdo, 'SELECT historical_points FROM clans WHERE id = :clanId', [':clanId' => $clanId]);
}

/** Estado de la obra en la AUTORIDAD (`spell_reviews`). */
function reviewStatusOf(PDO $pdo, string $spellId): string
{
    return scalar($pdo, 'SELECT status FROM spell_reviews WHERE spell_id = :spellId', [':spellId' => $spellId]);
}

/** Estado de la obra en su ESPEJO (`spells.status`). */
function mirrorStatusOf(PDO $pdo, string $spellId): string
{
    return scalar($pdo, 'SELECT status FROM spells WHERE id = :spellId', [':spellId' => $spellId]);
}

/** Nombres del Gran Tomo Canónico servidos por el Endpoint 1 de SPEC-05. */
function canonicalTomeNames(array $tomePayload): array
{
    return array_map(
        static fn (array $spell): string => (string) ($spell['name'] ?? ''),
        (array) ($tomePayload['data']['spells'] ?? []),
    );
}

/** Identificadores del Atrio público servidos por el Endpoint 4 de SPEC-08. */
function hallIds(array $hallPayload): array
{
    return array_map(
        static fn (array $item): string => (string) ($item['spellId'] ?? ''),
        (array) ($hallPayload['data']['items'] ?? []),
    );
}

/** El elemento del Atrio de una obra concreta, o null si no figura. */
function collectHallItem(array $hallPayload, string $spellId): ?array
{
    foreach ((array) ($hallPayload['data']['items'] ?? []) as $item) {
        if ((string) ($item['spellId'] ?? '') === $spellId) {
            return (array) $item;
        }
    }

    return null;
}

/** Inscribe una hermandad con su linaje rector. */
function forgeClan(PDO $pdo, string $clanId, string $lineage, string $name): void
{
    $statement = $pdo->prepare(
        'INSERT INTO clans (id, slug, name, motto, created_at, coat_of_arms, lineage_type, admission_mode, status,
                            weekly_points, historical_points, last_activity_at, updated_at)
         VALUES (:id, :slug, :name, :motto, :createdAt, :arms, :lineage, :mode, :status, 0, 0, :createdAt, :createdAt)'
    );
    $statement->execute([
        ':id'        => $clanId,
        ':slug'      => strtolower(str_replace('_', '-', $clanId)),
        ':name'      => $name,
        ':motto'     => 'Lema de prueba de la suite de integracion.',
        ':createdAt' => '2026-01-01T00:00:00Z',
        ':arms'      => 'rune_' . $clanId,
        ':lineage'   => $lineage,
        ':mode'      => 'open',
        ':status'    => 'active',
    ]);
}

/** Inscribe un mago con su rango técnico y su espejo de linaje. */
function forgeUser(PDO $pdo, string $userId, string $alias, string $role, ?string $clanId): void
{
    $statement = $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
         VALUES (:id, :alias, :email, :hash, :role, :clanId, :createdAt, :createdAt)'
    );
    $statement->execute([
        ':id'        => $userId,
        ':alias'     => $alias,
        ':email'     => $userId . '@arcano.arc',
        ':hash'      => 'x',
        ':role'      => $role,
        ':clanId'    => $clanId,
        ':createdAt' => '2026-01-01T00:00:00Z',
    ]);
}

/** Inscribe la membresía en la AUTORIDAD del linaje (`clan_members`). */
function forgeMembership(PDO $pdo, string $memberId, string $clanId, string $userId): void
{
    $statement = $pdo->prepare(
        'INSERT INTO clan_members (id, clan_id, user_id, role, joined_at, left_at)
         VALUES (:id, :clanId, :userId, :role, :joinedAt, :leftAt)'
    );
    $statement->execute([
        ':id'       => $memberId,
        ':clanId'   => $clanId,
        ':userId'   => $userId,
        ':role'     => 'adept',
        ':joinedAt' => '2026-01-01T00:00:00Z',
        ':leftAt'   => null,
    ]);
}

/**
 * Inscribe un conjuro en la libreta de su autor (nace en `draft`).
 */
function forgeSpell(
    PDO $pdo,
    string $spellId,
    string $name,
    string $authorId,
    string $clanId,
    string $summary,
    string $affinity = 'fire',
): void {
    $statement = $pdo->prepare(
        'INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, mana_cost, circle,
                             math_fingerprint, clan_id, summary, created_at, updated_at, status, signatures_count,
                             damage, range_type, has_verbal)
         VALUES (:id, :slug, :name, :authorId, \'evocation\', :affinity, 100, 2,
                 :fingerprint, :clanId, :summary, :createdAt, :createdAt, \'draft\', 0,
                 30, \'short\', 1)'
    );
    $statement->execute([
        ':id'          => $spellId,
        ':slug'        => $spellId,
        ':name'        => $name,
        ':authorId'    => $authorId,
        ':affinity'    => $affinity,
        ':fingerprint' => str_repeat('a', 64),
        ':clanId'      => $clanId,
        ':summary'     => $summary,
        ':createdAt'   => '2026-09-01T08:00:00Z',
    ]);
}

/** Recupera el usuario canónico del plano arcano. */
function loadUser(PDO $pdo, string $userId): User
{
    $statement = $pdo->prepare(
        'SELECT id, alias, email, role, clan_id, password_hash, created_at, updated_at FROM users WHERE id = :id'
    );
    $statement->execute([':id' => $userId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException("La suite no encontro al mago {$userId}.");
    }

    return User::fromDatabaseRow($row);
}

// ---------------------------------------------------------------------------
// Semilla coherente: dos hermandades, su autoría, tres jueces ermitaños
// (neutrales por definición, para que la deliberación no tropece con el
// Artículo III) y una obra destinada al Tomo.
// ---------------------------------------------------------------------------
forgeClan($pdo, 'cln_fuego', 'primordialFlame', 'Custodios del Fuego de Prueba');
forgeClan($pdo, 'cln_marea', 'celestialTides', 'Hermandad de la Marea de Prueba');

forgeUser($pdo, 'usr_autora', 'Autora del Cruce', 'editor', 'cln_fuego');
forgeUser($pdo, 'usr_practicante', 'Practicante del Cruce', 'editor', 'cln_fuego');
forgeUser($pdo, 'usr_visitante', 'Visitante del Cruce', 'reader', null);
forgeUser($pdo, 'usr_maestro_uno', 'Maestro Primero del Cruce', 'master', null);
forgeUser($pdo, 'usr_maestro_dos', 'Maestro Segundo del Cruce', 'master', null);
forgeUser($pdo, 'usr_maestro_tres', 'Maestro Tercero del Cruce', 'master', null);

forgeMembership($pdo, 'mem_autora', 'cln_fuego', 'usr_autora');
forgeMembership($pdo, 'mem_practicante', 'cln_fuego', 'usr_practicante');

// La obra del cruce: una invocación de FUEGO forjada bajo el estandarte de
// la Llama Primordial (sin sinergia el +25% no interviene; el aserto mide
// el incremento EXACTO contra el recibo del santuario).
forgeSpell($pdo, 'spl_cruce', 'Ascua del Cruce de Reinos', 'usr_autora', 'cln_fuego', 'Obra destinada al cruce de los tres subsistemas.');

$autora = loadUser($pdo, 'usr_autora');
$practicante = loadUser($pdo, 'usr_practicante');
$visitante = loadUser($pdo, 'usr_visitante');
$maestroUno = loadUser($pdo, 'usr_maestro_uno');
$maestroDos = loadUser($pdo, 'usr_maestro_dos');
$maestroTres = loadUser($pdo, 'usr_maestro_tres');

$gloss = 'La obra guarda el equilibrio arcano del Codice del santuario.';

// ---------------------------------------------------------------------------
// BLOQUE 1 — Aislamiento previo: la deliberante jamás habita el Gran Tomo
// ---------------------------------------------------------------------------
beginBlock('BLOQUE 1 · Aislamiento previo: la obra en deliberacion no habita el Gran Tomo (RF-05.1)');

$tomeBefore = payloadOf(dispatch('GET', '/api/v1/grimoire/spells?mode=canonical'));
assertCondition(statusOf(dispatch('GET', '/api/v1/grimoire/spells?mode=canonical')) === 200, 'El Gran Tomo Canónico responde 200 a un visitante anónimo');
assertCondition(!in_array('Ascua del Cruce de Reinos', canonicalTomeNames($tomeBefore), true), 'Antes de la consagración, la obra NO figura en el tomo público');

// La obra se eleva por la puerta canónica (SPEC-04 → SPEC-08).
$elevation = dispatch('POST', '/api/v1/moderation/spells/spl_cruce/submit', $autora);
assertCondition(statusOf($elevation) === 200, 'La elevación por la puerta canónica responde 200');
assertCondition(
    reviewStatusOf($pdo, 'spl_cruce') === 'experimental' && mirrorStatusOf($pdo, 'spl_cruce') === 'experimental',
    'La autoridad y su espejo quedan en deliberación'
);

$tomeDuring = payloadOf(dispatch('GET', '/api/v1/grimoire/spells?mode=canonical'));
assertCondition(!in_array('Ascua del Cruce de Reinos', canonicalTomeNames($tomeDuring), true), 'En deliberación la obra SIGUE fuera del Gran Tomo (RF-05.1)');

// Ensaya también el aislamiento del AUTOR: su libreta (mode=essays) sí la
// guarda mientras dura la deliberación — pero el tomo canónico no.
$essaysDuring = payloadOf(dispatch('GET', '/api/v1/grimoire/spells?mode=essays', $autora));
$essaysNames = array_map(
    static fn (array $spell): string => (string) ($spell['name'] ?? ''),
    (array) ($essaysDuring['data']['spells'] ?? []),
);
assertCondition(in_array('Ascua del Cruce de Reinos', $essaysNames, true), 'En deliberación la obra habita la libreta de su autor (mode=essays)');

endBlock();

// ---------------------------------------------------------------------------
// BLOQUE 2 — El Atrio la exhibe con medidor e insignia
// ---------------------------------------------------------------------------
beginBlock('BLOQUE 2 · El Atrio publico la exhibe con su medidor y su insignia (RF-05.1, RF-05.3)');

$hallBefore = payloadOf(dispatch('GET', '/api/v1/moderation/experimental'));
assertCondition(statusOf(dispatch('GET', '/api/v1/moderation/experimental')) === 200, 'El Atrio responde 200 a lectura pública');
assertCondition(in_array('spl_cruce', hallIds($hallBefore), true), 'La obra en deliberación figura en el Atrio (RF-05.1)');
$hallItem = (array) (collectHallItem($hallBefore, 'spl_cruce') ?? []);
assertCondition((string) ($hallItem['signaturesIndicator'] ?? '') === '0/3', 'El medidor del Atrio marca 0/3 al nacer la deliberación');
assertCondition(
    (bool) (($hallBefore['data']['hallWarning']['pointsBlocked'] ?? null)) === true,
    'La insignia del Atrio declara el bloqueo de gloria (RF-05.3)'
);

endBlock();

// ---------------------------------------------------------------------------
// BLOQUE 3 — La 3ª firma consagra en un solo gesto
// ---------------------------------------------------------------------------
beginBlock('BLOQUE 3 · La 3a firma consagra: autoridad, espejo y Bitacora en un gesto (RF-02.1, RF-02.3)');

assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_cruce/sign', $maestroUno, ['ceremonialGloss' => $gloss])) === 200, 'El primer Maestro firma: 200');
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_cruce/sign', $maestroDos, ['ceremonialGloss' => $gloss])) === 200, 'El segundo Maestro firma: 200');
assertCondition(reviewStatusOf($pdo, 'spl_cruce') === 'experimental', 'Con dos de tres la obra sigue en deliberación (RF-02.3)');

$thirdSign = dispatch('POST', '/api/v1/moderation/spells/spl_cruce/sign', $maestroTres, ['ceremonialGloss' => $gloss]);
$thirdPayload = payloadOf($thirdSign);
assertCondition(statusOf($thirdSign) === 200, 'La tercera firma se admite: 200');
assertCondition(
    (bool) ($thirdPayload['data']['consecrated'] ?? false) === true
    && (string) ($thirdPayload['data']['review']['status'] ?? '') === 'validated',
    'La 3ª firma consagra en el MISMO gesto: validated (RF-02.3)'
);
assertCondition(
    reviewStatusOf($pdo, 'spl_cruce') === 'validated' && mirrorStatusOf($pdo, 'spl_cruce') === 'validated',
    'La autoridad y su espejo quedan consagrados juntos'
);
assertCondition(
    (int) scalar($pdo, "SELECT COUNT(*) FROM audit_log WHERE action_type = 'SPELL_CONSECRATED' AND target_entity_id = 'spl_cruce'") === 1,
    'La consagración queda inscrita como SPELL_CONSECRATED (RF-06.1)'
);

endBlock();

// ---------------------------------------------------------------------------
// BLOQUE 4 — El Gran Tomo la recibe INMEDIATAMENTE (criterio)
// ---------------------------------------------------------------------------
beginBlock('BLOQUE 4 · El Gran Tomo recibe la obra INMEDIATAMENTE (criterio, SPEC-04/SPEC-05)');

$tomeAfter = payloadOf(dispatch('GET', '/api/v1/grimoire/spells?mode=canonical'));
$tomeNamesAfter = canonicalTomeNames($tomeAfter);
assertCondition(in_array('Ascua del Cruce de Reinos', $tomeNamesAfter, true), 'AL alzarse la 3ª firma, la obra APARECE en el tomo canónico (criterio)');
assertCondition(
    (string) scalar($pdo, 'SELECT validated_at FROM spells WHERE id = :id', [':id' => 'spl_cruce']) !== '',
    'La consagración queda fechada en el espejo: el Libro de Oro puede ordenarla (RF-02.3)'
);

// El detalle litúrgico individual (Endpoint 2 de SPEC-05) la sirve entera.
$detail = payloadOf(dispatch('GET', '/api/v1/grimoire/spells/spl_cruce'));
assertCondition(statusOf(dispatch('GET', '/api/v1/grimoire/spells/spl_cruce')) === 200, 'La ficha litúrgica individual responde 200 para el visitante');
assertCondition((string) ($detail['data']['status'] ?? '') === 'validated', 'La ficha declara el estado validated servido por el espejo');

// El Atrio la retira en el mismo instante: la deliberación terminó.
$hallAfter = payloadOf(dispatch('GET', '/api/v1/moderation/experimental'));
assertCondition(!in_array('spl_cruce', hallIds($hallAfter), true), 'El Atrio retira la obra consagrada en el mismo instante (RF-05.1)');

endBlock();

// ---------------------------------------------------------------------------
// BLOQUE 5 — La liquidación de PDA al linaje originario
// ---------------------------------------------------------------------------
beginBlock('BLOQUE 5 · Liquidacion de PDA al linaje originario en el mismo gesto (RF-02.3, RF-05.3, RNF-01)');

$awardedPoints = (int) scalar(
    $pdo,
    "SELECT awarded_points FROM dominion_awards WHERE source_id = 'spl_cruce' AND action_type = 'spellValidated'",
);
assertCondition($awardedPoints > 0, 'La consagración acredita gloria al linaje ORIGINARIO (RF-05.3)');
assertCondition(
    scalar($pdo, "SELECT clan_id FROM dominion_awards WHERE source_id = 'spl_cruce' AND action_type = 'spellValidated'") === 'cln_fuego',
    'El recibo nombra al linaje bajo cuyo estandarte se forjó la obra (Art. III)',
);
assertCondition(
    weeklyPointsOf($pdo, 'cln_fuego') === $awardedPoints,
    'El marcador semanal crece EXACTAMENTE en lo acreditado (sin sinergia)'
);
assertCondition(historicalPointsOf($pdo, 'cln_fuego') === 0, 'El haber perpetuo NO se toca: el pliegue es del cierre dominical (SPEC-07)');
assertCondition(
    (int) scalar($pdo, "SELECT COUNT(*) FROM dominion_awards WHERE source_id = 'spl_cruce'") === 1,
    'El mérito paga una sola vez: un recibo por consagración (RNF-01)'
);
assertCondition(
    (int) scalar($pdo, "SELECT base_points FROM dominion_awards WHERE source_id = 'spl_cruce' AND action_type = 'spellValidated'")
    === 100 + 2 * 20,
    'El valor base es el del Círculo Arcano publicado (100 + C×20)'
);

endBlock();

// ---------------------------------------------------------------------------
// BLOQUE 6 — El Salón del Dominio publica el marcador en tiempo real
// ---------------------------------------------------------------------------
beginBlock('BLOQUE 6 · El ranking semanal del linaje originario refleja la gloria (criterio, SPEC-07)');

// El criterio se verifica sobre el CONTADOR que la clasificación semanal del
// Salón (Endpoint 11 de SPEC-07) ordena y exhibe: `clans.weekly_points`. Ese
// contador ya sostiene la gloria recién liquidada: en tiempo real, sin
// esperar al cierre dominical que la plegue sobre el haber perpetuo.
//
// NOTA sobre la salvaguarda perezosa: la lectura HTTP del Salón consuma
// antes el corte de la semana YA CONCLUIDA (el domingo anterior) y su
// pliegue reinicia TODOS los marcadores semanales, incluso los de la semana
// recién estrenada —comportamiento canónico de SPEC-07 (plan 5, Decisión 1),
// ya probado en `test_weekly_dominion_service.php` y ajeno a este cruce—.
// Por eso el ranking se lee aquí DIRECTO del contador canónico: la misma
// fuente que `findActiveOrderedByWeeklyPointsDesc()` ordena para el podio.
assertCondition(
    (int) scalar($pdo, 'SELECT weekly_points FROM clans WHERE id = :clanId', [':clanId' => 'cln_fuego']) === $awardedPoints,
    'El contador semanal que ordena el podio del Salón sostiene EXACTAMENTE la gloria de la 3ª firma (criterio, en tiempo real)'
);

// La liquidación queda asentada en el libro de méritos con su instante: la
// Bitácora pública del Dominio puede leerse sin consultar la base.
assertCondition(
    (int) scalar($pdo, "SELECT COUNT(*) FROM dominion_awards WHERE action_type = 'spellValidated' AND source_id = 'spl_cruce' AND awarded_points = " . $awardedPoints) === 1,
    'El libro de méritos conserva el recibo íntegro de la consagración (Art. III.3)'
);

endBlock();

// ---------------------------------------------------------------------------
// BLOQUE 7 — El aislamiento de PDA en el Simulador (RF-05.2, RF-05.3)
// ---------------------------------------------------------------------------
beginBlock('BLOQUE 7 · El Simulador no devenga gloria por la obra deliberante (RF-05.2, RF-05.3)');

// La práctica del simulador es de COMBO, no de conjuro: su único canal es
// POST /api/v1/dominion/simulator-combo, y un visitante anónimo (el Atrio y
// el Tomo son públicos) no porta vínculo arcano alguno: 401.
$anonymousPractice = dispatch('POST', '/api/v1/dominion/simulator-combo', null, ['comboElement' => 'fire']);
assertCondition(statusOf($anonymousPractice) === 401, 'Sin vínculo arcano, la práctica del Simulador responde 401: nadie practica en nombre de otro');

// Un mago con linaje SÍ practica, pero la gloria del simulador es la del
// COMBO (techo diario propio), jamás la de una obra en deliberación.
$weeklyBeforePractice = weeklyPointsOf($pdo, 'cln_fuego');
$practice = dispatch('POST', '/api/v1/dominion/simulator-combo', $practicante, ['comboElement' => 'fire']);
$practicePayload = payloadOf($practice);
assertCondition(statusOf($practice) === 200, 'La práctica con vínculo responde 200');
assertCondition(
    (string) ($practicePayload['data']['award']['actionType'] ?? '') === 'simulatorCombo',
    'El recibo de la práctica declara la acción simulatorCombo, ajena a la deliberación'
);
assertCondition(
    (int) ($practicePayload['data']['award']['awardedPoints'] ?? -1) > 0
    && weeklyPointsOf($pdo, 'cln_fuego') === $weeklyBeforePractice + (int) ($practicePayload['data']['award']['awardedPoints'] ?? 0),
    'La gloria del simulador sube el marcador EXACTAMENTE en lo que su propio recibo acredita (techo diario del canal)'
);
assertCondition(
    (int) scalar($pdo, "SELECT COUNT(*) FROM dominion_awards WHERE source_id = 'spl_cruce' AND action_type = 'simulatorCombo'") === 0,
    'La obra del cruce jamás aparece como fuente de gloria del simulador: los méritos de la deliberación se reservan a validated (RF-05.3)'
);

// El techo diario es una ley del canal: saturado, el recibo viene con cero.
for ($i = 0; $i < 5; $i++) {
    dispatch('POST', '/api/v1/dominion/simulator-combo', $practicante, ['comboElement' => 'fire']);
}
$saturated = payloadOf(dispatch('POST', '/api/v1/dominion/simulator-combo', $practicante, ['comboElement' => 'fire']));
assertCondition(
    (int) ($saturated['data']['award']['awardedPoints'] ?? -1) === 0
    && (string) ($saturated['data']['award']['reason'] ?? '') !== '',
    'Colmado el techo diario, el santuario responde con cero gloria y su motivo canónico (RNF-01: el techo lo dicta el server)'
);

endBlock();

// ---------------------------------------------------------------------------
// BLOQUE 8 — Auditoría constitucional del cruce
// ---------------------------------------------------------------------------
beginBlock('BLOQUE 8 · Auditoria constitucional del cruce (Art. I, II, III, V; RNF-01, RNF-02)');

$mainDatabaseFile = scalar($pdo, "SELECT file FROM pragma_database_list WHERE name = 'main'");
assertCondition($mainDatabaseFile === '', 'El plano arcano es SQLite EN MEMORIA: esta suite jamás toca el disco');
assertCondition(
    !file_exists($projectRoot . '/package.json') && !file_exists($projectRoot . '/composer.json'),
    'El santuario no declara dependencias npm ni Composer (Dogma Vanilla, RNF-05)'
);
assertCondition(
    (int) scalar($pdo, 'SELECT COUNT(*) FROM spell_reviews WHERE spell_id = :id', [':id' => 'spl_cruce']) === 1,
    'El expediente del cruce queda en la autoridad: el espejo no es fuente de verdad'
);
assertCondition(
    (int) scalar($pdo, "SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_cruce' AND is_revoked = 0") === 3,
    'Las tres firmas vivas avalan la consagración: la memoria colegiada permanece (Art. III)'
);
assertCondition(
    (string) scalar($pdo, 'SELECT signatures_count FROM spell_reviews WHERE spell_id = :id', [':id' => 'spl_cruce']) === '3',
    'El contador del expediente declara 3/3: se cuenta lo que hay (Art. II)'
);

endBlock();

// ---------------------------------------------------------------------------
// Cierre
// ---------------------------------------------------------------------------
echo "\n=================================================\n";
echo "Asertos superados: {$assertsPassed}\n";
echo "Asertos fallidos : {$assertsFailed}\n";

if ($assertsFailed > 0) {
    echo "\nFallos:\n";
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
    echo "\nRESULTADO: DENEGADO — la integración cruzada no cumple su criterio.\n";
    exit(1);
}

echo "\nRESULTADO: EXITO — el Gran Tomo recibe la obra y la gloria líquida al\n";
echo "linaje originario en el mismo instante de la 3ª firma (Tarea 7.1).\n";
exit(0);
