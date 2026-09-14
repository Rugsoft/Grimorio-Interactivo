/**
 * convalescenceBannerComponent.js — Aviso solemne de Convalecencia Arcana.
 *
 * Tarea 5.3 (TASKS-07). RF-01.6, RF-01.7, RNF-03.
 *
 * Un mago que abandona su casa o es expulsado por el Patriarca purga catorce
 * (14) días naturales de meditación obligatoria; mientras la purga, el
 * santuario le veda fundar una hermandad y postular a otra (RF-01.6) y su
 * condición se exhibe con claridad en su perfil (RF-01.7). Este componente
 * pinta ese aviso y aplica el veto sobre las acciones de afiliación.
 *
 * Contrato de datos: el ClanMemberDto que el santuario sirve al cerrar una
 * membresía (Endpoints 7 y 8 del plan: `data.convalescenceExpiresAt`, marca
 * ISO 8601 UTC del fin de los catorce días). El mismo sobre llega dirigido
 * por eventos (plan 4.1): `clan:convalescence-started` lo despliega y
 * `clan:member-joined` / `clan:created` lo retiran, porque quien vuelve a
 * militar ya no purga. El componente JAMÁS consulta la API por su cuenta: su
 * perfil se lo entrega el orquestador.
 *
 * Aritmética: los días restantes se alzan al día entero inmediato superior
 * (`ceil`), en espejo exacto del cómputo del backend
 * (`ClanMemberDto::convalescenceDaysRemaining`), de modo que dos horas de
 * purga cuentan como un día de meditación y la frontera es inclusiva: en el
 * instante exacto de la expiración la convalecencia ha terminado.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM nativo, cero librerías; `textContent`
 *     puro e `innerHTML` PROHIBIDO (AGENTS.md 6.1) — el alias y el nombre de
 *     la casa abandonada son datos de usuario y viajan como texto literal.
 *   - Artículo IV/V: leyendas solemnes en castellano; identificadores en
 *     inglés camelCase; comentarios en castellano.
 *   - RNF-01 (Determinismo): jamás se lee el reloj del sistema por sorpresa;
 *     el instante se inyecta (`now`) y en producción es la única lectura.
 *   - RNF-02 (Dogma Vanilla): sin sondeo periódico; la cuenta se refresca
 *     cuando el orquestador vuelve a montar el aviso.
 */

/** Duración canónica del descanso obligatorio en días naturales (RF-01.6). */
export const CONVALESCENCE_DAYS = 14;

/** Título ceremonial del aviso. */
export const CONVALESCENCE_TITLE = 'Convalecencia Arcana';

/** Leyenda del bloqueo de afiliación mientras dure la purga (RF-01.6). */
export const AFFILIATION_VETO_LEGEND =
  'Mientras dure la meditación no podrás fundar una hermandad ni postular a otra casa.';

/** Marca del veto en el DOM; las vistas pueden consultarla sin re-calcular. */
export const AFFILIATION_VETO_MARK = 'convalescence';

/** Suceso del bus que despliega el aviso (plan 4.1). */
export const CONVALESCENCE_STARTED_EVENT = 'clan:convalescence-started';

/** Sucesos que retiran el aviso: quien vuelve a militar ya no purga. */
export const CONVALESCENCE_ENDED_EVENTS = Object.freeze(['clan:member-joined', 'clan:created']);

/** Milisegundos de un día natural: la unidad del cómputo (RNF-01). */
const MILLISECONDS_PER_DAY = 86400000;

/**
 * Interpreta una marca de instante como milisegundos desde la época.
 *
 * Se admiten las tres formas que maneja el santuario: la marca ISO 8601 UTC
 * (`2026-09-15T12:00:00Z`), su variante con desplazamiento y una fecha
 * nativa o numérica ya resuelta (arneses).
 *
 * @param {Date|number|string|null} instant Instante a interpretar.
 * @returns {number|null} Milisegundos, o `null` si no es interpretable.
 */
