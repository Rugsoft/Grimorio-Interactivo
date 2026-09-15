<?php

declare(strict_types=1);

/**
 * test_moderation_signature_repositories.php — Verificación de la Tarea 1.3 de TASKS-08.
 *
 * Valida contra el criterio «Hecho cuando» de
 * specs/08-moderation-two-step.tasks.md:
 *   «findActiveSignatures() devuelve solo las firmas con is_revoked = 0, y
 *    findLatestVerdictBySpell() retorna el texto íntegro de la última
 *    objeción formulada por un Maestro.»
 *
 * Estrategia: se levanta una base SQLite efímera con la secuencia canónica
 * (database/schema.sql + database/seeds.sql + sql/08_moderation_schema.sql) y
 * se ejercitan los dos repositorios contra el contrato real del esquema de la
 * Tarea 1.1. Los asertos de «solo las vivas» y «texto íntegro» se comprueban
 * además CONTRA LA BASE, no solo contra el valor devuelto: la fila revocada ha
 * de seguir existiendo con su motivo, y el dictamen ha de conservar su
 * puntuación carácter por carácter.
 *
 * Fases:
 *   [0]  Superficie: los dos módulos existen, declaran strict_types, publican
 *        sus constantes canónicas y sus métodos, sin dependencias externas.
 *   [1]  Estampado de la Firma de Consagración (insertSignature).
 *   [2]  Unicidad del aval vivo por Maestro y conjuro (RF-02.4).
 *   [3]  findActiveSignatures() devuelve SOLO las firmas vivas (criterio).
 *   [4]  findActiveSignaturesByMaster(): el censo del firmante (RF-03.4/03.5).
 *   [5]  Revocación con memoria: motivo canónico, idempotencia y rastro.
 *   [6]  Anulación en bloque de los avales previos (RF-02.6).
 *   [7]  El contador 0/3 y la pluralidad de hermandades (RF-02.1).
 *   [8]  El Dictamen de Objeción: umbral de veinte caracteres (RF-02.5).
 *   [9]  findLatestVerdictBySpell() devuelve el texto ÍNTEGRO (criterio).
 *   [10] Inmutabilidad del dictamen, binding ante entrada hostil y auditoría
 *        estática del fuente.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sin librerías externas.
 *   - Artículo V (Dualidad): identificadores en inglés snake_case; narrativa
 *     y comentarios en noble castellano.
 *
 * Uso: php scratch/test_moderation_signature_repositories.php
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

/** ¿Qué valor devuelve este cierre, o null si lanza excepción? */
function attempt(callable $operation): mixed
{
    try {
        return $operation();
    } catch (Throwable) {
        return null;
    }
}

echo "== VERIFICACION TAREA 1.3: Firmas de Consagracion y Dictamenes de Objecion ==\n\n";

$projectRoot = dirname(__DIR__);
$signaturePath = $projectRoot . '/src/Repositories/MasterSignatureRepository.php';
$verdictPath = $projectRoot . '/src/Repositories/ObjectionVerdictRepository.php';

// --- FASE 0: Superficie de los módulos ---
echo "FASE 0: Superficie de los modulos\n";
assertCondition(file_exists($signaturePath), 'Existe src/Repositories/MasterSignatureRepository.php');
assertCondition(file_exists($verdictPath), 'Existe src/Repositories/ObjectionVerdictRepository.php');

if (!file_exists($signaturePath) || !file_exists($verdictPath)) {
    echo "\nRESULTADO: FALLO — faltan los repositorios de la Tarea 1.3 (fase roja del TDD).\n";
    exit(1);
}

$signatureSource = (string) file_get_contents($signaturePath);
$verdictSource = (string) file_get_contents($verdictPath);

