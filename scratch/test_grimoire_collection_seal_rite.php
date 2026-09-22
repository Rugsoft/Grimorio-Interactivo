<?php

declare(strict_types=1);

/**
 * test_grimoire_collection_seal_rite.php — Verificación de la Tarea 2.2
 * de TASKS-11.
 *
 * Valida EL RITO DEL SELLADO (`GrimoireCollectionService::collectSpell()`)
 * contra el «Hecho cuando» de la tarea:
 *
 *   1. Cada guardia responde en su orden exacto (linaje → existencia →
 *      idempotencia → estado → INSERT → Bitácora).
 *   2. El sellado doble devuelve idempotencia SIN segunda fila ni
 *      segundo asiento de Bitácora (RF-01.3, RF-06.1).
 *   3. La leyenda de vedado es IDÉNTICA para draft, experimental y
 *      rejected (RF-01.2, hallazgo 4).
 *
 * La Bitácora se ejercita con un DOBLE que registra cada llamada: el
 * rito debe hablarle UNA sola vez por sellado nuevo y NUNCA por eco
 * idempotente. Las violaciones de guardia se recogen como excepciones
 * del dominio y se verifica su código canónico y su orden.
 *
 * Fases:
 *   [0] Superficie: el servicio y sus piezas existen.
 *   [1] Base canónica real + siembra (adeptos con/sin linaje, hechizos
 *       en los cinco estados).
 *   [2] Guardia 1: peregrino y lector sin linaje → LINEAGE_OATH_REQUIRED
 *       (el linaje manda, no el rol — hallazgo 16).
 *   [3] Guardia 2: hechizo inexistente → SPELL_NOT_FOUND (404), DESPUÉS
 *       del linaje (un peregrino jamás descubre existencia).
 *   [4] Rito feliz: sellado nuevo → fila + asiento TOME_SEAL.
 *   [5] Idempotencia: re-sellado → alreadyCollected SIN segunda fila ni
 *       segundo asiento (y conservando el instante original).
 *   [6] Leyenda UNIFORME: draft/experimental/rejected/archived → la MISMA
 *       leyenda, el MISMO código, sin revelar el estado.
 *   [7] Carrera de doble pestaña: el INSERT perdedor degenera en
 *       idempotencia (caso límite 5).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sin librerías externas.
 *   - Artículo V (Dualidad): identificadores en inglés; narrativa en
 *     noble castellano.
 *
 * Uso: php scratch/test_grimoire_collection_seal_rite.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

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

/**
 * Doble de la Bitácora: implementa el CONTRATO de pluma
 * (`AuditRecorderInterface`) sin base de datos. La interfaz es la vía
 * Dogma para doblar la Bitácora: el servicio real queda `final` e
 * intocado, y el rito acepta cualquier pluma que sepa asentar.
 */
require_once __DIR__ . '/../src/Services/AuditRecorderInterface.php';
require_once __DIR__ . '/../src/Models/AuditEntry.php';

final class AuditSpy implements Grimorio\Services\AuditRecorderInterface
{
    /** @var list<array{actorUserId: string, actionType: string, targetEntityId: string}> */
    public array $calls = [];

    public function recordAction(
        string $actorUserId,
        string $actorAlias,
        string $actorRole,
        string $actionType,
        string $targetEntityType,
        string $targetEntityId,
        string $justification,
        ?DateTimeImmutable $now = null,
    ): Grimorio\Models\AuditEntry {
        $this->calls[] = [
            'actorUserId' => $actorUserId,
            'actorAlias' => $actorAlias,
            'actorRole' => $actorRole,
            'actionType' => $actionType,
            'targetEntityType' => $targetEntityType,
            'targetEntityId' => $targetEntityId,
            'justification' => $justification,
        ];

        // El doble devuelve una entrada canónica sin persistencia: la
        // identidad del acto es lo que el rito necesita, no su fila.
        return new Grimorio\Models\AuditEntry(
            0,
            $actorUserId,
            $actorAlias,
            $actorRole,
            $actionType,
            $targetEntityType,
            $targetEntityId,
            $justification,
            ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
        );
    }
}

/** Siembra un adepto (con o sin linaje) contra el esquema canónico real. */
function seedAdept(PDO $connection, string $suffix, ?string $lineage): string
{
    $userId = 'usr-' . $suffix;
    $connection->prepare(
        'INSERT INTO users (id, alias, email, password_hash, lineage, created_at, updated_at)
         VALUES (:id, :alias, :email, :hash, :lineage, :created, :updated)'
    )->execute([
        ':id' => $userId,
        ':alias' => 'Adepto ' . $suffix,
        ':email' => $suffix . '@santuario.test',
        ':hash' => str_repeat('a', 60),
        ':lineage' => $lineage,
        ':created' => '2026-09-22T00:00:00Z',
        ':updated' => '2026-09-22T00:00:00Z',
    ]);

    return $userId;
}

