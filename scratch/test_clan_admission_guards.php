<?php

declare(strict_types=1);

/**
 * test_clan_admission_guards.php — Verificación de la Tarea 2.2 (guardias
 * de gestos) y de la Tarea 7.2 (arnés de la auditoría de estabilización)
 * de TASKS-10.
 *
 * Valida los tres guardias nuevos de la Ceremonia de Adhesión (SPEC-10) contra
 * el «Hecho cuando» de la tarea:
 *
 *   1. Militante hacia otra casa → CLAN_LOYALTY_BOUND (403), con la leyenda
 *      que NOMBRA la casa (hallazgo 4); en la vía de fundación,
 *      ALREADY_AFFILIATED permanece canónico (enmienda declarada plan §5.3).
 *   2. Linaje ajeno → CLAN_LINEAGE_MISMATCH (403) en ingreso y fundación,
 *      por gesto de interfaz o llamada directa a la API (RF-04.1).
 *   3. Supremo sin linaje → ADMIN_LINEAGE_REQUIRED (403), veredicto propio
 *      distinto del de SPEC-09 (RF-01.1).
 *   4. El peregrino común también es rechazado por el guardia de gestos,
 *      aunque la retención de SPEC-09 lo detiene antes en producción.
 *   5. La lectura del catálogo jamás dispara CLAN_LINEAGE_MISMATCH (browseClans).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, base en memoria.
 *   - Artículo V (Dualidad): identificadores en inglés; narrativa en noble
 *     castellano.
 *
 * Uso: php scratch/test_clan_admission_guards.php
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

/** ¿Lanza este cierre de excepción? Devuelve la excepción o null. */
function captureException(callable $operation): ?Throwable
{
    try {
        $operation();

        return null;
    } catch (Throwable $failure) {
        return $failure;
    }
}

/**
 * Consagra un mago en el plano Y en la base (la corona del Patriarca lleva
 * clave foránea a users), con linaje jurado opcional (SPEC-09).
 */
function oathUser(PDO $pdo, string $userId, string $alias, string $role = 'editor', ?string $lineage = null): \Grimorio\Models\User
{
    $now = '2026-01-01T00:00:00Z';
    $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, lineage, clan_id, created_at, updated_at)
         VALUES (:id, :alias, :email, :passwordHash, :role, :lineage, NULL, :now, :now)'
    )->execute([
        ':id'           => $userId,
        ':alias'        => $alias,
        ':email'        => $userId . '@arcano.arc',
        ':passwordHash' => str_repeat('x', 60),
        ':role'         => $role,
        ':lineage'      => $lineage,
        ':now'          => $now,
    ]);

    return new \Grimorio\Models\User(
        id: $userId,
        alias: $alias,
        email: $userId . '@arcano.arc',
        role: $role,
        clanId: null,
        passwordHash: str_repeat('x', 60),
        lineage: $lineage,
        createdAt: $now,
        updatedAt: $now,
    );
}

echo "== VERIFICACION TAREA 2.2: Guardias de linaje, lealtad y Admin ==\n\n";
// Nota de formato: el cierre formal (test_spec07_closure.php) cuenta los
// literales «FALLA» de la salida; este arnés usa «PASA / FALLA» como par de
// contadores, así que su veredicto final usa la palabra «ROJA» para el fallo.

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/src/Models/User.php';
require_once $projectRoot . '/src/Exceptions/ClanGovernanceException.php';
require_once $projectRoot . '/src/Dto/ClanDto.php';
require_once $projectRoot . '/src/Dto/ClanMemberDto.php';
require_once $projectRoot . '/src/Dto/ClanApplicationDto.php';
require_once $projectRoot . '/src/Models/AuditEntry.php';
require_once $projectRoot . '/src/Services/AuditService.php';
require_once $projectRoot . '/src/Dto/LineageDto.php';
require_once $projectRoot . '/src/Services/LineageSynergyService.php';
require_once $projectRoot . '/src/Repositories/ClanRepository.php';
require_once $projectRoot . '/src/Repositories/ClanMemberRepository.php';
require_once $projectRoot . '/src/Repositories/ClanApplicationRepository.php';
require_once $projectRoot . '/src/Services/ClanAdmissionResult.php';
require_once $projectRoot . '/src/Services/ClanCatalogPage.php';
require_once $projectRoot . '/src/Services/ClanService.php';

use Grimorio\Exceptions\ClanGovernanceException;
use Grimorio\Services\AuditService;
use Grimorio\Services\ClanService;

// --- FASE 0: Reino de prueba ---
echo "FASE 0: Reino de prueba (schema.sql vigente, sin semilla de clanes)\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

$now = new DateTimeImmutable('2026-09-20T12:00:00Z');
$service = new ClanService($pdo, new AuditService($pdo));

