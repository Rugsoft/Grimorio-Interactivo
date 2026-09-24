<?php

declare(strict_types=1);

/**
 * test_lineage_oath_repository.php — Verificación de la Tarea 1.3 de TASKS-09.
 *
 * Valida el repositorio del juramento (`LineageOathRepository`):
 *
 * El criterio «Hecho cuando» de la tarea se comprueba en tres frentes:
 *   1. Todos los accesos usan prepare()/execute() con binding: auditoría
 *      estática del fuente (cero concatenación de valores del llamador).
 *   2. `sealOathGuarded` retorna «sellado ahora» (1) o «ya linajada» (0)
 *      según rowCount, y un segundo sellado sobre cuenta linajada no
 *      muta nada.
 *   3. La lectura del canon sirve las 8 fichas con `hasActiveClans`
 *      derivado de los clanes activos reales.
 *
 * Fases:
 *   [0]  Superficie: el repositorio existe y declara la guardia atómica.
 *   [1]  Auditoría estática: PDO exclusivamente preparado.
 *   [2]  Lectura del estado: peregrino, linajado e inexistente.
 *   [3]  La guardia atómica: un vencedor y cero mutaciones para el resto.
 *   [4]  La muralla del canon: la base rechaza claves ajenas.
 *   [5]  El canon con `hasActiveClans` derivado.
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): PDO nativo en :memory:, sin librerías.
 *   - Art. V (Dualidad): identificadores en inglés; narrativa en castellano.
 *
 * Uso: php scratch/test_lineage_oath_repository.php
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

/** Construye el santuario canónico con el mundo sembrado. */
function forgeSanctuary(): PDO
{
    $projectRoot = dirname(__DIR__);
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
    $pdo->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));

    return $pdo;
}

/** Inscribe un adepto de prueba en el santuario. */
function forgeAdept(PDO $pdo, string $id, string $alias, string $email, ?string $clanId, ?string $lineage): void
{
    $NOW = '2026-09-18T10:00:00Z';
    $statement = $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, created_at, updated_at)
         VALUES (:id, :alias, :email, \'x\', \'editor\', :clanId, :lineage, :now, :now)'
    );
    $statement->execute([
        ':id'      => $id,
        ':alias'   => $alias,
        ':email'   => $email,
        ':clanId'  => $clanId,
        ':lineage' => $lineage,
        ':now'     => $NOW,
    ]);
}

echo "== VERIFICACION TAREA 1.3: El repositorio del juramento ==\n\n";

$projectRoot = dirname(__DIR__);
$repositoryPath = $projectRoot . '/src/Repositories/LineageOathRepository.php';

// --- FASE 0: Superficie ---
echo "FASE 0: Superficie del repositorio\n";
assertCondition(file_exists($repositoryPath), 'Existe src/Repositories/LineageOathRepository.php');
if (!file_exists($repositoryPath)) {
    echo "\nRESULTADO: DENEGADO — falta el repositorio de la Tarea 1.3.\n";
    exit(1);
}
$repositorySource = (string) file_get_contents($repositoryPath);
assertCondition(str_contains($repositorySource, 'declare(strict_types=1);'), 'Tipado estricto obligatorio');
assertCondition(str_contains($repositorySource, 'WHERE id = :userId AND lineage IS NULL'), 'El sellado porta la GUARDIA ATÓMICA `WHERE lineage IS NULL` (RF-03.3, plan §2.2)');
assertCondition(str_contains($repositorySource, 'rowCount()'), 'El veredicto de carrera viaja por `rowCount` (sellado ahora / ya linajada)');

// --- FASE 1: Auditoría estática — PDO exclusivamente preparado ---
echo "\nFASE 1: Auditoria estatica — PDO exclusivamente preparado (criterio)\n";
// La única forma legítima de SQL con piezas externas es el nombre de
// columna interpolado desde listas cerradas del propio repositorio; aquí
// no existe ni eso: cualquier comilla doble o triple cadena con
// interpolación del llamador sería una concatenación encubierta.
$hasInterpolation = (bool) preg_match('/"(?:[^"$]|\\$\\{?)ares/', $repositorySource)
    || str_contains($repositorySource, '" . $') || str_contains($repositorySource, "' . $");
