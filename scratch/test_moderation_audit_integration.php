<?php

declare(strict_types=1);

/**
 * test_moderation_audit_integration.php — Verificación de la Tarea 1.4 de TASKS-08.
 *
 * Valida contra el criterio «Hecho cuando» de
 * specs/08-moderation-two-step.tasks.md:
 *   «Cada decreto imperial se persiste con su texto de justificación y se
 *    genera un registro estructurado correspondiente en la tabla de auditoría
 *    del santuario.»
 *
 * Estrategia: se levanta una base SQLite efímera con la secuencia canónica
 * (database/schema.sql + database/seeds.sql + sql/08_moderation_schema.sql) y
 * se ejercita `ImperialDecreeRepository` contra el contrato real del esquema de
 * la Tarea 1.1, con el `AuditService` de SPEC-03 como único canal hacia
 * `audit_log`. La atomicidad no se afirma: se prueba creando un disparador
 * temporal que hace fracasar la escritura de la bitácora y comprobando que el
 * decreto NO sobrevive.
 *
 * Fases:
 *   [0]  Superficie: el módulo existe, declara strict_types, publica su
 *        catálogo de decretos y exige la auditoría en su constructor.
 *   [1]  Inscripción del Decreto Imperial y sus lecturas.
 *   [2]  El criterio «Hecho cuando»: cada decreto deja su registro
 *        ESTRUCTURADO en la Bitácora de Auditoría (actor, acto, objetivo,
 *        edicto íntegro e instante compartido).
 *   [3]  Los umbrales: edicto de veinte caracteres y los cuatro decretos
 *        canónicos de RF-04.
 *   [4]  Atomicidad: si la bitácora no puede inscribirse, el decreto se
 *        desvanece con ella.
 *   [5]  Inmutabilidad de la memoria: la bitácora no admite enmienda ni purga
 *        (RNF-01, Art. III).
 *   [6]  El catálogo de acciones: AuditEntry acepta los diez actos de
 *        moderación de RF-06.1 y la Bitácora pública los rotula en castellano.
 *   [7]  100% consultas preparadas y auditoría estática del fuente.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sin librerías externas.
 *   - Artículo IV (Velo Arcano): todo acto de la bitácora se lee en castellano.
 *   - Artículo V (Dualidad): identificadores en inglés snake_case; narrativa y
 *     comentarios en noble castellano.
 *
 * Uso: php scratch/test_moderation_audit_integration.php
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

echo "== VERIFICACION TAREA 1.4: Decretos Imperiales e Integracion con la Bitacora ==\n\n";

$projectRoot = dirname(__DIR__);
$repositoryPath = $projectRoot . '/src/Repositories/ImperialDecreeRepository.php';
$entryPath = $projectRoot . '/src/Models/AuditEntry.php';
$viewPath = $projectRoot . '/public/assets/js/views/auditLogView.js';

// --- FASE 0: Superficie del módulo ---
echo "FASE 0: Superficie del modulo\n";
assertCondition(file_exists($repositoryPath), 'Existe src/Repositories/ImperialDecreeRepository.php');

if (!file_exists($repositoryPath)) {
    echo "\nRESULTADO: FALLO — falta el repositorio de la Tarea 1.4 (fase roja del TDD).\n";
    exit(1);
}

$repositorySource = (string) file_get_contents($repositoryPath);
$head = implode('', array_slice(file($repositoryPath, FILE_IGNORE_NEW_LINES) ?: [], 0, 40));
assertCondition(str_contains($head, 'declare(strict_types=1);'), 'Declara strict_types en las primeras 40 lineas (checklist AGENTS.md)');
assertCondition(
    str_contains($repositorySource, 'namespace Grimorio\\Repositories;'),
    'Habita el espacio de nombres canonico del santuario'
);
assertCondition(
    preg_match('#https?://#', $repositorySource) !== 1
    && str_contains($repositorySource, 'use Grimorio\\Services\\AuditService;'),
    'Cero dependencias externas y delega la bitacora en el canal de SPEC-03 (Articulo I)'
);
assertCondition(
    str_contains($repositorySource, "TYPE_SOVEREIGN_VALIDATION = 'sovereignValidation'")
    && str_contains($repositorySource, "TYPE_RESCUE_TO_EXPERIMENTAL = 'rescueToExperimental'")
    && str_contains($repositorySource, "TYPE_RESCUE_TO_VALIDATED = 'rescueToValidated'")
    && str_contains($repositorySource, "TYPE_REVOKE_AND_ARCHIVE = 'revokeAndArchive'"),
    'Publica los cuatro decretos canonicos de RF-04 tal como los acota el DDL'
);
assertCondition(
    str_contains($repositorySource, 'MIN_IMPERIAL_DECREE_LENGTH = 20'),
    'Publica el umbral de veinte caracteres del Edicto Imperial (RF-04.5)'
);
foreach (['insertDecree', 'findDecreeById', 'findDecreesBySpell', 'findLatestDecreeBySpell', 'findDecreesByAdmin'] as $methodName) {
    assertCondition(
        str_contains($repositorySource, "function {$methodName}("),
        "Declara el metodo canonico {$methodName}()"
    );
}

require_once $projectRoot . '/src/Services/AuditService.php';
require_once $projectRoot . '/src/Models/AuditEntry.php';
require_once $repositoryPath;
$repositoryClass = 'Grimorio\\Repositories\\ImperialDecreeRepository';

// La auditoría NO es opcional: sin bitácora no hay decreto (RF-04.5).
$reflection = new ReflectionMethod($repositoryClass, '__construct');
$constructorParameters = $reflection->getParameters();
assertCondition(count($constructorParameters) === 2, 'El constructor del repositorio exige dos colaboradores');
assertCondition(
    count($constructorParameters) === 2
    && (string) $constructorParameters[1]->getType() === 'Grimorio\\Services\\AuditService'
    && !$constructorParameters[1]->isOptional(),
    'El canal de la bitacora es un colaborador OBLIGATORIO: no se puede decretar sin dejar memoria'
);
assertCondition(
    count(array_filter($constructorParameters, static fn (ReflectionParameter $parameter): bool => !$parameter->isOptional())) === 2,
    'Ninguno de los dos colaboradores puede omitirse al construir el repositorio'
);

// Base de datos SQLite efímera en memoria: jamás contamina el santuario real.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON;');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
$pdo->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));
$pdo->exec((string) file_get_contents($projectRoot . '/sql/08_moderation_schema.sql'));

$auditService = new Grimorio\Services\AuditService($pdo);
/** @var ImperialDecreeRepository $repository */
$repository = new $repositoryClass($pdo, $auditService);

