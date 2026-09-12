<?php

/**
 * test_final_audit.php — Verificación de la Tarea 5.3 de TASKS-02.
 *
 * Auditoría integral de calidad visual y Dogma Vanilla (plan 7.1–7.4):
 *   - [7.1] Cero CDNs en el escaparate y contraste WCAG >= 4.5:1
 *     (la auditoría canónica `verify_design_tokens.php` debe seguir
 *     en verde sobre el árbol real, ahora con print.css y el escaparate).
 *   - [7.2] Condición dura de 60 fps: el ciclo arcaneBreathing SOLO
 *     modula propiedades compositables (transform/opacity).
 *   - [7.3] Condición dura de CLS = 0: espectrales y tarjetas comparten
 *     huella exacta (min-height y radio).
 *   - [7.4] Responsividad en resoluciones límite: 320 px (sin scroll
 *     horizontal), 768 px (2 columnas), 1280 px (tomo centrado),
 *     4K (tomo acotado, sin estiramiento).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): cero CDNs/dependencias.
 *   - Artículo IV/V: mensajes sin advertencias; documentación castellana.
 *
 * Criterio «Hecho cuando» (tasks.md 5.3):
 *   Se verifica que el escaparate pasa todas las pruebas de contraste,
 *   rendimiento y responsividad sin ninguna advertencia en la consola
 *   del navegador.
 *
 * Ejecución: php scratch/test_final_audit.php  (exit 0 = verde)
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

$projectRoot = dirname(__DIR__);
$componentsCss = (string) file_get_contents($projectRoot . '/public/assets/css/components.css');
$layoutCss     = (string) file_get_contents($projectRoot . '/public/assets/css/layout.css');
$tokensCss     = (string) file_get_contents($projectRoot . '/public/assets/css/tokens.css');

/** Extrae el bloque de un selector (tolerante a listas de selectores). */
function readRule(string $css, string $selector): ?string
{
    $escaped = preg_quote($selector, '#');
    return preg_match('#(\*/|^|\})\s*' . $escaped . '[^{}]*\{([^}]*)\}#s', $css, $m) ? $m[2] : null;
}

function readDecl(string $block, string $property): ?string
{
    return preg_match('/^\s*' . preg_quote($property, '/') . '\s*:\s*([^;]+);/m', $block, $m) ? trim($m[1]) : null;
}

/** Extrae un @keyframes con llaves balanceadas. */
function readKeyframes(string $css, string $name): ?string
{
    if (preg_match('/@keyframes\s+' . preg_quote($name, '/') . '\s*\{/', $css, $m, PREG_OFFSET_CAPTURE) !== 1) {
        return null;
    }
    $start = $m[0][1] + strlen($m[0][0]);
    $depth = 1;
    $len = strlen($css);
    for ($i = $start; $i < $len; $i++) {
        if ($css[$i] === '{') $depth++;
        elseif ($css[$i] === '}') {
            $depth--;
            if ($depth === 0) return substr($css, $start, $i - $start);
        }
    }
    return null;
}

echo "=== Tarea 5.3 (TASKS-02): Auditoría integral de calidad visual y Dogma Vanilla ===\n\n";

// =====================================================================
// [7.1] CERO CDNs Y CONTRASTE >= 4.5:1 (auditoría canónica en verde).
// =====================================================================
echo "[A·7.1] Cero CDNs y contraste WCAG (plan 7.1)\n";

// La auditoría canónica (Tarea 1.5) sobre el árbol REAL: debe seguir
// en verde ahora que print.css y el escaparate habitan el santuario.
$canonicalOutput = [];
$canonicalExit = 0;
exec('php ' . escapeshellarg(__DIR__ . '/verify_design_tokens.php') . ' ' . escapeshellarg($projectRoot) . ' 2>&1', $canonicalOutput, $canonicalExit);
assertArcane($canonicalExit === 0, 'verify_design_tokens.php sobre el árbol real: exit 0 (cero CDNs + contraste)');

// El escaparate queda incluido en el rastrillo de URLs externas.
assertArcane(
    is_file(__DIR__ . '/design_system_preview.html') && $canonicalExit === 0,
    'El escaparate pasa el rastrillo de URLs externas (autonomía Dogma Vanilla)'
);

// Cero @import en cualquier hoja (vector clásico de dependencia externa).
$importCount = 0;
foreach (['tokens.css', 'layout.css', 'components.css', 'print.css'] as $sheet) {
    $importCount += substr_count((string) file_get_contents($projectRoot . '/public/assets/css/' . $sheet), '@import');
}
assertArcane($importCount === 0, 'Cero @import en las 4 hojas (sin dependencias encadenadas)');

// =====================================================================
// [7.2] RENDIMIENTO: condición dura de 60 fps (plan 7.2).
// =====================================================================
echo "\n[B·7.2] Fluidez 60 fps: eje exclusivamente compositable\n";

$breathing = readKeyframes($componentsCss, 'arcaneBreathing');
assertArcane($breathing !== null, '@keyframes arcaneBreathing presente');