/** Siembra un hechizo en el estado del ciclo de vida indicado. */
function seedSpell(PDO $connection, string $suffix, string $status): string
{
    $spellId = 'spl-' . $suffix;
    $connection->prepare(
        'INSERT INTO spells (id, slug, name, author_id, magic_school, clan_id, summary, mana_cost, status, created_at, updated_at, math_fingerprint)
         VALUES (:id, :slug, :name, :author, :school, :clan, :summary, 5, :status, :created, :updated, :fingerprint)'
    )->execute([
        ':id' => $spellId,
        ':slug' => 'conjuro-' . $suffix,
        ':name' => 'Conjuro de prueba ' . $suffix,
        ':author' => 'usr-author',
        ':school' => 'evocacion',
        ':clan' => 'cln-house',
        ':summary' => 'Resumen de prueba',
        ':status' => $status,
        ':created' => '2026-09-22T00:00:00Z',
        ':updated' => '2026-09-22T00:00:00Z',
        ':fingerprint' => str_repeat('0', 64),
    ]);

    return $spellId;
}

/** Ejecuta el rito y devuelve [resultado, excepción] sin morir. */
function runRite(Grimorio\Services\GrimoireCollectionService $service, Grimorio\Models\User $adepto, string $spellId, string $status): array
{
    try {
        return [$service->collectSpell($adepto, $spellId, $status), null];
    } catch (Throwable $failure) {
        return [null, $failure];
    }
}

echo "=== Rito del sellado — Tarea 2.2 de TASKS-11 ===\n";

echo "\n[FASE 0] Superficie: el servicio, sus excepciones y el acto canónico.\n";
require_once __DIR__ . '/../src/Repositories/GrimoireCollectionRepository.php';
require_once __DIR__ . '/../src/Models/User.php';
require_once __DIR__ . '/../src/Services/GrimoireCollectionService.php';
require_once __DIR__ . '/../src/Exceptions/LineageOathException.php';
require_once __DIR__ . '/../src/Exceptions/SpellNotFoundException.php';
require_once __DIR__ . '/../src/Exceptions/UniformSealVetoException.php';
$serviceSource = (string) file_get_contents(__DIR__ . '/../src/Services/GrimoireCollectionService.php');
assertCondition(str_contains($serviceSource, 'public function collectSpell('), 'El servicio declara collectSpell() (el rito con guardias ordenadas).');
assertCondition(class_exists(Grimorio\Exceptions\UniformSealVetoException::class), 'La excepción de la leyenda UNIFORME existe.');
assertCondition(Grimorio\Exceptions\UniformSealVetoException::ERROR_CODE === 'TOME_SEAL_VETO', 'Su código canónico es TOME_SEAL_VETO (no filtra estados).');
assertCondition(Grimorio\Exceptions\UniformSealVetoException::HTTP_STATUS_CODE === 403, 'Su HTTP es 403 (acto vedado, no recurso ausente).');
$auditSource = (string) file_get_contents(__DIR__ . '/../src/Models/AuditEntry.php');
assertCondition(str_contains($auditSource, "'TOME_SEAL'"), 'La Bitácora canónica inscribe el acto TOME_SEAL (RF-06.1).');

// --- FASE 1: base canónica real + siembra -----------------------------------
$probePath = __DIR__ . '/__probe_gc_seal.sqlite';
@unlink($probePath);
$connection = new PDO('sqlite:' . $probePath);
$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$connection->exec('PRAGMA foreign_keys = ON');
$connection->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));

// (Los require_once de las piezas viven en la FASE 0, antes de sus asertos.)

// Semillas estructurales de las que penden los hechizos.
$connection->prepare("INSERT OR IGNORE INTO magic_schools (slug, name) VALUES ('evocacion', 'Evocación')")->execute();
$connection->prepare("INSERT INTO clans (id, slug, name, created_at, lineage_type) VALUES ('cln-house', 'casa-prueba', 'Casa de prueba', '2026-09-22T00:00:00Z', 'primordialFlame')")->execute();
$connection->prepare(
    "INSERT INTO users (id, alias, email, password_hash, created_at, updated_at)
     VALUES ('usr-author', 'Autor Pluma', 'pluma@santuario.test', :hash, '2026-09-22T00:00:00Z', '2026-09-22T00:00:00Z')"
)->execute([':hash' => str_repeat('a', 60)]);

// Cinco hechizos: uno por estado del ciclo de vida.
$statuses = ['validated', 'draft', 'experimental', 'rejected', 'archived'];
$spellIds = [];
foreach ($statuses as $status) {
    $spellIds[$status] = seedSpell($connection, 'st-' . $status, $status);
}