foreach (['MasterSignatureRepository' => $signatureSource, 'ObjectionVerdictRepository' => $verdictSource] as $className => $source) {
    $filePath = $className === 'MasterSignatureRepository' ? $signaturePath : $verdictPath;
    $head = implode('', array_slice(file($filePath, FILE_IGNORE_NEW_LINES) ?: [], 0, 40));
    assertCondition(
        str_contains($head, 'declare(strict_types=1);'),
        "{$className} declara strict_types en las primeras 40 lineas (checklist AGENTS.md)"
    );
    assertCondition(
        str_contains($source, 'namespace Grimorio\\Repositories;'),
        "{$className} habita el espacio de nombres canonico del santuario"
    );
    assertCondition(
        preg_match('#^use (?!Grimorio)[A-Z]#m', $source) === 1
        && preg_match('#https?://#', $source) !== 1,
        "{$className} solo depende de la biblioteca estandar de PHP: cero dependencias externas (Articulo I)"
    );

    // Auditoría de binding: todo el texto SQL vive en literales y ningún
    // literal SQL interpola una variable del llamador.
    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $source, $literals);
    $sqlLiterals = array_filter(
        $literals[1],
        static fn (string $literal): bool => preg_match('/\b(SELECT|INSERT INTO|UPDATE|DELETE FROM|FROM|WHERE|ORDER BY|JOIN)\b/', $literal) === 1
    );
    $interpolatedLiterals = array_values(
        array_filter($sqlLiterals, static fn (string $literal): bool => str_contains($literal, '$'))
    );
    assertCondition(
        $interpolatedLiterals === [],
        "{$className}: ningun literal SQL interpola una variable del llamador (parameter binding)"
    );
    assertCondition(
        substr_count($source, '->prepare(') >= 4,
        "{$className}: todo acceso al motor pasa por sentencias preparadas"
    );
}

foreach (['insertSignature', 'findActiveSignatures', 'findActiveSignaturesByMaster', 'revokeSignature'] as $methodName) {
    assertCondition(
        str_contains($signatureSource, "function {$methodName}("),
        "MasterSignatureRepository declara el metodo canonico {$methodName}()"
    );
}
foreach (['insertVerdict', 'findLatestVerdictBySpell'] as $methodName) {
    assertCondition(
        str_contains($verdictSource, "function {$methodName}("),
        "ObjectionVerdictRepository declara el metodo canonico {$methodName}()"
    );
}
assertCondition(
    str_contains($signatureSource, 'MAX_GLOSS_LENGTH = 250'),
    'Publica el techo de 250 caracteres de la glosa ceremonial (RF-02.2)'
);
assertCondition(
    str_contains($verdictSource, 'MIN_OBJECTION_REASON_LENGTH = 20'),
    'Publica el umbral de 20 caracteres del Dictamen de Objecion (RF-02.5)'
);
assertCondition(
    str_contains($signatureSource, "REVOCATION_RETRACTED = 'retracted'")
    && str_contains($signatureSource, "REVOCATION_CLAN_CONFLICT_ARISEN = 'clan_conflict_arisen'")
    && str_contains($signatureSource, "REVOCATION_RANK_LOST = 'rank_lost'")
    && str_contains($signatureSource, "REVOCATION_AUTHOR_WITHDRAWN = 'author_withdrawn'")
    && str_contains($signatureSource, "REVOCATION_SOVEREIGN_ARCHIVE = 'sovereign_archive'"),
    'Publica los cinco motivos canonicos de revocacion que declara el DDL de la Tarea 1.1'
);
assertCondition(
    str_contains($verdictSource, 'function insertVerdict(')
    && !str_contains($verdictSource, 'function update')
    && !str_contains($verdictSource, 'function delete'),
    'El dictamen no ofrece metodo alguno de mutacion: una objecion pronunciada es historia (Art. IV)'
);

require_once $signaturePath;
require_once $verdictPath;
$signatureClass = 'Grimorio\\Repositories\\MasterSignatureRepository';
$verdictClass = 'Grimorio\\Repositories\\ObjectionVerdictRepository';

// Base de datos SQLite efímera en memoria: jamás contamina el santuario real.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON;');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
$pdo->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));
$pdo->exec((string) file_get_contents($projectRoot . '/sql/08_moderation_schema.sql'));