$NOW = '2026-09-15T10:00:00Z';
$EDICT = 'Se constata fraude en la composición del conjuro; queda desterrado del Gran Tomo.';

/** Inscribe un mago del santuario con su rango y su linaje. */
$forgeUser = static function (PDO $connection, string $userId, string $alias, string $role, ?string $clanId) use ($NOW): void {
    $statement = $connection->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
         VALUES (:id, :alias, :email, :hash, :role, :clanId, :now, :now)'
    );
    $statement->execute([
        ':id'     => $userId,
        ':alias'  => $alias,
        ':email'  => $userId . '@arcano.arc',
        ':hash'   => 'x',
        ':role'   => $role,
        ':clanId' => $clanId,
        ':now'    => $NOW,
    ]);
};

/** Inscribe un conjuro del santuario. */
$forgeSpell = static function (PDO $connection, string $spellId, string $authorId) use ($NOW): void {
    $statement = $connection->prepare(
        'INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, mana_cost,
                             clan_id, summary, math_fingerprint, status, created_at, updated_at)
         VALUES (:id, :slug, :name, :authorId, :school, :affinity, 20, :clanId, :summary, :fingerprint,
                 :status, :now, :now)'
    );
    $statement->execute([
        ':id'          => $spellId,
        ':slug'        => 'obra-' . $spellId,
        ':name'        => 'Obra ' . $spellId,
        ':authorId'    => $authorId,
        ':school'      => 'evocation',
        ':affinity'    => 'fire',
        ':clanId'      => 'cln_primordial',
        ':summary'     => 'Obra de prueba de los decretos imperiales.',
        ':fingerprint' => str_repeat('c', 64),
        ':status'      => 'experimental',
        ':now'         => $NOW,
    ]);
};

