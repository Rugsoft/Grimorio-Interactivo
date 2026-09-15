/**
 * runeSealComponent.js — El Sello Rúnico forjado (SPEC-02 RF-07, SPEC-07 RF-02.4).
 *
 * El blasón de una casa NO se imprime: se FORJA. Este módulo dibuja un sello
 * heráldico determinista como SVG en línea, por aritmética nativa y sin un solo
 * archivo de imagen (Artículo I, Dogma Vanilla):
 *
 *   · La CARGA central declara el Linaje Mágico rector según el canon
 *     alquímico que los propios tokens del santuario ya declaran: fuego △,
 *     agua ▽, tierra ▽ barrada, viento △ con barra, más rayo, sol radiante,
 *     creciente abisal y ouroboros. Su color es el de la afinidad rectora en
 *     la paleta del sistema (`--color-affinity-*`), elegida para leerse sobre
 *     la tinta: la más apretada, Oscuridad, mide 4.64:1 sobre el disco.
 *   · El ANILLO de ocho muescas codifica el identificador `coat_of_arms`
 *     mediante una huella FNV-1a de 32 bits: el grosor y la longitud de cada
 *     muesca salen de sus bits, y una muesca maestra —más larga, rematada en
 *     rombo de metal— señala el sector `(huella >> 16) % 8`. La misma casa
 *     forja siempre el mismo sello; ninguna casa forja el de otra.
 *   · El METAL del anillo declara el estado, acompañado siempre de la FORMA:
 *     casa activa en oro antiguo, Clan Regente en oro vivo con su sello de
 *     cera presionado a las doce en punto, y casa disuelta en bronce con el
 *     anillo ROTO en su base (Herencia Ancestral). El color jamás habla solo.
 *
 * Lo que el sello codifica, no lo deletrea: el identificador técnico no entra
 * nunca en el texto de la interfaz —ni dentro del nombre accesible—, y tanto la
 * materia como el color de la carga viajan como Custom Properties del sistema
 * de diseño: la forja jamás escribe un literal de color.
 *
 * Constitución:
 *   - Artículo I: SVG nativo por aritmética; cero dependencias, cero imágenes.
 *   - Artículo II: el cliente dibuja lo que el servidor declara (linaje,
 *     color heráldico y estado llegan en el DTO); no decide nada por su cuenta.
 *   - Artículo V: identificadores en inglés camelCase; narrativa, etiquetas
 *     accesibles y comentarios en noble castellano.
 *
 * @module components/runeSealComponent
 */

/** Estados heráldicos canónicos del sello (RF-07.4). */
export const RUNE_SEAL_STATES = Object.freeze({
  ACTIVE: 'active',
  REGENT: 'regent',
  ARCHIVED: 'archived',
});

/** Carga central por afinidad elemental rectora (canon alquímico). */
export const LINEAGE_CHARGES = Object.freeze({
  fire: 'flame',
  water: 'tide',
  lightning: 'bolt',
  earth: 'root',
  wind: 'wind',
  light: 'sun',
  darkness: 'moon',
  pureArcane: 'ouroboros',
});

/** Carga de respaldo: el ouroboros del Arcano Puro. */
const FALLBACK_CHARGE = 'ouroboros';

/**
 * Color de la carga por afinidad rectora: la paleta del sistema, jamás un
 * literal. Son los tokens de afinidad —elegidos para leerse sobre la tinta—,
 * no el color del estandarte, que viste el marco heráldico sobre pergamino.
 */
export const RULING_ELEMENT_COLORS = Object.freeze({
  fire: 'var(--color-affinity-fire)',
  water: 'var(--color-affinity-water)',
  lightning: 'var(--color-affinity-lightning)',
  earth: 'var(--color-affinity-earth)',
  wind: 'var(--color-affinity-wind)',
  light: 'var(--color-affinity-light)',
  darkness: 'var(--color-affinity-darkness)',
  pureArcane: 'var(--color-affinity-arcane)',
});

/** Nombre ceremonial de cada afinidad, para la etiqueta accesible. */
export const RULING_ELEMENT_NAMES = Object.freeze({
  fire: 'Fuego',
  water: 'Agua / Escarcha',
  lightning: 'Rayo',
  earth: 'Tierra',
  wind: 'Viento',
  light: 'Luz',
  darkness: 'Oscuridad',
  pureArcane: 'Arcano Puro',
});

const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

/** Grosores de muesca: el peso también es dato, no adorno. */
const TALLY_WEIGHTS = Object.freeze([1.2, 2, 3, 4]);

/**
 * Huella FNV-1a de 32 bits: la misma cadena forja siempre el mismo sello.
 *
 * @param {string} text Identificador de blasón de la casa.
 * @returns {number} Huella entera sin signo.
 */
export function runeSealHash(text) {
  let hash = 0x811c9dc5;
  const source = String(text ?? '');
  for (let index = 0; index < source.length; index += 1) {
    hash ^= source.charCodeAt(index);
    hash = Math.imul(hash, 0x01000193) >>> 0;
  }
  return hash >>> 0;
}

