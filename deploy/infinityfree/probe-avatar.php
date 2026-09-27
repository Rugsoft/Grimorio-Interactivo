<?php

/**
 * probe-avatar.php — Sonda de diagnóstico de la efigie en producción
 * (SPEC-14, RF-03). Subir como public/probe-avatar.php, abrir en el
 * navegador, leer el veredicto y BORRAR del servidor de inmediato.
 *
 * Informa, sin secretos y con veredicto accionable en castellano:
 *   a. Chemin de las efigies: resolución (mismo criterio que el front
 *      controller), existencia y escribibilidad real.
 *   b. Fragua gráfica (GD): extensión, funciones del canon y una
 *      codificación/decodificación REAL con encuadre 512×512.
 *   c. Topes de transporte: upload_max_filesize y post_max_size
 *      efectivos, comparados contra el canon de SPEC-12.
 *   d. Funnel raíz vivo (doble vía): lectura del .htaccess raíz por
 *      sistema de ficheros + petición HTTP de prueba al almacenamiento.
 *
 * Seguridad: jamás imprime credenciales ni rutas absolutas completas
 * (solo la cola del chemin, recortada). Todo fichero de prueba se purga
 * en el bloque final. Convención del santuario: BORRAR tras el uso.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// Canon de SPEC-12 (ratificado; esta sonda NO lo altera, solo lo mide).
const CANON_MAX_BYTES = 2 * 1024 * 1024;   // peso máximo del fichero (2 MiB)
const CANON_REQUEST_BYTES = 4 * 1024 * 1024; // tope de petición que asume el 413
const FRAME_SIDE = 512;                    // marco ceremonial efectivo

/** Veredicto de una comprobación: EXITO | FALLO | NO_VERIFICABLE. */
function forgeVerdict(bool $ok, bool $unknown = false): string
{
    return $unknown ? 'NO_VERIFICABLE' : ($ok ? 'EXITO' : 'FALLO');
}

/** Normaliza un tope INI (p. ej. «2M», «512K») a bytes, o null si es ilimitado. */
function iniToBytes(?string $raw): ?int
{
    if ($raw === null || $raw === '' || strtolower(trim($raw)) === '-1') {
        return null; // ilimitado (o desconocido)
    }
    $value = (float) trim($raw);
    $suffix = strtolower(substr(trim($raw), -1));
    return (int) match ($suffix) {
        'k' => $value * 1024,
        'm' => $value * 1024 * 1024,
        'g' => $value * 1024 * 1024 * 1024,
        default => $value,
    };
}

/** Cola del chemin para el informe (jamás la ruta absoluta completa). */
function publicTail(string $path): string
{
    $parts = preg_split('#[/\\\\]+#', $path) ?: [];
    return implode('/', array_slice($parts, -3));
}

// ---------------------------------------------------------------------
// (a) El chemin de las efigies — mismo criterio que public/index.php
// ---------------------------------------------------------------------
$avatarsRoot = defined('GRIMORIO_AVATARS_ROOT')
    && is_string(GRIMORIO_AVATARS_ROOT)
    && GRIMORIO_AVATARS_ROOT !== ''
    ? GRIMORIO_AVATARS_ROOT
    : dirname(__DIR__, 2) . '/storage/avatars';

// Nota de paridad: el front controller deriva con dirname(__DIR__) desde
// public/ (un nivel), la sonda vive también en public/ al instalarla, de
// modo que dirname(__DIR__, 2) NO sería correcto aquí si estuviera en
// public/. Esta sonda se instala en public/, por eso el nivel correcto
// desde public/ es UNO (igual que el front controller).
if (!defined('GRIMORIO_AVATARS_ROOT')) {
    $avatarsRoot = dirname(__DIR__) . '/storage/avatars';
}

$cheminExistedBefore = is_dir($avatarsRoot);
$cheminCreatedByProbe = false;
$testFile = $avatarsRoot . '/sonda-probe-' . bin2hex(random_bytes(4)) . '.txt';
$cheminWritable = false;
$cheminError = null;

if (!is_dir($avatarsRoot) && !@mkdir($avatarsRoot, 0777, true) && !is_dir($avatarsRoot)) {
    $cheminError = 'El directorio no existe y no pudo crearse (revisa permisos del árbol).';
} else {
    $cheminCreatedByProbe = !$cheminExistedBefore;
    try {
        if (@file_put_contents($testFile, 'sonda') !== false && is_file($testFile)) {
            $cheminWritable = is_writable($testFile);
            @unlink($testFile);
        } else {
            $cheminError = 'El directorio existe pero rechaza la escritura de ficheros.';
        }
    } catch (\Throwable $writeFailure) {
        $cheminError = 'La escritura de prueba fracasó de forma inesperada.';
    }
}

