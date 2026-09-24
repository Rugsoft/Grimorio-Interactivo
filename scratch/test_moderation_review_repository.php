<?php

declare(strict_types=1);

/**
 * test_moderation_review_repository.php — Verificación de la Tarea 1.2 de TASKS-08.
 *
 * Valida contra el criterio «Hecho cuando» de
 * specs/08-moderation-two-step.tasks.md:
 *   «Todos los métodos ejecutan sentencias PDO preparadas,
 *    countActiveReviewsByAuthor() contabiliza exactamente los conjuros en
 *    experimental del usuario, y findAndLockById() recupera la fila para
 *    bloqueo transaccional.»
 *
 * Estrategia: se levanta una base SQLite efímera con la secuencia canónica
 * (database/schema.sql + database/seeds.sql) y se ejercita el repositorio
 * contra el contrato real del esquema de la Tarea 1.1. El bloqueo
 * transaccional se prueba con dos conexiones sobre un fichero temporal: la
 * segunda no puede escribir mientras la primera sostiene el bloqueo.
 *
 * Fases:
 *   [0]  Superficie: el módulo existe, declara strict_types y sus nueve
 *        métodos canónicos, sin dependencias externas.
 *   [1]  Inscripción del expediente (createOrUpdateReview) e idempotencia
 *        de la relación 1:1 con `spells`.
 *   [2]  Lecturas: findById y findBySpellId, con acierto y con ausencia.
 *   [3]  Transiciones canónicas y sellado de su marca temporal (updateStatus).
 *   [4]  Contador de firmas 0..3 (updateSignaturesCount).
 *   [5]  El cupo del autor: countActiveReviewsByAuthor cuenta EXACTAMENTE las
 *        obras en deliberación (criterio «Hecho cuando»).
 *   [6]  El bloqueo transaccional de findAndLockById (criterio): segunda
 *        conexión rechazada, liberación al confirmar la transacción.
 *   [7]  La cola del Atrio: filtros, orden determinista y lista blanca.
 *   [8]  El letargo de 90 días: reloj inyectado y memoria de firmas.
 *   [9]  100% consultas preparadas: binding real ante entrada hostil y
 *        auditoría estática del fuente.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sin librerías externas.
 *   - Artículo V (Dualidad): identificadores en inglés snake_case; narrativa
 *     y comentarios en noble castellano.
 *
 * Uso: php scratch/test_moderation_review_repository.php
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

/** ¿Lanza este cierre de excepción? Devuelve el mensaje o null. */
function captureError(callable $operation): ?string
{
    try {
        $operation();

        return null;
    } catch (Throwable $failure) {
        return $failure->getMessage();
    }
}

echo "== VERIFICACION TAREA 1.2: Repositorio del Expediente de Moderacion ==\n\n";

$projectRoot = dirname(__DIR__);
$repositoryPath = $projectRoot . '/src/Repositories/SpellReviewRepository.php';

// --- FASE 0: Superficie del módulo ---
echo "FASE 0: Superficie del modulo\n";
assertCondition(file_exists($repositoryPath), 'Existe src/Repositories/SpellReviewRepository.php');

if (!file_exists($repositoryPath)) {
    echo "\nRESULTADO: DENEGADO — falta el repositorio de la Tarea 1.2 (fase roja del TDD).\n";
    exit(1);
}