/**
 * Etiqueta accesible del sello, en noble castellano y sin clave técnica.
 *
 * @param {object} options
 * @param {string} options.houseName    Nombre canónico de la casa o del linaje.
 * @param {string} [options.lineageName] Título ceremonial del linaje rector.
 * @param {string} [options.state]       Estado heráldico canónico.
 * @param {string} [options.role]        'lineage' si el sello marca un linaje.
 * @returns {string}
 */
export function runeSealLabel({ houseName = '', lineageName = '', state = RUNE_SEAL_STATES.ACTIVE, role = 'house' } = {}) {
  const subject = role === 'lineage'
    ? `Sello del ${String(lineageName || houseName || 'linaje no declarado')}`
    : `Sello heráldico de ${String(houseName || 'una casa sin nombre')}`;

  const lineageClause = role === 'lineage' || !lineageName ? '' : `, del ${String(lineageName)}`;
  const honor = state === RUNE_SEAL_STATES.REGENT
    ? '; porta la corona dorada del Dominio durante los siete días de su mandato'
    : state === RUNE_SEAL_STATES.ARCHIVED
      ? '; casa disuelta, conservada como Herencia Ancestral'
      : '';

  return `${subject}${lineageClause}${honor}.`;
}

/**
 * Crea el trazo de la carga central: geometría dibujada, jamás un glifo.
 *
 * @param {string} charge Clave de carga (flame, tide, bolt, root, wind, sun, moon, ouroboros).
 * @returns {string[]} Lista de descripciones de trazo.
 */
function chargeTraces(charge) {
  switch (charge) {
    case 'flame':
      return [{ kind: 'fill', d: 'M50 24 L74 70 L26 70 Z' }];
    case 'tide':
      return [{ kind: 'fill', d: 'M50 76 L26 34 L74 34 Z' }];
    case 'bolt':
      return [{ kind: 'fill', d: 'M56 22 L34 54 L48 54 L42 78 L66 44 L50 44 Z' }];
    case 'root':
      return [
        { kind: 'fill', d: 'M50 76 L26 34 L74 34 Z' },
        { kind: 'line', d: 'M33 48 H67' },
      ];
    case 'wind':
      return [
        { kind: 'fill', d: 'M50 32 L72 70 L28 70 Z' },
        { kind: 'line', d: 'M26 20 H74' },
      ];
    case 'sun': {
      const traces = [{ kind: 'fill', circle: { cx: 50, cy: 50, r: 13 } }];
      for (let step = 0; step < 8; step += 1) {
        const radians = (step * 45) * Math.PI / 180;
        const inner = 21;
        const outer = 31;
        traces.push({
          kind: 'line',
          d: `M${(50 + Math.cos(radians) * inner).toFixed(1)} ${(50 + Math.sin(radians) * inner).toFixed(1)} `
            + `L${(50 + Math.cos(radians) * outer).toFixed(1)} ${(50 + Math.sin(radians) * outer).toFixed(1)}`,
        });
      }
      return traces;
    }
    case 'moon':
      return [{ kind: 'fill', d: 'M60 24 A30 30 0 1 0 60 76 A24 24 0 1 1 60 24 Z' }];
    case 'ouroboros':
      return [
        { kind: 'ring', circle: { cx: 50, cy: 50, r: 24 }, width: 5 },
        { kind: 'fill', d: 'M46 22 L58 30 L46 38 Z' },
      ];
    default:
      return [];
  }
}

/**
 * Forja el sello rúnico de una casa o de un linaje.
 *
 * @param {object} options
 * @param {string} options.houseName            Nombre canónico de la casa (o del linaje).
 * @param {string} [options.coatOfArms]         Identificador de blasón: se codifica, jamás se imprime.
 * @param {string} [options.rulingElement]      Afinidad elemental rectora (fire, water, …).
 * @param {string} [options.lineageName]        Título ceremonial del linaje, para la etiqueta accesible.
 * @param {string} [options.state]              Estado heráldico: active | regent | archived.
 * @param {'house'|'lineage'} [options.role]    Naturaleza de la marca.
 * @param {Document} [options.document]         Documento anfitrión (inyectable en los arneses).
 * @returns {SVGElement}
 */
