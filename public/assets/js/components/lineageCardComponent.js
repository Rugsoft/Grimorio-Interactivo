/**
 * lineageCardComponent.js — Tarjeta heráldica de la Ceremonia del Juramento
 * (SPEC-09, Tarea 4.1).
 *
 * RF-02.1: contraída, la tarjeta declara nombre solemne, glifo/blasón rúnico,
 *           estandarte ceremonial, afinidad rectora y doctrina condensada.
 * RF-02.2: expandida (selección), revela la doctrina íntegra y el botón
 *           «Jurar» — y solo si `hasActiveClans = false`, la nota discreta
 *           «Sin hermandades activas» (el dato jamás se inventa).
 * RNF-05:  enfocable y operable por teclado (Enter/espaciadora), con
 *           aria-expanded para lectores de pantalla.
 *
 * El texto del juramento NO vive aquí: su pronunciación solemne es del modal
 * (Tarea 4.2); la tarjeta solo convoca el gesto `onSwearIntent` cuando el
 * linaje ya está expandido y visible.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM nativo; `textContent` puro, `innerHTML`
 *     PROHIBIDO (AGENTS.md 6.1). Cero dependencias.
 *   - Artículo IV: leyendas solemnes en castellano.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * @module components/lineageCardComponent
 */

import {
  createRuneSeal,
  RUNE_SEAL_STATES,
} from './runeSealComponent.js';

/** Leyenda de la nota discreta de linajes sin hermandades vivas (RF-02.1). */
export const LINEAGE_CARD_NO_CLANS_LEGEND = 'Sin hermandades activas';

/** Etiqueta del gesto de jurar (RNF-02: voz solemne y activa). */
export const LINEAGE_CARD_SWEAR_LABEL = 'Jurar';

/** Rótulo del glifo heráldico (ornamento; el significado viaja en el nombre). */
const SEAL_ARIA_HIDDEN = 'true';

/** Marcado de teclas activadoras (Enter/espaciadora); otras se ignoran. */
const ACTIVATION_KEYS = new Set(['Enter', ' ']);

/**
 * Crea la tarjeta heráldica de un linaje del canon.
 *
 * @param {object} lineageProfile LineageProfileDto del canon (plan §2.1):
 *   { id, name, glyph, bannerColor, rulingElement, doctrineCondensed,
 *     doctrineFull, hasActiveClans }.
 * @param {object} componentOptions Opciones:
 *   - onSwearIntent(lineageId): gesto «Jurar» (el orquestador abrirá el modal).
 *   - onExpanded(lineageId): notificación de expansión (evento del plan §4).
 *   - elementFactory: fábrica de elementos (por defecto document.createElement;
 *     los arneses inyectan la suya).
 *   - documentRef: documento anfitrión del sello forjado (SVG; arneses sin
 *     navegador). Por defecto, el documento global.
 * @returns {object} API: { element, setExpanded, isExpanded, destroy }.
 */
