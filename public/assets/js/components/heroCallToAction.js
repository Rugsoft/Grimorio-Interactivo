/**
 * heroCallToAction.js — SPEC-17: «El Botón que No Miente».
 *
 * Resuelve la llamada a la acción del héroe de la portada a partir del estado
 * de sesión, de modo que **cada rótulo nombre un acto que quien lo mira puede
 * realmente realizar**.
 *
 * Por qué existe: `RF-01.4` de SPEC-01 ofrece rotular el botón «Consagrar
 * Linaje» porque entonces consagrar era una elección. SPEC-09 volvió el
 * juramento obligatorio e irreversible en el primer acceso y no enmendó
 * `RF-01.4`. La spec más nueva invalidó a la anterior por omisión, el botón
 * conservó el nombre y perdió el sentido, y acabó abriendo «Cruzar el
 * Umbral» a quien ya lo había cruzado. Esta spec cierra esa costura.
 *
 * Artículo I (Dogma Vanilla): este módulo es una FUNCIÓN PURA. Sin DOM, sin
 * red, sin temporizadores, sin efectos ni estado propio. Por eso puede
 * verificarse en un arnés sin navegador (RNF-17.4), y por eso su resultado no
 * puede depender del reloj.
 *
 * Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 */

/**
 * Acciones que el héroe puede ordenar. Son NOMBRES INTERNOS: viajan en el
 * descriptor y el orquestador los despacha, nunca se muestran. Por eso aquí
 * vale una clave técnica y en los rótulos no (RNF-17.2).
 */
export const HERO_CTA_ACTIONS = Object.freeze({
  /** Abrir el diálogo de acceso y registro, con la intención retenida. */
  OPEN_ACCESS: 'openAccess',
  // Nota (RF-17.3): `openAccess` solo lo usa el estado anónimo. Con sesión ya
  // activa, el Umbral no debe abrirse nunca: el peregrino va derecho a la
  // ceremonia. Ese era el defecto que esta spec vino a cerrar.
  /** Navegar a una vista interna del propio santuario. */
  NAVIGATE: 'navigate',
});

/**
 * Los cinco estados del CTA y lo que cada uno declara.
 *
 * Los tres primeros son llamadas a la acción reales. Los dos últimos son
 * ausencia declarada: `label: null` NO es un descriptor a medio rellenar,
 * es la afirmación de que no hay nada que ofrecer. El héroe no pinta un
 * botón deshabilitado ni reserva hueco (RF-17.4), porque un rótulo que
 * anuncia un hecho no es una llamada a la acción.
 *
 * @type {Readonly<Object.<string, Readonly<object>>>}
 */
export const HERO_CTA_STATES = Object.freeze({
  /** Sin sesión: no ha elegido nada, así que no se le promete una elección. */
  anonymous: Object.freeze({
    state: 'anonymous',
    label: 'Cruzar el Umbral',
    // WCAG 2.5.3: la etiqueta contiene el texto visible y añade el efecto
    // real. No nombra «umbral de acceso» porque el diálogo de acceso no es un
    // umbral: es un formulario.
    ariaLabel:
      'Cruzar el Umbral: abre el diálogo de acceso y registro. Al vincularte, jurarás tu linaje en la ceremonia.',
    action: HERO_CTA_ACTIONS.OPEN_ACCESS,
    target: null,
  }),

  /** Sesión viva sin juramento: el juramento es lo único que le queda. */
  pilgrim: Object.freeze({
    state: 'pilgrim',
    label: 'Consagrar Linaje',
    // El peregrino YA está dentro: no se le manda a cruzar un umbral que
    // tiene atrás, sino directamente a la ceremonia (RF-17.3). Es también lo
    // que evita el callejón sin salida que motivó esta spec.
    ariaLabel:
      'Consagrar Linaje: abre la ceremonia en la que jurarás tu linaje de forma irrevocable.',
    action: HERO_CTA_ACTIONS.NAVIGATE,
    target: 'juramento',
  }),

  /** Ya jurado, sin hermandad: el acto que le queda abierto es otro. */
  swornLoose: Object.freeze({
    state: 'swornLoose',
    label: 'Vincularse a una Hermandad',
    ariaLabel:
      'Vincularse a una Hermandad: abre el Vestíbulo, donde puedes pedir el ingreso en una casa de tu linaje.',
    action: HERO_CTA_ACTIONS.NAVIGATE,
    target: 'vestibule',
  }),

  /** Jurado y con hermandad: no le queda nada pendiente. */
  swornBound: Object.freeze({
    state: 'swornBound',
    label: null,
    ariaLabel: null,
    action: null,
    target: null,
  }),

  /** El custodio del Dominio no es un adepto: el heroism no le compete. */
  regent: Object.freeze({
    state: 'regent',
    label: null,
    ariaLabel: null,
    action: null,
    target: null,
  }),
});

