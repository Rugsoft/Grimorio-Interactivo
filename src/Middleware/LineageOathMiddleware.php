<?php

/**
 * LineageOathMiddleware.php — La guardia de sustancia del Juramento de
 * Linaje (SPEC-09, Tarea 2.3).
 *
 * Cubre: RF-01.3 (retención vistas + API), RF-01.4 (rutas permitidas),
 * RF-01.6 (exención del Supremo), RF-05.1 (denegación backend) y RF-05.3
 * (ruta retenida en sesión, saneada y caducante).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP nativo y PDO puro, sin framework.
 *   - Artículo IV: el rechazo viaja con leyenda solemne en castellano.
 *   - Artículo V: identificadores en inglés camelCase, claves de sesión
 *     en snake_case (convención de $_SESSION), documentación en castellano.
 *
 * DISEÑO (plan §1.1, §2.2 y §3.2):
 *   - La guardia se consulta estilo RbacMiddleware: devuelve null si el
 *     acceso queda CONCEDIDO; en caso contrario, la Response 403 lista
 *     para enviar, con el sobre `LINEAGE_OATH_REQUIRED` del contrato.
 *   - Antes de responder, retiene la ruta solicitada en la sesión del
 *     servidor (`$_SESSION['retainedRoute']`), SANEADA contra la lista de
 *     vistas internas de la SPA (HASH_TO_VIEW_MAP de main.js): jamás una
 *     URL externa ni una cadena hostil. La ruta viaja en la cabecera
 *     `X-Requested-Route` que el interceptor añade al navegar; las
 *     llamadas API puras retienen la ruta de la vista que las originó.
 *   - La retención solo se escribe cuando hay sesión activa: sin sesión
 *     no hay a dónde retener (y el flujo de login ya conduce a la
 *     ceremonia, RF-01.3).
 *   - Los peregrinos CON SERCIOS públicos (GET de lectura) pasan: la
 *     lectura pública inherente al rol editor (RF-05.1) permanece.
 */

declare(strict_types=1);

namespace Grimorio\Middleware;

use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Models\User;

/**
 * La guardia que mantiene el umbral: nadie pisa las salas de gestión sin
 * linaje jurado.
 */
final class LineageOathMiddleware
{
    /** Código técnico de la denegación (contrato del plan §2.2). */
    public const OATH_REQUIRED_CODE = 'LINEAGE_OATH_REQUIRED';

    /** Leyenda solemne del rechazo (RF-05.1). */
    public const OATH_REQUIRED_MESSAGE =
        'El santuario aguarda tu juramento: nadie pisa sus salas sin linaje jurado.';

    /** Clave de sesión de la ruta retenida (plan §2.1; caduca con la sesión). */
    public const SESSION_KEY_RETAINED_ROUTE = 'retainedRoute';

    /**
     * Las vistas internas de la SPA (HASH_TO_VIEW_MAP de main.js): el
     * universo de rutas retenibles. La ceremonia se sumará a esta lista
     * en la Tarea 3.2; el saneamiento la excluye de la retención (jurar
     * desde la ceremonia no retiene la ceremonia misma).
     */
    private const RETAINABLE_VIEWS = [
        'landing', 'library', 'codex', 'clans', 'simulator', 'creator',
        'experimentalHall', 'tower', 'auditLog', 'clan',
    ];

    /**
     * Las rutas API que el peregrino conserva (RF-01.4 y RF-05.1): el
     * juramento mismo, su canon, el perfil de credenciales, el cierre de
     * sesión y la lectura pública. Todo lo demás, denegado.
     *
     * @var array<string, list<string>> método => prefijos de ruta.
     */
    private const PERMITTED_ROUTES = [
        'GET' => [
            '/api/v1/lineage/oath-catalog',
            '/api/v1/lineages',
            '/api/v1/auth/session',
            // Lectura pública inherente al rol editor (RF-05.1):
            '/api/v1/spells',
            '/api/v1/portal/featured',
            '/api/v1/clans/preview',
            '/api/v1/clans',
            '/api/v1/lineages',
            '/api/v1/grimoire/spells',
            '/api/v1/elements/matrix',
            '/api/v1/audit/log',
            // El Salón del Dominio es contemplación pública (SPEC-07,
            // RF-06.1: se lee sin vínculo arcano); el estandarte del Clan
            // Regente del portal no puede quedar rehén del juramento.
            '/api/v1/dominion/leaderboard',
        ],
        'POST' => [
            '/api/v1/lineage/oath',
            '/api/v1/lineage/retained-route',
            '/api/v1/auth/bind',
            '/api/v1/auth/dissolve',
            '/api/v1/auth/dissolve-all',
        ],
    ];

