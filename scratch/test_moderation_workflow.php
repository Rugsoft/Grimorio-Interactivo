<?php

declare(strict_types=1);

/**
 * test_moderation_workflow.php — Suite de flujo completo de SPEC-08 (Tarea 4.1).
 *
 * Valida contra el criterio «Hecho cuando» de
 * specs/08-moderation-two-step.tasks.md:
 *   «La ejecución php scratch/test_moderation_workflow.php supera el 100% de
 *    los 11 bloques de asertos con código de salida 0.»
 *
 * Estrategia: NO se prueban unidades sueltas —cada una tiene ya su arnés en las
 * fases anteriores—, sino el FLUJO ENTERO de una obra por el Cónclave, despachado
 * por la pila REAL de producción: se carga `public/index.php`, se invoca
 * `buildRouter()` y se cruzan `Request`/`Response` nativos. El plano arcano es
 * SQLite **en memoria** (`GRIMORIO_DB_DSN=sqlite::memory:`), como manda la
 * tarea: cada ejecución nace virgen y no deja rastro alguno en disco.
 *
 * Las filas de siembra —un mago, una hermandad, un conjuro recién forjado— se
 * inscriben con SQL directo, porque un conjuro NACE en la libreta de su autor.
 * Todas las TRANSICIONES, en cambio, pasan por las autoridades canónicas
 * (elevación, firma, dictamen, decreto, letargo): la autoridad y su espejo
 * quedan así sincronizados por el propio código y jamás por el arnés.
 *
 * Bloques (los 11 primeros son los escenarios críticos exigidos; el 12 y el 13
 * completan el Plan §6.1 y las garantías constitucionales):
 *   1. Elevación draft -> experimental con la huella matemática sellada.
 *   2. Consagración en la 3ª firma y liquidación de PDA al linaje originario.
 *   3. Veto ético por linaje del autor y ex-linaje de los últimos 30 días.
 *   4. Auto-firma prohibida al autor con rango de Maestro.
 *   5. Pluralidad de hermandades y admisión de ermitaños neutrales.
 *   6. Veto de calidad por dictamen de objeción y retiro del Atrio.
 *   7. Re-apertura formal con el dictamen a la vista y el cupo liberado.
 *   8. Cupo de tres obras concurrentes y su liberación inmediata.
 *   9. Anulación de oficio: conflicto sobrevenido y pérdida de rango (N-1).
 *  10. Firma Soberana: inviolabilidad del borrador y veto al propio linaje.
 *  11. Caducidad por letargo de noventa días.
 *  12. Herencia Ancestral ante la disolución del linaje (Plan §6.1).
 *  13. Auditoría constitucional del flujo (RNF-01 a RNF-05).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Router/Request/Response/PDO nativos.
 *   - Artículo V: identificadores en inglés camelCase; leyendas en castellano.
 *
 * Uso: php scratch/test_moderation_workflow.php
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
/** @var list<array{index: int, title: string, asserts: int, failed: int}> */
$blockLedger = [];

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

/** Abre un bloque de asertos: los que vengan después le pertenecen. */
function beginBlock(string $title): void
{
    global $currentBlock, $assertsPassed, $assertsFailed;
    $currentBlock = ['title' => $title, 'passed' => $assertsPassed, 'failed' => $assertsFailed];
    echo "\n{$title}\n";
}

/** Cierra el bloque abierto y lo anota en el acta de la ejecución. */
function endBlock(): void
{
    global $currentBlock, $blockLedger, $assertsPassed, $assertsFailed;
    $blockLedger[] = [
        'index'  => count($blockLedger) + 1,
        'title'  => $currentBlock['title'],
        'asserts' => ($assertsPassed - $currentBlock['passed']) + ($assertsFailed - $currentBlock['failed']),
        'failed' => $assertsFailed - $currentBlock['failed'],
    ];
}

$projectRoot = dirname(__DIR__);
const CRON_SECRET = 'sello-del-letargo-de-la-suite-de-flujo';

echo "== SUITE DE FLUJO COMPLETO — MODERACION EN DOS PASOS (SPEC-08, Tarea 4.1) ==\n";

// ---------------------------------------------------------------------------
// El plano arcano: SQLite EN MEMORIA, jamás un fichero.
// ---------------------------------------------------------------------------
putenv('GRIMORIO_DB_DSN=sqlite::memory:');
putenv('GRIMORIO_CRON_SECRET=' . CRON_SECRET);

require_once $projectRoot . '/public/index.php';

use Grimorio\Core\Request;
use Grimorio\Database\Connection;
use Grimorio\Dto\DominionAwardDto;
use Grimorio\Dto\SpellCalculationInputDto;
use Grimorio\Models\User;
use Grimorio\Repositories\ClanRepository;
use Grimorio\Repositories\SpellReviewRepository;
use Grimorio\Services\ConstitutionalEthicsValidator;
use Grimorio\Services\MasterDeliberationService;
use Grimorio\Services\ModerationWorkflowService;
use Grimorio\Services\SpellBalanceService;

$router = buildRouter();
$pdo = Connection::getInstance()->getPdo();

// El guion de ascensión del cónclave (Tarea 1.1) es idempotente; aplicarlo
// sobre una base recién nacida prueba además esa propiedad en cada ejecución.
$pdo->exec((string) file_get_contents($projectRoot . '/sql/08_moderation_schema.sql'));

$CLOCK = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$STAMP = $CLOCK->format('Y-m-d\TH:i:s\Z');
$NINETY_ONE_DAYS_AGO = $CLOCK->modify('-91 days')->format('Y-m-d\TH:i:s\Z');
$TWENTY_NINE_DAYS_AGO = $CLOCK->modify('-29 days')->format('Y-m-d\TH:i:s\Z');
$THIRTY_ONE_DAYS_AGO = $CLOCK->modify('-31 days')->format('Y-m-d\TH:i:s\Z');
$FINGERPRINT = str_repeat('a', 64);
$TAMPERED_FINGERPRINT = str_repeat('0', 64);

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

function errorCodeOf(object $response): string
{
    return (string) (payloadOf($response)['error']['code'] ?? '');
}

function errorMessageOf(object $response): string
{
    return (string) (payloadOf($response)['error']['message'] ?? '');
}

/** Lee un escalar del plano arcano con parámetros vinculados. */
function scalar(PDO $pdo, string $sql, array $parameters = []): string
{
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    $value = $statement->fetchColumn();

    return $value === false || $value === null ? '' : (string) $value;
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

/** Contador de firmas vivas de la obra, medido sobre las firmas REALES. */
function liveSignatureCount(PDO $pdo, string $spellId): int
{
    return (int) scalar(
        $pdo,
        'SELECT COUNT(*) FROM master_signatures WHERE spell_id = :spellId AND is_revoked = 0',
        [':spellId' => $spellId],
    );
}

function indicatorOf(PDO $pdo, string $spellId): int
{
    return (int) scalar($pdo, 'SELECT signatures_count FROM spell_reviews WHERE spell_id = :spellId', [':spellId' => $spellId]);
}

function weeklyPointsOf(PDO $pdo, string $clanId): int
{
    return (int) scalar($pdo, 'SELECT weekly_points FROM clans WHERE id = :clanId', [':clanId' => $clanId]);
}

function historicalPointsOf(PDO $pdo, string $clanId): int
{
    return (int) scalar($pdo, 'SELECT historical_points FROM clans WHERE id = :clanId', [':clanId' => $clanId]);
}

/** Gloria acreditada por la consagración de una obra, según su recibo. */
function awardedPointsOf(PDO $pdo, string $spellId): int
{
    return (int) scalar(
        $pdo,
        "SELECT awarded_points FROM dominion_awards WHERE source_id = :spellId AND action_type = 'spellValidated'",
        [':spellId' => $spellId],
    );
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
        ':motto'     => 'Lema de prueba de la suite de flujo.',
        ':createdAt' => '2026-01-01T00:00:00Z',
        ':arms'      => 'rune_' . $clanId,
        ':lineage'   => $lineage,
        ':mode'      => 'open',
        ':status'    => 'active',
    ]);
}

/** Inscribe una membresía en el historial del linaje (`clan_members`). */
function forgeMembership(PDO $pdo, string $memberId, string $clanId, string $userId, string $joinedAt, ?string $leftAt = null): void
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
        ':joinedAt' => $joinedAt,
        ':leftAt'   => $leftAt,
    ]);
}

/**
 * Inscribe un conjuro en la libreta de su autor.
 *
 * Nace en `draft`, con la huella y el maná que se le indiquen: los escenarios
 * que miden el sellado del backend le entregan a propósito una huella y un
 * coste AMAÑADOS, para comprobar que la elevación los reescribe.
 */
