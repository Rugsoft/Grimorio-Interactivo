<?php

/**
 * test_bind_envelope_clan.php — Arnés de la Tarea 0 de TASKS-18 (SPEC-18).
 *
 * Verifica que `POST /api/v1/auth/bind` entregue un sobre `data.user` que
 * declara la hermandad del titular, y que ese sobre sea IDÉNTICO al que
 * entrega `GET /api/v1/auth/session` para la misma persona.
 *
 * Por qué existe (SPEC-18 §1.1): `forgeUserPayload()` extrae cada campo
 * buscando en la fila de `users` la clave por su nombre de CONTRATO
 * (`clanId`), pero las columnas se llaman en `snake_case` (`clan_id`).
 * `array_key_exists('clanId', $userRow)` es por tanto siempre `false` y el
 * cierre cae a su respaldo. Cinco campos pasan por ahí; cuatro coinciden
 * con su columna y se resuelven solos. Solo `clanId` no coincide, y es el
 * único roto. `session` no lo nota porque el `AuthMiddleware` le pasa
 * además la entidad `User`, que sí traduce. `bind` no tiene entidad —la
 * petición aún no está vinculada— y se queda solo con la fila.
 *
 * ESTRATEGIA (TDD, doctrina «No Spec, No Code»): este arnés se escribe
 * ANTES de tocar `src/Controllers/AuthController.php`. Hoy debe SALIR EN
 * ROJO nombrando `clanId`. Sin ese rojo no hay prueba de que la prueba
 * sirva: un arnés que ya pasa no demuestra nada sobre el arreglo.
 *
 * Tres fases, y las tres importan:
 *   [1] Titular CON hermandad → clanId y clanName correctos.  ← FALLA HOY
 *   [2] Titular SIN hermandad → clanId null y clanName "".     ← PASA HOY
 *   [3] bind y session dicen lo MISMO, campo a campo.          ← FALLA HOY
 *
 * La fase [2] es la que protege al peregrino: es la aserción que impide
 * un arreglo demasiado enthusiastico que «rellene» clanId con cualquier
 * cosa para hacer pasar la [1]. Una fase que hoy pasa y que DEBE seguir
 * pasando es parte del contrato igual que la que falla.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP 8.2 nativo, PDO nativo, sin una sola
 *     dependencia externa. Invoca el controlador directamente, sin servidor
 *     web y sin `fetch`, que es lo que permite medir el contrato sin red.
 *   - Artículo III: la base es efímera y se destruye al terminar. Este
 *     arnés NUNCA toca `database/grimorio.db`.
 *
 * Uso: php scratch/test_bind_envelope_clan.php  (exit 0 = verde)
 */

declare(strict_types=1);

$assertsPassed = 0;
$assertsFailed = 0;

/**
 * Asegura una condición y la reporta con el formato estándar del proyecto.
 *
 * @param bool   $condition Condición evaluada.
 * @param string $label     Descripción legible del requisito que se comprueba.
 */
function assertArcane(bool $condition, string $label): void
{
    global $assertsPassed, $assertsFailed;

    if ($condition) {
        $assertsPassed++;
        echo "  OK    {$label}\n";
        return;
    }

    $assertsFailed++;
    echo "  FALLA {$label}\n";
}

/**
 * Decodifica el cuerpo JSON de una Response con aserción integrada.
 *
 * @param \Grimorio\Core\Response $response Respuesta del controlador.
 * @return array<string, mixed> Cuerpo decodificado, o vacío si no lo es.
 */
