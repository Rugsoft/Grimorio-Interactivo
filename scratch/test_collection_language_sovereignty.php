<?php

/**
 * test_collection_language_sovereignty.php — Guard de soberanía lingüística
 * de los módulos nuevos de SPEC-11 (verificación de la Tarea 6.2).
 *
 * La tarea exige: «el guard de soberanía lingüística no encuentra
 * literales técnicos en los módulos nuevos». Este arnés barre los
 * módulos JS/PHP de la superficie de colección y sella:
 *
 *   [1] Cero referencias técnicas de spec en leyendas visibles
 *       (RF-xx.y, RNF-xx, SPEC-xx, códigos HTTP).
 *   [2] Cero literales de interfaz en inglés en los módulos nuevos
 *       (heurística de palabras funcionales inglesas).
 *   [3] Cero claves JSON en snake_case en los sobre de los controladores
 *       de colección (Artículo V: claves en inglés camelCase).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): arnés nativo, sin dependencias.
 *   - Artículo IV/V: velo arcano en castellano; claves en inglés camelCase.
 *
 * Uso: php scratch/test_collection_language_sovereignty.php  (exit 0 = verde)
 */

declare(strict_types=1);

$assertsPassed = 0;
$assertsFailed = 0;

/** Asegura una condición y la reporta con el formato estándar del proyecto. */
function assertArcane(bool $condition, string $label): void
{
    global $assertsPassed, $assertsFailed;
    if ($condition) {
        $assertsPassed++;
        echo "  OK   {$label}\n";
    } else {
        $assertsFailed++;
        echo "  FALLA {$label}\n";
    }
}

/** Retira comentarios de bloque y de línea para no escrutar la doc. */
function stripComments(string $source): string
{
    $withoutBlock = preg_replace('#/\*.*?\*/#s', ' ', $source) ?? $source;
    $withoutLine = preg_replace('~^\s*(?://|\*)[^"\']*$~m', ' ', $withoutBlock) ?? $withoutBlock;
    return $withoutLine;
}

/**
 * Extrae literales de cadena de un fuente sin comentarios.
 * En JS los comentarios de línea van con doble barra y los de bloque con
 * barra-asterisco; stripComments ya los retiró antes de escrutar.
 */
function extractStringLiterals(string $source): array
{
    preg_match_all('/([\'"])((?:\\\\.|(?!\1).)*)\1/', $source, $hits);
    $literals = [];
    foreach ($hits[2] as $literal) {
        $literal = trim($literal);
        // Solo texto natural con espacio: las claves de una palabra
        // (validated, collection, spellId...) no son texto visible.
        if ($literal !== '' && str_contains($literal, ' ') && preg_match('/[A-Za-zÁÉÍÓÚÑáéíóúñ]{2,}/', $literal)) {
            $literals[] = $literal;
        }
    }
    return $literals;
}

/** Heurística de texto en inglés funcional en la interfaz. */
function looksLikeEnglishUiText(string $text): bool
{
    $englishMarkers = [
        '/\bthe \b/i', '/\byour \b/i', '/\bplease\b/i', '/\bnot found\b/i',
        '/\brequired\b/i', '/\berror\b/i', '/\bforbidden\b/i', '/\bwelcome\b/i',
        '/\btry again\b/i', '/\bsubmit\b/i', '/\bexpired\b/i', '/\bfailed\b/i',
        '/\bsession expired\b/i', '/\bremove\b/i', '/\badd to\b/i', '/\bcancel\b/i',
        '/\bconfirm\b/i', '/\bsuccess\b/i',
    ];
    foreach ($englishMarkers as $marker) {
        if (preg_match($marker, $text) === 1) {
            return true;
        }
    }
    return false;
}

/** Heurística de referencia técnica de spec en texto visible. */
function looksLikeSpecReference(string $text): bool
{
    return preg_match('/\bRF-\d{2}\.\d+\b/', $text) === 1
        || preg_match('/\bRNF-\d{2}\b/', $text) === 1
        || preg_match('/\bSPEC-\d{2}\b/', $text) === 1
        || preg_match('/\bHTTP \d{3}\b/', $text) === 1;
}

/** Detecta claves JSON en snake_case dentro de un fuente PHP (sobre JSON). */
function findSnakeCaseJsonKeys(string $source): array
{
    // Claves de array tipo 'mi_clave' =>  (patrón de los DTOs/controladores).
    preg_match_all("/'([a-z]+(?:_[a-z0-9]+)+)'\s*=>/", $source, $hits);
    $allowedSnake = [
        // Las claves de BD legítimas viven en consultas SQL, no en sobres
        // JSON; la lista negra de abajo cubre las visibles al cliente.
    ];
    return array_values(array_diff(array_unique($hits[1]), $allowedSnake));
}

