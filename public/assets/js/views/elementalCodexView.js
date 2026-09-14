/**
 * elementalCodexView.js — Vista ceremonial del Códice de Afinidades
 * (SPEC-06, Tarea 5.2).
 *
 * Orquesta la Rueda Rúnica (Tareas 3.2–3.3) con la carga autónoma del
 * grafo elemental (Endpoint 1 vía elementalMatrixClient, Tarea 5.1) y la
 * sincronización con el resto del portal mediante EVENTOS DESACOPLADOS:
 *
 *   - Entrante `grimoire:codex-focus` { elementId, source }: el enlace
 *     rúnico de las fichas del Tomo (Tarea 4.4) preselecciona un elemento
 *     en la Rueda (RF-01.3).
 *   - Saliente `grimoire:codex-resolve` { request, verdict }: la vista
 *     consulta la resolución autoritativa (Endpoint 3, RF-04.1) y la
 *     publica hacia el orquestador/Simulador — sin imports cruzados.
 *
 * API pública: render, destroy, getReactionsForElement (RF-01.2),
 * requestComboResolution (RF-04.1) e isMobileLayout (Tarea 3.3).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Modules nativos, DOM estándar.
 *   - Artículo IV: leyendas y estados solemnes en noble castellano.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en
 *     castellano.
 *
 * Seguridad (AGENTS.md 6.1): todo dato del Códice viaja por textContent —
 * jamás innerHTML.
 */

import { createElementalWheelComponent } from '../components/elementalWheelComponent.js';
import { ELEMENTAL_API_ERROR_CODES } from '../api/elementalMatrixClient.js';

/** Leyendas solemnes de los estados de la vista (Art. IV). */
const LOADING_LEGEND = 'Descifrando el Códice de Afinidades…';
const FAILURE_LEGEND = 'La corriente de maná hacia el Códice se ha interrumpido.';
const RETRY_LABEL = 'Volver a intentar la invocación';

/** Nombre canónico del evento entrante del Tomo (Tarea 4.4). */
const CODEX_FOCUS_EVENT = 'grimoire:codex-focus';
/** Nombre canónico del evento saliente hacia el portal (RF-04.1). */
const CODEX_RESOLVE_EVENT = 'grimoire:codex-resolve';

/**
 * Crea la vista ceremonial del Códice.
 *
 * @param {HTMLElement} mountRoot Punto de montaje (`<main id="app">`).
 * @param {Object} options
 * @param {Object} options.elementalMatrixClient Cliente HTTP (Tarea 5.1).
 * @param {(options: Object) => Object} [options.createRuneWheel] Fábrica
 *   de la Rueda Rúnica (inyectable en arneses; por defecto la real).
 * @param {Object} [options.document] Documento inyectable (arneses).
 * @param {(tagName: string) => HTMLElement} [options.elementFactory]
 *   Fábrica de nodos inyectable (arneses).
 * @param {EventTarget} [options.eventTarget] Blanco de los eventos
 *   desacoplados (por defecto, el punto de montaje).
 * @returns {Object} API: { render, destroy, getReactionsForElement,
 *   requestComboResolution, isMobileLayout }.
 */