$repositorySource = (string) file_get_contents($repositoryPath);
$head = implode('', array_slice(file($repositoryPath, FILE_IGNORE_NEW_LINES) ?: [], 0, 40));
assertCondition(str_contains($head, 'declare(strict_types=1);'), 'Declara strict_types en las primeras 40 lineas (checklist AGENTS.md)');
assertCondition(
    str_contains($repositorySource, 'namespace Grimorio\Repositories;'),
    'Habita el espacio de nombres canonico del santuario'
);
assertCondition(
    preg_match('#^use (?!Grimorio)[A-Z]#m', $repositorySource) === 1
    && preg_match('#https?://#', $repositorySource) !== 1,
    'Solo depende de la biblioteca estandar de PHP: cero dependencias externas (Articulo I)'
);
// Auditoría de binding: todo el texto SQL vive en literales entrecomillados,
// y ningún literal SQL contiene una variable. Los nombres de columna que se
// ensamblan salen de listas cerradas del propio repositorio y los valores del
// llamador viajan siempre como parámetros vinculados.
preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $repositorySource, $literals);
$sqlLiterals = array_filter(
    $literals[1],
    static fn (string $literal): bool => preg_match('/\b(SELECT|INSERT INTO|UPDATE|DELETE FROM|FROM|WHERE|ORDER BY|JOIN)\b/', $literal) === 1
);
$interpolatedLiterals = array_values(array_filter($sqlLiterals, static fn (string $literal): bool => str_contains($literal, '$')));
assertCondition(
    $interpolatedLiterals === [],
    'Ningun literal SQL interpola una variable del llamador (parameter binding)'
);
assertCondition(
    preg_match_all("/\\\$parameters\\[':/", $repositorySource) >= 6,
    'Los filtros de la cola se registran como parametros vinculados'
);
assertCondition(
    substr_count($repositorySource, '->prepare(') >= 9,
    'Todo acceso al motor pasa por sentencias preparadas (>= 9 prepare() declarados)'
);

foreach ([
    'createOrUpdateReview',
    'findById',
    'findBySpellId',
    'findAndLockById',
    'updateStatus',
    'updateSignaturesCount',
    'countActiveReviewsByAuthor',
    'findQueueItems',
    'findStaleReviews',
] as $methodName) {
    assertCondition(
        str_contains($repositorySource, "function {$methodName}("),
        "Declara el metodo canonico {$methodName}()"
    );
}
assertCondition(
    str_contains($repositorySource, 'MAX_ACTIVE_REVIEWS_PER_AUTHOR = 3')
    && str_contains($repositorySource, 'STALE_REVIEW_DAYS = 90'),
    'Publica el cupo de tres y el letargo de 90 dias como fuente unica del canon (RF-01.2, RF-01.6)'
);

require_once $projectRoot . '/src/Repositories/SpellReviewRepository.php';
$repositoryClass = 'Grimorio\\Repositories\\SpellReviewRepository';

// Base de datos SQLite efímera en memoria: jamás contamina el santuario real.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON;');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
$pdo->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));

/** @var SpellReviewRepository $repository */
$repository = new $repositoryClass($pdo);

$NOW = '2026-09-15T10:00:00Z';
$FINGERPRINT = str_repeat('a', 64);

// Semilla: dos autoras y cuatro obras en estados distintos.
$pdo->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
            VALUES ('usr_autora_uno', 'Autora Primera', 'autora1@arcano.arc', 'x', 'editor', 'cln_primordial', '{$NOW}', '{$NOW}')");
$pdo->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
            VALUES ('usr_autora_dos', 'Autora Segunda', 'autora2@arcano.arc', 'x', 'editor', NULL, '{$NOW}', '{$NOW}')");

/** Inscribe un conjuro con el fingerprint canónico de 64 caracteres. */
$forgeSpell = static function (PDO $connection, string $spellId, string $slug, string $authorId, string $affinity, string $school, string $clanId) use ($NOW, $FINGERPRINT): void {
    $statement = $connection->prepare(
        'INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, mana_cost, clan_id, summary, math_fingerprint, created_at, updated_at)
         VALUES (:id, :slug, :name, :authorId, :school, :affinity, 20, :clanId, :summary, :fingerprint, :createdAt, :updatedAt)'
    );
    $statement->execute([
        ':id'          => $spellId,
        ':slug'        => $slug,
        ':name'        => 'Obra ' . $spellId,
        ':authorId'    => $authorId,
        ':school'      => $school,
        ':affinity'    => $affinity,
        ':clanId'      => $clanId,
        ':summary'     => 'Obra de prueba del expediente.',
        ':fingerprint' => $FINGERPRINT,
        ':createdAt'   => $NOW,
        ':updatedAt'   => $NOW,
    ]);
};
$forgeSpell($pdo, 'spl_uno', 'obra-uno', 'usr_autora_uno', 'fire', 'evocation', 'cln_primordial');
$forgeSpell($pdo, 'spl_dos', 'obra-dos', 'usr_autora_uno', 'water', 'abjuration', 'cln_primordial');
$forgeSpell($pdo, 'spl_tres', 'obra-tres', 'usr_autora_uno', 'wind', 'divination', 'cln_primordial');
$forgeSpell($pdo, 'spl_cuatro', 'obra-cuatro', 'usr_autora_uno', 'earth', 'transmutation', 'cln_primordial');
$forgeSpell($pdo, 'spl_ajena', 'obra-ajena', 'usr_autora_dos', 'lightning', 'evocation', 'cln_primordial');