// ---------------------------------------------------------------------
// (b) La fragua gráfica (GD) — extensión, funciones y prueba REAL
// ---------------------------------------------------------------------
$gdLoaded = extension_loaded('gd');
$gdFunctions = ['imagecreatefrompng', 'imagecreatefromjpeg', 'imagecreatefromwebp', 'imagecreatetruecolor', 'imagecopyresampled', 'imagepng'];
$missingFunctions = $gdLoaded ? array_values(array_filter($gdFunctions, fn (string $fn): bool => !function_exists($fn))) : $gdFunctions;
$gdRealTest = false;
$gdRealError = null;
$gdTempPath = null;

if ($gdLoaded && $missingFunctions === []) {
    try {
        // PNG sintético 1024×1024 → decodificación → encuadre 512×512 →
        // codificación a disco (el MISMO tránsito que uploadOwn()).
        $source = imagecreatetruecolor(1024, 1024);
        if ($source !== false) {
            imagefill($source, 0, 0, (int) imagecolorallocate($source, 40, 20, 60));
            $gdTempPath = tempnam(sys_get_temp_dir(), 'sonda_gd_') . '.png';
            if (imagepng($source, $gdTempPath)) {
                $decoded = imagecreatefrompng($gdTempPath);
                if ($decoded !== false) {
                    $frame = imagecreatetruecolor(FRAME_SIDE, FRAME_SIDE);
                    if ($frame !== false) {
                        $gdRealTest = imagecopyresampled($frame, $decoded, 0, 0, 0, 0, FRAME_SIDE, FRAME_SIDE, 1024, 1024)
                            && imagepng($frame, $gdTempPath);
                        imagedestroy($frame);
                    }
                    imagedestroy($decoded);
                }
            }
            imagedestroy($source);
        }
        if (!$gdRealTest) {
            $gdRealError = 'La fragua vive pero el tránsito real de encuadre fracasó.';
        }
    } catch (\Throwable $gdFailure) {
        $gdRealError = 'La fragua gráfica falló de forma inesperada.';
    }
}

// ---------------------------------------------------------------------
// (c) Los topes de transporte
// ---------------------------------------------------------------------
$uploadMax = iniToBytes(ini_get('upload_max_filesize'));
$postMax = iniToBytes(ini_get('post_max_size'));
$uploadOk = $uploadMax === null || $uploadMax >= CANON_MAX_BYTES;
$postOk = $postMax === null || $postMax >= CANON_REQUEST_BYTES;

// ---------------------------------------------------------------------
// (d) El funnel raíz — doble vía (fichero + petición HTTP)
// ---------------------------------------------------------------------
$htaccessPath = dirname(__DIR__) . '/.htaccess';
$htaccessExists = is_file($htaccessPath);
$htaccessDeniesStorage = false;
if ($htaccessExists) {
    $htaccessContent = (string) @file_get_contents($htaccessPath);
    // El funnel canónico: niega el árbol storage/ por URL con flag F.
    $htaccessDeniesStorage = (bool) preg_match('#RewriteRule\s+\^storage/\s+-\s+\[F[,\]]#i', $htaccessContent);
}

// Vía 2: petición HTTP de prueba contra el fichero de prueba del chemin.
// Solo tiene sentido si (a) logró escribir; honestidad antes que confort:
// si el transporte no está disponible, declara NO_VERIFICABLE.
$httpDeniesStorage = null;
$probeUrl = null;
if ($cheminWritable && $htaccessExists) {
    $canary = $avatarsRoot . '/sonda-http-' . bin2hex(random_bytes(4)) . '.txt';
    if (@file_put_contents($canary, 'SECRETO_DE_SONDA') !== false) {
        $base = dirname($_SERVER['SCRIPT_NAME'] ?? '/probe-avatar.php'); // …/public
        $probeUrl = str_replace('/public', '', $base) // el funnel reescribe /public/*
            . '/storage/avatars/' . basename($canary);
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 8]]);
        $body = @file_get_contents('http' . (!empty($_SERVER['HTTPS']) ? 's' : '') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . $probeUrl, false, $context);
        // EXITO = el contenido NO llegó (403/404 del funnel); si el cuerpo
        // volviera íntegro, el funnel está muerto y la efigie expuesta.
        $httpDeniesStorage = $body === false || !str_contains((string) $body, 'SECRETO_DE_SONDA');
        @unlink($canary);
    }
}