    /** El vínculo a la verdad de la cuenta (peregrino o linajado). */
    private \Grimorio\Repositories\LineageOathRepository $repository;

    public function __construct(\Grimorio\Repositories\LineageOathRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * La guardia: null si el acceso queda CONCEDIDO (el flujo continúa);
     * en caso contrario, la Response 403 `LINEAGE_OATH_REQUIRED` lista
     * para enviar — con la ruta solicitada retenida antes de responder.
     *
     * @param Request $request Petición con el usuario inyectado (Tarea 3.1).
     */
    public function guard(Request $request): ?Response
    {
        $user = $request->getUser();

        // Sin vínculo válido o visitante anónimo (id vacío): la
        // autenticación es asunto del AuthMiddleware (401); la retención
        // de ruta no tiene a dónde retener sin sesión y jamás convertirá
        // un anónimo en peregrino.
        if (!$user instanceof User || $user->getId() === '') {
            return null;
        }

        // RF-01.6: el Supremo navega exento, con o sin linaje.
        if ($user->getRole() === 'supremeAdmin') {
            return null;
        }

        // La verdad vive en la base, no en una afirmación del cliente.
        if ($this->repository->findAccountLineage($user->getId()) !== null) {
            return null;
        }

        // ---- Peregrino confirmado: decidir paso o retención ------------

        $path = $request->getPath();
        $method = strtoupper($request->getMethod());

        foreach (self::PERMITTED_ROUTES[$method] ?? [] as $permittedPrefix) {
            if ($path === $permittedPrefix || str_starts_with($path, $permittedPrefix . '/')) {
                return null;
            }
        }

        // RF-05.3: retener la ruta solicitada ANTES de responder. Solo
        // rutas internas de la SPA; jamás URLs externas ni cadenas hostiles.
        $requestedRoute = (string) ($request->getHeader('X-Requested-Route') ?? '');
        self::retainRouteIfInternal($requestedRoute);

        return Response::json(
            [
                'success' => false,
                'error'   => [
                    'code'           => self::OATH_REQUIRED_CODE,
                    'message'        => self::OATH_REQUIRED_MESSAGE,
                    'details'        => ['oathView' => '#/juramento'],
                    'recoveryAction' => 'GOTO_OATH',
                    'oathView'       => '#/juramento',
                ],
            ],
            403,
        );
    }

    /**
     * Retiene en la sesión la ruta solicitada, si es una vista interna
     * saneada (RF-05.3). Las URLs externas y las cadenas que no nombran
     * una vista del portal se descartan en silencio. Es el ÚNICO
     * saneamiento de rutas del juramento: lo comparten la guardia y el
     * endpoint de retención del controlador (Tarea 2.6).
     */
    public static function retainRouteIfInternal(string $requestedRoute): void
    {
        $candidate = trim($requestedRoute);
        if ($candidate === '' || !str_starts_with($candidate, '#/')) {
            return; // Sin ruta o URL externa: se descarta en silencio.
        }

        // El mapa canónico de hashes a vistas vive en la SPA (main.js);
        // aquí se replica su clave cerrada: una sola fuente canónica en
        // dos lenguajes, que el arnés cruza para evitar divergencia.
        $hashToView = [
            '#/'                  => 'landing',
            '#/biblioteca'        => 'library',
            '#/codex'             => 'codex',
            '#/linajes'           => 'clans',
            '#/simulador'         => 'simulator',
            '#/creador'           => 'creator',
            '#/atrio'             => 'experimentalHall',
            '#/torre'             => 'tower',
            '#/bitacora'          => 'auditLog',
        ];
        $viewName = $hashToView[$candidate] ?? null;
        if (!is_string($viewName) || !in_array($viewName, self::RETAINABLE_VIEWS, true)) {
            return; // Hash desconocido o vista no retenible: descartado.
        }

        $_SESSION[self::SESSION_KEY_RETAINED_ROUTE] = $candidate;
    }
}
