/**
 * grimoireCollectionView.js — La vista «Mi Grimorio»: el Tomo Personal
 * del adepto (SPEC-11, Tarea 5.3).
 *
 * Vista orquestadora del tomo íntimo: una carga del sobre paginado
 * (RNF-01: apertura < 100 ms de backend), filtro por afinidad con
 * conteo, estado vacío con invitación a la Biblioteca (RF-02.2, sin
 * lenguaje de error), paginación viva (plan §3.5, caso límite 10) y
 * degradación solemne tras 401 (RF-05.2, hallazgo 7: la sala íntima a
 * oscuras — nada se vacía ante los ojos del adepto).
 *
 * La vista es la ÚNICA que habla con el santuario (Artículo II): los
 * componentes (spellCardComponent, discardTomeEntryModalComponent)
 * pintan estados derivados del DTO y delegan gestos.
 *
 * Ciclo (plan §4.1):
 *   montaje → fetchCollection(element, page)
 *     ├─ 200 → pintar entradas + rótulo «N entradas · página X de Y»
 *     ├─ lista vacía → «Tu tomo aguarda su primera obra» + «Recorrer la Biblioteca»
 *     └─ 401 → aviso solemne + gestos apagados + lectura y filtro conservados
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Module nativo; DOM por createElement
 *     con textContent puro — innerHTML PROHIBIDO; cero librerías.
 *   - Artículo IV/V: leyendas en noble castellano; identificadores en inglés.
 *
 * @module views/grimoireCollectionView
 */

import { createSpellCardComponent } from '../components/spellCardComponent.js';
import { createDiscardTomeEntryModalComponent } from '../components/discardTomeEntryModalComponent.js';
import { ceremonialLegendFor } from '../api/grimoireCollectionClient.js';

/** Rótulos solemnes de la vista (Anexo A del plan, RATIFICADO). */
export const GRIMOIRE_COLLECTION_VIEW_TITLE = 'Mi Grimorio';
export const GRIMOIRE_COLLECTION_EMPTY_LEGEND = 'Tu tomo aguarda su primera obra';
export const GRIMOIRE_COLLECTION_EMPTY_CTA_LABEL = 'Recorrer la Biblioteca';
export const GRIMOIRE_COLLECTION_EMPTY_CTA_HASH = '#/biblioteca';
export const GRIMOIRE_COLLECTION_EXPIRED_LEGEND =
  'Tu vínculo con el santuario ha expirado: renuévalo y tus gestos aguardarán donde los dejaste.';

/** Afinidades canónicas del filtro (SPEC-06, las mismas del backend). */
export const COLLECTION_FILTER_ELEMENTS = Object.freeze([
  { value: '', label: 'Todas las afinidades' },
  { value: 'fire', label: 'Fuego' },
  { value: 'water', label: 'Agua' },
  { value: 'lightning', label: 'Rayo' },
  { value: 'earth', label: 'Tierra' },
  { value: 'wind', label: 'Viento' },
  { value: 'light', label: 'Luz' },
  { value: 'darkness', label: 'Oscuridad' },
  { value: 'pureArcane', label: 'Arcano Puro' },
]);

/** Marcas solemnes del tomo a rótulos castellanos (leídos del DTO; el
 *  MAPA ÚNICO vive en el backend — la vista solo rotula, jamás traduce
 *  estados del ciclo de vida, hallazgos 12/21). */
export const TOME_MARK_LABELS = Object.freeze({
  living: 'Obra viva',
  gestation: 'Obra en gestación',
  withdrawn: 'Obra apartada del canon',
});

/** ÚNICA marca con convocatoria plena (RF-03.2): todo lo que no sea
 *  `living` veda el gesto — la convocatoria aguarda (gestación) o el
 *  canon la ha apartado. La entrada jamás se disuelve por detrás. */
export const LIVING_TOME_MARK = 'living';

/** Leyendas canónicas de la vedación de convocatoria (RF-03.2, Artículo
 *  IV): sobrias, sin lenguaje de error, castellanas. */
export const SUMMON_VETO_LEGENDS = Object.freeze({
  gestation: 'La obra madura: la convocatoria aguarda a que el Tribunal la selle.',
  withdrawn: 'El canon ha apartado esta obra: su convocatoria queda vedada.',
});