/** @var MasterSignatureRepository $signatures */
$signatures = new $signatureClass($pdo);
/** @var ObjectionVerdictRepository $verdicts */
$verdicts = new $verdictClass($pdo);

$NOW = '2026-09-15T10:00:00Z';
$GLOSS = 'Por la pureza del fulgor solar y la armonía de su invocación.';

// Semilla: dos hermandades, tres Maestros y tres obras.
// `cln_primordial` llega con las semillas; las otras dos casas se inscriben
// aquí porque la pluralidad de RF-02.1 exige firmantes de hermandades distintas.
foreach ([
    'cln_marea'  => ['marea-de-aether', 'Marea de Aether', 'celestialTides', 'rune_tide_spiral'],
    'cln_bosque' => ['raices-del-mundo', 'Raíces del Mundo', 'worldRoots', 'rune_world_root'],
] as $clanId => [$slug, $name, $lineage, $coat]) {
    $statement = $pdo->prepare(
        'INSERT INTO clans (id, slug, name, motto, created_at, coat_of_arms, lineage_type,
                            admission_mode, status, weekly_points, historical_points, last_activity_at, updated_at)
         VALUES (:id, :slug, :name, :motto, :now, :coat, :lineage, :admission, :status, 0, 0, :now, :now)'
    );
    $statement->execute([
        ':id'        => $clanId,
        ':slug'      => $slug,
        ':name'      => $name,
        ':motto'     => 'La marea no olvida a quien la invoca.',
        ':now'       => $NOW,
        ':coat'      => $coat,
        ':lineage'   => $lineage,
        ':admission' => 'byApplication',
        ':status'    => 'active',
    ]);
}

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

$forgeUser($pdo, 'usr_autora', 'Autora de la Marea', 'editor', 'cln_marea');
$forgeUser($pdo, 'usr_maestro_marea', 'Maestro de la Marea', 'master', 'cln_marea');
$forgeUser($pdo, 'usr_maestro_bosque', 'Maestro del Bosque', 'master', 'cln_bosque');
$forgeUser($pdo, 'usr_maestro_ermitano', 'Maestro Ermitaño', 'master', null);

/** Inscribe un conjuro con la huella canónica de 64 caracteres. */
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
        ':affinity'    => 'water',
        ':clanId'      => 'cln_marea',
        ':summary'     => 'Obra en deliberacion para el arnes de las firmas.',
        ':fingerprint' => str_repeat('b', 64),
        ':status'      => 'experimental',
        ':now'         => $NOW,
    ]);
};

$forgeSpell($pdo, 'spl_marea', 'usr_autora');
$forgeSpell($pdo, 'spl_bosque', 'usr_autora');
$forgeSpell($pdo, 'spl_tercera', 'usr_autora');

// --- FASE 1: Estampado de la Firma de Consagración ---
echo "\nFASE 1: Estampado de la Firma de Consagracion\n";
$first = $signatures->insertSignature('sig_uno', 'spl_marea', 'usr_maestro_bosque', $NOW, 'cln_bosque', $GLOSS);
assertCondition($first !== null && $first['id'] === 'sig_uno', 'La firma se inscribe con su identificador (RF-02.1)');
assertCondition($first['spell_id'] === 'spl_marea' && $first['master_id'] === 'usr_maestro_bosque', 'Retiene el conjuro avalado y su firmante');
assertCondition($first['master_clan_id'] === 'cln_bosque', 'Retrata el clan del Maestro EN EL INSTANTE de firmar (Art. III)');
assertCondition($first['ceremonial_gloss'] === $GLOSS, 'Conserva integra la glosa ceremonial (RF-02.2)');
assertCondition($first['signed_at'] === $NOW, 'Sella el instante que le pasa el llamante (RNF-01)');
assertCondition($first['is_revoked'] === false && $first['revoked_at'] === null, 'La firma nace VIVA: is_revoked = 0');

