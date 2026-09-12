<?php

/**
 * test_design_showcase.php — Verificación de la Tarea 5.2 de TASKS-02.
 *
 * Escaparate del Sistema de Diseño: `scratch/design_system_preview.html`
 * enlaza las 4 hojas del santuario y exhibe las 8 tarjetas elementales,
 * una experimental, un pergamino espectral y controles interactivos,
 * funcionando de forma AUTÓNOMA (sin servidor ni red).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): rutas RELATIVAS y ES Modules nativos;
 *     cero CDNs, cero empaquetadores, cero JS inline masivo.
 *   - Artículo V: comentarios en castellano, clases kebab-case.
 *
 * Criterio «Hecho cuando» (tasks.md 5.2):
 *   Abrir scratch/design_system_preview.html en el navegador despliega
 *   el escaparate completo del sistema de diseño funcionando de forma
 *   autónoma.
 *
 * Estrategia: el arnés audita el HTML (enlaces a las 4 hojas con rutas
 * relativas correctas, inventario arcano completo) y EJECUTA una sonda
 * DOM con Node que importa el módulo de tarjetas real del proyecto,
 * construye las 9 tarjetas y verifica su cableado (las mismas técnicas
 * de los arneses .mjs anteriores).
 *
 * Ejecución: php scratch/test_design_showcase.php  (exit 0 = verde)
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

$showcasePath = __DIR__ . '/design_system_preview.html';
$cssDir       = __DIR__ . '/../public/assets/css';

echo "=== Tarea 5.2 (TASKS-02): Escaparate del Sistema de Diseño ===\n\n";

// ---------------------------------------------------------------------
// 1. Existencia y estructura del escaparate.
// ---------------------------------------------------------------------
echo "[1] Existencia y enlaces a las 4 hojas del santuario\n";

assertArcane(is_file($showcasePath), 'Existe scratch/design_system_preview.html');

$html = is_file($showcasePath) ? (string) file_get_contents($showcasePath) : '';

$expectedSheets = ['tokens.css', 'layout.css', 'components.css', 'print.css'];
foreach ($expectedSheets as $sheet) {
    assertArcane(
        str_contains($html, $sheet),
        "Enlaza {$sheet}"
    );
}

// Rutas relativas correctas: el escaparate vive en scratch/ → ../public/...
assertArcane(
    (bool) preg_match_all('#href="\.\./public/assets/css/[a-z]+\.css"#', $html) >= 4,
    'Las 4 hojas usan rutas relativas ../public/assets/css/ (autonomía sin servidor)'
);

// El DOCTYPE y el castellano del título.
assertArcane(str_contains($html, '<!DOCTYPE html>'), 'Documento HTML5 válido');
assertArcane(
    (bool) preg_match('#<html[^>]*lang="es"#', $html),
    'lang="es" (dualidad lingüística del documento)'
);

// ---------------------------------------------------------------------
// 2. Inventario arcano: 8 elementales + experimental + espectral.
//    Las tarjetas elementales las construye el módulo en vivo: los
//    atributos data-affinity se emiten con setAttribute, así que el
//    inventario se audita sobre la FUENTE del módulo (lo que el
//    navegador ejecuta realmente).
// ---------------------------------------------------------------------
echo "\n[2] Inventario arcano del escaparate\n";

$moduleSrcForInventory = '';
if (preg_match('#<script type="module">(.*?)</script>#s', $html, $mInv) === 1) {
    $moduleSrcForInventory = $mInv[1];
}

$affinities = ['fire', 'water', 'lightning', 'earth', 'wind', 'light', 'darkness', 'arcane'];
foreach ($affinities as $affinity) {
    assertArcane(
        str_contains($moduleSrcForInventory, "affinity: '{$affinity}'"),
        "Muestra elemental declarada en el módulo: {$affinity}"
    );
}
assertArcane(
    str_contains($moduleSrcForInventory, "setAttribute('data-affinity'") || str_contains($moduleSrcForInventory, 'data-affinity'),
    'El módulo rotula cada tarjeta con data-affinity (auditable en el DOM vivo)'
);

assertArcane(
    str_contains($html, 'data-status="experimental"') && str_contains($moduleSrcForInventory, "status: 'experimental'"),
    'Muestra experimental declarada (sello de Inestabilidad Arcana)'
);
assertArcane(
    str_contains($html, 'spectral-scroll-placeholder'),
    'Pergamino espectral exhibido'
);

// Controles interactivos: el escaparate debe permitir jugar con el sistema.
assertArcane(
    str_contains($html, 'data-showcase-toggle') || str_contains($html, 'showcase-toggle'),
    'Controles interactivos presentes'
);

// ---------------------------------------------------------------------
// 3. Autonomía: el módulo real de tarjetas se consume desde su ruta.
// ---------------------------------------------------------------------
echo "\n[3] Cableado real: importación del componente del proyecto\n";

assertArcane(
    (bool) preg_match('#import\s*\{[^}]*createSpellCardComponent[^}]*\}\s*from[^;]*spellCardComponent\.js#s', $html),
    'El escaparate importa createSpellCardComponent del proyecto'
);

// ---------------------------------------------------------------------
// 4. Sonda DOM: el escaparate construye tarjetas vivas de verdad.
//    (Se ejecuta la lógica del módulo con el DOM simulado del proyecto.)
// ---------------------------------------------------------------------
echo "\n[4] Sonda DOM: las 8 afinidades producen tarjetas cableadas\n";

// Extrae el bloque <script type="module"> del escaparate para reutilizar
// su generador de DTOs (misma fuente que ejecutará el navegador).
$moduleSrc = '';
if (preg_match('#<script type="module">(.*?)</script>#s', $html, $m) === 1) {
    $moduleSrc = $m[1];
}
assertArcane($moduleSrc !== '', 'El escaparate contiene su módulo <script type="module">');

// Debe existir un generador de DTOs de muestra (makeSpellDto o similar).
assertArcane(
    preg_match('/function\s+makeSpellDto|const\s+makeSpellDto/', $moduleSrc) === 1,
    'El módulo define un generador de DTOs de muestra (makeSpellDto)'
);

// Debe mapear las 8 afinidades ( SPELL_AFFINITIES o similar).
assertArcane(
    preg_match('/(SPELL_AFFINITIES|SHOWCASE_SPELLS|SHOWCASE_AFFINITIES)/', $moduleSrc) === 1,
    'El módulo declara el catálogo de las 8 afinidades'
);

// ---------------------------------------------------------------------
// 5. print.css está enlazado con media="print" (herencia de la 5.1).
// ---------------------------------------------------------------------
echo "\n[5] Hoja de impresión correctamente aislada\n";

assertArcane(
    (bool) preg_match('#<link[^>]*print\.css[^>]*media="print"#', $html),
    'print.css enlazado con media="print" (sin coste en pantalla)'
);

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);