// --- FASE 1: Inscripción e idempotencia del expediente ---
echo "\nFASE 1: Inscripcion del expediente y relacion 1:1 con `spells`\n";
$review = $repository->createOrUpdateReview(
    'rev_uno',
    'spl_uno',
    'usr_autora_uno',
    'experimental',
    $FINGERPRINT,
    'cln_primordial',
    0,
    $NOW
);
assertCondition($review['id'] === 'rev_uno' && $review['spell_id'] === 'spl_uno', 'La revision se inscribe con su identificador y su conjuro');
assertCondition($review['status'] === 'experimental' && $review['signatures_count'] === 0, 'Nace en deliberacion y con cero firmas (RF-01.2)');
assertCondition($review['submitted_at'] === $NOW, 'Sella el instante de entrada a la Torre de Moderacion');
assertCondition($review['origin_clan_id'] === 'cln_primordial', 'Conserva el clan patrimonial de concepcion (Art. III)');
assertCondition($review['math_fingerprint'] === $FINGERPRINT, 'Conserva integra la huella del balance sellado (Art. II)');
assertCondition($review['validated_at'] === null && $review['rejected_at'] === null, 'Los estados no alcanzados quedan vacios');

$updated = $repository->createOrUpdateReview(
    'rev_ignorado',
    'spl_uno',
    'usr_autora_uno',
    'experimental',
    $FINGERPRINT,
    'cln_primordial',
    1,
    null
);
assertCondition(
    $updated['id'] === 'rev_uno' && $updated['signatures_count'] === 1,
    'Reinscribir el mismo conjuro ACTUALIZA su fila en lugar de duplicarla (relacion 1:1)'
);
assertCondition(
    $updated['submitted_at'] === $NOW,
    'Un envio que omite la marca de entrada no borra la ya conocida'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM spell_reviews WHERE spell_id = 'spl_uno'")->fetchColumn() === 1,
    'La relacion 1:1 con `spells` se preserva tras varias inscripciones'
);
assertCondition(
    captureError(fn () => $repository->createOrUpdateReview('rev_x', 'spl_dos', 'usr_autora_uno', 'enDeliberacion', $FINGERPRINT)) !== null,
    'Un estado fuera de los cinco canonicos de RF-01.1 es rechazado antes de tocar la base'
);
assertCondition(
    captureError(fn () => $repository->createOrUpdateReview('rev_x', 'spl_dos', 'usr_autora_uno', 'draft', 'huella-corta')) !== null,
    'Una huella que no sea SHA-256 de 64 caracteres es rechazada (Art. II)'
);
assertCondition(
    captureError(fn () => $repository->createOrUpdateReview('rev_x', 'spl_dos', 'usr_autora_uno', 'draft', $FINGERPRINT, null, 4)) !== null,
    'Un expediente no puede nacer con cuatro firmas (RF-02.1)'
);

// --- FASE 2: Lecturas ---
echo "\nFASE 2: Lecturas por identificador y por conjuro\n";
assertCondition($repository->findById('rev_uno') !== null, 'findById recupera el expediente por su identificador');
assertCondition($repository->findBySpellId('spl_uno')['id'] === 'rev_uno', 'findBySpellId recupera el expediente del conjuro');
assertCondition($repository->findById('rev_inexistente') === null, 'Una revision inexistente devuelve null, no un fallo');
assertCondition($repository->findBySpellId('spl_sin_revision') === null, 'Un conjuro sin expediente devuelve null (fase roja honesta)');