$sinGlosa = $signatures->insertSignature('sig_dos', 'spl_marea', 'usr_maestro_ermitano', $NOW, null, null);
assertCondition($sinGlosa['ceremonial_gloss'] === null, 'La glosa es opcional: RF-02.2 la permite, no la exige');
assertCondition($sinGlosa['master_clan_id'] === null, 'Un Maestro ermitano firma sin linaje (RF-02.1 admite ermitanos)');

$glosaEnBlanco = $signatures->insertSignature('sig_tres', 'spl_bosque', 'usr_maestro_ermitano', $NOW, null, '   ');
assertCondition(
    $glosaEnBlanco['ceremonial_gloss'] === null,
    'Una glosa en blanco se guarda como ausencia, no como cadena vacia'
);
assertCondition(
    captureError(fn () => $signatures->insertSignature('sig_x', 'spl_tercera', 'usr_maestro_bosque', $NOW, 'cln_bosque', str_repeat('a', 251))) !== null,
    'Una glosa de 251 caracteres es rechazada antes de tocar la base (RF-02.2)'
);
$glosaExacta = $signatures->insertSignature('sig_cuatro', 'spl_tercera', 'usr_maestro_bosque', $NOW, 'cln_bosque', str_repeat('a', 250));
assertCondition($glosaExacta !== null, 'Una glosa de exactamente 250 caracteres es admitida: el techo se mide con rigor');
assertCondition(
    captureError(fn () => $signatures->insertSignature('sig_x', 'spl_inexistente', 'usr_maestro_bosque', $NOW)) !== null,
    'Una firma sobre un conjuro inexistente es rechazada: la clave foranea NO se confunde con la unicidad'
);
assertCondition(
    str_contains(
        (string) captureError(fn () => $signatures->insertSignature('sig_x', 'spl_inexistente', 'usr_maestro_bosque', $NOW)),
        'FOREIGN KEY'
    ),
    'La violacion de clave foranea no se traduce a null: la leyenda del motor nombra la FOREIGN KEY'
);
assertCondition(
    captureError(fn () => $signatures->insertSignature('sig_x', 'spl_tercera', 'usr_maestro_marea', $NOW, 'cln_fantasma')) !== null,
    'Un clan que no existe es rechazado por la clave foranea (Art. III: el linaje retratado ha de ser real)'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM master_signatures WHERE id = 'sig_x'")->fetchColumn() === 0,
    'Ninguna firma invalida quedo inscrita: el rechazo es real y no cosmetico'
);

// --- FASE 2: Unicidad del aval vivo (RF-02.4) ---
echo "\nFASE 2: Un solo aval VIVO por Maestro y conjuro\n";
assertCondition(
    $signatures->insertSignature('sig_duplicada', 'spl_marea', 'usr_maestro_bosque', '2026-09-16T10:00:00Z', 'cln_bosque') === null,
    'Una segunda firma viva del mismo Maestro sobre el mismo conjuro NO se inscribe: devuelve null, no una excepcion (RF-02.4)'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_marea' AND master_id = 'usr_maestro_bosque'")->fetchColumn() === 1,
    'La base conserva UNA sola firma del Maestro sobre la obra'
);
assertCondition(
    $signatures->insertSignature('sig_otra_obra', 'spl_bosque', 'usr_maestro_bosque', $NOW, 'cln_bosque') !== null,
    'El mismo Maestro firma sin estorbo otro conjuro distinto'
);
assertCondition($signatures->revokeSignature('sig_uno', 'retracted', '2026-09-17T10:00:00Z') === true, 'El Maestro se retracta de su firma (RF-02.4)');
assertCondition(
    $signatures->insertSignature('sig_reestampada', 'spl_marea', 'usr_maestro_bosque', '2026-09-18T10:00:00Z', 'cln_bosque') !== null,
    'Retractada la firma, el Maestro VUELVE a poder avalar la obra: la unicidad es parcial'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_marea' AND master_id = 'usr_maestro_bosque' AND is_revoked = 0")->fetchColumn() === 1,
    'Solo una de las dos filas del Maestro permanece viva'
);
assertCondition(
    $signatures->insertSignature('sig_tercer_maestro', 'spl_marea', 'usr_maestro_marea', $NOW, 'cln_marea') !== null,
    'Un tercer Maestro, de hermandad distinta, avala la misma obra'
);

