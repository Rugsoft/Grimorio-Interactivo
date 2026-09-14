<?php

/**
 * Script de verificación de la TAREA 1.4 — Modelo de Dominio y Servicio de Descubrimiento.
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación que verifica.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   Las llamadas a SpellDiscoveryService::getFeaturedSpells() retornan exactamente
 *   3 elementos con isGenesisSample: true cuando la base de datos no tiene
 *   hechizos validados de usuarios.
 *
 * Además, valida los contratos JSON del plan técnico (secciones 2.1, 2.2 y 2.3):
 *   - SpellSummaryDto: tarjetas de catálogo con camelCase en inglés.
 *   - SpellDetailDto:  ficha completa con componentes, firmas y validación.
 *   - Paginación offset/limit con hasMore (RF-03.7).
 *   - Filtros de búsqueda: query insensible a acentos, escuelas OR, maná <= (RF-03.3/3.5/3.6).
 *
 * Uso: php scratch/test_discovery.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Database/Connection.php';
require_once __DIR__ . '/../src/Models/Spell.php';
require_once __DIR__ . '/../src/Services/SpellDiscoveryService.php';

use Grimorio\Database\Connection;
use Grimorio\Models\Spell;
use Grimorio\Services\SpellDiscoveryService;

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
 * Prepara la base en memoria: siembra génesis y añade hechizos de usuarios de prueba.
 * Utiliza el canal Connection Singleton (Tarea 1.2) como hará el servicio real.
 */
function prepareTestDatabase(): PDO
{
    // Aislamiento por fase: restablece el Singleton para obtener una base
    // en memoria virgen (resetInstance existe para pruebas en CLI).
    Connection::resetInstance();
    putenv('GRIMORIO_DB_DSN=sqlite::memory:');
    $pdo = Connection::getInstance()->getPdo();

    // El Singleton ya auto-materializa schema.sql + seeds.sql en SQLite de
    // desarrollo: solo se siembra manualmente si el plano está vacío.
    $bootstrapReady = (int) $pdo->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'spells'"
    )->fetchColumn();
    if ($bootstrapReady === 0) {
        $pdo->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));
        $pdo->exec((string) file_get_contents(__DIR__ . '/../database/seeds.sql'));
    }

    return $pdo;
}

/**
 * Inserta un hechizo de usuario de prueba con fecha de validación dada.
 */
function insertUserSpell(PDO $pdo, string $slug, string $school, int $manaCost, string $validatedAt): void
{
    // El plano exige tres columnas que el cargador de semillas tampoco omite:
    // author_id y updated_at (NOT NULL sin DEFAULT) y math_fingerprint, cuyo
    // DEFAULT '' no satisface su propio CHECK (length = 64). El autor es el
    // Maestro Custodio de la casa primordial, coherente con el clan_id.
    $pdo->prepare(
        'INSERT INTO spells (id, slug, name, author_id, magic_school, mana_cost,
                             math_fingerprint, clan_id, summary, status,
                             is_genesis_sample, created_at, updated_at, validated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        'spl_' . md5($slug),
        $slug,
        'Conjuro de prueba ' . $slug,
        'usr_custodio_primordial',
        $school,
        $manaCost,
        str_repeat('f', 64),
        'cln_primordial',
        'Resumen arcano de ' . $slug,
        'validated',
        0,
        $validatedAt,
        $validatedAt,
        $validatedAt,
    ]);
}

echo "== VERIFICACION TAREA 1.4: Spell.php y SpellDiscoveryService.php ==\n\n";

// --- FASE 1: Entidad Spell inmutable y tipada ---
echo "FASE 1: Entidad Spell\n";
$sampleSpell = new Spell(
    id: 'spl_test',
    slug: 'llamas-de-frieren',
    name: 'Llamas de Frieren',
    magicSchool: 'evocation',
    manaCost: 45,
    clanId: 'cln_primordial',
    clanName: 'Custodios del Fuego Primordial',
    summary: 'Proyecta una ráfaga continua de fuego purificador.',
    status: 'validated',
    isGenesisSample: false,
    validatedAt: '2026-09-10T14:30:00Z'
);
assertCondition($sampleSpell->getSlug() === 'llamas-de-frieren', "getSlug() retorna el slug de la entidad");
assertCondition($sampleSpell->getManaCost() === 45, "getManaCost() retorna el coste de maná (Artículo II: dato leído, no calculado aquí)");

