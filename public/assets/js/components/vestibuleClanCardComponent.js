/**
 * vestibuleClanCardComponent.js — Tarjeta solemne del Vestíbulo de las
 * Hermandades (SPEC-10, Tarea 5.1).
 *
 * RF-01.3: cada tarjeta muestra, como mínimo, nombre canónico, lema
 *          heráldico, Sello Rúnico determinista (SPEC-02 RF-07; el
 *          identificador `coatOfArms` se CODIFICA, jamás se imprime),
 *          censo sobre plenitud («X de 30»), régimen rotulado en castellano
 *          («Admisión abierta» / «Requiere petición formal»), honor vigente
 *          (corona del Regente con los estilos del kit de SPEC-07, jamás
 *          ad hoc) y la condición del adepto ante esa casa.
 * RF-01.7: los cinco estados de tarjeta (`none`, `join`, `petition`,
 *          `pending`, `own`) se PINTAN desde el DTO (`VestibuleClanDto`) —
 *          la interfaz jamás decide: gestos, rótulos y leyendas viajan en
 *          `gesture` y `vedadoLegend` declarados por el santuario.
 * RF-03.5: con el gesto vedado (`gesture === null`), la leyenda solemne
 *          sustituye al control sin error ni modal; la contemplación
 *          permanece íntegra.
 * RNF-03:  tarjeta enfocable (tabindex=0) y operable por teclado
 *          (Enter/espaciadora); los controles internos son botones nativos.
 *
 * El estado vacío del catálogo («Ninguna hermandad ruega aún tu linaje»,
 * con su invitación discreta a fundar la primera casa) NO vive aquí: es
 * rótulo de la vista orquestadora (Tarea 5.5), que remite a SPEC-07 sin
 * flujo de fundación propio (RF-01.4 de la SPEC-10).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM nativo; `textContent` puro,
 *     `innerHTML` PROHIBIDO (AGENTS.md 6.1). Cero dependencias.
 *   - Artículo IV (El Velo Arcano): rótulos y leyendas en castellano.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * @module components/vestibuleClanCardComponent
 */

import {
  createRuneSeal,
  RUNE_SEAL_STATES,
} from './runeSealComponent.js';

/** Rótulos castellanos del estado del adepto ante la casa (RF-01.3, RF-01.7). */
export const VESTIBULE_CARD_RELATION_LABELS = Object.freeze({
  none: 'Sin vínculo con esta casa',
  pending: 'Pendiente de dictamen',
  ownHouse: 'Tu hermandad',
});

/** Etiquetas castellanas de cada gesto disponible (RF-02, RF-03; voz activa). */
export const VESTIBULE_CARD_GESTURE_LABELS = Object.freeze({
  join: 'Solicitar ingreso',
  petition: 'Presentar petición formal',
  withdraw: 'Retirar mi petición',
});

/** Rótulo del censo sobre plenitud (RF-01.3). */
export const VESTIBULE_CARD_CENSUS_LABEL = 'adeptos';

/** Marcado de teclas activadoras de la tarjeta (RNF-03). */
const ACTIVATION_KEYS = new Set(['Enter', ' ']);

/** Corona del Regente: el glifo del kit de SPEC-07 (sin estilos ad hoc). */
const REGENT_CROWN_GLYPH = '👑';

/**
 * Crea la tarjeta solemne de una hermandad del Vestíbulo.
 *
 * @param {object} clanDto VestibuleClanDto serializado (plan §2.2):
 *   { clanId, name, motto, coatOfArms, lineageType, memberCount, memberLimit,
 *     admissionMode, admissionModeLabel, isRegent, adeptRelation, gesture,
 *     vedadoLegend, petitionId? }.
 * @param {object} componentOptions Opciones:
 *   - onGesture(clanId, gesture): gesto activado (`join` | `petition` |
 *     `withdraw`). El orquestador abrirá el modal que corresponda (Tareas
 *     5.2 y 5.3); la tarjeta jamás consume adhesión por su cuenta.
 *   - elementFactory: fábrica de elementos (por defecto document.createElement;
 *     los arneses inyectan la suya).
 *   - documentRef: documento anfitrión del sello forjado (SVG; arneses sin
 *     navegador). Por defecto, el documento global.
 * @returns {object} API: { element, destroy }.
 */