// --- FASE 3: findActiveSignatures devuelve SOLO las vivas (criterio) ---
echo "\nFASE 3: findActiveSignatures devuelve SOLO las firmas vivas (criterio 'Hecho cuando')\n";
$vivas = $signatures->findActiveSignatures('spl_marea');
$idsVivos = array_column($vivas, 'id');
assertCondition(count($vivas) === 3, 'La obra avalada por tres Maestros devuelve sus TRES firmas vivas y ninguna caida');
assertCondition(
    !in_array('sig_uno', $idsVivos, true)
    && in_array('sig_dos', $idsVivos, true)
    && in_array('sig_reestampada', $idsVivos, true)
    && in_array('sig_tercer_maestro', $idsVivos, true),
    'La firma RETRACTADA queda fuera del censo y las vivas entran (is_revoked = 0)'
);
assertCondition(
    array_reduce($vivas, static fn (bool $alive, array $row): bool => $alive && $row['is_revoked'] === false, true),
    'Ninguna firma del censo activo trae is_revoked verdadero'
);
assertCondition(
    $idsVivos === ['sig_dos', 'sig_tercer_maestro', 'sig_reestampada'],
    'El censo se ordena por signed_at y, a igualdad de milesima, por identificador: orden determinista (RNF-01)'
);
assertCondition(
    $signatures->findActiveSignatures('spl_inexistente') === [],
    'Una obra sin avales devuelve un censo vacio, no un error'
);
assertCondition(
    $signatures->findActiveSignature('spl_marea', 'usr_maestro_marea')['id'] === 'sig_tercer_maestro',
    'La firma viva de un Maestro concreto sobre una obra se localiza por su par canonico'
);
assertCondition(
    $signatures->findActiveSignature('spl_marea', 'usr_maestro_bosque')['id'] === 'sig_reestampada',
    'Tras la retractacion, el par (obra, Maestro) resuelve a la firma NUEVA, no a la caida'
);
assertCondition(
    $signatures->findSignatureById('sig_uno')['is_revoked'] === true
    && (int) $pdo->query("SELECT COUNT(*) FROM master_signatures WHERE id = 'sig_uno'")->fetchColumn() === 1,
    'La firma revocada SIGUE EN LA BASE: la revocacion deja memoria, no borra (RF-02.4)'
);

// --- FASE 4: El censo del firmante ---
echo "\nFASE 4: findActiveSignaturesByMaster, el censo del firmante (RF-03.4, RF-03.5)\n";
$censoBosque = $signatures->findActiveSignaturesByMaster('usr_maestro_bosque');
assertCondition(count($censoBosque) === 3, 'El Maestro del Bosque mantiene TRES avales vivos sobre obras distintas');
assertCondition(
    array_column($censoBosque, 'spell_id') === ['spl_tercera', 'spl_bosque', 'spl_marea'],
    'El censo cruza conjuros y va ordenado por instante —y por identificador a igualdad—: la lectura del suscriptor de eventos'
);
assertCondition(
    array_reduce($censoBosque, static fn (bool $alive, array $row): bool => $alive && $row['is_revoked'] === false, true),
    'El censo del firmante solo trae firmas vivas'
);
assertCondition(
    count($signatures->findActiveSignaturesByMaster('usr_maestro_marea')) === 1
    && count($signatures->findActiveSignaturesByMaster('usr_autor_inexistente')) === 0,
    'Un Maestro con un solo aval lo ve, y un usuario sin firma alguna recibe el censo vacio'
);