$summaryDto = $sampleSpell->toSummaryDto();
assertCondition(
    ($summaryDto['magicSchool'] ?? null) === 'evocation' && ($summaryDto['manaCost'] ?? null) === 45,
    "toSummaryDto() emite claves camelCase en inglés (contrato plan 2.1)"
);
assertCondition(
    ($summaryDto['magicSchoolLabel'] ?? null) === 'Evocación',
    "toSummaryDto() incluye magicSchoolLabel en castellano (Artículo IV)"
);
assertCondition(
    ($summaryDto['isGenesisSample'] ?? null) === false,
    "toSummaryDto() incluye isGenesisSample (contrato plan 2.1)"
);
assertCondition(
    !array_key_exists('description', $summaryDto),
    "toSummaryDto() NO incluye la descripción completa (solo la ficha de detalle la porta)"
);

$detailedSpell = new Spell(
    id: 'spl_test_2',
    slug: 'manto-de-niebla',
    name: 'Manto de Niebla',
    magicSchool: 'abjuration',
    manaCost: 12,
    clanId: 'cln_primordial',
    clanName: 'Custodios del Fuego Primordial',
    summary: 'Velo de bruma arcano.',
    status: 'validated',
    isGenesisSample: true,
    validatedAt: '2026-01-01T00:00:00Z',
    description: 'Tejido a partir del aliento de la montaña al alba.',
    components: [
        'verbal'   => 'Caligo velamen',
        'somatic'  => 'Palmas cruzando el rostro',
        'material' => 'Pañuelo empapado en rocío',
    ],
    validationSignaturesCount: 3
);
$detailDto = $detailedSpell->toDetailDto();
assertCondition(
    ($detailDto['components']['verbal'] ?? null) === 'Caligo velamen',
    "toDetailDto() incluye el objeto components (contrato plan 2.2)"
);
assertCondition(
    ($detailDto['validationSignaturesCount'] ?? null) === 3,
    "toDetailDto() incluye validationSignaturesCount (contrato plan 2.2)"
);

// --- FASE 2: CRITERIO "Hecho cuando" — génesis con base sin validados de usuarios ---
echo "\nFASE 2: Criterio Hecho cuando (génesis sin hechizos de usuarios)\n";
$pdo = prepareTestDatabase();
// Escenario del criterio: SOLO los 3 pergaminos primordiales (ningún hechizo de usuario).
$service = new SpellDiscoveryService(Connection::getInstance());
$featured = $service->getFeaturedSpells();

assertCondition(is_array($featured) && count($featured) === 3, "getFeaturedSpells() retorna exactamente 3 elementos (retorna: " . count($featured) . ")");
$allGenesis = count($featured) === 3 && count(array_filter($featured, fn ($s) => $s->isGenesisSample())) === 3;
assertCondition($allGenesis, "Los 3 elementos retornados tienen isGenesisSample = true");

$genesisSlugs = array_map(fn (Spell $s) => $s->getSlug(), $featured);
sort($genesisSlugs);
assertCondition(
    $genesisSlugs === ['chispa-de-ignicion', 'manto-de-niebla', 'susurro-del-viento'],
    "Los slugs coinciden con los Pergaminos Primordiales canónicos (RF-01.3)"
);

// --- FASE 3: Destacados reales cuando existen validados de usuarios (RF-01.2) ---
echo "\nFASE 3: Destacados con hechizos validados de usuarios\n";
// Tres validados de usuario: deben sustituir a los primordiales en la galería.
insertUserSpell($pdo, 'rayo-astral', 'evocation', 40, '2026-09-09T10:00:00Z');
insertUserSpell($pdo, 'escudo-de-raices', 'abjuration', 25, '2026-09-10T09:00:00Z');
insertUserSpell($pdo, 'espejo-de-brumas', 'illusion', 30, '2026-09-11T08:00:00Z');

$featuredReal = $service->getFeaturedSpells();
assertCondition(count($featuredReal) === 3, "Con 3 validados de usuario, retorna 3 destacados");
$noneGenesis = count(array_filter($featuredReal, fn (Spell $s) => $s->isGenesisSample())) === 0;
assertCondition($noneGenesis, "Ningún destacado es génesis (sustituidos por validados reales)");