// --- FASE 3: Transiciones y sellado temporal ---
echo "\nFASE 3: Transiciones canonicas y sus marcas temporales\n";
assertCondition(
    $repository->updateStatus('spl_uno', 'rejected', '2026-09-16T00:00:00Z') === true,
    'La objecion transiciona el expediente a rechazado (RF-02.6)'
);
$rejected = $repository->findBySpellId('spl_uno');
assertCondition(
    $rejected['status'] === 'rejected' && $rejected['rejected_at'] === '2026-09-16T00:00:00Z',
    'El estado rechazado sella su propia columna (rejected_at)'
);
assertCondition(
    $repository->updateStatus('spl_uno', 'draft', '2026-09-17T00:00:00Z') === true,
    'Reabrir como borrador es una transicion legitima (RF-01.4)'
);
$reopened = $repository->findBySpellId('spl_uno');
assertCondition(
    $reopened['status'] === 'draft' && $reopened['reopened_at'] === '2026-09-17T00:00:00Z',
    'El borrador alcanzado por transicion sella reopened_at, no submitted_at'
);
assertCondition(
    $repository->updateStatus('spl_uno', 'experimental', '2026-09-18T00:00:00Z') === true
    && $repository->findBySpellId('spl_uno')['submitted_at'] === '2026-09-18T00:00:00Z',
    'Reenviar a la Torre vuelve a sellar la entrada a deliberacion'
);
assertCondition(
    $repository->updateStatus('spl_uno', 'validated', null) === true
    && $repository->findBySpellId('spl_uno')['validated_at'] === null,
    'Una transicion sin instante muda el estado sin reescribir la historia'
);
assertCondition(
    $repository->updateStatus('spl_inexistente', 'draft') === false,
    'Transicionar un conjuro sin expediente devuelve falso, jamás un error'
);
assertCondition(
    captureError(fn () => $repository->updateStatus('spl_uno', 'heretico')) !== null,
    'Un estado ajeno al canon es rechazado en el repositorio y en la base'
);

// --- FASE 4: Contador de firmas ---
echo "\nFASE 4: Contador de firmas del expediente\n";
assertCondition($repository->updateSignaturesCount('spl_uno', 1) === true, 'El contador admite una firma');
assertCondition($repository->findBySpellId('spl_uno')['signatures_count'] === 1, 'El contador queda fijado exactamente');
assertCondition($repository->updateSignaturesCount('spl_uno', 3) === true, 'El contador admite el techo canonico de tres (RF-02.1)');
assertCondition(
    captureError(fn () => $repository->updateSignaturesCount('spl_uno', 4)) !== null,
    'Una cuarta firma es rechazada antes de tocar la base'
);
assertCondition(
    captureError(fn () => $repository->updateSignaturesCount('spl_uno', -1)) !== null,
    'Un contador negativo es rechazado'
);
assertCondition($repository->updateSignaturesCount('spl_inexistente', 1) === false, 'Sin expediente, el contador no se fija y se informa');

// --- FASE 5: El cupo del autor (criterio «Hecho cuando») ---
echo "\nFASE 5: countActiveReviewsByAuthor cuenta EXACTAMENTE las obras en deliberacion\n";
$repository->createOrUpdateReview('rev_dos', 'spl_dos', 'usr_autora_uno', 'experimental', $FINGERPRINT, 'cln_primordial', 0, $NOW);
$repository->createOrUpdateReview('rev_tres', 'spl_tres', 'usr_autora_uno', 'experimental', $FINGERPRINT, 'cln_primordial', 2, $NOW);
$repository->createOrUpdateReview('rev_cuatro', 'spl_cuatro', 'usr_autora_uno', 'validated', $FINGERPRINT, 'cln_primordial', 3, $NOW);
$repository->createOrUpdateReview('rev_ajena', 'spl_ajena', 'usr_autora_dos', 'experimental', $FINGERPRINT, null, 0, $NOW);