assertCondition(!$hasInterpolation, 'Cero concatenación de variables en el SQL: todo viaja por binding');
$preparedCount = substr_count($repositorySource, '->prepare(');
$execDirectCount = preg_match_all('/->exec\\(\\s*\\$/', $repositorySource);
assertCondition($preparedCount === 5, 'Las cinco consultas del repositorio nacen preparadas (halladas: ' . $preparedCount . ')');
assertCondition($execDirectCount === 0, 'Ninguna ejecución directa de SQL construido en tiempo de ejecución');

// --- FASE 2: Lectura del estado de la cuenta ---
echo "\nFASE 2: Lectura del estado de la cuenta (findAccountLineage)\n";
require_once $repositoryPath;
$pdo = forgeSanctuary();
$repository = new Grimorio\Repositories\LineageOathRepository($pdo);

forgeAdept($pdo, 'usr_peregrina', 'Peregrina del Velo', 'peregrina@arcano.arc', null, null);
forgeAdept($pdo, 'usr_jurada', 'Jurada de la Llama', 'jurada@arcano.arc', 'cln_primordial', 'primordialFlame');

assertCondition($repository->findAccountLineage('usr_peregrina') === null, 'La peregrina lee null: retención activa (RF-01.3)');
assertCondition($repository->findAccountLineage('usr_jurada') === 'primordialFlame', 'La linajada lee su juramento');
assertCondition($repository->findAccountLineage('usr_fantasma') === null, 'Una cuenta inexistente también lee null (el servicio distingue)');
assertCondition($repository->accountExists('usr_peregrina') && !$repository->accountExists('usr_fantasma'), '`accountExists` distingue peregrino de inexistente');

// --- FASE 3: La guardia atómica ---
echo "\nFASE 3: La guardia atómica del sellado (sealOathGuarded)\n";
$victory = $repository->sealOathGuarded('usr_peregrina', 'celestialTides', '2026-09-18T11:00:00Z');
assertCondition($victory === 1, 'El primer sellado vence: rowCount = 1 (sellado ahora)');
assertCondition($repository->findAccountLineage('usr_peregrina') === 'celestialTides', 'El juramento quedó inscrito en la cuenta');

$stamp = $repository->sealOathGuarded('usr_peregrina', 'celestialTides', '2026-09-18T12:00:00Z');
assertCondition($stamp === 0, 'Un segundo sellado del MISMO linaje no muta nada: rowCount = 0 (la guardia corta)');

$conflict = $repository->sealOathGuarded('usr_peregrina', 'solarCrown', '2026-09-18T12:30:00Z');
assertCondition($conflict === 0, 'Un sellado de linaje DISTINTO tampoco muta: rowCount = 0 (conflicto solemne)');
assertCondition($repository->findAccountLineage('usr_peregrina') === 'celestialTides', 'El vínculo permanece perpetuo: ni re-sello ni cambio lo alteran (RF-03.4)');

// La carrera real: dos juramentos sobre la misma peregrina. El guardia
// del UPDATE determina un único vencedor dentro de la misma transacción.
$carrera = forgeSanctuary();
forgeAdept($carrera, 'usr_indecisa', 'Indecisa del Umbral', 'indecisa@arcano.arc', null, null);
$firstWins = null;
$carrera->beginTransaction();
$firstWins = (new Grimorio\Repositories\LineageOathRepository($carrera))->sealOathGuarded('usr_indecisa', 'primordialFlame', '2026-09-18T11:00:00Z') === 1;
$secondLoses = (new Grimorio\Repositories\LineageOathRepository($carrera))->sealOathGuarded('usr_indecisa', 'abyssalShadows', '2026-09-18T11:00:01Z') === 0;
$carrera->commit();
assertCondition($firstWins && $secondLoses, 'Dos juramentos entrelazados: UN vencedor determinista y el perdedor con rowCount = 0');
assertCondition((new Grimorio\Repositories\LineageOathRepository($carrera))->findAccountLineage('usr_indecisa') === 'primordialFlame', 'La cuenta porta exactamente el linaje del vencedor, jamás una mezcla');