/** Eventos del plan §4 que la vista emite sobre su raíz. */
export const GRIMOIRE_COLLECTION_VIEW_EVENTS = Object.freeze({
  catalogLoaded: 'collection:loaded',
  catalogFailed: 'collection:failed',
  collectionChanged: 'collection:changed',
  sealFailed: 'tome:seal-failed',
  praiseFailed: 'tome:praise-failed',
  sessionExpired: 'collection:session-expired',
});

/** Tamaño de hoja del contrato (candado del backend, CollectionPageDto). */
const PAGE_LIMIT = 50;

/**
 * Crea la vista «Mi Grimorio».
 *
 * @param {HTMLElement} mountRoot Punto de montaje (`<main id="app">`).
 * @param {Object} options
 * @param {Object} options.collectionClient Cliente del tomo (Tarea 4.2):
 *        fetchCollection, collectSpell, discardSpell, praiseSpell.
 * @param {(hash: string) => void} [options.onNavigateToLibrary] Invitación
 *        al estado vacío (RF-02.2): el shell navega a #/biblioteca.
 * @param {(slug: string, meta?: {spellId: string}) => void} [options.onSummonSpell]
 *        Convocatoria de una entrada viva hacia el Simulador (RF-03.1,
 *        Tarea 5.4): solo se emite con `tomeMark` 'living'.
 * @param {(tagName: string) => HTMLElement} [options.elementFactory]
 *        Fábrica inyectable (arneses sin navegador).
 * @param {Document} [options.documentRef] Documento anfitrión.
 * @param {EventTarget} [options.eventTarget] Bus del plan §4 (por defecto,
 *        la raíz de montaje).
 * @returns {Object} API: { render, destroy, retry, setFilter, setPage }.
 */