function toMillis(instant) {
  if (instant === null || instant === undefined) return null;
  if (instant instanceof Date) {
    const time = instant.getTime();
    return Number.isNaN(time) ? null : time;
  }
  if (typeof instant === 'number') return Number.isFinite(instant) ? instant : null;
  const parsed = Date.parse(String(instant).trim());
  return Number.isFinite(parsed) ? parsed : null;
}

/**
 * Días naturales de convalecencia restantes (espejo del DTO del backend).
 *
 * @param {string|null} expiresAt Marca ISO 8601 UTC del fin de la purga.
 * @param {Date|number|string} nowUtc Instante de consulta (inyectado).
 * @returns {number|null} Días restantes alzados al entero superior, o `null`
 *          si no media convalecencia (marca ausente, ilegible o ya vencida).
 */
export function convalescenceDaysRemaining(expiresAt, nowUtc) {
  const expiry = toMillis(expiresAt);
  const now = toMillis(nowUtc);
  if (expiry === null || now === null || expiry <= now) return null;

  return Math.ceil((expiry - now) / MILLISECONDS_PER_DAY);
}

/**
 * ¿Sigue el mago purgando su convalecencia en el instante dado? (RF-01.6)
 *
 * @param {string|null} expiresAt Marca ISO 8601 UTC del fin de la purga.
 * @param {Date|number|string} nowUtc Instante de consulta.
 * @returns {boolean} `true` mientras la marca sea futura.
 */
export function isInConvalescence(expiresAt, nowUtc) {
  return convalescenceDaysRemaining(expiresAt, nowUtc) !== null;
}

/**
 * Leyenda ceremonial del contador: «En Convalecencia Arcana: restan X días de
 * meditación» (RF-01.7), con la concordancia del singular en el último día.
 *
 * @param {number} days Días naturales restantes.
 * @returns {string} Leyenda en noble castellano.
 */
export function convalescenceLegend(days) {
  const count = Number.isInteger(days) && days > 0 ? days : 0;

  return count === 1
    ? 'En Convalecencia Arcana: resta 1 día de meditación'
    : `En Convalecencia Arcana: restan ${count} días de meditación`;
}

/**
 * Crea el componente del aviso de Convalecencia Arcana.
 *
 * @param {HTMLElement} mountRoot Contenedor del perfil del mago (en el shell,
 *        la franja de avisos de la cabecera, junto al distintivo de sesión).
 * @param {Object} options
 * @param {() => Date|number|string} [options.now] Reloj inyectable (arneses).
 * @param {(tagName: string) => HTMLElement} [options.elementFactory] Fábrica
 *        inyectable (arneses sin navegador).
 * @param {EventTarget} [options.bus] Bus de eventos del plan 4.1; suscrito a
 *        `clan:convalescence-started` / `clan:member-joined` / `clan:created`.
 * @param {HTMLElement|HTMLElement[]|(() => HTMLElement[])} [options.affiliationControls]
 *        Acciones de afiliación (fundar / postular) sometidas al veto.
 * @param {(control: HTMLElement) => void} [options.onVetoedAttempt] Se invoca
 *        cuando alguien pulsa una acción vedada (el orquestador puede elevar
 *        el aviso solemne sin que la acción llegue a ejecutarse).
 * @returns {Object} API: { render, setConvalescence, clear, isActive,
 *          daysRemaining, setAffiliationControls, getAffiliationControls,
 *          destroy }.
 */
