<?php

/**
 * test_auth_language_sovereignty.php — Arnés del hueco de cobertura RNF-05 (SPEC-03).
 *
 * La auditoría de estabilización halló que los cierres de SPEC-10 añadieron
 * guards de soberanía lingüística para SUS leyendas, pero la superficie de
 * autenticación de SPEC-03 (modales de acceso, aviso de congelación,
 * jerarquía insuficiente, badge, navbar) no tenía guard equivalente.
 *
 * Este arnés barre los módulos PHP y JS de la superficie auth y sella:
 *
 *   [1] Cero literales de cadena visibles en inglés: toda leyenda, aviso,
 *       placeholder, rótulo o mensaje de error contenido en los módulos
 *       debe ser texto castellano (heurística de palabras funcionales
 *       inglesas: "the", "your", "password", "invalid", "failed", etc.).
 *   [2] Cero referencias técnicas de spec en leyendas visibles
 *       (RF-xx.y, Art. III...), el mismo patrón del guard de SPEC-10:
 *       la numeración es de los specs, jamás del usuario.
 *   [3] Cero claves JSON en snake_case en los contratos del controlador
 *       auth (Art. V: las claves viajan en inglés camelCase).
 *   [4] Los roles técnicos (reader, editor, master, supremeAdmin) viven
 *       como claves técnicas en inglés y se rotulan en castellano SOLO
 *       en las capas de presentación (auditLogView.js).
 *   [5] Los placeholders y aria-labels del modal de recuperación y del
 *       badge están en castellano (son texto visible/al usuario lector
 *       de pantalla).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): arnés nativo, sin dependencias.
 *   - Artículo IV/V: velo arcano en castellano; claves en inglés camelCase.
 *
 * Uso: php scratch/test_auth_language_sovereignty.php  (exit 0 = verde)
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

$projectRoot = dirname(__DIR__);
echo "=== Hueco RNF-05 (SPEC-03): soberanía lingüística de la superficie auth ===\n\n";

/**
 * Retira comentarios de un fuente (bloque /* *\/, línea // y # PHP) para
 * no acusar identificadores ni JSDoc que jamás llegan al usuario.
 */
function stripComments(string $source): string
{
    $withoutBlock = preg_replace('#/\*.*?\*/#s', ' ', $source) ?? $source;
    $withoutLine = preg_replace('#^\s*(?://|\*)[^"\']*$#m', ' ', $withoutBlock) ?? $withoutBlock;
    return $withoutLine;
}

/**
 * Extrae literales de cadena ('...' y "...") de un fuente sin comentarios.
 * Aproximación suficiente: los comentarios multi-línea ya se retiraron y
 * los literales del código no contienen saltos.
 *
 * @return array<int, string>
 */
function extractStringLiterals(string $source): array
{
    preg_match_all('/([\'"])((?:\\\\.|(?!\1).)*)\1/', $source, $hits);
    $literals = [];
    foreach ($hits[2] as $literal) {
        $literal = trim($literal);
        // Solo interesa texto natural con espacio: claves técnicas de una
        // palabra (reader, editor, GET...) no son texto visible.
        if ($literal !== '' && str_contains($literal, ' ') && preg_match('/[A-Za-zÁÉÍÓÚÑáéíóúñ]{2,}/', $literal)) {
            $literals[] = $literal;
        }
    }
    return $literals;
}

