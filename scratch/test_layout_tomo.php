<?php

/**
 * test_layout_tomo.php — Verificación de la Tarea 2.1 de TASKS-02.
 *
 * Contenedor «El Tomo Central»: clase .grimoire-tomo-container acotada
 * a 1280 px, centrado automático y padding lateral de seguridad
 * (plan técnico 4.1).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): CSS Grid/Flexbox nativos, sin frameworks.
 *   - Artículo V: clases en inglés kebab-case, comentarios en castellano.
 *
 * Criterio «Hecho cuando» (tasks.md 2.1):
 *   En monitores panorámicos (1920 px o 4K), el contenedor mantiene un
 *   ancho máximo visual de 1280 px perfectamente centrado con márgenes
 *   exteriores solemnes.
 *
 * Estrategia de verificación (sin navegador): análisis estático de la
 * regla CSS + simulación aritmética del layout a 1920 px y 3840 px.
 *
 * Ejecución: php scratch/test_layout_tomo.php  (exit 0 = verde)
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
$tokensPath = __DIR__ . '/../public/assets/css/tokens.css';
$layoutCss  = (string) file_get_contents($layoutPath);
$tokensCss  = (string) file_get_contents($tokensPath);

/**
 * Extrae el bloque de declaraciones de un selector CSS simple.
 * El selector puede abrir el archivo, seguir a '}' o seguir al cierre
 * de un comentario doc (estilo del proyecto).
 */
function readRule(string $css, string $selector): ?string
{
    $escaped = preg_quote($selector, '/');
    // Ancla válida: cierre de comentario doc, inicio de archivo o llave.
    // (delimitador # para poder usar / literal dentro del patrón)
    $closeComment = chr(42) . chr(47);
    return preg_match('#(' . preg_quote($closeComment, '#') . '|^|\})\s*' . $escaped . '\s*\{([^}]*)\}#s', $css, $m) ? $m[2] : null;
}

/**
 * Lee una declaración de un bloque de reglas.
 */
function readDecl(string $block, string $property): ?string
{
    return preg_match('/' . preg_quote($property, '/') . '\s*:\s*([^;]+);/', $block, $m) ? trim($m[1]) : null;
}

/**
 * Resuelve un valor CSS que puede ser var(--token) contra tokens.css.
 */
function resolveValue(string $value, string $tokensCss): string
{
    if (preg_match('/^var\(\s*(--[a-zA-Z0-9-]+)\s*\)$/', $value, $m)) {
        $token = preg_match('/' . preg_quote($m[1], '/') . '\s*:\s*([^;]+);/', $tokensCss, $t) ? trim($t[1]) : $value;
        return $token;
    }
    return $value;
}

/**
 * Convierte '1280px' o '48px' a float de píxeles.
 */
function toPx(?string $value): ?float
{
    if ($value === null) {
        return null;
    }
    return preg_match('/^(-?\d+(?:\.\d+)?)px$/', trim($value), $m) ? (float) $m[1] : null;
}

echo "=== Tarea 2.1 (TASKS-02): Contenedor «El Tomo Central» a 1280 px ===\n\n";

// ---------------------------------------------------------------------
// 1. La clase existe con todas sus declaraciones canónicas.
// ---------------------------------------------------------------------
echo "[1] Regla .grimoire-tomo-container en layout.css\n";

$rule = readRule($layoutCss, '.grimoire-tomo-container');
assertArcane($rule !== null, 'Existe el selector .grimoire-tomo-container en layout.css');

