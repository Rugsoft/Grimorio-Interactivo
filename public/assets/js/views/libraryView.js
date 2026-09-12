/**
 * libraryView.js — Vista de Biblioteca con Buscador en Vivo y Filtros (Tarea 5.2).
 *
 * RF-03.3: barra de búsqueda reactiva — insensible a tildes/mayúsculas en el
 *          cliente (textNormalizer, Tarea 3.1) y con umbral de 2 caracteres
 *          (plan 5.1); la normalización definitiva la hace el backend con su
 *          UDF `norm` (Tarea 1.4).
 * RF-03.5: checkboxes de las 8 Escuelas canónicas del semillero operando bajo
 *          UNIÓN (OR): marcar varias amplía el resultado.
 * RF-03.6: control deslizante de tope de maná (<=) con etiqueta en vivo.
 * RNF-02:  las interacciones actualizan el listado visible en < 150 ms sin
 *          refrescar la página (debounce de 250 ms + render en memoria).
 *
 * Arquitectura (plan 4.1): los filtros viven en `store.activeFilters`
 * (fuente única de verdad); la vista los refleja y notifica al orquestador.
 *
 * Constitución:
 *   - Artículo I: ES Modules nativos; input[type=range] y checkboxes estándar.
 *   - Artículo IV: etiquetas solemnes en castellano.
 *   - Artículo V: identificadores en inglés camelCase; comentarios castellanos.
 *
 * Seguridad (AGENTS.md 6.1): jamás innerHTML; la query hostil viaja como
 * DATO (textContent + parámetros), nunca como markup ejecutable.
 */

import { createSpellCardComponent } from '../components/spellCardComponent.js';
import { normalizeSearchText } from '../utils/textNormalizer.js';

/** Latencia del debounce de la búsqueda (plan: reactiva pero contenida). */
const SEARCH_DEBOUNCE_MS = 250;

/** Umbral de caracteres para que la búsqueda filtre (plan 5.1). */
const SEARCH_MIN_LENGTH = 2;

/** Tope superior del deslizador de maná (balance del creador, SPEC-04). */
const MAX_MANA_CEILING = 100;

/** Las 8 Escuelas de Magia canónicas (contrato con database/seeds.sql). */
const MAGIC_SCHOOLS = Object.freeze([
  { slug: 'abjuration', label: 'Abjuración' },
  { slug: 'conjuration', label: 'Conjuración' },
  { slug: 'divination', label: 'Adivinación' },
  { slug: 'enchantment', label: 'Encantamiento' },
  { slug: 'evocation', label: 'Evocación' },
  { slug: 'illusion', label: 'Ilusión' },
  { slug: 'necromancy', label: 'Nigromancia' },
  { slug: 'transmutation', label: 'Transmutación' },
]);

/**
 * Crea la vista de biblioteca.
 *
 * @param {HTMLElement} mountRoot Punto de montaje (`<main id="app">`).
 * @param {Object} options
 * @param {Object} options.store Almacén reactivo (Tarea 3.2).
 * @param {Object} options.spellClient Cliente HTTP (Tarea 3.3; necesita fetchSpells).
 * @param {(slug: string) => void} options.onSpellSelect Notifica la selección de tarjeta.
 * @param {(tagName: string) => HTMLElement} [options.elementFactory] Fábrica inyectable (tests).
 * @returns {Object} API: { render, destroy, retry, setSpellClient }.
 */