export function createLineageCardComponent(lineageProfile, componentOptions = {}) {
  const {
    onSwearIntent,
    onExpanded,
    elementFactory = (tagName) => document.createElement(tagName),
    documentRef = globalThis.document,
  } = componentOptions;

  /** DTO validado de forma defensiva: el canon llega del backend (Tarea 2.1). */
  const lineage = lineageProfile ?? {};
  const lineageId = String(lineage.id ?? '');
  const lineageName = String(lineage.name ?? 'Linaje sin nombre');

  /** Estado de expansión (uno a la vez lo gobierna la vista). */
  let isExpanded = false;
  let isDestroyed = false;

  /** Raíz de la tarjeta: artículo enfocable y conmutable. */
  const cardElement = elementFactory('article');
  cardElement.setAttribute('class', 'lineage-card');
  cardElement.setAttribute('data-lineage-id', lineageId);
  cardElement.setAttribute('tabindex', '0');
  cardElement.setAttribute('aria-expanded', 'false');
  cardElement.setAttribute('aria-label', `${lineageName}. Pulse para desplegar su doctrina y el juramento`);

  /** Cabecera: sello heráldico + nombre + estandarte. */
  const headerElement = elementFactory('div');
  headerElement.setAttribute('class', 'lineage-card__header');
  cardElement.appendChild(headerElement);

  // El sello (SPEC-07): la MISMA forja heráldica de las fichas de linaje.
  const seal = createRuneSeal({
    houseName: lineageName,
    coatOfArms: `rune_lineage_${lineageId}`,
    rulingElement: String(lineage.rulingElement ?? ''),
    lineageName,
    state: RUNE_SEAL_STATES.ACTIVE,
    role: 'lineage',
    document: documentRef,
  });
  seal.setAttribute('class', 'lineage-card__seal');
  seal.setAttribute('aria-hidden', SEAL_ARIA_HIDDEN);
  headerElement.appendChild(seal);

  /** Cuerpo textual: nombre, elemento rector, condensada. */
  const bodyElement = elementFactory('div');
  bodyElement.setAttribute('class', 'lineage-card__body');
  headerElement.appendChild(bodyElement);

  const nameElement = elementFactory('h3');
  nameElement.setAttribute('class', 'lineage-card__name');
  nameElement.textContent = lineageName;
  bodyElement.appendChild(nameElement);

  const bannerElement = elementFactory('span');
  bannerElement.setAttribute('class', 'lineage-card__banner');
  // El estandarte es DATO del DTO (no decoración): viaja como Custom Property
  // y la hoja de estilos lo viste; jamás un literal de color en la forja.
  bannerElement.setAttribute('style', `--lineage-banner: ${String(lineage.bannerColor ?? 'transparent')}`);
  bannerElement.textContent = String(lineage.rulingElement ?? '');
  bodyElement.appendChild(bannerElement);

  const condensedElement = elementFactory('p');
  condensedElement.setAttribute('class', 'lineage-card__doctrine-condensed');
  condensedElement.textContent = String(lineage.doctrineCondensed ?? '');
  bodyElement.appendChild(condensedElement);

  /** Región expandida: oculta mientras aria-expanded lo declare contraído. */
  const expansionElement = elementFactory('div');
  expansionElement.setAttribute('class', 'lineage-card__expansion');
  expansionElement.setAttribute('hidden', 'true');
  cardElement.appendChild(expansionElement);

  const fullDoctrineElement = elementFactory('p');
  fullDoctrineElement.setAttribute('class', 'lineage-card__doctrine-full');
  fullDoctrineElement.textContent = String(lineage.doctrineFull ?? '');
  expansionElement.appendChild(fullDoctrineElement);

  // La nota discreta SOLO si el dato declara ausencia de hermandades (RF-02.1).
  if (lineage.hasActiveClans === false) {
    const noClansElement = elementFactory('p');
    noClansElement.setAttribute('class', 'lineage-card__no-clans');
    noClansElement.textContent = LINEAGE_CARD_NO_CLANS_LEGEND;
    expansionElement.appendChild(noClansElement);
  }

  const swearButton = elementFactory('button');
  swearButton.setAttribute('type', 'button');
  swearButton.setAttribute('class', 'lineage-card__swear button button--primary');
  swearButton.textContent = LINEAGE_CARD_SWEAR_LABEL;
  swearButton.setAttribute('aria-label', `Jurar el ${lineageName}`);
  swearButton.addEventListener('click', (clickEvent) => {
    clickEvent.stopPropagation();
    if (typeof onSwearIntent === 'function') onSwearIntent(lineageId);
  });
  expansionElement.appendChild(swearButton);

  /** Expande o contrae la tarjeta (idempotente, accesible, con notificación). */
  function setExpanded(nextIsExpanded) {
    if (isDestroyed) return;
    const expanded = nextIsExpanded === true;
    if (expanded === isExpanded) return;
    isExpanded = expanded;
    cardElement.setAttribute('aria-expanded', String(expanded));
    if (expanded) {
      expansionElement.removeAttribute('hidden');
      if (typeof onExpanded === 'function') onExpanded(lineageId);
    } else {
      expansionElement.setAttribute('hidden', 'true');
    }
  }

  /** Activación unificada: click sobre la tarjeta o Enter/espaciadora. */
  function handleCardActivation(activationEvent) {
    if (activationEvent.type === 'keydown' && !ACTIVATION_KEYS.has(activationEvent.key)) {
      return;
    }
    if (activationEvent.type === 'keydown') {
      activationEvent.preventDefault();
    }
    // Solo el click sobre la propia tarjeta expande; el botón «Jurar» tiene
    // su gesto propio (stopPropagation) y jamás re-expande.
    setExpanded(!isExpanded);
  }

  cardElement.addEventListener('click', handleCardActivation);
  cardElement.addEventListener('keydown', handleCardActivation);

  /** Baja limpia: los listeners viven en nodos que se retiran del árbol. */
  function destroy() {
    isDestroyed = true;
  }

  return {
    element: cardElement,
    setExpanded,
    isExpanded: () => isExpanded,
    destroy,
  };
}
