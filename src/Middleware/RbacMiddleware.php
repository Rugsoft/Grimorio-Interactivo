<?php

/**
 * RbacMiddleware.php — Control de acceso por la Jerarquía Sagrada (RBAC).
 *
 * Tarea 3.2 (TASKS-03): intercepta rutas y valida que el rol técnico del
 * usuario satisfaga los privilegios exigidos, respondiendo 403 Forbidden
 * con leyenda de jerarquía insuficiente en caso contrario (RF-05.1, RF-05.2).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP nativo puro, sin framework.
 *   - Artículo III: el veto de jerarquía es la primera muralla que
 *     protege la moderación; la segunda es el servicio de conflicto de
 *     intereses (Tarea 2.4).
 *   - Artículo IV: la leyenda de rechazo conserva la solemnidad arcana.
 *   - Artículo V: identificadores en inglés camelCase, leyendas y
 *     documentación en castellano.
 *
 * Jerarquía acumulativa (RF-05.1):
 *   reader < editor < master < supremeAdmin
 * Cada rol hereda todos los privilegios del escalafón anterior.
 *
 * Seguridad:
 *   - El cuerpo del 403 es IDÉNTICO para cualquier jerarquía insuficiente:
 *     jamás revela el rol real del solicitante ni el privilegio evaluado
 *     (sin filtraciones de contexto para sondeos de escalada).
 *   - Una petición sin usuario inyectado (defecto de la cadena) se trata
 *     como anónima reader, jamás con privilegios elevados.
 */

declare(strict_types=1);

namespace Grimorio\Middleware;

use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Models\User;

/**
 * Verificación estricta de los roles sagrados.
 */
final class RbacMiddleware
{
    /** Escalafones canónicos en orden ascendente de jerarquía (RF-05.1). */
    private const ROLE_HIERARCHY = ['reader', 'editor', 'master', 'supremeAdmin'];

    /** Código técnico del error de jerarquía insuficiente. */
    private const INSUFFICIENT_HIERARCHY_CODE = 'INSUFFICIENT_HIERARCHY';

    /** Leyenda solemne del rechazo (RF-05.2). */
    private const INSUFFICIENT_HIERARCHY_MESSAGE =
        'Tus conocimientos aún no alcanzan la jerarquía necesaria para invocar este poder.';

    /**
     * Autoriza la petición contra el privilegio exigido por la ruta.
     *
     * Devuelve null si el acceso queda CONCEDIDO (el flujo continúa);
     * en caso contrario, la Response 403 lista para ser enviada.
     *
     * @param Request $request            Petición con el usuario inyectado (Tarea 3.1).
     * @param string  $requiredPrivilege  Rol mínimo exigido por la ruta.
     */
    public function authorize(Request $request, string $requiredPrivilege): ?Response
    {
        $user = $request->getUser();

        // Defensa en profundidad: sin usuario inyectado se asume anónimo
        // reader (jamás privilegios elevados por defecto).
        $activeRole = $user instanceof User ? $user->getRole() : 'reader';

        // Rol desconocido (fuera del canon): denegación por defecto.
        $roleRank = array_search($activeRole, self::ROLE_HIERARCHY, true);
        if ($roleRank === false) {
            return $this->forgeInsufficientHierarchyResponse();
        }

        $requiredRank = array_search($requiredPrivilege, self::ROLE_HIERARCHY, true);
        if ($requiredRank === false) {
            // Privilegio inexistente en el canon: defecto a denegación
            // (una ruta mal configurada jamás abre el paso).
            return $this->forgeInsufficientHierarchyResponse();
        }

        // Jerarquía acumulativa: el rango del rol debe alcanzar o superar
        // el rango del privilegio exigido (RF-05.1).
        if ($roleRank >= $requiredRank) {
            return null; // Acceso concedido: el flujo continúa.
        }

        return $this->forgeInsufficientHierarchyResponse();
    }

    /**
     * Forja la Response 403 con el cuerpo JSON canónico del proyecto.
     * El cuerpo es idéntico en todos los casos de jerarquía insuficiente:
     * no filtra el rol del solicitante ni el privilegio evaluado.
     */
    private function forgeInsufficientHierarchyResponse(): Response
    {
        return Response::json([
            'success' => false,
            'error'   => [
                'code'           => self::INSUFFICIENT_HIERARCHY_CODE,
                'message'        => self::INSUFFICIENT_HIERARCHY_MESSAGE,
                'recoveryAction' => 'ELEVATE_ROLE',
            ],
        ], 403);
    }
}