export function createConvalescenceBannerComponent(mountRoot, options = {}) {
  const {
    now = () => Date.now(),
    elementFactory = (tagName) => globalThis.document?.createElement?.(tagName) ?? null,
    bus = null,
    affiliationControls = null,
    onVetoedAttempt = null,
  } = options;

  /** Nodos vivos del aviso, para limpieza determinista. */
  let mountedNodes = [];

  /** Días restantes del aviso vigente (`null` = sin convalecencia). */
  let activeDays = null;

  /** Acciones de afiliación registradas en el componente. */
  let vetoTargets = [];

  /** Estado previo de cada acción vedada: control → { hadDisabled, previousTitle, listener }. */
  const controlsUnderVeto = new Map();

  /** El componente quedó destruido: nada vuelve a pintarse ni a vetarse. */
  let isDestroyed = false;

  /** El bus ya está suscrito (una sola vez por vida del componente). */
  let isBusBound = false;

  // -----------------------------------------------------------------
  // Ciclo de vida del aviso
  // -----------------------------------------------------------------

  /** Retira del árbol los nodos del aviso previo (re-render idempotente). */
  function clearPainting() {
    for (const node of mountedNodes) {
      node?.remove?.();
    }
    mountedNodes = [];
  }

  /** Añade un nodo de texto seguro (nunca innerHTML, AGENTS.md 6.1). */
  function appendTextElement(parent, tagName, className, text) {
    const node = elementFactory(tagName);
    if (node === null) return null;
    if (className !== null) node.className = className;
    node.textContent = text;
    parent.appendChild(node);
    mountedNodes.push(node);
    return node;
  }

  /** Clave ceremonial del mago en purga: alias y casa abandonada, si constan. */
  function describePenitent(payload) {
    const alias = typeof payload.userAlias === 'string' ? payload.userAlias.trim() : '';
    const clanName = typeof payload.clanName === 'string' ? payload.clanName.trim() : '';

    if (alias !== '' && clanName !== '') {
      return `El mago ${alias} medita su retorno tras partir de la casa «${clanName}».`;
    }
    if (alias !== '') {
      return `El mago ${alias} medita su retorno a la comunidad.`;
    }

    return 'Este mago medita su retorno a la comunidad antes de jurar un nuevo estandarte.';
  }

  /**
   * Pinta el aviso del perfil con su contador y su barra decreciente.
   *
   * @param {object} payload Sobre normalizado: { expiresAt, userAlias, clanName }.
   * @param {number} days Días naturales restantes.
   */
  function paintBanner(payload, days) {
    const banner = elementFactory('aside');
    if (banner === null) return;
    banner.className = 'convalescence-banner';
    banner.setAttribute('id', 'convalescenceBanner');
    // Región con nombre accesible: se anuncia correctamente (RNF-03).
    banner.setAttribute('role', 'region');
    banner.setAttribute('aria-labelledby', 'convalescenceBannerTitle');
    banner.setAttribute('data-days-remaining', String(days));
    banner.setAttribute('data-convalescence-expires-at', String(payload.expiresAt ?? ''));
    mountRoot.appendChild(banner);
    mountedNodes.push(banner);

    const title = appendTextElement(banner, 'h2', 'convalescence-banner__title', CONVALESCENCE_TITLE);
    title?.setAttribute('id', 'convalescenceBannerTitle');

    // Leyenda del contador: la voz de RF-01.7 en el perfil.
    const legend = appendTextElement(
      banner,
      'p',
      'convalescence-banner__legend',
      convalescenceLegend(days),
    );
    legend?.setAttribute('role', 'status');
    legend?.setAttribute('aria-live', 'polite');
    legend?.setAttribute('aria-atomic', 'true');

    appendTextElement(banner, 'p', 'convalescence-banner__penitent', describePenitent(payload));

    // Barra decreciente: de 14 días a 0 (el descanso que se agota).
    const ratio = Math.min(Math.max(days / CONVALESCENCE_DAYS, 0), 1);
    const progress = elementFactory('div');
    if (progress !== null) {
      progress.className = 'convalescence-banner__progress';
      progress.setAttribute('role', 'progressbar');
      progress.setAttribute('aria-valuemin', '0');
      progress.setAttribute('aria-valuemax', String(CONVALESCENCE_DAYS));
      progress.setAttribute('aria-valuenow', String(days));
      progress.setAttribute(
        'aria-label',
        `Días de meditación restantes: ${days} de ${CONVALESCENCE_DAYS}`,
      );
      // El ancho viaja como custom property: la paleta y el trazo viven en CSS.
      progress.setAttribute('style', `--convalescence-progress: ${Math.round(ratio * 100)}%`);
      banner.appendChild(progress);
      mountedNodes.push(progress);

      const fill = elementFactory('span');
      if (fill !== null) {
        fill.className = 'convalescence-banner__progress-fill';
        // Ornamento: su medida ya viaja en el valor accesible de la barra.
        fill.setAttribute('aria-hidden', 'true');
        progress.appendChild(fill);
        mountedNodes.push(fill);
      }
    }

    appendTextElement(banner, 'p', 'convalescence-banner__veto', AFFILIATION_VETO_LEGEND);
  }

  // -----------------------------------------------------------------
  // Veto de afiliación (RF-01.6)
  // -----------------------------------------------------------------

  /** Normaliza las acciones de afiliación declaradas por el orquestador. */
  function normalizeControls(source) {
    if (source === null || source === undefined) return [];
    const resolved = typeof source === 'function' ? source() : source;
    if (Array.isArray(resolved)) return resolved.filter((control) => control !== null && control !== undefined);
    return [resolved];
  }

  /** Veda una acción de afiliación conservando su estado previo. */
  function applyVetoTo(control) {
    if (controlsUnderVeto.has(control)) return;

    const hadDisabled = typeof control.hasAttribute === 'function'
      ? control.hasAttribute('disabled') === true
      : control.disabled === true;
    const previousTitle = typeof control.getAttribute === 'function'
      ? control.getAttribute('title')
      : null;

    // El veto intercepta el clic: la acción vedada jamás se ejecuta.
    const listener = (event) => {
      event?.preventDefault?.();
      event?.stopPropagation?.();
      if (typeof onVetoedAttempt === 'function') onVetoedAttempt(control);
    };

    controlsUnderVeto.set(control, { hadDisabled, previousTitle, listener });
    control.disabled = true;
    control.setAttribute?.('disabled', '');
    control.setAttribute?.('aria-disabled', 'true');
    control.setAttribute?.('data-affiliation-veto', AFFILIATION_VETO_MARK);
    control.setAttribute?.('title', AFFILIATION_VETO_LEGEND);
    control.addEventListener?.('click', listener);
  }

  /** Levanta el veto y restituye el estado previo de cada acción. */
  function releaseAffiliationControls() {
    for (const [control, veto] of controlsUnderVeto) {
      control.removeEventListener?.('click', veto.listener);
      control.removeAttribute?.('aria-disabled');
      control.removeAttribute?.('data-affiliation-veto');
      if (veto.previousTitle === null || veto.previousTitle === undefined) {
        control.removeAttribute?.('title');
      } else {
        control.setAttribute?.('title', veto.previousTitle);
      }
      // Solo se libera lo que este componente vedó.
      if (veto.hadDisabled !== true) {
        control.disabled = false;
        control.removeAttribute?.('disabled');
      }
    }
    controlsUnderVeto.clear();
  }

  /** Aplica el veto a todas las acciones registradas (si hay purga vigente). */
  function applyAffiliationVeto() {
    for (const control of vetoTargets) {
      applyVetoTo(control);
    }
  }

  /**
   * Declara las acciones de afiliación sometidas al veto. Si ya hay una
   * convalecencia vigente, el veto se aplica de inmediato.
   *
   * @param {HTMLElement|HTMLElement[]|(() => HTMLElement[])} controls Acciones.
   */
  function setAffiliationControls(controls) {
    if (isDestroyed) return;
    releaseAffiliationControls();
    vetoTargets = normalizeControls(controls);
    if (activeDays !== null) applyAffiliationVeto();
  }

  /** Devuelve las acciones registradas (para inspección de las vistas). */
  function getAffiliationControls() {
    return [...vetoTargets];
  }

  // -----------------------------------------------------------------
  // Entrada de datos: perfil, eventos y limpieza
  // -----------------------------------------------------------------

  /** Normaliza el sobre de convalecencia (ClanMemberDto o sesión). */
  function normalizePayload(source) {
    if (source === null || typeof source !== 'object') {
      return { expiresAt: null, userAlias: '', clanName: '' };
    }

    return {
      expiresAt: source.convalescenceExpiresAt ?? null,
      userAlias: source.userAlias ?? source.alias ?? '',
      clanName: source.clanName ?? '',
    };
  }

  /**
   * Despliega (o retira) el aviso según el sobre de convalecencia.
   *
   * @param {object|null} source ClanMemberDto del mago, o sobre de sesión.
   * @returns {boolean} `true` si el aviso quedó desplegado con purga vigente.
   */
  function setConvalescence(source) {
    if (isDestroyed) return false;

    releaseAffiliationControls();
    clearPainting();
    activeDays = null;

    const payload = normalizePayload(source);
    const days = convalescenceDaysRemaining(payload.expiresAt, now());
    if (days === null) return false;

    activeDays = days;
    paintBanner(payload, days);
    applyAffiliationVeto();

    return true;
  }

  /** Retira el aviso y levanta el veto (el mago ha vuelto a la comunidad). */
  function clear() {
    if (isDestroyed) return;
    releaseAffiliationControls();
    clearPainting();
    activeDays = null;
  }

  /** ¿Está el perfil en Convalecencia Arcana ahora mismo? */
  function isActive() {
    return activeDays !== null;
  }

  /** Días naturales restantes del aviso vigente (`null` si no lo hay). */
  function daysRemaining() {
    return activeDays;
  }

  // -----------------------------------------------------------------
  // Bus de eventos (plan 4.1)
  // -----------------------------------------------------------------

  /** `clan:convalescence-started`: el cierre de una membresía abre la purga. */
  function handleConvalescenceStarted(event) {
    // Un EventTarget nativo porta el sobre en `detail`; un bus simulado
    // puede entregarlo directamente.
    setConvalescence(event?.detail ?? event ?? null);
  }

  /** `clan:member-joined` / `clan:created`: el mago ya milita en otra casa. */
  function handleConvalescenceEnded() {
    clear();
  }

  /** Suscribe el componente al bus una sola vez (idempotente). */
  function bindBus() {
    if (isBusBound || isDestroyed) return;
    if (bus === null || typeof bus.addEventListener !== 'function') return;

    isBusBound = true;
    bus.addEventListener(CONVALESCENCE_STARTED_EVENT, handleConvalescenceStarted);
    for (const eventName of CONVALESCENCE_ENDED_EVENTS) {
      bus.addEventListener(eventName, handleConvalescenceEnded);
    }
  }

  /** Desuscribe el componente del bus (destroy). */
  function unbindBus() {
    if (!isBusBound || bus === null || typeof bus.removeEventListener !== 'function') return;

    bus.removeEventListener(CONVALESCENCE_STARTED_EVENT, handleConvalescenceStarted);
    for (const eventName of CONVALESCENCE_ENDED_EVENTS) {
      bus.removeEventListener(eventName, handleConvalescenceEnded);
    }
  }

  /**
   * Monta el aviso desde un sobre ya conocido. Equivale a setConvalescence:
   * se expone con ambos nombres porque las vistas llaman `render` y los
   * eventos llaman `setConvalescence`.
   *
   * @param {object|null} source Sobre de convalecencia.
   * @returns {boolean} `true` si el aviso quedó desplegado.
   */
  function render(source) {
    return setConvalescence(source);
  }

  /** Retira el aviso, levanta el veto y abandona el bus. */
  function destroy() {
    if (isDestroyed) return;
    isDestroyed = true;
    releaseAffiliationControls();
    clearPainting();
    unbindBus();
    activeDays = null;
    vetoTargets = [];
  }

  // Montaje inicial: las acciones declaradas quedan registradas (sin veto
  // hasta que una convalecencia real se despliegue) y el bus queda suscrito.
  setAffiliationControls(affiliationControls);
  bindBus();

  return {
    render,
    setConvalescence,
    clear,
    isActive,
    daysRemaining,
    setAffiliationControls,
    getAffiliationControls,
    destroy,
  };
}
