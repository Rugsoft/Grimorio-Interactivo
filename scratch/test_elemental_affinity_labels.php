<?php

declare(strict_types=1);

/**
 * test_elemental_affinity_labels.php — Arnés del hallazgo H9 del recorrido
 * manual de SPEC-11 (Tarea 9.2).
 *
 * La insignia elemental de TODA tarjeta rotulaba «Arcano Puro» — un conjuro
 * de rayo o de viento incluido — porque ningún DTO portaba su etiqueta.
 *
 * Verifica:
 *   [1] Cada afinidad canónica del Códice (SPEC-06) tiene su etiqueta en
 *       castellano y NINGUNA etiqueta es el identificador técnico.
 *   [2] `toSummaryDto()` porta `elementalAffinity` y `elementalAffinityLabel`.
 *   [3] La afinidad ausente ('none') y la ajena al Códice se rotulan con el
 *       elemento neutro: jamás un identificador crudo en la tarjeta (RNF-03).
 *   [4] Paridad de mapas entre el backend (Spell) y el cliente (ELEMENT_NAMES
 *       de grimoireBookComponent) — la disciplina de espejos del proyecto.
 *   [5] La tarjeta viste el elemento DECLARADO por el DTO y solo cae al mapa
 *       por escuela cuando el hechizo no declara afinidad.
 *
 * Ejecución: php scratch/test_elemental_affinity_labels.php  (exit 0 = verde)
 */

require_once __DIR__ . '/../src/Models/Spell.php';
require_once __DIR__ . '/../src/Dto/SpellCreateDto.php';

use Grimorio\Dto\SpellCreateDto;
use Grimorio\Models\Spell;

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

/** Forja una entidad desde una fila canónica de `spells`. */
function spellWithAffinity(string $affinity): Spell
{
    return Spell::fromDatabaseRow([
        'id'               => 'spl_probe',
        'slug'             => 'conjuro-sonda',
        'name'             => 'Conjuro Sonda',
        'magic_school'     => 'evocation',
        'elemental_affinity' => $affinity,
        'mana_cost'        => 25,
        'clan_id'          => 'cln_probe',
        'clan_name'        => 'Casa Sonda',
        'summary'          => 'Sonda de laboratorio.',
        'status'           => 'validated',
        'is_genesis_sample' => 0,
    ]);
}

echo "== VERIFICACION H9: etiquetas canónicas de afinidad elemental ==\n\n";

// --- FASE 1: el mapa castellano de las ocho afinidades canónicas ---
echo "FASE 1: etiqueta castellana de cada afinidad del Códice\n";

$canonicalAffinities = SpellCreateDto::CANONICAL_AFFINITIES;
assertArcane(count($canonicalAffinities) === 8, 'El Códice declara sus 8 afinidades canónicas (SPEC-06)');

foreach ($canonicalAffinities as $affinity) {
    $label = spellWithAffinity($affinity)->getElementalAffinityLabel();
    assertArcane(
        is_string($label) && $label !== '' && $label !== $affinity,
        "La afinidad '{$affinity}' se rotula en castellano: «{$label}» (jamás el identificador)"
    );
}

// --- FASE 2: el DTO de resumen porta la pareja ---
echo "\nFASE 2: contrato del DTO de tarjeta\n";

$boltDto = spellWithAffinity('lightning')->toSummaryDto();
assertArcane(
    ($boltDto['elementalAffinity'] ?? null) === 'lightning',
    'toSummaryDto() porta el identificador técnico `elementalAffinity` (camelCase, Artículo V)'
);
assertArcane(
    ($boltDto['elementalAffinityLabel'] ?? null) === 'Rayo',
    'toSummaryDto() porta `elementalAffinityLabel` en castellano (Artículo IV)'
);
assertArcane(
    ($boltDto['elementalAffinityLabel'] ?? null) !== 'Arcano Puro',
    'El conjuro de rayo NO rotula «Arcano Puro» (hallazgo H9)'
);

$windDto = spellWithAffinity('wind')->toSummaryDto();
assertArcane(
    ($windDto['elementalAffinityLabel'] ?? null) === 'Viento',
    'El conjuro de viento porta su etiqueta propia (hallazgo H9)'
);