if ($rule !== null) {
    $width   = readDecl($rule, 'width');
    $maxWRaw = readDecl($rule, 'max-width');
    $maxW    = $maxWRaw !== null ? resolveValue($maxWRaw, $tokensCss) : null;
    $mLeft   = readDecl($rule, 'margin-left');
    $mRight  = readDecl($rule, 'margin-right');
    $pLeft   = readDecl($rule, 'padding-left');
    $pRight  = readDecl($rule, 'padding-right');
    $boxSizing = readDecl($rule, 'box-sizing');

    assertArcane($width === '100%', "width: 100% (declarado: {$width})");
    assertArcane(
        toPx($maxW) === 1280.0,
        'max-width resuelve a 1280px (vía token --container-tomo-max o literal)'
    );
    assertArcane($mLeft === 'auto' && $mRight === 'auto', 'margin-left/right: auto (centrado solemne)');
    assertArcane($pLeft !== null && toPx(resolveValue((string) $pLeft, $tokensCss)) >= 16.0, 'padding-left de seguridad >= 16px');
    assertArcane($pRight !== null && toPx(resolveValue((string) $pRight, $tokensCss)) >= 16.0, 'padding-right de seguridad >= 16px');
    assertArcane($boxSizing === 'border-box', 'box-sizing: border-box (el padding no infla el tomo)');
}

// ---------------------------------------------------------------------
// 2. El token --container-tomo-max existe y vale 1280px (fuente única).
// ---------------------------------------------------------------------
echo "\n[2] Token maestro --container-tomo-max en tokens.css\n";

$tokenValue = preg_match('/--container-tomo-max\s*:\s*([^;]+);/', $tokensCss, $mTok) ? trim($mTok[1]) : null;
assertArcane($tokenValue !== null, 'Existe --container-tomo-max en tokens.css');
assertArcane(toPx($tokenValue) === 1280.0, "--container-tomo-max = 1280px (valor: {$tokenValue})");

// ---------------------------------------------------------------------
// 3. Simulación aritmética del layout en monitores panorámicos.
//    width:100% + max-width:1280 + margin auto => ancho visual:
//      min(viewport, 1280)  y márgenes = (viewport - ancho) / 2.
// ---------------------------------------------------------------------
echo "\n[3] Simulación del criterio: panorámicas 1920 px y 4K\n";

$containerToken = toPx($tokenValue) ?? 1280.0;
foreach ([1920.0, 3840.0] as $viewport) {
    $visualWidth = min($viewport, $containerToken);
    $sideMargin  = ($viewport - $visualWidth) / 2;
    assertArcane(
        $visualWidth === 1280.0,
        "A {$viewport}px de viewport el tomo mide exactamente 1280px de ancho visual"
    );
    assertArcane(
        $sideMargin > 200.0,
        "A {$viewport}px los márgenes exteriores son solemnes ({$sideMargin}px por lado)"
    );
    assertArcane(
        abs(($visualWidth + 2 * $sideMargin) - $viewport) < 0.01,
        "A {$viewport}px el conjunto (tomo + márgenes) cuadra exactamente con el viewport"
    );
}

// ---------------------------------------------------------------------
// 4. El centrado no lo rompe un contexto flex padre: #app es el hijo
//    del body flex (columna). Verificamos que el body no estira a los
//    hijos en el eje horizontal (align-items por defecto = stretch en
//    columna solo estira vertical… en columna estira el eje X). El
//    propio #app ya usa width:100%; el tomo se usa DENTRO de las vistas.
//    Comprobamos que las vistas tienen un gancho para montarlo.
// ---------------------------------------------------------------------
echo "\n[4] Integración: el contenedor es utilizable por las vistas\n";

// Alguna vista u orquestador debe conocer la clase (búsqueda en el JS).
$jsDir = __DIR__ . '/../public/assets/js';
$uses  = 0;
$rit = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($jsDir, FilesystemIterator::SKIP_DOTS));
foreach ($rit as $fileInfo) {
    if ($fileInfo->isFile() && str_ends_with($fileInfo->getFilename(), '.js')) {
        if (str_contains((string) file_get_contents($fileInfo->getPathname()), 'grimoire-tomo-container')) {
            $uses++;
        }
    }
}
assertArcane(
    $uses > 0 || str_contains((string) file_get_contents(__DIR__ . '/../public/index.html'), 'grimoire-tomo-container'),
    'La clase .grimoire-tomo-container se usa en vistas o en el shell (no es CSS muerto)'
);

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);