// Los 3 más recientes, ordenados por validated_at DESC (RF-01.2).
$featuredSlugsByRecency = array_map(fn (Spell $s) => $s->getSlug(), $featuredReal);
assertCondition(
    $featuredSlugsByRecency === ['espejo-de-brumas', 'escudo-de-raices', 'rayo-astral'],
    "Ordenados por fecha de validación descendente (más recientes primero)"
);

// --- FASE 4: Caso mixto — 1 validado de usuario + 2 huecos de génesis (RF-01.3) ---
echo "\nFASE 4: Caso mixto (completar huecos con primordiales)\n";
$pdoFresh = prepareTestDatabase();
insertUserSpell($pdoFresh, 'único-validado', 'divination', 20, '2026-09-11T12:00:00Z');
$serviceFresh = new SpellDiscoveryService(Connection::getInstance());
$featuredMixed = $serviceFresh->getFeaturedSpells();

$genesisCountMixed = count(array_filter($featuredMixed, fn (Spell $s) => $s->isGenesisSample()));
assertCondition(count($featuredMixed) === 3, "La galería siempre se presenta con 3 elementos");
assertCondition($genesisCountMixed === 2, "Con 1 validado de usuario, se completan 2 huecos con primordiales");

// --- FASE 5: Catálogo paginado con hasMore (RF-03.1, RF-03.7) ---
echo "\nFASE 5: Catálogo paginado (offset/limit + hasMore)\n";
$pageOne = $serviceFresh->getSpells(offset: 0, limit: 2);
assertCondition(count($pageOne['items']) === 2, "Primera página respeta el límite de 2 elementos");
assertCondition($pageOne['hasMore'] === true, "hasMore = true cuando existen más resultados");

$pageTwo = $serviceFresh->getSpells(offset: 2, limit: 2);
assertCondition(count($pageTwo['items']) >= 1, "Segunda página retorna el resto de resultados");
assertCondition($pageTwo['hasMore'] === false, "hasMore = false al agotar el catálogo");

// Sin solapamiento entre páginas (paginación correcta).
$slugsPageOne = array_map(fn (Spell $s) => $s->getSlug(), $pageOne['items']);
$slugsPageTwo = array_map(fn (Spell $s) => $s->getSlug(), $pageTwo['items']);
assertCondition(
    count(array_intersect($slugsPageOne, $slugsPageTwo)) === 0,
    "No hay solapamiento entre páginas consecutivas"
);

// Ordenación por validación descendente en el catálogo completo (RF-03.1).
$allValidatedDates = array_map(fn (Spell $s) => $s->getValidatedAt(), $serviceFresh->getSpells(offset: 0, limit: 50)['items']);
$sortedDates = $allValidatedDates;
rsort($sortedDates);
assertCondition(
    $allValidatedDates === $sortedDates,
    "El catálogo se ordena por fecha de validación descendente"
);

// --- FASE 6: Filtros de búsqueda (RF-03.3, RF-03.5, RF-03.6) ---
echo "\nFASE 6: Filtros de búsqueda\n";

// RF-03.3: query insensible a acentos y mayúsculas (normalización NFD en backend).
$accentMatch = $serviceFresh->getSpells(query: 'ignicion');
$foundIgnition = count(array_filter($accentMatch['items'], fn (Spell $s) => $s->getSlug() === 'chispa-de-ignicion'));
assertCondition($foundIgnition === 1, "La query 'ignicion' (sin tilde) encuentra 'Chispa de Ignición'");

$uppercaseMatch = $serviceFresh->getSpells(query: 'MANTO');
$foundMantle = count(array_filter($uppercaseMatch['items'], fn (Spell $s) => $s->getSlug() === 'manto-de-niebla'));
assertCondition($foundMantle === 1, "La query 'MANTO' (mayúsculas) encuentra 'Manto de Niebla'");

// Query de menos de 2 caracteres: no filtra (regla del plan 5.1).
$shortQuery = $serviceFresh->getSpells(query: 'a');
assertCondition(count($shortQuery['items']) === 4, "Query con 1 carácter no filtra (retorna el catálogo completo)");

