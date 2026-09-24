<?php

declare(strict_types=1);

/**
 * test_moderation_dtos.php — Verificación de la Tarea 2.1 de TASKS-08.
 *
 * Valida contra el criterio «Hecho cuando» de
 * specs/08-moderation-two-step.tasks.md:
 *   «Todos los DTOs se instancian con tipado estricto y su codificación con
 *    json_encode() produce las estructuras JSON normalizadas definidas en los
 *    contratos del plan.»
 *
 * Estrategia: se levanta una base SQLite efímera con la secuencia canónica
 * (database/schema.sql + database/seeds.sql + sql/08_moderation_schema.sql) y
 * se hidratan los cinco DTOs desde filas reales del esquema de la Tarea 1.1,
 * comparando su `json_encode()` clave a clave contra el contrato del plan. El
 * canon (estados, decretos, umbrales) se cruza a TRES bandas —DTO,
 * repositorio y CHECK de la base— para que ninguna de las tres pueda divergir
 * de las otras en silencio.
 *
 * Fases:
 *   [0]  Superficie: los cinco ficheros existen, declaran strict_types, son
 *        `final readonly`, implementan JsonSerializable y viven en Grimorio\Dto.
 *   [1]  Tipado estricto: los tipos declarados muerden (TypeError) y los
 *        constructores rechazan cuanto rompe el canon.
 *   [2]  Contrato JSON: el mapa de claves camelCase del plan y su orden.
 *   [3]  Hidratación desde la base: cada fromDatabaseRow refleja su fila.
 *   [4]  Cruz del canon a tres bandas (DTO ↔ repositorio ↔ CHECK de la base).
 *   [5]  El elemento de la cola de la Torre (RF-05.4).
 *   [6]  Auditoría estática del fuente: cero dependencias y claves camelCase.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sin librerías externas.
 *   - Artículos II, III y IV: se vigilan los umbrales y la memoria.
 *   - Artículo V (Dualidad): claves camelCase, narrativa en castellano.
 *
 * Uso: php scratch/test_moderation_dtos.php
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

/** ¿Lanza este cierre de excepción? Devuelve la clase y el mensaje, o null. */
function captureError(callable $operation): ?string
{
    try {
        $operation();

        return null;
    } catch (Throwable $failure) {
        return $failure::class . ': ' . $failure->getMessage();
    }
}

echo "== VERIFICACION TAREA 2.1: DTOs inmutables del dominio de moderacion ==\n\n";

$projectRoot = dirname(__DIR__);
$dtoFiles = [
    'SpellReviewDto'        => $projectRoot . '/src/Dto/SpellReviewDto.php',
    'MasterSignatureDto'    => $projectRoot . '/src/Dto/MasterSignatureDto.php',
    'ObjectionVerdictDto'   => $projectRoot . '/src/Dto/ObjectionVerdictDto.php',
    'ImperialDecreeDto'     => $projectRoot . '/src/Dto/ImperialDecreeDto.php',
    'ModerationQueueItemDto' => $projectRoot . '/src/Dto/ModerationQueueItemDto.php',
];

// --- FASE 0: Superficie del módulo ---
echo "FASE 0: Superficie de los cinco DTOs\n";
foreach ($dtoFiles as $dtoName => $dtoPath) {
    assertCondition(file_exists($dtoPath), "Existe src/Dto/{$dtoName}.php");

    if (!file_exists($dtoPath)) {
        continue;
    }

    $source = (string) file_get_contents($dtoPath);
    $head = implode('', array_slice(file($dtoPath, FILE_IGNORE_NEW_LINES) ?: [], 0, 45));

    assertCondition(str_contains($head, 'declare(strict_types=1);'), "{$dtoName}: declara strict_types (checklist AGENTS.md)");
    assertCondition(str_contains($source, 'namespace Grimorio\\Dto;'), "{$dtoName}: habita el espacio de nombres Grimorio\\Dto");
    assertCondition(str_contains($source, "final readonly class {$dtoName}"), "{$dtoName}: es final readonly (RNF-01: el retrato no se muta)");
    assertCondition(str_contains($source, 'implements JsonSerializable'), "{$dtoName}: implementa JsonSerializable nativo");
    assertCondition(
        preg_match('#^use (?!Grimorio)[A-Z]#m', $source) === 1
        && preg_match('#https?://#', $source) !== 1,
        "{$dtoName}: solo depende de la biblioteca estandar de PHP (Articulo I)"
    );
}