function decodeJson(object $response): array
{
    $decoded = json_decode($response->getBody(), true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * Construye un Request JSON tipado como el que despacharía el Front Controller.
 *
 * El cuerpo viaja inyectado porque la SAPI CLI no admite escritura en
 * `php://input`; bajo SAPI web el flujo es el nativo de `php://input`.
 *
 * @param string               $method  Verbo HTTP.
 * @param string               $path    Ruta de la API.
 * @param array<string, mixed> $payload Cuerpo JSON.
 * @return \Grimorio\Core\Request Petición lista para el controlador.
 */
function forgeJsonRequest(string $method, string $path, array $payload = []): \Grimorio\Core\Request
{
    $rawBody = $payload === [] ? '' : (string) json_encode($payload, JSON_UNESCAPED_UNICODE);

    return new \Grimorio\Core\Request($method, $path, [], ['Content-Type' => 'application/json'], $rawBody);
}

/**
 * Lee un campo del sobre distinguiendo tres estados que NO son el mismo.
 *
 * El operador `??` de PHP trata `null` como si la clave no existiera, así
 * que no puede usarse para un contrato donde «la clave vale null» y «la
 * clave falta» son afirmaciones distintas — y aquí lo son: `clanId: null`
 * es la respuesta CORRECTA de quien no tiene hermandad. Este arnés cometió
 * ese error en su primera versión y produjo un rojo falso en la fase que
 * debía estar verde. La lección queda en el nombre de la función: un
 * contrato que admite null necesita un lector que no confunda null con
 * ausencia.
 *
 * @param array<string, mixed> $sobre  Sobre `data.user`.
 * @param string               $campo  Clave a leer.
 * @return mixed El valor real, o la cadena «<<AUSENTE>>» si falta la clave.
 */
function valorDelSobre(array $sobre, string $campo): mixed
{
    return array_key_exists($campo, $sobre) ? $sobre[$campo] : '<<AUSENTE>>';
}

/**
 * Invoca `bind()` con las credenciales dadas y devuelve el `data.user`.
 *
 * Se encapsula para que cada fase lea como una intención y no como una
 * ceremonia de seis líneas de construcción de peticiones.
 *
 * @param \Grimorio\Controllers\AuthController $controller  Controlador bajo prueba.
 * @param string                              $identity    Alias o correo.
 * @param string                              $passphrase  Frase de paso.
 * @return array<string, mixed> El sobre `data.user`, o vacío si no lo hay.
 */
function bindUser(\Grimorio\Controllers\AuthController $controller, string $identity, string $passphrase): array
{
    $response = $controller->bind(forgeJsonRequest('POST', '/api/v1/auth/bind', [
        'identity'   => $identity,
        'passphrase' => $passphrase,
    ]));

    if ($response->getStatusCode() !== 200) {
        return [];
    }

    $body = decodeJson($response);
    $user = $body['data']['user'] ?? null;

    return is_array($user) ? $user : [];
}

$projectRoot = dirname(__DIR__);

// Las cookies que SessionManager emite durante `bind` disparan avisos
// cosméticos bajo CLI: no afectan a ninguna aserción, solo al ruido.
ob_start();

require_once $projectRoot . '/public/index.php';

use Grimorio\Controllers\AuthController;
use Grimorio\Core\RateLimiter;
use Grimorio\Core\Request;
use Grimorio\Core\SessionManager;
use Grimorio\Models\User;

echo "=== Tarea 0 (TASKS-18): el sobre de vínculo que olvidaba la casa ===\n\n";

// ---------------------------------------------------------------------
// [0] Superficie: ¿existe siquiera lo que vamos a medir?
// ---------------------------------------------------------------------
echo "[0] Superficie bajo prueba\n";

assertArcane(
    class_exists(AuthController::class),
    'La clase Grimorio\Controllers\AuthController existe y el autoload la resuelve'
);

if (!class_exists(AuthController::class)) {
    ob_end_flush();
    echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
    echo "El arnés no puede seguir: sin controlador no hay sobre que medir.\n";
    exit(1);
}

// ---------------------------------------------------------------------
// Sandbox: base SQLite efímera con el esquema canónico completo.
// ---------------------------------------------------------------------
$sandboxDb = sys_get_temp_dir() . '/grimorio_bind_envelope_' . getmypid() . '.sqlite';
if (file_exists($sandboxDb)) {
    @unlink($sandboxDb);
}

$pdo = new PDO('sqlite:' . $sandboxDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

$now = '2026-09-29T12:00:00Z';

// Una hermandad y su Linaje canónico, para el titular que sí tiene casa.
$pdo->exec(
    "INSERT INTO clans (id, slug, name, motto, created_at)
     VALUES ('cln_sobre', 'mareas-de-aether', 'Mareas de Aether', 'La marea no olvida.', '{$now}')"
);

// Dos titulares con el MISMO linaje jurado y distinta pertenencia: es la
// única forma de que la diferencia del sobre sea atribuible a la
// hermandad y no al resto del linaje (RF-18.4 frente al caso límite 1).
$passphrase = 'palabra-secreta-del-arnes';
$insertUser = $pdo->prepare(
    'INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, created_at, updated_at)
     VALUES (:id, :alias, :email, :hash, :role, :clanId, :lineage, :createdAt, :createdAt)'
);

$insertUser->execute([
    ':id' => 'usr_sobre_herm', ':alias' => 'HermanoDeSobre', ':email' => 'hermano@sobre.arc',
    ':hash' => password_hash($passphrase, PASSWORD_BCRYPT), ':role' => 'editor',
    ':clanId' => 'cln_sobre', ':lineage' => 'celestialTides', ':createdAt' => $now,
]);
$insertUser->execute([
    ':id' => 'usr_sobre_suelto', ':alias' => 'JuradoSinCasa', ':email' => 'suelto@sobre.arc',
    ':hash' => password_hash($passphrase, PASSWORD_BCRYPT), ':role' => 'editor',
    ':clanId' => null, ':lineage' => 'celestialTides', ':createdAt' => $now,
]);

// `clan_members` es la AUTORIDAD de la afiliación (RF-01.1, Art. VII);
// `users.clan_id` es su espejo denormalizado. Se inscribe la fila que
// justifica el espejo, para que la pareja sea coherente como lo sería en
// producción y no un artefacto del arnés.
$insertMember = $pdo->prepare(
    'INSERT INTO clan_members (id, clan_id, user_id, role, joined_at)
     VALUES (:id, :clanId, :userId, :role, :joinedAt)'
);
$insertMember->execute([
    ':id' => 'clm_sobre_herm', ':clanId' => 'cln_sobre',
    ':userId' => 'usr_sobre_herm', ':role' => 'adept', ':joinedAt' => $now,
]);

$sessionManager = new SessionManager($pdo, '198.51.100.24', 'Arnés SPEC-18/1.0');
$rateLimiter = new RateLimiter($pdo);
$controller = new AuthController($pdo, $sessionManager, $rateLimiter);

// ---------------------------------------------------------------------
// [1] Titular CON hermandad: el sobre debe declararla (RF-18.4).
//     ESTA ES LA FASE QUE FALLA HOY.
// ---------------------------------------------------------------------
echo "\n[1] Titular con hermandad: el sobre declara su casa (RF-18.1, RF-18.4)\n";

$hermUser = bindUser($controller, 'HermanoDeSobre', $passphrase);

assertArcane(
    $hermUser !== [],
    'El vínculo del titular con hermandad responde 200 con data.user'
);
assertArcane(
    ($hermUser['alias'] ?? null) === 'HermanoDeSobre',
    'El sobre identifica al titular que entró por el Umbral'
);
assertArcane(
    valorDelSobre($hermUser, 'clanId') === 'cln_sobre',
    'clanId viaja con el identificador de la hermandad del titular'
);
assertArcane(
    valorDelSobre($hermUser, 'clanName') === 'Mareas de Aether',
    'clanName viaja con el nombre solemne de esa hermandad'
);
assertArcane(
    ($hermUser['lineage'] ?? null) === 'celestialTides',
    'El linaje jurado sobrevive al arreglo: clan_id y lineage son campos independientes'
);
assertArcane(
    !isset($hermUser['passwordHash']),
    'El sobre jamás expone la huella de la frase de paso'
);

// ---------------------------------------------------------------------
// [2] Titular SIN hermandad: el sobre sigue diciendo que no tiene (caso
//     límite 1). ESTA FASE PASA HOY Y DEBE SEGUIR PASANDO: es la que
//     impide que el arreglo del punto [1] invente una casa.
// ---------------------------------------------------------------------
echo "\n[2] Titular sin hermandad: el sobre NO inventa una casa (caso límite 1)\n";

$sueltoUser = bindUser($controller, 'JuradoSinCasa', $passphrase);

assertArcane(
    $sueltoUser !== [],
    'El vínculo del titular sin hermandad responde 200 con data.user'
);
// `valorDelSobre` y no `??`: aquí null es la respuesta CORRECTA y hay que
// poder decir «vale null» sin que el lector lo confunda con «no existe».
assertArcane(
    valorDelSobre($sueltoUser, 'clanId') === null,
    'clanId viaja como null (la clave EXISTE con valor nulo: no se omite)'
);
assertArcane(
    valorDelSobre($sueltoUser, 'clanName') === '',
    'clanName viaja como cadena vacía, no como null ni como un nombre inventado'
);
assertArcane(
    array_key_exists('clanId', $sueltoUser) && array_key_exists('clanName', $sueltoUser),
    'Ambas claves están PRESENTES también sin hermandad: el contrato no cambia de forma'
);
assertArcane(
    valorDelSobre($sueltoUser, 'id') === 'usr_sobre_suelto',
    'El mismo lector distingue bien una clave presente con valor: lee id sin inventar marcas'
);

// ---------------------------------------------------------------------
// [3] Paridad: bind y session dicen lo mismo sobre la MISMA persona
//     (RF-18.3). ESTA FASE FALLA HOY.
// ---------------------------------------------------------------------
echo "\n[3] Paridad de contrato: bind y session dicen lo mismo (RF-18.3)\n";

assertArcane(
    isset($hermUser['id']) && $hermUser['id'] === 'usr_sobre_herm',
    'El sobre de bind trae el id del titular (el pivote de la comparación de paridad)'
);

// Se reproduce lo que hace `AuthMiddleware`: materializa la entidad desde
// la fila y se la inyecta en la petición. Es la diferencia exacta entre
// los dos endpoints, y por eso uno miente y el otro no.
$hermRow = $pdo->query(
    "SELECT id, alias, email, role, clan_id, lineage, password_hash, created_at, updated_at
       FROM users WHERE id = 'usr_sobre_herm'"
)->fetch(PDO::FETCH_ASSOC);

assertArcane(
    is_array($hermRow) && ($hermRow['clan_id'] ?? null) === 'cln_sobre',
    'La fila de users SÍ trae clan_id: el dato existe, lo que falta es traducirlo'
);

$sessionRequest = new Request('GET', '/api/v1/auth/session');
if (is_array($hermRow)) {
    $sessionRequest->setUser(User::fromDatabaseRow($hermRow));
}
$sessionResponse = $controller->session($sessionRequest);
$sessionUser = decodeJson($sessionResponse)['data']['user'] ?? [];

assertArcane(
    $sessionResponse->getStatusCode() === 200 && is_array($sessionUser),
    'La verificación de sesión responde 200 con data.user para el titular inyectado'
);
assertArcane(
    valorDelSobre($sessionUser, 'clanId') === 'cln_sobre'
    && valorDelSobre($sessionUser, 'clanName') === 'Mareas de Aether',
    'session declara la hermandad correctamente: la entidad User sí traduce clan_id'
);

// Comparación de paridad, campo a campo. La única diferencia admisible
// entre ambos sobres sería `authenticated`, que solo existe en session y
// no forma parte de `data.user`; como se comparan los dos `data.user`, no
// hay excepción que aplicar.
$camposDelSobre = ['id', 'alias', 'role', 'clanId', 'clanName', 'lineage', 'avatarKind', 'avatarReference'];
$divergentes = [];
foreach ($camposDelSobre as $campo) {
    $enBind = valorDelSobre($hermUser, $campo);
    $enSession = valorDelSobre($sessionUser, $campo);
    if ($enBind !== $enSession) {
        $divergentes[] = sprintf('%s (bind=%s, session=%s)', $campo, var_export($enBind, true), var_export($enSession, true));
    }
}

assertArcane(
    $divergentes === [],
    'bind y session entregan el MISMO data.user campo a campo (' . implode('; ', $divergentes ?: ['sin divergencias']) . ')'
);

// ---------------------------------------------------------------------
// [4] El mapeo no RESUELVE campos por defecto (RF-18.2).
//     El `default` que hoy devuelve el linaje ante cualquier clave
//     desconocida es el mecanismo que escondió el defecto: un campo
//     resuelto por reserva es un campo que nadie verificó.
//
//     Aquí se mide el COMPORTAMIENTO y no la forma del código, y esa
//     distinción se pagó cara. La primera versión de esta fase leía las
//     líneas del fichero y contaba quantos brazos `default` había, con
//     un aserto que exigía CERO. La prueba de mutación la desmontó: el
//     arreglo correcto conserva `default => null` —que es inocuo y
//     necesario, porque sin él un campo ausente reventaría el cierre— y
//     el aserto lo declaraba roto. Un arnés que exige una FORMA en vez
//     de un EFECTO no mide el contrato: dicta la implementación, y el
//     primer refactor razonable lo vuelve falso sin que nada cambie.
//
//     El cierre es privado, así que se invoca por reflexión con filas
//     manipuladas. Lo que se comprueba es la RESPUESTA, que es lo que el
//     contrato promete.
// ---------------------------------------------------------------------
echo "\n[4] El mapeo no resuelve campos por reserva (RF-18.2)\n";

$metodoForge = new ReflectionMethod(AuthController::class, 'forgeUserPayload');
$metodoForge->setAccessible(true);

/**
 * Invoca el cierre privado con la fila dada y devuelve el campo pedido.
 *
 * @param array<string, mixed> $fila   Fila de `users` simulada.
 * @param string               $campo  Clave de contrato a leer del sobre.
 * @param User|null            $entidad Entidad de sesión, o null.
 * @return mixed Valor del campo, o la marca «<<ERROR>>» si el cierre lanzó.
 */
function campoDelSobreForjado(array $fila, string $campo, ?User $entidad = null): mixed
{
    global $metodoForge, $controller;

    try {
        $sobre = $metodoForge->invoke($controller, $fila, $entidad);
    } catch (Throwable $fallo) {
        // Un cierre que revienta ante una fila sin una columna no es un
        // contrato: es una trampa. Se marca y se sigue, para que el
        // veredicto siga siendo legible en vez de morir aquí.
        return '<<ERROR: ' . $fallo::class . '>>';
    }

    return is_array($sobre) ? valorDelSobre($sobre, $campo) : '<<SOBRE INVALIDO>>';
}

// Fila completa: es el camino que ya sabemos que debe funcionar.
$filaCompleta = [
    'id' => 'usr_sobre_herm', 'alias' => 'HermanoDeSobre', 'role' => 'editor',
    'clan_id' => 'cln_sobre', 'lineage' => 'celestialTides',
];

assertArcane(
    campoDelSobreForjado($filaCompleta, 'clanId') === 'cln_sobre',
    'El cierre traduce clan_id a clanId cuando la fila trae la columna'
);

// Fila SIN la columna clan_id, con el linaje presente. Aquí está el hueco:
// un default que devuelve el linaje haría que clanId valiera
// 'celestialTides', que es un linaje disfrazado de hermandad.
$filaSinClanId = $filaCompleta;
unset($filaSinClanId['clan_id']);

assertArcane(
    campoDelSobreForjado($filaSinClanId, 'clanId') === null,
    'Ante una fila sin clan_id, clanId vale null y NO se rellena con el linaje (RF-18.2)'
);

// Fila con clan_id vacío: el espejo desincronizado. Tampoco puede
// convertirse en el linaje.
$filaConClanVacio = array_merge($filaCompleta, ['clan_id' => '']);

assertArcane(
    campoDelSobreForjado($filaConClanVacio, 'clanId') === null,
    'Ante clan_id vacío, clanId vale null y no se sustituye por el linaje'
);

// La fila entera ausente: el caso degenerado. Sin fila, el cierre solo
// puede apoyarse en la entidad, y sin entidad debe dar null en vez de
// inventar. Esto es lo que impide un campo de repuesto que a su vez sea de repuesto.
assertArcane(
    campoDelSobreForjado([], 'clanId') === null,
    'Sin fila y sin entidad, clanId vale null en vez de dar un valor inventado'
);
assertArcane(
    campoDelSobreForjado([], 'lineage') === null,
    'Sin fila y sin entidad, lineage también vale null: no hay telaraña de valores'
);

// Con entidad presente, la ruta de session debe seguir funcionando: el
// arreglo de bind no puede romper el endpoint que hoy acierta.
$entidad = User::fromDatabaseRow($pdo->query(
    "SELECT id, alias, email, role, clan_id, lineage, password_hash, created_at, updated_at
       FROM users WHERE id = 'usr_sobre_herm'"
)->fetch(PDO::FETCH_ASSOC));

assertArcane(
    campoDelSobreForjado([], 'clanId', $entidad) === 'cln_sobre',
    'Con entidad inyectada y sin fila, clanId sale de la entidad: la ruta de session no se rompe'
);

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";

if ($assertsFailed > 0) {
    echo "\nRojo esperado mientras forgeUserPayload busque 'clanId' en una fila de\n";
    echo "columnas snake_case, y mientras su default resuelva el linaje ante\n";
    echo "una clave que no encuentra. La Tarea 1 traduce por nombre de columna\n";
    echo "y hace que la reserva no devuelva ningún campo. Nada de esto toca\n";
    echo "src/ todavía.\n";
}

ob_end_flush();
@unlink($sandboxDb);

exit($assertsFailed === 0 ? 0 : 1);