// --- FASE 5: Revocación con memoria ---
echo "\nFASE 5: La revocacion deja motivo y no se reescribe\n";
$filasAntes = (int) $pdo->query('SELECT COUNT(*) FROM master_signatures')->fetchColumn();
assertCondition(
    captureError(fn () => $signatures->revokeSignature('sig_tercer_maestro', 'porqueSi', $NOW)) !== null,
    'Un motivo de revocacion fuera del canon es rechazado antes de tocar la base'
);
assertCondition(
    $signatures->revokeSignature('sig_tercer_maestro', 'clan_conflict_arisen', '2026-09-20T10:00:00Z') === true,
    'La nulidad constitucional sobrevenida revoca la firma de oficio (RF-03.4)'
);
$caida = $signatures->findSignatureById('sig_tercer_maestro');
assertCondition($caida['is_revoked'] === true && $caida['revoked_at'] === '2026-09-20T10:00:00Z', 'La revocacion sella su instante');
assertCondition($caida['revocation_reason'] === 'clan_conflict_arisen', 'La revocacion conserva el motivo canonico');
assertCondition(
    $signatures->revokeSignature('sig_tercer_maestro', 'rank_lost', '2026-09-21T10:00:00Z') === false
    && $signatures->findSignatureById('sig_tercer_maestro')['revocation_reason'] === 'clan_conflict_arisen',
    'Revocar dos veces la misma firma devuelve false y NO reescribe el motivo original: idempotencia'
);
assertCondition(
    $signatures->revokeSignature('sig_inexistente', 'retracted', $NOW) === false,
    'Revocar una firma que no existe devuelve false sin levantar excepcion'
);
assertCondition(
    (int) $pdo->query('SELECT COUNT(*) FROM master_signatures')->fetchColumn() === $filasAntes,
    'El censo de filas no ha menguado con ninguna revocacion: la memoria es integra'
);

// --- FASE 6: Anulación en bloque (RF-02.6) ---
echo "\nFASE 6: Anulacion en bloque de los avales previos (RF-02.6)\n";
$anuladas = $signatures->revokeActiveSignaturesForSpell('spl_marea', 'author_withdrawn', '2026-09-22T10:00:00Z');
assertCondition($anuladas === 2, 'La obra conservaba DOS avales vivos —el retractado ya cayo— y la anulacion los cuenta');
assertCondition($signatures->findActiveSignatures('spl_marea') === [], 'Tras la anulacion, la obra queda sin aval alguno (N -> 0)');
assertCondition(
    $signatures->revokeActiveSignaturesForSpell('spl_marea', 'author_withdrawn', '2026-09-22T10:00:00Z') === 0,
    'Anular de nuevo no vuelve a contar las ya caidas: la operacion es idempotente'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_marea' AND is_revoked = 1")->fetchColumn() === 4,
    'Los CUATRO avales de la obra quedan revocados con su rastro en la base (la retractada incluida)'
);
assertCondition(
    captureError(fn () => $signatures->revokeActiveSignaturesForSpell('spl_marea', 'motivoInventado', $NOW)) !== null,
    'La anulacion en bloque tambien exige un motivo canonico'
);

// --- FASE 7: El contador 0/3 y la pluralidad ---
echo "\nFASE 7: El contador 0/3 y la pluralidad de hermandades (RF-02.1)\n";
assertCondition($signatures->countActiveSignatures('spl_tercera') === 1, 'La obra avalada una vez cuenta uno: indicador 1/3');
assertCondition($signatures->countActiveSignatures('spl_bosque') === 2, 'La obra avalada dos veces cuenta dos: indicador 2/3');
assertCondition($signatures->countActiveSignatures('spl_marea') === 0, 'La obra anulada cuenta cero: el Atrio la devuelve a 0/3');
assertCondition(
    $signatures->countActiveSignatures('spl_inexistente') === 0,
    'Una obra sin avales cuenta cero sin consultar fila alguna'
);
$clanesFirmantes = array_column($signatures->findActiveSignatures('spl_bosque'), 'master_clan_id');
assertCondition(
    count($clanesFirmantes) === 2 && $clanesFirmantes[0] !== $clanesFirmantes[1],
    'Los dos avales de la obra proceden de hermandades distintas: pluralidad satisfecha (RF-02.1)'
);
assertCondition(
    $signatures->insertSignature('sig_ermitano_dos', 'spl_tercera', 'usr_maestro_ermitano', '2026-09-23T10:00:00Z', null, null) !== null,
    'Un segundo ermitano sin clan se admite como firmante independiente (RF-02.1)'
);
assertCondition(
    $signatures->countActiveSignatures('spl_tercera') === 2
    && $signatures->findActiveSignatures('spl_tercera')[1]['master_clan_id'] === null,
    'La firma del ermitano engrosa el contador de la obra sin aportar clan'
);