export function createVestibuleClanCardComponent(clanDto, componentOptions = {}) {
  const {
    onGesture,
    documentRef = globalThis.document,
  } = componentOptions;

  // El default de la fábrica deriva del documento INYECTADO (los arneses
  // carecen de `globalThis.document`; hallazgo de los arneses 5.3/5.4/5.5).
  const elementFactory = componentOptions.elementFactory
    ?? ((tagName) => documentRef.createElement(tagName));

  const dto = clanDto ?? {};
  const clanId = String(dto.clanId ?? '');
  const gesture = dto.gesture ?? null;
  const vedadoLegend = typeof dto.vedadoLegend === 'string' ? dto.vedadoLegend : '';
  const relationLabel = VESTIBULE_CARD_RELATION_LABELS[dto.adeptRelation]
    ?? VESTIBULE_CARD_RELATION_LABELS.none;

  // ---------------------------------------------------------------
  // Raíz: <article> enfocable y operable por teclado (RNF-03).
  // ---------------------------------------------------------------
  const cardElement = elementFactory('article');
  cardElement.className = 'vestibule-card';
  cardElement.setAttribute('data-clan-id', clanId);
  cardElement.setAttribute('data-gesture', gesture ?? 'vedado');
  cardElement.setAttribute('data-relation', dto.adeptRelation ?? 'none');
  cardElement.setAttribute('tabindex', '0');
  cardElement.setAttribute('aria-label', `Hermandad ${String(dto.name ?? '')}: ${relationLabel}`);

  // Honor vigente: la corona del Regente viste los estilos del kit de
  // SPEC-07 (`.podium-rank--regent` family); jamás un adorno ad hoc.
  if (dto.isRegent === true) {
    const crown = elementFactory('span');
    crown.className = 'podium-rank__crown vestibule-card__crown';
    crown.setAttribute('aria-hidden', 'true');
    crown.textContent = REGENT_CROWN_GLYPH;
    cardElement.appendChild(crown);
  }

  // Sello Rúnico determinista (SPEC-02 RF-07): el metal y la forma derivan
  // del estado heráldico — bronce roto si la casa yace disuelta.
  const sealState = dto.adeptRelation === 'ownHouse' && dto.houseArchived === true
    ? RUNE_SEAL_STATES.ARCHIVED
    : dto.isRegent === true
      ? RUNE_SEAL_STATES.REGENT
      : RUNE_SEAL_STATES.ACTIVE;
  const seal = createRuneSeal({
    houseName: String(dto.name ?? ''),
    coatOfArms: String(dto.coatOfArms ?? ''),
    rulingElement: String(dto.lineageType ?? ''),
    state: sealState,
    role: 'house',
    document: documentRef,
  });
  cardElement.appendChild(seal);

  // ---------------------------------------------------------------
  // Identidad: nombre canónico y lema heráldico (RF-01.3).
  // ---------------------------------------------------------------
  const identity = elementFactory('div');
  identity.className = 'vestibule-card__identity';

  const nameHeading = elementFactory('h3');
  nameHeading.className = 'vestibule-card__name';
  nameHeading.textContent = String(dto.name ?? 'Casa sin nombre');
  identity.appendChild(nameHeading);

  const motto = elementFactory('p');
  motto.className = 'vestibule-card__motto';
  motto.textContent = String(dto.motto ?? '');
  identity.appendChild(motto);

  cardElement.appendChild(identity);

  // ---------------------------------------------------------------
  // Plenitud y régimen: «X de 30» y rótulo castellano (RF-01.3).
  // ---------------------------------------------------------------
  const facts = elementFactory('div');
  facts.className = 'vestibule-card__facts';

  const census = elementFactory('p');
  census.className = 'vestibule-card__census';
  const censusCount = elementFactory('span');
  censusCount.className = 'vestibule-card__census-count';
  censusCount.textContent = `${Number(dto.memberCount ?? 0)} de ${Number(dto.memberLimit ?? 30)}`;
  census.appendChild(censusCount);
  const censusLabel = elementFactory('span');
  censusLabel.className = 'vestibule-card__census-label';
  censusLabel.textContent = ` ${VESTIBULE_CARD_CENSUS_LABEL}`;
  census.appendChild(censusLabel);
  facts.appendChild(census);

  const regime = elementFactory('p');
  regime.className = 'vestibule-card__regime';
  // El rótulo canónico viaja en el DTO (`admissionModeLabel`); el fallback
  // es el mapa local, por si un backend legado viajara sin él.
  regime.textContent = dto.admissionModeLabel
    ?? (dto.admissionMode === 'byApplication' ? 'Requiere petición formal' : 'Admisión abierta');
  facts.appendChild(regime);

  cardElement.appendChild(facts);

  // ---------------------------------------------------------------
  // Estado del adepto + UN gesto o su leyenda vedada (RF-01.7, RF-03.5).
  // ---------------------------------------------------------------
  const statusArea = elementFactory('div');
  statusArea.className = 'vestibule-card__status';

  const relation = elementFactory('p');
  relation.className = 'vestibule-card__relation';
  relation.textContent = relationLabel;
  statusArea.appendChild(relation);

  // Referencia viva del gesto disponible (null si está vedado): el
  // teclado de la tarjeta la consulta sin suponer su existencia.
  let gestureButton = null;

  if (typeof gesture === 'string' && gesture !== '') {
    // Gesto disponible: botón nativo, voz activa, callback delegado.
    gestureButton = elementFactory('button');
    gestureButton.type = 'button';
    gestureButton.className = `vestibule-card__gesture vestibule-card__gesture--${gesture}`;
    gestureButton.textContent = VESTIBULE_CARD_GESTURE_LABELS[gesture] ?? gesture;
    gestureButton.addEventListener('click', () => {
      if (typeof onGesture === 'function') {
        onGesture(clanId, gesture);
      }
    });
    statusArea.appendChild(gestureButton);
  } else {
    // Gesto vedado: la leyenda solemne del santuario (RF-03.5), jamás un
    // error ni un modal. Sin leyenda declarada, rótulo neutro de contemplación.
    const vedado = elementFactory('p');
    vedado.className = 'vestibule-card__vedado';
    vedado.textContent = vedadoLegend !== '' ? vedadoLegend : 'Contemplación libre: sin gesto operativo en esta casa.';
    statusArea.appendChild(vedado);
  }

  cardElement.appendChild(statusArea);

  // ---------------------------------------------------------------
  // Teclado de la tarjeta: Enter/espaciadora activan el gesto SI existe
  // (delegación al botón); sin gesto, la tarjeta solo recibe foco.
  // ---------------------------------------------------------------
  function handleCardKeydown(keydownEvent) {
    if (!ACTIVATION_KEYS.has(keydownEvent.key)) return;
    if (gestureButton === null) return; // Gesto vedado: solo foco.
    // Evita doble activación cuando el foco ya está sobre el botón.
    if (keydownEvent.target === gestureButton) return;
    keydownEvent.preventDefault?.();
    gestureButton.click?.();
  }
  cardElement.addEventListener('keydown', handleCardKeydown);

  /** Baja limpia: la tarjeta es estática, pero respeta el contrato. */
  function destroy() {
    cardElement.removeEventListener('keydown', handleCardKeydown);
    if (typeof cardElement.remove === 'function') {
      cardElement.remove();
    }
  }

  return { element: cardElement, destroy };
}

