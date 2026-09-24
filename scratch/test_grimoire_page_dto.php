<?php

/**
 * test_grimoire_page_dto.php — Arnés TDD de la Tarea 1.1 (TASKS-05).
 *
 * Verifica el GrimoirePageDto: carga útil de una página del Tomo Arcano
 * con tipado estricto, construcción desde fila de base de datos y
 * serialización JSON nativa fiel al contrato del plan (Sec. 2.1).
 *
 * Criterio «Hecho cuando» (Tarea 1.1): la instanciación de GrimoirePageDto
 * y su posterior json_encode() genera exactamente la estructura tipada
 * requerida por el contrato REST sin campos nulos ni advertencias de tipo.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): json_encode nativo, sin librerías.
 *   - Artículo V: claves técnicas en inglés camelCase; leyendas castellanas.
 *
 * Uso: php scratch/test_grimoire_page_dto.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../src/Dto/GrimoirePageDto.php';

use Grimorio\Dto\GrimoirePageDto;

$assertsPassed = 0;
$assertsFailed = 0;

/** Aserta una condición y la registra en la bitácora solemne. */
function assertCondition(bool $condition, string $description): void
{
    global $assertsPassed, $assertsFailed;
    if ($condition) {
        $assertsPassed++;
        echo "  [OK]   {$description}" . PHP_EOL;
    } else {
        $assertsFailed++;
        echo "  [FALLA] {$description}" . PHP_EOL;
    }
}

echo '== ARNES TDD: GrimoirePageDto (Tarea 1.1, TASKS-05) ==' . PHP_EOL;

/**
 * Fila canónica de base de datos (espejo del esquema real `spells`,
 * como la entregaría un fetch(PDO::FETCH_ASSOC) del servicio 1.2).
 * @return array<string, null|int|string>
 */
function buildCanonicalDatabaseRow(): array
{
    return [
        'id'                  => 'spl_8f1a2c3b',
        'slug'                => 'esfera-de-llamas-purificadoras',
        'name'                => 'Esfera de Llamas Purificadoras',
        'magic_school'        => 'evocation',
        'elemental_affinity'  => 'fire',
        'circle'              => 2,
        'mana_cost'           => 35,
        'casting_time'        => 'action',
        'components_verbal'   => '¡Llamas del alba, descended y consumid la penumbra!',
        'components_somatic'  => 'Pulgar e índice unidos frente al pecho',
        'components_material' => '',
        'has_verbal'          => 1,
        'has_somatic'         => 1,
        'has_material'        => 0,
        'range_type'          => 'medium',
        'area_type'           => 'sphere',
        'duration_type'       => 'instant',
        'damage'              => 30,
        'healing'             => 0,
        'barrier'             => 0,
        'crowd_control_type'  => 'none',
        'summary'             => 'Esfera ígnea condensada que estalla en ascuas.',
        'description'         => 'Una esfera ígnea condensada que estalla en ascuas voraces al contacto.',
        'author_alias'        => 'Archimago Ignis',
        'clan_name'           => 'Círculo de la Llama Eterna',
        'status'              => 'validated',
    ];
}

// ---------------------------------------------------------------------
// [1] Instanciación y propiedades tipadas.
// ---------------------------------------------------------------------
echo PHP_EOL . '[1] Instanciación con tipado estricto' . PHP_EOL;

$row = buildCanonicalDatabaseRow();
$dto = GrimoirePageDto::fromDatabaseRow($row);

assertCondition($dto instanceof GrimoirePageDto, 'fromDatabaseRow() forja un GrimoirePageDto');
assertCondition($dto->id === 'spl_8f1a2c3b', 'La propiedad id es de tipo string');
assertCondition($dto->circle === 2, 'La propiedad circle es de tipo int');
assertCondition($dto->manaCost === 35, 'La propiedad manaCost es de tipo int');
assertCondition($dto->hasVerbal === true && $dto->hasSomatic === true && $dto->hasMaterial === false, 'Los componentes hasVerbal/hasSomatic/hasMaterial son bool desde 0/1 de la BD');
assertCondition($dto->effects === ['damage' => 30, 'healing' => 0, 'barrier' => 0, 'crowdControlType' => 'none'], 'El bloque effects agrupa el efecto cuantitativo canónico');
assertCondition($dto->incantationFormula === '¡Llamas del alba, descended y consumid la penumbra!', 'La fórmula litúrgica (incantationFormula) viaja en noble castellano');

// ---------------------------------------------------------------------
// [2] Constructor público con valores por defecto sanos.
// ---------------------------------------------------------------------
echo PHP_EOL . '[2] Constructor directo con defectos sanos' . PHP_EOL;

$minimal = new GrimoirePageDto(
    id: 'spl_min',
    slug: 'conjuro-minimo',
    name: 'Conjuro Mínimo',
    magicSchool: 'evocation',
    elementalAffinity: 'none',
);
assertCondition($minimal->circle === 1, 'Círculo por defecto: 1');
assertCondition($minimal->manaCost === 5, 'Coste de maná por defecto: suelo de 5 (Artículo II)');
assertCondition($minimal->castingTime === 'action', 'Tiempo de lanzamiento por defecto: action');
assertCondition($minimal->hasVerbal === false, 'Componente verbal por defecto: false');
assertCondition($minimal->effects === ['damage' => 0, 'healing' => 0, 'barrier' => 0, 'crowdControlType' => 'none'], 'Efectos por defecto: todo a cero con CC none');
assertCondition($minimal->description === '', 'Descripción por defecto: cadena vacía (sin null)');