// --- FASE 8: El Dictamen de Objeción ---
echo "\nFASE 8: El Dictamen de Objecion y su umbral de veinte caracteres (RF-02.5)\n";
$verdict = $verdicts->insertVerdict('obj_uno', 'spl_tercera', 'usr_maestro_bosque', $GLOSS, $NOW);
assertCondition($verdict['id'] === 'obj_uno' && $verdict['spell_id'] === 'spl_tercera', 'El dictamen se inscribe con su conjuro');
assertCondition($verdict['master_id'] === 'usr_maestro_bosque', 'El dictamen conserva a su firmante');
assertCondition($verdict['objection_reason'] === $GLOSS, 'El dictamen conserva la justificacion integra');
assertCondition($verdict['objected_at'] === $NOW, 'El dictamen sella el instante que le pasa el llamante (RNF-01)');
assertCondition(
    captureError(fn () => $verdicts->insertVerdict('obj_x', 'spl_tercera', 'usr_maestro_bosque', str_repeat('a', 19), $NOW)) !== null,
    'Una justificacion de 19 caracteres es rechazada antes de tocar la base (RF-02.5)'
);
assertCondition(
    captureError(fn () => $verdicts->insertVerdict('obj_x', 'spl_tercera', 'usr_maestro_bosque', str_repeat(' ', 40), $NOW)) !== null,
    'Cuarenta espacios no son una justificacion: el umbral se mide sobre el texto recortado'
);
assertCondition(
    $verdicts->insertVerdict('obj_borde', 'spl_bosque', 'usr_maestro_bosque', 'Falta coherencia ya!', $NOW) !== null,
    'Una justificacion de exactamente 20 caracteres es admitida: el umbral se mide con rigor'
);
assertCondition(
    $verdicts->insertVerdict('obj_acentuado', 'spl_bosque', 'usr_maestro_marea', 'Nótese la herejía arcana.', '2026-09-15T11:00:00Z') !== null,
    'Las tildes del castellano no consumen el cupo del dictamen: se cuentan caracteres, no bytes'
);
assertCondition(
    captureError(fn () => $verdicts->insertVerdict('obj_x', 'spl_inexistente', 'usr_maestro_bosque', $GLOSS, $NOW)) !== null,
    'Un dictamen contra un conjuro inexistente es rechazado por la clave foranea'
);