/**
 * ¿La sesión porta un linaje efectivamente jurado?
 *
 * Solo cuenta una cadena NO VACÍA. La cadena vacía es la forma más sutil de
 * perder al peregrino: si se tomara por juramento, el héroe dejaría de
 * ofrecerle la ceremonia que SPEC-09 le impone, y su única salida sería un
 * enlace que no existe.
 *
 * @param {unknown} lineage Valor crudo del sobre de sesión.
 * @returns {boolean} `true` solo si hay linaje jurado.
 */
function hasSwornLineage(lineage) {
  return typeof lineage === 'string' && lineage.trim() !== '';
}

/**
 * Resuelve la llamada a la acción del héroe a partir del estado de sesión.
 *
 * El orden de evaluación importa y es parte del contrato (RF-17.1): gana el
 * PRIMER estado que aplica.
 *
 *  1. **Custodio** — se evalúa antes que nada, incluso antes que la
 *     autenticación, porque el Regente puede estar vinculado y jurado y,
 *     sin este orden, recibiría una llamada a vincularse a una hermandad que
 *     como custodio no puede usar.
 *  2. **Anónimo** — antes que cualquier examen del clan, porque un `userClan`
 *     residual en una sesión cerrada es basura de estado, no una hermandad.
 *  3. **Peregrino** — antes que el examen de la hermandad por el mismo
 *     motivo: un peregrino no puede vincularse a ninguna casa.
 *  4. **Jurado suelto** — el linaje ya está; falta la hermandad.
 *  5. **Jurado vinculado** — nada pendiente: se declara la ausencia.
 *
 * @param {object|null|undefined} sessionState Estado global (`store`): se
 *        leen `isAuthenticated`, `userRole`, `userLineage` y `userClan`.
 *        Ausente o corrupto degrada al anónimo, que es el único estado que
 *        nunca promete nada que el sistema no haga.
 * @returns {Readonly<object>|null} Descriptor del CTA, o `null` cuando no
 *          hay llamada a la acción que hacer (RF-17.4).
 */
export function resolveHeroCallToAction(sessionState) {
  // Una sesión ausente no puede reventar la portada: sin sesión no hay
  // misterio de estado que examinar, y el anónimo es su degradación natural.
  const session = typeof sessionState === 'object' && sessionState !== null
    ? sessionState
    : { isAuthenticated: false, userRole: 'reader', userClan: null, userLineage: null };

  // 1. El custodio del Dominio no es un adepto. Se resuelve primero.
  if (session.userRole === 'supremeAdmin') {
    return null;
  }

  // 2. Sin sesión no hay acto propio que ofrecer.
  if (session.isAuthenticated !== true) {
    return HERO_CTA_STATES.anonymous;
  }

  // 3. Sin juramento, la ceremonia es lo único que le corresponde.
  if (!hasSwornLineage(session.userLineage)) {
    return HERO_CTA_STATES.pilgrim;
  }

  // 4. Jurado y sin hermandad: el acto abierto es la adhesión (SPEC-10).
  if (session.userClan === null || session.userClan === undefined) {
    return HERO_CTA_STATES.swornLoose;
  }

  // 5. Jurado y vinculado: no queda nada que ofrecer, y eso se declara.
  return null;
}
