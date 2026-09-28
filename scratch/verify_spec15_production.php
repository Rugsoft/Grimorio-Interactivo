<?php

/**
 * verify_spec15_production.php — Verificación de SPEC-15 en producción
 * (Tarea 4.3 de TASKS-15; procedimiento §9b de la guía de despliegue).
 *
 * ⚠ PUERTA DE AUTORIZACIÓN: este arnés NO se ejecuta sin la autorización
 * explícita del custodio (PLAN-15 §6.4). El custodio debe invocarlo
 * manualmente pasando la bandera --autorizado por argumento; sin ella el
 * arnés se niega a salir de su máquina.
 *
 * Procedimiento de BAJO IMPACTO (guía §9b):
 *   1. Consagración de una cuenta de ensayo (o 409 si ya existe: idempotente).
 *   2. Vínculo y captura de atributos de Set-Cookie (RF-01).
 *   3. Control positivo de sesión (authenticated true).
 *   4. Disolución global: Set-Cookie expiratorio con el mismo alcance (RF-04.1/04.3).
 *   5. Rechazo del token revocado (RF-04.4).
 *   6. Re-vínculo y renuncia: Set-Cookie expiratorio (RF-04.2) + legado (RF-09.3).
 *   7. No filtración: los cuerpos no contienen IP de cliente ni cabeceras (§9.9).
 *
 * Privacidad (RNF-02): el token crudo viaja SOLO en memoria y jamás se
 * imprime ni persiste; las capturas muestran atributos y veredictos, con
 * el valor de la cookie siempre enmascarado.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): curl nativo de PHP, sin librerías.
 *   - Artículo V: identificadores en inglés, leyendas en castellano.
 *
 * Uso: php scratch/verify_spec15_production.php --autorizado [url]
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// Puerta de autorización: sin la bandera explícita, no se sale al mundo.
// ---------------------------------------------------------------------
$autorizado = in_array('--autorizado', $argv, true);
if (!$autorizado) {
    echo "DENEGADO: falta la bandera --autorizado.\n";
    echo "Esta verificación muta producción con una cuenta de ensayo y exige\n";
    echo "la autorización explícita del custodio (PLAN-15 §6.4, guía §9b).\n";
    exit(2);
}

$urlBase = 'https://grimoriointeractivo.freedev.app';
foreach ($argv as $arg) {
    if (str_starts_with($arg, 'http')) {
        $urlBase = rtrim($arg, '/');
    }
}

$assertsPassed = 0;
$assertsFailed = 0;

/** Asegura una condición y la reporta. */
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

/**
 * Cookie global de la sesión de verificación: __test (challenge anti-bot
 * del hosting) + grimorio_session (vínculo). El challenge es transparente
 * para el navegador; el arnés lo resuelve una vez y lo reutiliza.
 */
function antiBotCookie(): string
{
    static $testCookie = null;
    if ($testCookie !== null) {
        return $testCookie;
    }

    // Primera petición: el hosting responde con la prueba JS (AES-CBC).
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 15]]);
    $challenge = (string) @file_get_contents('https://grimoriointeractivo.freedev.app/', false, $ctx);
    if (preg_match('/toNumbers\("([0-9a-f]+)"\),b=toNumbers\("([0-9a-f]+)"\),c=toNumbers\("([0-9a-f]+)"\)/', $challenge, $m) === 1) {
        // slowAES.decrypt(c, 2, a, b): CBC sin padding con data de 16 bytes.
        $raw = openssl_decrypt(hex2bin($m[3]), 'aes-128-cbc', hex2bin($m[1]), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, hex2bin($m[2]));
        if (is_string($raw) && $raw !== '') {
            $testCookie = '__test=' . bin2hex($raw);
        }
    }
    return $testCookie ?? '';
}

/**
 * Petición HTTP con curl nativo: devuelve [cuerpo, setCookies[], status].
 * Las cabeceras Set-Cookie se capturan completas (atributos incluidos);
 * el valor de la cookie se enmascara al imprimir. Inyecta automáticamente
 * la cookie __test del challenge anti-bot del hosting.
 *
 * @return array{0: string, 1: list<string>, 2: int}
 */
function httpRequest(string $url, string $method = 'GET', ?string $jsonBody = null, ?string $cookieHeader = null): array
{
    $setCookies = [];
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$setCookies): int {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $setCookies[] = trim(substr($line, strlen('Set-Cookie:')));
            }
            return strlen($line);
        },
    ]);
    $headers = ['Accept: application/json'];
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($handle, CURLOPT_POSTFIELDS, $jsonBody);
    }
    // El challenge anti-bot viaja SIEMPRE; la cookie de sesión se concatena.
    $cookieParts = [];
    $antiBot = antiBotCookie();
    if ($antiBot !== '') {
        $cookieParts[] = $antiBot;
    }
    if ($cookieHeader !== null) {
        $cookieParts[] = $cookieHeader;
    }
    if ($cookieParts !== []) {
        $headers[] = 'Cookie: ' . implode('; ', $cookieParts);
    }
    curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);

    $body = (string) curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    return [$body, $setCookies, $status];
}