function forgeSpell(
    PDO $pdo,
    string $spellId,
    string $name,
    string $authorId,
    string $clanId,
    string $summary,
    int $circle = 1,
    string $fingerprint = '',
    int $manaCost = 0,
    int $damage = 20,
    string $affinity = 'fire',
): void {
    $statement = $pdo->prepare(
        'INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, mana_cost, circle,
                             math_fingerprint, clan_id, summary, created_at, updated_at, status, signatures_count,
                             damage, range_type, has_verbal)
         VALUES (:id, :slug, :name, :authorId, \'evocation\', :affinity, :manaCost, :circle,
                 :fingerprint, :clanId, :summary, :createdAt, :createdAt, \'draft\', 0,
                 :damage, \'short\', 1)'
    );
    $statement->execute([
        ':id'          => $spellId,
        ':slug'        => $spellId,
        ':name'        => $name,
        ':authorId'    => $authorId,
        ':affinity'    => $affinity,
        ':manaCost'    => $manaCost,
        ':circle'      => $circle,
        ':fingerprint' => $fingerprint === '' ? str_repeat('a', 64) : $fingerprint,
        ':clanId'      => $clanId,
        ':summary'     => $summary,
        ':createdAt'   => '2026-09-01T08:00:00Z',
        ':damage'      => $damage,
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
// Semilla
// ---------------------------------------------------------------------------
forgeClan($pdo, 'cln_fuego', 'primordialFlame', 'Custodios del Fuego de Prueba');
forgeClan($pdo, 'cln_marea', 'celestialTides', 'Hermandad de la Marea de Prueba');
forgeClan($pdo, 'cln_sombra', 'abyssalShadows', 'Cofradia de la Sombra de Prueba');

$memberships = [
    // Autoría.
    ['usr_autora_uno', 'editor', 'cln_fuego'],
    ['usr_autora_dos', 'editor', 'cln_fuego'],
    ['usr_autora_cuatro', 'editor', 'cln_marea'],
    ['usr_autora_cinco', 'editor', 'cln_fuego'],
    ['usr_autora_seis', 'editor', 'cln_sombra'],
    ['usr_autora_siete', 'editor', 'cln_fuego'],
    ['usr_autora_ocho', 'editor', 'cln_fuego'],
    // Lectura.
    ['usr_lector', 'reader', null],
    // Maestros ermitaños: neutrales por definición.
    ['usr_ermitano_uno', 'master', null],
    ['usr_ermitano_dos', 'master', null],
    ['usr_ermitano_tres', 'master', null],
    ['usr_maestro_efimero', 'master', null],
    // Maestros con linaje.
    ['usr_maestro_fuego', 'master', 'cln_fuego'],
    ['usr_maestro_marea_uno', 'master', 'cln_marea'],
    ['usr_maestro_marea_dos', 'master', 'cln_marea'],
    ['usr_maestro_mudado', 'master', 'cln_marea'],
    ['usr_maestro_marchado', 'master', 'cln_marea'],
    ['usr_maestro_ajeno', 'master', 'cln_sombra'],
    ['usr_maestro_autor', 'master', 'cln_marea'],
    // Potestad suprema.
    ['usr_supremo', 'supremeAdmin', null],
    ['usr_supremo_fuego', 'supremeAdmin', 'cln_fuego'],
    ['usr_supremo_autor', 'supremeAdmin', null],
];
foreach ($memberships as [$userId, $role, $clanId]) {
    forgeUser($pdo, $userId, 'Mago ' . substr($userId, 4), $role, $clanId);
    if ($clanId !== null) {
        forgeMembership($pdo, 'mem_' . substr($userId, 4), $clanId, $userId, '2026-01-01T00:00:00Z');
    }
}

// Los dos Maestros que MUDARON de linaje: el historial de membresía es la
// autoridad del veto de treinta días, y el espejo `users.clan_id` no lo es.
forgeMembership($pdo, 'mem_mudado_fuego', 'cln_fuego', 'usr_maestro_mudado', '2026-02-01T00:00:00Z', $TWENTY_NINE_DAYS_AGO);
forgeMembership($pdo, 'mem_marchado_fuego', 'cln_fuego', 'usr_maestro_marchado', '2026-02-01T00:00:00Z', $THIRTY_ONE_DAYS_AGO);

// Obras: todas nacen en la libreta (draft).
forgeSpell($pdo, 'spl_hueco', 'Ascua de la Huella Sellada', 'usr_autora_uno', 'cln_fuego', 'Obra de la huella amañada.', 2, $TAMPERED_FINGERPRINT, 200);
forgeSpell($pdo, 'spl_gemelo', 'Ascua Gemela de la Huella', 'usr_autora_dos', 'cln_fuego', 'Gemela exacta de la anterior.', 2, $TAMPERED_FINGERPRINT, 200);
forgeSpell($pdo, 'spl_consagracion', 'Ascua de la Consagracion', 'usr_autora_uno', 'cln_fuego', 'Obra destinada al Gran Tomo.', 3, '', 0, 30);
forgeSpell($pdo, 'spl_veto', 'Ascua del Veto Etico', 'usr_autora_dos', 'cln_fuego', 'Obra para medir el Articulo III.');
forgeSpell($pdo, 'spl_propia', 'Ascua de la Pluma Propia', 'usr_maestro_autor', 'cln_marea', 'Obra de un autor con rango de Maestro.');
forgeSpell($pdo, 'spl_pluralidad', 'Ascua de la Pluralidad', 'usr_autora_uno', 'cln_fuego', 'Obra para medir una voz por estandarte.');
forgeSpell($pdo, 'spl_objecion', 'Ascua del Dictamen', 'usr_autora_cuatro', 'cln_marea', 'Obra que sera vetada por dictamen.');
forgeSpell($pdo, 'spl_anulable', 'Ascua Anulable', 'usr_autora_cuatro', 'cln_marea', 'Obra cuyos avales caeran de oficio.');
forgeSpell($pdo, 'spl_reapertura', 'Ascua de la Plaza Liberada', 'usr_autora_cuatro', 'cln_marea', 'Obra que ocupa la plaza devuelta por la reapertura.');
forgeSpell($pdo, 'spl_privada', 'Ascua Privada del Borrador', 'usr_autora_uno', 'cln_fuego', 'Borrador que nadie eleva.');
forgeSpell($pdo, 'spl_del_linaje', 'Ascua del Linaje del Supremo', 'usr_autora_ocho', 'cln_fuego', 'Obra del linaje del propio Supremo.');
forgeSpell($pdo, 'spl_soberana', 'Ascua de la Firma Soberana', 'usr_autora_cuatro', 'cln_marea', 'Obra que el Supremo consagra de oficio.', 2);
forgeSpell($pdo, 'spl_letargo', 'Ascua Letargica', 'usr_autora_siete', 'cln_fuego', 'Obra olvidada noventa y un dias.');
forgeSpell($pdo, 'spl_herencia', 'Ascua de la Herencia Ancestral', 'usr_autora_seis', 'cln_sombra', 'Obra de un linaje que sera disuelto.');
forgeSpell($pdo, 'spl_del_supremo_autor', 'Ascua del Supremo Autor', 'usr_supremo_autor', 'cln_marea', 'Obra de la propia pluma del Administrador Supremo.');
forgeSpell($pdo, 'spl_cupo_uno', 'Ascua Primera del Cupo', 'usr_autora_cinco', 'cln_fuego', 'Primera de las tres plazas.');
forgeSpell($pdo, 'spl_cupo_dos', 'Ascua Segunda del Cupo', 'usr_autora_cinco', 'cln_fuego', 'Segunda de las tres plazas.');
forgeSpell($pdo, 'spl_cupo_tres', 'Ascua Tercera del Cupo', 'usr_autora_cinco', 'cln_fuego', 'Tercera de las tres plazas.');
forgeSpell($pdo, 'spl_cupo_cuatro', 'Ascua Cuarta del Cupo', 'usr_autora_cinco', 'cln_fuego', 'La que no cabe hasta que una plaza se libere.');

$workflow = new ModerationWorkflowService($pdo);
$deliberation = new MasterDeliberationService($pdo);
$ethics = new ConstitutionalEthicsValidator($pdo);
$reviews = new SpellReviewRepository($pdo);
$clanRepository = new ClanRepository($pdo);
$balance = new SpellBalanceService();

$autoraUno = loadUser($pdo, 'usr_autora_uno');
$autoraDos = loadUser($pdo, 'usr_autora_dos');
$autoraCuatro = loadUser($pdo, 'usr_autora_cuatro');
$autoraCinco = loadUser($pdo, 'usr_autora_cinco');
$autoraSeis = loadUser($pdo, 'usr_autora_seis');
$autoraOcho = loadUser($pdo, 'usr_autora_ocho');
$lector = loadUser($pdo, 'usr_lector');
$ermitanoUno = loadUser($pdo, 'usr_ermitano_uno');
$ermitanoDos = loadUser($pdo, 'usr_ermitano_dos');
$ermitanoTres = loadUser($pdo, 'usr_ermitano_tres');
$maestroFuego = loadUser($pdo, 'usr_maestro_fuego');
$maestroMareaUno = loadUser($pdo, 'usr_maestro_marea_uno');
$maestroMareaDos = loadUser($pdo, 'usr_maestro_marea_dos');
$maestroMudado = loadUser($pdo, 'usr_maestro_mudado');
$maestroMarchado = loadUser($pdo, 'usr_maestro_marchado');
$maestroAjeno = loadUser($pdo, 'usr_maestro_ajeno');
$maestroAutor = loadUser($pdo, 'usr_maestro_autor');
$supremo = loadUser($pdo, 'usr_supremo');
$supremoFuego = loadUser($pdo, 'usr_supremo_fuego');
$supremoAutor = loadUser($pdo, 'usr_supremo_autor');

$gloss = 'La obra guarda el equilibrio mathematico del Codice Arcano.';

// ---------------------------------------------------------------------------
// BLOQUE 1 — Elevación draft -> experimental con la huella sellada
// ---------------------------------------------------------------------------
beginBlock('BLOQUE 1 · Elevacion draft -> experimental con la huella matematica sellada (RF-01.1, Art. II)');

$seededAuthors = "'usr_autora_uno', 'usr_autora_dos', 'usr_autora_cuatro', 'usr_autora_cinco',
                   'usr_autora_seis', 'usr_autora_siete', 'usr_autora_ocho', 'usr_maestro_autor'";
assertCondition(
    (int) scalar($pdo, "SELECT COUNT(*) FROM spells WHERE author_id IN ({$seededAuthors}) AND status = 'draft'")
    === (int) scalar($pdo, "SELECT COUNT(*) FROM spells WHERE author_id IN ({$seededAuthors})")
    && (int) scalar($pdo, "SELECT COUNT(*) FROM spells WHERE author_id IN ({$seededAuthors})") >= 17,
    'La semilla nace entera en las libretas: las dieciocho obras de prueba aguardan en draft'
);
assertCondition(
    (int) $pdo->query('SELECT COUNT(*) FROM spell_reviews')->fetchColumn() === 0,
    'La Torre esta vacia: ninguna obra tiene expediente antes del primer bloque'
);

$submitHueco = dispatch('POST', '/api/v1/moderation/spells/spl_hueco/submit', $autoraUno);
assertCondition(statusOf($submitHueco) === 200, 'El autor eleva su obra: 200');
$huecoPayload = payloadOf($submitHueco);
assertCondition(
    (int) ($huecoPayload['data']['review']['signaturesCount'] ?? -1) === 0
    && (string) ($huecoPayload['data']['review']['signaturesIndicator'] ?? '') === '0/3',
    'La obra entra a deliberacion con el medidor en 0/3 (RF-01.1)'
);
assertCondition((int) ($huecoPayload['data']['remainingCapacity'] ?? -1) === 2, 'El cupo del autor baja a dos plazas');

$sealedFingerprint = scalar($pdo, 'SELECT math_fingerprint FROM spells WHERE id = :id', [':id' => 'spl_hueco']);
assertCondition($sealedFingerprint !== $TAMPERED_FINGERPRINT, 'La huella AMAÑADA del borrador no sobrevive a la elevacion');
assertCondition(
    preg_match('/^[0-9a-f]{64}$/', $sealedFingerprint) === 1,
    'La huella queda sellada como un SHA-256 hexadecimal de 64 caracteres (Art. II)'
);

$expectedInput = new SpellCalculationInputDto(
    damage: 20,
    healing: 0,
    barrier: 0,
    crowdControlType: 'none',
    rangeType: 'short',
    areaType: 'singleTarget',
    durationType: 'instant',
    hasVerbal: true,
    hasSomatic: false,
    hasMaterial: false,
);
assertCondition(
    $sealedFingerprint === $balance->computeMathFingerprint($expectedInput),
    'La huella sellada es EXACTAMENTE la del balance recalculado por el backend'
);
assertCondition(
    (int) scalar($pdo, 'SELECT mana_cost FROM spells WHERE id = :id', [':id' => 'spl_hueco']) === $balance->calculate($expectedInput)->finalManaCost
    && (int) scalar($pdo, 'SELECT mana_cost FROM spells WHERE id = :id', [':id' => 'spl_hueco']) !== 200,
    'El maná tambien se revalida en el servidor: el coste amañado de 200 no se publica (Art. II)'
);
assertCondition(
    reviewStatusOf($pdo, 'spl_hueco') === 'experimental' && mirrorStatusOf($pdo, 'spl_hueco') === 'experimental',
    'La autoridad y su espejo quedan en experimental en el mismo gesto'
);
assertCondition(
    (int) scalar($pdo, "SELECT COUNT(*) FROM audit_log WHERE action_type = 'MODERATION_SUBMITTED' AND target_entity_id = 'spl_hueco'") === 1,
    'La elevacion queda inscrita en la Bitacora publica (RF-06.1)'
);

// La gemela: misma matematica, otro autor y otra libreta.
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_gemelo/submit', $autoraDos)) === 200, 'La obra gemela se eleva: 200');
assertCondition(
    scalar($pdo, 'SELECT math_fingerprint FROM spells WHERE id = :id', [':id' => 'spl_gemelo']) === $sealedFingerprint,
    'Dos obras con la misma matematica sellan la MISMA huella: el sellado es determinista (RNF-01)'
);

// El borrador privado no se eleva dos veces ni por un atajo.
assertCondition(
    errorCodeOf(dispatch('POST', '/api/v1/moderation/spells/spl_hueco/submit', $autoraUno)) === 'SPELL_NOT_IN_DRAFT',
    'Reelevar una obra ya elevada responde SPELL_NOT_IN_DRAFT (400)'
);
assertCondition(
    statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_hueco/submit', $lector)) === 403,
    'Un lector no eleva plegarias a la Torre: 403 (RF-01.2)'
);

endBlock();

// ---------------------------------------------------------------------------
// BLOQUE 2 — Consagración en la 3ª firma y liquidación de PDA
// ---------------------------------------------------------------------------
beginBlock('BLOQUE 2 · Consagracion en la 3a firma y liquidacion de PDA al linaje originario (RF-02.1, RF-02.3)');

assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_consagracion/submit', $autoraUno)) === 200, 'La obra destinada al Tomo se eleva: 200');

$weeklyFuegoBefore = weeklyPointsOf($pdo, 'cln_fuego');
$historicalFuegoBefore = historicalPointsOf($pdo, 'cln_fuego');

$firstSign = dispatch('POST', '/api/v1/moderation/spells/spl_consagracion/sign', $maestroMareaUno, ['ceremonialGloss' => $gloss]);
assertCondition(statusOf($firstSign) === 200, 'El primer Maestro firma: 200');
assertCondition(
    (int) (payloadOf($firstSign)['data']['review']['signaturesCount'] ?? -1) === 1
    && indicatorOf($pdo, 'spl_consagracion') === 1,
    'El contador queda en 1/3 recontado desde las firmas vivas'
);
assertCondition(
    scalar($pdo, "SELECT master_clan_id FROM master_signatures WHERE spell_id = 'spl_consagracion'") === 'cln_marea',
    'La firma retrata el linaje del firmante en el instante de firmar (Art. III)'
);
assertCondition(
    scalar($pdo, "SELECT ceremonial_gloss FROM master_signatures WHERE spell_id = 'spl_consagracion'") === $gloss,
    'La glosa ceremonial se conserva caracter a caracter (RF-02.2)'
);
assertCondition(
    scalar($pdo, "SELECT revocation_reason FROM master_signatures WHERE spell_id = 'spl_consagracion' LIMIT 1") === ''
    || scalar($pdo, "SELECT is_revoked FROM master_signatures WHERE spell_id = 'spl_consagracion'") === '0',
    'El aval nace vivo: la firma no se revoca sola'
);

assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_consagracion/sign', $ermitanoUno, ['ceremonialGloss' => $gloss])) === 200, 'El segundo Maestro firma: 200');
assertCondition(indicatorOf($pdo, 'spl_consagracion') === 2, 'El contador queda en 2/3');
assertCondition(
    (string) scalar($pdo, 'SELECT status FROM spell_reviews WHERE spell_id = :id', [':id' => 'spl_consagracion']) === 'experimental',
    'Con dos de tres firmas la obra SIGUE en deliberacion (RF-02.3)'
);

$thirdSign = dispatch('POST', '/api/v1/moderation/spells/spl_consagracion/sign', $ermitanoDos, ['ceremonialGloss' => $gloss]);
assertCondition(statusOf($thirdSign) === 200, 'La tercera firma se admite: 200');
$thirdPayload = payloadOf($thirdSign);
assertCondition(
    (bool) ($thirdPayload['data']['consecrated'] ?? false) === true
    && (string) ($thirdPayload['data']['review']['status'] ?? '') === 'validated'
    && (int) ($thirdPayload['data']['review']['signaturesCount'] ?? -1) === 3,
    'La 3a firma consagra la obra en el MISMO gesto: validated y 3/3 (RF-02.3)'
);
assertCondition(
    reviewStatusOf($pdo, 'spl_consagracion') === 'validated' && mirrorStatusOf($pdo, 'spl_consagracion') === 'validated',
    'La autoridad y el espejo quedan consagrados juntos'
);
assertCondition(
    scalar($pdo, 'SELECT validated_at FROM spell_reviews WHERE spell_id = :id', [':id' => 'spl_consagracion']) !== '',
    'La consagracion queda fechada en el expediente'
);
$awardedPoints = awardedPointsOf($pdo, 'spl_consagracion');
$consagracionInput = new SpellCalculationInputDto(
    damage: 30,
    healing: 0,
    barrier: 0,
    crowdControlType: 'none',
    rangeType: 'short',
    areaType: 'singleTarget',
    durationType: 'instant',
    hasVerbal: true,
    hasSomatic: false,
    hasMaterial: false,
);
$publishedCircle = (int) scalar($pdo, 'SELECT circle FROM spells WHERE id = :id', [':id' => 'spl_consagracion']);
assertCondition($awardedPoints > 0, 'La consagracion acredita gloria al linaje ORIGINARIO (RF-05.3)');
assertCondition(
    $publishedCircle === $balance->calculate($consagracionInput)->circle
    && (int) scalar($pdo, 'SELECT mana_cost FROM spells WHERE id = :id', [':id' => 'spl_consagracion']) === $balance->calculate($consagracionInput)->finalManaCost,
    'El Circulo y el mana publicados son los del balance recalculado, no los del borrador (Art. II)'
);
assertCondition(
    (int) scalar($pdo, 'SELECT base_points FROM dominion_awards WHERE source_id = :id', [':id' => 'spl_consagracion'])
    === DominionAwardDto::circleBasePoints($publishedCircle),
    'El valor base del merito es el del Circulo Arcano PUBLICADO (100 + C x 20)'
);
assertCondition(
    weeklyPointsOf($pdo, 'cln_fuego') === $weeklyFuegoBefore + $awardedPoints,
    'El marcador semanal del linaje crece EXACTAMENTE en lo acreditado'
);
assertCondition(
    historicalPointsOf($pdo, 'cln_fuego') === $historicalFuegoBefore,
    'El haber perpetuo NO se toca: el pliegue es del cierre dominical (RF-04.3 de SPEC-07)'
);
assertCondition(
    (int) scalar($pdo, "SELECT COUNT(*) FROM dominion_awards WHERE source_id = 'spl_consagracion'") === 1,
    'El merito paga una sola vez: un recibo por consagracion (RNF-01)'
);
assertCondition(
    (int) scalar($pdo, "SELECT COUNT(*) FROM audit_log WHERE action_type = 'SPELL_CONSECRATED' AND target_entity_id = 'spl_consagracion'") === 1,
    'La consagracion queda inscrita como SPELL_CONSECRATED (RF-06.1)'
);
assertCondition(
    errorCodeOf(dispatch('POST', '/api/v1/moderation/spells/spl_consagracion/retract', $maestroMareaUno)) === 'SIGNATURE_IRREVOCABLE',
    'Consagrada la obra, retractarse es imposible: SIGNATURE_IRREVOCABLE (409, RF-02.4)'
);

endBlock();

// ---------------------------------------------------------------------------
// BLOQUE 3 — Veto ético: linaje del autor y ex-linaje de 30 días
// ---------------------------------------------------------------------------
beginBlock('BLOQUE 3 · Veto etico por linaje del autor y ex-linaje de los ultimos 30 dias (RF-03.1, Art. III)');

assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_veto/submit', $autoraDos)) === 200, 'La obra del veto se eleva: 200');

$sameClanVeto = dispatch('POST', '/api/v1/moderation/spells/spl_veto/sign', $maestroFuego, ['ceremonialGloss' => $gloss]);
assertCondition(statusOf($sameClanVeto) === 403, 'El Maestro del linaje del autor queda vetado: 403');
assertCondition(errorCodeOf($sameClanVeto) === 'CONSTITUTIONAL_ETHICS_VETO', 'El 403 declara CONSTITUTIONAL_ETHICS_VETO');
assertCondition(
    str_contains(errorMessageOf($sameClanVeto), 'linaje') || str_contains(errorMessageOf($sameClanVeto), 'Hermandad'),
    'La leyenda del veto se pronuncia en noble castellano (Art. IV)'
);

$historicalVeto = dispatch('POST', '/api/v1/moderation/spells/spl_veto/sign', $maestroMudado, ['ceremonialGloss' => $gloss]);
assertCondition(statusOf($historicalVeto) === 403, 'Quien habito el linaje hace 29 dias tambien queda vetado: 403');
assertCondition(errorCodeOf($historicalVeto) === 'CONSTITUTIONAL_ETHICS_VETO', 'El veto historico declara el mismo codigo canonico');
assertCondition(
    (int) scalar($pdo, "SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_veto'") === 0,
    'Ningun veto deja firma alguna: la Torre no inscribe lo que prohibe'
);

assertCondition(
    statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_veto/sign', $maestroMarchado, ['ceremonialGloss' => $gloss])) === 200,
    'Quien partio hace 31 dias recupera su potestad: firma con 200'
);
assertCondition(
    statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_veto/sign', $ermitanoUno, ['ceremonialGloss' => $gloss])) === 200,
    'El ermitaño no tiene linaje con el que chocar: firma con 200'
);
assertCondition(indicatorOf($pdo, 'spl_veto') === 2, 'Los dos avales legitimos quedan contados en 2/3');

endBlock();

// ---------------------------------------------------------------------------
// BLOQUE 4 — Auto-firma prohibida
// ---------------------------------------------------------------------------
beginBlock('BLOQUE 4 · Auto-firma prohibida al autor con rango de Maestro (RF-03.2)');

assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_propia/submit', $maestroAutor)) === 200, 'El autor-Maestro eleva su propia obra: 200');
$selfSignature = dispatch('POST', '/api/v1/moderation/spells/spl_propia/sign', $maestroAutor, ['ceremonialGloss' => $gloss]);
assertCondition(statusOf($selfSignature) === 403, 'La propia pluma no se avala: 403');
assertCondition(errorCodeOf($selfSignature) === 'SELF_SIGNING_PROHIBITED', 'El 403 declara SELF_SIGNING_PROHIBITED');
assertCondition(indicatorOf($pdo, 'spl_propia') === 0, 'El contador de la obra del autor-Maestro permanece en 0/3');
assertCondition(
    (int) scalar($pdo, "SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_propia'") === 0,
    'La auto-firma no deja rastro alguno en la Torre'
);
assertCondition(
    statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_propia/sign', $ermitanoUno, ['ceremonialGloss' => $gloss])) === 200,
    'Un tercer Maestro si puede avalar la obra del autor-Maestro: 200'
);

// El otro rango del escenario: un Administrador Supremo autor. Su pluma no
// juzga en la Torre (CANONICAL_JUDGE_ROLES), y su via es el decreto —donde le
// espera otro veto, el de la propia obra, que el bloque 10 comprueba—.
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_del_supremo_autor/submit', $supremoAutor)) === 200, 'El Administrador Supremo eleva su propia obra: 200');
$supremeSelfSign = dispatch('POST', '/api/v1/moderation/spells/spl_del_supremo_autor/sign', $supremoAutor, ['ceremonialGloss' => $gloss]);
assertCondition(statusOf($supremeSelfSign) === 403, 'La potestad suprema no pluma en la Torre, ni siquiera sobre su obra: 403');
assertCondition(errorCodeOf($supremeSelfSign) === 'INSUFFICIENT_RANK_TO_JUDGE', 'El 403 declara INSUFFICIENT_RANK_TO_JUDGE');
assertCondition(indicatorOf($pdo, 'spl_del_supremo_autor') === 0, 'Su contador permanece en 0/3: el gesto no prospero');

endBlock();

// ---------------------------------------------------------------------------
// BLOQUE 5 — Pluralidad de hermandades y ermitaños neutrales
// ---------------------------------------------------------------------------
beginBlock('BLOQUE 5 · Pluralidad de hermandades y admision de ermitanos neutrales (RF-02.1, Art. III)');

assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_pluralidad/submit', $autoraUno)) === 200, 'La obra de la pluralidad se eleva: 200');
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_pluralidad/sign', $maestroMareaUno, ['ceremonialGloss' => $gloss])) === 200, 'La primera voz de la Marea firma: 200');

$secondVoice = dispatch('POST', '/api/v1/moderation/spells/spl_pluralidad/sign', $maestroMareaDos, ['ceremonialGloss' => $gloss]);
assertCondition(statusOf($secondVoice) === 409, 'La segunda voz del MISMO estandarte choca: 409');
assertCondition(errorCodeOf($secondVoice) === 'CLAN_PLURALITY_VIOLATION', 'El 409 declara CLAN_PLURALITY_VIOLATION');
assertCondition(indicatorOf($pdo, 'spl_pluralidad') === 1, 'El rechazo no infla el contador: sigue en 1/3');
assertCondition(
    (int) scalar($pdo, "SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_pluralidad' AND master_id = 'usr_maestro_marea_dos'") === 0,
    'La voz rechazada no inscribe firma alguna'
);
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_pluralidad/sign', $ermitanoUno, ['ceremonialGloss' => $gloss])) === 200, 'El primer ermitaño firma: 200');
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_pluralidad/sign', $ermitanoDos, ['ceremonialGloss' => $gloss])) === 200, 'El segundo ermitaño firma: 200');
assertCondition(
    reviewStatusOf($pdo, 'spl_pluralidad') === 'validated',
    'Tres Maestros de tres clanes distintos (dos de ellos sin clan) consagran la obra (RF-02.1)'
);
assertCondition(
    (int) scalar($pdo, "SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_pluralidad' AND master_clan_id IS NULL") === 2,
    'Los avales de los ermitaños se firman SIN linaje: no ocupan plaza de hermandad alguna'
);

endBlock();

// ---------------------------------------------------------------------------
// BLOQUE 6 — Veto de calidad por dictamen de objeción
// ---------------------------------------------------------------------------
beginBlock('BLOQUE 6 · Veto de calidad por dictamen de objecion y retiro del Atrio (RF-02.5, RF-02.6, RF-05.1)');

assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_objecion/submit', $autoraCuatro)) === 200, 'La obra del dictamen se eleva: 200');
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_objecion/sign', $ermitanoUno, ['ceremonialGloss' => $gloss])) === 200, 'El primer aval entra: 200');
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_objecion/sign', $ermitanoDos, ['ceremonialGloss' => $gloss])) === 200, 'El segundo aval entra: 200');

$hallBefore = payloadOf(dispatch('GET', '/api/v1/moderation/experimental'));
$hallIdsBefore = array_map(
    static fn (array $item): string => (string) ($item['spellId'] ?? ''),
    (array) ($hallBefore['data']['items'] ?? []),
);
assertCondition(in_array('spl_objecion', $hallIdsBefore, true), 'La obra en deliberacion figura en el Atrio publico (RF-05.1)');

$briefVerdict = dispatch('POST', '/api/v1/moderation/spells/spl_objecion/object', $ermitanoTres, ['objectionReason' => str_repeat('a', 19)]);
assertCondition(statusOf($briefVerdict) === 422, 'Un dictamen de 19 caracteres no veta: 422 (RF-02.5)');
assertCondition(errorCodeOf($briefVerdict) === 'OBJECTION_TOO_BRIEF', 'El 422 declara OBJECTION_TOO_BRIEF');
assertCondition(
    statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_objecion/object', $ermitanoTres, ['objectionReason' => str_repeat(' ', 40)])) === 422,
    'Cuarenta espacios no son justificacion: 422'
);
assertCondition(indicatorOf($pdo, 'spl_objecion') === 2, 'Los dictamenes breves no tocan el contador: sigue en 2/3');

$verdictText = 'El conjuro presenta anacronismos que vulneran el Velo Arcano.';
$validVerdict = dispatch('POST', '/api/v1/moderation/spells/spl_objecion/object', $ermitanoTres, ['objectionReason' => $verdictText]);
assertCondition(statusOf($validVerdict) === 200, 'Un dictamen de 61 caracteres veta la obra: 200 (RF-02.6)');
assertCondition(
    (string) (payloadOf($validVerdict)['data']['review']['status'] ?? '') === 'rejected'
    && reviewStatusOf($pdo, 'spl_objecion') === 'rejected'
    && mirrorStatusOf($pdo, 'spl_objecion') === 'rejected',
    'La obra pasa a rejected en la autoridad y en su espejo'
);
assertCondition(
    (int) scalar($pdo, "SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_objecion' AND is_revoked = 1 AND revocation_reason = 'review_rejected'") === 2,
    'Los DOS avales previos caen con el motivo canonico review_rejected (RF-02.6)'
);
assertCondition(indicatorOf($pdo, 'spl_objecion') === 0 && liveSignatureCount($pdo, 'spl_objecion') === 0, 'El contador vuelve a cero tras el veto');
assertCondition(
    scalar($pdo, 'SELECT rejected_at FROM spell_reviews WHERE spell_id = :id', [':id' => 'spl_objecion']) !== '',
    'El veto queda fechado en el expediente'
);
assertCondition(
    scalar($pdo, 'SELECT objection_reason FROM objection_verdicts WHERE spell_id = :id', [':id' => 'spl_objecion']) === $verdictText,
    'El dictamen se conserva caracter a caracter para que el autor subsane (RF-06.2)'
);

$hallAfter = payloadOf(dispatch('GET', '/api/v1/moderation/experimental'));
$hallIdsAfter = array_map(
    static fn (array $item): string => (string) ($item['spellId'] ?? ''),
    (array) ($hallAfter['data']['items'] ?? []),
);
assertCondition(!in_array('spl_objecion', $hallIdsAfter, true), 'La obra vetada se retira del Atrio en el mismo gesto (RF-05.1)');
assertCondition(
    count($hallIdsAfter) === count($hallIdsBefore) - 1,
    'El Atrio pierde exactamente una obra: la vetada'
);
assertCondition(
    (bool) ($hallAfter['data']['hallWarning']['pointsBlocked'] ?? false) === true,
    'La insignia del Atrio declara el bloqueo de gloria de las obras en deliberacion (RF-05.3)'
);

endBlock();

// ---------------------------------------------------------------------------
// BLOQUE 7 — Re-apertura formal con el dictamen a la vista
// ---------------------------------------------------------------------------
beginBlock('BLOQUE 7 · Reapertura formal con el dictamen a la vista y el cupo liberado (RF-01.4, RF-06.2)');

$reopen = dispatch('POST', '/api/v1/moderation/spells/spl_objecion/reopen', $autoraCuatro);
$reopenPayload = payloadOf($reopen);
assertCondition(statusOf($reopen) === 200, 'El autor reabre su obra vetada: 200');
assertCondition(
    (string) ($reopenPayload['data']['review']['status'] ?? '') === 'draft'
    && reviewStatusOf($pdo, 'spl_objecion') === 'draft'
    && mirrorStatusOf($pdo, 'spl_objecion') === 'draft',
    'La obra vuelve a la libreta de su autor: draft'
);
assertCondition(
    (string) ($reopenPayload['data']['lastVerdict']['objectionReason'] ?? '') === $verdictText,
    'El dictamen viaja INTEGRO en la re-apertura: el autor subsana leyendo la ley'
);
// El cupo: una obra vetada JAMAS consumio plaza, de modo que la re-apertura la
// devuelve entera. No se aserta el contador: se aserta que el autor PUEDE
// elevar de inmediato una obra nueva, que es lo unico que prueba una plaza.
assertCondition(
    (int) ($reopenPayload['data']['remainingCapacity'] ?? -1) === ModerationWorkflowService::MAX_CONCURRENT_REVIEWS
    && (int) scalar($pdo, "SELECT COUNT(*) FROM spell_reviews WHERE author_id = 'usr_autora_cuatro' AND status = 'experimental'") === 0,
    'La plaza del autor queda ENTERA: la obra vetada jamas la consumio (RF-01.5)'
);
assertCondition(
    statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_reapertura/submit', $autoraCuatro)) === 200,
    'El autor eleva una obra nueva acto seguido: la plaza es real, no un numero (RF-01.5)'
);
assertCondition(
    scalar($pdo, 'SELECT objection_reason FROM objection_verdicts WHERE spell_id = :id', [':id' => 'spl_objecion']) === $verdictText,
    'La memoria del dictamen sobrevive a la re-apertura (RF-06.2)'
);
assertCondition(
    errorCodeOf(dispatch('POST', '/api/v1/moderation/spells/spl_objecion/reopen', $autoraCuatro)) === 'SPELL_NOT_REJECTED',
    'Una obra ya reabierta no se reabre dos veces: SPELL_NOT_REJECTED (400)'
);

endBlock();

// ---------------------------------------------------------------------------
// BLOQUE 8 — Cupo de tres obras concurrentes
// ---------------------------------------------------------------------------
beginBlock('BLOQUE 8 · Cupo de tres obras concurrentes y su liberacion inmediata (RF-01.2, RF-01.5)');

foreach (['spl_cupo_uno', 'spl_cupo_dos', 'spl_cupo_tres'] as $index => $spellId) {
    $response = dispatch('POST', '/api/v1/moderation/spells/' . $spellId . '/submit', $autoraCinco);
    assertCondition(statusOf($response) === 200, 'La plaza ' . ($index + 1) . ' del cupo se ocupa: 200');
}
assertCondition($workflow->remainingCapacity('usr_autora_cinco') === 0, 'Con tres obras en la Torre el autor no tiene plazas');

$fourth = dispatch('POST', '/api/v1/moderation/spells/spl_cupo_cuatro/submit', $autoraCinco);
assertCondition(statusOf($fourth) === 409, 'La cuarta obra concurrente es rechazada: 409 (RF-01.2)');
assertCondition(errorCodeOf($fourth) === 'TOWER_CAPACITY_EXCEEDED', 'El 409 declara TOWER_CAPACITY_EXCEEDED');
assertCondition(
    mirrorStatusOf($pdo, 'spl_cupo_cuatro') === 'draft'
    && (int) scalar($pdo, "SELECT COUNT(*) FROM spell_reviews WHERE spell_id = 'spl_cupo_cuatro'") === 0,
    'La obra rechazada permanece intacta en su libreta: sin expediente ni rastro'
);

$withdraw = dispatch('POST', '/api/v1/moderation/spells/spl_cupo_uno/withdraw', $autoraCinco);
assertCondition(statusOf($withdraw) === 200 && $workflow->remainingCapacity('usr_autora_cinco') === 1, 'La retirada libera una plaza de inmediato (RF-01.5)');
assertCondition(
    statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_cupo_cuatro/submit', $autoraCinco)) === 200,
    'La cuarta obra entra ahora que hay plaza: 200'
);
assertCondition(
    $workflow->remainingCapacity('usr_autora_cinco') === 0 && reviewStatusOf($pdo, 'spl_cupo_uno') === 'draft',
    'La plaza liberada se ocupa y la obra retirada descansa en la libreta'
);

endBlock();

// ---------------------------------------------------------------------------
// BLOQUE 9 — Anulación de oficio: conflicto sobrevenido y pérdida de rango
// ---------------------------------------------------------------------------
beginBlock('BLOQUE 9 · Anulacion de oficio por conflicto sobrevenido y perdida de rango (RF-03.4, RF-03.5)');

assertCondition(
    statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_anulable/submit', $autoraCuatro)) === 200,
    'La obra anulable se eleva: 200 (segunda plaza del autor, ya reabierto)'
);
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_anulable/sign', $maestroAjeno, ['ceremonialGloss' => $gloss])) === 200, 'El Maestro ajeno firma: 200');
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_anulable/sign', loadUser($pdo, 'usr_maestro_efimero'), ['ceremonialGloss' => $gloss])) === 200, 'El Maestro efimero firma: 200');
assertCondition(indicatorOf($pdo, 'spl_anulable') === 2 && liveSignatureCount($pdo, 'spl_anulable') === 2, 'Dos avales vivos sobre la obra');

$rankLoss = $ethics->revokeConflictedSignatures('usr_maestro_efimero', null, 'editor', $CLOCK);
assertCondition($rankLoss->count() === 1, 'La degradacion de rango derriba UN aval: N-1');
assertCondition(
    (string) scalar($pdo, "SELECT revocation_reason FROM master_signatures WHERE spell_id = 'spl_anulable' AND master_id = 'usr_maestro_efimero'") === 'rank_lost',
    'El motivo conservado es la perdida del rango, no un conflicto que no existia'
);
assertCondition(
    indicatorOf($pdo, 'spl_anulable') === 1
    && (int) scalar($pdo, 'SELECT signatures_count FROM spells WHERE id = :id', [':id' => 'spl_anulable']) === 1
    && liveSignatureCount($pdo, 'spl_anulable') === 1,
    'La autoridad, el espejo y las firmas reales quedan contados en 1: N-1 exacto'
);
assertCondition(
    scalar($pdo, "SELECT revoked_at FROM master_signatures WHERE spell_id = 'spl_anulable' AND master_id = 'usr_maestro_efimero'") !== '',
    'La anulacion queda fechada'
);
assertCondition(
    (int) scalar($pdo, "SELECT COUNT(*) FROM audit_log WHERE action_type = 'SIGNATURE_ANNULMENT' AND target_entity_id = 'spl_anulable'") === 1,
    'La anulacion de oficio se inscribe en la Bitacora publica (RF-03.5)'
);

// El conflicto SOBREVIENE: el Maestro entra en el linaje de la obra que avalo.
// Solo hay UNA membresia viva por mago (indice unico parcial `idx_active_member`),
// de modo que la mudanza cierra la fila anterior —con su convalecencia arcana—
// antes de inscribir la nueva: el historial es la autoridad del veto.
$pdo->exec(
    "UPDATE clan_members
        SET left_at = '{$STAMP}',
            convalescence_expires_at = '{$CLOCK->modify('+14 days')->format('Y-m-d\TH:i:s\Z')}'
      WHERE user_id = 'usr_maestro_ajeno' AND left_at IS NULL"
);
$pdo->exec("UPDATE users SET clan_id = 'cln_marea' WHERE id = 'usr_maestro_ajeno'");
forgeMembership($pdo, 'mem_ajeno_marea', 'cln_marea', 'usr_maestro_ajeno', $STAMP);
$conflict = $ethics->revokeConflictedSignatures('usr_maestro_ajeno', 'cln_marea', null, $CLOCK);
assertCondition($conflict->count() === 1, 'El conflicto sobrevenido derriba el aval restante: N-1');
assertCondition(
    (string) scalar($pdo, "SELECT revocation_reason FROM master_signatures WHERE spell_id = 'spl_anulable' AND master_id = 'usr_maestro_ajeno'") === 'clan_conflict_arisen',
    'El motivo canonico es clan_conflict_arisen'
);
assertCondition(
    indicatorOf($pdo, 'spl_anulable') === 0 && liveSignatureCount($pdo, 'spl_anulable') === 0,
    'La deliberacion queda limpia: cero avales vivos'
);
assertCondition($ethics->revokeConflictedSignatures('usr_maestro_ajeno', 'cln_marea', null, $CLOCK)->count() === 0, 'La anulacion es idempotente: repetirla no derriba nada mas');
assertCondition(
    $ethics->revokeConflictedSignatures('usr_maestro_marea_uno', null, 'editor', $CLOCK)->count() === 0,
    'Una obra ya consagrada es irrevocable: la degradacion no alcanza su aval (RF-02.7)'
);
assertCondition(
    $ethics->revokeConflictedSignatures('usr_ermitano_uno', null, null, $CLOCK)->count() === 0,
    'Sin causa concurrente no hay anulacion alguna'
);

endBlock();

// ---------------------------------------------------------------------------
// BLOQUE 10 — Firma Soberana: la inviolabilidad del borrador y el veto del linaje propio
// ---------------------------------------------------------------------------
beginBlock('BLOQUE 10 · Firma Soberana: inviolabilidad del borrador y veto al linaje propio (RF-04.1, RF-04.2)');

$edict = 'Se constata la excelencia matematica y liturgica de esta obra: queda consagrada de oficio.';

// Un borrador que la Torre NO conoce no tiene expediente: para el Conclave
// Supremo es una obra inexistente, y asi lo declara.
$onUnknownDraft = dispatch('POST', '/api/v1/moderation/sovereign/validate', $supremo, ['spellId' => 'spl_privada', 'imperialDecreeText' => $edict]);
assertCondition(
    statusOf($onUnknownDraft) === 404 && errorCodeOf($onUnknownDraft) === 'SPELL_NOT_FOUND',
    'La Firma Soberana no alcanza un borrador sin expediente: 404'
);
assertCondition(
    mirrorStatusOf($pdo, 'spl_privada') === 'draft'
    && (int) scalar($pdo, "SELECT COUNT(*) FROM sovereign_decrees WHERE spell_id = 'spl_privada'") === 0,
    'El borrador privado queda intacto y sin decreto: la libreta del autor es inviolable'
);

// El borrador CON expediente (la obra creada despues de la Torre): 400.
$reviews->createOrUpdateReview('rev_privada', 'spl_privada', 'usr_autora_uno', 'draft', $FINGERPRINT, 'cln_fuego', 0, null);
$onDraft = dispatch('POST', '/api/v1/moderation/sovereign/validate', $supremo, ['spellId' => 'spl_privada', 'imperialDecreeText' => $edict]);
assertCondition(statusOf($onDraft) === 400, 'La Firma Soberana no alcanza un borrador con expediente: 400');
assertCondition(errorCodeOf($onDraft) === 'CANNOT_SOVEREIGN_VALIDATE_NON_EXPERIMENTAL', 'El 400 declara CANNOT_SOVEREIGN_VALIDATE_NON_EXPERIMENTAL');
assertCondition(
    reviewStatusOf($pdo, 'spl_privada') === 'draft'
    && (int) scalar($pdo, "SELECT COUNT(*) FROM sovereign_decrees WHERE spell_id = 'spl_privada'") === 0,
    'El borrador sigue en la libreta de su autor, jamas en el Gran Tomo'
);

assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_del_linaje/submit', $autoraOcho)) === 200, 'La obra del linaje del Supremo se eleva: 200');
$ownClanVeto = dispatch('POST', '/api/v1/moderation/sovereign/validate', $supremoFuego, ['spellId' => 'spl_del_linaje', 'imperialDecreeText' => $edict]);
assertCondition(statusOf($ownClanVeto) === 403, 'El Supremo no consagra la obra de su propio linaje: 403 (Art. III.2)');
assertCondition(errorCodeOf($ownClanVeto) === 'SOVEREIGN_OWN_CLAN_VETO', 'El 403 declara SOVEREIGN_OWN_CLAN_VETO');
assertCondition(
    (int) scalar($pdo, "SELECT COUNT(*) FROM sovereign_decrees WHERE spell_id = 'spl_del_linaje'") === 0,
    'El veto no deja decreto inscrito'
);
assertCondition(
    statusOf(dispatch('POST', '/api/v1/moderation/sovereign/validate', $maestroMareaUno, ['spellId' => 'spl_del_linaje', 'imperialDecreeText' => $edict])) === 403,
    'Un Maestro de la Torre tampoco dicta decretos soberanos: 403'
);

// La propia pluma: la otra cara del escenario 4, donde la potestad suprema
// tampoco alcanza. El Supremo si puede elevar su obra, jamas consagrarla.
$selfValidation = dispatch('POST', '/api/v1/moderation/sovereign/validate', $supremoAutor, ['spellId' => 'spl_del_supremo_autor', 'imperialDecreeText' => $edict]);
assertCondition(statusOf($selfValidation) === 403, 'El Supremo no consagra su propia obra de oficio: 403 (RF-03.2)');
assertCondition(errorCodeOf($selfValidation) === 'SELF_VALIDATION_PROHIBITED', 'El 403 declara SELF_VALIDATION_PROHIBITED');
assertCondition(
    (int) scalar($pdo, "SELECT COUNT(*) FROM sovereign_decrees WHERE spell_id = 'spl_del_supremo_autor'") === 0
    && reviewStatusOf($pdo, 'spl_del_supremo_autor') === 'experimental',
    'La propia pluma no deja decreto ni altera la deliberacion'
);

assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_soberana/submit', $autoraCuatro)) === 200, 'La obra soberana se eleva: 200');
$weeklyMareaBefore = weeklyPointsOf($pdo, 'cln_marea');
$sovereignValidation = dispatch('POST', '/api/v1/moderation/sovereign/validate', $supremo, ['spellId' => 'spl_soberana', 'imperialDecreeText' => $edict]);
$sovereignPayload = payloadOf($sovereignValidation);
assertCondition(statusOf($sovereignValidation) === 200, 'El Supremo consagra de oficio: 200');
assertCondition(
    reviewStatusOf($pdo, 'spl_soberana') === 'validated' && mirrorStatusOf($pdo, 'spl_soberana') === 'validated',
    'La Firma Soberana eleva la obra al Gran Tomo (RF-04.1)'
);
assertCondition(
    (string) ($sovereignPayload['data']['decree']['imperialDecreeText'] ?? '') === $edict
    && (int) scalar($pdo, "SELECT COUNT(*) FROM sovereign_decrees WHERE spell_id = 'spl_soberana'") === 1,
    'El Edicto Imperial viaja integro y queda inscrito (RF-04.5)'
);
assertCondition(weeklyPointsOf($pdo, 'cln_marea') > $weeklyMareaBefore, 'La consagracion de oficio acredita gloria al linaje originario (RF-05.3)');

endBlock();

// ---------------------------------------------------------------------------
// BLOQUE 11 — Caducidad por letargo de noventa días
// ---------------------------------------------------------------------------
beginBlock('BLOQUE 11 · Caducidad por letargo de noventa dias (RF-01.6)');

// La obra letárgica recibe su expediente por la API del repositorio (la
// autoridad del ciclo de vida), con la entrada a la Torre NOVENTA Y UN DIAS
// atrás: el reloj lo pone el santuario, jamás un instante del exterior.
$reviews->createOrUpdateReview('rev_letargo', 'spl_letargo', 'usr_autora_siete', 'experimental', $FINGERPRINT, 'cln_fuego', 1, $NINETY_ONE_DAYS_AGO);
$pdo->exec(
    "INSERT INTO master_signatures (id, spell_id, master_id, master_clan_id, signed_at, is_revoked)
     VALUES ('sig_letargo', 'spl_letargo', 'usr_maestro_marea_uno', 'cln_marea', '{$NINETY_ONE_DAYS_AGO}', 0)"
);
assertCondition(reviewStatusOf($pdo, 'spl_letargo') === 'experimental' && mirrorStatusOf($pdo, 'spl_letargo') === 'experimental', 'La obra letargica yace en deliberacion desde hace 91 dias');

assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/cron-check-expiry')) === 401, 'Sin el sello del custodio el letargo no se invoca: 401');
assertCondition(
    statusOf(dispatch('POST', '/api/v1/moderation/cron-check-expiry', null, null, ['X-Arcane-Cron-Secret' => 'sello-ajeno'])) === 403,
    'Un sello ajeno es rechazado: 403'
);

$capacityBeforeSweep = $workflow->remainingCapacity('usr_autora_siete');
$sweep = dispatch('POST', '/api/v1/moderation/cron-check-expiry', null, null, ['X-Arcane-Cron-Secret' => CRON_SECRET]);
$sweepPayload = payloadOf($sweep);
assertCondition(statusOf($sweep) === 200, 'Con el sello del custodio el barrido se ejecuta: 200');
$expiredIds = array_map(
    static fn (array $expired): string => (string) ($expired['spellId'] ?? ''),
    (array) ($sweepPayload['data']['expired'] ?? []),
);
assertCondition(
    (int) ($sweepPayload['data']['expiredCount'] ?? -1) === 1 && $expiredIds === ['spl_letargo'],
    'Caduca EXACTAMENTE la obra letargica: el barrido no toca lo reciente'
);
assertCondition(
    (string) ($sweepPayload['data']['legend'] ?? '') === ModerationWorkflowService::EXPIRY_LEGEND
    && (int) ($sweepPayload['data']['staleDays'] ?? 0) === ModerationWorkflowService::STALE_REVIEW_DAYS,
    'El acta declara la leyenda canonica y el umbral de noventa dias naturales (Art. IV)'
);
assertCondition(
    reviewStatusOf($pdo, 'spl_letargo') === 'rejected' && mirrorStatusOf($pdo, 'spl_letargo') === 'rejected',
    'La obra letargica pasa a rejected en la autoridad y en su espejo'
);
assertCondition(
    (int) scalar($pdo, "SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_letargo' AND is_revoked = 1 AND revocation_reason = 'review_expired'") === 1,
    'El aval viejo cae con el motivo canonico review_expired'
);
assertCondition(
    $workflow->remainingCapacity('usr_autora_siete') === $capacityBeforeSweep + 1,
    'El letargo libera el cupo del autor en el mismo barrido (RF-01.5)'
);
assertCondition(
    (int) scalar($pdo, "SELECT COUNT(*) FROM audit_log WHERE action_type = 'MODERATION_EXPIRED' AND target_entity_id = 'spl_letargo'") === 1,
    'La caducidad se inscribe en la Bitacora con su acto propio (RF-06.1)'
);
assertCondition(
    (int) (payloadOf(dispatch('POST', '/api/v1/moderation/cron-check-expiry', null, null, ['X-Arcane-Cron-Secret' => CRON_SECRET]))['data']['expiredCount'] ?? -1) === 0,
    'El barrido es idempotente: la segunda pasada no caduca nada'
);

endBlock();

// ---------------------------------------------------------------------------
// BLOQUE 12 — Herencia Ancestral ante la disolución del linaje
// ---------------------------------------------------------------------------
beginBlock('BLOQUE 12 · Herencia Ancestral ante la disolucion del linaje (RF-03.7, Plan Sec. 6.1)');

assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_herencia/submit', $autoraSeis)) === 200, 'La obra del linaje mortal se eleva: 200');
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_herencia/sign', $ermitanoUno, ['ceremonialGloss' => $gloss])) === 200, 'El primer aval entra: 200');
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_herencia/sign', $ermitanoDos, ['ceremonialGloss' => $gloss])) === 200, 'El segundo aval entra: 200');

// El linaje se disuelve MIENTRAS la obra se delibera (RF-05.3 de SPEC-07).
assertCondition($clanRepository->setStatusArchived('cln_sombra', $STAMP), 'El linaje originario se disuelve durante la revision');
$historicalSombraBefore = historicalPointsOf($pdo, 'cln_sombra');

$finalSign = dispatch('POST', '/api/v1/moderation/spells/spl_herencia/sign', $ermitanoTres, ['ceremonialGloss' => $gloss]);
assertCondition(statusOf($finalSign) === 200 && (bool) (payloadOf($finalSign)['data']['consecrated'] ?? false) === true, 'La tercera firma consagra la obra del linaje disuelto');
assertCondition(reviewStatusOf($pdo, 'spl_herencia') === 'validated', 'La obra entra al Gran Tomo como Herencia Ancestral (RF-03.7)');
$heritageAward = awardedPointsOf($pdo, 'spl_herencia');
assertCondition($heritageAward > 0, 'La consagracion acredita su gloria');
assertCondition(
    weeklyPointsOf($pdo, 'cln_sombra') === 0,
    'La casa disuelta NO disputa el Dominio semanal: su marcador queda en cero'
);
assertCondition(
    historicalPointsOf($pdo, 'cln_sombra') === $historicalSombraBefore + $heritageAward,
    'La gloria se inscribe DIRECTAMENTE en el haber perpetuo: Herencia Ancestral'
);

endBlock();

// ---------------------------------------------------------------------------
// BLOQUE 13 — Auditoría constitucional del flujo
// ---------------------------------------------------------------------------
beginBlock('BLOQUE 13 · Auditoria constitucional del flujo (RNF-01 a RNF-05, Art. I, IV y V)');

// Artículo I: el plano arcano es memoria volátil, y no hay dependencias ajenas.
$mainDatabaseFile = scalar($pdo, "SELECT file FROM pragma_database_list WHERE name = 'main'");
assertCondition($mainDatabaseFile === '', 'El plano arcano es SQLite EN MEMORIA: esta suite jamas toca el disco');
assertCondition(
    !file_exists($projectRoot . '/package.json')
    && !file_exists($projectRoot . '/composer.json')
    && !file_exists($projectRoot . '/vendor')
    && !file_exists($projectRoot . '/node_modules'),
    'El santuario no declara dependencias npm ni Composer (RNF-05, Dogma Vanilla)'
);

$controllerSources = [];
foreach (['ModerationController', 'MasterDeliberationController', 'SovereignAdminController'] as $controllerName) {
    $controllerSources[$controllerName] = (string) file_get_contents($projectRoot . '/src/Controllers/' . $controllerName . '.php');
}
$sqlFree = true;
$strictTyped = true;
foreach ($controllerSources as $source) {
    if (preg_match('/\b(INSERT INTO|UPDATE |DELETE FROM|SELECT .* FROM)\b/', $source) === 1 || str_contains($source, 'new PDO')) {
        $sqlFree = false;
    }
    if (!str_contains(substr($source, 0, 2000), 'declare(strict_types=1);')) {
        $strictTyped = false;
    }
}
assertCondition($sqlFree, 'Los tres controladores del flujo no escriben SQL ni abren conexiones: delegan en sus servicios (Art. I)');
assertCondition($strictTyped, 'Los tres controladores declaran tipos estrictos (AGENTS.md 6.1)');
assertCondition(
    str_contains((string) file_get_contents(__FILE__), 'declare(strict_types=1);'),
    'Esta misma suite declara tipos estrictos (RNF-05)'
);

// Artículo V y RNF-01: todo acto inscrito pertenece al catálogo canonico y
// tiene su rotulo castellano en la Bitacora publica.
$auditEntrySource = (string) file_get_contents($projectRoot . '/src/Models/AuditEntry.php');
preg_match('/CANONICAL_ACTION_TYPES = \[(.*?)\];/s', $auditEntrySource, $catalogMatch);
preg_match_all("/'([A-Z][A-Z_]+)'/", (string) ($catalogMatch[1] ?? ''), $catalogHits);
$canonicalActs = $catalogHits[1] ?? [];
assertCondition(count($canonicalActs) >= 20, 'El catalogo canonico de actos de la Bitacora se lee de su fuente unica');

$auditLogSource = (string) file_get_contents($projectRoot . '/public/assets/js/views/auditLogView.js');
$inscribedActs = [];
$statement = $pdo->query('SELECT DISTINCT action_type FROM audit_log ORDER BY action_type');
foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $actionType) {
    $inscribedActs[] = (string) $actionType;
}
assertCondition(count($inscribedActs) >= 8, 'El flujo inscribe al menos ocho actos distintos en la Bitacora');
$unknownActs = array_values(array_diff($inscribedActs, $canonicalActs));
assertCondition($unknownActs === [], 'Todo acto inscrito por el flujo pertenece al catalogo canonico: ' . implode(', ', $unknownActs));
$unlabeledActs = [];
foreach ($inscribedActs as $actionType) {
    if (!str_contains($auditLogSource, $actionType . ':')) {
        $unlabeledActs[] = $actionType;
    }
}
assertCondition($unlabeledActs === [], 'Todo acto inscrito tiene su rotulo en noble castellano: ' . implode(', ', $unlabeledActs));

// RNF-01 y AGENTS.md 6.1: parameter binding ante entrada hostil. La narrativa
// del autor jamas debe poder alterar una sentencia.
$hostile = "Pergamino hostil'); DROP TABLE spells; --";
forgeSpell($pdo, 'spl_hostil', 'Ascua Hostil', 'usr_autora_ocho', 'cln_fuego', $hostile);
assertCondition(statusOf(dispatch('POST', '/api/v1/moderation/spells/spl_hostil/submit', $autoraOcho)) === 200, 'La obra de narrativa hostil se eleva: 200');
assertCondition(
    scalar($pdo, 'SELECT summary FROM spells WHERE id = :id', [':id' => 'spl_hostil']) === $hostile,
    'La narrativa hostil se conserva VERBATIM: el parameter binding la neutraliza (RNF-01)'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'spells'")->fetchColumn() === 1,
    'La tabla raiz sigue en pie: ninguna sentencia fue alterada'
);
assertCondition(
    scalar($pdo, 'SELECT math_fingerprint FROM spells WHERE id = :id', [':id' => 'spl_hostil']) === $sealedFingerprint,
    'La narrativa no participa del balance: la huella es identica a la de sus gemelas (Art. II)'
);

// RNF-03 y Art. IV: las leyendas de rechazo se pronuncian en castellano.
$refusalMessage = errorMessageOf(dispatch('POST', '/api/v1/moderation/spells/spl_hostil/submit', $lector));
assertCondition(
    mb_strlen($refusalMessage, 'UTF-8') > 20 && preg_match('/[a-z] [a-z]/u', $refusalMessage) === 1,
    'El rechazo del lector llega con su leyenda ceremonial en castellano'
);

// La raíz única del webhook de cierre no depende de esta especificación.
assertCondition(
    is_file($projectRoot . '/database/schema.sql') && is_file($projectRoot . '/sql/08_moderation_schema.sql'),
    'Las dos moradas del DDL del conclave siguen declaradas en el santuario'
);

endBlock();

// ---------------------------------------------------------------------------
// Cierre
// ---------------------------------------------------------------------------
echo "\n=================================================\n";
echo "BLOQUES DE ASERTOS\n";
foreach ($blockLedger as $block) {
    printf("  Bloque %2d · %-3s asertos · %d fallos · %s\n", $block['index'], $block['asserts'], $block['failed'], $block['title']);
}
echo "\nLos 11 escenarios criticos de la Tarea 4.1 quedan cubiertos por los bloques 1 a 11;\n";
echo "los bloques 12 y 13 completan el Plan Sec. 6.1 (Herencia Ancestral) y las garantias\n";
echo "constitucionales de RNF-01 a RNF-05.\n\n";
echo "Asertos superados: {$assertsPassed}\n";
echo "Asertos fallidos : {$assertsFailed}\n";

if ($assertsFailed > 0) {
    echo "\nFallos:\n";
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
    echo "\nRESULTADO: DENEGADO — el flujo completo de moderacion no cumple su criterio.\n";
    exit(1);
}

echo "\nRESULTADO: EXITO — los 11 escenarios criticos del flujo de moderacion pasan\n";
echo "integramente sobre la pila real de produccion (Tarea 4.1).\n";
exit(0);