// --- FASE 4: La muralla del canon en la base ---
echo "\nFASE 4: La muralla del canon (el CHECK es la última instáncia)\n";
$otro = forgeSanctuary();
forgeAdept($otro, 'usr_nueva', 'Nueva Sin Umbral', 'nueva@arcano.arc', null, null);
assertCondition(
    captureError(static fn () => (new Grimorio\Repositories\LineageOathRepository($otro))->sealOathGuarded('usr_nueva', 'dracoStorm', '2026-09-18T11:00:00Z')) !== null,
    'Un linaje ajeno al canon es rechazado por la base: el repositorio jamás valida solo (única validación en el servicio, caso límite 5)'
);
assertCondition(
    captureError(static fn () => (new Grimorio\Repositories\LineageOathRepository($otro))->sealOathGuarded('usr_inexistente', 'solarCrown', '2026-09-18T11:00:00Z')) === null
    && (new Grimorio\Repositories\LineageOathRepository($otro))->sealOathGuarded('usr_inexistente', 'solarCrown', '2026-09-18T11:00:00Z') === 0,
    'Sellado sobre cuenta inexistente: sin excepción y sin filas mutadas (rowCount = 0)'
);

// --- FASE 5: El canon con hasActiveClans derivado ---
echo "\nFASE 5: El canon ceremonial con hasActiveClans derivado (RF-02.1)\n";
$catalogo = $repository->findOathCatalog();
assertCondition(count($catalogo) === 8, 'El canon sirve las OCHO fichas canónicas');
$primera = $catalogo[0];
assertCondition(
    $primera['id'] === 'primordialFlame'
    && isset($primera['name'], $primera['glyph'], $primera['banner_color'], $primera['ruling_element'], $primera['doctrine_condensed'], $primera['doctrine_full'], $primera['has_active_clans']),
    'La ficha porta todas las claves del contrato (id, name, glyph, banner_color, ruling_element, doctrinas, has_active_clans)'
);
assertCondition((int) $primera['has_active_clans'] === 1, 'La Llama Primordial guarda clanes activos: las semillas del Custodio visten sin la nota');
assertCondition(
    (bool) array_reduce($catalogo, static fn (bool $carry, array $ficha): bool => $carry && str_starts_with((string) $ficha['doctrine_full'], (string) $ficha['doctrine_condensed']), true),
    'Las 8 fichas derivan su condensada de la íntegra: un solo texto canónico'
);
$ordenCanonico = ['primordialFlame', 'celestialTides', 'eternalTempest', 'worldRoots', 'dawnWinds', 'solarCrown', 'abyssalShadows', 'aetherWeavers'];
$idsServidos = array_map(static fn (array $ficha): string => (string) $ficha['id'], $catalogo);
assertCondition($idsServidos === $ordenCanonico, 'El canon viaja en el orden ceremonial de las semillas (position ASC)');

// La bandera DERIVA de los clanes reales: archivando la única hermandad
// de un linaje, su ficha pierde el «Sí» sin tocar `lineage_doctrines`.
$otro->exec("UPDATE clans SET status = 'archived' WHERE id = 'cln_primordial'");
$canonArchivado = (new Grimorio\Repositories\LineageOathRepository($otro))->findOathCatalog();
$llama = array_values(array_filter($canonArchivado, static fn (array $ficha): bool => $ficha['id'] === 'primordialFlame'))[0];
assertCondition((int) $llama['has_active_clans'] === 0, 'Archivada la hermandad, la bandera deriva a 0: nota «Sin hermandades activas» en la ceremonia');
assertCondition(
    (int) $otro->query("SELECT COUNT(*) FROM lineage_doctrines WHERE id = 'primordialFlame'")->fetchColumn() === 1,
    'El canon permanece inmutable: la bandera es derivada, jamás una columna administrada (exclusión 5)'
);
$aislada = (new Grimorio\Repositories\LineageOathRepository($otro))->hasActiveClans('primordialFlame');
assertCondition($aislada === false, 'La lectura aislada `hasActiveClans` coincide con la bandera de la ficha');

echo "\n== RESUMEN == Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";
if ($assertsFailed === 0) {
    echo "RESULTADO: EXITO — El repositorio del juramento mide y persiste con guardia atómica: un vencedor, idempotencia por re-evaluación y canon con bandera derivada (Tarea 1.3).\n";
    exit(0);
}

echo "RESULTADO: DENEGADO — Corregir los asertos en rojo antes de continuar.\n";
exit(1);