/** Enmascara el valor de la cookie para cualquier impresión (RNF-02). */
function maskCookie(string $setCookieLine): string
{
    return (string) preg_replace('/^(grimorio_session=)[^;]+/', '$1<ENMASCARADO>', $setCookieLine);
}

/** Busca la Set-Cookie de grimorio_session en la lista capturada. */
function sessionCookieOf(array $setCookies): ?string
{
    foreach ($setCookies as $line) {
        if (str_contains($line, 'grimorio_session=')) {
            return $line;
        }
    }
    return null;
}

/** ¿La línea de cookie porta expiración inmediata (Max-Age=0 o Expires pasado)? */
function isExpiring(string $setCookieLine): bool
{
    if (preg_match('/Max-Age=0\b/i', $setCookieLine) === 1) {
        return true;
    }
    if (preg_match('/Expires=([^;]+)/i', $setCookieLine, $m) === 1) {
        $expiresTs = strtotime(trim($m[1]));
        return $expiresTs !== false && $expiresTs < time();
    }
    return false;
}

/** Extrae el valor crudo de la cookie de sesión (uso interno, jamás impreso). */
function extractSessionToken(string $setCookieLine): ?string
{
    if (preg_match('/grimorio_session=([^;]+)/', $setCookieLine, $m) === 1) {
        return $m[1];
    }
    return null;
}

echo "=== Tarea 4.3 (TASKS-15): verificación de SPEC-15 en producción ===\n";
echo "Objetivo: {$urlBase}\n";
echo "Cuenta de ensayo efímera; el residuo canónico es el legado anónimo (RF-09.3).\n\n";

// ---------------------------------------------------------------------
// Identidad de la cuenta de ensayo (efímera, jamás impresa la frase).
// ---------------------------------------------------------------------
$ensayoAlias = 'EnsayoSpec15' . date('YmdHi');
$ensayoEmail = 'ensayo.spec15.' . date('YmdHi') . '@ejemplo.sanctuario';
$ensayoPass  = 'frase-ensayo-' . bin2hex(random_bytes(12));

// 1. Consagración (201; 409 si el alias ya existiera: idempotente).
echo "[1] Consagración de la cuenta de ensayo (RF-01)\n";
[$body, $setCookies, $status] = httpRequest(
    $urlBase . '/api/v1/auth/consecrate',
    'POST',
    (string) json_encode(['alias' => $ensayoAlias, 'email' => $ensayoEmail, 'passphrase' => $ensayoPass])
);
assertArcane(in_array($status, [201, 409], true), "La consagración responde 201 (o 409 si ya existía): {$status}");
$consecrateCookie = sessionCookieOf($setCookies);

// 2. Atributos de la cookie emitida (RF-01.1) — sobre la cabecera cruda.
echo "\n[2] Cookie del vínculo sobre HTTPS (RF-01.1)\n";
if ($consecrateCookie !== null) {
    echo "       " . maskCookie($consecrateCookie) . "\n";
    assertArcane(stripos($consecrateCookie, 'secure') !== false, 'La cookie porta Secure (HTTPS real de producción)');
    assertArcane(stripos($consecrateCookie, 'httponly') !== false, 'La cookie porta HttpOnly');
    assertArcane(stripos($consecrateCookie, 'samesite=strict') !== false, 'La cookie porta SameSite=Strict');
    assertArcane(stripos($consecrateCookie, 'path=/') !== false, 'La cookie porta Path=/');
    assertArcane(stripos($consecrateCookie, 'max-age=1209600') !== false, 'La cookie caduca a los 14 días (Max-Age=1209600)');
} else {
    assertArcane(false, 'La consagración emitió Set-Cookie de sesión');
}

// 3. Vínculo con credenciales correctas (token en memoria, jamás impreso).
echo "\n[3] Vínculo y control positivo de sesión (RF-02)\n";
[$bindBody, $bindCookies, $bindStatus] = httpRequest(
    $urlBase . '/api/v1/auth/bind',
    'POST',
    (string) json_encode(['identity' => $ensayoEmail, 'passphrase' => $ensayoPass])
);
assertArcane($bindStatus === 200, "El vínculo de la cuenta de ensayo responde 200: {$bindStatus}");
$bindCookieLine = sessionCookieOf($bindCookies);
$rawToken = $bindCookieLine !== null ? extractSessionToken($bindCookieLine) : null;
assertArcane(is_string($rawToken) && $rawToken !== '', 'La cabecera Set-Cookie del vínculo porta el token (en memoria, enmascarado en salida)');

$sessionBody = '';
if (is_string($rawToken) && $rawToken !== '') {
    [$sessionBody, , $sessionStatus] = httpRequest(
        $urlBase . '/api/v1/auth/session',
        'GET',
        null,
        'grimorio_session=' . $rawToken
    );
    assertArcane($sessionStatus === 200 && str_contains($sessionBody, '"authenticated":true'), 'Control positivo: el token vigente autentica en producción');
}

