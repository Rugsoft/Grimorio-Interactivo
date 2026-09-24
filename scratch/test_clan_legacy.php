<?php

/**
 * test_clan_legacy.php — Arnés del Legado Ancestral de una hermandad.
 *
 * Tarea 6.4 (TASKS-07). Verifica el Endpoint 13 del plan
 * (`GET /api/v1/clans/{id}/spells`), que sirve los conjuros ratificados que
 * pertenecen perpetuamente al clan bajo cuyo estandarte fueron concebidos.
 *
 * Criterio «Hecho cuando» que se mide aquí, sobre la pila REAL
 * (buildRouter + Request/Response + PDO canónicos):
 *   «La vista muestra los conjuros validados del clan independientemente de si
 *    los autores siguen en la hermandad, y marca con el sello de "Herencia
 *    Ancestral" si el clan está en estado `archived`.»
 *
 * Fases:
 *   [1] El contrato del Endpoint 13 (claves, orden, Círculo, maná y créditos).
 *   [2] Inviolabilidad del patrimonio: el autor parte y su obra permanece (RF-05.1).
 *   [3] Herencia Ancestral: la casa disuelta conserva su legado (RF-05.3).
 *   [4] Fronteras: borradores, experimentales y casas ajenas jamás; 404 y vacío.
 *   [5] Pureza y seguridad: lectura pública, inyección, Router real y Dogma Vanilla.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo; cero librerías ni dependencias npm.
 *   - Artículo III: los conjuros ratificados son patrimonio inviolable del clan.
 *   - Artículo V: identificadores en inglés camelCase, narrativa en castellano.
 *
 * Uso: php scratch/test_clan_legacy.php
 * Salida: código 0 si todo pasa; 1 en caso contrario.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$projectRoot = dirname(__DIR__);

// La pila REAL (autoload nativo + buildRouter + Connection) se carga una sola
// vez; el PDO canónico se materializa al primer getPdo() sobre la base efímera.
putenv('GRIMORIO_DB_DSN=sqlite::memory:');
require_once $projectRoot . '/public/index.php';

use Grimorio\Core\Request;
use Grimorio\Database\Connection;
use Grimorio\Dto\ClanLegacySpellDto;
use Grimorio\Models\Spell;
use Grimorio\Repositories\ClanMemberRepository;
use Grimorio\Services\AuditService;
use Grimorio\Services\ClanService;

$assertsPassed = 0;
$assertsFailed = 0;
/** @var list<string> */
$failures = [];

/** Aserta una condición y registra el resultado en la bitácora. */
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

/** Despacha por el router REAL de producción (buildRouter). */
function dispatch(string $method, string $uri): object
{
    global $router;

    $path = (string) (parse_url($uri, PHP_URL_PATH) ?: $uri);

    return $router->dispatch(new Request($method, $path));
}

/** Sobre JSON decodificado de una Response. */
function payloadOf(object $response): array
{
    $decoded = json_decode($response->getBody(), true);

    return is_array($decoded) ? $decoded : [];
}

/** Código de estado de una Response. */
function statusOf(object $response): int
{
    return (int) $response->getStatusCode();
}

/** Marca temporal ISO 8601 UTC del santuario. */
function utcStamp(string $modifier = 'now'): string
{
    return (new DateTimeImmutable($modifier, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
}

/** Inscribe un mago en la tabla `users`. */
function seedUser(PDO $pdo, string $userId, string $alias, string $role = 'editor'): void
{
    $statement = $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
         VALUES (:id, :alias, :email, :passwordHash, :role, NULL, :now, :now)'
    );
    $statement->execute([
        ':id'           => $userId,
        ':alias'        => $alias,
        ':email'        => $userId . '@sanctuario.arc',
        ':passwordHash' => str_repeat('x', 60),
        ':role'         => $role,
        ':now'          => utcStamp(),
    ]);
}

/** Funda una hermandad directamente en el plano (andarivel de fixtures). */
function seedClan(
    PDO $pdo,
    string $clanId,
    string $name,
    string $status = 'active',
    string $lineageType = 'primordialFlame',
    string $admissionMode = 'open',
    ?string $patriarchId = null,
): void {
    $now = utcStamp();
    $statement = $pdo->prepare(
        'INSERT INTO clans (id, slug, name, motto, created_at, coat_of_arms, lineage_type,
                            admission_mode, status, patriarch_id, weekly_points,
                            historical_points, last_activity_at, updated_at)
         VALUES (:id, :slug, :name, :motto, :now, :coatOfArms, :lineageType,
                 :admissionMode, :status, :patriarchId, 0, 0, :now, :now)'
    );
    $statement->execute([
        ':id'            => $clanId,
        ':slug'          => strtolower(str_replace('_', '-', $clanId)),
        ':name'          => $name,
        ':motto'         => 'Lema de ' . $name,
        ':now'           => $now,
        ':coatOfArms'    => 'rune_' . $clanId,
        ':lineageType'   => $lineageType,
        ':admissionMode' => $admissionMode,
        ':status'        => $status,
        ':patriarchId'   => $patriarchId,
    ]);
}