foreach ($dtoFiles as $dtoPath) {
    if (file_exists($dtoPath)) {
        require_once $dtoPath;
    }
}
foreach (['SpellReviewDto', 'MasterSignatureDto', 'ObjectionVerdictDto', 'ImperialDecreeDto', 'ModerationQueueItemDto'] as $className) {
    assertCondition(class_exists('Grimorio\\Dto\\' . $className), "La clase Grimorio\\Dto\\{$className} es cargable");
}

$reviewClass = 'Grimorio\\Dto\\SpellReviewDto';
$signatureClass = 'Grimorio\\Dto\\MasterSignatureDto';
$objectionClass = 'Grimorio\\Dto\\ObjectionVerdictDto';
$decreeClass = 'Grimorio\\Dto\\ImperialDecreeDto';
$queueClass = 'Grimorio\\Dto\\ModerationQueueItemDto';

$FINGERPRINT = str_repeat('a', 64);
$NOW = '2026-09-15T10:00:00Z';

// --- FASE 1: Tipado estricto e invariantes de constructor ---
echo "\nFASE 1: Tipado estricto y guardas del constructor\n";

$review = new $reviewClass(
    id: 'rev_uno',
    spellId: 'spl_uno',
    authorId: 'usr_autora',
    status: 'experimental',
    signaturesCount: 2,
    mathFingerprint: $FINGERPRINT,
    originClanId: 'cln_primordial',
    submittedAt: $NOW,
);
assertCondition($review->id === 'rev_uno' && $review->signaturesCount === 2, 'Un expediente canonico se instancia con tipado estricto');
assertCondition($review->signaturesIndicator() === '2/3', 'El indicador ceremonial es 2/3 (RF-05.1)');
assertCondition($review->isUnderDeliberation() === true && $review->isConsecrated() === false, 'La obra sigue en deliberacion');
assertCondition(
    $reviewClass::SIGNATURES_REQUIRED === 3 && $reviewClass::FINGERPRINT_LENGTH === 64,
    'Publica el techo de tres firmas y la huella SHA-256 como fuente unica del canon'
);

assertCondition(
    str_starts_with((string) captureError(static fn () => new $reviewClass(id: 42, spellId: 'spl_uno', authorId: 'usr_autora')), 'TypeError'),
    'Un identificador entero es rechazado por el tipado estricto (TypeError)'
);
assertCondition(
    str_starts_with((string) captureError(static fn () => new $queueClass(spellId: 'spl_uno', spellName: 'Obra', authorId: 'usr_autora', signaturesCount: '0')), 'TypeError'),
    'Un conteo de firmas textual es rechazado por el tipado estricto (TypeError)'
);
assertCondition(
    str_starts_with((string) captureError(static fn () => new $signatureClass(id: 'sig_uno', spellId: 'spl_uno', masterId: 'usr_maestro', isRevoked: 1)), 'TypeError'),
    'Un booleano entero es rechazado por el tipado estricto (TypeError)'
);

assertCondition(
    captureError(static fn () => new $reviewClass(id: '', spellId: 'spl_uno', authorId: 'usr_autora')) !== null,
    'Ningun expediente se forja sin identificador'
);
assertCondition(
    str_contains((string) captureError(static fn () => new $reviewClass(id: 'rev', spellId: 'spl', authorId: 'usr', status: 'canonizado')), 'canon'),
    'Un estado ajeno al canon es rechazado nombrando los cinco de RF-01.1'
);
assertCondition(
    captureError(static fn () => new $reviewClass(id: 'rev', spellId: 'spl', authorId: 'usr', signaturesCount: 4)) !== null,
    'Una cuarta firma es rechazada: el techo de RF-02.1 es infranqueable'
);
assertCondition(
    captureError(static fn () => new $reviewClass(id: 'rev', spellId: 'spl', authorId: 'usr', mathFingerprint: str_repeat('b', 63))) !== null,
    'Una huella truncada es rechazada: sin huella de 64 caracteres no hay juicio (Art. II)'
);

