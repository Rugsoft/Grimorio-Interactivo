/**
 * errorView.js — Vistas temáticas de error y estados de rescate (Tarea 5.5).
 *
 * RF-06.1: búsqueda sin coincidencias — leyenda *«Ningún conjuro responde a
 *          esas runas»* con botón de restablecimiento de filtros.
 * RF-06.2: error 404 — *«Pergamino desvanecido en el éter»* con ENLACE
 *          DIRECTO de retorno a la biblioteca (sobre estándar del plan 2.4,
 *          código `SCROLL_LOST_IN_AETHER`, `recoveryAction: RETURN_TO_LIBRARY`).
 * RF-06.3: corte de maná — *«La corriente de maná se ha interrumpido»* con
 *          botón de reintento (`recoveryAction: RETRY`), sobre el contrato de
 *          API_ERROR_CODES del cliente HTTP (Tarea 3.3).
 *
 * La vista es PRESENTACIONAL: recibe el estado (kind o errorEnvelope) y
 * notifica las acciones de rescate al orquestador vía callbacks — nunca
 * decide rutas ni reintentos por su cuenta (plan 4.1).
 *
 * Constitución:
 *   - Artículo I: ES Modules nativos; `<a>` y `<button>` estándar.
 *   - Artículo IV: leyendas y botones solemnes en castellano.
 *   - Artículo V: identificadores en inglés camelCase; comentarios castellanos.
 *
 * Seguridad (AGENTS.md 6.1): todo texto (incluidos `details` y mensajes del
 * backend, que provienen de datos) viaja por textContent — jamás innerHTML.
 */

/** Códigos de error conocidos (eco de API_ERROR_CODES del cliente, Tarea 3.3). */
import { API_ERROR_CODES } from '../api/spellClient.js';

/** Acciones de rescate que esta vista puede notificar al orquestador. */
export const ERROR_RECOVERY_ACTIONS = Object.freeze({
  resetFilters: 'RESET_FILTERS',
  returnToLibrary: 'RETURN_TO_LIBRARY',
  retry: 'RETRY',
});

/** Ruta hash de la biblioteca (retornó directo desde el 404, plan 4.3). */
const LIBRARY_HASH = '#/biblioteca';

/** Leyendas temáticas por tipo de estado (Art. IV). */
const EMPTY_SEARCH_MESSAGE = 'Ningún conjuro responde a esas runas.';

/**
 * Crea la vista de errores y rescate.
 *
 * @param {HTMLElement} mountRoot Punto de montaje (`<main id="app">`).
 * @param {Object} options
 * @param {() => void} [options.onResetFilters] Rescate RF-06.1: restablecer filtros.
 * @param {() => void} [options.onReturnToLibrary] Rescate RF-06.2: volver a la biblioteca.
 * @param {() => void} [options.onRetry] Rescate RF-06.3: reintentar la invocación.
 * @param {(tagName: string) => HTMLElement} [options.elementFactory] Fábrica inyectable (tests).
 * @returns {Object} API: { render, clear, destroy }.
 */
