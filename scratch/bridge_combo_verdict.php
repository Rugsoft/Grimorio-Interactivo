<?php

/**
 * bridge_combo_verdict.php — Puente de paridad de veredictos (SPEC-06).
 *
 * Herramienta CLI del arnés de la Tarea 2.2: recibe por argumento el payload
 * del Endpoint 3 (activeAura, incomingSpell, stunlockImmune), resuelve con
 * el servicio PHP real (ElementalMatrixService::resolveCombo) y emite el
 * veredicto como JSON de una línea. El arnés Node lo invoca para comparar
 * el motor cliente contra el backend autoritativo.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): solo PHP nativo; sin servicios web.
 *   - Artículo V: identificadores en inglés camelCase; documentación en
 *     castellano.
 *
 * Uso: php scratch/bridge_combo_verdict.php '{"activeAura":"fire","incomingSpell":{"id":"s1","element":"water","baseDamage":40},"stunlockImmune":false}'
 * Salida: el veredicto de once claves como JSON de una línea (exit 0), o
 * {"error": "..."} con exit 1 si el payload es ilegible.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../src/Dto/ElementalReactionDto.php';
require __DIR__ . '/../src/Dto/ElementalMatrixGraphDto.php';
require __DIR__ . '/../src/Dto/ComboResolutionResultDto.php';
require __DIR__ . '/../src/Dto/SpellImpactData.php';
require __DIR__ . '/../src/Services/ElementalMatrixService.php';

use Grimorio\Dto\SpellImpactData;
use Grimorio\Services\ElementalMatrixService;

$rawPayload = $argv[1] ?? '';
if ($rawPayload === '') {
    echo json_encode(['error' => 'Uso: php bridge_combo_verdict.php \'{payload JSON}\''], JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(1);
}

try {
    $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new JsonException('El payload debe ser un objeto JSON.');
    }

    $spell = $payload['incomingSpell'] ?? [];
    if (!is_array($spell)) {
        throw new JsonException('El payload exige el objeto incomingSpell.');
    }

    $verdict = (new ElementalMatrixService())->resolveCombo(
        activeAura: (string) ($payload['activeAura'] ?? ''),
        incomingSpell: new SpellImpactData(
            id: (string) ($spell['id'] ?? 'spl_bridge'),
            element: (string) ($spell['element'] ?? ''),
            baseDamage: (int) ($spell['baseDamage'] ?? 0),
            baseHealing: (int) ($spell['baseHealing'] ?? 0),
            baseBarrier: (int) ($spell['baseBarrier'] ?? 0),
            crowdControlType: (string) ($spell['crowdControlType'] ?? 'none'),
        ),
        stunlockImmune: (bool) ($payload['stunlockImmune'] ?? false),
    );

    echo json_encode($verdict, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), PHP_EOL;
    exit(0);
} catch (JsonException $exception) {
    echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(1);
}