// ---------------------------------------------------------------------
// Veredicto accionable
// ---------------------------------------------------------------------
$remedies = [];
if ($cheminError !== null) {
    $remedies[] = 'Chemin: ' . $cheminError . ' Remedio: crea el directorio desde el File Manager y dale permisos 700, o declara GRIMORIO_AVATARS_ROOT en env.php si tu topología no es canónica.';
}
if (!$gdLoaded || $missingFunctions !== []) {
    $remedies[] = 'GD: faltan herramientas del canon (' . implode(', ', $missingFunctions === [] ? ['extensión gd'] : $missingFunctions) . '). Remedio: contacta al hospedaje o restringe el canon de formatos anunciado.';
} elseif (!$gdRealTest) {
    $remedies[] = 'GD: ' . ($gdRealError ?? 'el tránsito real fracasó.');
}
if (!$uploadOk || !$postOk) {
    $remedies[] = 'Transporte: los topes del hospedaje son menores que el canon (fichero 2 MiB / petición 4 MiB). Los envíos sobre upload_max_filesize recibirán aviso honesto; los sobre post_max_size morirán antes de la aplicación (no hay aviso posible): ajusta el canon anunciado o consulta al hospedaje.';
}
if (!$htaccessExists || !$htaccessDeniesStorage || $httpDeniesStorage === false) {
    $remedies[] = 'Funnel: el .htaccess raíz no está negando storage/ por URL — la efigie propia podría exponerse. Remedio: reinstala el .htaccess raíz del paquete de despliegue (htaccess-root) y repite esta sonda.';
}

$allCritical = $cheminError === null
    && $gdLoaded && $missingFunctions === [] && $gdRealTest
    && $uploadOk && $postOk
    && $htaccessExists && $htaccessDeniesStorage
    && ($httpDeniesStorage === null || $httpDeniesStorage === true);

echo json_encode([
    'verdict' => $allCritical ? 'EXITO' : 'REVISAR',
    'checks' => [
        'chemin' => [
            'resolved' => publicTail($avatarsRoot),
            'exists' => $cheminError === null,
            'writable' => $cheminWritable,
            'verdict' => $cheminError === null && $cheminWritable ? 'EXITO' : 'FALLO',
            'createdByProbe' => $cheminCreatedByProbe,
        ],
        'gd' => [
            'loaded' => $gdLoaded,
            'missingFunctions' => $missingFunctions,
            'realFrameTest512' => $gdRealTest,
            'verdict' => forgeVerdict($gdLoaded && $missingFunctions === [] && $gdRealTest),
        ],
        'transport' => [
            'uploadMaxFilesize' => ini_get('upload_max_filesize'),
            'postMaxSize' => ini_get('post_max_size'),
            'canon' => ['fileBytes' => CANON_MAX_BYTES, 'requestBytes' => CANON_REQUEST_BYTES],
            'verdict' => forgeVerdict($uploadOk && $postOk),
        ],
        'funnel' => [
            'htaccessExists' => $htaccessExists,
            'htaccessDeniesStorage' => $htaccessDeniesStorage,
            'httpDenied' => $httpDeniesStorage,
            'httpProbeUrl' => $probeUrl,
            'verdict' => forgeVerdict($htaccessExists && $htaccessDeniesStorage && ($httpDeniesStorage !== false), $httpDeniesStorage === null && (!$htaccessExists || !$htaccessDeniesStorage) === false && $httpDeniesStorage === null),
        ],
    ],
    'remedies' => $remedies,
    'note' => 'BORRA ESTA SONDA DEL SERVIDOR TRAS EL DIAGNÓSTICO (convención del santuario, SPEC-14 RF-03.3).',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// ---------------------------------------------------------------------
// Purga íntegra de restos (el servidor queda como estaba)
// ---------------------------------------------------------------------
if (isset($gdTempPath) && is_string($gdTempPath) && is_file($gdTempPath)) {
    @unlink($gdTempPath);
}
if ($cheminCreatedByProbe && $cheminError === null && is_dir($avatarsRoot)) {
    // Si la sonda CREÓ el árbol y no queda nada de ella dentro, lo deja
    // como estaba: el diagnóstico no deja estado en el servidor.
    $leftovers = glob($avatarsRoot . '/sonda-*') ?: [];
    if ($leftovers === [] && count((array) @scandir($avatarsRoot)) <= 2) {
        @rmdir($avatarsRoot);
        // Si además ella creó storage/ (padre) vacío, también.
        $parent = dirname($avatarsRoot);
        if (!$cheminExistedBefore && is_dir($parent) && count((array) @scandir($parent)) <= 2) {
            @rmdir($parent);
        }
    }
}
