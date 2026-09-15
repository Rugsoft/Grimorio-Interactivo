/**
 * lineageHallComponent.js — Salón de los Linajes: pabellón ceremonial del
 * Dominio (Tarea 6.3, TASKS-07).
 *
 * RF-06.1: exhibe la Clasificación Semanal en Vivo (puesto, estandarte,
 *   linaje, PDA semanales y número de conjuros sellados), el Prestigio
 *   Histórico de todos los tiempos y el Libro Mayor de Campeones Pasados.
 * RF-06.2: permite filtrar por cualquiera de los 8 Linajes Mágicos Canónicos
 *   pulsando su glifo rúnico.
 * RF-04.4: la casa que ciñe la corona se marca con el oro del Dominio.
 * RNF-03: contraste, zona táctil, foco visible, anuncios corteses y respeto
 *   por el movimiento reducido (la vestidura vive en lineage-hall.css).
 *
 * Contrato de datos (Endpoint 11, plan 2.2): `DominionHall` con sus cuatro
 * secciones `weeklyRanking`, `historicalRanking`, `currentRegentClan` y
 * `hallOfFameWeeks`. El catálogo de los 8 linajes llega del Endpoint 10.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM nativo; `innerHTML` PROHIBIDO
 *     (AGENTS.md 6.1) — todo texto viaja por `textContent`.
 *   - Artículo II: el componente JAMÁS calcula PDA, ni ordena podios, ni
 *     dirime desempates. Solo contempla el orden que el Salón proclama.
 *   - Artículo III: el linaje fundacional `cln_primordial` es NEUTRO; se
 *     señala como no competidor en vez de fingirlo contendiente.
 *   - Artículo IV/V: leyendas en noble castellano; identificadores en inglés
 *     camelCase; comentarios en castellano.
 *
 * Degradación elegante (AGENTS.md 8): sin catálogo de linajes los estandartes
 * muestran la clave técnica y no hay filtros; sin contienda, leyenda de
 * vacío; sin corriente de maná, error temático con reintento.
 */

import { ELEMENTAL_MATRIX_ELEMENTS } from '../utils/comboResolver.js';
import { createRuneSeal, RUNE_SEAL_STATES } from './runeSealComponent.js';

/** Título ceremonial del pabellón. */
export const LINEAGE_HALL_TITLE = 'Salón de los Linajes';

/** Preámbulo solemne que acompaña al título. */
export const LINEAGE_HALL_HINT =
  'La gloria que el Dominio inscribe: la contienda de la semana en curso, el prestigio perpetuo de todos los tiempos y el libro de los campeones pasados.';

/** Pestañas ceremoniales del Salón (plan 4.2, RF-06.1). */
export const LINEAGE_HALL_TABS = Object.freeze([
  { id: 'weekly', label: 'Clasificación Semanal en Vivo' },
  { id: 'historical', label: 'Prestigio Histórico Perpetuo' },
  { id: 'hallOfFame', label: 'Libro Mayor de Campeones Pasados' },
]);

/** Claves canónicas de las tres secciones (Dualismo Lingüístico, Art. V). */
export const LINEAGE_HALL_TAB_WEEKLY = 'weekly';
export const LINEAGE_HALL_TAB_HISTORICAL = 'historical';
export const LINEAGE_HALL_TAB_HALL_OF_FAME = 'hallOfFame';

/** Leyendas de los estados del Salón. */
export const LINEAGE_HALL_LOADING_LEGEND = 'Convocando los anales del Dominio…';
export const LINEAGE_HALL_EMPTY_LEGEND = 'El Salón aguarda: aún ningún linaje ha contendido por el Dominio.';
export const LINEAGE_HALL_ERROR_LEGEND =
  'La corriente de maná se ha interrumpido: el Salón de los Linajes no responde.';

/** Etiquetas accesibles de la estructura ceremonial. */
export const LINEAGE_HALL_TABLIST_LABEL = 'Secciones del Salón de los Linajes';
export const LINEAGE_HALL_FILTER_GROUP_LABEL = 'Filtro por Linaje Mágico rector';
export const LINEAGE_HALL_RETRY_LABEL = 'Reintentar invocación';

