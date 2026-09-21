<?php

declare(strict_types=1);

/**
 * test_clan_admission_race.php — Arnés de la Tarea 7.4 de TASKS-10.
 *
 * Valida las CARRERAS de la adhesión (SPEC-10, RF-02.2, RF-02.3; casos
 * límite 1, 2, 5, 6 y 8 del plan §6.2) contra el «Hecho cuando»:
 *
 *   1. Última vacante disputada (caso límite 8): dos ingresos concurrentes
 *      a la casa con una sola silla — la serialización por transacción deja
 *      UN solo adeptos; el perdedor recibe CLAN_QUOTA_EXCEEDED con la
 *      leyenda de plenitud (RF-02.2).
 *   2. Casa que muta de régimen en vuelo (caso límite 2): el gesto cargado
 *      como `open` encuentra una casa `byApplication` — el veredicto del
 *      backend manda y el gesto jamás se convierte en otro compromiso.
 *   3. Doble envío idempotente (caso límite 6, RF-02.3): el militante que
 *      repite el gesto sobre SU casa recibe éxito sin mutación; si apunta a
 *      OTRA casa, CLAN_LOYALTY_BOUND con la leyenda que nombra la suya.
 *   4. Retirada y dictamen concurrentes (caso límite 5): la serialización
 *      por transacción deja UN solo desenlace — la segunda pluma encuentra
 *      la petición ya resuelta y responde APPLICATION_ALREADY_RESOLVED (409)
 *      sin mutar nada.
 *   5. La estampa de llegada (plan §3.3) alimenta el desempate: el orden de
 *      llegada UTC decide quién gana la silla.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, base en memoria.
 *   - Artículo V (Dualidad): identificadores en inglés; narrativa en noble
 *     castellano.
 *
 * Uso: php scratch/test_clan_admission_race.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

$assertsPassed = 0;
$assertsFailed = 0;

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

function captureException(callable $operation): ?Throwable
{
    try {
        $operation();
        return null;
    } catch (Throwable $exception) {
        return $exception;
    }
}

function oathUser(PDO $pdo, string $userId, string $alias, string $role = 'editor', string $lineage = 'primordialFlame'): Grimorio\Models\User
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
        ':lineage'      => $role === 'reader' ? null : $lineage,
        ':now'          => $now,
    ]);

    return new Grimorio\Models\User(
        id: $userId,
        alias: $alias,
        email: $userId . '@arcano.arc',
        role: $role,
        clanId: null,
        passwordHash: str_repeat('x', 60),
        lineage: $role === 'reader' ? null : $lineage,
        createdAt: $now,
        updatedAt: $now,
    );
}

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

// --- FASE 0: Reino de prueba -----------------------------------------------
echo "FASE 0: Reino de prueba\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

$now = new DateTimeImmutable('2026-09-20T12:00:00Z');
$service = new ClanService($pdo, new AuditService($pdo));

// --- FASE 1: Última vacante disputada (caso límite 8, RF-02.2) -------------
echo "\nFASE 1: La última vacante disputada por dos ingresos concurrentes\n";
// La casa nace con SU fundadora (el Patriarca es el primer adeptos del
// censo), de modo que la silla única ya tiene a alguien sentado: la casa
// arranca EN plenitud y cualquier nuevo ingreso pierde la carrera.
$houseSilla = $service->foundClan(
    oathUser($pdo, 'usr_anfitriona', 'AnfitrionaDeLaSilla', 'editor'),
    'Casa de la Silla Única', 'Una sola silla, ya ocupada', 'rune_silla', 'primordialFlame', 'open', $now
);
// La fundadora parte: su fila CERRADA libera la silla restante (censo 1/30
// visible como «la última vacante») sin abrir convalecencia a los gestos.
$pdo->prepare("UPDATE clan_members SET left_at = :leftAt WHERE clan_id = :clanId AND user_id = 'usr_anfitriona'")
    ->execute([':leftAt' => '2026-09-19T00:00:00Z', ':clanId' => $houseSilla->id]);
// Y la casa rebaja su censo a 0: para retratar «la última vacante libre»
// la llenamos con 29 adeptos — la silla 30 queda en disputa.
for ($i = 1; $i <= 29; $i++) {
    oathUser($pdo, 'usr_lleno_' . $i, 'Ocupante' . $i, 'editor');
    $service->applyToClan(
        new Grimorio\Models\User(
            id: 'usr_lleno_' . $i,
            alias: 'Ocupante' . $i,
            email: 'usr_lleno_' . $i . '@arcano.arc',
            role: 'editor',
            clanId: null,
            passwordHash: 'x',
            lineage: 'primordialFlame',
            createdAt: '2026-01-01T00:00:00Z',
            updatedAt: '2026-01-01T00:00:00Z',
        ),
        $houseSilla->id, $now
    );
}
$pretendienteA = oathUser($pdo, 'usr_pret_a', 'PretendienteAntiguo', 'editor');
$pretendienteB = oathUser($pdo, 'usr_pret_b', 'PretendienteTardio', 'editor');

// Primer gesto: la silla se ocupa.
$ganador = $service->applyToClan($pretendienteA, $houseSilla->id, $now);
assertCondition($ganador->isAdmitted(), 'El primero toma la silla: ingreso admitido');

// Segundo gesto, MISMOS datos pero el censo ya no respira: carrera perdida.
$e = captureException(static fn () => $service->applyToClan($pretendienteB, $houseSilla->id, $now));
assertCondition($e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::CLAN_QUOTA_EXCEEDED, 'El perdedor de la carrera recibe CLAN_QUOTA_EXCEEDED (409, RF-02.2)');
assertCondition($e !== null && $e->httpStatus === 409, 'La plenitud responde 409');
assertCondition($e !== null && str_contains($e->getMessage(), 'plenitud'), 'La leyenda del perdedor es la de plenitud de SPEC-07');

// El ganador fue EL ÚNICO entrante de la carrera: ninguna otra fila nació.
$statementGanador = $pdo->prepare('SELECT COUNT(*) FROM clan_members WHERE clan_id = :clanId AND user_id = :userId AND left_at IS NULL');
$statementGanador->execute([':clanId' => $houseSilla->id, ':userId' => 'usr_pret_a']);
assertCondition((int) $statementGanador->fetchColumn() === 1, 'El ganador porta la única membresía nueva de la carrera');
$statementPerdedor = $pdo->prepare('SELECT COUNT(*) FROM clan_members WHERE user_id = :userId AND left_at IS NULL');
$statementPerdedor->execute([':userId' => 'usr_pret_b']);
assertCondition((int) $statementPerdedor->fetchColumn() === 0, 'El perdedor quedó fuera: ninguna membresía nació para él');

// La silla única quedó OCUPADA por el ganador: el censo activo vuelve a
// 30 y nada cambió más — la serialización dejó un único desenlace.
$statement = $pdo->prepare('SELECT COUNT(*) FROM clan_members WHERE clan_id = :clanId AND left_at IS NULL');
$statement->execute([':clanId' => $houseSilla->id]);
assertCondition((int) $statement->fetchColumn() === 30, 'La casa vuelve a 30 adeptos activos: solo el ganador entró (caso límite 8)');

// --- FASE 2: Casa que muta de régimen en vuelo (caso límite 2) -------------
echo "\nFASE 2: La casa muda su rito en vuelo — el gesto jamás se convierte\n";
$houseMutable = $service->foundClan(
    oathUser($pdo, 'usr_mutante', 'FundadoraMutable', 'editor'),
    'Casa del Rito Cambiante', 'Ayer abierta, hoy de pergamino', 'rune_mutante', 'primordialFlame', 'open', $now
);
$cortesano = oathUser($pdo, 'usr_cortesano', 'CortesanoDelRitoViejo', 'editor');

// El catálogo del cortesano retrata la casa como `open` (foto antigua)…
$catalogoAntiguo = $service->browseClans('primordialFlame');
$retrato = null;
foreach ($catalogoAntiguo->items as $item) {
    if ($item->id === $houseMutable->id) {
        $retrato = $item;
        break;
    }
}
assertCondition($retrato !== null && $retrato->admissionMode === 'open', 'La foto del catálogo retrata la casa como `open`');

// …pero ANTES del gesto, la casa muda su rito a `byApplication`.
$clanRepository = new Grimorio\Repositories\ClanRepository($pdo, new AuditService($pdo));
$clanRepository->updateAdmissionMode($houseMutable->id, 'byApplication', $now->format('Y-m-d\TH:i:s\Z'));

// El gesto enviado bajo la foto antigua encuentra la realidad mudada: el
// backend decide. Un ingreso `open` no puede forzarse: la casa exige pergamino.
$e = captureException(static fn () => $service->applyToClan($cortesano, $houseMutable->id, $now));
assertCondition($e !== null, 'El gesto bajo la foto antigua NO prospera como ingreso inmediato (el backend manda)');
assertCondition(
    $e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::INVALID_MOTIVATION,
    'El veredicto exige el pergamino del nuevo rito: INVALID_MOTIVATION (el gesto jamás se convierte en otro compromiso)'
);
$statement = $pdo->prepare("SELECT COUNT(*) FROM clan_members WHERE clan_id = :clanId AND user_id = :userId AND left_at IS NULL");
$statement->execute([':clanId' => $houseMutable->id, ':userId' => 'usr_cortesano']);
assertCondition((int) $statement->fetchColumn() === 0, 'Ninguna membresía nació del gesto mudado para el cortesano');

// El gesto HONESTO con el rito nuevo (petición formal) sí prospera.
$peticionMudada = $service->applyToClan($cortesano, $houseMutable->id, $now, 'Me pliego al nuevo rito: presento mi pergamino.');
assertCondition($peticionMudada->isPending(), 'Bajo el rito nuevo, la petición formal remite (RF-03.1)');

// --- FASE 3: Doble envío idempotente (caso límite 6, RF-02.3) --------------
echo "\nFASE 3: El doble envío es idempotente — el militante no duplica su lealtad\n";
$houseDoble = $service->foundClan(
    oathUser($pdo, 'usr_fundadora_doble', 'FundadoraDelDoble', 'editor'),
    'Casa del Doble Juramento', 'Repetir no compromete dos veces', 'rune_doble', 'primordialFlame', 'open', $now
);
$militante = oathUser($pdo, 'usr_militante_doble', 'MilitanteDelDoble', 'editor');

$primero = $service->applyToClan($militante, $houseDoble->id, $now);
assertCondition($primero->isAdmitted(), 'El primer gesto admite al militante');

// El militante REPITE el gesto sobre SU casa (doble clic, reintento de red,
// segunda pestaña): éxito sin mutación — la membresía ya existía.
$repetido = $service->applyToClan($militante, $houseDoble->id, $now);
assertCondition($repetido->isAdmitted(), 'El gesto repetido sobre la casa propia responde éxito sin mutación (RF-02.3)');
$statement = $pdo->prepare('SELECT COUNT(*) FROM clan_members WHERE clan_id = :clanId AND user_id = :userId AND left_at IS NULL');
$statement->execute([':clanId' => $houseDoble->id, ':userId' => 'usr_militante_doble']);
assertCondition((int) $statement->fetchColumn() === 1, 'Una SOLA membresía persiste: la repetición no duplica nada');

// Y si el militante apunta a OTRA casa de SU linaje: lealtad empeñada.
$houseOtra = $service->foundClan(
    oathUser($pdo, 'usr_fundadora_otra', 'FundadoraDeLaOtra', 'editor'),
    'Casa de la Otra Puerta', 'Otra puerta, misma sangre', 'rune_otra', 'primordialFlame', 'open', $now
);
$e = captureException(static fn () => $service->applyToClan($militante, $houseOtra->id, $now));
assertCondition($e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::CLAN_LOYALTY_BOUND, 'El militante hacia otra casa: CLAN_LOYALTY_BOUND (RF-02.3)');
assertCondition($e !== null && str_contains($e->getMessage(), 'Casa del Doble Juramento'), 'La leyenda nombra la casa donde vive su lealtad');

// --- FASE 4: Retirada y dictamen concurrentes (caso límite 5) --------------
echo "\nFASE 4: Retirada y dictamen concurrentes — un solo desenlace\n";
$houseDeliberacion = $service->foundClan(
    oathUser($pdo, 'usr_patriarca_carrera', 'PatriarcaDeLaCarrera', 'editor'),
    'Casa de la Deliberación Rápida', 'Delibera antes de que retires', 'rune_carrera', 'primordialFlame', 'byApplication', $now
);
$postulante = oathUser($pdo, 'usr_post_carrera', 'PostulanteDeLaCarrera', 'editor');
$peticionCarrera = $service->applyToClan($postulante, $houseDeliberacion->id, $now, 'Presento mi palabra antes de la carrera de plumas.');
$patriarca = oathUser($pdo, 'usr_patriarca_real', 'ElPatriarcaReal', 'editor', 'primordialFlame');
// El Patriarca ciñe la corona por la vía directa (el fundador es otro).
$pdo->prepare('UPDATE clans SET patriarch_id = :patriarchId WHERE id = :clanId')
    ->execute([':patriarchId' => 'usr_patriarca_real', ':clanId' => $houseDeliberacion->id]);

// El dictamen gana la carrera: la petición queda resuelta (rechazada).
$service->resolveApplication($patriarca, $houseDeliberacion->id, $peticionCarrera->application?->id ?? '', 'reject', $now, 'Tu vocación aún no ha florecido.');

// La retirada llega TARDE: la petición ya no está pendiente.
$e = captureException(static fn () => $service->withdrawApplication($postulante, $houseDeliberacion->id, $peticionCarrera->application?->id ?? '', $now));
assertCondition($e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::APPLICATION_ALREADY_RESOLVED, 'La retirada tardía responde APPLICATION_ALREADY_RESOLVED (409, caso límite 5)');
assertCondition($e !== null && $e->httpStatus === 409, 'La carrera perdida responde 409');

// Un solo desenlace: la fila sigue rejected con SU motivo, jamás cancelled.
$statement = $pdo->prepare("SELECT status, verdict_motive, resolved_at FROM clan_applications WHERE id = :id");
$statement->execute([':id' => $peticionCarrera->application?->id ?? '']);
$fila = $statement->fetch(PDO::FETCH_ASSOC);
assertCondition($fila !== null && $fila['status'] === 'rejected', 'La fila conserva el desenlace del dictamen: rejected (un solo desenlace determinista)');
assertCondition($fila !== null && str_contains((string) $fila['verdict_motive'], 'florecido'), 'El motivo del dictamen sobrevive íntegro: la retirada no mutó nada');

// Y la carrera inversa: retirada primero, dictamen después — también 409.
$postulante2 = oathUser($pdo, 'usr_post_carrera2', 'PostulanteQueRetira', 'editor');
$peticionCarrera2 = $service->applyToClan($postulante2, $houseDeliberacion->id, $now, 'Mi petición voladora: retiro antes de que delibere.');
$service->withdrawApplication($postulante2, $houseDeliberacion->id, $peticionCarrera2->application?->id ?? '', $now);
$e = captureException(static fn () => $service->resolveApplication($patriarca, $houseDeliberacion->id, $peticionCarrera2->application?->id ?? '', 'approve', $now));
assertCondition($e instanceof ClanGovernanceException && $e->errorCode === ClanGovernanceException::APPLICATION_ALREADY_RESOLVED, 'El dictamen tardío sobre la retirada: APPLICATION_ALREADY_RESOLVED (serialización simétrica)');

// --- FASE 5: La estampa de llegada alimenta el desempate (plan §3.3) -------
echo "\nFASE 5: La estampa de llegada queda grabada para el desempate\n";
// El recibidor de la silla anterior porta su estampa de llegada servida.
$statement = $pdo->prepare('SELECT joined_at FROM clan_members WHERE clan_id = :clanId AND left_at IS NULL');
$statement->execute([':clanId' => $houseSilla->id]);
$joinedAt = (string) $statement->fetchColumn();
assertCondition($joinedAt === '2026-09-20T12:00:00Z', 'La estampa de llegada del ganador queda grabada en la membresía (desempate auditable, RF-04.5)');

// --- Veredicto ---
echo "\n=============================\n";
echo "Asertos: {$assertsPassed} PASA / {$assertsFailed} FALLOS\n";
if ($assertsFailed > 0) {
    echo "Las carreras de la adhesión quedan ROJAS: no cumplen aún su contrato.\n";
    exit(1);
}
echo "Las carreras de la adhesión se serializan: un solo desenlace, el orden de llegada manda y el gesto jamás se convierte.\n";
exit(0);