/** Inscribe una membresía por la AUTORIDAD (`clan_members`). */
function seedMembership(PDO $pdo, string $memberId, string $clanId, string $userId, string $role = 'adept'): void
{
    (new ClanMemberRepository($pdo))->addMember($memberId, $clanId, $userId, $role, utcStamp('-30 days'));
}

/** Inscribe un conjuro en el plano con el estado y la autoría indicados. */
function seedSpell(
    PDO $pdo,
    string $spellId,
    string $slug,
    string $name,
    string $authorId,
    string $clanId,
    string $status = 'validated',
    int $circle = 1,
    int $manaCost = 5,
    string $school = 'evocation',
    string $validatedAt = '2026-09-01T00:00:00Z',
    bool $isGenesis = false,
): void {
    $statement = $pdo->prepare(
        'INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, casting_time,
                             mana_cost, circle, math_fingerprint, clan_id, summary, description,
                             components_verbal, components_somatic, components_material,
                             status, validation_signatures_count, signatures_count, is_genesis_sample,
                             created_at, updated_at, validated_at)
         VALUES (:id, :slug, :name, :authorId, :school, \'fire\', \'action\',
                 :manaCost, :circle, :fingerprint, :clanId, :summary, \'\',
                 \'\', \'\', \'\',
                 :status, 3, 3, :isGenesis,
                 :now, :now, :validatedAt)'
    );
    $statement->execute([
        ':id'          => $spellId,
        ':slug'        => $slug,
        ':name'        => $name,
        ':authorId'    => $authorId,
        ':school'      => $school,
        ':manaCost'    => $manaCost,
        ':circle'      => $circle,
        ':fingerprint' => str_repeat('a', 64),
        ':clanId'      => $clanId,
        ':summary'     => 'Resumen de ' . $name . '.',
        ':status'      => $status,
        ':isGenesis'   => $isGenesis ? 1 : 0,
        ':now'         => utcStamp(),
        ':validatedAt' => $validatedAt,
    ]);
}

/** Entidad User del titular, tal y como la materializaría AuthMiddleware. */
function actor(PDO $pdo, string $userId): \Grimorio\Models\User
{
    $statement = $pdo->prepare(
        'SELECT id, alias, email, password_hash, role, clan_id, created_at, updated_at
           FROM users WHERE id = :userId'
    );
    $statement->execute([':userId' => $userId]);

    return \Grimorio\Models\User::fromDatabaseRow($statement->fetch(PDO::FETCH_ASSOC));
}

/** Claves del contrato del Endpoint 13 (plan 2.2). */
function contractKeys(): array
{
    return ['id', 'slug', 'name', 'magicSchool', 'magicSchoolLabel', 'circle', 'manaCost',
            'authorAlias', 'validatedAt', 'summary', 'isGenesisSample'];
}

echo "== ARNÉS — LEGADO ANCESTRAL DE UNA HERMANDAD (Tarea 6.4, SPEC-07) ==\n";

// =====================================================================
// FASE 0 · Fixtures sobre la base efímera del santuario
// =====================================================================
echo "\n[FASE 0] Fixtures: casas, adeptos y conjuros sellados\n";

$pdo = Connection::getInstance()->getPdo();
$router = buildRouter();

seedUser($pdo, 'usr_ember_pat', 'PatriarcaBrasa');
seedUser($pdo, 'usr_ember_adept', 'AdeptaCeniza');
seedUser($pdo, 'usr_tide_pat', 'PatriarcaMarea');