assertCondition(
    $repository->countActiveReviewsByAuthor('usr_autora_uno') === 2,
    'Cuenta dos obras en deliberacion: la validada y la rechazada NO ocupan plaza (RF-01.5)'
);
assertCondition(
    $repository->countActiveReviewsByAuthor('usr_autora_dos') === 1,
    'El cupo es de cada autor: la obra ajena no consume la plaza de nadie más'
);
assertCondition(
    $repository->countActiveReviewsByAuthor('usr_sin_obras') === 0,
    'Un autor sin obras devuelve cero, nunca null'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM spell_reviews WHERE author_id = 'usr_autora_uno'")->fetchColumn() === 4,
    'El autor conserva sus cuatro expedientes: el cupo se mide, no se borra'
);
// La plaza se libera sola al consagrarse la obra (RF-01.5).
$repository->updateStatus('spl_dos', 'validated', $NOW);
assertCondition(
    $repository->countActiveReviewsByAuthor('usr_autora_uno') === 1,
    'Consagrar una obra libera su plaza de inmediato (RF-01.5)'
);

// --- FASE 6: El bloqueo transaccional (criterio «Hecho cuando») ---
echo "\nFASE 6: findAndLockById recupera la fila BAJO BLOQUEO transaccional (criterio)\n";
$lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'grimorio_review_lock_' . getmypid() . '.sqlite';
@unlink($lockPath);

$locker = new PDO('sqlite:' . $lockPath);
$locker->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$locker->exec('PRAGMA foreign_keys = ON;');
$locker->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
$locker->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));
$locker->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
               VALUES ('usr_autora_uno', 'Autora Primera', 'autora1@arcano.arc', 'x', 'editor', 'cln_primordial', '{$NOW}', '{$NOW}')");
$forgeSpell($locker, 'spl_bloqueo', 'obra-bloqueo', 'usr_autora_uno', 'fire', 'evocation', 'cln_primordial');
$lockerRepository = new $repositoryClass($locker);
$lockerRepository->createOrUpdateReview('rev_bloqueo', 'spl_bloqueo', 'usr_autora_uno', 'experimental', $FINGERPRINT, 'cln_primordial', 2, $NOW);
$lockerRepository->updateSignaturesCount('spl_bloqueo', 2);

$intruder = new PDO('sqlite:' . $lockPath);
$intruder->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$intruder->setAttribute(PDO::ATTR_TIMEOUT, 1);

$locked = $lockerRepository->findAndLockById('spl_bloqueo');
assertCondition($locked !== null && $locked['signatures_count'] === 2, 'El expediente se recupera con sus dos firmas vivas');
assertCondition($locker->inTransaction() === true, 'La consulta deja la transaccion ABIERTA: el llamante la cierra');
assertCondition($lockerRepository->findAndLockById('spl_sin_expediente') === null, 'Un conjuro sin expediente no bloquea nada y devuelve null');

$intruderError = captureError(function () use ($intruder, $NOW): void {
    $statement = $intruder->prepare(
        'INSERT INTO master_signatures (id, spell_id, master_id, master_clan_id, signed_at)
         VALUES (:id, :spellId, :masterId, NULL, :signedAt)'
    );
    $statement->execute([
        ':id'       => 'sig_intrusa',
        ':spellId'  => 'spl_bloqueo',
        ':masterId' => 'usr_custodio_primordial',
        ':signedAt' => $NOW,
    ]);
});
assertCondition(
    $intruderError !== null && preg_match('/lock|busy/i', $intruderError) === 1,
    'Mientras el bloqueo vive, otro escritor es rechazado por el motor (consagracion atomica, RF-02.3)'
);

