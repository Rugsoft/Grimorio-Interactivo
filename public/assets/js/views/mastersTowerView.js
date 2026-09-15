/**
 * mastersTowerView.js — Vista solemne de la Torre de Deliberación
 * (Tarea 6.4, TASKS-08).
 *
 * RF-05.4: monta el panel del Cónclave en su ruta reservada. La entrada es
 *   EXCLUSIVA de Maestros y del Administrador Supremo (RF-03.5): la vista
 *   ejerce el control de acceso RBAC sobre el rol servido por el store y
 *   REDIRIGE al visitante no autorizado (criterio «Hecho cuando»).
 * RF-02.1 / RF-02.5: los gestos de firma y objeción los ANUNCIA la vista al
 *   orquestador; los modales litúrgicos de la Tarea 6.1 los recogerán.
 *
 * El ARBITRAJE del acceso es doble (defensa en profundidad):
 *   - El BACKEND responde 401/403 a la cola de la Torre ante un rol menor
 *     (INSUFFICIENT_RANK_TO_JUDGE): la autoridad es una sola (Art. II).
 *   - La VISTA evita el viaje inútil: si el rol del store no alcanza
 *     `master`, ni siquiera consulta: redirige por `onAccessDenied` y
 *     anuncia el evento `moderation:tower-access-denied` (plan 4.1).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Module nativo; DOM puro; cero librerías.
 *   - Artículo II: la vista JAMÁS aritmetiza el veto ético: lee el rol
 *     servido y delega el veredicto en el santuario.
 *   - Artículo III: la memoria de clanes jamás llega al navegador; el veto
 *     viaja ya resuelto en cada tarjeta (Tarea 3.2).
 *   - Artículo IV/V: leyendas en noble castellano; identificadores en inglés.
 */

import { createMastersTowerComponent } from '../components/mastersTowerComponent.js';

/** Rangos con potestad de juzgar (RF-03.5; espejo del RbacMiddleware). */
const JUDGE_ROLES = Object.freeze(['master', 'supremeAdmin']);

/** Eventos del plan 4.1 que refrescan la cola sin sondear el reloj. */
const SIGNED_EVENT = 'moderation:signed';
const RETRACTED_EVENT = 'moderation:retracted';
const CONSECRATED_EVENT = 'moderation:consecrated';
const REJECTED_EVENT = 'moderation:rejected';

/** Evento anunciado ante un intento de entrada sin rango (plan 4.1). */
export const TOWER_ACCESS_DENIED_EVENT = 'moderation:tower-access-denied';

/** Leyendas ceremoniales de la vista (RNF-03). */
const TOWER_VIEW_LEGENDS = Object.freeze({
  accessDeniedNotice: 'La Torre de Deliberación se reserva a los Maestros del Cónclave y al Administrador Supremo.',
});

/**
 * Crea la vista de la Torre de Deliberación.
 *
 * @param {HTMLElement} mountRoot Punto de montaje (`<main id="app">`).
 * @param {Object} options
 * @param {Object} options.moderationClient Cliente HTTP (Tarea 5.1; necesita
 *        `fetchDeliberationQueue`).
 * @param {Object} options.store Almacén reactivo (fuente del rol servido:
 *        `getState().userRole`). La vista registra `currentView: 'tower'`.
 * @param {(spellId: string) => void} [options.onSignatureIntent] Gesto de
 *        firma (RF-02.1); el orquestador abre el modal de glosa (Tarea 6.1).
 * @param {(spellId: string) => void} [options.onObjectionIntent] Gesto de
 *        objeción (RF-02.5); el orquestador abre el modal del dictamen.
 * @param {(origin: string) => void} [options.onAccessDenied] Redirección
 *        ante un rol sin rango (criterio); el orquestador decide el destino
 *        —la vista jamás navega por su cuenta (plan 4.1)—.
 * @param {(tagName: string) => HTMLElement} [options.elementFactory] Fábrica
 *        inyectable (arneses sin navegador).
 * @param {Document} [options.documentRef] Documento anfitrión (arneses).
 * @param {EventTarget} [options.eventTarget] Bus del plan 4.1. Por defecto,
 *        la raíz de montaje.
 * @returns {Object} API: { render, destroy, retry, setModerationClient }.
 */