assertCondition(
    (new $signatureClass(id: 'sig', spellId: 'spl', masterId: 'usr', ceremonialGloss: str_repeat('g', 250)))->hasCeremonialGloss() === true,
    'Una glosa de exactamente 250 caracteres es admitida (RF-02.2)'
);
assertCondition(
    captureError(static fn () => new $signatureClass(id: 'sig', spellId: 'spl', masterId: 'usr', ceremonialGloss: str_repeat('g', 251))) !== null,
    'Una glosa de 251 caracteres es rechazada (RF-02.2)'
);
assertCondition(
    (new $signatureClass(id: 'sig', spellId: 'spl', masterId: 'usr', masterClanId: null))->isNeutralHermit() === true,
    'El Maestro sin clan es reconocido como ermitano neutral (caso limite 3)'
);
assertCondition(
    captureError(static fn () => new $signatureClass(id: 'sig', spellId: 'spl', masterId: 'usr', isRevoked: false, revocationReason: 'retracted')) !== null,
    'Una firma viva no puede portar motivo de revocacion'
);
assertCondition(
    captureError(static fn () => new $signatureClass(id: 'sig', spellId: 'spl', masterId: 'usr', isRevoked: true)) !== null,
    'Toda firma revocada declara su instante (RNF-01)'
);
assertCondition(
    (new $signatureClass(id: 'sig', spellId: 'spl', masterId: 'usr', isRevoked: true, revokedAt: $NOW, revocationReason: 'clan_conflict_arisen'))->isActive() === false,
    'Una firma anulada de oficio deja de contar para el techo de tres (RF-03.4)'
);

assertCondition(
    (new $objectionClass(id: 'obj', spellId: 'spl', masterId: 'usr', objectionReason: str_repeat('x', 20)))->reasonLength() === 20,
    'Una justificacion de exactamente veinte caracteres es admitida (RF-02.5)'
);
assertCondition(
    captureError(static fn () => new $objectionClass(id: 'obj', spellId: 'spl', masterId: 'usr', objectionReason: str_repeat('x', 19))) !== null,
    'Una justificacion de diecinueve caracteres es rechazada (RF-02.5)'
);
assertCondition(
    captureError(static fn () => new $objectionClass(id: 'obj', spellId: 'spl', masterId: 'usr', objectionReason: str_repeat(' ', 40))) !== null,
    'Cuarenta espacios no son una justificacion: el umbral se mide sobre el texto recortado'
);

assertCondition(
    (new $decreeClass(id: 'dec', spellId: 'spl', adminId: 'usr_admin', decreeType: 'rescueToValidated', imperialDecreeText: str_repeat('e', 20)))->rescuesSpell() === true,
    'El decreto de rescate se reconoce por su tipo canonico (RF-04.2)'
);
assertCondition(
    (new $decreeClass(id: 'dec', spellId: 'spl', adminId: 'usr_admin', decreeType: 'revokeAndArchive', imperialDecreeText: str_repeat('e', 20)))->archivesSpell() === true,
    'El decreto de archivo se reconoce por su tipo canonico (RF-04.3)'
);
assertCondition(
    str_contains((string) captureError(static fn () => new $decreeClass(id: 'dec', spellId: 'spl', adminId: 'usr_admin', decreeType: 'soberbia', imperialDecreeText: str_repeat('e', 20))), 'canon'),
    'Un decreto ajeno al canon supremo es rechazado'
);
assertCondition(
    captureError(static fn () => new $decreeClass(id: 'dec', spellId: 'spl', adminId: 'usr_admin', imperialDecreeText: str_repeat('e', 19))) !== null,
    'Todo decreto exige Edicto Imperial de al menos veinte caracteres (RF-04.5)'
);

assertCondition(
    captureError(static fn () => new $queueClass(spellId: 'spl', spellName: 'Obra', authorId: 'usr', signaturesCount: 4)) !== null,
    'Ningun elemento de la cola exhibe una cuarta rubrica'
);
assertCondition(
    (new $queueClass(spellId: 'spl', spellName: 'Obra', authorId: 'usr', hasEthicalConflict: true))->isEligibleForSignature() === false,
    'El conflicto etico veda la firma al Maestro consultante (Art. III)'
);
assertCondition(
    (new $queueClass(spellId: 'spl', spellName: 'Obra', authorId: 'usr'))->isHermitWork() === true,
    'La obra de un ermitano se reconoce por su clan ausente'
);

// --- FASE 2: El contrato JSON del plan ---
echo "\nFASE 2: Contrato JSON normalizado
";

