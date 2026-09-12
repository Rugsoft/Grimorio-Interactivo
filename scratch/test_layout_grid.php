<?php

/**
 * test_layout_grid.php — Verificación de la Tarea 2.2 de TASKS-02.
 *
 * Rejilla adaptable del catálogo `.spell-card-grid` con CSS Grid:
 * 3 columnas en escritorio (> 1024 px), 2 en tabletas (768–1024 px)
 * y 1 en móviles (< 768 px) — plan técnico 4.1.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): CSS Grid nativo, sin frameworks.
 *   - Artículo V: clases en inglés kebab-case, comentarios en castellano.
 *
 * Criterio «Hecho cuando» (tasks.md 2.2):
 *   Al redimensionar la ventana, la rejilla transmuta fluidamente entre
 *   3, 2 y 1 columna sin rupturas visuales en los puntos de quiebre.
 *
 * Estrategia (sin navegador): análisis estático de la regla base y de
 * las media queries + simulación aritmética del número de columnas en
 * los anchos de prueba (320, 767, 768, 1024, 1025, 1920).
 *
 * Ejecución: php scratch/test_layout_grid.php  (exit 0 = verde)
 */

declare(strict_types=1);

$assertsPassed = 0;
$assertsFailed = 0;

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

$layoutPath = __DIR__ . '/../public/assets/css/layout.css';
$layoutCss  = (string) file_get_contents($layoutPath);

/**
 * Extrae el bloque de un selector simple (ancla: cierre de comentario,
 * inicio de archivo o llave — estilo del proyecto).
 */
function readRule(string $css, string $selector): ?string
{
    $escaped = preg_quote($selector, '#');
    return preg_match('#(\*/|^|\})\s*' . $escaped . '\s*\{([^}]*)\}#s', $css, $m) ? $m[2] : null;
}

/**
 * Lee una declaración de un bloque.
 */
function readDecl(string $block, string $property): ?string
{
    return preg_match('/' . preg_quote($property, '/') . '\s*:\s*([^;]+);/', $block, $m) ? trim($m[1]) : null;
}

/**
 * Extrae todas las media queries con su condición y su contenido.
 *
 * @return array<int, array{condition: string, body: string}>
 */
function readMediaQueries(string $css): array
{
    $queries = [];
    if (preg_match_all('/@media\s*([^{]+)\{((?:[^{}]*\{[^}]*\})*[^}]*)\}/s', $css, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $queries[] = ['condition' => trim($m[1]), 'body' => $m[2]];
        }
    }
    return $queries;
}

/**
 * Traduce un grid-template-columns a número de columnas (o null).
 * Acepta repeat(N, ...) y la pista única '1fr' (1 columna, forma
 * canónica del plan para móviles).
 */
function columnCount(?string $template): ?int
{
    if ($template === null) {
        return null;
    }
    if (preg_match('/repeat\(\s*(\d+)\s*,/', $template, $m)) {
        return (int) $m[1];
    }
    if (preg_match('/^1fr$/', trim($template))) {
        return 1;
    }
    return null;
}

/**
 * ¿La condición media aplica a un ancho de viewport dado?
 * Soporta min-width y max-width combinadas con "and".
 */
function mediaApplies(string $condition, float $viewportWidth): bool
{
    $applies = true;
    if (preg_match_all('/(min|max)-width\s*:\s*([\d.]+)px/', $condition, $m, PREG_SET_ORDER)) {
        foreach ($m as $clause) {
            $value = (float) $clause[2];
            if ($clause[1] === 'min') {
                $applies = $applies && $viewportWidth >= $value;
            } else {
                $applies = $applies && $viewportWidth <= $value;
            }
        }
    }
    return $applies;
}

echo "=== Tarea 2.2 (TASKS-02): Rejilla adaptable 3/2/1 columnas ===\n\n";

// ---------------------------------------------------------------------
// 1. Regla base: 3 columnas de escritorio.
// ---------------------------------------------------------------------
echo "[1] Regla base .spell-card-grid (escritorio > 1024 px)\n";

$baseRule = readRule($layoutCss, '.spell-card-grid');
assertArcane($baseRule !== null, 'Existe el selector .spell-card-grid en layout.css');

if ($baseRule !== null) {
    $display = readDecl($baseRule, 'display');
    $columns = columnCount(readDecl($baseRule, 'grid-template-columns'));
    assertArcane($display === 'grid', 'display: grid (CSS Grid nativo, Dogma Vanilla)');
    assertArcane($columns === 3, "3 columnas en la regla base (declaradas: " . ($columns ?? 'null') . ")");
    assertArcane(
        (bool) preg_match('/minmax\(0\s*,\s*1fr\)/', $baseRule),
        'Las columnas usan minmax(0, 1fr) (las tarjetas no desbordan la pista)'
    );
}