export function createErrorView(mountRoot, options) {
  const {
    onResetFilters,
    onReturnToLibrary,
    onRetry,
    elementFactory = (tagName) => document.createElement(tagName),
  } = options;

  /** Nodos vivos de la vista, para limpieza determinista en destroy(). */
  const mountedNodes = [];

  /** Registra un nodo como hijo de la vista para su ciclo de vida. */
  function track(node) {
    mountedNodes.push(node);
    return node;
  }

  /** Búsqueda recursiva por clase sobre el DOM (real o simulado). */
  function byClass(root, className) {
    // DOM real: querySelector nativo. Simulado: barrido con classes.has().
    if (typeof root.querySelector === 'function') {
      return root.querySelector('.' + className);
    }
    const found = [];
    (function walk(node) {
      for (const child of node.children ?? []) {
        if (child.classes?.has(className)) found.push(child);
        walk(child);
      }
    })(root);
    return found[0] ?? null;
  }

  /** Añade un elemento de texto seguro (nunca innerHTML, AGENTS.md 6.1). */
  function appendTextElement(parent, tagName, className, text) {
    const node = elementFactory(tagName);
    node.className = className;
    node.textContent = text;
    parent.appendChild(node);
    return track(node);
  }

  /**
   * Monta el bloque de rescate común: retorno a la biblioteca como última
   * salida; el botón específico (reseteo o reintento) según el estado.
   *
   * @param {HTMLElement} container Bloque donde añadir los controles.
   * @param {{reset?: boolean, retry?: boolean}} controls Botones a montar.
   */
  function buildRescueControls(container, controls) {
    if (controls.reset) {
      const resetButton = track(elementFactory('button'));
      resetButton.type = 'button';
      resetButton.className = 'error-view__reset button button--secondary';
      resetButton.textContent = 'Restablecer los filtros arcanos';
      resetButton.addEventListener('click', () => onResetFilters?.());
      container.appendChild(resetButton);
    }

    if (controls.retry) {
      const retryButton = track(elementFactory('button'));
      retryButton.type = 'button';
      retryButton.className = 'error-view__retry button button--secondary';
      retryButton.textContent = 'Reintentar invocación';
      retryButton.addEventListener('click', () => onRetry?.());
      container.appendChild(retryButton);
    }

    // Rescate universal: enlace DIRECTO de retorno a la biblioteca (criterio
    // RF-06.2). En un `<a>` real la navegación hash funciona sin JS; el
    // handler adicional avisa al orquestador para sincronizar el store.
    const returnLink = track(elementFactory('a'));
    returnLink.className = 'error-view__return';
    returnLink.setAttribute('href', LIBRARY_HASH);
    returnLink.textContent = 'Regresar a la Biblioteca de Conjuros';
    returnLink.addEventListener('click', () => onReturnToLibrary?.());
    container.appendChild(returnLink);
  }

  /**
   * Renderiza un estado de error o de rescate. Idempotente: reemplaza el
   * estado anterior sin duplicar raíces.
   *
   * Acepta DOS formas mutuamente excluyentes:
   *   - `{ kind: 'emptySearch', message? }` — estado sin sobre (RF-06.1).
   *   - `{ errorEnvelope }` — sobre estándar del plan 2.4 (RF-06.2/06.3).
   *
   * @param {Object} state Estado de error a representar.
   */
  function render(state = {}) {
    destroy(false);

    const errorEnvelope = state.errorEnvelope ?? null;
    const kind = state.kind
      ?? (errorEnvelope?.error?.code === API_ERROR_CODES.scrollLost ? 'notFound' : 'networkError');

    const root = track(elementFactory('section'));
    // El tomo central acota la vista de rescate a 1280 px (Tarea 2.1).
    root.className = 'error-view grimoire-tomo-container';
    root.setAttribute('data-kind', kind);
    root.setAttribute('role', 'alert');
    mountRoot.appendChild(root);

    appendTextElement(root, 'p', 'error-view__sigil', '⚠');

    // Mensaje principal: el del sobre si existe; si no, la leyenda del estado.
    const message = errorEnvelope?.error?.message
      ?? state.message
      ?? EMPTY_SEARCH_MESSAGE;
    appendTextElement(root, 'h1', 'error-view__message', message);

    // Detalles técnicos del sobre (plan 2.4), como TEXTO seguro.
    if (errorEnvelope?.error?.details) {
      appendTextElement(root, 'p', 'error-view__details', errorEnvelope.error.details);
    }

    // Controles de rescate según el tipo de estado:
    if (kind === 'emptySearch') {
      // RF-06.1 (criterio): leyenda temática + botón de reseteo.
      buildRescueControls(root, { reset: true });
    } else {
      // RF-06.3 (recoveryAction RETRY) y códigos desconocidos: reintento como
      // degradación elegante, con el retorno a la biblioteca como rescate alterno.
      const shouldOfferRetry = errorEnvelope?.error?.recoveryAction === 'RETRY'
        || errorEnvelope?.error?.code === API_ERROR_CODES.networkError
        || errorEnvelope !== null; // código no catalogado: reintento genérico.
      buildRescueControls(root, { retry: shouldOfferRetry });
    }
  }

  /** Retira el estado de error del montaje sin destruir la instancia. */
  function clear() {
    destroy(false);
  }

  /**
   * Retira la vista del punto de montaje y libera sus nodos.
   * @param {boolean} [removeFromRoot=true] El render interno lo invoca con
   *        false para reemplazar el estado previo antes de volver a montar.
   */
  function destroy(removeFromRoot = true) {
    for (const node of mountedNodes.splice(0)) {
      node.remove?.();
    }
    if (removeFromRoot) {
      for (const child of [...(mountRoot.children ?? [])]) {
        if (String(child.className ?? '').includes('error-view')) {
          child.remove?.();
        }
      }
    }
  }

  return { render, clear, destroy };
}
