<?php

/**
 * test_spell_balance_bridge.php — Puente de simetría PHP-JS (Tarea 5.1).
 *
 * Arnés de verificación (no es código de producción): recibe por argv el
 * payload JSON del simulador cliente, lo materializa en el DTO canónico
 * y ejecuta el SpellBalanceService REAL de producción. Devuelve el
 * desglose en el mismo contrato JSON que simulateMana() del cliente
 * (o {overload: true, calculatedMana} ante Sobrecarga Arcana), de modo
 * que el arnés Node pueda comparar ambos motores campo a campo.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro vía CLI, sin servicios web.
 *   - Artículo II: el motor ejecutado es el del backend real, jamás una
 *     reimplementación para la prueba.
 *   - Artículo V: identificadores en inglés camelCase, comentarios en
 *     castellano.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Dto/SpellCalculationInputDto.php';
require __DIR__ . '/../src/Dto/SpellCalculationResultDto.php';
require __DIR__ . '/../src/Exceptions/ArcaneOverloadException.php';
require __DIR__ . '/../src/Services/SpellBalanceService.php';

use Grimorio\Dto\SpellCalculationInputDto;
use Grimorio\Exceptions\ArcaneOverloadException;
use Grimorio\Services\SpellBalanceService;

$rawPayload = $argv[1] ?? '';
if ($rawPayload === '') {
    fwrite(STDERR, "Uso: php test_spell_balance_bridge.php '{payload JSON}'\n");
    exit(2);
}

$payload = json_decode($rawPayload, true);
if (!is_array($payload)) {
    fwrite(STDERR, "El payload no es JSON válido.\n");
    exit(2);
}

// El puente usa la misma coerción estricta que el endpoint de producción.
try {
    $inputDto = SpellCalculationInputDto::fromArray($payload);
} catch (InvalidArgumentException $invalidInput) {
    fwrite(STDERR, $invalidInput->getMessage() . "\n");
    exit(2);
}

try {
    $result = (new SpellBalanceService())->calculate($inputDto);
} catch (ArcaneOverloadException $overload) {
    // La sobrecarga también forma parte del contrato de simetría.
    echo json_encode([
        'overload'       => true,
        'calculatedMana' => $overload->getCalculatedMana(),
        'errorCode'      => $overload->getErrorCode(),
    ], JSON_UNESCAPED_UNICODE);
    exit(0);
}

// El DTO materializa el desglose pedagógico con claves camelCase idénticas.
echo json_encode($result, JSON_UNESCAPED_UNICODE);
exit(0);