$contracts = [
    $reviewClass => ['id', 'spellId', 'authorId', 'originClanId', 'status', 'signaturesCount', 'signaturesRequired', 'signaturesIndicator', 'mathFingerprint', 'submittedAt', 'validatedAt', 'rejectedAt', 'reopenedAt', 'archivedAt'],
    $signatureClass => ['id', 'spellId', 'masterId', 'masterClanId', 'ceremonialGloss', 'signedAt', 'isRevoked', 'revokedAt', 'revocationReason', 'isActive'],
    $objectionClass => ['id', 'spellId', 'masterId', 'objectionReason', 'reasonLength', 'objectedAt'],
    $decreeClass => ['id', 'spellId', 'adminId', 'decreeType', 'imperialDecreeText', 'decreedAt'],
    $queueClass => ['spellId', 'spellSlug', 'spellName', 'authorId', 'authorAlias', 'originClanId', 'originClanName', 'elementalAffinity', 'magicSchool', 'signaturesCount', 'signaturesRequired', 'signaturesIndicator', 'hasEthicalConflict', 'submittedAt', 'waitingDays'],
];

$instances = [
    $reviewClass => $review,
    $signatureClass => new $signatureClass(id: 'sig_uno', spellId: 'spl_uno', masterId: 'usr_maestro', masterClanId: 'cln_primordial', ceremonialGloss: 'Por la pureza del fulgor solar.', signedAt: $NOW),
    $objectionClass => new $objectionClass(id: 'obj_uno', spellId: 'spl_uno', masterId: 'usr_maestro', objectionReason: 'La descripcion presenta anacronismos manifiestos.', objectedAt: $NOW),
    $decreeClass => new $decreeClass(id: 'dec_uno', spellId: 'spl_uno', adminId: 'usr_admin', imperialDecreeText: 'Por mandato del Conclave Supremo, esta obra se consagra de oficio.', decreedAt: $NOW),
    $queueClass => new $queueClass(spellId: 'spl_uno', spellName: 'Obra Uno', authorId: 'usr_autora', authorAlias: 'Autora Primera', signaturesCount: 1, elementalAffinity: 'fire', magicSchool: 'evocation', originClanId: 'cln_primordial', originClanName: 'Custodios del Fuego Primordial', submittedAt: $NOW),
];

foreach ($contracts as $dtoName => $expectedKeys) {
    $instance = $instances[$dtoName];
    $payload = $instance->jsonSerialize();
    $encoded = json_encode($instance, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $decoded = json_decode((string) $encoded, true);
    $shortName = substr((string) strrchr($dtoName, '\\'), 1);

    assertCondition(array_keys($payload) === $expectedKeys, "{$shortName}: el mapa de claves camelCase coincide con el contrato del plan y en su orden");
    assertCondition($decoded === $payload, "{$shortName}: json_encode() round-trips sin transformacion adicional");
    assertCondition(json_last_error() === JSON_ERROR_NONE, "{$shortName}: json_encode() codifica sin error");
    assertCondition(
        array_filter($expectedKeys, static fn (string $key): bool => str_contains($key, '_')) === [],
        "{$shortName}: ninguna clave filtra snake_case al contrato publico (RNF-05)"
    );
}

assertCondition(
    json_encode($instances[$reviewClass], JSON_UNESCAPED_UNICODE) === '{"id":"rev_uno","spellId":"spl_uno","authorId":"usr_autora","originClanId":"cln_primordial","status":"experimental","signaturesCount":2,"signaturesRequired":3,"signaturesIndicator":"2\/3","mathFingerprint":"' . $FINGERPRINT . '","submittedAt":"' . $NOW . '","validatedAt":null,"rejectedAt":null,"reopenedAt":null,"archivedAt":null}',
    'El expediente codificado es EXACTAMENTE la estructura normalizada del contrato'
);
assertCondition(
    json_encode($instances[$queueClass]) === '{"spellId":"spl_uno","spellSlug":null,"spellName":"Obra Uno","authorId":"usr_autora","authorAlias":"Autora Primera","originClanId":"cln_primordial","originClanName":"Custodios del Fuego Primordial","elementalAffinity":"fire","magicSchool":"evocation","signaturesCount":1,"signaturesRequired":3,"signaturesIndicator":"1\/3","hasEthicalConflict":false,"submittedAt":"' . $NOW . '","waitingDays":null}',
    'El elemento de la cola codificado es EXACTAMENTE la tarjeta de la Torre de Deliberacion (RF-05.4)'
);

// --- FASE 3: Hidratacion desde la base ---
echo "\nFASE 3: Hidratacion desde filas reales del esquema
";

$databasePath = $projectRoot . '/scratch/moderation_dtos.sqlite';
@unlink($databasePath);
$connection = new PDO('sqlite:' . $databasePath);
$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$connection->exec('PRAGMA foreign_keys = ON;');
$connection->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
$connection->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));
$connection->exec((string) file_get_contents($projectRoot . '/sql/08_moderation_schema.sql'));

