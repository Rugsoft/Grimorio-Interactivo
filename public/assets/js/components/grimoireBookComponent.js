/**
 * grimoireBookComponent.js — Tomo Arcano y Navegación de Páginas.
 *
 * Tarea 4.2 (TASKS-05): maqueta el simulador como un tomo abierto a doble
 * página (RF-01.1): la izquierda con la iluminación rúnica, metadatos,
 * componentes litúrgicos y fórmula en noble castellano; la derecha aloja
 * el contenedor de la Cámara de Conjuración. Incluye navegación acotada
 * sin bucle infinito (flechas desvanecidas en los extremos, RF-01.4),
 * atajos de teclado (flechas direccionales, RF-01.3), índice rúnico por
 * Círculo/Afinidad y pergamino virgen ante filtros vacíos (RF-01.4).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Modules nativos; DOM vía creación de
 *     nodos y textContent — el componente jamás usa innerHTML (AGENTS.md 6.1).
 *   - Artículo IV/V: leyendas solemnes castellanas; API en inglés camelCase.
 *
 * Cobertura: RF-01.1 (doble página), RF-01.3 (transiciones de página),
 * RF-01.4 (navegación acotada, índice y pergamino virgen), RF-01.5
 * (exhibición de la ficha litúrgica), RNF-03 (foco visible, aria).
 */

/** Etiquetas solemnes de la interfaz (Artículo IV). */
export const EMPTY_TOME_LEGEND = 'Aún no se han inscrito conjuros bajo esta afinidad o círculo en el santuario';

/** Canon de afinidades y sus nombres solemnes (plan 3.1). */
export const ELEMENT_NAMES = Object.freeze({
  fire: 'Fuego', water: 'Agua', lightning: 'Rayo', earth: 'Tierra',
  wind: 'Viento', light: 'Luz', darkness: 'Oscuridad', pureArcane: 'Arcano Puro',
});

/** Números romanos de los Círculos Arcanos. */
export const ROMAN_CIRCLES = Object.freeze({ 1: 'I', 2: 'II', 3: 'III', 4: 'IV', 5: 'V' });

/** Nombres solemnes de los tiempos de lanzamiento. */
const CASTING_TIME_NAMES = Object.freeze({
  action: 'acción', bonus: 'acción adicional', ritual: 'ritual', reaction: 'reacción',
});

/**
 * Crea el componente del Tomo Arcano montado sobre `options.root`.
 *
 * @param {object} options
 *   - root: anfitrión donde se monta el tomo.
 *   - cameraSlot: elemento (contenedor de la Cámara) a alojar en la página
 *     derecha; si se omite, se crea un hueco vacío.
 *   - spells: catálogo (fichas del contrato REST) a exhibir.
 *   - onPageChange(detail): callback con { spell, pageNumber, totalPages }
 *     en cada transición (bus de eventos del plan 4.1).
 *   - createElement: creador de nodos inyectable para arneses.
 * @returns {object} API: nextPage, previousPage, setFilter, clearFilter,
 *   getFilter, getState.
 */