$forgeUser($pdo, 'usr_autora', 'Autora del Alba', 'editor', 'cln_primordial');
$forgeUser($pdo, 'usr_custodio', 'Custodio Supremo', 'supremeAdmin', null);
$forgeUser($pdo, 'usr_archivista', 'Archivista del Conclave', 'supremeAdmin', 'cln_primordial');
$forgeSpell($pdo, 'spl_fraude', 'usr_autora');
$forgeSpell($pdo, 'spl_dudoso', 'usr_autora');

$auditCount = static fn (PDO $connection): int => (int) $connection->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();

// --- FASE 1: Inscripción del Decreto Imperial ---
echo "\nFASE 1: Inscripcion del Decreto Imperial\n";
$decree = $repository->insertDecree(
    'dec_uno',
    'spl_fraude',
    'usr_custodio',
    'revokeAndArchive',
    $EDICT,
    $NOW
);
assertCondition($decree['id'] === 'dec_uno' && $decree['spell_id'] === 'spl_fraude', 'El decreto se inscribe con su obra');
assertCondition($decree['admin_id'] === 'usr_custodio', 'El decreto conserva a su Administrador Supremo');
assertCondition($decree['decree_type'] === 'revokeAndArchive', 'El decreto conserva su tipo canonico');
assertCondition($decree['imperial_decree_text'] === $EDICT, 'El edicto se guarda INTEGRO, con su puntuacion');
assertCondition($decree['decreed_at'] === $NOW, 'El decreto sella el instante que le pasa el llamante (RNF-01)');
assertCondition(
    $repository->findDecreeById('dec_uno')['id'] === 'dec_uno'
    && $repository->findDecreeById('dec_inexistente') === null,
    'Un decreto se recupera por su identificador y su ausencia no es un error'
);

$segundo = $repository->insertDecree(
    'dec_dos',
    'spl_dudoso',
    'usr_custodio',
    'rescueToExperimental',
    'Se determina que la objeción previa carecía de fundamento litúrgico objetivo; se reabre la deliberación.',
    '2026-09-16T10:00:00Z'
);
assertCondition($segundo['spell_id'] === 'spl_dudoso', 'El Administrador dicta un rescate sobre otra obra');
assertCondition(
    count($repository->findDecreesBySpell('spl_dudoso')) === 1 && $repository->countDecreesBySpell('spl_dudoso') === 1,
    'El historial de una obra devuelve sus decretos y su censo los cuenta'
);
$tercero = $repository->insertDecree(
    'dec_tres',
    'spl_fraude',
    'usr_archivista',
    'sovereignValidation',
    'Por mandato del Cónclave Supremo, esta obra es consagrada de oficio por su excepcional belleza.',
    '2026-09-17T10:00:00Z'
);
assertCondition($tercero['admin_id'] === 'usr_archivista', 'Un segundo Administrador Supremo puede dictar decreto');
assertCondition(
    array_column($repository->findDecreesBySpell('spl_fraude'), 'id') === ['dec_uno', 'dec_tres'],
    'El historial de una obra se lee del decreto mas antiguo al mas reciente'
);
assertCondition(
    $repository->findLatestDecreeBySpell('spl_fraude')['id'] === 'dec_tres',
    'El ultimo decreto de una obra es el mas reciente'
);
assertCondition(
    array_column($repository->findDecreesByAdmin('usr_custodio'), 'id') === ['dec_dos', 'dec_uno'],
    'El censo de decretos de un Administrador se ordena del mas reciente al mas antiguo'
);
assertCondition(
    $repository->findLatestDecreeBySpell('spl_sin_decretos') === null
    && $repository->countDecreesBySpell('spl_sin_decretos') === 0,
    'Una obra sin decretos devuelve null y cuenta cero, no un error'
);