export function createLibraryView(mountRoot, options) {
  const {
    store,
    spellClient,
    onSpellSelect,
    elementFactory = (tagName) => document.createElement(tagName),
  } = options;

  /** Cliente HTTP vivo (conmutable en reintentos tras fallo). */
  let activeSpellClient = spellClient;

  /** Nodos vivos de la vista, para limpieza determinista en destroy(). */
  const mountedNodes = [];

  /** Temporizador del debounce de búsqueda. */
  let searchDebounceHandle = null;

  /** Guardia anti-carreras: solo la última petición pinta el catálogo. */
  let fetchSequence = 0;

  /** Referencias a controles vivos. */
  let searchInput = null;
  let manaRange = null;
  let manaValueLabel = null;
  let loadMoreSlot = null; // Hueco al pie de la rejilla (RF-03.7, Tarea 2.3).

  /** Registra un nodo como hijo de la vista para su ciclo de vida. */
  function track(node) {
    mountedNodes.push(node);
    return node;
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
   * Deriva el parámetro `schools` (CSV) a partir del estado del store.
   * La unión OR la resuelve el backend recibiendo todas las escuelas marcadas.
   */
  function buildSchoolsParam() {
    return store.getState().activeFilters.schools.join(',');
  }

  /**
   * Consulta una tanda del catálogo (contrato del plan 3) y la pinta según
   * el modo indicado. Guardia anti-carreras: si llegó una consulta más
   * nueva, la respuesta vieja se descarta sin renderizar.
   *
   * @param {'replace'|'append'} renderMode `replace` para consultas nuevas
   *        (búsqueda/filtros) o `append` para la carga incremental (RF-03.7).
   */
  async function fetchAndRenderCatalog(renderMode = 'replace') {
    const state = store.getState();
    const filters = state.activeFilters;
    const currentSequence = ++fetchSequence;

    const result = await activeSpellClient.fetchSpells({
      query: filters.query,
      schools: buildSchoolsParam(),
      maxMana: filters.maxMana,
      includeExperimental: filters.includeExperimental ? 1 : 0,
      offset: state.pagination.offset,
      limit: state.pagination.limit,
    });

    // Una respuesta tardía de una consulta antigua no pinta (RNF-02/RNF-05).
    if (currentSequence !== fetchSequence) return;

    // La vista puede haber sido destruida mientras la petición volaba.
    if (!mountedNodes.includes(viewRoot)) return;

    if (!result.success) {
      renderErrorState(result.error);
      if (renderMode === 'append') {
        store.setState({
          pagination: { ...state.pagination, isLoading: false },
        });
      }
      return;
    }

    clearErrorState();
    renderCatalogCards(result.data.items, renderMode);
    store.setState({
      pagination: { ...state.pagination, hasMore: result.data.hasMore },
    });
    syncLoadMoreSlot(result.data.items.length);
  }

  /** Elimina el bloque de error, si existe. */
  function clearErrorState() {
    const existingError = viewRoot ? byClass(viewRoot, 'library-view__error') : null;
    existingError?.remove?.();
  }

  /** Monta el estado de error temático con botón de reintento (RF-06.3). */
  function renderErrorState(errorEnvelope) {
    clearErrorState();
    const errorBlock = track(elementFactory('div'));
    errorBlock.className = 'library-view__error';
    errorBlock.setAttribute('role', 'alert');

    appendTextElement(
      errorBlock,
      'p',
      'library-view__error-message',
      errorEnvelope?.message ?? 'La corriente de maná se ha interrumpido: el catálogo no responde.',
    );

    const retryButton = track(elementFactory('button'));
    retryButton.type = 'button';
    retryButton.className = 'library-view__error-retry button button--secondary';
    retryButton.textContent = 'Reintentar invocación';
    retryButton.addEventListener('click', () => retry());
    errorBlock.appendChild(retryButton);

    catalogGrid?.appendChild(errorBlock);
  }

  /** Repuebla la rejilla con las tarjetas del catálogo recibido. */
  function renderCatalogCards(items, renderMode = 'replace') {
    if (renderMode === 'replace') {
      catalogGrid.replaceChildren?.();
      if (!catalogGrid.replaceChildren) {
        // Compatibilidad con el DOM simulado: vaciado manual.
        for (const child of [...catalogGrid.children]) child.remove();
      }
    }
    // En modo `append` (RF-03.7) las tarjetas previas NO se tocan: se
    // concatena al pie (sin salto de scroll, criterio de la Tarea 5.3).

    for (const spellSummaryDto of items) {
      const card = createSpellCardComponent(spellSummaryDto, {
        onSpellSelect: (slug, originElement) => onSpellSelect?.(slug, originElement),
        elementFactory,
      });
      catalogGrid.appendChild(card);
      track(card);
    }

    if (items.length === 0 && renderMode === 'replace') {
      appendTextElement(
        catalogGrid,
        'p',
        'library-view__empty',
        'Ningún conjuro responde a esas runas: afloja los filtros o forja tú el hechizo.',
      );
    }
  }

  /**
   * Sincroniza el hueco al pie de la rejilla (RF-03.7): botón
   * «Desenrollar más pergaminos» mientras haya más tandas; retirado al
   * agotar el catálogo (criterio: ocultar el botón al llegar al final).
   *
   * @param {number} lastBatchSize Tamaño de la última tanda recibida.
   */
  function syncLoadMoreSlot(lastBatchSize) {
    if (!loadMoreSlot) return;
    const hasMore = store.getState().pagination.hasMore;

    // El botón muere con su estado: sin tanda pendiente, sin cargando.
    // querySelector es el contrato del DOM real; el fallback children/classes
    // solo existe para el DOM simulado de los arneses.
    const existingButton = loadMoreSlot.querySelector?.('.library-view__load-more')
      ?? loadMoreSlot.children.find?.(
        (child) => child.classes?.has('library-view__load-more'),
      ) ?? null;
    existingButton?.remove?.();

    // La nota de final es única: se retira la previa antes de valorar el estado.
    loadMoreSlot.querySelectorAll?.('.library-view__end-note')
      .forEach?.((noteNode) => noteNode.remove?.());

    if (!hasMore) {
      if (lastBatchSize > 0) {
        appendTextElement(
          loadMoreSlot,
          'p',
          'library-view__end-note',
          'Has llegado al final del grimorio.',
        );
      }
      return;
    }

    const loadMoreButton = track(elementFactory('button'));
    loadMoreButton.type = 'button';
    loadMoreButton.className = 'library-view__load-more button button--secondary';
    loadMoreButton.textContent = 'Desenrollar más pergaminos';
    loadMoreButton.addEventListener('click', () => loadMoreSpells());
    loadMoreSlot.appendChild(loadMoreButton);
  }

  /**
   * Carga incremental arcana (plan 5.2, RF-03.7): avanza el offset del
   * store, consulta la siguiente tanda y la CONCATENA a la rejilla sin
   * alterar la posición de scroll. Guardas: sin tandas pendientes ni
   * carga en vuelo.
   */
  async function loadMoreSpells() {
    const pagination = store.getState().pagination;
    if (pagination.isLoading || !pagination.hasMore) {
      return; // Guarda del plan 5.2: nada que desenrollar.
    }

    store.setState({
      pagination: { ...pagination, isLoading: true },
    });

    const nextOffset = pagination.offset + pagination.limit;
    store.setState({
      pagination: { ...store.getState().pagination, offset: nextOffset },
    });

    await fetchAndRenderCatalog('append');

    const finalPagination = store.getState().pagination;
    if (finalPagination.isLoading) {
      store.setState({
        pagination: { ...finalPagination, isLoading: false },
      });
    }
  }

  /**
   * Manejador del buscador (RF-03.3): debounce 250 ms y notificación al
   * backend. El filtrado LOCAL (< 150 ms percibidos) usa el normalizador de
   * la Tarea 3.1 sobre el catálogo ya cargado mientras la red viaja.
   */
  function handleSearchInput() {
    const rawQuery = searchInput.value ?? '';
    store.setState({
      activeFilters: {
        ...store.getState().activeFilters,
        query: rawQuery,
      },
    });

    clearTimeout(searchDebounceHandle);
    // Refresco LOCAL inmediato sobre lo ya cargado (percepción < 150 ms):
    applyLocalFilterPreview();
    // Cualquier consulta NUEVA reinicia la paginación (plan 3: los filtros
    // son acumulativos sobre la primera tanda).
    resetPaginationForNewQuery();

    searchDebounceHandle = setTimeout(() => {
      fetchAndRenderCatalog();
    }, SEARCH_DEBOUNCE_MS);
  }

  /**
   * Reinicia la paginación a la primera tanda (plan 4.1/3): una consulta
   * nueva por búsqueda o filtro parte de offset 0 y reaparece el botón de
   * desenrollado si el catálogo filtrado tiene más resultados.
   */
  function resetPaginationForNewQuery() {
    const pagination = store.getState().pagination;
    if (pagination.offset !== 0 || !pagination.hasMore || pagination.isLoading) {
      store.setState({
        pagination: { ...pagination, offset: 0, hasMore: true, isLoading: false },
      });
      syncLoadMoreSlot(0);
    }
  }

  /**
   * Filtrado local provisional del catálogo ya renderizado (plan 5.1):
   * normaliza con la MISMA disciplina que el backend para anticipar el
   * resultado mientras la consulta viaja. Con < 2 caracteres no filtra.
   */
  function applyLocalFilterPreview() {
    const normalizedQuery = normalizeSearchText(store.getState().activeFilters.query);
    if (normalizedQuery.length < SEARCH_MIN_LENGTH) return;

    const cards = viewRoot ? allByClass(viewRoot, 'spell-card') : [];
    for (const card of cards) {
      const haystack = normalizeSearchText(
        `${card.getAttribute('data-name') ?? ''} ${card.getAttribute('data-summary') ?? ''}`,
      );
      card.setAttribute('data-preview-hidden', haystack.includes(normalizedQuery) ? 'false' : 'true');
    }
  }

  /**
   * Manejador de un checkbox de Escuela (RF-03.5, unión OR): actualiza el
   * Set-like del store y refresca con debounce corto (los clicks son rápidos).
   */
  function handleSchoolToggle(schoolEvent) {
    const schoolSlug = schoolEvent.currentTarget.getAttribute('data-school');
    const isChecked = schoolEvent.currentTarget.checked;
    const previousSchools = store.getState().activeFilters.schools;

    const nextSchools = isChecked
      ? [...previousSchools, schoolSlug]
      : previousSchools.filter((existingSlug) => existingSlug !== schoolSlug);

    store.setState({
      activeFilters: {
        ...store.getState().activeFilters,
        schools: nextSchools,
      },
    });

    clearTimeout(searchDebounceHandle);
    resetPaginationForNewQuery();
    searchDebounceHandle = setTimeout(() => {
      fetchAndRenderCatalog();
    }, SEARCH_DEBOUNCE_MS);
  }

  /**
   * Manejador de la pestaña de Archivos Experimentales (RF-03.2, Art. III):
   * conmuta la bandera includeExperimental del store y refresca. Por defecto
   * (false) el backend SEGREGA los no validados del catálogo público.
   */
  function handleExperimentalToggle(toggleEvent) {
    const includeExperimental = toggleEvent.currentTarget.checked === true;

    store.setState({
      activeFilters: {
        ...store.getState().activeFilters,
        includeExperimental,
      },
    });

    clearTimeout(searchDebounceHandle);
    resetPaginationForNewQuery();
    searchDebounceHandle = setTimeout(() => {
      fetchAndRenderCatalog();
    }, SEARCH_DEBOUNCE_MS);
  }

  /**
   * Manejador del deslizador de maná (RF-03.6): etiqueta en vivo + filtro <=.
   */
  function handleManaInput() {
    const rawMaxMana = Number(manaRange.value);
    manaValueLabel.textContent = `Tope de maná: ${rawMaxMana}`;

    store.setState({
      activeFilters: {
        ...store.getState().activeFilters,
        maxMana: rawMaxMana,
      },
    });

    clearTimeout(searchDebounceHandle);
    resetPaginationForNewQuery();
    searchDebounceHandle = setTimeout(() => {
      fetchAndRenderCatalog();
    }, SEARCH_DEBOUNCE_MS);
  }

  /**
   * Reintenta la última consulta tras un fallo de red (RF-06.3).
   * Permite además inyectar un cliente recuperado (tests / orquestador).
   */
  function setSpellClient(nextSpellClient) {
    activeSpellClient = nextSpellClient;
  }

  async function retry() {
    fetchSequence++; // invalida respuestas en vuelo del cliente fallido.
    await fetchAndRenderCatalog();
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
  function allByClass(root, className) {
    if (typeof root.querySelectorAll === 'function') {
      return [...root.querySelectorAll('.' + className)];
    }
    const found = [];
    (function walk(node) {
      for (const child of node.children ?? []) {
        if (child.classes?.has(className)) found.push(child);
        walk(child);
      }
    })(root);
    return found;
  }

  /** Referencias de la vista. */
  let viewRoot = null;
  let catalogGrid = null;

  /** Construye la sección de filtros (RF-03.5, RF-03.6). */
  function buildFiltersSection() {
    const filters = track(elementFactory('section'));
    filters.className = 'library-filters';
    filters.setAttribute('aria-label', 'Filtros del catálogo');

    appendTextElement(filters, 'h2', 'library-filters__title', 'Filtrar el Arcano');

    // Checkbox por cada Escuela canónica (unión OR, RF-03.5):
    for (const school of MAGIC_SCHOOLS) {
      const label = elementFactory('label');
      label.className = 'library-filter__school-label';

      const checkbox = elementFactory('input');
      checkbox.setAttribute('type', 'checkbox');
      checkbox.className = 'library-filter__school';
      checkbox.setAttribute('data-school', school.slug);
      checkbox.checked = false;
      checkbox.addEventListener('change', handleSchoolToggle);
      track(checkbox);

      label.appendChild(checkbox);
      label.appendChild((() => {
        const textNode = elementFactory('span');
        textNode.className = 'library-filter__school-name';
        textNode.textContent = school.label;
        return textNode;
      })());

      filters.appendChild(label);
      track(label);
    }

    // Pestaña de Archivos Experimentales (RF-03.2, Art. III): bandera
    // explícita del visitante; sin ella, el backend segrega los no validados.
    const experimentalLabel = elementFactory('label');
    experimentalLabel.className = 'library-filter__experimental-label';

    const experimentalCheckbox = elementFactory('input');
    experimentalCheckbox.setAttribute('type', 'checkbox');
    experimentalCheckbox.className = 'library-filter__experimental-checkbox';
    experimentalCheckbox.checked = false;
    experimentalCheckbox.addEventListener('change', handleExperimentalToggle);
    track(experimentalCheckbox);

    const experimentalText = elementFactory('span');
    experimentalText.className = 'library-filter__experimental';
    experimentalText.textContent = 'Archivos Experimentales';

    experimentalLabel.appendChild(experimentalCheckbox);
    experimentalLabel.appendChild(experimentalText);
    filters.appendChild(experimentalLabel);
    track(experimentalLabel);

    // Deslizador de tope de maná (RF-03.6):
    const manaLabel = elementFactory('label');
    manaLabel.className = 'library-filter__mana-label';
    manaLabel.setAttribute('for', 'libraryMaxMana');

    manaRange = elementFactory('input');
    manaRange.id = 'libraryMaxMana';
    manaRange.setAttribute('type', 'range');
    manaRange.className = 'library-filter__max-mana';
    manaRange.setAttribute('min', '1');
    manaRange.setAttribute('max', String(MAX_MANA_CEILING));
    manaRange.value = String(MAX_MANA_CEILING);
    manaRange.addEventListener('input', handleManaInput);
    track(manaRange);

    manaValueLabel = elementFactory('span');
    manaValueLabel.className = 'library-filter__max-mana-value';
    manaValueLabel.textContent = `Tope de maná: ${MAX_MANA_CEILING}`;

    manaLabel.appendChild(manaRange);
    manaLabel.appendChild(manaValueLabel);
    filters.appendChild(manaLabel);
    track(manaLabel);

    return filters;
  }

  /**
   * Monta la vista completa. Idempotente: limpia el montaje previo y
   * reinicia los filtros del store a su estado inicial del plan 4.1.
   */
  async function render() {
    destroy(false);

    viewRoot = track(elementFactory('section'));
    // El tomo central acota la biblioteca a 1280 px (Tarea 2.1, RF-05.1).
    viewRoot.className = 'library-view grimoire-tomo-container';
    mountRoot.appendChild(viewRoot);

    // La biblioteca pasa a ser la vista activa del store (plan 4.1).
    store.setState({
      currentView: 'library',
      activeFilters: { query: '', schools: [], maxMana: null, includeExperimental: false },
      pagination: { offset: 0, limit: 50, hasMore: true, isLoading: false },
    });

    // Título de rescate (RF-04.3, plan 5.3): destino del foco cuando la
    // tarjeta de origen desapareció del DOM al cerrarse la ficha.
    const libraryTitle = appendTextElement(viewRoot, 'h1', 'library-view__title', 'Biblioteca de Conjuros');
    libraryTitle.setAttribute('id', 'libraryHeaderTitle');

    searchInput = elementFactory('input');
    searchInput.className = 'library-view__search';
    searchInput.setAttribute('type', 'search');
    searchInput.setAttribute('placeholder', 'Invoca por nombre o esencia del conjuro…');
    searchInput.setAttribute('aria-label', 'Buscar hechizos');
    searchInput.setAttribute('maxlength', '100');
    searchInput.addEventListener('input', handleSearchInput);
    track(searchInput);
    viewRoot.appendChild(searchInput);

    viewRoot.appendChild(buildFiltersSection());

    catalogGrid = track(elementFactory('div'));
    catalogGrid.className = 'library-view__grid';
    viewRoot.appendChild(catalogGrid);

    // Hueco al pie de la rejilla para la paginación arcana (RF-03.7;
    // el hueco ya existe como .load-more-slot en layout.css, Tarea 2.3).
    loadMoreSlot = track(elementFactory('div'));
    loadMoreSlot.className = 'load-more-slot';
    viewRoot.appendChild(loadMoreSlot);

    const loading = appendTextElement(catalogGrid, 'p', 'library-view__loading', 'Desenrollando los pergaminos…');
    await fetchAndRenderCatalog();
    loading.remove();
  }

  /**
   * Retira la vista del punto de montaje, cancela temporizadores y libera nodos.
   * @param {boolean} [removeFromRoot=true] El render interno lo invoca con
   *        false para limpiar la renderización previa antes de volver a montar.
   */
  function destroy(removeFromRoot = true) {
    clearTimeout(searchDebounceHandle);
    fetchSequence++; // invalida respuestas en vuelo.
    for (const node of mountedNodes.splice(0)) {
      node.remove?.();
    }
    catalogGrid = null;
    searchInput = null;
    manaRange = null;
    manaValueLabel = null;
    loadMoreSlot = null;
    if (removeFromRoot) {
      for (const child of [...(mountRoot.children ?? [])]) {
        if (String(child.className ?? '').includes('library-view')) {
          child.remove?.();
        }
      }
    }
  }

  return { render, destroy, retry, setSpellClient };
}