export function createGrimoireCollectionView(mountRoot, options = {}) {
  const {
    collectionClient,
    onNavigateToLibrary,
    onReservedAction,
    documentRef = globalThis.document,
  } = options;

  // El default de la fábrica deriva del documento INYECTADO (los arneses
  // carecen de `globalThis.document`; lección de los arneses de SPEC-10).
  const elementFactory = options.elementFactory
    ?? ((tagName) => documentRef.createElement(tagName));

  /** Bus de eventos del plan §4. */
  const eventTarget = options.eventTarget ?? mountRoot;

  /** Nodos vivos de la vista, para limpieza determinista. */
  const mountedNodes = [];

  /** Guardia anti-carreras: solo el sobre más reciente pinta. */
  let fetchSequence = 0;
  let isDestroyed = false;

  /** Estado vivo de la paginación y el filtro (RF-02.3, caso límite 4). */
  let currentElement = '';
  let currentPage = 1;
  /** Sobre vivo: `collectionChanged` y los repintados beben de él. */
  let currentState = null;

  /** Referencias vivas. */
  let viewRoot = null;
  let entriesHost = null;
  let countLabel = null;
  let filterSelect = null;
  let paginationHost = null;
  let emptyStateBox = null;
  let sessionExpiredBox = null;
  let accessButton = null;
  let liveRegion = null;
  let discardModal = null;

  /** Registra un nodo como hijo de la vista. */
  function track(node) {
    mountedNodes.push(node);
    return node;
  }

  /** Emite un evento del plan §4 sobre el bus de la vista. */
  function emit(eventName, detail = {}) {
    const CustomEventCtor = globalThis.CustomEvent;
    if (typeof CustomEventCtor === 'function') {
      eventTarget.dispatchEvent(new CustomEventCtor(eventName, { detail, bubbles: true }));
    }
  }

  /** Narra en la región viva (RNF-03). */
  function announce(message) {
    if (liveRegion !== null) {
      liveRegion.textContent = '';
      liveRegion.textContent = String(message);
    }
  }

  // -------------------------------------------------------------------
  // Degradación solemne tras 401 (RF-05.2, hallazgo 7)
  // -------------------------------------------------------------------

  /**
   * La sala íntima a oscuras: aviso solemne del umbral, gestos apagados
   * (disabled + aria-disabled) y NADA vaciado — la lectura y el filtro
   * activo se conservan a la vista del adepto.
   */
  function degradeSession() {
    if (sessionExpiredBox !== null) sessionExpiredBox.removeAttribute('hidden');
    announce(GRIMOIRE_COLLECTION_EXPIRED_LEGEND);
    emit(GRIMOIRE_COLLECTION_VIEW_EVENTS.sessionExpired, { code: 'UNAUTHENTICATED' });

    // Solo se apagan los gestos de las tarjetas YA CARGADAS: la lectura
    // (título, entradas, filtro y paginación) permanece íntegra.
    const gestures = findDescendants(viewRoot, (node) => node.tagName === 'BUTTON');
    for (const gesture of gestures) {
      if (gesture === accessButton) continue;
      gesture.disabled = true;
      gesture.setAttribute('aria-disabled', 'true');
    }
    if (filterSelect !== null) {
      filterSelect.disabled = true;
      filterSelect.setAttribute('aria-disabled', 'true');
    }
  }

  /** Descendientes del árbol (para el DOM simulado y el real). */
  function findDescendants(root, predicate, found = []) {
    if (root === null) return found;
    for (const child of root.children ?? []) {
      if (predicate(child)) found.push(child);
      findDescendants(child, predicate, found);
    }
    return found;
  }

  // -------------------------------------------------------------------
  // Gestos — la vista consume las intenciones de los componentes.
  // -------------------------------------------------------------------

  /** Ruta los gestos de la tarjeta compartida (Tarea 5.1, plan §4.2). */
  function handleTomeGesture(eventType, payload) {
    const spellId = String(payload?.spellId ?? '');
    if (eventType === 'tome:seal') {
      void performSeal(spellId, payload?.slug ?? '');
      return;
    }
    if (eventType === 'tome:praise') {
      void performPraise(spellId, payload?.slug ?? '');
      return;
    }
    if (eventType === 'tome:discard') {
      const entry = (currentState?.entries ?? []).find((e) => e?.spell?.id === spellId);
      discardModal?.open({
        spellId,
        spellName: String(entry?.spell?.name ?? spellId),
        originElement: payload?.originElement ?? null,
      });
    }
  }

  /** Sellado directo (la obra ya vive en el tomo: eco solemne). */
  async function performSeal(spellId, spellSlug) {
    const result = await collectionClient.collectSpell(spellId);
    if (result?.success !== true) {
      announce(ceremonialLegendFor(result));
      emit(GRIMOIRE_COLLECTION_VIEW_EVENTS.sealFailed, { spellId, code: result?.error?.code ?? 'UNKNOWN' });
      return;
    }
    announce(`«${spellSlug || spellId}» queda sellado en tu tomo.`);
  }

  /** Elogio directo: los estados solemnes se narran, jamás gritan. */
  async function performPraise(spellId, spellSlug) {
    const result = await collectionClient.praiseSpell(spellId);
    if (result?.success !== true) {
      announce(ceremonialLegendFor(result));
      emit(GRIMOIRE_COLLECTION_VIEW_EVENTS.praiseFailed, { spellId, code: result?.error?.code ?? 'UNKNOWN' });
      return;
    }
    if (result.data?.praised === true) {
      announce(`Tu homenaje a «${spellSlug || spellId}» ya resuena en su casa.`);
    } else {
      announce('Un adepto de la casa no granjea gloria para su propio estandarte.');
    }
  }

  /**
   * Retirada consumada (RF-02.4, caso límite 10): el total de la
   * respuesta actualiza el rótulo y la paginación viva decide destino —
   * la página corriente si sigue viva, la última viva si quedó vaciada —
   * siempre conservando el filtro activo.
   */
  async function performDiscard(spellId) {
    const result = await collectionClient.discardSpell(spellId, currentElement !== '' ? currentElement : null);
    if (result?.success !== true) {
      announce(ceremonialLegendFor(result));
      return;
    }

    const total = Number(result.data?.total ?? 0);
    emit(GRIMOIRE_COLLECTION_VIEW_EVENTS.collectionChanged, { spellId, total });
    announce('La obra ha dejado tu tomo.');

    // PAGINACIÓN VIVA (plan §3.5): reanudar en la página válida más
    // cercana bajo el filtro vigente, jamás una pantalla fantasma.
    const totalPages = Math.max(1, Math.ceil(total / PAGE_LIMIT));
    const destinationPage = Math.min(Math.max(1, currentPage), totalPages);
    currentPage = destinationPage;
    await load();
  }

  // -------------------------------------------------------------------
  // Pintura
  // -------------------------------------------------------------------

  /** Rótulo «N entradas · página X de Y» (el conteo viaja en el sobre). */
  function renderCountLabel() {
    if (countLabel === null || currentState === null) return;
    const total = Number(currentState.total ?? 0);
    const page = Number(currentState.page ?? 1);
    const totalPages = Number(currentState.totalPages ?? 1);
    countLabel.textContent = `${total} ${total === 1 ? 'entrada' : 'entradas'} · página ${page} de ${totalPages}`;
  }

  /** Paginación viva: hoja anterior / siguiente con candados de borde. */
  function renderPagination() {
    if (paginationHost === null || currentState === null) return;
    paginationHost.replaceChildren();

    const total = Number(currentState.total ?? 0);
    const totalPages = Math.max(1, Math.ceil(total / PAGE_LIMIT));
    if (totalPages <= 1) return;

    const previousButton = elementFactory('button');
    previousButton.type = 'button';
    previousButton.className = 'collection-view__page collection-view__page--previous';
    previousButton.textContent = 'Página anterior';
    previousButton.disabled = currentPage <= 1;
    previousButton.addEventListener('click', () => {
      void setPage(Math.max(1, currentPage - 1));
    });
    paginationHost.appendChild(previousButton);

    const nextButton = elementFactory('button');
    nextButton.type = 'button';
    nextButton.className = 'collection-view__page collection-view__page--next';
    nextButton.textContent = 'Página siguiente';
    nextButton.disabled = currentPage >= totalPages;
    nextButton.addEventListener('click', () => {
      void setPage(Math.min(totalPages, currentPage + 1));
    });
    paginationHost.appendChild(nextButton);
  }

  /** Estado vacío (RF-02.2): invitación, jamás lenguaje de error. */
  function renderEmptyState() {
    const emptyBox = elementFactory('div');
    emptyBox.className = 'collection-view__empty';

    const legend = elementFactory('p');
    legend.className = 'collection-view__empty-legend';
    legend.textContent = GRIMOIRE_COLLECTION_EMPTY_LEGEND;
    emptyBox.appendChild(legend);

    const cta = elementFactory('button');
    cta.type = 'button';
    cta.className = 'collection-view__empty-cta';
    cta.textContent = GRIMOIRE_COLLECTION_EMPTY_CTA_LABEL;
    cta.addEventListener('click', () => {
      if (typeof onNavigateToLibrary === 'function') {
        onNavigateToLibrary(GRIMOIRE_COLLECTION_EMPTY_CTA_HASH);
      }
    });
    emptyBox.appendChild(cta);

    entriesHost.appendChild(emptyBox);
  }

  /**
   * Pinta las entradas del sobre vivo con la tarjeta compartida (Tarea
   * 5.1): cada entrada viaja como DTO de tarjeta + `tomeMark` + gestos
   * de retirada por entrada.
   */
  function renderEntries() {
    if (entriesHost === null || currentState === null) return;
    entriesHost.replaceChildren();

    const entries = Array.isArray(currentState.entries) ? currentState.entries : [];

    if (entries.length === 0) {
      renderEmptyState();
      return;
    }

    for (const entry of entries) {
      const spellDto = entry?.spell ?? {};
      const entryMark = String(entry?.tomeMark ?? 'living');
      const card = createSpellCardComponent(
        {
          ...spellDto,
          // El gesto compartido se alimenta del estado embebido del DTO;
          // en el tomo, la retirada se ofrece por entrada (RF-02.4).
          adeptState: {
            collected: true,
            praised: entry?.praiseStatus?.praised === true,
            praiseAllowed: entry?.praiseStatus?.allowed === true,
          },
        },
        {
          // CONVOCATORIA DESDE EL TOMO (RF-03.1): SOLO una entrada viva
          // (tomeMark 'living') enruta hacia el Simulador — la marca del
          // DTO manda (RF-03.2, casos límite 2 y 7).
          onSpellSelect: (slug) => {
            if (entryMark !== LIVING_TOME_MARK) {
              announce(SUMMON_VETO_LEGENDS[entryMark]
                ?? 'Esta obra no admite convocatoria en su estado actual.');
              return;
            }
            options.onSummonSpell?.(String(slug ?? ''), {
              spellId: String(spellDto.id ?? ''),
              originElement: null,
            });
          },
          onTomeGesture: (eventType, payload) => {
            // En el tomo, el gesto de colección ya está consumado: el
            // botón de retirada por entrada es la única mutación propia.
            if (eventType === 'tome:praise') {
              handleTomeGesture(eventType, payload);
              return;
            }
            openDiscardModal(String(payload?.spellId ?? ''), payload?.originElement ?? null);
          },
          elementFactory,
        },
      );
      card.setAttribute('data-tome-mark', String(entry?.tomeMark ?? 'living'));
      card.setAttribute('data-added-at', String(entry?.addedAt ?? ''));

      // La marca solemne viaja ROTULADA (leída del DTO; jamás traducida aquí).
      const markLabel = elementFactory('p');
      markLabel.className = `spell-card__tome-mark spell-card__tome-mark--${String(entry?.tomeMark ?? 'living')}`;
      markLabel.textContent = TOME_MARK_LABELS[String(entry?.tomeMark ?? 'living')] ?? '';
      card.appendChild(markLabel);

      // Gesto propio del tomo: la retirada solemne (RF-02.4).
      const discardButton = elementFactory('button');
      discardButton.type = 'button';
      discardButton.className = 'spell-card__discard';
      discardButton.textContent = 'Retirar del tomo';
      const spellId = String(spellDto.id ?? '');
      discardButton.addEventListener('click', () => openDiscardModal(spellId, discardButton));
      card.appendChild(discardButton);

      entriesHost.appendChild(card);
    }
  }

  /** Despliega el modal solemne de retirada (Tarea 5.2). */
  function openDiscardModal(spellId, originElement) {
    if (spellId === '') return;
    const entry = (currentState?.entries ?? []).find((e) => e?.spell?.id === spellId);
    discardModal?.open({
      spellId,
      spellName: String(entry?.spell?.name ?? spellId),
      originElement: originElement ?? null,
    });
  }

  // -------------------------------------------------------------------
  // Carga
  // -------------------------------------------------------------------

  /** Una carga del sobre paginado (RF-02.1, RNF-01). */
  async function load() {
    if (typeof collectionClient?.fetchCollection !== 'function') return;

    const currentSequence = ++fetchSequence;
    const result = await collectionClient.fetchCollection(
      currentElement !== '' ? currentElement : null,
      currentPage,
    );

    if (currentSequence !== fetchSequence || isDestroyed) return;

    if (result?.success !== true) {
      if (result?.status === 401) {
        // DEGRADACIÓN SOLEMNE (RF-05.2, hallazgo 7): aviso del umbral,
        // gestos apagados y NADA vaciado — lo leído sigue a la vista.
        degradeSession();
        emit(GRIMOIRE_COLLECTION_VIEW_EVENTS.catalogFailed, { code: result?.error?.code ?? 'UNAUTHENTICATED' });
        return;
      }
      // Fallo de corriente: aviso solemne sin desmontar nada.
      announce(ceremonialLegendFor(result));
      emit(GRIMOIRE_COLLECTION_VIEW_EVENTS.catalogFailed, { code: result?.error?.code ?? 'UNKNOWN' });
      return;
    }

    sessionExpiredBox?.setAttribute('hidden', '');
    currentState = result.data ?? {};
    renderEntries();
    renderCountLabel();
    renderPagination();
    emit(GRIMOIRE_COLLECTION_VIEW_EVENTS.catalogLoaded, { state: currentState });
  }

  /** Cambio de hoja (paginación viva, caso límite 4). */
  async function setPage(page) {
    currentPage = Math.max(1, Number(page) || 1);
    await load();
  }

  /** Cambio de filtro por afinidad (RF-02.3): vuelve a la hoja 1. */
  async function setFilter(element) {
    currentElement = String(element ?? '');
    currentPage = 1;
    await load();
  }

  /** Reintento tras fallo (la vista permanece operativa). */
  async function retry() {
    fetchSequence += 1;
    await load();
  }

  // -------------------------------------------------------------------
  // Esqueleto
  // -------------------------------------------------------------------

  /** Monta el esqueleto: encabezado, filtro, host de entradas, paginación. */
  function buildSkeleton() {
    viewRoot = track(elementFactory('section'));
    viewRoot.className = 'collection-view grimoire-tomo-container';

    const heading = elementFactory('h2');
    heading.className = 'collection-view__title';
    heading.textContent = GRIMOIRE_COLLECTION_VIEW_TITLE;
    viewRoot.appendChild(heading);

    // Región viva (RNF-03).
    liveRegion = elementFactory('p');
    liveRegion.className = 'collection-view__live';
    liveRegion.setAttribute('role', 'status');
    liveRegion.setAttribute('aria-live', 'polite');
    viewRoot.appendChild(liveRegion);

    // Aviso solemne de la sesión expirada (RF-05.2): oculto por defecto.
    sessionExpiredBox = elementFactory('div');
    sessionExpiredBox.className = 'collection-view__session-expired';
    sessionExpiredBox.setAttribute('role', 'alert');
    sessionExpiredBox.setAttribute('hidden', '');
    const expiredLegend = elementFactory('p');
    expiredLegend.className = 'collection-view__expired-legend';
    expiredLegend.textContent = GRIMOIRE_COLLECTION_EXPIRED_LEGEND;
    sessionExpiredBox.appendChild(expiredLegend);

    accessButton = elementFactory('button');
    accessButton.type = 'button';
    accessButton.className = 'collection-view__access-btn button button--primary';
    accessButton.textContent = 'Cruzar el Umbral';
    accessButton.setAttribute('aria-label', 'Cruzar el Umbral: renovar vínculo o consagrarse');
    accessButton.addEventListener('click', () => {
      if (typeof onReservedAction === 'function') {
        onReservedAction('openGrimoire');
      } else {
        const thresholdBtn = documentRef?.getElementById?.('navCrossThreshold');
        thresholdBtn?.click?.();
      }
    });
    sessionExpiredBox.appendChild(accessButton);

    viewRoot.appendChild(sessionExpiredBox);

    // Filtro por afinidad con conteo (RF-02.3).
    const filterBar = elementFactory('div');
    filterBar.className = 'collection-view__filter-bar';
    filterSelect = elementFactory('select');
    filterSelect.className = 'collection-view__filter';
    filterSelect.setAttribute('aria-label', 'Filtrar el tomo por afinidad elemental');
    for (const option of COLLECTION_FILTER_ELEMENTS) {
      const optionNode = elementFactory('option');
      optionNode.value = option.value;
      optionNode.textContent = option.label;
      filterSelect.appendChild(optionNode);
    }
    filterSelect.addEventListener('change', () => {
      void setFilter(filterSelect.value);
    });
    filterBar.appendChild(filterSelect);

    countLabel = elementFactory('p');
    countLabel.className = 'collection-view__count';
    countLabel.textContent = '0 entradas';
    filterBar.appendChild(countLabel);
    viewRoot.appendChild(filterBar);

    // Anfitrión de entradas y paginación.
    entriesHost = elementFactory('div');
    entriesHost.className = 'collection-view__entries';
    viewRoot.appendChild(entriesHost);

    paginationHost = elementFactory('nav');
    paginationHost.className = 'collection-view__pagination';
    viewRoot.appendChild(paginationHost);

    // Diálogo nativo del modal solemne de retirada (Tarea 5.2).
    const dialogElement = elementFactory('dialog');
    dialogElement.className = 'discard-tome-modal';
    viewRoot.appendChild(dialogElement);
    discardModal = createDiscardTomeEntryModalComponent(dialogElement, {
      onDiscard: (spellId) => {
        void performDiscard(spellId);
      },
      documentRef,
      windowRef: globalThis.window,
    });

    mountRoot.appendChild(viewRoot);
  }

  /** Monta la vista y carga el sobre. Idempotente. */
  async function render() {
    if (isDestroyed) return;
    destroy(false);
    buildSkeleton();
    await load();
  }

  /** Retira la vista del punto de montaje y libera sus nodos. */
  function destroy(removeFromMount = true) {
    fetchSequence += 1;

    discardModal?.destroy?.();
    discardModal = null;

    for (const node of mountedNodes.splice(0)) {
      node.remove?.();
    }
    viewRoot = null;
    entriesHost = null;
    countLabel = null;
    filterSelect = null;
    paginationHost = null;
    emptyStateBox = null;
    sessionExpiredBox = null;
    liveRegion = null;
    currentState = null;

    if (removeFromMount) {
      for (const child of [...(mountRoot.children ?? [])]) {
        if (String(child.className ?? '').includes('collection-view')) {
          child.remove?.();
        }
      }
      isDestroyed = true;
    }
  }

  return { render, destroy, retry, setFilter, setPage };
}