// --- FASE 9: findLatestVerdictBySpell devuelve el texto ÍNTEGRO (criterio) ---
echo "\nFASE 9: findLatestVerdictBySpell devuelve el texto INTEGRO (criterio 'Hecho cuando')\n";
$ultimo = $verdicts->findLatestVerdictBySpell('spl_bosque');
assertCondition($ultimo !== null && $ultimo['id'] === 'obj_acentuado', 'De dos dictámenes sobre la misma obra devuelve el mas reciente');
assertCondition(
    $ultimo['objection_reason'] === 'Nótese la herejía arcana.',
    'El texto vuelve INTEGRO, con sus tildes y su puntuacion, sin truncar ni normalizar (RF-06.2, Art. IV)'
);
assertCondition(
    mb_strlen($ultimo['objection_reason']) === mb_strlen((string) $pdo->query("SELECT objection_reason FROM objection_verdicts WHERE id = 'obj_acentuado'")->fetchColumn()),
    'La longitud devuelta coincide caracter a caracter con la guardada en la base'
);
$historico = $verdicts->findVerdictsBySpell('spl_bosque');
assertCondition(
    count($historico) === 2 && array_column($historico, 'id') === ['obj_borde', 'obj_acentuado'],
    'El historial de una obra devuelve sus dictámenes de la mas antigua a la mas reciente (RF-06.2)'
);
assertCondition(
    $verdicts->findLatestVerdictBySpell('spl_marea') === null,
    'Una obra jamas objetada devuelve null, no un dictamen en blanco'
);
assertCondition(
    $verdicts->findLatestVerdictBySpell('spl_bosque')['spell_id'] === 'spl_bosque',
    'La ultima objecion pertenece siempre a la obra consultada'
);
assertCondition(
    array_column($verdicts->findVerdictsByMaster('usr_maestro_bosque'), 'id') === ['obj_uno', 'obj_borde'],
    'El censo de vetos de un Maestro se ordena del mas reciente al mas antiguo y, a igualdad de milesima, por identificador (RF-06.1)'
);
assertCondition(
    $verdicts->findVerdictById('obj_uno')['id'] === 'obj_uno' && $verdicts->findVerdictById('obj_inexistente') === null,
    'Un dictamen se recupera por su identificador y su ausencia no es un error'
);

// --- FASE 10: Inmutabilidad, binding y auditoría estática ---
echo "\nFASE 10: Inmutabilidad del dictamen, binding y auditoria estatica\n";
$before = (int) $pdo->query('SELECT COUNT(*) FROM objection_verdicts')->fetchColumn();
assertCondition($before === 3, 'Tres dictamenes inscritos y ninguno recortado por el camino');
$firmasAntes = (int) $pdo->query('SELECT COUNT(*) FROM master_signatures')->fetchColumn();
assertCondition(
    !method_exists($verdictClass, 'updateVerdict')
    && !method_exists($verdictClass, 'deleteVerdict')
    && !method_exists($verdictClass, 'revokeVerdict'),
    'El repositorio de dictámenes no expone mutacion alguna: la objecion es historia (Art. IV)'
);
$hostil = "x'); DROP TABLE master_signatures; --";
$verdictHostil = attempt(fn () => $verdicts->insertVerdict('obj_hostil', 'spl_tercera', 'usr_maestro_bosque', $hostil, $NOW));
assertCondition(
    $verdictHostil !== null && $verdictHostil['objection_reason'] === $hostil,
    'Una justificacion hostil se guarda LITERAL: el valor viaja como dato, jamas como SQL'
);
assertCondition(
    (int) $pdo->query('SELECT COUNT(*) FROM master_signatures')->fetchColumn() === $firmasAntes,
    'La tabla vecina sobrevive al intento de inyeccion: parameter binding inviolable (AGENTS.md 6.1)'
);
$firmaHostil = $signatures->insertSignature("sig'); DROP TABLE objection_verdicts; --", 'spl_bosque', 'usr_maestro_marea', $NOW);
assertCondition(
    $firmaHostil !== null && str_contains($firmaHostil['id'], 'DROP TABLE'),
    'Un identificador hostil de firma se guarda literal y no amplia la busqueda'
);
assertCondition(
    (int) $pdo->query('SELECT COUNT(*) FROM objection_verdicts')->fetchColumn() === $before + 1,
    'El otro ausente del ataque sigue en pie: las tablas del conclave no caen ante una entrada hostil'
);
assertCondition(
    preg_match('/\.\s*\$\w+\s*\.\s*[\'"]/', $signatureSource) !== 1
    && substr_count($signatureSource, 'execute(') >= 5,
    'La auditoria estatica del fuente no encuentra SQL ensamblado con variables'
);

echo "\n== RESUMEN == Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";
if ($assertsFailed === 0) {
    echo "RESULTADO: EXITO — Las firmas de consagracion se persisten sin perder memoria y el dictamen de objecion se conserva integro (Tarea 1.3).\n";
    exit(0);
}

echo "RESULTADO: FALLO — Corregir los asertos en rojo antes de continuar.\n";
exit(1);