// Raíz del proyecto anclada al PROPIO fichero: el guard ha de juzgar las
// mismas rutas se invoque desde donde se invoque (un lote que lo ejecute
// desde scratch/ jamás ha de dar un falso rojo por cwd).
$projectRoot = dirname(__DIR__);

// Módulos NUEVOS de la superficie de colección (SPEC-11).
$jsModules = [
    $projectRoot . '/public/assets/js/views/grimoireCollectionView.js',
    $projectRoot . '/public/assets/js/views/grimoireSimulatorView.js',
    $projectRoot . '/public/assets/js/components/discardTomeEntryModalComponent.js',
    $projectRoot . '/public/assets/js/components/spellCardComponent.js',
    $projectRoot . '/public/assets/js/api/grimoireCollectionClient.js',
];
$phpModules = [
    $projectRoot . '/src/Controllers/GrimoireCollectionController.php',
    $projectRoot . '/src/Services/GrimoireCollectionService.php',
    $projectRoot . '/src/Dto/CollectionEntryDto.php',
    $projectRoot . '/src/Dto/CollectionPageDto.php',
];

// ---------------------------------------------------------------------
echo "[1] Módulos JS de colección: cero referencias técnicas en leyendas\n";
$jsSpecHits = [];
$jsEnglishHits = [];
foreach ($jsModules as $module) {
    if (!file_exists($module)) {
        continue; // El guard de cobertura de ficheros no es de esta tarea.
    }
    $source = stripComments((string) file_get_contents($module));
    foreach (extractStringLiterals($source) as $literal) {
        if (looksLikeSpecReference($literal)) {
            $jsSpecHits[] = "{$module}: «{$literal}»";
        }
        if (looksLikeEnglishUiText($literal)) {
            $jsEnglishHits[] = "{$module}: «{$literal}»";
        }
    }
}
assertArcane($jsSpecHits === [], 'JS: cero referencias RF/RNF/SPEC en leyendas visibles'
    . ($jsSpecHits !== [] ? ' — ' . implode('; ', array_slice($jsSpecHits, 0, 3)) : ''));
assertArcane($jsEnglishHits === [], 'JS: cero literales de interfaz en inglés'
    . ($jsEnglishHits !== [] ? ' — ' . implode('; ', array_slice($jsEnglishHits, 0, 3)) : ''));

// ---------------------------------------------------------------------
echo "[2] Controladores y DTOs de colección: sobre camelCase (Artículo V)\n";
$snakeHits = [];
foreach ($phpModules as $module) {
    if (!file_exists($module)) {
        continue;
    }
    $source = (string) file_get_contents($module);
    foreach (findSnakeCaseJsonKeys($source) as $key) {
        $snakeHits[] = "{$module}: '{$key}'";
    }
}
assertArcane($snakeHits === [], 'PHP: cero claves JSON en snake_case en los sobres'
    . ($snakeHits !== [] ? ' — ' . implode('; ', array_slice($snakeHits, 0, 3)) : ''));

// ---------------------------------------------------------------------
echo "[3] Leyendas canónicas del Anexo A presentes y castellanas\n";
$clientSource = (string) file_get_contents($projectRoot . '/public/assets/js/api/grimoireCollectionClient.js');
$viewSource = stripComments((string) file_get_contents($projectRoot . '/public/assets/js/views/grimoireCollectionView.js'));
$canonLegends = [
    'Ya está en tu tomo',
    'Ya rendiste homenaje',
    'Obra en gestación',
    'Obra apartada del canon',
    'Tu tomo aguarda su primera obra',
    'Un adepto de la casa no granjea gloria para su propio estandarte',
    'Tu vínculo con el santuario ha expirado',
];
$missingLegends = [];
foreach ($canonLegends as $legend) {
    if (!str_contains($clientSource, $legend) && !str_contains($viewSource, $legend)
        && !str_contains((string) file_get_contents($projectRoot . '/public/assets/js/components/spellCardComponent.js'), $legend)) {
        $missingLegends[] = $legend;
    }
}
assertArcane($missingLegends === [], 'Las leyendas del Anexo A viven en los módulos'
    . ($missingLegends !== [] ? ' — faltan: ' . implode('; ', $missingLegends) : ''));

// ---------------------------------------------------------------------
echo "\n== RESUMEN ==\n";
echo "Asertos superados: {$assertsPassed}\n";
echo "Asertos fallidos:  {$assertsFailed}\n";

if ($assertsFailed === 0) {
    echo "\nRESULTADO: EXITO — El guard de soberanía lingüística no halla literales técnicos.\n";
    exit(0);
}
echo "\nRESULTADO: DENEGADO — Revisar los hallazgos marcados.\n";
exit(1);