seedClan($pdo, 'cln_ember', 'Custodios de la Llama', 'active', 'primordialFlame', 'open', 'usr_ember_pat');
seedClan($pdo, 'cln_tide', 'Mareas de Aether', 'active', 'celestialTides', 'byApplication', 'usr_tide_pat');
seedClan($pdo, 'cln_relic', 'Ceniza Eterna', 'archived', 'primordialFlame', 'open', null);

seedMembership($pdo, 'clm_ember_pat', 'cln_ember', 'usr_ember_pat', 'patriarch');
seedMembership($pdo, 'clm_ember_adept', 'cln_ember', 'usr_ember_adept', 'adept');
seedMembership($pdo, 'clm_tide_pat', 'cln_tide', 'usr_tide_pat', 'patriarch');

// Legado de la casa de la brasa: dos obras ratificadas y dos que no lo están.
seedSpell($pdo, 'spl_ember_old', 'ascua-vigilante', 'Ascua Vigilante', 'usr_ember_adept', 'cln_ember', 'validated', 3, 42, 'evocation', '2026-09-01T00:00:00Z');
seedSpell($pdo, 'spl_ember_new', 'faro-de-brasa', 'Faro de Brasa', 'usr_ember_pat', 'cln_ember', 'validated', 5, 80, 'abjuration', '2026-09-10T00:00:00Z');
seedSpell($pdo, 'spl_ember_draft', 'brasa-secreta', 'Brasa Secreta', 'usr_ember_pat', 'cln_ember', 'draft', 2, 20, 'evocation', '2026-09-12T00:00:00Z');
seedSpell($pdo, 'spl_ember_exp', 'chispa-en-prueba', 'Chispa en Prueba', 'usr_ember_pat', 'cln_ember', 'experimental', 2, 20, 'evocation', '2026-09-12T00:00:00Z');

// Obra ajena y reliquia de una casa disuelta.
seedSpell($pdo, 'spl_tide_one', 'marea-serena', 'Marea Serena', 'usr_tide_pat', 'cln_tide', 'validated', 4, 60, 'conjuration', '2026-09-05T00:00:00Z');
seedSpell($pdo, 'spl_relic_one', 'ultima-ceniza', 'Última Ceniza', 'usr_ember_adept', 'cln_relic', 'validated', 1, 5, 'necromancy', '2026-08-01T00:00:00Z');

// Casa sin legado alguno.
seedClan($pdo, 'cln_silent', 'Vigías del Silencio', 'active', 'abyssalShadows', 'open', null);

assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM spells WHERE clan_id = 'cln_ember'")->fetchColumn() === 4,
    'El plano efímero hospeda las cuatro obras de la casa de la brasa (dos ratificadas)',
);

// =====================================================================
// FASE 1 · El contrato del Endpoint 13
// =====================================================================
echo "\n[FASE 1] El contrato del Endpoint 13 (RF-05.1, plan 2.2)\n";

$legacyResponse = dispatch('GET', '/api/v1/clans/cln_ember/spells');
$legacy = payloadOf($legacyResponse);
$legacyData = $legacy['data'] ?? [];

assertCondition(statusOf($legacyResponse) === 200, 'La consulta del legado responde 200 OK');
assertCondition(($legacy['success'] ?? false) === true, 'El sobre declara éxito');
assertCondition(isset($legacyData['clan'], $legacyData['spells'], $legacyData['count']), 'El sobre porta `clan`, `spells` y `count`');
assertCondition(($legacyData['clan']['id'] ?? '') === 'cln_ember', 'Identifica la casa cuyo legado se contempla');
assertCondition(($legacyData['clan']['name'] ?? '') === 'Custodios de la Llama', 'La casa viaja con su Nombre Canónico (Art. IV)');
assertCondition(($legacyData['count'] ?? -1) === count($legacyData['spells'] ?? []), '`count` concuerda con el número de piezas servidas');

