/**
 * lineageOathView.js — La Ceremonia del Juramento de Linaje (SPEC-09,
 * Tarea 4.3).
 *
 * Orquestador de la pantalla solemne y bloqueante del primer acceso:
 *
 * RF-02.1: carga el canon (8 Linajes con heráldica y doctrinas) vía el
 *           cliente del juramento y despliega la rejilla de tarjetas
 *           heráldicas; si el canon no responde, aviso solemne controlado
 *           con reintento — jamás libera la retención (RF-05.1).
 * RF-02.2: una tarjeta expandida convoca al modal solemne (Tarea 4.2);
 *           tarjeta y modal derivan del MISMO texto canónico (doctrina
 *           íntegra y juramento que nombra al linaje).
 * RF-03.1/03.2: tras la doble confirmación, invoca `sealOath`; el veredicto
 *           se emite como `oath:sealed { lineage, retainedRoute }` — el
 *           orquestador de la SPA conduce el retorno — o `oath:failed
 *           { code, message }` con aviso solemne y ceremonia operativa.
 * RNF-04:  una sola carga del canon por montaje; guardia anti-carreras.
 *
 * Eventos del plan §4 (CustomEvent sobre el bus): `oath:catalog-loaded`,
 * `oath:lineage-expanded` (delegado de la tarjeta), `oath:confirmation-*`
 * (delegados del modal) y los veredictos `oath:sealed` / `oath:failed`.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Module nativo; DOM puro; cero librerías.
 *   - Artículo IV/V: leyendas solemnes en castellano; identificadores en inglés.
 *   - Degradación elegante (AGENTS.md 8): nunca una traza interna al usuario.
 *
 * @module views/lineageOathView
 */

import { createLineageCardComponent } from '../components/lineageCardComponent.js';
import { createOathModalComponent } from '../components/oathModalComponent.js';

/** Leyendas solemnes de la ceremonia (RNF-02). */
export const LINEAGE_OATH_VIEW_TITLE = 'El Umbral de los Linajes';
export const LINEAGE_OATH_VIEW_INTRO =
  'Antes de pisar el santuario, elige la Casa que llevarás para siempre: contempla su doctrina y jura con conocimiento de causa.';
export const LINEAGE_OATH_CATALOG_ERROR_LEGEND = 'El canon no responde: los Ocho Linajes guardan silencio.';
export const LINEAGE_OATH_RETRY_LABEL = 'Reintentar invocación';
export const LINEAGE_OATH_SEALING_LEGEND = 'Sellando el juramento…';
export const LINEAGE_OATH_SEALED_LEGEND = 'El juramento ha sido sellado. El santuario te aguarda.';
export const LINEAGE_OATH_401_LEGEND =
  'Tu vínculo con el santuario ha expirado. Renueva tu sesión para completar el juramento.';
export const LINEAGE_OATH_GENERIC_FAILURE_LEGEND = 'El juramento no pudo sellarse. La ceremonia permanece abierta para reintentarlo.';

/** Códigos de error del contrato (plan §2.2) con su leyenda solemne. */
const FAILURE_LEGEND_BY_CODE = Object.freeze({
  SESSION_EXPIRED: LINEAGE_OATH_401_LEGEND,
  LINEAGE_OATH_CONFLICT: LINEAGE_OATH_GENERIC_FAILURE_LEGEND,
  OATH_FORBIDDEN_ROLE: LINEAGE_OATH_GENERIC_FAILURE_LEGEND,
  INVALID_LINEAGE: LINEAGE_OATH_GENERIC_FAILURE_LEGEND,
  networkError: LINEAGE_OATH_GENERIC_FAILURE_LEGEND,
});

/** Eventos del plan §4. */
const CATALOG_LOADED_EVENT = 'oath:catalog-loaded';
const LINEAGE_EXPANDED_EVENT = 'oath:lineage-expanded';
const SEALING_EVENT = 'oath:sealing';
const SEALED_EVENT = 'oath:sealed';
const FAILED_EVENT = 'oath:failed';