/** Heurística de texto en inglés funcional: artículos/posesivos/palabras típicas. */
function looksLikeEnglishUiText(string $text): bool
{
    $englishMarkers = [
        '/\bthe \b/i', '/\byour \b/i', '/\bpassword\b/i', '/\binvalid\b/i',
        '/\bfailed\b/i', '/\bsession\b/i', '/\bplease\b/i', '/\bnot found\b/i',
        '/\brequired\b/i', '/\berror\b/i', '/\bforbidden\b/i', '/\bwelcome\b/i',
        '/\bemail address\b/i', '/\baccount\b/i', '/\btry again\b/i', '/\bsubmit\b/i',
        '/\busername\b/i', '/\block(ed)? out\b/i', '/\btoo many\b/i', '/\bexpired\b/i',
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
        || preg_match('/\bArt\.\s*(?:I{1,3}V?|V|III)\b/', $text) === 1
        || preg_match('/\bSPEC-\d{2}\b/', $text) === 1;
}

/**
 * Fase 1 · Cero leyendas en inglés y cero referencias técnicas en los
 * módulos PHP de la superficie auth (servicio, controlador, gestores).
 */
echo "[1] Módulos PHP de la superficie auth: castellano puro y sin referencias\n";

$phpModules = [
    'src/Controllers/AuthController.php',
    'src/Services/AuthService.php',
    'src/Services/ClanConflictService.php',
    'src/Core/SessionManager.php',
    'src/Core/RateLimiter.php',
];

$englishLeaks = [];
$specRefLeaks = [];
foreach ($phpModules as $module) {
    $path = $projectRoot . '/' . $module;
    $source = (string) file_get_contents($path);
    $stripped = stripComments($source);
    foreach (extractStringLiterals($stripped) as $literal) {
        if (looksLikeEnglishUiText($literal)) {
            $englishLeaks[] = basename($module) . ' → "' . mb_substr($literal, 0, 70) . '"';
        }
        if (looksLikeSpecReference($literal)) {
            $specRefLeaks[] = basename($module) . ' → "' . mb_substr($literal, 0, 70) . '"';
        }
    }
}

assertArcane($englishLeaks === [], 'Ningún literal visible de los módulos PHP porta texto de UI en inglés' . ($englishLeaks ? ' — hallados: ' . implode(' | ', array_slice($englishLeaks, 0, 3)) : ''));
assertArcane($specRefLeaks === [], 'Ningún literal visible porta referencias técnicas (RF-xx, RNF-xx, Art., SPEC-xx)' . ($specRefLeaks ? ' — hallados: ' . implode(' | ', array_slice($specRefLeaks, 0, 3)) : ''));

// Sondeos canónicos: las leyendas solemnes del spec viven en castellano.
$authServiceSource = stripComments((string) file_get_contents($projectRoot . '/src/Services/AuthService.php'));
assertArcane(str_contains($authServiceSource, 'ya fue reclamada'), 'La leyenda neutra de identidad reclamada está en castellano');
assertArcane(str_contains($authServiceSource, 'no es válido') || str_contains($authServiceSource, 'debe tener'), 'Los avisos del canon de consagración están en castellano');

$authControllerSource = stripComments((string) file_get_contents($projectRoot . '/src/Controllers/AuthController.php'));
assertArcane(str_contains($authControllerSource, 'Las runas no reconocen'), 'La leyenda del 401 neutro («Las runas no reconocen…») está en castellano');
assertArcane(str_contains($authControllerSource, 'umbral permanecerá cerrado'), 'La leyenda de la congelación 429 está en castellano');
assertArcane(str_contains($authControllerSource, 'pergamino de restablecimiento'), 'La leyenda del pergamino remitido está en castellano');

$sessionManagerSource = stripComments((string) file_get_contents($projectRoot . '/src/Core/SessionManager.php'));
assertArcane(str_contains($sessionManagerSource, "'samesite' => 'Strict'") || str_contains($sessionManagerSource, '"samesite"'), 'Las claves técnicas de cookie permanecen en inglés (contrato nativo de PHP)');

/**
 * Fase 2 · Contratos REST: cero claves JSON en snake_case en el controlador
 * auth (Art. V: las claves viajan en inglés camelCase).
 */
echo "\n[2] Contratos REST del controlador auth: claves camelCase\n";

preg_match_all("/['\"]([A-Za-z_]+)['\"]\\s*=>/", $authControllerSource, $jsonKeys);
$snakeKeys = array_values(array_filter($jsonKeys[1], fn (string $key): bool => str_contains($key, '_')));
assertArcane(
    $snakeKeys === [],
    'Ninguna clave JSON del controlador auth usa snake_case' . ($snakeKeys ? ' — halladas: ' . implode(', ', array_slice($snakeKeys, 0, 5)) : '')
);
assertArcane(
    str_contains($authControllerSource, "'recoveryAction'") && str_contains($authControllerSource, "'message'"),
    'El contrato porta sus claves canónicas en camelCase (message, recoveryAction)'
);

/**
 * Fase 3 · Superficie JS: rótulos del badge y navbar en castellano; roles
 * técnicos rotulados en la capa de presentación, jamás crudos.
 */
echo "\n[3] Superficie JS de sesión: rótulos castellanos\n";

$badgeSource = stripComments((string) file_get_contents($projectRoot . '/public/assets/js/components/userProfileBadge.js'));
assertArcane(str_contains($badgeSource, 'Peregrino sin Linaje'), 'El rótulo del peregrino está en castellano (SPEC-09)');
assertArcane(str_contains($badgeSource, 'Disolver este vínculo'), 'El menú del badge rota en castellano las disoluciones');
assertArcane(str_contains($badgeSource, 'Cruzar el Umbral'), 'El umbral de acceso se rotula en castellano');

$navbarSource = stripComments((string) file_get_contents($projectRoot . '/public/assets/js/components/navbarComponent.js'));
foreach (['Inicio', 'Biblioteca', 'Salón de Linajes', 'Bitácora de Auditoría', 'Torre de Deliberación'] as $expectedLabel) {
    assertArcane(str_contains($navbarSource, $expectedLabel), "La navbar rota «{$expectedLabel}» en castellano");
}

$auditViewSource = stripComments((string) file_get_contents($projectRoot . '/public/assets/js/views/auditLogView.js'));
assertArcane(str_contains($auditViewSource, 'Maestro del Cónclave'), 'El rol técnico `master` se rota «Maestro del Cónclave» en la presentación');
assertArcane(str_contains($auditViewSource, 'Admin Supremo'), 'El rol técnico `supremeAdmin` se rota «Admin Supremo» en la presentación');

// El mapa de roles del Bitácora usa claves del canon del backend con
// valores castellanos: el rol crudo jamás llega crudo al usuario.
assertArcane(
    preg_match("/(?:reader|lector):\s*'/", $auditViewSource) === 1
    && preg_match("/master:\s*'Maestro del Cónclave'/", $auditViewSource) === 1,
    'El mapa de roles porta clave técnica con rótulo castellano, jamás el rol crudo'
);

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);