/** Aviso de que el Libro Mayor es perpetuo e independiente de los PDA vivos. */
export const LINEAGE_HALL_FAME_HINT =
  'El Libro Mayor es perpetuo: inmortaliza a los campeones de todas las semanas concluidas y no se rige por los contadores de la semana en curso.';

/** Aviso para las casas disueltas cuyo estandarte ya no figura en la clasificación. */
export const LINEAGE_HALL_FAME_FILTER_HINT =
  'Al filtrar por linaje solo se contemplan las casas campeonas cuyo estandarte aún figura en la clasificación.';

/** Linaje fundacional NEUTRO: custodia el canon y no compite (Art. III). */
export const LINEAGE_HALL_NEUTRAL_CLAN_ID = 'cln_primordial';

/** Leyenda del linaje neutro (Art. III). */
export const LINEAGE_HALL_NEUTRAL_LEGEND =
  'Linaje neutro: custodia el canon y no compite por el Dominio (Art. III).';

/** Leyenda del clan que ciñe la corona del Dominio. */
export const LINEAGE_HALL_REGENT_LABEL = 'Clan Regente de la semana en curso';

/** Índice de elementos canónicos por clave técnica (espejo del Códice). */
const ELEMENT_BY_ID = Object.freeze(
  Object.fromEntries(ELEMENTAL_MATRIX_ELEMENTS.map((element) => [element.id, element])),
);

/** Etiqueta ordinal castellana: 1º, 2º, 3º… */
function ordinalLabel(position) {
  return `${position}º`;
}

/**
 * Crea el componente del Salón de los Linajes.
 *
 * @param {HTMLElement} mountRoot Contenedor donde se monta el pabellón.
 * @param {Object} [options]
 * @param {Array<object>} [options.lineages] Catálogo de los 8 linajes (Endpoint 10).
 * @param {object|null} [options.hall] Salón del Dominio (Endpoint 11), o null.
 * @param {() => void} [options.onRetry] Reintento desde el error temático.
 * @param {(clanId: string) => void} [options.onClanSelect] Selección de una casa.
 * @param {(tagName: string) => HTMLElement} [options.elementFactory] Fábrica
 *        inyectable (arneses sin navegador).
 * @param {Document} [options.documentRef] Documento anfitrión de los sellos
 *        forjados (arneses sin navegador).
 * @returns {Object} API: { render, setLineages, setHall, setError, setTab,
 *          setLineageFilter, getActiveTab, getActiveLineageFilter, destroy }.
 */