$spells = $legacyData['spells'] ?? [];
assertCondition(count($spells) === 2, 'Solo las obras RATIFICADAS componen el legado (Círculo de vida del conjuro)');
assertCondition(
    array_map(static fn (array $spell): string => (string) $spell['id'], $spells) === ['spl_ember_new', 'spl_ember_old'],
    'El legado se ordena por ratificación descendente (la más reciente primero)',
);
assertCondition(
    array_keys($spells[0]) === contractKeys(),
    'Cada pieza porta EXACTAMENTE las claves del contrato, en su orden canónico',
);
assertCondition((int) $spells[0]['circle'] === 5 && (int) $spells[1]['circle'] === 3, 'El Círculo sellado en la forja viaja con la obra');
assertCondition((int) $spells[0]['manaCost'] === 80, 'El coste de maná se LEE del registro, jamás se recalcula (Art. II)');
assertCondition($spells[0]['magicSchoolLabel'] === 'Abjuración', 'La escuela viaja con su etiqueta ceremonial en castellano (Art. IV)');
assertCondition($spells[1]['magicSchoolLabel'] === 'Evocación', 'Cada escuela se rotula según el canon del santuario');
assertCondition($spells[0]['authorAlias'] === 'PatriarcaBrasa', 'La obra porta el crédito de su autor original (RF-05.1)');
assertCondition($spells[1]['authorAlias'] === 'AdeptaCeniza', 'Y el de la adepto que la concibió');
assertCondition($spells[0]['validatedAt'] === '2026-09-10T00:00:00Z', 'La marca de ratificación viaja intacta');

// El pergamino génesis pertenece al linaje fundacional neutro.
$primordialLegacy = payloadOf(dispatch('GET', '/api/v1/clans/cln_primordial/spells'));
$primordialSpells = $primordialLegacy['data']['spells'] ?? [];
assertCondition(
    count($primordialSpells) >= 3 && ($primordialSpells[0]['isGenesisSample'] ?? false) === true,
    'El linaje fundacional expone sus Pergaminos Primordiales como legado legítimo',
);
assertCondition(
    ($primordialSpells[0]['authorAlias'] ?? '') === 'El Custodio Primordial',
    'Los Pergaminos Primordiales conservan el crédito de su tutor fundacional',
);

// El DTO guarda el canon por sí mismo (Art. II).
$canonGuard = true;
try {
    new ClanLegacySpellDto(id: 'spl_x', slug: 's', name: 'N', magicSchool: 'evocation', magicSchoolLabel: 'Evocación', circle: 6, manaCost: 5);
    $canonGuard = false;
} catch (InvalidArgumentException) {
    $canonGuard = true;
}
assertCondition($canonGuard, 'Un Círculo fuera del canon 1-5 no puede forjar el DTO');

$manaGuard = true;
try {
    new ClanLegacySpellDto(id: 'spl_x', slug: 's', name: 'N', magicSchool: 'evocation', magicSchoolLabel: 'Evocación', circle: 1, manaCost: -1);
    $manaGuard = false;
} catch (InvalidArgumentException) {
    $manaGuard = true;
}
assertCondition($manaGuard, 'Un coste de maná negativo es rechazado en el DTO (Art. II)');

// =====================================================================
// FASE 2 · Inviolabilidad del patrimonio (RF-05.1)
// =====================================================================
echo "\n[FASE 2] El autor parte y su obra permanece (RF-05.1)\n";

$clanService = new ClanService($pdo, new AuditService($pdo));
$adeptBefore = actor($pdo, 'usr_ember_adept');
assertCondition($adeptBefore->getClanId() === 'cln_ember', 'La adepto milita en la casa de la brasa antes de partir');

$membershipBefore = payloadOf(dispatch('GET', '/api/v1/clans/cln_ember'))['data']['members'] ?? [];
assertCondition(count($membershipBefore) === 2, 'El censo de la casa cuenta dos adeptos antes de la partida');

// La renuncia se cursa por el SERVICIO REAL: el espejo y la autoridad mudan.
$clanService->leaveClan($adeptBefore, 'cln_ember');

$adeptAfter = actor($pdo, 'usr_ember_adept');
assertCondition($adeptAfter->getClanId() === null, 'Partida consumada: el espejo `users.clan_id` queda vacío (Tarea 2.5)');
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM clan_members WHERE user_id = 'usr_ember_adept' AND left_at IS NULL")->fetchColumn() === 0,
    'La autoridad `clan_members` cierra la membresía con su marca de salida',
);

$membershipAfter = payloadOf(dispatch('GET', '/api/v1/clans/cln_ember'))['data']['members'] ?? [];
assertCondition(count($membershipAfter) === 1, 'El censo de la casa pierde a la adepto que partió');