$connection->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
                   VALUES ('usr_autora', 'Autora Primera', 'autora@arcano.arc', 'x', 'editor', 'cln_primordial', '{$NOW}', '{$NOW}')");
$connection->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
                   VALUES ('usr_maestro', 'Maestro Vigilante', 'maestro@arcano.arc', 'x', 'master', 'cln_primordial', '{$NOW}', '{$NOW}')");
$connection->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
                   VALUES ('usr_admin', 'Administrador Supremo', 'admin@arcano.arc', 'x', 'supremeAdmin', NULL, '{$NOW}', '{$NOW}')");
$connection->exec("INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, mana_cost, clan_id, summary, math_fingerprint, created_at, updated_at)
                   VALUES ('spl_uno', 'obra-uno', 'Obra Uno', 'usr_autora', 'evocation', 'fire', 20, 'cln_primordial', 'Resumen de la obra.', '{$FINGERPRINT}', '{$NOW}', '{$NOW}')");
$connection->exec("INSERT INTO spell_reviews (id, spell_id, author_id, origin_clan_id, status, signatures_count, math_fingerprint, submitted_at)
                   VALUES ('rev_uno', 'spl_uno', 'usr_autora', 'cln_primordial', 'experimental', 1, '{$FINGERPRINT}', '{$NOW}')");
$connection->exec("INSERT INTO master_signatures (id, spell_id, master_id, master_clan_id, ceremonial_gloss, signed_at, is_revoked)
                   VALUES ('sig_viva', 'spl_uno', 'usr_maestro', 'cln_primordial', 'Por la pureza del fulgor solar y la armonia de su invocacion.', '{$NOW}', 0)");
$connection->exec("INSERT INTO master_signatures (id, spell_id, master_id, master_clan_id, ceremonial_gloss, signed_at, is_revoked, revoked_at, revocation_reason)
                   VALUES ('sig_muerta', 'spl_uno', 'usr_admin', NULL, NULL, '{$NOW}', 1, '{$NOW}', 'retracted')");
$connection->exec("INSERT INTO objection_verdicts (id, spell_id, master_id, objection_reason, objected_at)
                   VALUES ('obj_uno', 'spl_uno', 'usr_maestro', 'La descripcion lirica presenta anacronismos manifiestos que vulneran el Velo Arcano.', '{$NOW}')");
$connection->exec("INSERT INTO sovereign_decrees (id, spell_id, admin_id, decree_type, imperial_decree_text, decreed_at)
                   VALUES ('dec_uno', 'spl_uno', 'usr_admin', 'sovereignValidation', 'Por mandato del Conclave Supremo, esta obra es consagrada de oficio.', '{$NOW}')");

$fetchRow = static function (PDO $connection, string $sql, array $parameters): array {
    $statement = $connection->prepare($sql);
    $statement->execute($parameters);

    return (array) $statement->fetch(PDO::FETCH_ASSOC);
};

$hydratedReview = $reviewClass::fromDatabaseRow($fetchRow($connection, 'SELECT * FROM spell_reviews WHERE id = :id', [':id' => 'rev_uno']));
assertCondition($hydratedReview->spellId === 'spl_uno' && $hydratedReview->status === 'experimental', 'El expediente se hidrata desde `spell_reviews` con su conjuro y su estado');
assertCondition($hydratedReview->signaturesCount === 1 && $hydratedReview->signaturesIndicator() === '1/3', 'El contador de firmas viaja desde la base al indicador ceremonial');
assertCondition($hydratedReview->mathFingerprint === $FINGERPRINT, 'La huella sellada se retrata tal cual, sin recalculo (Art. II)');

$liveSignature = $signatureClass::fromDatabaseRow($fetchRow($connection, 'SELECT * FROM master_signatures WHERE id = :id', [':id' => 'sig_viva']));
assertCondition($liveSignature->isActive() === true && $liveSignature->hasCeremonialGloss() === true, 'La firma viva se hidrata con su glosa liturgica');
assertCondition(mb_strlen((string) $liveSignature->ceremonialGloss, 'UTF-8') === 61, 'La glosa conserva sus 61 caracteres, sin recorte ni relleno (Art. IV)');
$revoquedSignature = $signatureClass::fromDatabaseRow($fetchRow($connection, 'SELECT * FROM master_signatures WHERE id = :id', [':id' => 'sig_muerta']));
assertCondition($revoquedSignature->isRevoked === true && $revoquedSignature->revocationReason === 'retracted', 'La firma revocada se conserva con su motivo (RF-02.4, RNF-01)');
assertCondition($revoquedSignature->revokedAt === $NOW, 'El instante de la revocacion viaja a la Bitacora');

$hydratedObjection = $objectionClass::fromDatabaseRow($fetchRow($connection, 'SELECT * FROM objection_verdicts WHERE id = :id', [':id' => 'obj_uno']));
assertCondition($hydratedObjection->reasonLength() === 84, 'La objecion se retrata integra, con sus 84 caracteres y su puntuacion (RF-06.2)');
assertCondition(
    $hydratedObjection->objectionReason === 'La descripcion lirica presenta anacronismos manifiestos que vulneran el Velo Arcano.',
    'El texto de la objecion vuelve caracter a caracter, sin mutilar'
);

$hydratedDecree = $decreeClass::fromDatabaseRow($fetchRow($connection, 'SELECT * FROM sovereign_decrees WHERE id = :id', [':id' => 'dec_uno']));
assertCondition($hydratedDecree->consecratesSpell() === true && $hydratedDecree->decreeType === 'sovereignValidation', 'El decreto se hidrata con su tipo canonico (RF-04.1)');
assertCondition($hydratedDecree->imperialDecreeText === 'Por mandato del Conclave Supremo, esta obra es consagrada de oficio.', 'El Edicto Imperial vuelve integro (RF-04.5)');

$queueRow = $fetchRow(
    $connection,
    'SELECT r.spell_id, s.slug, s.name, r.author_id, u.alias AS author_alias, r.origin_clan_id, c.name AS origin_clan_name, s.elemental_affinity, s.magic_school, r.signatures_count, r.submitted_at
       FROM spell_reviews r
       JOIN spells s ON s.id = r.spell_id
       JOIN users u ON u.id = r.author_id
       LEFT JOIN clans c ON c.id = r.origin_clan_id
      WHERE r.spell_id = :spellId AND r.status = :status',
    [':spellId' => 'spl_uno', ':status' => 'experimental']
);
$queueItem = $queueClass::fromDatabaseRow($queueRow, true, new DateTimeImmutable('2026-09-25T10:00:00Z'));
assertCondition($queueItem->spellName === 'Obra Uno' && $queueItem->spellSlug === 'obra-uno', 'La cola resuelve el nombre y el enlace directo de la obra');
assertCondition($queueItem->authorAlias === 'Autora Primera' && $queueItem->originClanName === 'Custodios del Fuego Primordial', 'La cola resuelve el alias del autor y el nombre del linaje (RF-05.4)');
assertCondition($queueItem->hasEthicalConflict === true && $queueItem->isEligibleForSignature() === false, 'El veredicto etico calculado en servidor veda la firma (RF-03.1)');
assertCondition($queueItem->waitingDays === 10, 'La antiguedad se mide contra el instante inyectado: diez dias de espera (RF-05.4)');
assertCondition($queueItem->signaturesIndicator() === '1/3', 'La cola exhibe el indicador de firmas del expediente');

$connection = null;
@unlink($databasePath);
assertCondition(!file_exists($databasePath), 'La base efimera del arnes se retira: jamas contamina el santuario');

// --- FASE 4: Cruz del canon a tres bandas ---
echo "\nFASE 4: Cruz del canon (DTO vs repositorio vs CHECK de la base)
";

require_once $projectRoot . '/src/Repositories/SpellReviewRepository.php';
require_once $projectRoot . '/src/Repositories/MasterSignatureRepository.php';
require_once $projectRoot . '/src/Repositories/ImperialDecreeRepository.php';
// La pluma exige su contrato desde SPEC-11 (Fase 2): se carga antes del
// servicio, como hace el autoloader del front controller.
require_once $projectRoot . '/src/Services/AuditRecorderInterface.php';
require_once $projectRoot . '/src/Services/AuditService.php';

$sqlSchema = (string) file_get_contents($projectRoot . '/database/schema.sql');
$sqlModeration = (string) file_get_contents($projectRoot . '/sql/08_moderation_schema.sql');

/**
 * Extrae la lista cerrada de un CHECK `columna IN (...)` del DDL.
 *
 * Aisla primero el bloque de la tabla nombrada: `status` existe en varias
 * tablas del esquema (clans, moderation_requests, spells, spell_reviews) y
 * leer la primera aparición del fichero devolvería el canon ajeno en lugar
 * del propio.
 */
$readCheckList = static function (string $sqlText, string $tableName, string $columnName): array {
    if (preg_match('#CREATE TABLE IF NOT EXISTS ' . $tableName . ' \((.*?)\n\);#s', $sqlText, $block) !== 1) {
        return [];
    }
    $sqlText = $block[1];
    if (preg_match('#CHECK \\(' . $columnName . ' IN \\(([^)]*)\\)\\)#', $sqlText, $match) !== 1) {
        return [];
    }
    preg_match_all("/'([a-zA-Z_]+)'/", $match[1], $values);

    return $values[1];
};

$reviewRepositoryClass = 'Grimorio\\Repositories\\SpellReviewRepository';
$signatureRepositoryClass = 'Grimorio\\Repositories\\MasterSignatureRepository';
$decreeRepositoryClass = 'Grimorio\\Repositories\\ImperialDecreeRepository';

$databaseStatuses = $readCheckList($sqlModeration, 'spell_reviews', 'status');
$reviewStatuses = $reviewClass::CANONICAL_STATUSES;
$repositoryStatuses = $reviewRepositoryClass::CANONICAL_STATUSES;
assertCondition($databaseStatuses === $reviewStatuses, 'Los cinco estados del DTO coinciden nombre a nombre con el CHECK de `spell_reviews`');
assertCondition($repositoryStatuses === $reviewStatuses, 'Los cinco estados del DTO coinciden con el canon del repositorio');
assertCondition(
    $readCheckList($sqlSchema, 'spells', 'status') === $reviewStatuses,
    'El espejo de `spells` admite los mismos cinco estados: un solo contador de ciclo de vida (RF-01.1)'
);

$databaseDecreeTypes = $readCheckList($sqlModeration, 'sovereign_decrees', 'decree_type');
assertCondition($databaseDecreeTypes === $decreeClass::CANONICAL_DECREE_TYPES, 'Los cuatro decretos del DTO coinciden con el CHECK de `sovereign_decrees`');
assertCondition(
    $decreeRepositoryClass::CANONICAL_DECREE_TYPES === $decreeClass::CANONICAL_DECREE_TYPES,
    'Los cuatro decretos del DTO coinciden con el catalogo del repositorio (RF-04)'
);
assertCondition(
    $decreeRepositoryClass::MIN_IMPERIAL_DECREE_LENGTH === $decreeClass::IMPERIAL_DECREE_MIN_LENGTH,
    'El umbral del Edicto Imperial es uno solo: veinte caracteres (RF-04.5)'
);
assertCondition(
    preg_match('#CHECK \\(length\\(objection_reason\\) >= ' . $objectionClass::OBJECTION_REASON_MIN_LENGTH . '\\)#', $sqlModeration) === 1,
    'El umbral de la objecion del DTO coincide con el CHECK de la base (RF-02.5)'
);
assertCondition(
    preg_match('#CHECK \\(ceremonial_gloss IS NULL OR length\\(ceremonial_gloss\\) <= ' . $signatureClass::CEREMONIAL_GLOSS_MAX_LENGTH . '\\)#', $sqlModeration) === 1,
    'El techo de la glosa ceremonial del DTO coincide con el CHECK de la base (RF-02.2)'
);
assertCondition(
    preg_match('#CHECK \\(signatures_count >= 0 AND signatures_count <= ' . $reviewClass::SIGNATURES_REQUIRED . '\\)#', $sqlModeration) === 1,
    'El techo de tres firmas del DTO coincide con el CHECK de la base (RF-02.1)'
);
assertCondition(
    $queueClass::SIGNATURES_REQUIRED === $reviewClass::SIGNATURES_REQUIRED,
    'La cola y el expediente comparten un solo techo de firmas'
);
assertCondition(
    $signatureClass::CANONICAL_REVOCATION_REASONS === ['retracted', 'clan_conflict_arisen', 'rank_lost', 'author_withdrawn', 'sovereign_archive', 'review_expired', 'review_rejected'],
    'El DTO publica las siete causas de revocacion: las cinco del esquema, el letargo de RF-01.6 y el veto de RF-02.6'
);
assertCondition(
    $signatureRepositoryClass::CANONICAL_REVOCATION_REASONS === $signatureClass::CANONICAL_REVOCATION_REASONS,
    'El catalogo de causas es uno solo: DTO y repositorio no divergen'
);

// --- FASE 5: El elemento de la cola de la Torre ---
echo "\nFASE 5: Elemento de la cola y sus casos limite
";

$hermitRow = [
    'spell_id'           => 'spl_ermitano',
    'slug'               => 'obra-ermitana',
    'name'               => 'Obra Ermitana',
    'author_id'          => 'usr_ermitano',
    'author_alias'       => 'Ermitano Neutral',
    'origin_clan_id'     => null,
    'origin_clan_name'   => null,
    'elemental_affinity' => 'none',
    'magic_school'       => 'abjuration',
    'signatures_count'   => 0,
    'submitted_at'       => null,
];
$hermitItem = $queueClass::fromDatabaseRow($hermitRow);
assertCondition($hermitItem->isHermitWork() === true && $hermitItem->originClanName === null, 'La obra de un ermitano viaja sin linaje y sin conflicto de hermandad');
assertCondition($hermitItem->signaturesIndicator() === '0/3' && $hermitItem->isUnsigned() === true, 'La obra huerfana exhibe 0/3 en el Atrio de Pruebas (RF-05.1)');
assertCondition($hermitItem->waitingDays === null && $hermitItem->hasEthicalConflict === false, 'Sin instante de consulta no se inventa antiguedad, y sin conflicto declarado nadie queda vetado');
assertCondition($hermitItem->jsonSerialize()['originClanId'] === null, 'El nulo canonico del ermitano se preserva en el JSON');

// --- FASE 6: Auditoria estatica del fuente ---
echo "\nFASE 6: Auditoria estatica de los cinco DTOs
";

foreach ($dtoFiles as $dtoName => $dtoPath) {
    if (!file_exists($dtoPath)) {
        continue;
    }
    $source = (string) file_get_contents($dtoPath);

    assertCondition(
        preg_match('/[\x{00e1}\x{00e9}\x{00ed}\x{00f3}\x{00fa}\x{00f1}\x{00bf}\x{00a1}]/u', $source) === 1,
        "{$dtoName}: la narrativa y los comentarios van en noble castellano (RNF-03, Articulo V)"
    );
    assertCondition(
        str_contains($source, 'function jsonSerialize(): array'),
        "{$dtoName}: declara la firma canonica de jsonSerialize()"
    );
    assertCondition(
        preg_match('/\$this->[a-zA-Z_]+ *= [^=]/', $source) !== 1 && substr_count($source, 'readonly') >= 1,
        "{$dtoName}: ninguna propiedad se reasigna tras el constructor (RNF-01)"
    );
    assertCondition(
        !str_contains($source, 'new PDO') && !str_contains($source, '\\$_GET') && !str_contains($source, '\\$_POST'),
        "{$dtoName}: el retrato no toca la base ni la peticion del mundo exterior"
    );
    assertCondition(
        preg_match_all("/'([a-z]+_[a-z_]+)' =>/i", $source, $snakeKeys) === 0,
        "{$dtoName}: ninguna clave snake_case asoma al contrato JSON (RNF-05)"
    );
}

// --- VEREDICTO ---
echo "\n== RESULTADO: {$assertsPassed} asertos pasados, {$assertsFailed} fallidos ==\n";
if ($assertsFailed === 0) {
    echo "TAREA 2.1 VERIFICADA: los cinco DTOs se instancian con tipado estricto y codifican\n";
    echo "EXACTAMENTE las estructuras JSON normalizadas de los contratos del plan.\n";
    exit(0);
}

echo "TAREA 2.1 NO VERIFICADA: revisense los asertos en rojo.\n";
exit(1);
