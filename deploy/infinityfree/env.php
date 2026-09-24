<?php

/**
 * env.php — Configuración de entorno para despliegues sin variables de
 * entorno (hosting compartido, p. ej. InfinityFree).
 *
 * Mecanismo: PHP ejecuta este fichero ANTES del front controller mediante
 * `auto_prepend_file`. La directiva viaja en DOS canales equivalentes
 * (installados como public/.htaccess y public/.user.ini, según la SAPI
 * del hosting — mod_php o CGI/FastCGI). Es PHP nativo puro (Artículo I).
 *
 * Constitución:
 *   - Artículo I: PDO nativo; el DSN apunta a un SQLite PERSISTENTE en
 *     disco, no a `sqlite::memory:` (el fallback de Connection borra
 *     datos y sesiones en cada petición, inviable en producción).
 *   - Artículo V: identificadores en inglés, documentación en castellano.
 *
 * Seguridad (AGENTS.md 6.1): se instala dentro de public/ pero el guard
 * de abort garantiza que jamás responda nada si alguien lo pide por URL
 * directo.
 *
 * Ajuste de instalación: la ruta absoluta de la base debe apuntar al
 * directorio protegido storage/ de TU cuenta. Tras subir el repositorio,
 * sustituye «/TU_RUTA_ABSOLUTA/htdocs» por la ruta real (el panel de
 * InfinityFree la muestra en «Account Details»; en el File Manager se ve
 * como /home/volXX_YY/...).
 */

// Jamás ejecutable como endpoint: solo como fichero antepuesto.
if (!isset($_SERVER['SCRIPT_FILENAME']) || realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Acceso vedado.');
}

// La raíz absoluta del repositorio subido (donde viven src/, public/, database/).
$projectRoot = '/TU_RUTA_ABSOLUTA/htdocs';

// Directorio protegido de la base (chmod 700 recomendado desde el File Manager).
$storageDir = $projectRoot . '/storage';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0700, true);
}

// DSN SQLite persistente: datos y sesiones sobreviven entre peticiones.
// Connection.php auto-bootstrap ejecutará schema.sql + seeds.sql en la
// primera petición (la mesa raíz `spells` aún no existirá).
//
// VÍA `define` (no `putenv`): el sandbox de InfinityFree tiene `putenv`
// en disable_functions y la llamada muere en silencio (verificado con la
// sonda: dsnTrasRequire: null). Connection.php resuelve primero las
// constantes GRIMORIO_DB_* y solo recurre al entorno como fallback.
if (!defined('GRIMORIO_DB_DSN')) {
    define('GRIMORIO_DB_DSN', 'sqlite:' . $storageDir . '/grimorio_live.sqlite');
}