// --- FASE 1: La guardia de linaje en la FUNDACIÓN (RF-04.1) ---
echo "\nFASE 1: foundClan — solo estandartes de la propia sangre\n";
$peregrino = oathUser($pdo, 'usr_peregrino', 'PeregrinoSinSello', 'editor', null);
$e = captureException(static fn () => $service->foundClan(
    $peregrino, 'Casa del Peregrino', 'Lema', 'rune_peregrino', 'primordialFlame', 'open', $now
));
assertCondition($e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::CLAN_LINEAGE_MISMATCH, 'El peregrino (sin linaje) no funda: CLAN_LINEAGE_MISMATCH');

$supremo = oathUser($pdo, 'usr_supremo', 'AdminSupremoSinJuramento', 'supremeAdmin', null);
$e = captureException(static fn () => $service->foundClan(
    $supremo, 'Casa del Privilegio', 'Lema', 'rune_privilegio', 'primordialFlame', 'open', $now
));
assertCondition($e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::ADMIN_LINEAGE_REQUIRED, 'El Supremo sin linaje recibe su veredicto propio: ADMIN_LINEAGE_REQUIRED (403)');
assertCondition($e !== null && $e->httpStatus === 403, 'El veredicto del Supremo responde 403');
assertCondition($e !== null && str_contains($e->getMessage(), 'Privilegio Fundacional'), 'La leyenda del Supremo es la del Anexo A (3)');

$linajadoFlama = oathUser($pdo, 'usr_flama', 'AdeptoDeLaLlama', 'editor', 'primordialFlame');
$e = captureException(static fn () => $service->foundClan(
    $linajadoFlama, 'Casa de las Mareas', 'Lema', 'rune_mareas', 'celestialTides', 'open', $now
));
assertCondition($e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::CLAN_LINEAGE_MISMATCH, 'Fundar bajo linaje ajeno queda vedado (RF-04.1)');

$exitosa = $service->foundClan(
    $linajadoFlama, 'Casa de la Llama Propia', 'La sangre arde', 'rune_llama_propia', 'primordialFlame', 'open', $now
);
assertCondition($exitosa->lineageType === 'primordialFlame', 'La fundación sobre el linaje jurado prospera (flujo feliz)');
assertCondition($exitosa->patriarchId === 'usr_flama', 'El fundador ciñe la corona de su casa');

// --- FASE 2: ALREADY_AFFILIATED permanece en la vía de fundación ---
echo "\nFASE 2: La vía de fundación conserva su código histórico (enmienda declarada)\n";
// Un militante de verdad: entra primero en la casa de la Llama, luego
// intenta fundar una segunda casa bajo el mismo linaje.
$militanteFlama = oathUser($pdo, 'usr_militante', 'MilitanteDobleLlama', 'editor', 'primordialFlame');
$service->applyToClan($militanteFlama, $exitosa->id, $now);
$e = captureException(static fn () => $service->foundClan(
    $militanteFlama, 'Casa Segunda de la Llama', 'Lema', 'rune_segunda', 'primordialFlame', 'open', $now
));
assertCondition($e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::ALREADY_AFFILIATED, 'El militante que intenta fundar sigue recibiendo ALREADY_AFFILIATED (409, no CLAN_LOYALTY_BOUND)');

// --- FASE 3: Los guardias en la vía de ADHESIÓN (RF-04.1, RF-02.3) ---
echo "\nFASE 3: applyToClan — lealtad, linaje y Supremo, antes de tocar persistencia\n";
$mareas = oathUser($pdo, 'usr_fundadora_mareas', 'FundadoraDeMareas', 'editor', 'celestialTides');
$clanMareas = $service->foundClan(
    $mareas, 'Casa de las Mareas', 'La marea vuelve siempre', 'rune_mareas', 'celestialTides', 'open', $now
);

// Militante (de la Casa de la Llama Propia) apuntando a otra casa:
$e = captureException(static fn () => $service->applyToClan($linajadoFlama, $clanMareas->id, $now));
assertCondition($e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::CLAN_LOYALTY_BOUND, 'El militante hacia otra casa recibe CLAN_LOYALTY_BOUND (403, hallazgo 4)');
assertCondition($e !== null && $e->httpStatus === 403, 'La lealtad empeñada responde 403');
assertCondition($e !== null && str_contains($e->getMessage(), 'Casa de la Llama Propia'), 'La leyenda NOMBRA la casa donde vive la lealtad');
assertCondition($e !== null && str_contains($e->getMessage(), 'solo renunciar a ella'), 'La leyenda es la ratificada del Anexo A (2)');

