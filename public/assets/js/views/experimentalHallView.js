/**
 * experimentalHallView.js — Vista pública del Atrio de los Arcanos
 * Experimentales (Tarea 6.4, TASKS-08).
 *
 * RF-05.1: monta el pabellón comunitario del Atrio en su ruta pública. La
 *   contemplación es LIBRE: ningún control exige vínculo arcano y cualquier
 *   visitante puede explorar y filtrar el catálogo de obras en deliberación
 *   (criterio «Hecho cuando»).
 * RF-05.2: el gesto de prueba en el simulador lo ANUNCIA la vista al
 *   orquestador (`onSpellTest`); la vista jamás navega por su cuenta.
 *
 * La vista es la única responsable de hablar con el santuario: consulta el
 * catálogo público (Endpoint 4) y entrega los sobres al componente, que solo
 * contempla y pinta (Artículo II: la insignia, el medidor y la paginación
 * viajan servidos; jamás se recalculan en el cliente).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Module nativo; DOM puro; cero librerías.
 *   - Artículo II: el estado canónico lo fija la consulta, no la vista.
 *   - Artículo IV/V: leyendas en noble castellano; identificadores en inglés.
 *   - Degradación elegante (AGENTS.md 8): corte de maná con reintento y
 *     guardia anti-carreras para que una respuesta tardía de una consulta
 *     antigua jamás pinte.
 */

import { createExperimentalHallComponent } from '../components/experimentalHallComponent.js';

/** Eventos del plan 4.1 que refrescan el Atrio sin sondear el reloj. */
const SUBMITTED_EVENT = 'moderation:submitted';
const SIGNED_EVENT = 'moderation:signed';
const CONSECRATED_EVENT = 'moderation:consecrated';

/**
 * Crea la vista del Atrio de los Arcanos Experimentales.
 *
 * @param {HTMLElement} mountRoot Punto de montaje (`<main id="app">`).
 * @param {Object} options
 * @param {Object} options.moderationClient Cliente HTTP (Tarea 5.1; necesita
 *        `fetchExperimentalHall`).
 * @param {Object} [options.store] Almacén reactivo; la vista registra
 *        `currentView: 'experimentalHall'` al montar.
 * @param {(spellId: string) => void} [options.onSpellTest] Gesto de prueba en
 *        la Cámara de Conjuración (RF-05.2; lo declara el orquestador).
 * @param {(tagName: string) => HTMLElement} [options.elementFactory] Fábrica
 *        inyectable (arneses sin navegador).
 * @param {Document} [options.documentRef] Documento anfitrión (arneses).
 * @param {EventTarget} [options.eventTarget] Bus del plan 4.1; el Atrio se
 *        refresca cuando una obra entra (`moderation:submitted`), recibe
 *        firma (`moderation:signed`) o asciende (`moderation:consecrated`),
 *        sin sondear el reloj (RNF-02). Por defecto, la raíz de montaje.
 * @returns {Object} API: { render, destroy, retry, setModerationClient }.
 */
export function createExperimentalHallView(mountRoot, options = {}) {
  const {
    moderationClient,
    store = null,
    onSpellTest,
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

  /** El Atrio quedó desmontado: ninguna respuesta tardía pinta nada. */
  let isDestroyed = false;

  /** Referencias vivas. */
  let viewRoot = null;
  let hall = null;

  /** Registra un nodo como hijo de la vista para su ciclo de vida. */
  function track(node) {
    mountedNodes.push(node);
    return node;
  }

  /** Consulta el catálogo público y alimenta el componente. */
  async function load() {
    if (hall === null) return;
    await hall.render();
  }

  /**
   * Refresco dirigido por evento (plan 4.1): la deliberación avanza y el
   * catálogo comunitario refleja la nueva realidad sin re-consulta ciega
   * de gorro; es SOLO LECTURA: ningún evento decide estados ni medidores.
   */
  function handleDeliberationAdvanced() {
    if (isDestroyed || hall === null) return;
    void load();
  }

  /** Reintenta la última consulta tras un corte de corriente (RNF). */
  async function retry() {
    hall?.render();
  }

  /** Conmuta el cliente del Atrio (reintentos con otro canal). */
  function setModerationClient(nextModerationClient) {
    activeModerationClient = nextModerationClient;
    if (hall !== null) {
      // El componente vive sobre el cliente que la vista le dio al montar;
      // para conmutarlo de verdad, el repintado lo re-forja.
      hall.destroy();
      hall = createExperimentalHallComponent(viewRoot, {
        moderationClient: activeModerationClient,
        onSpellTest: typeof onSpellTest === 'function' ? onSpellTest : undefined,
        elementFactory,
        documentRef,
      });
      hall.render();
    }
  }

  /**
   * Monta la vista completa. Idempotente y de SOLO LECTURA: ningún control
   * exige sesión (RF-05.1). Los visitantes exploran y filtran libremente.
   */
  async function render() {
    if (isDestroyed) return;

    destroyInternal();

    viewRoot = track(elementFactory('section'));
    // El tomo central acota el Atrio a la anchura canónica del santuario.
    viewRoot.className = 'experimental-hall-view grimoire-tomo-container';
    mountRoot.appendChild(viewRoot);

    // El Atrio pasa a ser la vista activa del store (plan 4.1).
    store?.setState?.({ currentView: 'experimentalHall' });

    hall = createExperimentalHallComponent(viewRoot, {
      moderationClient: activeModerationClient,
      onSpellTest: typeof onSpellTest === 'function' ? onSpellTest : undefined,
      elementFactory,
      documentRef,
    });
    await hall.render();

    // El Atrio escucha el bus mientras vive: la deliberación avanzada lo
    // refresca (moderation:submitted / signed / consecrated, plan 4.1).
    if (typeof eventTarget?.addEventListener === 'function') {
      eventTarget.addEventListener(SUBMITTED_EVENT, handleDeliberationAdvanced);
      eventTarget.addEventListener(SIGNED_EVENT, handleDeliberationAdvanced);
      eventTarget.addEventListener(CONSECRATED_EVENT, handleDeliberationAdvanced);
    }
  }

  /**
   * Retira la pintura previa sin marcar la vista como destruida (el render
   * idempotente lo usa antes de volver a montar).
   */
  function destroyInternal() {
    if (typeof eventTarget?.removeEventListener === 'function') {
      eventTarget.removeEventListener(SUBMITTED_EVENT, handleDeliberationAdvanced);
      eventTarget.removeEventListener(SIGNED_EVENT, handleDeliberationAdvanced);
      eventTarget.removeEventListener(CONSECRATED_EVENT, handleDeliberationAdvanced);
    }
    hall?.destroy?.();
    hall = null;
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
        if (String(child.className ?? '').includes('experimental-hall-view')) {
          child.remove?.();
        }
      }
    }

    if (removeFromMount) isDestroyed = true;
  }

  return { render, destroy, retry, setModerationClient };
}