/**
 * Crea la vista de la ceremonia del juramento.
 *
 * @param {HTMLElement} mountRoot Punto de montaje (`<main id="app">`).
 * @param {Object} options
 * @param {Object} options.lineageOathClient Cliente HTTP del juramento
 *        (Tarea 3.1): `fetchOathCatalog`, `sealOath`.
 * @param {HTMLDialogElement} [options.oathDialog] `<dialog>` anfitrión del
 *        modal solemne (Tarea 4.2). Por defecto se forja un diálogo propio.
 * @param {Document} [options.documentRef] Documento anfitrión (arneses).
 * @param {(tagName: string) => HTMLElement} [options.elementFactory] Fábrica
 *        inyectable (arneses sin navegador).
 * @param {EventTarget} [options.eventTarget] Bus del plan §4; por defecto la
 *        raíz de montaje.
 * @returns {Object} API: { render, destroy, retry }.
 */
export function createLineageOathView(mountRoot, options = {}) {
  const {
    lineageOathClient,
    oathDialog = null,
    documentRef = globalThis.document,
    elementFactory = (tagName) => globalThis.document.createElement(tagName),
    eventTarget = null,
  } = options;

  const bus = eventTarget ?? mountRoot;

  /** Guardias y estado vivo de la ceremonia. */
  let isDestroyed = false;
  let fetchSequence = 0;
  let sealInProgress = false;

  /** El canon cargado (LineageProfileDto[]), la rejilla y las tarjetas. */
  let catalog = [];
  let cards = [];

  /** Nodos vivos para limpieza determinista. */
  const mountedNodes = [];
  function track(node) {
    mountedNodes.push(node);
    return node;
  }

  /** Emite un evento del plan §4 sobre el bus. */
  function emit(eventName, detail) {
    const CustomEventCtor = globalThis.CustomEvent;
    bus.dispatchEvent(new CustomEventCtor(eventName, { detail, bubbles: true }));
  }

  /** Localizador por clase para DOM simulado (fallback de querySelector). */
  function findByClass(root, className, found = []) {
    for (const child of root.children ?? []) {
      if (child.classList?.contains?.(className)) found.push(child);
      findByClass(child, className, found);
    }
    return found;
  }

  /** Aviso solemne de fallo: el canon o el sellado no responden (RF-02.1/03.2). */
  function showFailure(legend) {
    let failure = findByClass(viewRoot, 'lineage-oath__failure')[0] ?? null;
    if (failure === null) {
      failure = documentRef.createElement('div');
      failure.setAttribute('class', 'lineage-oath__failure');
      failure.setAttribute('role', 'alert');
      viewRoot.appendChild(failure);

      const message = documentRef.createElement('p');
      message.setAttribute('class', 'lineage-oath__failure-legend');
      track(message);
      failure.appendChild(message);
      failure._legendElement = message;

      const retryButton = documentRef.createElement('button');
      retryButton.setAttribute('type', 'button');
      retryButton.setAttribute('class', 'lineage-oath__retry button button--secondary');
      retryButton.textContent = LINEAGE_OATH_RETRY_LABEL;
      retryButton.addEventListener('click', () => {
        void retry();
      });
      failure.appendChild(retryButton);
    }
    failure._legendElement.textContent = legend;
    failure.removeAttribute('hidden');
  }

  /** Retira el aviso de fallo (reintento en marcha o éxito). */
  function clearFailure() {
    const failure = findByClass(viewRoot, 'lineage-oath__failure')[0] ?? null;
    failure?.setAttribute('hidden', 'true');
  }

  /** Leyenda solemne del mapa de códigos, con degradación genérica. */
  function failureLegendFor(code) {
    return FAILURE_LEGEND_BY_CODE[code] ?? LINEAGE_OATH_GENERIC_FAILURE_LEGEND;
  }

  /** Pinta «Sellando…»: gestos vedados mientras el backend resuelve (RF-03.3). */
  function setSealing(isSealing) {
    sealInProgress = isSealing;
    const sealing = findByClass(viewRoot, 'lineage-oath__sealing')[0] ?? null;
    if (sealing !== null) {
      if (isSealing) sealing.removeAttribute('hidden');
      else sealing.setAttribute('hidden', 'true');
    }
    for (const card of cards) {
      card.element.setAttribute('aria-busy', String(isSealing));
    }
  }

  /** Monta la rejilla solemne con las 8 tarjetas heráldicas (RF-02.1). */
  function renderGrid() {
    let grid = findByClass(viewRoot, 'lineage-oath__grid')[0] ?? null;
    if (grid === null) {
      grid = documentRef.createElement('div');
      grid.setAttribute('class', 'lineage-oath__grid');
      viewRoot.appendChild(grid);
    }
    // Reconstrucción limpia: el canon es inmutable, pero un fallo de sellado
    // no debe dejar tarjetas duplicadas si la vista repinta.
    if (typeof grid.replaceChildren === 'function') {
      grid.replaceChildren();
    } else {
      for (const childNode of [...(grid.children ?? [])]) childNode.remove();
    }
    cards = [];
    for (const lineageProfile of catalog) {
      const card = createLineageCardComponent(lineageProfile, {
        onSwearIntent: (lineageId) => handleSwearIntent(lineageId),
        onExpanded: (lineageId) => emit(LINEAGE_EXPANDED_EVENT, { lineageId }),
        elementFactory,
        documentRef,
      });
      track(card.element);
      grid.appendChild(card.element);
      cards.push(card);
    }
  }

  /**
   * Gesto «Jurar» de una tarjeta expandida: convoca al modal solemne
   * (Tarea 4.2) con el MISMO texto canónico (RF-02.2).
   */
  function handleSwearIntent(lineageId) {
    if (isDestroyed || sealInProgress) return;
    const lineageProfile = catalog.find((entry) => entry.id === lineageId) ?? null;
    if (lineageProfile === null) return;
    // Solo una tarjeta EXPANDIDA convoca el modal (RF-02.3).
    const card = cards.find((entry) => entry.element.getAttribute('data-lineage-id') === lineageId) ?? null;
    if (card === null || card.isExpanded() === false) return;
    oathModal.open({
      lineageId,
      lineageName: String(lineageProfile.name ?? lineageId),
      originElement: card.element,
    });
  }

  /** Doble confirmación consumada: el sellado viaja al backend (RF-03.1). */
  async function handleOathConfirmed(lineageId) {
    if (sealInProgress) return;
    setSealing(true);
    emit(SEALING_EVENT, { lineageId });

    const result = await lineageOathClient.sealOath(lineageId);
    if (isDestroyed) return;
    setSealing(false);

    if (result?.success === true) {
      const data = result.data ?? {};
      // Retorno: ruta retenida del veredicto, o el portal (RF-03.1). El
      // orquestador (main.js) consume `oath:sealed` y navega sin recarga.
      emit(SEALED_EVENT, {
        lineage: data.lineage ?? lineageId,
        retainedRoute: typeof data.retainedRoute === 'string' ? data.retainedRoute : null,
      });
      return;
    }

    // Fallo solemne controlado (RF-03.2): sin trazas, ceremonia operativa.
    const errorCode = result?.error?.code ?? 'networkError';
    showFailure(failureLegendFor(errorCode));
    emit(FAILED_EVENT, {
      code: errorCode,
      message: result?.error?.message ?? LINEAGE_OATH_GENERIC_FAILURE_LEGEND,
    });
  }

  /** El modal solemne (Tarea 4.2), forjado sobre su diálogo anfitrión. */
  let oathModal = null;

  /** Raíz de la vista y referencias vivas. */
  let viewRoot = null;

  /** Consulta el canon y despliega la ceremonia (RF-02.1, RNF-04). */
  async function load() {
    const currentSequence = ++fetchSequence;
    const result = await lineageOathClient.fetchOathCatalog();

    // Respuesta tardía o vista desmontada: jamás se pinta (RNF-04).
    if (currentSequence !== fetchSequence || isDestroyed || viewRoot === null) return;

    if (result?.success !== true || !Array.isArray(result.data?.lineages)) {
      // El canon no responde: aviso solemne + reintento, SIN liberar la
      // retención (RF-05.1: sin datos del canon no hay juramento).
      showFailure(LINEAGE_OATH_CATALOG_ERROR_LEGEND);
      emit(FAILED_EVENT, {
        code: result?.error?.code ?? 'networkError',
        message: result?.error?.message ?? LINEAGE_OATH_CATALOG_ERROR_LEGEND,
      });
      return;
    }

    catalog = result.data.lineages;
    clearFailure();
    renderGrid();
    emit(CATALOG_LOADED_EVENT, { lineages: catalog });
  }

  /** Reintento de la carga del canon (o del sellado tras fallo). */
  async function retry() {
    if (isDestroyed) return;
    fetchSequence += 1; // invalida respuestas en vuelo de la consulta fallida.
    clearFailure();
    if (catalog.length === 0) {
      await load();
    }
  }

  /** Monta la ceremonia completa. Idempotente. */
  async function render() {
    if (isDestroyed) return;

    viewRoot = documentRef.createElement('section');
    viewRoot.setAttribute('class', 'lineage-oath');
    viewRoot.setAttribute('aria-labelledby', 'lineageOathTitle');
    viewRoot.setAttribute('aria-busy', 'true');
    track(viewRoot);
    mountRoot.replaceChildren?.(viewRoot);

    const title = documentRef.createElement('h2');
    title.setAttribute('class', 'lineage-oath__title');
    title.setAttribute('id', 'lineageOathTitle');
    title.textContent = LINEAGE_OATH_VIEW_TITLE;
    viewRoot.appendChild(title);

    const intro = documentRef.createElement('p');
    intro.setAttribute('class', 'lineage-oath__intro');
    intro.textContent = LINEAGE_OATH_VIEW_INTRO;
    viewRoot.appendChild(intro);

    // «Sellando…»: región viva que informa mientras el backend resuelve.
    const sealing = documentRef.createElement('p');
    sealing.setAttribute('class', 'lineage-oath__sealing');
    sealing.setAttribute('aria-live', 'polite');
    sealing.setAttribute('hidden', 'true');
    sealing.textContent = LINEAGE_OATH_SEALING_LEGEND;
    viewRoot.appendChild(sealing);

    // La leyenda de sellado feliz (RNF-05); el retorno lo conduce el bus.
    const sealedLegend = documentRef.createElement('p');
    sealedLegend.setAttribute('class', 'lineage-oath__sealed');
    sealedLegend.setAttribute('role', 'status');
    sealedLegend.setAttribute('hidden', 'true');
    sealedLegend.textContent = LINEAGE_OATH_SEALED_LEGEND;
    viewRoot.appendChild(sealedLegend);

    // El modal solemne sobre su diálogo (inyectable; por defecto forjado).
    let hostDialog = oathDialog;
    if (hostDialog === null || hostDialog === undefined) {
      // Sin diálogo inyectado, la vista forja y aloja el suyo: la ceremonia
      // es autosuficiente (en la SPA el shell trae #oathModal).
      hostDialog = documentRef.createElement('dialog');
      hostDialog.setAttribute('class', 'modal modal--oath');
      track(hostDialog);
      viewRoot.appendChild(hostDialog);
    }
    oathModal = createOathModalComponent(hostDialog, {
      onConfirm: (lineageId) => void handleOathConfirmed(lineageId),
      documentRef,
    });

    await load();
    viewRoot.setAttribute('aria-busy', 'false');
  }

  /** Desmonta la ceremonia y su modal (RNF-05, limpieza determinista). */
  function destroy() {
    if (isDestroyed) return;
    isDestroyed = true;
    fetchSequence += 1;
    oathModal?.destroy?.();
    for (const card of cards) card.destroy?.();
    for (const node of mountedNodes) node.remove?.();
    mountedNodes.length = 0;
    cards = [];
    catalog = [];
    viewRoot = null;
  }

  return {
    render,
    destroy,
    retry,
  };
}