// RF-03.5: escuelas con lógica disyuntiva OR.
$orSchools = $serviceFresh->getSpells(schools: ['evocation', 'divination']);
$schoolsFound = array_map(fn (Spell $s) => $s->getMagicSchool(), $orSchools['items']);
$onlyRequestedSchools = count($schoolsFound) > 0 && count(array_diff(array_unique($schoolsFound), ['evocation', 'divination'])) === 0;
assertCondition($onlyRequestedSchools, "El filtro de escuelas opera con unión OR (solo evocation + divination)");

// RF-03.6: tope de maná inclusivo (<=).
$manaFilter = $serviceFresh->getSpells(maxMana: 12);
$manaCosts = array_map(fn (Spell $s) => $s->getManaCost(), $manaFilter['items']);
$allUnderThreshold = count($manaCosts) > 0 && max($manaCosts) <= 12;
assertCondition($allUnderThreshold, "El filtro de maná retorna solo costes <= 12 (tope inclusivo)");

// Combinación acumulativa de filtros.
$combined = $serviceFresh->getSpells(schools: ['evocation'], maxMana: 30);
$combinedSchools = array_unique(array_map(fn (Spell $s) => $s->getMagicSchool(), $combined['items']));
$combinedMana = array_map(fn (Spell $s) => $s->getManaCost(), $combined['items']);
$combinedOk = $combinedSchools === ['evocation'] && (empty($combinedMana) || max($combinedMana) <= 30);
assertCondition($combinedOk, "Los filtros combinados se aplican de forma acumulativa (escuela AND maná)");

// --- FASE 7: Aislamiento de experimentales y ficha por slug (RF-03.2, RF-04) ---
echo "\nFASE 7: Estados y ficha de detalle\n";
// Por defecto (solo lectura pública), el catálogo NO incluye experimentales.
$publicCatalog = $serviceFresh->getSpells();
$noExperimental = count(array_filter($publicCatalog['items'], fn (Spell $s) => $s->getStatus() === 'experimental')) === 0;
assertCondition($noExperimental, "El catálogo base no incluye hechizos experimentales (RF-03.2, Art. III)");

// Al incluirlos explícitamente, aparecen. El slug 'boceto-prohibido' ya
// vive en seeds.sql (RF-03.2): se siembra otro slug para esta fase.
// Mismas columnas obligatorias del plano: autor, huella matemática de 64
// caracteres y marca de actualización, además de la de creación.
$pdoFresh->prepare(
    'INSERT INTO spells (id, slug, name, author_id, magic_school, mana_cost,
                         math_fingerprint, clan_id, summary, status,
                         is_genesis_sample, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute(['spl_exp_1', 'runa-erratica', 'Runa Errática', 'usr_custodio_primordial', 'necromancy', 60, str_repeat('0', 64), 'cln_primordial', 'Hechizo experimental', 'experimental', 0, '2026-09-11T00:00:00Z', '2026-09-11T00:00:00Z']);

$experimentalCatalog = $serviceFresh->getSpells(includeExperimental: true);
$hasExperimental = count(array_filter($experimentalCatalog['items'], fn (Spell $s) => $s->getStatus() === 'experimental')) > 0;
assertCondition($hasExperimental, "includeExperimental = true revela los Archivos Experimentales (RF-03.2)");

// Ficha por slug (RF-04): los datos completos se resuelven a nivel de servicio.
$detail = $serviceFresh->getSpellBySlug('manto-de-niebla');
assertCondition($detail instanceof Spell && $detail->getSlug() === 'manto-de-niebla', "getSpellBySlug() retorna la entidad completa por slug");
assertCondition($detail !== null && count($detail->getComponents()) > 0, "La ficha incluye los componentes arcanos (contrato plan 2.2)");

$lostScroll = $serviceFresh->getSpellBySlug('pergamino-inexistente');
assertCondition($lostScroll === null, "getSpellBySlug() retorna null para pergaminos desterrados (manejable por el controlador, RF-06.2)");

// --- Resumen final ---
echo "\n== RESUMEN ==\n";
echo "Asertos superados: {$assertsPassed}\n";
echo "Asertos fallidos:  {$assertsFailed}\n";

if ($assertsFailed === 0) {
    echo "\nRESULTADO: EXITO — La Tarea 1.4 cumple su criterio 'Hecho cuando'.\n";
    exit(0);
}

echo "\nRESULTADO: FALLO — Corregir los asertos marcados con [FALLA].\n";
exit(1);