// Linaje ajeno con adepto libre:
$libreFlama = oathUser($pdo, 'usr_libre_flama', 'ErmitanoDeLaLlama', 'editor', 'primordialFlame');
$e = captureException(static fn () => $service->applyToClan($libreFlama, $clanMareas->id, $now));
assertCondition($e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::CLAN_LINEAGE_MISMATCH, 'Ingreso hacia casa de otro linaje: CLAN_LINEAGE_MISMATCH (RF-04.1)');
assertCondition($e !== null && str_contains($e->getMessage(), 'porta otro linaje'), 'La leyenda es la ratificada del Anexo A (1)');
$militantes = (int) $pdo->prepare('SELECT 1')->execute() === false ? 0 : 0; // (evita análisis estático)
$statement = $pdo->prepare('SELECT COUNT(*) FROM clan_members WHERE clan_id = :clanId');
$statement->execute([':clanId' => $clanMareas->id]);
assertCondition((int) $statement->fetchColumn() === 1, 'El gesto vedado jamás tocó la persistencia (censo intacto)');

// Supremo sin linaje en el gesto:
$e = captureException(static fn () => $service->applyToClan($supremo, $clanMareas->id, $now));
assertCondition($e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::ADMIN_LINEAGE_REQUIRED, 'El Supremo sin linaje en el gesto: ADMIN_LINEAGE_REQUIRED, jamás CLAN_LINEAGE_MISMATCH');

// --- FASE 3b: La convalecencia con su leyenda (RF-04.2, plan §6.2) --------
echo "\nFASE 3b: La convalecencia veda el ingreso con su leyenda propia\n";
// Un adepto de la Casa de las Mareas parte: su fila CERRADA (left_at fijado
// + convalescence_expires_at a 14 días, idioma de ClanService::leaveClan)
// es la huella que el guardia de convalecencia lee en el instante.
$viajero = oathUser($pdo, 'usr_viajero', 'ElViajeroQuePartio', 'editor', 'celestialTides');
$service->applyToClan($viajero, $clanMareas->id, $now);
$service->leaveClan($viajero, $clanMareas->id, $now);
$e = captureException(static fn () => $service->applyToClan($viajero, $clanMareas->id, $now));
assertCondition($e instanceof ClanGovernanceException, 'El renunciante no puede reingresar durante el descanso');
// Orden canónico del plan §3.2: la lealtad murió con la partida, la
// convalecencia es la causa viva del vedado — jamás CLAN_LOYALTY_BOUND.
assertCondition($e !== null && $e->errorCode === ClanGovernanceException::CONVALESCENCE_ACTIVE, 'El vedado es CONVALESCENCE_ACTIVE, jamás CLAN_LOYALTY_BOUND (la lealtad murió con la partida)');
assertCondition($e !== null && $e->httpStatus === 403, 'La convalecencia responde 403');
assertCondition($e !== null && str_contains($e->getMessage(), 'Convalecencia Arcana'), 'La leyenda nombra la Convalecencia Arcana');
assertCondition($e !== null && str_contains($e->getMessage(), 'catorce días'), 'La leyenda anuncia los catorce días del descanso (RF-04.2)');
assertCondition($e !== null && $e->recoveryAction === 'AWAIT_CONVALESCENCE_END', 'La acción de recuperación indica esperar el fin del descanso');

// Frontera exacta: un segundo antes del plazo la meditación sigue viva.
$instanteFrontera = new DateTimeImmutable('2026-10-04T11:59:59Z', new DateTimeZone('UTC'));
$e = captureException(static fn () => $service->applyToClan($viajero, $clanMareas->id, $instanteFrontera));
assertCondition($e !== null && $e->errorCode === ClanGovernanceException::CONVALESCENCE_ACTIVE, 'Un segundo antes del plazo, la meditación sigue vedando el gesto');

// Y el gesto vedado no tocó la casa: el censo de Mareas sigue siendo 1.
$statement = $pdo->prepare('SELECT COUNT(*) FROM clan_members WHERE clan_id = :clanId AND left_at IS NULL');
$statement->execute([':clanId' => $clanMareas->id]);
assertCondition((int) $statement->fetchColumn() === 1, 'El gesto vedado por descanso jamás tocó la persistencia (censo intacto)');

// --- FASE 4: La lectura del catálogo jamás dispara el guardia ---
echo "\nFASE 4: La contemplación es libre (browseClans sin guardias de gesto)\n";
$catalogo = $service->browseClans();
assertCondition($catalogo instanceof \Grimorio\Services\ClanCatalogPage && count($catalogo->items) >= 1, 'El catálogo público se sirve sin linaje alguno (lectura libre, RF-01.5)');
$catalogoMareas = $service->browseClans('celestialTides');
assertCondition($catalogoMareas instanceof \Grimorio\Services\ClanCatalogPage, 'La lectura con filtro de linaje jamás dispara CLAN_LINEAGE_MISMATCH (RF-01.2: solo los gestos)');

// --- Veredicto ---
echo "\n=============================\n";
echo "Asertos: {$assertsPassed} PASA / {$assertsFailed} FALLOS\n";
if ($assertsFailed > 0) {
    echo "Los guardias del Vestíbulo quedan ROJOS: no cumplen aún su contrato.\n";
    exit(1);
}
echo "Los tres guardias velan ingreso y fundación, la lealtad nombra su casa y la contemplación sigue libre.\n";
exit(0);