// ---------------------------------------------------------------------
// [3] Serialización JSON fiel al contrato del plan (Sec. 2.1).
// ---------------------------------------------------------------------
echo PHP_EOL . '[3] Serialización JSON nativa (json_encode)' . PHP_EOL;

$payload = json_encode($dto, JSON_UNESCAPED_UNICODE);
assertCondition(is_string($payload) && $payload !== '', 'json_encode() genera un cuerpo JSON válido');

$decoded = json_decode((string) $payload, true);
assertCondition(is_array($decoded), 'El cuerpo se decodifica sin advertencias de tipo');

$expectedKeys = [
    'id', 'slug', 'name', 'magicSchool', 'elementalAffinity', 'elementalAffinityLabel', 'circle',
    'manaCost', 'castingTime', 'incantationFormula',
    'hasVerbal', 'hasSomatic', 'hasMaterial',
    'rangeType', 'areaType', 'durationType',
    'effects', 'description', 'authorAlias', 'clanName', 'status',
];
$actualKeys = array_keys($decoded);
assertCondition($actualKeys === $expectedKeys, 'Las claves raíz coinciden EXACTAMENTE con el contrato (orden canónico)');

assertCondition(isset($decoded['effects']['damage'], $decoded['effects']['healing'], $decoded['effects']['barrier'], $decoded['effects']['crowdControlType']), 'El bloque anidado effects porta sus 4 claves camelCase');
assertCondition($decoded['elementalAffinity'] === 'fire' && $decoded['rangeType'] === 'medium' && $decoded['areaType'] === 'sphere', 'Afinidad y geometría viajan en inglés camelCase (Artículo V)');

$hasNullValues = false;
array_walk_recursive($decoded, static function ($value) use (&$hasNullValues): void {
    if ($value === null) {
        $hasNullValues = true;
    }
});
assertCondition($hasNullValues === false, 'El cuerpo JSON no porta campos nulos (criterio «Hecho cuando»)');

assertCondition(
    json_encode($minimal, JSON_UNESCAPED_UNICODE) !== false
    && !str_contains((string) json_encode($minimal, JSON_UNESCAPED_UNICODE), 'null'),
    'El DTO mínimo serializa sin null ni advertencias',
);

// ---------------------------------------------------------------------
// [3b] Rótulo elemental canónico (hallazgo 13, spec §10.4).
// ---------------------------------------------------------------------
echo PHP_EOL . '[3b] Rótulo elemental canónico (paridad con resumen/detalle)' . PHP_EOL;

assertCondition(
    ($decoded['elementalAffinityLabel'] ?? null) === 'Fuego',
    'La obra de afinidad fire rota «Fuego» — jamás «Arcano Puro» por defecto (hallazgo 13)',
);
assertCondition(
    in_array('elementalAffinityLabel', $actualKeys, true),
    'La clave elementalAffinityLabel viaja en la serialización (plan §2.1 enmendado)',
);

$noneDecoded = json_decode((string) json_encode($minimal, JSON_UNESCAPED_UNICODE), true);
assertCondition(
    ($noneDecoded['elementalAffinityLabel'] ?? null) === 'Arcano Puro',
    'La afinidad none rota el neutro «Arcano Puro» (RNF-03: jamás un identificador crudo)',
);

$forgedLabel = GrimoirePageDto::fromDatabaseRow($row)->elementalAffinityLabel;
assertCondition(
    $forgedLabel === 'Fuego',
    'El rótulo forjado desde fila resuelve el canónico «Fuego» (mapa espejo de Spell::ELEMENTAL_AFFINITY_LABELS)',
);

$enriched = $dto->withAdeptState(true, false);
assertCondition(
    $enriched->elementalAffinityLabel === 'Fuego' && $enriched->adeptState !== null,
    'La enriquecedora withAdeptState() conserva el rótulo elemental (inmutabilidad por copia)',
);

$unknownRow = buildCanonicalDatabaseRow();
$unknownRow['elemental_affinity'] = 'aetherium';
$unknownDecoded = json_decode((string) json_encode(GrimoirePageDto::fromDatabaseRow($unknownRow), JSON_UNESCAPED_UNICODE), true);
assertCondition(
    ($unknownDecoded['elementalAffinityLabel'] ?? null) === 'Arcano Puro',
    'Una afinidad ajena al Códice rota el neutro (sin identifiers técnicos en la tarjeta)',
);

// ---------------------------------------------------------------------
// [4] Inmutabilidad (readonly) y defensas de canon.
// ---------------------------------------------------------------------
echo PHP_EOL . '[4] Inmutabilidad y canon' . PHP_EOL;

$mutationThrew = false;
try {
    /** @psalm-suppress UnusedPropertyAssignment */
    $dto->name = 'Nombre Corrupto';
} catch (Error) {
    $mutationThrew = true;
}
assertCondition($mutationThrew, 'La mutación externa de una propiedad readonly falla con Error');

assertCondition(
    GrimoirePageDto::fromDatabaseRow($row)->jsonSerialize() === $dto->jsonSerialize(),
    'Dos forjas desde la misma fila producen serializaciones idénticas (determinismo)',
);

// ---------------------------------------------------------------------
// Resumen final.
// ---------------------------------------------------------------------
echo PHP_EOL . '== RESUMEN ==' . PHP_EOL;
echo "Asertos superados: {$assertsPassed}" . PHP_EOL;
echo "Asertos fallidos:  {$assertsFailed}" . PHP_EOL;

if ($assertsFailed > 0) {
    echo PHP_EOL . 'RESULTADO: DENEGADO' . PHP_EOL;
    exit(1);
}
echo PHP_EOL . 'RESULTADO: EXITO — GrimoirePageDto listo para el contrato REST (Tarea 1.1).' . PHP_EOL;
exit(0);