export function createGrimoireBookComponent(options = {}) {
  const root = options.root;
  const externalCameraSlot = options.cameraSlot ?? null;
  const onPageChange = typeof options.onPageChange === 'function' ? options.onPageChange : () => {};
  const createNode = options.createElement
    ?? (typeof document !== 'undefined' ? document.createElement.bind(document) : null);
  if (typeof createNode !== 'function') {
    throw new TypeError('grimoireBookComponent requiere root del navegador o createElement inyectable');
  }

  /** Estado interno del tomo. */
  const state = {
    spells: Array.isArray(options.spells) ? options.spells : [],
    pageNumber: 1,
    filter: { circle: null, element: null },
  };

  // --- Construcción del árbol (sin innerHTML, AGENTS.md 6.1) ---
  const book = createNode('div');
  book.className = 'grimoire-book';
  book.setAttribute('role', 'group');
  book.setAttribute('aria-label', 'Tomo Arcano');

  // Flechas de navegación (navegación acotada, RF-01.4).
  const prevArrow = createNode('button');
  prevArrow.className = 'grimoire-book__arrow grimoire-book__arrow--prev';
  prevArrow.type = 'button';
  prevArrow.setAttribute('aria-label', 'Página anterior');

  const nextArrow = createNode('button');
  nextArrow.className = 'grimoire-book__arrow grimoire-book__arrow--next';
  nextArrow.type = 'button';
  nextArrow.setAttribute('aria-label', 'Página siguiente');

  // Página izquierda: liturgia del conjuro (RF-01.1/01.5).
  const leftPage = createNode('article');
  leftPage.className = 'grimoire-book__page grimoire-book__page--left';

  const emptyPage = createNode('div');
  emptyPage.className = 'grimoire-book__empty';
  const emptyLegend = createNode('p');
  emptyLegend.className = 'grimoire-book__empty-legend';
  emptyLegend.textContent = EMPTY_TOME_LEGEND;
  emptyPage.appendChild(emptyLegend);

  const pageFace = createNode('div');
  pageFace.className = 'grimoire-book__face';
  const spellName = createNode('h2');
  spellName.className = 'grimoire-book__spell-name';
  const spellCircle = createNode('p');
  spellCircle.className = 'grimoire-book__spell-circle';
  const spellElement = createNode('p');
  spellElement.className = 'grimoire-book__spell-element';
  const spellCastingTime = createNode('p');
  spellCastingTime.className = 'grimoire-book__spell-casting-time';
  const spellMana = createNode('p');
  spellMana.className = 'grimoire-book__spell-mana';
  const spellFormula = createNode('p');
  spellFormula.className = 'grimoire-book__spell-formula';
  spellFormula.setAttribute('aria-label', 'Fórmula litúrgica del conjuro');
  const spellDescription = createNode('p');
  spellDescription.className = 'grimoire-book__spell-description';
  pageFace.appendChild(spellName);
  pageFace.appendChild(spellCircle);
  pageFace.appendChild(spellElement);
  pageFace.appendChild(spellCastingTime);
  pageFace.appendChild(spellMana);
  pageFace.appendChild(spellFormula);
  pageFace.appendChild(spellDescription);

  // Página derecha: contenedor de la Cámara de Conjuración (RF-01.1).
  const rightPage = createNode('article');
  rightPage.className = 'grimoire-book__page grimoire-book__page--right';
  const cameraHousing = externalCameraSlot ?? createNode('div');
  if (!externalCameraSlot) {
    cameraHousing.className = 'grimoire-book__camera-slot';
  }
  cameraHousing.classList?.add?.('grimoire-book__camera-slot');
  rightPage.appendChild(cameraHousing);

  // Índice rúnico (RF-01.4): filtros por Círculo y Afinidad.
  const runeIndex = createNode('nav');
  runeIndex.className = 'grimoire-book__rune-index';
  runeIndex.setAttribute('aria-label', 'Índice rúnico');

  const circleIndex = createNode('div');
  circleIndex.className = 'grimoire-book__circle-index';
  for (const circle of [1, 2, 3, 4, 5]) {
    const option = createNode('button');
    option.className = 'grimoire-book__circle-option';
    option.type = 'button';
    option.textContent = `Círculo ${ROMAN_CIRCLES[circle]}`;
    option.setAttribute('data-circle', String(circle));
    option.addEventListener('click', () => {
      setFilter({ circle, element: state.filter.element });
    });
    circleIndex.appendChild(option);
  }

  const elementIndex = createNode('div');
  elementIndex.className = 'grimoire-book__element-index';
  for (const [key, label] of Object.entries(ELEMENT_NAMES)) {
    const option = createNode('button');
    option.className = 'grimoire-book__element-option';
    option.type = 'button';
    option.textContent = label;
    option.setAttribute('data-element', key);
    option.addEventListener('click', () => {
      setFilter({ circle: state.filter.circle, element: key });
    });
    elementIndex.appendChild(option);
  }

  const filterClear = createNode('button');
  filterClear.className = 'grimoire-book__filter-clear';
  filterClear.type = 'button';
  filterClear.textContent = 'Ver todo el tomo';
  filterClear.addEventListener('click', () => clearFilter());

  runeIndex.appendChild(circleIndex);
  runeIndex.appendChild(elementIndex);
  runeIndex.appendChild(filterClear);

  // Montaje final del tomo.
  leftPage.appendChild(pageFace);
  leftPage.appendChild(emptyPage);
  book.appendChild(prevArrow);
  book.appendChild(leftPage);
  book.appendChild(rightPage);
  book.appendChild(nextArrow);
  book.appendChild(runeIndex);
  root.appendChild(book);

  /** Catálogo tras aplicar el filtro activo (índice rúnico). */
  function filteredSpells() {
    return state.spells.filter((spell) => {
      if (state.filter.circle !== null && spell.circle !== state.filter.circle) return false;
      if (state.filter.element !== null && spell.elementalAffinity !== state.filter.element) return false;
      return true;
    });
  }

  /** Ficha visible actualmente (o null si el pergamino está virgen). */
  function currentSpell() {
    const spells = filteredSpells();
    if (spells.length === 0) return null;
    const index = Math.min(Math.max(state.pageNumber, 1), spells.length) - 1;
    return spells[index];
  }

  /** Pinta la página izquierda y el estado de las flechas (RF-01.4/01.5). */
  function render() {
    const spells = filteredSpells();
    const total = spells.length;
    const spell = currentSpell();

    // Flechas: desvanecidas rúnicamente en los extremos (sin bucle).
    prevArrow.disabled = total === 0 || state.pageNumber <= 1;
    nextArrow.disabled = total === 0 || state.pageNumber >= total;

    // Marcado activo del índice rúnico.
    for (const option of circleIndex.children) {
      option.classList.toggle('grimoire-book__circle-option--active',
        state.filter.circle !== null && Number(option.getAttribute('data-circle')) === state.filter.circle);
    }
    for (const option of elementIndex.children) {
      option.classList.toggle('grimoire-book__element-option--active',
        state.filter.element !== null && option.getAttribute('data-element') === state.filter.element);
    }

    // Exhibición (RF-01.5) o pergamino virgen (RF-01.4).
    const hasSpell = spell !== null;
    pageFace.classList.toggle('grimoire-book__face--hidden', !hasSpell);
    emptyPage.classList.toggle('grimoire-book__empty--visible', !hasSpell);
    if (!hasSpell) {
      return;
    }

    spellName.textContent = spell.name ?? 'Conjuro sin nombre';
    spellCircle.textContent = `Círculo ${ROMAN_CIRCLES[spell.circle] ?? '?'}`;
    spellElement.textContent = ELEMENT_NAMES[spell.elementalAffinity] ?? spell.elementalAffinity ?? '';
    spellCastingTime.textContent = `Tiempo de lanzamiento: ${CASTING_TIME_NAMES[spell.castingTime] ?? spell.castingTime ?? '—'}`;
    spellMana.textContent = `${spell.manaCost ?? 0} puntos de maná`;
    spellFormula.textContent = spell.incantationFormula ?? '';
    spellDescription.textContent = spell.description ?? '';
  }

  /** Notifica la transición de página (bus `grimoire:page-change`). */
  function notifyChange() {
    const spell = currentSpell();
    if (spell) {
      onPageChange({
        spell,
        pageNumber: state.pageNumber,
        totalPages: filteredSpells().length,
      });
    }
  }

  /**
   * Avanza a la página siguiente (RF-01.3). En el extremo derecho el
   * control está desvanecido y la llamada es inoperante (sin bucle).
   */
  function nextPage() {
    const total = filteredSpells().length;
    if (total === 0 || state.pageNumber >= total) return;
    state.pageNumber += 1;
    render();
    notifyChange();
  }

  /**
   * Retrocede a la página anterior (RF-01.3). En el extremo izquierdo la
   * llamada es inoperante.
   */
  function previousPage() {
    if (filteredSpells().length === 0 || state.pageNumber <= 1) return;
    state.pageNumber -= 1;
    render();
    notifyChange();
  }

  /** Aplica el filtro del índice rúnico y vuelve a la primera página. */
  function setFilter(filter) {
    state.filter = {
      circle: filter?.circle ?? null,
      element: filter?.element ?? null,
    };
    state.pageNumber = 1;
    render();
    notifyChange();
  }

  /** Limpia los filtros: catálogo completo desde la primera página. */
  function clearFilter() {
    setFilter({ circle: null, element: null });
  }

  /** Filtro activo (para vistas y arneses). */
  function getFilter() {
    return { ...state.filter };
  }

  /** Copia del estado (página, catálogo filtrado y ficha visible). */
  function getState() {
    const spells = filteredSpells();
    return {
      pageNumber: state.pageNumber,
      totalPages: spells.length,
      spell: currentSpell(),
      spells,
    };
  }

  // Atajos de teclado (RF-01.3): flechas direccionales sobre el tomo.
  book.addEventListener('keydown', (event) => {
    if (event?.key === 'ArrowRight') {
      nextPage();
    } else if (event?.key === 'ArrowLeft') {
      previousPage();
    }
  });

  prevArrow.addEventListener('click', () => previousPage());
  nextArrow.addEventListener('click', () => nextPage());

  render();
  return { nextPage, previousPage, setFilter, clearFilter, getFilter, getState };
}