export function createLineageHallComponent(mountRoot, options = {}) {
  const {
    lineages = [],
    hall = null,
    onRetry,
    onClanSelect,
    elementFactory = (tagName) => globalThis.document.createElement(tagName),
    documentRef = globalThis.document,
  } = options;

  /** Nodos vivos del componente, para limpieza determinista. */
  const mountedNodes = [];

  /** Catálogo de linajes por clave canónica (nombre, glifo, elemento). */
  let catalog = new Map(
    (Array.isArray(lineages) ? lineages : []).map((lineage) => [lineage?.id, lineage]),
  );

  /** Salón del Dominio vigente (null mientras no se reciba). */
  let hallData = hall ?? null;

  /** Fase de pintado: 'loading' | 'ready' | 'error'. */
  let phase = hallData === null ? 'loading' : 'ready';

  /** Sobre de error del Salón, cuando la fase es 'error'. */
  let errorEnvelope = null;

  /** Pestaña activa y filtro de linaje activo (null = todos). */
  let activeTab = LINEAGE_HALL_TAB_WEEKLY;
  let activeLineage = null;

  /** El componente quedó destruido: ninguna respuesta tardía pinta nada. */
  let isDestroyed = false;

  /** Referencias vivas del armazón. */
  let hallRoot = null;
  let tabButtons = new Map();
  let filterBar = null;
  let filterButtons = new Map();
  let announcement = null;
  let panel = null;

  /** Registra un nodo como hijo del componente para su ciclo de vida. */
  function track(node) {
    mountedNodes.push(node);
    return node;
  }

  /** Añade un nodo de texto seguro (nunca innerHTML, AGENTS.md 6.1). */
  function appendTextElement(parent, tagName, className, text) {
    const node = elementFactory(tagName);
    node.className = className;
    node.textContent = text;
    parent.appendChild(node);
    return track(node);
  }

  /** Vacía los hijos de un nodo (real o simulado). */
  function clearChildren(node) {
    if (node === null || node === undefined) return;
    if (typeof node.replaceChildren === 'function') {
      node.replaceChildren();
      return;
    }
    for (const child of [...(node.children ?? [])]) {
      child.remove?.();
    }
  }

  /** Anuncia un cambio al lector de pantalla (aria-live cortés). */
  function announce(text) {
    if (announcement !== null) {
      announcement.textContent = text;
    }
  }

  /** ¿El linaje del catálogo, o la clave técnica como degradación? */
  function describeLineage(lineageType) {
    const lineage = catalog.get(lineageType) ?? null;
    const element = lineage ? ELEMENT_BY_ID[lineage.rulingElement] ?? null : null;

    return {
      ceremonialName: lineage?.name ?? (lineageType === '' ? 'Linaje no declarado' : lineageType),
      rulingElement: lineage?.rulingElement ?? null,
      elementName: element?.name ?? null,
      glyph: lineage?.glyph ?? null,
      bannerColor: lineage?.bannerColor ?? null,
      heraldicFrame: lineage?.heraldicFrame ?? null,
    };
  }

  /** Leyenda completa del linaje rector de una casa. */
  function describeClanLineage(clanDto) {
    const lineage = describeLineage(String(clanDto?.lineageType ?? ''));
    return lineage.elementName === null
      ? lineage.ceremonialName
      : `${lineage.ceremonialName} · elemento rector: ${lineage.elementName}`;
  }

  /**
   * Índice de linaje por casa, forjado con las dos clasificaciones y el
   * regente vigente. Solo sirve para filtrar el Libro Mayor: las casas
   * disueltas que ya no figuran en él quedan sin linaje conocido.
   */
  function buildClanLineageIndex() {
    const index = new Map();
    const sections = [
      hallData?.weeklyRanking,
      hallData?.historicalRanking,
      hallData?.currentRegentClan === null || hallData?.currentRegentClan === undefined
        ? []
        : [hallData.currentRegentClan],
    ];

    for (const section of sections) {
      for (const clanDto of Array.isArray(section) ? section : []) {
        if (clanDto?.id !== undefined && clanDto?.id !== null) {
          index.set(String(clanDto.id), String(clanDto.lineageType ?? ''));
        }
      }
    }

    return index;
  }

  /** ¿Se contempla esta casa bajo el filtro de linaje vigente? */
  function matchesActiveLineage(clanLineageType) {
    if (activeLineage === null) return true;
    return String(clanLineageType ?? '') === activeLineage;
  }

  /* =====================================================================
     Armazón ceremonial
     ===================================================================== */

  /** Construye (una sola vez) el pabellón: título, pestañas, filtros y panel. */
  function ensureShell() {
    if (hallRoot !== null) return;

    hallRoot = track(elementFactory('section'));
    hallRoot.className = 'lineage-hall';
    hallRoot.setAttribute('data-view', 'lineageHall');
    // Región etiquetada: el Salón se anuncia como un todo contemplable.
    hallRoot.setAttribute('role', 'region');
    hallRoot.setAttribute('aria-labelledby', 'lineageHallTitle');
    mountRoot.appendChild(hallRoot);

    const header = track(elementFactory('header'));
    header.className = 'lineage-hall__header';
    hallRoot.appendChild(header);

    const title = appendTextElement(header, 'h1', 'lineage-hall__title', LINEAGE_HALL_TITLE);
    title.setAttribute('id', 'lineageHallTitle');
    appendTextElement(header, 'p', 'lineage-hall__hint', LINEAGE_HALL_HINT);

    buildTabs();
    buildFilterBar();

    announcement = appendTextElement(hallRoot, 'p', 'lineage-hall__announcement', '');
    // Los cambios de sección o de filtro se verbalizan por cortesía.
    announcement.setAttribute('role', 'status');
    announcement.setAttribute('aria-live', 'polite');
    announcement.setAttribute('aria-atomic', 'true');

    panel = track(elementFactory('div'));
    panel.className = 'lineage-hall__panel';
    panel.setAttribute('id', 'lineageHallPanel');
    panel.setAttribute('role', 'tabpanel');
    panel.setAttribute('tabindex', '0');
    hallRoot.appendChild(panel);
  }

  /** Conmutador de pestañas (tablist WAI-ARIA con navegación por flechas). */
  function buildTabs() {
    const tablist = track(elementFactory('div'));
    tablist.className = 'lineage-hall__tabs';
    tablist.setAttribute('role', 'tablist');
    tablist.setAttribute('aria-label', LINEAGE_HALL_TABLIST_LABEL);
    hallRoot.appendChild(tablist);

    for (const tab of LINEAGE_HALL_TABS) {
      const button = track(elementFactory('button'));
      button.type = 'button';
      button.className = 'lineage-tab';
      button.setAttribute('id', `lineageHallTab-${tab.id}`);
      button.setAttribute('role', 'tab');
      button.setAttribute('aria-controls', 'lineageHallPanel');
      button.textContent = tab.label;
      button.setAttribute('data-tab', tab.id);
      button.addEventListener('click', () => setTab(tab.id));
      button.addEventListener('keydown', (event) => handleTabKeydown(event, tab.id));
      tablist.appendChild(button);
      tabButtons.set(tab.id, button);
    }
  }

  /** Navegación por teclado del conmutador (RNF-03). */
  function handleTabKeydown(event, tabId) {
    const order = LINEAGE_HALL_TABS.map((tab) => tab.id);
    const currentIndex = order.indexOf(tabId);
    let nextIndex = null;

    if (event.key === 'ArrowRight') nextIndex = (currentIndex + 1) % order.length;
    else if (event.key === 'ArrowLeft') nextIndex = (currentIndex - 1 + order.length) % order.length;
    else if (event.key === 'Home') nextIndex = 0;
    else if (event.key === 'End') nextIndex = order.length - 1;

    if (nextIndex === null) return;

    event.preventDefault?.();
    const nextTab = order[nextIndex];
    setTab(nextTab);
    tabButtons.get(nextTab)?.focus?.();
  }

  /** Barra de filtros: un glifo rúnico por cada Linaje Canónico (RF-06.2). */
  function buildFilterBar() {
    filterBar = track(elementFactory('div'));
    filterBar.className = 'lineage-hall__filters';
    filterBar.setAttribute('role', 'group');
    filterBar.setAttribute('aria-label', LINEAGE_HALL_FILTER_GROUP_LABEL);
    hallRoot.appendChild(filterBar);
    paintFilters();
  }

  /** Repinta los ocho filtros desde el catálogo de linajes. */
  function paintFilters() {
    clearChildren(filterBar);
    filterButtons = new Map();

    for (const lineage of catalog.values()) {
      if (lineage === null || lineage === undefined) continue;

      const lineageId = String(lineage.id ?? '');
      if (lineageId === '') continue;

      const button = track(elementFactory('button'));
      button.type = 'button';
      button.className = 'lineage-filter';
      button.setAttribute('data-lineage', lineageId);
      button.setAttribute('data-heraldic-frame', String(lineage.heraldicFrame ?? ''));
      button.setAttribute('aria-pressed', 'false');
      // El nombre accesible describe la acción y el linaje (RF-06.2).
      button.setAttribute('aria-label', `Filtrar por el ${String(lineage.name ?? lineageId)}`);
      button.setAttribute('title', String(lineage.name ?? lineageId));
      button.addEventListener('click', () => setLineageFilter(lineageId));

      // El sello del linaje (SPEC-02 RF-07): su carga y su afinidad rectora
      // lo declaran; el glifo rúnico técnico no se imprime jamás.
      const glyph = track(createRuneSeal({
        houseName: String(lineage.name ?? lineageId),
        coatOfArms: `rune_lineage_${lineageId}`,
        rulingElement: String(lineage.rulingElement ?? ''),
        lineageName: String(lineage.name ?? lineageId),
        role: 'lineage',
        document: documentRef,
      }));
      glyph.classList.add('lineage-filter__glyph');
      // El sello es ornamental aquí: su significado viaja en el nombre accesible.
      glyph.setAttribute('aria-hidden', 'true');
      button.appendChild(glyph);

      filterBar.appendChild(button);
      filterButtons.set(lineageId, button);
    }

    // El catálogo pudo cambiar: el filtro activo se revalida contra él.
    if (activeLineage !== null && !filterButtons.has(activeLineage)) {
      activeLineage = null;
    }
    paintFilterState();
  }

  /** Refresca el estado ARIA de los filtros (aria-pressed). */
  function paintFilterState() {
    for (const [lineageId, button] of filterButtons) {
      button.setAttribute('aria-pressed', lineageId === activeLineage ? 'true' : 'false');
    }
  }

  /** Refresca el estado ARIA de las pestañas y el panel. */
  function paintTabState() {
    for (const [tabId, button] of tabButtons) {
      const isActive = tabId === activeTab;
      button.setAttribute('aria-selected', isActive ? 'true' : 'false');
      // Recorrido de foco: solo la pestaña activa es tabulable (WAI-ARIA).
      button.setAttribute('tabindex', isActive ? '0' : '-1');
    }
    panel?.setAttribute('aria-labelledby', `lineageHallTab-${activeTab}`);
  }

  /* =====================================================================
     Pintado de las tres secciones
     ===================================================================== */

  /**
   * Sello Rúnico de una casa (SPEC-02 RF-07): la carga declara el linaje y el
   * metal del anillo declara su estado. El blasón se codifica, no se imprime.
   */
  function appendShield(parent, clanDto, lineage, state = RUNE_SEAL_STATES.ACTIVE) {
    const shield = track(createRuneSeal({
      houseName: String(clanDto.name ?? ''),
      coatOfArms: String(clanDto.coatOfArms ?? ''),
      rulingElement: String(lineage?.rulingElement ?? ''),
      lineageName: String(lineage?.ceremonialName ?? ''),
      state,
      document: documentRef,
    }));
    shield.classList.add('podium-rank__coat');
    parent.appendChild(shield);
  }

  /** ¿Es esta casa el linaje fundacional neutro? (Art. III) */
  function isNeutralClan(clanDto) {
    return String(clanDto?.id ?? '') === LINEAGE_HALL_NEUTRAL_CLAN_ID;
  }

  /** Hace accionable una pieza del Salón si el orquestador lo pidió. */
  function bindClanSelection(node, clanDto) {
    if (typeof onClanSelect !== 'function') return;
    const clanId = String(clanDto.id ?? '');
    node.setAttribute('data-action', 'selectClan');
    node.setAttribute('role', 'button');
    node.setAttribute('tabindex', '0');
    node.addEventListener('click', () => onClanSelect(clanId));
    node.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault?.();
        onClanSelect(clanId);
      }
    });
  }

  /** Una posición del podio semanal (RF-06.1, RF-04.4). */
  function buildPodiumRank(clanDto, position, isRegent) {
    const lineage = describeLineage(String(clanDto.lineageType ?? ''));
    const article = track(elementFactory('article'));
    article.className = isRegent ? 'podium-rank podium-rank--regent' : 'podium-rank';
    if (isNeutralClan(clanDto)) article.className += ' podium-rank--neutral';
    article.setAttribute('data-clan-id', String(clanDto.id ?? ''));
    article.setAttribute('data-lineage', String(clanDto.lineageType ?? ''));
    article.setAttribute('data-rank', String(position));

    const positionLabel = isNeutralClan(clanDto) ? '—' : ordinalLabel(position);
    appendTextElement(article, 'p', 'podium-rank__position', positionLabel);

    // La corona es CONTENIDO (el reinado), no adorno (RF-04.4).
    if (isRegent) {
      const crown = elementFactory('span');
      crown.className = 'podium-rank__crown';
      crown.setAttribute('role', 'img');
      crown.setAttribute('aria-label', LINEAGE_HALL_REGENT_LABEL);
      crown.textContent = '♛';
      article.appendChild(crown);
      track(crown);
    }

    appendShield(
      article,
      clanDto,
      lineage,
      isRegent
        ? RUNE_SEAL_STATES.REGENT
        : (String(clanDto.status ?? 'active') === 'archived'
          ? RUNE_SEAL_STATES.ARCHIVED
          : RUNE_SEAL_STATES.ACTIVE),
    );

    const name = appendTextElement(
      article,
      'h3',
      'podium-rank__clan-name',
      String(clanDto.name ?? ''),
    );
    name.setAttribute('id', `lineageHallClan-${String(clanDto.id ?? '')}`);

    appendTextElement(article, 'p', 'podium-rank__motto', `«${String(clanDto.motto ?? '')}»`);
    appendTextElement(article, 'p', 'podium-rank__lineage', describeClanLineage(clanDto));
    appendTextElement(
      article,
      'p',
      'podium-rank__points',
      `${Number(clanDto.weeklyPoints ?? 0)} PDA semanales · ${Number(clanDto.memberCount ?? 0)} adeptos`,
    );

    if (isNeutralClan(clanDto)) {
      appendTextElement(article, 'p', 'podium-rank__neutral', LINEAGE_HALL_NEUTRAL_LEGEND);
    } else if (isRegent) {
      appendTextElement(
        article,
        'p',
        'podium-rank__reign',
        'Ciñe la corona dorada del Dominio durante los siete días de su mandato.',
      );
    }

    bindClanSelection(article, clanDto);

    return article;
  }

  /** Pinta la Clasificación Semanal en Vivo. */
  function paintWeeklyRanking() {
    const rows = Array.isArray(hallData?.weeklyRanking) ? hallData.weeklyRanking : [];
    const visible = rows.filter((clanDto) => matchesActiveLineage(clanDto?.lineageType));
    const regentId = String(hallData?.currentRegentClan?.id ?? '');

    if (visible.length === 0) {
      appendTextElement(panel, 'p', 'lineage-hall__empty', LINEAGE_HALL_EMPTY_LEGEND);
      return;
    }

    const podium = track(elementFactory('div'));
    podium.className = 'lineage-hall__podium';
    panel.appendChild(podium);

    for (const clanDto of visible) {
      // El puesto es el del canon del Salón, no el de la lista filtrada.
      const position = rows.indexOf(clanDto) + 1;
      const isRegent = regentId !== '' && String(clanDto?.id ?? '') === regentId;
      podium.appendChild(buildPodiumRank(clanDto, position, isRegent));
    }
  }

  /** Pinta una fila del Prestigio Histórico Perpetuo (RF-06.1). */
  function buildHistoricalRow(clanDto, position) {
    const row = track(elementFactory('div'));
    row.className = isNeutralClan(clanDto) ? 'historical-row historical-row--neutral' : 'historical-row';
    row.setAttribute('data-clan-id', String(clanDto.id ?? ''));
    row.setAttribute('data-lineage', String(clanDto.lineageType ?? ''));

    appendTextElement(
      row,
      'span',
      'historical-row__rank',
      isNeutralClan(clanDto) ? '—' : ordinalLabel(position),
    );
    appendTextElement(row, 'span', 'historical-row__clan', String(clanDto.name ?? ''));
    appendTextElement(row, 'span', 'historical-row__lineage', describeLineage(String(clanDto.lineageType ?? '')).ceremonialName);
    appendTextElement(
      row,
      'span',
      'historical-row__glory',
      `${Number(clanDto.historicalPoints ?? 0)} de gloria perpetua`,
    );

    bindClanSelection(row, clanDto);

    return row;
  }

  /** Pinta el Prestigio Histórico de todos los tiempos. */
  function paintHistoricalRanking() {
    const rows = Array.isArray(hallData?.historicalRanking) ? hallData.historicalRanking : [];
    const visible = rows.filter((clanDto) => matchesActiveLineage(clanDto?.lineageType));

    if (visible.length === 0) {
      appendTextElement(panel, 'p', 'lineage-hall__empty', LINEAGE_HALL_EMPTY_LEGEND);
      return;
    }

    const list = track(elementFactory('div'));
    list.className = 'lineage-hall__historical';
    panel.appendChild(list);

    for (const clanDto of visible) {
      const position = rows.indexOf(clanDto) + 1;
      list.appendChild(buildHistoricalRow(clanDto, position));
    }
  }

  /** Pinta una entrada del Libro Mayor de Campeones Pasados (RF-06.1). */
  function buildFameEntry(cycleDto) {
    const entry = track(elementFactory('article'));
    entry.className = 'fame-entry';
    entry.setAttribute('data-cycle-id', String(cycleDto?.id ?? ''));
    entry.setAttribute('data-regent-clan-id', String(cycleDto?.regentClanId ?? ''));

    appendTextElement(
      entry,
      'p',
      'fame-entry__week',
      String(cycleDto?.label ?? `Año ${cycleDto?.cycleYear ?? 0} · Semana ${cycleDto?.weekNumber ?? 0}`),
    );
    appendTextElement(entry, 'h3', 'fame-entry__champion', String(cycleDto?.regentClanName ?? ''));
    appendTextElement(
      entry,
      'p',
      'fame-entry__glory',
      `${Number(cycleDto?.winningPoints ?? 0)} PDA · ${Number(cycleDto?.winnerSpellCount ?? 0)} conjuros sellados`,
    );

    return entry;
  }

  /** Pinta el Libro Mayor de Campeones Pasados. */
  function paintHallOfFame() {
    appendTextElement(panel, 'p', 'lineage-hall__hint lineage-hall__fame-hint', LINEAGE_HALL_FAME_HINT);

    const weeks = Array.isArray(hallData?.hallOfFameWeeks) ? hallData.hallOfFameWeeks : [];
    const lineageIndex = buildClanLineageIndex();
    const visible = weeks.filter((cycleDto) => {
      if (activeLineage === null) return true;
      // Solo se contemplan los campeones cuyo estandarte aún consta en el Salón.
      const knownLineage = lineageIndex.get(String(cycleDto?.regentClanId ?? ''));
      return knownLineage !== undefined && knownLineage === activeLineage;
    });

    if (visible.length === 0) {
      appendTextElement(
        panel,
        'p',
        'lineage-hall__empty',
        activeLineage === null
          ? LINEAGE_HALL_EMPTY_LEGEND
          : 'Ninguna casa campeona de este linaje figura aún en el Salón.',
      );
      return;
    }

    if (activeLineage !== null) {
      appendTextElement(panel, 'p', 'lineage-hall__hint', LINEAGE_HALL_FAME_FILTER_HINT);
    }

    const book = track(elementFactory('div'));
    book.className = 'lineage-hall__hall-of-fame';
    panel.appendChild(book);

    for (const cycleDto of visible) {
      book.appendChild(buildFameEntry(cycleDto));
    }
  }

  /** Texto de la proclama cortés según la sección y el filtro vigentes. */
  function announcementFor(visibleCount) {
    const lineage = activeLineage === null ? '' : ` Filtro: ${describeLineage(activeLineage).ceremonialName}.`;

    if (activeTab === LINEAGE_HALL_TAB_HISTORICAL) {
      return `Prestigio Histórico Perpetuo: ${visibleCount} hermandades.${lineage}`;
    }
    if (activeTab === LINEAGE_HALL_TAB_HALL_OF_FAME) {
      return `Libro Mayor de Campeones Pasados: ${visibleCount} semanas inmortalizadas.${lineage}`;
    }
    return `Clasificación Semanal en Vivo: ${visibleCount} hermandades.${lineage}`;
  }

  /** Repinta el panel según la fase y la sección activas. */
  function paint() {
    if (hallRoot === null) return;

    paintTabState();
    paintFilterState();
    clearChildren(panel);

    if (phase === 'loading') {
      appendTextElement(panel, 'p', 'lineage-hall__loading', LINEAGE_HALL_LOADING_LEGEND);
      return;
    }

    if (phase === 'error') {
      const errorBlock = track(elementFactory('div'));
      errorBlock.className = 'lineage-hall__error';
      // role="alert": el fallo interrumpe la contemplación del Salón.
      errorBlock.setAttribute('role', 'alert');
      appendTextElement(
        errorBlock,
        'p',
        'lineage-hall__error-message',
        typeof errorEnvelope?.message === 'string' && errorEnvelope.message.trim() !== ''
          ? errorEnvelope.message
          : LINEAGE_HALL_ERROR_LEGEND,
      );

      const retryButton = elementFactory('button');
      retryButton.type = 'button';
      retryButton.className = 'lineage-hall__error-retry';
      retryButton.textContent = LINEAGE_HALL_RETRY_LABEL;
      retryButton.addEventListener('click', () => {
        phase = 'loading';
        paint();
        if (typeof onRetry === 'function') onRetry();
      });
      errorBlock.appendChild(retryButton);
      track(retryButton);
      panel.appendChild(errorBlock);
      announcement.textContent = '';
      return;
    }

    if (activeTab === LINEAGE_HALL_TAB_HISTORICAL) {
      paintHistoricalRanking();
    } else if (activeTab === LINEAGE_HALL_TAB_HALL_OF_FAME) {
      paintHallOfFame();
    } else {
      paintWeeklyRanking();
    }

    announce(announcementFor(countVisibleRows()));
  }

  /** Cuenta las piezas visibles de la sección activa (para la proclama). */
  function countVisibleRows() {
    if (activeTab === LINEAGE_HALL_TAB_HISTORICAL) {
      const rows = Array.isArray(hallData?.historicalRanking) ? hallData.historicalRanking : [];
      return rows.filter((clanDto) => matchesActiveLineage(clanDto?.lineageType)).length;
    }

    if (activeTab === LINEAGE_HALL_TAB_HALL_OF_FAME) {
      const weeks = Array.isArray(hallData?.hallOfFameWeeks) ? hallData.hallOfFameWeeks : [];
      const lineageIndex = buildClanLineageIndex();
      return weeks.filter((cycleDto) => {
        if (activeLineage === null) return true;
        return lineageIndex.get(String(cycleDto?.regentClanId ?? '')) === activeLineage;
      }).length;
    }

    const rows = Array.isArray(hallData?.weeklyRanking) ? hallData.weeklyRanking : [];
    return rows.filter((clanDto) => matchesActiveLineage(clanDto?.lineageType)).length;
  }

  /* =====================================================================
     API pública
     ===================================================================== */

  /** Monta el pabellón y pinta el estado vigente (idempotente). */
  function render() {
    if (isDestroyed) return;
    ensureShell();
    paint();
  }

  /** Fija el catálogo de los 8 linajes canónicos (Endpoint 10). */
  function setLineages(lineages) {
    if (isDestroyed) return;
    catalog = new Map(
      (Array.isArray(lineages) ? lineages : []).map((lineage) => [lineage?.id, lineage]),
    );
    if (hallRoot !== null) paintFilters();
    paint();
  }

  /** Fija el Salón del Dominio (Endpoint 11) y lo pinta. */
  function setHall(hall) {
    if (isDestroyed) return;
    hallData = hall ?? null;
    errorEnvelope = null;
    phase = hallData === null ? 'loading' : 'ready';
    render();
  }

  /** Declara el corte de corriente y ofrece reintento. */
  function setError(error) {
    if (isDestroyed) return;
    errorEnvelope = error ?? null;
    phase = 'error';
    render();
  }

  /** Alterna la sección ceremonial del Salón. */
  function setTab(tabId) {
    if (isDestroyed) return;
    if (!tabButtons.has(tabId)) return;
    activeTab = tabId;
    paint();
  }

  /**
   * Filtra por Linaje Mágico rector. Pulsar el linaje ya activo retira el
   * filtro: el mismo gesto enciende y apaga (RF-06.2).
   */
  function setLineageFilter(lineageId) {
    if (isDestroyed) return;

    const requested = lineageId === null || lineageId === undefined ? null : String(lineageId);
    if (requested !== null && !filterButtons.has(requested)) return;

    activeLineage = requested === activeLineage ? null : requested;
    paint();
  }

  /** Sección activa en este instante. */
  function getActiveTab() {
    return activeTab;
  }

  /** Linaje activo en este instante, o null si se contemplan todos. */
  function getActiveLineageFilter() {
    return activeLineage;
  }

  /** Retira el pabellón del montaje y libera sus nodos. */
  function destroy() {
    if (isDestroyed) return;
    isDestroyed = true;
    for (const node of mountedNodes.splice(0)) {
      node.remove?.();
    }
    hallRoot = null;
    tabButtons = new Map();
    filterBar = null;
    filterButtons = new Map();
    announcement = null;
    panel = null;
  }

  return {
    render,
    setLineages,
    setHall,
    setError,
    setTab,
    setLineageFilter,
    getActiveTab,
    getActiveLineageFilter,
    destroy,
  };
}
