<?php

/**
 * probe-env.php — Sonda de diagnóstico AMPLIADA del despliegue (subir como
 * public/probe-env.php, abrir en el navegador y BORRAR de inmediato).
 *
 * Informa, sin secretos:
 *   1. SAPI de PHP (apache2handler | cgi-fcgi | ...).
 *   2. Qué cree PHP que vale la directiva auto_prepend_file (ini real).
 *   3. Si env.php existe junto a esta sonda.
 *   4. Si el .htaccess de esta carpeta contiene el bloque de prepención
 *      (se lee por sistema de ficheros, no por HTTP).
 *   5. Si el directorio raíz permite crear storage/ (escribibilidad).
 *
 * Seguridad: jamás imprime credenciales. Borrar tras el diagnóstico.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// --- 1. SAPI -------------------------------------------------------------
$phpSapi = php_sapi_name();

// --- 2. INI real de auto_prepend_file ------------------------------------
$autoPrependIni = ini_get('auto_prepend_file');
if ($autoPrependIni === false || $autoPrependIni === '') {
    $autoPrependIni = null;
}

// --- 3. env.php presente --------------------------------------------------
$envPath = __DIR__ . '/env.php';
$envExists = is_file($envPath);

// --- 4. .htaccess local con el bloque de prepención -----------------------
$htaccessPath = __DIR__ . '/.htaccess';
$htaccessExists = is_file($htaccessPath);
$htaccessHasPrepend = false;
$htaccessBlocked = null; // ¿Apache prohíbe leerlo por filesystem? (raro)
if ($htaccessExists) {
    $raw = @file_get_contents($htaccessPath);
    if ($raw === false) {
        $htaccessBlocked = true;
    } else {
        $htaccessBlocked = false;
        $htaccessHasPrepend = str_contains($raw, 'auto_prepend_file');
    }
}

// --- 5. Raíz del proyecto y escribibilidad --------------------------------
// La sonda vive en public/; la raíz es su padre.
$projectRoot = dirname(__DIR__);
$storageDir = $projectRoot . '/storage';
$storageExists = is_dir($storageDir);
$projectRootWritable = is_writable($projectRoot);
$storageWritable = $storageExists ? is_writable($storageDir) : null;

// Intento real de creación si falta (inocuo: es el mismo gesto de env.php).
$storageCreateAttempt = null;
if (!$storageExists && $projectRootWritable) {
    $storageCreateAttempt = @mkdir($storageDir, 0700, true) ? 'creado' : 'FALLO';
}

// --- 6. Ejecución REAL de env.php (canal del front controller) -------------
// Simula lo que hace public/index.php: require + verificación del DSN.
$dsnAfterRequire = null;
$constantAfterRequire = null;
$sqliteFileExists = null;
$sqliteFileBytes = null;
$dsnBefore = getenv('GRIMORIO_DB_DSN') ?: null;
$putenvDisponible = function_exists('putenv');
if ($envExists) {
    require $envPath; // El guard de env.php no aborta: SCRIPT_FILENAME es esta sonda.
    $dsnAfterRequire = getenv('GRIMORIO_DB_DSN') ?: null;
    $constantAfterRequire = defined('GRIMORIO_DB_DSN') ? constant('GRIMORIO_DB_DSN') : null;
    $dsnResuelto = $constantAfterRequire ?? $dsnAfterRequire;
    if (is_string($dsnResuelto) && str_starts_with($dsnResuelto, 'sqlite:')) {
        $candidate = substr($dsnResuelto, 7);
        if (is_file($candidate)) {
            $sqliteFileExists = true;
            $sqliteFileBytes = filesize($candidate);
        } else {
            $sqliteFileExists = false;
        }
    }
}

echo (string) json_encode([
    'phpSapi'             => $phpSapi,
    'autoPrependIni'      => $autoPrependIni,
    'envPhpExiste'        => $envExists,
    'htaccessExiste'      => $htaccessExists,
    'htaccessLeible'      => $htaccessBlocked === false ? true : ($htaccessBlocked === true ? false : 'sin-fichero'),
    'htaccessTienePrepend'=> $htaccessHasPrepend,
    'raizProyecto'        => $projectRoot,
    'raizEscribible'      => $projectRootWritable,
    'storageExiste'       => $storageExists,
    'storageEscribible'   => $storageWritable,
    'storageIntentoCrear' => $storageCreateAttempt,
    'dsnTrasRequire'      => $dsnAfterRequire,
    'constanteTrasRequire'=> $constantAfterRequire,
    'putenvDisponible'    => $putenvDisponible,
    'dsnValidoSqlite'     => (is_string($constantAfterRequire) && str_starts_with($constantAfterRequire, 'sqlite:'))
        || (is_string($dsnAfterRequire) && str_starts_with($dsnAfterRequire, 'sqlite:')),
    'sqliteFicheroExiste' => $sqliteFileExists,
    'sqliteFicheroBytes'  => $sqliteFileBytes,
    'veredicto'           => (($constantAfterRequire ?? $dsnAfterRequire) !== null)
        ? 'OK: el DSN queda materializado. Abre /api/v1/spells y prueba la web.'
        : 'FALLO: env.php no materializo el DSN ni por constante ni por entorno.',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