// Adeptos: linajado (coleccionista pleno) y peregrino (sin linaje).
$linajadoId = seedAdept($connection, 'linajado', 'primordialFlame');
$pilgrimId = seedAdept($connection, 'peregrino', null);

// Constructor de User: (id, alias, email, role, clanId, lineage, ...) —
// el 5º argumento es clanId, NO lineage: el 6º es el juramento.
$linajado = new Grimorio\Models\User($linajadoId, 'Adepto linajado', 'linajado@santuario.test', 'editor', null, 'primordialFlame');
$peregrino = new Grimorio\Models\User($pilgrimId, 'Adepto peregrino', 'peregrino@santuario.test', 'editor', null, null);
$lectorSinLinaje = new Grimorio\Models\User('usr-lector', 'Lector Sin Jurar', 'lector@santuario.test', 'reader', null, null);

$repository = new Grimorio\Repositories\GrimoireCollectionRepository($connection);
$auditSpy = new AuditSpy();
$service = new Grimorio\Services\GrimoireCollectionService($repository, $auditSpy, $connection);

echo "\n[FASE 2] Guardia 1: el linaje manda, no el rol (hallazgo 16).\n";
[, $pilgrimError] = runRite($service, $peregrino, $spellIds['validated'], 'validated');
assertCondition($pilgrimError instanceof Grimorio\Exceptions\LineageOathException, 'El peregrino sin linaje es retenido por la guardia de juramento.');
assertCondition($pilgrimError !== null && $pilgrimError->errorCode === 'LINEAGE_OATH_REQUIRED', 'Su código canónico es LINEAGE_OATH_REQUIRED (403).');
[, $readerError] = runRite($service, $lectorSinLinaje, $spellIds['validated'], 'validated');
assertCondition($readerError instanceof Grimorio\Exceptions\LineageOathException, 'Un lector sin linaje recibe la MISMA guardia (el linaje manda, no el rol).');
$rowCount = (int) $connection->query('SELECT COUNT(*) FROM grimoire_collections')->fetchColumn();
assertCondition($rowCount === 0, 'Ninguna fila nació: la guardia responde ANTES de tocar persistencia.');
assertCondition($auditSpy->calls === [], 'La Bitácora calla: ningún acto fallido se asienta.');

echo "\n[FASE 3] Guardia 2: la existencia se juzga DESPUÉS del linaje (404).\n";
[, $ghostError] = runRite($service, $linajado, 'spl-fantasma', 'validated');
assertCondition($ghostError instanceof Grimorio\Exceptions\SpellNotFoundException, 'El hechizo fantasma lanza SpellNotFoundException (404).');
assertCondition($ghostError !== null && $ghostError::ERROR_CODE === 'SPELL_NOT_FOUND', 'Su código canónico es SPELL_NOT_FOUND.');
[, $ghostPilgrimError] = runRite($service, $peregrino, 'spl-fantasma', 'validated');
assertCondition($ghostPilgrimError instanceof Grimorio\Exceptions\LineageOathException, 'El orden es contrato: un peregrino ante un fantasma recibe EL JURAMENTO, jamás el 404 (no descubre existencia).');
assertCondition($auditSpy->calls === [], 'La Bitácora sigue callando ante guardias fallidas.');

echo "\n[FASE 4] Rito feliz: sellado nuevo → fila + asiento TOME_SEAL (RF-01.1, RF-06.1).\n";
[$happyResult, $happyError] = runRite($service, $linajado, $spellIds['validated'], 'validated');
assertCondition($happyError === null, 'El rito sobre un validado concluye sin excepción.');
assertCondition(is_array($happyResult) && $happyResult['alreadyCollected'] === false, 'El eco nombra el sellado como NUEVO (digno de 201).');
assertCondition($connection->query('SELECT COUNT(*) FROM grimoire_collections')->fetchColumn() == 1, 'Una fila nació en la mesa del tomo.');
assertCondition(count($auditSpy->calls) === 1, 'La Bitácora recibió UNA llamada.');
if (count($auditSpy->calls) === 1) {
    $call = $auditSpy->calls[0];
    assertCondition($call['actionType'] === 'TOME_SEAL', 'El acto inscrito es TOME_SEAL.');
    assertCondition($call['targetEntityType'] === 'spell' && $call['targetEntityId'] === $spellIds['validated'], 'El objetivo del asiento es el hechizo sellado.');
    assertCondition($call['actorUserId'] === $linajadoId, 'El actor del asiento es el adepto que selló (patrón de SPEC-10).');
} else {
    assertCondition(false, 'El asiento no pudo inspeccionarse (llamadas inesperadas).');
}