// `toDetailDto()` hereda la pareja (ficha completa).
$detailDto = spellWithAffinity('lightning')->toDetailDto();
assertArcane(
    ($detailDto['elementalAffinityLabel'] ?? null) === 'Rayo',
    'La ficha de detalle hereda la etiqueta elemental del resumen'
);

// --- FASE 3: neutro y afinidades ajenas ---
echo "\nFASE 3: afinidad ausente o ajena al Códice\n";

assertArcane(
    spellWithAffinity('none')->getElementalAffinityLabel() === 'Arcano Puro',
    "La afinidad 'none' se rotula con el elemento neutro"
);
assertArcane(
    spellWithAffinity('air')->getElementalAffinityLabel() === 'Arcano Puro',
    "Una afinidad ajena al Códice ('air') cae al elemento neutro, jamás al identificador crudo"
);
assertArcane(
    spellWithAffinity('')->getElementalAffinity() === 'none',
    "Una afinidad vacía se normaliza a 'none' en la entidad"
);

// --- FASE 4: paridad de mapas backend ⇄ cliente ---
echo "\nFASE 4: paridad del mapa con el cliente\n";

$bookJs = (string) file_get_contents(__DIR__ . '/../public/assets/js/components/grimoireBookComponent.js');
$clientLabels = [];
if (preg_match('/ELEMENT_NAMES = Object\.freeze\(\{(.*?)\}\);/s', $bookJs, $match) === 1) {
    preg_match_all("/'?([a-zA-Z]+)'?\s*:\s*'([^']+)'/", $match[1], $pairs, PREG_SET_ORDER);
    foreach ($pairs as $pair) {
        $clientLabels[$pair[1]] = $pair[2];
    }
}
assertArcane(
    count($clientLabels) === 8,
    'El cliente declara sus 8 nombres solemnes de elemento (ELEMENT_NAMES)'
);

$mismatched = [];
foreach ($canonicalAffinities as $affinity) {
    $serverLabel = spellWithAffinity($affinity)->getElementalAffinityLabel();
    $clientLabel = $clientLabels[$affinity] ?? null;
    if ($clientLabel !== $serverLabel) {
        $mismatched[] = "{$affinity} (backend «{$serverLabel}» vs cliente «{$clientLabel}»)";
    }
}
assertArcane(
    $mismatched === [],
    'El mapa del backend y el del cliente son espejo exacto — divergencias: ' . (implode('; ', $mismatched) ?: 'ninguna')
);

// --- FASE 5: la tarjeta viste el elemento declarado ---
echo "\nFASE 5: derivación del vestido elemental en la tarjeta\n";

$cardJs = (string) file_get_contents(__DIR__ . '/../public/assets/js/components/spellCardComponent.js');
assertArcane(
    str_contains($cardJs, 'CANONICAL_ELEMENTS')
    && str_contains($cardJs, 'declaredElement'),
    'La tarjeta deriva el vestido del elemento DECLARADO por el DTO (hallazgo H9)'
);
assertArcane(
    (bool) preg_match('/pureArcane:\s*\'arcane\'/', $cardJs),
    'Arcano Puro viste la variante neutra del kit (`arcane`)'
);

$tokensCss = (string) file_get_contents(__DIR__ . '/../public/assets/css/tokens.css');
$tokenNames = ['fire', 'water', 'lightning', 'earth', 'wind', 'light', 'darkness', 'arcane'];
$missingTokens = [];
foreach ($tokenNames as $tokenName) {
    if (!str_contains($tokensCss, "--color-affinity-{$tokenName}:")) {
        $missingTokens[] = $tokenName;
    }
}
assertArcane(
    $missingTokens === [],
    'Cada elemento que la tarjeta puede vestir tiene su token de color — faltan: ' . (implode(', ', $missingTokens) ?: 'ninguno')
);

// --- Resumen final ---
echo "\n== RESUMEN ==\n";
echo "Asertos superados: {$assertsPassed}\n";
echo "Asertos fallidos:  {$assertsFailed}\n";

if ($assertsFailed === 0) {
    echo "\nRESULTADO: EXITO — El hallazgo H9 queda cerrado.\n";
    exit(0);
}

echo "\nRESULTADO: DENEGADO — Corregir los asertos marcados con [FALLA].\n";
exit(1);