// 4. Disolución global: Set-Cookie expiratorio con el mismo alcance (RF-04.1/04.3).
echo "\n[4] Disolución global: expiración de la cookie portadora (RF-04.1/04.3)\n";
if (is_string($rawToken) && $rawToken !== '') {
    [$dissolveBody, $dissolveCookies, $dissolveStatus] = httpRequest(
        $urlBase . '/api/v1/auth/dissolve-all',
        'POST',
        null,
        'grimorio_session=' . $rawToken
    );
    assertArcane($dissolveStatus === 200 && str_contains($dissolveBody, '"success":true'), "La disolución global responde 200 con éxito: {$dissolveStatus}");
    $dissolveCookie = sessionCookieOf($dissolveCookies);
    if ($dissolveCookie !== null) {
        echo "       " . maskCookie($dissolveCookie) . "\n";
        assertArcane(isExpiring($dissolveCookie), 'La respuesta porta Set-Cookie EXPIRATORIO de grimorio_session (RF-04.1)');
        assertArcane(stripos($dissolveCookie, 'path=/') !== false, 'La expiración conserva Path=/ (RF-04.3)');
        assertArcane(stripos($dissolveCookie, 'httponly') !== false, 'La expiración conserva HttpOnly (RF-04.3)');
        assertArcane(stripos($dissolveCookie, 'samesite=strict') !== false, 'La expiración conserva SameSite=Strict (RF-04.3)');
    } else {
        assertArcane(false, 'La disolución porta Set-Cookie de grimorio_session (cabecera ausente)');
    }

    // 5. Rechazo del token revocado (RF-04.4).
    echo "\n[5] Rechazo del token revocado (RF-04.4)\n";
    [$staleBody, , $staleStatus] = httpRequest(
        $urlBase . '/api/v1/auth/session',
        'GET',
        null,
        'grimorio_session=' . $rawToken
    );
    assertArcane($staleStatus === 200 && str_contains($staleBody, '"authenticated":false'), 'El token revocado NO autentica una petición posterior');
} else {
    echo "  (omitido: sin token de vínculo)\n";
}

// 6. Re-vínculo y renuncia: Set-Cookie expiratorio (RF-04.2) + legado (RF-09.3).
echo "\n[6] Renuncia: expiración de la cookie portadora (RF-04.2, RF-09.3)\n";
[$rebindBody, $rebindCookies, $rebindStatus] = httpRequest(
    $urlBase . '/api/v1/auth/bind',
    'POST',
    (string) json_encode(['identity' => $ensayoEmail, 'passphrase' => $ensayoPass])
);
$rebindCookieLine = sessionCookieOf($rebindCookies);
$rebindToken = $rebindCookieLine !== null ? extractSessionToken($rebindCookieLine) : null;
assertArcane($rebindStatus === 200 && is_string($rebindToken) && $rebindToken !== '', 'El re-vínculo de la cuenta de ensayo responde 200');

if (is_string($rebindToken) && $rebindToken !== '') {
    [$renounceBody, $renounceCookies, $renounceStatus] = httpRequest(
        $urlBase . '/api/v1/auth/renounce-account',
        'POST',
        null,
        'grimorio_session=' . $rebindToken
    );
    assertArcane($renounceStatus === 200 && str_contains($renounceBody, '"success":true'), "La renuncia responde 200 con éxito: {$renounceStatus}");
    $renounceCookie = sessionCookieOf($renounceCookies);
    if ($renounceCookie !== null) {
        echo "       " . maskCookie($renounceCookie) . "\n";
        assertArcane(isExpiring($renounceCookie), 'La respuesta de renuncia porta Set-Cookie EXPIRATORIO (RF-04.2)');
        assertArcane(stripos($renounceCookie, 'path=/') !== false && stripos($renounceCookie, 'httponly') !== false, 'La expiración de la renuncia conserva el alcance (RF-04.3)');
    } else {
        assertArcane(false, 'La renuncia porta Set-Cookie de grimorio_session (cabecera ausente)');
    }

    // El token de la cuenta renunciada ya no autentica (RF-04.4).
    [$postBody, , ] = httpRequest(
        $urlBase . '/api/v1/auth/session',
        'GET',
        null,
        'grimorio_session=' . $rebindToken
    );
    assertArcane(str_contains($postBody, '"authenticated":false'), 'El token de la cuenta renunciada NO autentica');
}

// 7. No filtración (§9 criterio 9): los cuerpos no filtran IP ni cabeceras.
echo "\n[7] No filtración en las respuestas (§9 criterio 9)\n";
$allBodies = $body . $bindBody . $sessionBody . ($dissolveBody ?? '') . ($renounceBody ?? '');
$leak = stripos($allBodies, 'x-forwarded') !== false
    || stripos($allBodies, 'remote_addr') !== false
    || stripos($allBodies, 'ip_address') !== false;
assertArcane(!$leak, 'Los cuerpos JSON no contienen IP de cliente ni cabeceras de procedencia');

// ---------------------------------------------------------------------
// Cierre: la memoria del token muere con el proceso; nada se persiste.
// ---------------------------------------------------------------------
unset($rawToken, $rebindToken, $bindCookieLine, $rebindCookieLine);

echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
echo "Registra estos veredictos (con fecha) en la guía §9b y en TASKS-15.\n";
exit($assertsFailed === 0 ? 0 : 1);