/**
 * Crea el estado solemne de catálogo vacío (RF-01.4 de la SPEC-10):
 * «Ninguna hermandad ruega aún tu linaje» con la invitación discreta a
 * fundar la primera casa — rótulo que remite a SPEC-07, sin flujo propio.
 *
 * La tarea 5.1 lo consigna como parte del «Hecho cuando»; la vista
 * orquestadora (Tarea 5.5) lo monta cuando el catálogo llega sin casas.
 *
 * @param {object} componentOptions Opciones:
 *   - elementFactory: fábrica de elementos inyectable.
 * @returns {object} API: { element, destroy }.
 */
export function createVestibuleEmptyState(componentOptions = {}) {
  const {
    elementFactory = (tagName) => globalThis.document.createElement(tagName),
  } = componentOptions;

  const emptyElement = elementFactory('div');
  emptyElement.className = 'vestibule-empty';
  emptyElement.setAttribute('role', 'status');
  emptyElement.setAttribute('aria-live', 'polite');

  const legend = elementFactory('p');
  legend.className = 'vestibule-empty__legend';
  legend.textContent = 'Ninguna hermandad ruega aún tu linaje';
  emptyElement.appendChild(legend);

  const invitation = elementFactory('p');
  invitation.className = 'vestibule-empty__invitation';
  invitation.textContent = 'Podrás ser quien funde la primera.';
  emptyElement.appendChild(invitation);

  function destroy() {
    if (typeof emptyElement.remove === 'function') {
      emptyElement.remove();
    }
  }

  return { element: emptyElement, destroy };
}