// ---------------------------------------------------------------------
// 2. Media queries de quiebre: tabletas (768–1024) y móviles (<768).
// ---------------------------------------------------------------------
echo "\n[2] Puntos de quiebre en media queries\n";

$queries     = readMediaQueries($layoutCss);
$tabletCols  = null;
$mobileCols  = null;
$tabletFound = false;
$mobileFound = false;

foreach ($queries as $query) {
    // ¿La media query toca .spell-card-grid?
    if (!str_contains($query['body'], '.spell-card-grid')) {
        continue;
    }
    $cols = columnCount(readDecl(
        (string) (preg_match('/\.spell-card-grid\s*\{([^}]*)\}/', $query['body'], $gm) ? $gm[1] : ''),
        'grid-template-columns'
    ));
    // ¿Condición de tableta? (max-width:1024 con min-width:768, o solo max 1024)
    if (preg_match('/max-width\s*:\s*1024px/', $query['condition'])) {
        $tabletFound = true;
        $tabletCols  = $cols ?? $tabletCols;
    }
    // ¿Condición de móvil? (max-width: 767px o 767.9px o 767.98px)
    if (preg_match('/max-width\s*:\s*767(\.\d+)?px/', $query['condition'])) {
        $mobileFound = true;
        $mobileCols  = $cols ?? $mobileCols;
    }
}

assertArcane($tabletFound, 'Existe media query de tableta con max-width: 1024px');
assertArcane($tabletCols === 2, "La tableta transmuta a 2 columnas (declaradas: " . ($tabletCols ?? 'null') . ")");
assertArcane($mobileFound, 'Existe media query de móvil con max-width: 767px (o fracción)');
assertArcane($mobileCols === 1, "El móvil transmuta a 1 columna (declaradas: " . ($mobileCols ?? 'null') . ")");

// ---------------------------------------------------------------------
// 3. Simulación aritmética: la rejilla responde bien en cada ancho.
//    Regla de cascada: base si nada aplica; si media aplica, la última
//    condición aplicable gana (orden del archivo).
// ---------------------------------------------------------------------
echo "\n[3] Simulación de redimensionado (cascada CSS)\n";

$effectiveCols = function (float $viewport) use ($layoutCss, $baseRule): ?int {
    $cols = columnCount(readDecl((string) $baseRule, 'grid-template-columns'));
    foreach (readMediaQueries($layoutCss) as $query) {
        if (!str_contains($query['body'], '.spell-card-grid')) {
            continue;
        }
        if (mediaApplies($query['condition'], $viewport)) {
            $ruleCols = columnCount(readDecl(
                (string) (preg_match('/\.spell-card-grid\s*\{([^}]*)\}/', $query['body'], $gm) ? $gm[1] : ''),
                'grid-template-columns'
            ));
            if ($ruleCols !== null) {
                $cols = $ruleCols;
            }
        }
    }
    return $cols;
};

$expectations = [
    [1920.0, 3, 'escritorio panorámico'],
    [1025.0, 3, 'umbral escritorio (justo sobre 1024)'],
    [1024.0, 2, 'umbral tableta (justo en 1024)'],
    [800.0,  2, 'tableta estándar'],
    [768.0,  2, 'umbral tableta inferior (justo en 768)'],
    [767.0,  1, 'umbral móvil (justo bajo 768)'],
    [375.0,  1, 'móvil estándar'],
    [320.0,  1, 'móvil ultra-estrecho'],
];

foreach ($expectations as [$viewport, $expectedCols, $label]) {
    $cols = $effectiveCols((float) $viewport);
    assertArcane(
        $cols === $expectedCols,
        sprintf('A %gpx (%s): %d columna(s) — esperadas %d', $viewport, $label, $cols ?? -1, $expectedCols)
    );
}

// ---------------------------------------------------------------------
// 4. Transmutación fluida: entre quiebres vecinos el conteo solo cambia
//    en los puntos definidos (sin saltos intermedios).
// ---------------------------------------------------------------------
echo "\n[4] Continuidad de la transmutación\n";

$jumpPoints = [];
$prev = $effectiveCols(320.0);
for ($w = 320.0; $w <= 1920.0; $w += 1.0) {
    $current = $effectiveCols((float) $w);
    if ($current !== $prev) {
        $jumpPoints[] = (int) $w;
        $prev = $current;
    }
}
assertArcane(
    count($jumpPoints) === 2,
    'Exactamente 2 puntos de transmutación en 320→1920 (' . implode(', ', $jumpPoints) . ')'
);
assertArcane(
    $jumpPoints === [768, 1025],
    'Las transmutaciones ocurren SOLO en los quiebres 768 y 1025'
);

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);
