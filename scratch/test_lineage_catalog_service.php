<?php

declare(strict_types=1);

/**
 * test_lineage_catalog_service.php — Verificación de la Tarea 2.1 de TASKS-09.
 *
 * Valida el servicio del canon inmutable (`LineageCatalogService`) y sus
 * DTOs (`LineageProfileDto`, `LineageOathCatalogDto`):
 *
 * El criterio «Hecho cuando» de la tarea se comprueba en tres frentes:
 *   1. El servicio retorna exactamente 8 fichas con las claves del
 *      contrato del plan (§2.2, Endpoint 1).
 *   2. `hasActiveClans` refleja la existencia de clanes activos por
 *      linaje (derivada de `clans`, no almacenada).
 *   3. No existe método alguno de mutación en su API.
 *
 * Fases:
 *   [0]  Superficie: servicio y DTOs, con tipado estricto.
 *   [1]  Inmutabilidad estructural: la API pública no declara pluma.
 *   [2]  El contrato: 8 fichas, claves camelCase exactas, accountState.
 *   [3]  La bandera derivada: archivar la hermandad la apaga; el canon
 *        permanece.
 *   [4]  Coherencia de heráldica con SPEC-07 (fuente única).
 *   [5]  Los DTOs son la última muralla: ficha malformada no nace.
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): PDO nativo en :memory:, sin librerías.
 *   - Art. V (Dualidad): identificadores en inglés; narrativa en castellano.
 *
 * Uso: php scratch/test_lineage_catalog_service.php
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

echo "== VERIFICACION TAREA 2.1: El canon ceremonial inmutable ==\n\n";

$projectRoot = dirname(__DIR__);
$servicePath = $projectRoot . '/src/Services/LineageCatalogService.php';
$profileDtoPath = $projectRoot . '/src/Dto/LineageProfileDto.php';
$catalogDtoPath = $projectRoot . '/src/Dto/LineageOathCatalogDto.php';

// --- FASE 0: Superficie ---
echo "FASE 0: Superficie del servicio y sus DTOs\n";
$missing = array_filter([$servicePath, $profileDtoPath, $catalogDtoPath], static fn (string $path): bool => !file_exists($path));
assertCondition($missing === [], 'Existen el servicio y los dos DTOs de la tarea');
if ($missing !== []) {
    echo "\nRESULTADO: FALLO — faltan ficheros de la Tarea 2.1: " . implode(', ', $missing) . "\n";
    exit(1);
}
foreach ([$servicePath, $profileDtoPath, $catalogDtoPath] as $path) {
    assertCondition(str_contains((string) file_get_contents($path), 'declare(strict_types=1);'), 'Tipado estricto en ' . basename($path));
}

// --- FASE 1: Inmutabilidad estructural ---
echo "\nFASE 1: Inmutabilidad estructural (criterio: sin pluma en la API)\n";
$serviceSource = (string) file_get_contents($servicePath);
// El bloque público del servicio: solo debe declarar el constructor y
// la lectura. Cualquier método público con verbo de escritura rompe el
// canon (exclusión 5).
preg_match('/final class LineageCatalogService(.*)$/s', $serviceSource, $classBody);
$publicMethods = [];
if (preg_match_all('/public function (\w+)\(/', $classBody[1] ?? '', $matches) > 0) {
    $publicMethods = $matches[1];
}
assertCondition($publicMethods === ['__construct', 'getOathCatalog'], 'La API pública declara SOLO el constructor y la lectura: ' . implode(', ', $publicMethods));
$mutatingVerbs = ['save', 'update', 'delete', 'insert', 'create', 'seal', 'write', 'archive', 'replace', 'edit'];
$plumas = array_filter($publicMethods, static fn (string $method): bool => (bool) preg_match('/(' . implode('|', $mutatingVerbs) . ')/i', $method));
assertCondition($plumas === [], 'Ningún verbo de mutación vive en la API del canon (hallados: ' . implode(', ', $plumas) . ')');
assertCondition(!str_contains($serviceSource, 'UPDATE ') && !str_contains($serviceSource, 'INSERT INTO') && !str_contains($serviceSource, 'DELETE FROM'), 'El servicio no porta SQL de escritura alguno');

// --- FASE 2: El contrato ---
echo "\nFASE 2: El contrato del canon (plan §2.2, Endpoint 1)\n";
require_once $projectRoot . '/src/Repositories/LineageOathRepository.php';
require_once $profileDtoPath;
require_once $catalogDtoPath;
require_once $servicePath;

$pdo = forgeSanctuary();
$service = new Grimorio\Services\LineageCatalogService(new Grimorio\Repositories\LineageOathRepository($pdo));

// El Custodio sembrado ya porta linaje; el peregrino se inscribe para
// ejercitar los dos estados del accountState.
$pdo->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, created_at, updated_at)
            VALUES ('usr_peregrino', 'Peregrina del Velo', 'peregrina@arcano.arc', 'x', 'editor', NULL, NULL, '2026-09-18T10:00:00Z', '2026-09-18T10:00:00Z')");

$catalogo = $service->getOathCatalog('usr_peregrino');
assertCondition(count($catalogo->lineages) === 8, 'El canon sirve EXACTAMENTE 8 fichas (halladas: ' . count($catalogo->lineages) . ')');
assertCondition($catalogo->accountState === 'pilgrim', 'La cuenta peregrina se declara `pilgrim`');
assertCondition($service->getOathCatalog('usr_custodio_primordial')->accountState === 'lineaged', 'La cuenta linajada se declara `lineaged` (linajados curiosos, RF-01.6)');

$expectedKeys = ['id', 'name', 'glyph', 'bannerColor', 'rulingElement', 'doctrineCondensed', 'doctrineFull', 'hasActiveClans'];
$clavesExactas = true;
$serializada = json_decode(json_encode($catalogo), true);
foreach ($serializada['lineages'] as $ficha) {
    if (array_keys($ficha) !== $expectedKeys) {
        $clavesExactas = false;
        break;
    }
}
assertCondition($clavesExactas, 'Las 8 fichas viajan con las claves camelCase EXACTAS del contrato');
assertCondition(
    array_keys($serializada) === ['accountState', 'lineages'],
    'El catálogo viaja con las claves raíz EXACTAS del contrato'
);
assertCondition($serializada['lineages'][0]['id'] === 'primordialFlame' && $serializada['lineages'][7]['id'] === 'aetherWeavers', 'El orden ceremonial de las semillas se conserva (primordialFlame → aetherWeavers)');

// --- FASE 3: La bandera derivada ---
echo "\nFASE 3: hasActiveClans deriva de los clanes reales (criterio)\n";
$porId = [];
foreach ($catalogo->lineages as $ficha) {
    $porId[$ficha->id] = $ficha;
}
assertCondition($porId['primordialFlame']->hasActiveClans === true, 'La Llama Primordial guarda hermandad activa: sin nota (semillas del Custodio)');
$sinClanes = array_filter($catalogo->lineages, static fn (Grimorio\Dto\LineageProfileDto $ficha): bool => !$ficha->hasActiveClans);
assertCondition(count($sinClanes) === 7, 'Los otros 7 linajes visten la nota «Sin hermandades activas» (RF-02.1)');
assertCondition(
    (bool) array_reduce($catalogo->lineages, static fn (bool $carry, Grimorio\Dto\LineageProfileDto $ficha): bool => $carry && str_starts_with($ficha->doctrineFull, $ficha->doctrineCondensed), true),
    'Las 8 fichas derivan su condensada de la íntegra: un solo texto canónico (RF-02.1/02.2)'
);

// Archivar la hermandad apaga la bandera sin tocar el canon.
$pdo->exec("UPDATE clans SET status = 'archived' WHERE id = 'cln_primordial'");
$canonArchivado = $service->getOathCatalog('usr_peregrino');
$porIdArchivado = [];
foreach ($canonArchivado->lineages as $ficha) {
    $porIdArchivado[$ficha->id] = $ficha;
}
assertCondition($porIdArchivado['primordialFlame']->hasActiveClans === false, 'Archivada la hermandad, la bandera se apaga: nota solemne en la ceremonia');
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM lineage_doctrines WHERE doctrine_condensed <> ''")->fetchColumn() === 8,
    'El canon permanece inmutable tras el cambio de bandera (exclusión 5)'
);

// --- FASE 4: Coherencia de heráldica con SPEC-07 ---
echo "\nFASE 4: Heráldica compartida con SPEC-07 (fuente única, jamás divergente)\n";
require_once $projectRoot . '/src/Dto/LineageDto.php';
require_once $projectRoot . '/src/Services/LineageSynergyService.php';
$synergy = new Grimorio\Services\LineageSynergyService();
$salonSpec07 = [];
foreach ($synergy->listLineages() as $lineageDto) {
    $salonSpec07[$lineageDto->id] = ['glyph' => $lineageDto->glyph, 'bannerColor' => $lineageDto->bannerColor, 'rulingElement' => $lineageDto->rulingElement];
}
$coherente = true;
foreach ($catalogo->lineages as $ficha) {
    $esperada = $salonSpec07[$ficha->id] ?? null;
    if ($esperada === null || $ficha->glyph !== $esperada['glyph'] || $ficha->bannerColor !== $esperada['bannerColor'] || $ficha->rulingElement !== $esperada['rulingElement']) {
        $coherente = false;
        break;
    }
}
assertCondition($coherente, 'Las 8 heráldicas de la ceremonia coinciden con el Salón de Linajes de SPEC-07');
assertCondition($service->getOathCatalog('usr_peregrino')->lineages[0]->name === 'Linaje de la Llama Primordial', 'El nombre ceremonial viaja en noble castellano (Art. IV)');

// --- FASE 5: Los DTOs son la última muralla ---
echo "\nFASE 5: Los DTOs no dejan nacer fichas malformadas\n";
$doctrinaLlama = $porId['primordialFlame'];
assertCondition(
    captureError(static fn () => new Grimorio\Dto\LineageProfileDto('x', 'X', 'g', 'rojo', 'fire', 'a', 'a', true)) !== null,
    'Un estandarte fuera de #rrggbb es rechazado por el DTO'
);
assertCondition(
    captureError(static fn () => new Grimorio\Dto\LineageProfileDto('x', 'X', 'g', '#ff4500', 'fire', 'Doctrina corta', 'Doctrina que no empieza igual', true)) !== null,
    'Una condensada que no es recorte de la íntegra es rechazada: un solo texto canónico'
);
$legitima = new Grimorio\Dto\LineageProfileDto(
    id: 'primordialFlame',
    name: $doctrinaLlama->name,
    glyph: $doctrinaLlama->glyph,
    bannerColor: $doctrinaLlama->bannerColor,
    rulingElement: $doctrinaLlama->rulingElement,
    doctrineCondensed: $doctrinaLlama->doctrineCondensed,
    doctrineFull: $doctrinaLlama->doctrineFull,
    hasActiveClans: true,
);
assertCondition($legitima->jsonSerialize()['hasActiveClans'] === true, 'Una ficha legítima se serializa con su contrato intacto');

echo "\n== RESUMEN == Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";
if ($assertsFailed === 0) {
    echo "RESULTADO: EXITO — El canon ceremonial es inmutable por diseño: 8 fichas con contrato exacto, bandera derivada y heráldica compartida (Tarea 2.1).\n";
    exit(0);
}

echo "RESULTADO: FALLO — Corregir los asertos en rojo antes de continuar.\n";
exit(1);