export function createMastersTowerView(mountRoot, options = {}) {
  const {
    moderationClient,
    store = null,
    onSignatureIntent,
    onObjectionIntent,
    onAccessDenied,
    elementFactory = (tagName) => globalThis.document.createElement(tagName),
    documentRef = globalThis.document,
  } = options;

  /** Bus de eventos: la deliberación avanza por evento, no por sondeo. */
  const eventTarget = options.eventTarget
    ?? (typeof globalThis.addEventListener === 'function' ? globalThis : mountRoot);

  /** Cliente HTTP vivo (conmutable en reintentos tras un fallo). */
  let activeModerationClient = moderationClient;

  /** Nodos vivos de la vista, para limpieza determinista. */
  const mountedNodes = [];

  /** La Torre quedó desmontada: ninguna respuesta tardía pinta nada. */
  let isDestroyed = false;

  /** Referencias vivas. */
  let viewRoot = null;
  let tower = null;

  /** Registra un nodo como hijo de la vista para su ciclo de vida. */
  function track(node) {
    mountedNodes.push(node);
    return node;
  }

  /** Anuncia por el bus un gesto o aviso del plan 4.1. */
  function emitBusEvent(eventName, detail) {
    try {
      const eventConstructor = documentRef.CustomEvent ?? globalThis.CustomEvent;
      documentRef.defaultView?.dispatchEvent?.(new eventConstructor(eventName, { detail }));
    } catch {
      // Sin CustomEvent (entornos mínimos): el callback portará el aviso.
    }
  }

  /**
   * Arbitra el acceso con el rol SERVIDO por el store (Art. II: la vista
   * jamás deduce rango: lo lee).
   *
   * @returns {boolean} `true` si el rol alcanza la potestad de juzgar.
   */
  function hasJudgeRank() {
    const servedRole = String(store?.getState?.().userRole ?? 'reader');
    return JUDGE_ROLES.includes(servedRole);
  }

  /** Consulta la cola y alimenta el componente. */
  async function load() {
    if (tower === null) return;
    await tower.render();
  }

  /**
   * Refresco dirigido por evento (plan 4.1): la deliberación avanza y la
   * cola del Cónclave refleja la nueva realidad. Es SOLO LECTURA: ningún
   * evento decide firmas ni contadores.
   */
  function handleDeliberationAdvanced() {
    if (isDestroyed || tower === null) return;
    void load();
  }

  /** Reintenta la última consulta tras un corte de corriente (RNF). */
  async function retry() {
    tower?.render();
  }

  /** Conmuta el cliente de la Torre (reintentos con otro canal). */
  function setModerationClient(nextModerationClient) {
    activeModerationClient = nextModerationClient;
    if (tower !== null) {
      tower.destroy();
      tower = createMastersTowerComponent(viewRoot, {
        moderationClient: activeModerationClient,
        onSignatureIntent: typeof onSignatureIntent === 'function' ? onSignatureIntent : undefined,
        onObjectionIntent: typeof onObjectionIntent === 'function' ? onObjectionIntent : undefined,
        elementFactory,
        documentRef,
      });
      tower.render();
    }
  }

  /**
   * Monta la vista. Si el rol servido no alcanza `master`, la vista ni
   * siquiera consulta: redirige por `onAccessDenied`, anuncia el aviso por
   * el bus y desiste (criterio: los no autorizados son REDIRIGIDOS).
   */
  async function render() {
    if (isDestroyed) return;

    destroyInternal();

    // Control de acceso RBAC (RF-03.5): el arbitraje del backend permanece
    // (401/403 INSUFFICIENT_RANK_TO_JUDGE); la vista evita el viaje inútil.
    if (!hasJudgeRank()) {
      emitBusEvent(TOWER_ACCESS_DENIED_EVENT, {
        message: TOWER_VIEW_LEGENDS.accessDeniedNotice,
      });
      if (typeof onAccessDenied === 'function') {
        onAccessDenied('tower');
      }
      return;
    }

    viewRoot = track(elementFactory('section'));
    viewRoot.className = 'masters-tower-view grimoire-tomo-container';
    mountRoot.appendChild(viewRoot);

    // La Torre pasa a ser la vista activa del store (plan 4.1).
    store?.setState?.({ currentView: 'tower' });

    tower = createMastersTowerComponent(viewRoot, {
      moderationClient: activeModerationClient,
      onSignatureIntent: typeof onSignatureIntent === 'function' ? onSignatureIntent : undefined,
      onObjectionIntent: typeof onObjectionIntent === 'function' ? onObjectionIntent : undefined,
      elementFactory,
      documentRef,
    });
    await tower.render();

    // La Torre escucha el bus mientras vive: la deliberación avanzada
    // refresca la cola (moderation:signed / retracted / consecrated /
    // rejected, plan 4.1).
    if (typeof eventTarget?.addEventListener === 'function') {
      eventTarget.addEventListener(SIGNED_EVENT, handleDeliberationAdvanced);
      eventTarget.addEventListener(RETRACTED_EVENT, handleDeliberationAdvanced);
      eventTarget.addEventListener(CONSECRATED_EVENT, handleDeliberationAdvanced);
      eventTarget.addEventListener(REJECTED_EVENT, handleDeliberationAdvanced);
    }
  }

  /**
   * Retira la pintura previa sin marcar la vista como destruida (el render
   * idempotente lo usa antes de volver a montar).
   */
  function destroyInternal() {
    if (typeof eventTarget?.removeEventListener === 'function') {
      eventTarget.removeEventListener(SIGNED_EVENT, handleDeliberationAdvanced);
      eventTarget.removeEventListener(RETRACTED_EVENT, handleDeliberationAdvanced);
      eventTarget.removeEventListener(CONSECRATED_EVENT, handleDeliberationAdvanced);
      eventTarget.removeEventListener(REJECTED_EVENT, handleDeliberationAdvanced);
    }
    tower?.destroy?.();
    tower = null;
    for (const node of mountedNodes.splice(0)) {
      node.remove?.();
    }
    viewRoot = null;
  }

  /**
   * Retira la vista del punto de montaje y libera sus nodos.
   * @param {boolean} [removeFromMount=true] El render interno lo invoca con
   *        false para limpiar la pintura previa antes de volver a montar.
   */
  function destroy(removeFromMount = true) {
    destroyInternal();

    if (removeFromMount) {
      for (const child of [...(mountRoot.children ?? [])]) {
        if (String(child.className ?? '').includes('masters-tower-view')) {
          child.remove?.();
        }
      }
    }

    if (removeFromMount) isDestroyed = true;
  }

  return { render, destroy, retry, setModerationClient };
}