$legacyAfterDeparture = payloadOf(dispatch('GET', '/api/v1/clans/cln_ember/spells'));
$spellsAfter = $legacyAfterDeparture['data']['spells'] ?? [];
assertCondition(
    count($spellsAfter) === 2,
    'La partida NO resta una sola pieza al legado: el patrimonio es inviolable (RF-05.1)',
);
assertCondition(
    ($spellsAfter[1]['id'] ?? '') === 'spl_ember_old' && ($spellsAfter[1]['authorAlias'] ?? '') === 'AdeptaCeniza',
    'La obra de la autora ausente sigue en el legado con su crédito original («Forjado por Mago X»)',
);
assertCondition(
    ($legacyAfterDeparture['data']['count'] ?? 0) === 2,
    'El recuento del legado permanece intacto tras la salida',
);

// =====================================================================
// FASE 3 · Herencia Ancestral (RF-05.3)
// =====================================================================
echo "\n[FASE 3] La casa disuelta conserva su memoria (RF-05.3)\n";

$relicResponse = dispatch('GET', '/api/v1/clans/cln_relic/spells');
$relic = payloadOf($relicResponse);
$relicSpells = $relic['data']['spells'] ?? [];

assertCondition(statusOf($relicResponse) === 200, 'El legado de una casa disuelta se contempla sin vínculo ni bloqueo');
assertCondition(($relic['data']['clan']['status'] ?? '') === 'archived', 'El sobre declara el estado `archived` que la ficha rotulará como Herencia Ancestral');
assertCondition(
    count($relicSpells) === 1 && ($relicSpells[0]['name'] ?? '') === 'Última Ceniza',
    'Los conjuros ratificados de la casa disuelta se preservan perpetuamente en el Gran Tomo',
);

// =====================================================================
// FASE 4 · Fronteras del legado
// =====================================================================
echo "\n[FASE 4] Fronteras: lo no ratificado jamás, lo ajeno tampoco\n";

$emberIds = array_map(static fn (array $spell): string => (string) $spell['id'], $spellsAfter);
assertCondition(
    !in_array('spl_ember_draft', $emberIds, true) && !in_array('spl_ember_exp', $emberIds, true),
    'Ni borradores ni experimentales componen el legado (solo `validated`)',
);

// RF-05.2 · El borrador es libreta del autor, jamás patrimonio de una casa.
$draftRow = $pdo->query(
    "SELECT author_id, status FROM spells WHERE id = 'spl_ember_draft'"
)->fetch();
assertCondition(
    ($draftRow['author_id'] ?? '') === 'usr_ember_pat' && ($draftRow['status'] ?? '') === 'draft',
    'El borrador sigue en la libreta de su autor aunque este haya partido de la hermandad (RF-05.2)',
);
$tideIds = array_map(
    static fn (array $spell): string => (string) $spell['id'],
    payloadOf(dispatch('GET', '/api/v1/clans/cln_tide/spells'))['data']['spells'],
);
assertCondition(
    !in_array('spl_ember_draft', $tideIds, true),
    'El borrador tampoco engrosa el patrimonio de una casa ajena: solo se vinculará al publicarse (RF-05.2)',
);
assertCondition(
    !in_array('spl_tide_one', $emberIds, true),
    'Las obras de una casa ajena jamás se atribuyen a este estandarte',
);
assertCondition(
    array_map(static fn (array $spell): string => (string) $spell['id'], payloadOf(dispatch('GET', '/api/v1/clans/cln_tide/spells'))['data']['spells'])
        === ['spl_tide_one'],
    'Cada casa expone únicamente su propio patrimonio',
);

$missingResponse = dispatch('GET', '/api/v1/clans/cln_inexistente/spells');
assertCondition(statusOf($missingResponse) === 404, 'Una casa inexistente responde 404');
assertCondition(
    (payloadOf($missingResponse)['error']['code'] ?? '') === 'CLAN_NOT_FOUND',
    'El rechazo porta el código canónico CLAN_NOT_FOUND',
);

$silentLegacy = payloadOf(dispatch('GET', '/api/v1/clans/cln_silent/spells'));
assertCondition(
    ($silentLegacy['data']['spells'] ?? null) === [] && ($silentLegacy['data']['count'] ?? -1) === 0,
    'Una casa sin obras ratificadas devuelve un legado vacío, no un error',
);

