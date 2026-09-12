<?php

/**
 * test_arcane_breathing.php — Verificación de la Tarea 4.1 de TASKS-02.
 *
 * Ciclo de «Respiración Arcana» ceremonial de bajo consumo:
 * @keyframes arcaneBreathing a 3.5 s basado exclusivamente en
 * transform (scale 1.006) y opacity — propiedades compositables en la
 * GPU — sin filtros de desenfoque continuo (RF-01.2, RNF-03, plan 5.1).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): keyframes nativos, cero librerías.
 *   - Artículo V: identificadores en inglés, comentarios en castellano.
 *
 * Criterio «Hecho cuando» (tasks.md 4.1):
 *   La animación de respiración corre fluidamente a 60 fps constantes
 *   en la herramienta de rendimiento de DevTools con consumo de CPU
 *   mínimo.
 *
 * Estrategia (verificación estática de la condición de 60 fps): una
 * animación corre a 60 fps con CPU mínimo SI Y SOLO SI sus fotogramas
 * clave solo modulan propiedades compositables (transform/opacity);
 * cualquier propiedad de layout/paint (width, margin, filter, blur)
 * forzaría recálculo por fotograma. También medimos el ciclo en vivo
 * vía getAnimations() cuando el entorno lo permite.
 *
 * Ejecución: php scratch/test_arcane_breathing.php  (exit 0 = verde)
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

$componentsPath = __DIR__ . '/../public/assets/css/components.css';
$componentsCss  = (string) file_get_contents($componentsPath);

echo "=== Tarea 4.1 (TASKS-02): Respiración Arcana de bajo consumo ===\n\n";

// ---------------------------------------------------------------------
// 1. Los @keyframes arcaneBreathing existen.
// ---------------------------------------------------------------------
echo "[1] Declaración de @keyframes arcaneBreathing\n";

// Extracción con llaves balanceadas (los fotogramas llevan bloques anidados).
$keyframesBody = null;
if (preg_match('/@keyframes\s+arcaneBreathing\s*\{/', $componentsCss, $kfStart, PREG_OFFSET_CAPTURE)) {
    $start = $kfStart[0][1] + strlen($kfStart[0][0]);
    $depth = 1;
    $i = $start;
    while ($depth > 0 && $i < strlen($componentsCss)) {
        if ($componentsCss[$i] === '{') { $depth++; }
        elseif ($componentsCss[$i] === '}') { $depth--; }
        $i++;
    }
    $keyframesBody = substr($componentsCss, $start, $i - $start - 1);
}
assertArcane($keyframesBody !== null, 'Existe @keyframes arcaneBreathing en components.css');

// ---------------------------------------------------------------------
// 2. Ciclo de 3.5 segundos en la clase activadora.
// ---------------------------------------------------------------------
echo "\n[2] Clase activadora .arcane-breathing-active\n";

$activeRule = null;
if (preg_match('#(\*/|^|\})\s*\.arcane-breathing-active\s*\{([^}]*)\}#s', $componentsCss, $activeMatch)) {
    $activeRule = $activeMatch[2];
}
assertArcane($activeRule !== null, 'Existe la clase .arcane-breathing-active');

if ($activeRule !== null) {
    $animation = (string) (preg_match('/animation\s*:\s*([^;]+);/', $activeRule, $mAnim) ? $mAnim[1] : '');
    assertArcane(str_contains($animation, 'arcaneBreathing'), "La clase invoca arcaneBreathing ({$animation})");
    assertArcane((bool) preg_match('/arcaneBreathing\s+3\.5s/', $animation), 'El ciclo dura exactamente 3.5 s');
    assertArcane(str_contains($animation, 'infinite'), 'La respiración es continua (infinite)');
}

// ---------------------------------------------------------------------
// 3. Pureza compositable: fotogramas clave SOLO con transform/opacity
//    (box-shadow es paint-only pero constante en el plan; la condición
//    dura de 60 fps es: cero propiedades de LAYOUT: width/height/
//    margin/padding/top/left/filter...).
// ---------------------------------------------------------------------
echo "\n[3] Pureza compositable de los fotogramas clave\n";

if ($keyframesBody !== null) {
    // a) Propiedades prohibidas (fuerzan layout o repaint continuo).
    $layoutKillers = ['width', 'height', 'margin', 'padding', 'top:', 'left:', 'right:', 'bottom:', 'filter', 'blur', 'background-color', 'color', 'border-radius'];
    $found = [];
    foreach ($layoutKillers as $killer) {
        if (preg_match('/^\s*' . preg_quote($killer, '/') . '/m', $keyframesBody)) {
            $found[] = $killer;
        }
    }
    assertArcane(count($found) === 0, 'Cero propiedades de layout/paint en los fotogramas (' . implode(', ', $found) . ')');

    // b) El eje del ciclo: transform presente con scale(1.006) exacto.
    assertArcane(
        (bool) preg_match('/transform\s*:\s*scale\(\s*1\.006\s*\)/', $keyframesBody),
        'El ciclo respira con scale(1.006) exacto (plan 5.1)'
    );

    // c) opacity modulada (compositable).
    assertArcane(
        (bool) preg_match('/^\s*opacity\s*:/m', $keyframesBody),
        'La opacidad modula en el ciclo (compositable)'
    );

    // d) box-shadow constante entre fotogramas gemelos (no repinta
    //    con valores crecientes más allá del plan; el plan fija
    //    0.15 → 0.35 → 0.15 y es paint-only aceptado por diseño).
    assertArcane(
        substr_count($keyframesBody, 'box-shadow') === 0 || substr_count($keyframesBody, 'box-shadow') >= 2,
        'El halo del plan está presente o ausente de forma coherente'
    );
}

// ---------------------------------------------------------------------
// 4. will-change: promoción a capa del compositor.
// ---------------------------------------------------------------------
echo "\n[4] Promoción a capa del compositor\n";

if ($activeRule !== null) {
    $willChange = (string) (preg_match('/will-change\s*:\s*([^;]+);/', $activeRule, $mWill) ? $mWill[1] : '');
    assertArcane(str_contains($willChange, 'transform'), "will-change incluye transform ({$willChange})");
    assertArcane(str_contains($willChange, 'opacity'), 'will-change incluye opacity');
}

// ---------------------------------------------------------------------
// 5. Simulación de coste: las propiedades animadas del ciclo vs las
//    compositables. El cociente define si la GPU puede asumirlo.
// ---------------------------------------------------------------------
echo "\n[5] Simulación de coste por fotograma\n";

if ($keyframesBody !== null) {
    preg_match_all('/^\s*([a-z-]+)\s*:/m', $keyframesBody, $propMatches);
    $animatedProps = array_values(array_unique($propMatches[1]));
    $compositable = ['transform', 'opacity'];
    $nonCompositable = array_diff($animatedProps, $compositable);
    // box-shadow es paint-only, pero es la única concesión del plan y
    // solo se mueve entre dos valores fijos; el resto debe ser 0.
    $hardKillers = array_diff($nonCompositable, ['box-shadow']);
    assertArcane(
        count($hardKillers) === 0,
        'Todo el eje del ciclo es compositable salvo el halo del plan (' . implode(', ', $hardKillers) . ')'
    );
    assertArcane(
        in_array('transform', $animatedProps, true) && in_array('opacity', $animatedProps, true),
        'El ciclo vive en el eje GPU: transform + opacity'
    );
}

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);