// --- FASE 2: El criterio «Hecho cuando» ---
echo "\nFASE 2: Cada decreto deja su registro ESTRUCTURADO en la Bitacora (criterio)\n";
assertCondition($auditCount($pdo) === 3, 'Los TRES decretos han dejado su registro en `audit_log`: ninguno sin memoria');
$entrada = $pdo->query("SELECT * FROM audit_log WHERE target_entity_id = 'spl_fraude' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCondition($entrada !== false, 'El decreto mas antiguo tiene su entrada correspondiente');
assertCondition($entrada['actor_user_id'] === 'usr_custodio', 'La bitacora imputa el acto a su Administrador Supremo');
assertCondition($entrada['actor_alias'] === 'Custodio Supremo', 'La bitacora guarda el alias publico del actuante en ese instante');
assertCondition($entrada['actor_role'] === 'supremeAdmin', 'La bitacora guarda el rol tecnico activo en ese instante');
assertCondition(
    $entrada['action_type'] === 'SOVEREIGN_ARCHIVE',
    'El destierro se inscribe con su acto canonico propio (SOVEREIGN_ARCHIVE)'
);
assertCondition($entrada['target_entity_type'] === 'spell' && $entrada['target_entity_id'] === 'spl_fraude', 'La bitacora apunta al conjuro afectado');
assertCondition($entrada['justification'] === $EDICT, 'El edicto imperial integro es la justificacion de la entrada (RF-04.5)');
assertCondition($entrada['created_at'] === $NOW, 'El decreto y su memoria comparten instante: no pueden fecharse por separado');

$acciones = [];
foreach ($pdo->query('SELECT action_type, target_entity_id FROM audit_log ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    $acciones[] = $fila['action_type'] . ':' . $fila['target_entity_id'];
}
assertCondition(
    $acciones === ['SOVEREIGN_ARCHIVE:spl_fraude', 'SOVEREIGN_RESCUE:spl_dudoso', 'SOVEREIGN_VALIDATION:spl_fraude'],
    'Los cuatro decretos se traducen a su acto canonico: el llamante no elige el nombre del acto'
);
assertCondition(
    (int) $pdo->query('SELECT COUNT(*) FROM audit_log WHERE action_type = ' . $pdo->quote('SOVEREIGN_RESCUE'))->fetchColumn() === 1,
    'Rescatar a experimental y rescatar a validated comparten el mismo acto soberano'
);

// --- FASE 3: Los umbrales ---
echo "\nFASE 3: El edicto de veinte caracteres y los cuatro decretos canonicos\n";
assertCondition(
    captureError(fn () => $repository->insertDecree('dec_x', 'spl_fraude', 'usr_custodio', 'revokeAndArchive', str_repeat('a', 19), $NOW)) !== null,
    'Un edicto de 19 caracteres es rechazado antes de tocar la base (RF-04.5)'
);
assertCondition(
    captureError(fn () => $repository->insertDecree('dec_x', 'spl_fraude', 'usr_custodio', 'revokeAndArchive', str_repeat(' ', 40), $NOW)) !== null,
    'Cuarenta espacios no son un edicto: el umbral se mide sobre el texto recortado'
);
assertCondition(
    $repository->insertDecree('dec_borde', 'spl_dudoso', 'usr_custodio', 'rescueToValidated', 'Falta coherencia ya!', '2026-09-18T10:00:00Z') !== null,
    'Un edicto de exactamente 20 caracteres es admitido: el umbral se mide con rigor'
);
assertCondition(
    captureError(fn () => $repository->insertDecree('dec_x', 'spl_fraude', 'usr_custodio', 'sovereignDismissal', $EDICT, $NOW)) !== null,
    'Un decreto fuera de los cuatro canonicos de RF-04 es rechazado antes de tocar la base'
);
assertCondition(
    captureError(fn () => $repository->insertDecree('dec_x', 'spl_fraude', 'usr_fantasma', 'revokeAndArchive', $EDICT, $NOW)) !== null,
    'Un decreto sin Administrador inscrito es rechazado: sin identidad no hay transparencia'
);
assertCondition(
    $auditCount($pdo) === 4,
    'Ningun decreto invalido dejo memoria alguna: la bitacora solo inscribe actos que existieron'
);
assertCondition(
    captureError(fn () => $repository->insertDecree('dec_x', 'spl_inexistente', 'usr_custodio', 'revokeAndArchive', $EDICT, $NOW)) !== null
    && $auditCount($pdo) === 4,
    'Un decreto sobre un conjuro inexistente es rechazado por la clave foranea y no mancha la bitacora'
);

// --- FASE 4: Atomicidad ---
echo "\nFASE 4: Atomicidad del decreto y su memoria\n";
$decreesAntes = (int) $pdo->query('SELECT COUNT(*) FROM sovereign_decrees')->fetchColumn();
$auditAntes = $auditCount($pdo);
// Disparador temporal: la bitácora se cierra para el Administrador marcado, de
// modo que la segunda escritura fracase DESPUÉS de la primera.
$pdo->exec(
    "CREATE TRIGGER trg_arnes_sella_bitacora BEFORE INSERT ON audit_log
     WHEN NEW.actor_user_id = 'usr_archivista'
     BEGIN SELECT RAISE(ABORT, 'la bitacora esta sellada por el arnes'); END"
);
$fallo = captureError(fn () => $repository->insertDecree(
    'dec_atomico',
    'spl_dudoso',
    'usr_archivista',
    'rescueToValidated',
    'Se consagra de oficio pese a que la bitacora no admite mas memoria en este instante.',
    '2026-09-19T10:00:00Z'
));
assertCondition($fallo !== null, 'Si la bitacora rechaza la memoria, el decreto entero fracasa');
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM sovereign_decrees WHERE id = 'dec_atomico'")->fetchColumn() === 0,
    'El decreto NO sobrevive al fallo de su bitacora: la transaccion se deshizo por completo'
);
assertCondition(
    (int) $pdo->query('SELECT COUNT(*) FROM sovereign_decrees')->fetchColumn() === $decreesAntes,
    'El censo de decretos queda intacto tras el fracaso'
);
assertCondition($auditCount($pdo) === $auditAntes, 'La bitacora tampoco admite una memoria huerfana');
$pdo->exec('DROP TRIGGER trg_arnes_sella_bitacora');
assertCondition(
    $repository->insertDecree('dec_atomico', 'spl_dudoso', 'usr_archivista', 'rescueToValidated', 'Se consagra de oficio tras retirarse el sello temporal del arnes.', '2026-09-19T11:00:00Z') !== null,
    'Retirado el sello, el mismo decreto se inscribe sin estorbo'
);

// --- FASE 5: Inmutabilidad de la memoria ---
echo "\nFASE 5: La memoria del decreto es inalterable (RNF-01, Articulo III)\n";
assertCondition(
    captureError(fn () => $pdo->exec("UPDATE audit_log SET justification = 'Enmienda indebida' WHERE id = 1")) !== null,
    'La bitacora rechaza toda enmienda: el edicto no puede reescribirse'
);
assertCondition(
    captureError(fn () => $pdo->exec('DELETE FROM audit_log WHERE id = 1')) !== null,
    'La bitacora rechaza toda purga: la memoria del decreto es eterna'
);
assertCondition(
    $pdo->query('SELECT justification FROM audit_log WHERE id = 1')->fetchColumn() === $EDICT,
    'El edicto original sigue en pie tras los dos intentos'
);
assertCondition(
    $pdo->query("SELECT justification FROM audit_log WHERE target_entity_id = 'spl_fraude' ORDER BY id ASC LIMIT 1")->fetchColumn()
    === $repository->findDecreeById('dec_uno')['imperial_decree_text'],
    'El edicto del decreto y el de la bitacora son literalmente el mismo texto: una sola palabra, dos moradas'
);
assertCondition(
    $repository->findDecreeById('dec_uno')['imperial_decree_text'] === $EDICT,
    'El edicto sigue siendo el pronunciado: nada lo ha enmendado por el camino'
);

// --- FASE 6: El catálogo de acciones ---
echo "\nFASE 6: El catalogo de acciones y su rotulo en castellano (RF-06.1, Art. IV)\n";
$entrySource = (string) file_get_contents($entryPath);
preg_match('/CANONICAL_ACTION_TYPES = \[(.*?)\];/s', $entrySource, $catalogMatch);
preg_match_all("/'([A-Z][A-Z0-9_]+)'/", $catalogMatch[1] ?? '', $catalogNames);
$canonicalActions = $catalogNames[1] ?? [];
assertCondition(count($canonicalActions) >= 28, 'La bitacora admite el catalogo entero de actos del santuario');

$moderationActs = [
    'MODERATION_SUBMITTED',
    'MODERATION_WITHDRAWN',
    'MODERATION_REOPENED',
    'SIGNATURE_RETRACTED',
    'SIGNATURE_ANNULMENT',
    'SPELL_CONSECRATED',
    'MODERATION_EXPIRED',
    'SOVEREIGN_VALIDATION',
    'SOVEREIGN_RESCUE',
    'SOVEREIGN_ARCHIVE',
];
foreach ($moderationActs as $act) {
    assertCondition(in_array($act, $canonicalActions, true), "El catalogo admite el acto de moderacion {$act}");
}
assertCondition(
    in_array('SIGN_VALIDATE', $canonicalActions, true) && in_array('SIGN_REJECT', $canonicalActions, true),
    'La firma de consagracion y el Dictamen de Objecion reutilizan los actos que SPEC-03 ya nombraba'
);
assertCondition(
    !in_array('MODERATION_OBLITERATED', $canonicalActions, true),
    'Un acto inventado sigue fuera del catalogo: la ampliacion no lo abre de par en par'
);
$entry = new Grimorio\Models\AuditEntry(0, 'usr_custodio', 'Custodio Supremo', 'supremeAdmin', 'SOVEREIGN_ARCHIVE', 'spell', 'spl_fraude', $EDICT, $NOW);
assertCondition(
    $entry->getActionType() === 'SOVEREIGN_ARCHIVE' && $entry->toNormalizedArray()['actionType'] === 'SOVEREIGN_ARCHIVE',
    'Una entrada con el acto soberano se instancia y se serializa con su nombre canonico'
);

// El rótulo: un acto sin nombre en castellano se imprimiría en la lengua del código.
$viewSource = (string) file_get_contents($viewPath);
preg_match('/function actionLabel\(actionType\) \{\s*const labels = \{(.*?)\n    \};/s', $viewSource, $labelMatch);
preg_match_all('/^\s*([A-Z][A-Z0-9_]+):/m', $labelMatch[1] ?? '', $labelNames);
$labelledActions = $labelNames[1] ?? [];
$sinRotulo = array_values(array_diff($canonicalActions, $labelledActions));
assertCondition(
    count($labelledActions) >= 28,
    'La Bitacora publica rotula al menos tantos actos como admite el catalogo'
);
assertCondition(
    $sinRotulo === [],
    'Todo acto del catalogo tiene su rotulo solemne en castellano (sin rotulos huerfanos: ' . implode(', ', array_slice($sinRotulo, 0, 4)) . ')'
);
assertCondition(
    str_contains($viewSource, "SOVEREIGN_ARCHIVE: 'Destierro Soberano del Canon'"),
    'El destierro soberano se lee en castellano en la Bitacora publica'
);

// --- FASE 7: Binding y auditoría estática ---
echo "\nFASE 7: Parameter binding y auditoria estatica del fuente\n";
preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $repositorySource, $literals);
$sqlLiterals = array_filter(
    $literals[1],
    static fn (string $literal): bool => preg_match('/\b(SELECT|INSERT INTO|UPDATE|DELETE FROM|FROM|WHERE|ORDER BY|JOIN)\b/', $literal) === 1
);
$interpolatedLiterals = array_values(
    array_filter($sqlLiterals, static fn (string $literal): bool => str_contains($literal, '$'))
);
assertCondition($interpolatedLiterals === [], 'Ningun literal SQL interpola una variable del llamador (parameter binding)');
assertCondition(substr_count($repositorySource, '->prepare(') >= 7, 'Todo acceso al motor pasa por sentencias preparadas');
assertCondition(
    substr_count($repositorySource, 'execute(') >= 7,
    'Cada sentencia preparada viaja con sus parametros vinculados'
);
$hostil = "x'); DROP TABLE sovereign_decrees; -- mas texto para superar el umbral";
$decreeHostil = $repository->insertDecree('dec_hostil', 'spl_dudoso', 'usr_custodio', 'rescueToValidated', $hostil, '2026-09-20T10:00:00Z');
assertCondition($decreeHostil['imperial_decree_text'] === $hostil, 'Un edicto hostil se guarda LITERAL: el valor viaja como dato');
assertCondition(
    (int) $pdo->query('SELECT COUNT(*) FROM sovereign_decrees')->fetchColumn() > 0,
    'La tabla del conclave sobrevive al intento de inyeccion (AGENTS.md 6.1)'
);
assertCondition(
    $pdo->query("SELECT justification FROM audit_log WHERE created_at = '2026-09-20T10:00:00Z'")->fetchColumn() === $hostil,
    'La memoria del decreto hostil tambien se guarda literal, sin interpolacion alguna'
);

echo "\n== RESUMEN == Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";
if ($assertsFailed === 0) {
    echo "RESULTADO: EXITO — Cada Decreto Imperial se persiste con su edicto y deja su registro inmutable en la Bitacora (Tarea 1.4).\n";
    exit(0);
}

echo "RESULTADO: FALLO — Corregir los asertos en rojo antes de continuar.\n";
exit(1);