if ($breathing !== null) {
    // Prohibidos en el eje de la animación: propiedades que fuerzan
    // recálculo de estilo/layout por fotograma.
    $forbidden = '/(width|height|margin|padding|top|left|right|bottom|font-size|filter|blur)\s*:/';
    assertArcane(!preg_match($forbidden, $breathing), 'Cero propiedades de layout/paint en el keyframes');

    $transformValues = [];
    if (preg_match_all('/transform\s*:\s*([^;]+);/', $breathing, $mT) >= 1) {
        $transformValues = array_map('trim', $mT[1]);
    }
    assertArcane(count($transformValues) >= 2, 'El ciclo viaja por transform (compositable en GPU)');
    assertArcane(
        (bool) array_filter($transformValues, fn ($v) => str_contains($v, 'scale(1.006)')),
        'Escala canónica scale(1.006) del plan 5.1'
    );

    $opacityValues = [];
    if (preg_match_all('/opacity\s*:\s*([\d.]+)\s*;/', $breathing, $mO) >= 1) {
        $opacityValues = array_map('floatval', $mO[1]);
    }
    assertArcane(
        count($opacityValues) >= 2 && min($opacityValues) >= 0.5,
        'Opacidad modulada sin desaparecer (mín ' . (count($opacityValues) ? min($opacityValues) : 0) . ')'
    );

    $activator = readRule($componentsCss, '.arcane-breathing-active');
    assertArcane(
        $activator !== null && str_contains((string) readDecl($activator, 'animation'), '3.5s'),
        'Ciclo ceremonial de 3.5s exactos'
    );
    assertArcane(
        $activator !== null && str_contains((string) $activator, 'will-change'),
        'Promoción a capa del compositor (will-change)'
    );
}

// El barrido espectral viaja también solo por transform/opacity.
$sweep = readKeyframes($componentsCss, 'spectralSweep');
assertArcane(
    $sweep !== null && !preg_match('/(left|margin|top|width)\s*:/', $sweep),
    'spectralSweep sin propiedades de layout (60 fps también en carga)'
);

// =====================================================================
// [7.3] CLS = 0: huella exacta espectral ↔ tarjeta (plan 7.3).
// =====================================================================
echo "\n[C·7.3] Estabilidad de maquetación: huella idéntica\n";

$cardRule = readRule($componentsCss, '.spell-card');
$phRule   = readRule($componentsCss, '.spectral-scroll-placeholder');

$cardMinH = $cardRule !== null ? readDecl($cardRule, 'min-height') : null;
$phMinH   = $phRule !== null ? readDecl($phRule, 'min-height') : null;
assertArcane($cardMinH === $phMinH && $cardMinH === '240px', "min-height espejo (tarjeta={$cardMinH}, espectral={$phMinH})");

$cardRadius = $cardRule !== null ? readDecl($cardRule, 'border-radius') : null;
$phRadius   = $phRule !== null ? readDecl($phRule, 'border-radius') : null;
assertArcane($cardRadius === $phRadius && $cardRadius === 'var(--radius-seal)', 'Radio espejo (--radius-seal)');

// En zoom, la liberación de geometría abarca a AMBOS (sin CLS bajo zoom).
// El grupo de selectores vive repartido en dos líneas: regex tolerante.
$zoomLiberation = null;
if (preg_match('#\.spell-card\s*,\s*\.spectral-scroll-placeholder\s*\{([^}]*)\}#s', $layoutCss, $mZoom) === 1) {
    $zoomLiberation = $mZoom[1];
}
assertArcane(
    $zoomLiberation !== null && str_contains((string) readDecl($zoomLiberation, 'min-height'), 'auto'),
    'La media query de zoom libera tarjeta y espectral por igual'
);

// =====================================================================
// [7.4] RESOLUCIONES LÍMITE: 320 / 768 / 1280 / 4K (plan 7.4).
// =====================================================================
echo "\n[D·7.4] Resoluciones límite (320 / 768 / 1280 / 4K)\n";

// 320 px: contención horizontal y columna única.
assertArcane(
    (bool) preg_match('/body\s*\{[^}]*overflow-x\s*:\s*clip/s', $layoutCss),
    '320 px: overflow-x: clip (cero scroll horizontal)'
);
assertArcane(
    (bool) preg_match('/\(max-width:\s*767\.9px\)\s*\{[^}]*\.spell-card-grid[^}]*1fr/s', $layoutCss),
    '320 px: rejilla a 1 columna'
);

// 768 px: 2 columnas (media query de tableta).
assertArcane(
    (bool) preg_match('/\(max-width:\s*1024px\) and \(min-width:\s*768px\)\s*\{[^}]*\.spell-card-grid[^}]*repeat\(2/s', $layoutCss),
    '768 px: rejilla a 2 columnas'
);

// 1280 px y 4K: tomo acotado y centrado (token maestro).
$tomo = readRule($layoutCss, '.grimoire-tomo-container');
assertArcane(
    $tomo !== null && str_contains((string) readDecl($tomo, 'max-width'), 'var(--container-tomo-max)'),
    '1280/4K: tomo acotado al token maestro'
);
assertArcane(
    $tomo !== null && str_contains((string) $tomo, 'margin-left: auto') && str_contains((string) $tomo, 'margin-right: auto'),
    '1280/4K: centrado solemne (margin auto)'
);
assertArcane(
    str_contains($tokensCss, '--container-tomo-max: 1280px'),
    'Token maestro --container-tomo-max: 1280px (sin estiramiento en 4K)'
);

// =====================================================================
// [E] CONSOLA LIMPIA: cero console.error/warn en los módulos del portal.
// =====================================================================
echo "\n[E] Consola sin advertencias (criterio del escaparate)\n";

$consoleViolations = 0;
$rit = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoot . '/public/assets/js', FilesystemIterator::SKIP_DOTS)
);
foreach ($rit as $fileInfo) {
    if ($fileInfo->isFile() && $fileInfo->getExtension() === 'js') {
        $src = (string) file_get_contents($fileInfo->getPathname());
        if (preg_match('/console\.(error|warn)\(/', $src)) {
            $consoleViolations++;
            echo "  FALLA console.error/warn en " . $fileInfo->getFilename() . "\n";
        }
    }
}
assertArcane($consoleViolations === 0, 'Cero console.error/warn en los módulos del portal');

// =====================================================================
// Veredicto.
// =====================================================================
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);