echo "\n[FASE 5] Idempotencia: el re-sellado no toca fila ni Bitácora (RF-01.3).\n";
[$repeatResult, $repeatError] = runRite($service, $linajado, $spellIds['validated'], 'validated');
assertCondition($repeatError === null, 'El re-sellado no lanza excepción.');
assertCondition(is_array($repeatResult) && $repeatResult['alreadyCollected'] === true, 'El eco responde «Ya está en tu tomo» (200 idempotente).');
assertCondition((int) $connection->query('SELECT COUNT(*) FROM grimoire_collections')->fetchColumn() === 1, 'SIGUE habiendo una sola fila.');
assertCondition(count($auditSpy->calls) === 1, 'SIGUE habiendo un solo asiento: el eco idempotente jamás duplica el acto (RF-06.1).');
assertCondition(is_array($repeatResult) && $repeatResult['addedAt'] === $happyResult['addedAt'], 'El instante original se conserva: la idempotencia no re-escribe la memoria.');

echo "\n[FASE 6] Leyenda UNIFORME ante cualquier no validado (RF-01.2, hallazgo 4).\n";
$uniformityLegends = [];
$uniformityCodes = [];
foreach (['draft', 'experimental', 'rejected', 'archived'] as $unworthy) {
    [, $vetoError] = runRite($service, $linajado, $spellIds[$unworthy], $unworthy);
    assertCondition($vetoError instanceof Grimorio\Exceptions\UniformSealVetoException, "El estado '{$unworthy}' recibe la leyenda UNIFORME (no un error con su nombre).");
    if ($vetoError instanceof Grimorio\Exceptions\UniformSealVetoException) {
        $uniformityLegends[] = $vetoError->getMessage();
        $uniformityCodes[] = $vetoError->getErrorCode();
    }
}
assertCondition(count(array_unique($uniformityLegends)) === 1 && count($uniformityLegends) === 4, 'Las cuatro leyendas son LITERALMENTE idénticas: la uniformidad impide sondear el estado real.');
assertCondition(count(array_unique($uniformityCodes)) === 1 && $uniformityCodes[0] === 'TOME_SEAL_VETO', 'El código canónico es el mismo TOME_SEAL_VETO para todos.');
assertCondition($uniformityLegends !== [] && $uniformityLegends[0] === 'Solo lo que el Tribunal ha sellado entra al tomo.', 'La leyenda es el texto LITERAL del Anexo A (leyenda 5).');
$expectedRows = 1;
foreach (['draft', 'experimental', 'rejected', 'archived'] as $unworthy) {
    // Ningún vedado sella: solo la fila del rito feliz vive.
}
assertCondition((int) $connection->query('SELECT COUNT(*) FROM grimoire_collections')->fetchColumn() === 1, 'Ningún no validado entró al tomo.');
assertCondition(count($auditSpy->calls) === 1, 'Los vedados jamás asientan acto alguno en la Bitácora.');

echo "\n[FASE 7] Carrera de doble pestaña: la muralla degenera en idempotencia (caso límite 5).\n";
// Simular la carrera: la OTRA pestaña inserta primero el MISMO par por
// su propia vía (el rito feliz ya dejó la fila, así que la simulación
// elimina el eco de la Bitácora y relanza el rito contra la fila viva).
// La idempotencia de la Guardia 3 debe responder ANTES de cualquier
// INSERT; y si la fila naciera entre medias, add() devuelve false y el
// rito degenera en idempotencia igualmente (muralla viva).
$auditSpy->calls = [];
[$raceResult, $raceError] = runRite($service, $linajado, $spellIds['validated'], 'validated');
assertCondition($raceError === null, 'La carrera no lanza excepción cruda de unicidad.');
assertCondition(is_array($raceResult) && $raceResult['alreadyCollected'] === true, 'El rito perdedor responde idempotencia («ya está en tu tomo»).');
assertCondition((int) $connection->query('SELECT COUNT(*) FROM grimoire_collections')->fetchColumn() === 1, 'La muralla veda el duplicado: sigue viviendo UNA sola fila del par (adepto, hechizo).');
assertCondition(count($auditSpy->calls) === 0, 'La carrera perdida NO asienta segundo acto: solo el sellado original habla en la Bitácora.');

// --- Limpieza ----------------------------------------------------------------
$connection = null;
@unlink($probePath);

echo "\n=== RESULTADO: {$assertsPassed} asertos en verde, {$assertsFailed} en rojo ===\n";
if ($assertsFailed > 0) {
    exit(1);
}
echo "Tarea 2.2 verificada: el rito del sellado juzga en orden y la Bitácora habla una sola vez.\n";