export function createRuneSeal({
  houseName = '',
  coatOfArms = '',
  rulingElement = '',
  lineageName = '',
  state = RUNE_SEAL_STATES.ACTIVE,
  role = 'house',
  document: hostDocument = globalThis.document,
} = {}) {
  const documentRef = hostDocument;
  const sealState = Object.values(RUNE_SEAL_STATES).includes(state)
    ? state
    : RUNE_SEAL_STATES.ACTIVE;
  const charge = LINEAGE_CHARGES[rulingElement] ?? FALLBACK_CHARGE;
  // El color de la carga sale de la afinidad rectora, jamás del estandarte: el
  // sello vive sobre tinta y su paleta está medida contra ella.
  const chargeColor = RULING_ELEMENT_COLORS[rulingElement] ?? 'var(--sigil-tick)';

  // El metal del anillo: oro antiguo, oro vivo del regente o bronce de la casa
  // disuelta. Viaja como Custom Property: la paleta vive en tokens.css.
  const metal = sealState === RUNE_SEAL_STATES.REGENT
    ? 'var(--sigil-ring-regent)'
    : sealState === RUNE_SEAL_STATES.ARCHIVED
      ? 'var(--sigil-ring-archived)'
      : 'var(--sigil-ring-active)';

  const seal = documentRef.createElementNS(SVG_NAMESPACE, 'svg');
  seal.setAttribute('viewBox', '0 0 100 100');
  seal.setAttribute('class', 'rune-seal');
  seal.setAttribute('role', 'img');
  seal.setAttribute('focusable', 'false');
  seal.setAttribute('data-heraldic-state', sealState);
  seal.setAttribute('data-heraldic-charge', charge);
  seal.setAttribute('aria-label', runeSealLabel({ houseName, lineageName, state: sealState, role }));

  const appendChild = (tagName, attributes) => {
    const node = documentRef.createElementNS(SVG_NAMESPACE, tagName);
    for (const [name, value] of Object.entries(attributes)) {
      node.setAttribute(name, String(value));
    }
    seal.appendChild(node);
    return node;
  };

  // El disco de tinta: la casa disuelta lo apaga, nunca lo pierde.
  appendChild('circle', {
    cx: 50, cy: 50, r: 46,
    fill: 'var(--sigil-disc)',
    ...(sealState === RUNE_SEAL_STATES.ARCHIVED ? { opacity: 0.82 } : {}),
  });

  // El anillo: roto en su base cuando la casa yace disuelta.
  if (sealState === RUNE_SEAL_STATES.ARCHIVED) {
    appendChild('path', {
      d: 'M8 50 A42 42 0 1 1 92 50',
      fill: 'none', stroke: metal, 'stroke-width': 2.4, 'stroke-linecap': 'round',
    });
  } else {
    appendChild('circle', {
      cx: 50, cy: 50, r: 42, fill: 'none', stroke: metal, 'stroke-width': 2.4,
    });
  }

  // Las ocho muescas: la casa escrita en su propio anillo.
  const hash = runeSealHash(coatOfArms !== '' ? coatOfArms : houseName);
  const masterSector = (hash >>> 16) % 8;
  for (let sector = 0; sector < 8; sector += 1) {
    const bits = (hash >>> (sector * 2)) & 0b11;
    const isMaster = sector === masterSector;
    const length = (4 + bits * 2.5) * (isMaster ? 1.55 : 1);
    const radians = (sector * 45 + 22.5) * Math.PI / 180;
    const inner = 31;
    const outer = inner + length;
    const x1 = (50 + Math.cos(radians) * inner).toFixed(1);
    const y1 = (50 + Math.sin(radians) * inner).toFixed(1);
    const x2 = Number((50 + Math.cos(radians) * outer).toFixed(1));
    const y2 = Number((50 + Math.sin(radians) * outer).toFixed(1));

    appendChild('path', {
      d: `M${x1} ${y1} L${x2} ${y2}`,
      stroke: 'var(--sigil-tick)',
      'stroke-width': TALLY_WEIGHTS[bits],
      'stroke-linecap': 'round',
      opacity: 0.88,
      fill: 'none',
    });

    if (isMaster) {
      appendChild('path', {
        d: `M${x2} ${(y2 - 3.4).toFixed(1)} L${(x2 + 3.4).toFixed(1)} ${y2} `
          + `L${x2} ${(y2 + 3.4).toFixed(1)} L${(x2 - 3.4).toFixed(1)} ${y2} Z`,
        fill: metal,
      });
    }
  }

  // La carga central: el linaje rector, dibujado y teñido con su color heráldico.
  for (const trace of chargeTraces(charge)) {
    if (trace.circle) {
      appendChild('circle', {
        ...trace.circle,
        ...(trace.kind === 'fill'
          ? { fill: chargeColor }
          : { fill: 'none', stroke: chargeColor, 'stroke-width': trace.width ?? 4.5 }),
      });
      continue;
    }
    appendChild('path', {
      d: trace.d,
      ...(trace.kind === 'fill'
        ? { fill: chargeColor }
        : { fill: 'none', stroke: chargeColor, 'stroke-width': 4.5, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }),
    });
  }

  // El honor del regente: la cera se PRESIONA sobre el anillo, a las doce.
  if (sealState === RUNE_SEAL_STATES.REGENT) {
    appendChild('circle', { cx: 50, cy: 8, r: 7.5, fill: 'var(--sigil-wax)' });
    appendChild('circle', {
      cx: 50, cy: 8, r: 7.5, fill: 'none', stroke: 'var(--color-ember-red-deep)', 'stroke-width': 1,
    });
    appendChild('path', {
      d: 'M46 8 A4 4 0 0 1 54 8', fill: 'none', stroke: 'var(--sigil-ring-regent)',
      'stroke-width': 1.2, 'stroke-linecap': 'round',
    });
  }

  return seal;
}