// La consagración del tercer aval acontece DENTRO del bloqueo.
assertCondition($lockerRepository->updateSignaturesCount('spl_bloqueo', 3) === true, 'La tercera firma se inscribe dentro del bloqueo');
$locker->commit();
$intruder->exec("INSERT INTO master_signatures (id, spell_id, master_id, master_clan_id, signed_at)
                 VALUES ('sig_intrusa', 'spl_bloqueo', 'usr_custodio_primordial', NULL, '{$NOW}')");
assertCondition(
    (int) $intruder->query("SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_bloqueo'")->fetchColumn() === 1,
    'Confirmada la transaccion, el bloqueo se libera y el tercer firmante entra'
);
unset($intruder);
unset($locker);
@unlink($lockPath);

// --- FASE 7: La cola del Atrio ---
echo "\nFASE 7: La cola de deliberacion del Atrio (RF-05.1)\n";
$queue = $repository->findQueueItems();
$queueSpells = array_column($queue, 'spell_id');
assertCondition(
    in_array('spl_tres', $queueSpells, true) && in_array('spl_ajena', $queueSpells, true),
    'La cola devuelve por omision las obras EN DELIBERACION'
);
assertCondition(
    in_array('spl_cuatro', $queueSpells, true) === false && in_array('spl_uno', $queueSpells, true) === false,
    'La cola excluye las consagradas y las rechazadas (RF-05.1)'
);
assertCondition(
    $queueSpells === array_values(array_unique($queueSpells)),
    'Ninguna obra se duplica en la cola'
);
assertCondition(
    $repository->findQueueItems(['authorId' => 'usr_autora_dos'])[0]['spell_id'] === 'spl_ajena',
    'El filtro por autor acota la cola a las obras de un mago'
);
assertCondition(
    count($repository->findQueueItems(['elementalAffinity' => 'wind'])) === 1
    && $repository->findQueueItems(['elementalAffinity' => 'wind'])[0]['spell_id'] === 'spl_tres',
    'La cola cruza con `spells` para filtrar por afinidad elemental'
);
assertCondition(
    count($repository->findQueueItems(['magicSchool' => 'evocation'])) === 1,
    'La cola filtra por escuela de magia'
);
assertCondition(
    count($repository->findQueueItems(['originClanId' => 'cln_primordial'])) === 1
    && count($repository->findQueueItems(['originClanId' => 'cln_inexistente'])) === 0,
    'La cola filtra por clan de origen: las obras de ermitano no acreditan patrimonio'
);
assertCondition(
    count($repository->findQueueItems(['minSignatures' => 1])) === 1,
    'La cola puede pedir solo las obras ya avaladas por algun Maestro'
);
assertCondition(
    count($repository->findQueueItems(['limit' => 1])) === 1,
    'La cola admite paginacion acotada'
);
$allQueue = $repository->findQueueItems(['limit' => 1]);
$secondItem = $repository->findQueueItems(['limit' => 1, 'offset' => 1]);
assertCondition(
    $secondItem !== [] && $secondItem[0]['id'] !== $allQueue[0]['id'],
    'El desplazamiento avanza la pagina sin repetir la primera obra'
);
assertCondition(
    captureError(fn () => $repository->findQueueItems(['DROP TABLE spell_reviews' => '1'])) !== null,
    'Un filtro desconocido es rechazado: la lista blanca no admite claves ajenas'
);
assertCondition(
    captureError(fn () => $repository->findQueueItems(['limit' => -5])) !== null,
    'La paginacion negativa es rechazada'
);

// --- FASE 8: El letargo de 90 días ---
echo "\nFASE 8: El letargo arcano de 90 dias sin resonancia (RF-01.6)\n";
$repository->createOrUpdateReview('rev_olvidada', 'spl_cuatro', 'usr_autora_uno', 'experimental', $FINGERPRINT, 'cln_primordial', 0, '2026-05-01T00:00:00Z');
$repository->createOrUpdateReview('rev_reciente', 'spl_dos', 'usr_autora_uno', 'experimental', $FINGERPRINT, 'cln_primordial', 0, '2026-09-01T00:00:00Z');
$repository->createOrUpdateReview('rev_avalada', 'spl_ajena', 'usr_autora_dos', 'experimental', $FINGERPRINT, null, 1, '2026-05-01T00:00:00Z');
$pdo->exec("DELETE FROM master_signatures");
$pdo->exec("INSERT INTO master_signatures (id, spell_id, master_id, master_clan_id, signed_at, is_revoked, revoked_at, revocation_reason)
            VALUES ('sig_memoria', 'spl_ajena', 'usr_custodio_primordial', 'cln_primordial', '2026-08-01T00:00:00Z', 1, '2026-08-02T00:00:00Z', 'retracted')");

$instant = static fn (string $utc): DateTimeImmutable => new DateTimeImmutable($utc, new DateTimeZone('UTC'));

// Con el reloj en septiembre, la unica obra en letargo es la olvidada desde
// mayo; la obra con una firma RETRACTADA de agosto NO lo esta: retractarse es
// tambien mirar la obra (RF-01.6).
$staleBySpell = array_column($repository->findStaleReviews(90, $instant('2026-09-15T10:00:00Z')), 'spell_id');
assertCondition(
    $staleBySpell === ['spl_cuatro'],
    'En letargo queda exactamente la obra sin resonancia desde mayo (RF-01.6)'
);
assertCondition(
    in_array('spl_ajena', $staleBySpell, true) === false,
    'Una firma RETRACTADA cuenta como interaccion de un Maestro y reinicia el reloj'
);
assertCondition(
    in_array('spl_dos', $staleBySpell, true) === false && in_array('spl_tres', $staleBySpell, true) === false,
    'La obra reenviada en septiembre conserva su reloj fresco: el letargo no se hereda'
);

// Con el reloj avanzado a noviembre, la obra avalada en agosto tambien caduca y
// la cola llega ordenada por su reloj ascendente: lo mas olvidado primero.
$novemberStale = array_column($repository->findStaleReviews(90, $instant('2026-11-15T10:00:00Z')), 'spell_id');
assertCondition(
    $novemberStale === ['spl_cuatro', 'spl_ajena'],
    'Avanzado el reloj, la obra avalada en agosto entra en letargo y la cola va ordenada por olvido'
);
assertCondition(
    $repository->findStaleReviews(90, $instant('2026-09-20T00:00:00Z')) !== [],
    'El reloj es inyectable: el repositorio no inventa su propio instante (RNF-01)'
);
assertCondition(
    captureError(fn () => $repository->findStaleReviews(0)) !== null,
    'Un umbral de letargo no positivo es rechazado'
);

// --- FASE 9: 100% consultas preparadas ---
echo "\nFASE 9: Parameter binding ante entrada hostil\n";
$hostile = "x' OR '1'='1";
assertCondition(
    $repository->findQueueItems(['authorId' => $hostile]) === [],
    'Un filtro hostil devuelve cero filas: la concatenacion ingenua habria devuelto la cola entera'
);
assertCondition(
    $repository->findById("rev_uno' OR '1'='1") === null,
    'Un identificador hostil no puede ampliar la busqueda'
);
$forgeSpell($pdo, 'spl_hostil', 'obra-hostil', 'usr_autora_uno', 'fire', 'evocation', 'cln_primordial');
$hostileReview = $repository->createOrUpdateReview(
    "rev_hostil'; DROP TABLE spell_reviews; --",
    'spl_hostil',
    'usr_autora_uno',
    'draft',
    $FINGERPRINT,
    'cln_primordial',
    0,
    null
);
assertCondition(
    $hostileReview['id'] === "rev_hostil'; DROP TABLE spell_reviews; --",
    'Un identificador hostil se guarda literal: el binding lo neutraliza'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'spell_reviews'")->fetchColumn() === 1,
    'La tabla sobrevive al intento de inyeccion: parameter binding inviolable (AGENTS.md 6.1)'
);
assertCondition(
    $repository->findById("rev_hostil'; DROP TABLE spell_reviews; --")['spell_id'] === 'spl_hostil',
    'El expediente hostil se relee con su conjuro: el valor viaja como DATO, jamas como SQL'
);

// --- Resumen ---
echo "\n== RESUMEN == Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";
if ($assertsFailed === 0) {
    echo "RESULTADO: EXITO — El expediente de moderacion se persiste con PDO preparado, cupo medible y bloqueo transaccional (Tarea 1.2).\n";
    exit(0);
}
echo "RESULTADO: DENEGADO — El repositorio del expediente no cumple su criterio 'Hecho cuando'.\n";
exit(1);