// =====================================================================
// FASE 5 · Pureza y seguridad
// =====================================================================
echo "\n[FASE 5] Pureza, inyección y Dogma Vanilla\n";

// ANÓNIMO: el legado es de LECTURA PÚBLICA (RF-06.1: el Santuario se contempla).
assertCondition(
    statusOf(dispatch('GET', '/api/v1/clans/cln_ember/spells')) === 200,
    'Sin sesión alguna el legado sigue siendo contemplable (lectura pública)',
);

// INYECCIÓN: un identificador hostil jamás alcanza el motor como SQL.
$hostile = dispatch('GET', '/api/v1/clans/' . rawurlencode("cln_ember' OR '1'='1") . '/spells');
assertCondition(statusOf($hostile) === 404, 'Un identificador hostil responde 404 y JAMÁS un 500 (consultas preparadas)');

// PARIDAD con el router real de producción.
$frontController = (string) file_get_contents($projectRoot . '/public/index.php');
assertCondition(
    str_contains($frontController, "'/api/v1/clans/{id}/spells'"),
    'El front controller registra la ruta canónica del Endpoint 13',
);

// DOGMA VANILLA: los ficheros nuevos solo dependen de PHP nativo.
$dtoSource = (string) file_get_contents($projectRoot . '/src/Dto/ClanLegacySpellDto.php');
$serviceSource = (string) file_get_contents($projectRoot . '/src/Services/SpellDiscoveryService.php');
assertCondition(
    str_contains($dtoSource, 'declare(strict_types=1);') && str_contains($serviceSource, 'declare(strict_types=1);'),
    'Tipado estricto declarado en los ficheros tocados (AGENTS.md 8)',
);
assertCondition(
    !str_contains($dtoSource, 'use Grimorio\\Services') && !str_contains($dtoSource, 'use Grimorio\\Controllers'),
    'El DTO solo depende de la entidad del conjuro, jamás de servicios ni controladores',
);
assertCondition(
    !str_contains($dtoSource, 'composer') && !str_contains($dtoSource, 'vendor/'),
    'Cero dependencias npm/Composer (Dogma Vanilla)',
);
assertCondition(
    str_contains($serviceSource, 's.clan_id = :clanId') && !preg_match('/clan_id\s*=\s*\$/', $serviceSource),
    'La consulta del legado vincula el clan como parámetro preparado (AGENTS.md 6.1)',
);
assertCondition(
    str_contains($serviceSource, "AND s.status = :statusValidated"),
    'El estado ratificado también viaja como parámetro vinculado',
);

// El servicio responde a la entidad Spell: una sola fuente para las etiquetas.
$dtoFromSpell = ClanLegacySpellDto::fromSpell(
    Spell::fromDatabaseRow([
        'id' => 'spl_probe', 'slug' => 'probe', 'name' => 'Probe', 'magic_school' => 'evocation',
        'mana_cost' => 10, 'clan_id' => 'cln_ember', 'clan_name' => 'Custodios de la Llama',
        'summary' => 'Resumen', 'status' => 'validated', 'is_genesis_sample' => 0,
        'validated_at' => '2026-09-01T00:00:00Z', 'description' => '', 'components_verbal' => '',
        'components_somatic' => '', 'components_material' => '', 'validation_signatures_count' => 3,
    ]),
    2,
    'Probadora',
);
assertCondition(
    $dtoFromSpell->jsonSerialize()['magicSchoolLabel'] === 'Evocación'
        && $dtoFromSpell->jsonSerialize()['authorAlias'] === 'Probadora',
    'La factoría del DTO delega la etiqueta escolar en la entidad del conjuro (una sola verdad)',
);

// =====================================================================
// RESUMEN
// =====================================================================
echo "\n== RESUMEN ==\n";
echo "Asertos superados: {$assertsPassed}\n";
echo "Asertos fallidos:  {$assertsFailed}\n";

if ($assertsFailed > 0) {
    echo "\nRESULTADO: DENEGADO — El legado ancestral no cumple aún su criterio.\n";
    exit(1);
}

echo "\nRESULTADO: EXITO — El legado ancestral de las hermandades queda verificado.\n";
