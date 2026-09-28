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

// Chemin de las efigies propias (SPEC-14 — La Efigie en Producción).
//
// DESCOMENTAR SOLO si tu topología difiere de la canónica (repositorio
// completo en htdocs/): la derivación automática del front controller ya
// resuelve htdocs/storage/avatars, y el funnel raíz (.htaccess con
// `RewriteRule ^storage/ - [F,L]`) la niega por URL. Esta constante es la
// válvula para árboles no canónicos (hermano de los GRIMORIO_DB_* de la
// variante MySQL):
//
// if (!defined('GRIMORIO_AVATARS_ROOT')) {
//     define('GRIMORIO_AVATARS_ROOT', $projectRoot . '/storage/avatars');
// }

// ---------------------------------------------------------------------
// Seguridad de sesión y procedencia (SPEC-15 — Tarea 2.3 de TASKS-15).
// ---------------------------------------------------------------------

// Bandera `Secure` de la cookie de vínculo (SPEC-15 RF-01, RF-02):
//
// En la topología verificada de este hosting (apache2handler, HTTPS=on
// como señal DIRECTA de servidor, sin Cloudflare propio ni offload TLS
// hacia PHP), la detección nativa de SessionManager ya es fiable y esta
// constante NO es necesaria. DESCOMENTAR solo como REFUERZO explícito
// (o si la topología cambiara a un offload que ocultara la señal):
//
// if (!defined('GRIMORIO_COOKIE_SECURE')) {
//     define('GRIMORIO_COOKIE_SECURE', true);
// }
//
// REGLA DE ORO (PLAN-15 §4): esta constante jamás podrá establecerse a
// false en la configuración de producción aprobada; ninguna cabecera
// controlable por el cliente (X-Forwarded-Proto, CF-Visitor, ...) participa
// jamás en la decisión de `Secure`.

// Lista de proxies confiables para la procedencia del limitador
// (SPEC-15 RF-03, RNF-06; Tarea 2.2 de TASKS-15):
//
// La POLÍTICA RATIFICADA (Tarea 0.3 de TASKS-15, con la evidencia del
// hosting: X-Forwarded-For idéntica a REMOTE_ADDR, cabeceras de Cloudflare
// ausentes) es la LISTA VACÍA: ninguna cabecera reenviada se consulta y
// la única autoridad de procedencia es REMOTE_ADDR validada. NO POBLAR
// esta lista sin evidencia nueva del soporte del hosting (lista de IPs
// estables de sus proxies y saneamiento verificado de sus cabeceras);
// cualquier valor iría como array de strings IPv4/IPv6 EXACTAS (sin CIDR,
// sin hostnames) y solo tras registrar esa evidencia en TASKS-15:
//
// if (!defined('GRIMORIO_TRUSTED_PROXY_IPS')) {
//     define('GRIMORIO_TRUSTED_PROXY_IPS', []); // Vacía por defecto (política ratificada).
// }