export function createCodexView(mountRoot, options = {}) {
  const {
    elementalMatrixClient,
    createRuneWheel = (wheelOptions) => createElementalWheelComponent(wheelOptions),
    document: doc = globalThis.document,
    elementFactory = (tagName) => doc.createElement(tagName),
    eventTarget = mountRoot,
  } = options;

  /** Nodo raíz de la vista (se crea en render()). */
  let root = null;
  /** Instancia viva de la Rueda Rúnica. */
  let runeWheel = null;
  /** Grafo elemental vigente (fuente de verdad de la vista). */
  let matrixGraph = null;
  /** Escucha viva del evento entrante del Tomo. */
  let focusListener = null;

  /**
   * Carga el grafo, monta la Rueda o degrada con leyenda y reintento.
   * Autonomía total: la vista no depende de que nadie la alimente (RF-01.1).
   */
  async function loadMatrix() {
    const graphEnvelope = await elementalMatrixClient.fetchMatrixGraph();
    if (graphEnvelope.success !== true) {
      renderFailure(graphEnvelope);
      return;
    }
    matrixGraph = graphEnvelope.data;
    mountWheel();
  }

  /** Monta la Rueda Rúnica real con el grafo vigente. */
  function mountWheel() {
    const wheelHost = root.querySelector('.elemental-codex-view__wheel-host');
    if (wheelHost === null) {
      return;
    }
    wheelHost.replaceChildren();
    runeWheel = createRuneWheel({
      catalog: matrixGraph,
      document: doc,
      createElement: elementFactory,
      eventTarget,
    });
    runeWheel.mount(wheelHost);
    renderLoading(false);
  }

  /** Muestra u oculta la leyenda de carga (RF-01.1, estado inicial). */
  function renderLoading(visible) {
    const legend = root.querySelector('.elemental-codex-view__loading');
    if (legend !== null) {
      legend.setAttribute('hidden', visible ? '' : 'hidden');
      if ('hidden' in legend) legend.hidden = !visible;
    }
  }

  /** Estado de fallo: leyenda solemne + botón de reintento (RNF-05). */
  function renderFailure(envelope) {
    const failure = root.querySelector('.elemental-codex-view__failure');
    if (failure === null) {
      return;
    }
    failure.setAttribute('hidden', 'hidden');
    if ('hidden' in failure) failure.hidden = false;
    const legend = failure.querySelector('.elemental-codex-view__failure-legend');
    if (legend !== null) {
      legend.textContent = envelope?.error?.message ?? FAILURE_LEGEND;
    }
    renderLoading(false);
  }

  /**
   * Escucha entrante (RF-01.3): el enlace rúnico del Tomo preselecciona
   * el elemento en la Rueda. Sin acoplamiento: solo evento y contrato.
   *
   * @param {{detail?: {elementId?: string}}} event
   */
  function handleCodexFocus(event) {
    const elementId = String(event?.detail?.elementId ?? '');
    if (elementId === '' || runeWheel === null) {
      return;
    }
    runeWheel.highlightElement(elementId);
    // La compatibilidad del elemento enfocado también pasa por la API
    // (RF-01.2): la lámina ya la pinta la Rueda con el grafo local.
    void elementalMatrixClient.fetchReactionsForElement(elementId);
  }

  return {
    /** Monta la vista: esqueleto, estados y carga autónoma del grafo. */
    async render() {
      root = elementFactory('section');
      root.setAttribute('class', 'elemental-codex-view');

      const loading = elementFactory('p');
      loading.setAttribute('class', 'elemental-codex-view__loading');
      loading.textContent = LOADING_LEGEND;
      root.appendChild(loading);

      const failure = elementFactory('div');
      failure.setAttribute('class', 'elemental-codex-view__failure');
      failure.setAttribute('hidden', 'hidden');
      if ('hidden' in failure) failure.hidden = true;
      const failureLegend = elementFactory('p');
      failureLegend.setAttribute('class', 'elemental-codex-view__failure-legend');
      failureLegend.textContent = FAILURE_LEGEND;
      const retryButton = elementFactory('button');
      retryButton.setAttribute('class', 'elemental-codex-view__retry');
      retryButton.type = 'button';
      retryButton.setAttribute('data-action', 'retry');
      retryButton.textContent = RETRY_LABEL;
      retryButton.addEventListener('click', () => {
        failure.setAttribute('hidden', 'hidden');
        if ('hidden' in failure) failure.hidden = true;
        renderLoading(true);
        void loadMatrix();
      });
      failure.appendChild(failureLegend);
      failure.appendChild(retryButton);
      root.appendChild(failure);

      const wheelHost = elementFactory('div');
      wheelHost.setAttribute('class', 'elemental-codex-view__wheel-host');
      root.appendChild(wheelHost);

      mountRoot.appendChild(root);

      // Sincronización entrante desacoplada (Tarea 4.4 → Códice).
      focusListener = handleCodexFocus;
      eventTarget.addEventListener(CODEX_FOCUS_EVENT, focusListener);

      renderLoading(true);
      await loadMatrix();
    },

    /** Desmonta la vista y baja las escuchas (limpieza determinista). */
    destroy() {
      if (focusListener !== null) {
        eventTarget.removeEventListener(CODEX_FOCUS_EVENT, focusListener);
        focusListener = null;
      }
      runeWheel?.destroy?.();
      runeWheel = null;
      matrixGraph = null;
      if (root !== null && typeof root.remove === 'function') {
        root.remove();
      } else if (mountRoot && typeof mountRoot.replaceChildren === 'function') {
        mountRoot.replaceChildren();
      }
      root = null;
    },

    /**
     * Consulta de compatibilidades de un glifo (RF-01.2): aristas
     * reactivas duales y catalizadoras vía Endpoint 2.
     *
     * @param {string} element Identificador canónico del elemento.
     */
    getReactionsForElement(element) {
      return elementalMatrixClient.fetchReactionsForElement(element);
    },

    /**
     * Resolución autoritativa de un combo (RF-04.1): consulta el
     * Endpoint 3 y emite `grimoire:codex-resolve` con el veredicto para
     * que el orquestador lo entregue al Simulador — eventos desacoplados.
     *
     * @param {Object} request Payload canónico
     *   { activeAura, incomingSpell, stunlockImmune }.
     */
    async requestComboResolution(request) {
      const verdictEnvelope = await elementalMatrixClient.resolveCombo(request);
      if (typeof eventTarget.dispatchEvent === 'function') {
        const event = typeof CustomEvent === 'function'
          ? new CustomEvent(CODEX_RESOLVE_EVENT, { detail: { request, verdict: verdictEnvelope.data ?? null, error: verdictEnvelope.error ?? null }, bubbles: true })
          : { type: CODEX_RESOLVE_EVENT, detail: { request, verdict: verdictEnvelope.data ?? null, error: verdictEnvelope.error ?? null }, bubbles: true };
        eventTarget.dispatchEvent(event);
      }
      return verdictEnvelope;
    },

    /** ¿Está vigente el layout móvil de la Rueda? (Tarea 3.3) */
    isMobileLayout() {
      return runeWheel?.isMobileLayout?.() ?? false;
    },
  };
}
