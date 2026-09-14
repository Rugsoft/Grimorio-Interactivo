/**
 * elementalAuraComponent.js — Halo de aura elemental del maniquí (SPEC-06).
 *
 * Tarea 3.1 (TASKS-06): renderiza sobre el blanco imbuido el halo luminoso
 * pulsante del color heráldico del elemento activo (RF-02.2) y el anillo
 * rúnico circular que decrece durante la ventana de resonancia de cinco
 * segundos (RF-02.1), con refresco homogéneo (RF-02.4) y disipación suave
 * al expirar (RF-02.5). Al expirar de forma natural emite el evento
 * `combo:aura-expired {element}` del plan 4.1.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): módulo ES nativo; SVG y Custom Properties
 *     nativas; documento, reloj, planificador de cuadros y bus de eventos
 *     llegan inyectados. Cero librerías.
 *   - Artículo II: los colores heráldicos se LEEN del Códice (matriz espejo
 *     de comboResolver.js); el componente no decide colores.
 *   - Artículo IV: sin texto visible propio más allá de lo semántico
 *     (aria-hidden: el aura es decorativa; los anuncios los hacen otros).
 *   - Artículo V: identificadores en inglés camelCase; documentación en
 *     castellano.
 *
 * Estructura DOM (raíz .elemental-aura):
 *   <div class="elemental-aura elemental-aura--active elemental-aura--pulse" aria-hidden="true">
 *     <svg> … <circle class="elemental-aura__ring-progress"> … </svg>
 *   </div>
 * La variable CSS --aura-color porta el color heráldico; los estilos viven
 * en public/assets/css/components/elemental-codex.css.
 *
 * Cobertura: RF-02.1, RF-02.2, RF-02.4, RF-02.5, RNF-03 (movimiento
 * reducido: sin pulso decorativo; el anillo informativo permanece).
 */

import { ELEMENTAL_MATRIX_ELEMENTS } from '../utils/comboResolver.js';

/** Ventana canónica de resonancia, en ms (RF-02.1). */
export const AURA_RESONANCE_DURATION_MS = 5000;

/** Radio canónico del anillo rúnico (viewBox 100×100). */
const RING_RADIUS = 45;
/** Longitud total del círculo (stroke-dasharray del anillo). */
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS;

/** Ticks del bucle de escena, en ms (≈60 fps sin exigir 60 reales). */
const FRAME_INTERVAL_MS = 16;

/**
 * Crea el componente visual del halo de aura.
 *
 * @param {object} [options]
 *   - document: fábrica DOM inyectable (por defecto, document global).
 *   - now: reloj inyectable (() => ms de época). Por defecto, Date.now.
 *   - scheduleFrame: planificador de cuadros inyectable ((callback) => cancel)
 *     — por defecto, setTimeout con el tick de escena.
 *   - eventTarget: bus de eventos inyectable (por defecto, window). Escucha
 *     combo:aura-applied / combo:aura-refreshed y emite combo:aura-expired.
 *   - prefersReducedMotion: predicate inyectable (() => bool) para RNF-03.
 * @returns {object} API: mount, applyAura, refreshAura, dissipate, clear,
 *   isAuraActive, getActiveElement, getRemainingMs, destroy.
 */
export function createElementalAuraComponent(options = {}) {
  // Fallback al documento global vía globalThis: una const local con el
  // mismo nombre no puede referenciarse a sí misma (TDZ), ni siquiera con
  // typeof, así que el respaldo se lee del objeto global.
  const document = options.document ?? globalThis.document;
  const now = options.now ?? (() => Date.now());
  const scheduleFrame = options.scheduleFrame
    ?? ((callback) => {
      const timerId = setTimeout(() => callback(), FRAME_INTERVAL_MS);
      return () => clearTimeout(timerId);
    });
  const eventTarget = options.eventTarget ?? window;
  const prefersReducedMotion = options.prefersReducedMotion ?? (() =>
    typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

  /** @type {Element|null} Raíz del aura, montada por mount(). */
  let auraRoot = null;
  /** Elemento, o null si el blanco está neutral. */
  let activeElement = null;
  /** Instante de expiración de la ventana vigente. */
  let expiresAt = 0;
  /** Cancelador del cuadro programado. */
  let cancelFrame = null;
  /** ¿Se emitió ya la expiración natural de la ventana vigente? */
  let expiryAnnounced = true;

  /** Escuchas del bus (dadas de baja en destroy()). */
  const onAuraApplied = (event) => {
    const element = event.detail?.element;
    if (typeof element === 'string' && element !== '') {
      applyAura(element, event.detail?.expiresAt);
    }
  };
  const onAuraRefreshed = (event) => {
    const element = event.detail?.element;
    if (typeof element === 'string' && element !== '') {
      refreshAura(event.detail?.expiresAt);
    }
  };

  /**
   * Color heráldico del elemento leído del Códice (blanco si es ajeno).
   * @param {string} elementId
   */
  function heraldicColorOf(elementId) {
    return ELEMENTAL_MATRIX_ELEMENTS.find((element) => element.id === elementId)?.color ?? '#ffffff';
  }

  /**
   * Construye la estructura DOM del aura (idempotente).
   */
  function ensureRoot() {
    if (auraRoot !== null) {
      return auraRoot;
    }

    const svgNamespace = 'http://www.w3.org/2000/svg';
    // Degradación grácil: los entornos sin soporte SVG (arneses sin DOM
    // real) fabrican los nodos por la vía plana; el navegador siempre
    // dispone de createElementNS y preserva el espacio de nombres.
    const svgFactory = document.createElementNS?.bind(document)
      ?? document.createElement.bind(document);
    const svg = svgFactory(svgNamespace, 'svg');
    svg.setAttribute('viewBox', '0 0 100 100');
    svg.setAttribute('class', 'elemental-aura__svg');

    // Anillo de fondo (surco rúnico sobre el que decrece el progreso).
    const ringTrack = svgFactory(svgNamespace, 'circle');
    ringTrack.setAttribute('cx', '50');
    ringTrack.setAttribute('cy', '50');
    ringTrack.setAttribute('r', String(RING_RADIUS));
    ringTrack.setAttribute('class', 'elemental-aura__ring-track');
    svg.appendChild(ringTrack);

    // Anillo de progreso: decrece consumiendo su trazo (RF-02.2).
    const ringProgress = svgFactory(svgNamespace, 'circle');
    ringProgress.setAttribute('cx', '50');
    ringProgress.setAttribute('cy', '50');
    ringProgress.setAttribute('r', String(RING_RADIUS));
    ringProgress.setAttribute('class', 'elemental-aura__ring-progress');
    ringProgress.setAttribute('stroke-dasharray', String(RING_CIRCUMFERENCE));
    ringProgress.setAttribute('stroke-dashoffset', '0');
    svg.appendChild(ringProgress);

    // Halo pulsante (disco interior).
    const halo = svgFactory(svgNamespace, 'circle');
    halo.setAttribute('cx', '50');
    halo.setAttribute('cy', '50');
    halo.setAttribute('r', '38');
    halo.setAttribute('class', 'elemental-aura__halo');
    svg.appendChild(halo);

    auraRoot = document.createElement('div');
    auraRoot.setAttribute('class', 'elemental-aura');
    auraRoot.setAttribute('aria-hidden', 'true'); // Decorativa: los anuncios los hace la región viva.
    auraRoot.appendChild(svg);
    // La raíz nace oculta: se exhibe al imbuir.
    auraRoot.setAttribute('hidden', '');

    return auraRoot;
  }

  /**
   * Pinta el estado de la ventana sobre la raíz (clases, color y anillo).
   */
  function render() {
    if (auraRoot === null) {
      return;
    }

    const remainingMs = getRemainingMs();
    const progress = Math.max(0, Math.min(1, remainingMs / AURA_RESONANCE_DURATION_MS));
    // Degradación grácil: entornos sin búsqueda por selectores (arneses sin
    // DOM real) pierden solo el repintado del anillo; el estado elemental
    // jamás depende de él.
    if (typeof auraRoot.querySelector === 'function') {
      const ringProgress = auraRoot.querySelector('.elemental-aura__ring-progress');
      if (ringProgress !== null) {
        ringProgress.setAttribute('stroke-dashoffset', String(RING_CIRCUMFERENCE * (1 - progress)));
      }
    }

    if (activeElement !== null && remainingMs > 0) {
      auraRoot.removeAttribute('hidden');
      auraRoot.classList.add('elemental-aura--active');
      auraRoot.classList.remove('elemental-aura--fading');
      // RNF-03: sin pulso decorativo bajo movimiento reducido.
      if (prefersReducedMotion()) {
        auraRoot.classList.remove('elemental-aura--pulse');
      } else {
        auraRoot.classList.add('elemental-aura--pulse');
      }
      auraRoot.style.setProperty('--aura-color', heraldicColorOf(activeElement));
    } else if (activeElement === null) {
      // Durante el desvanecimiento (clase --fading) la raíz permanece
      // visible hasta que el CSS concluye la transición: no se oculta ni
      // se despega la clase de desvanecimiento (RF-02.5, disolución suave).
      if (!auraRoot.classList.contains('elemental-aura--fading')) {
        auraRoot.setAttribute('hidden', '');
        auraRoot.classList.remove('elemental-aura--active', 'elemental-aura--pulse');
      }
    }
  }

  /**
   * Apaga el bucle de escena si la ventana ya no vive.
   */
  function stopSceneIfIdle() {
    if (activeElement === null && cancelFrame !== null) {
      cancelFrame();
      cancelFrame = null;
    }
  }

  /**
   * Bucle de escena: repinta el anillo y gobierna la expiración natural.
   */
  function sceneTick() {
    cancelFrame = null;
    if (activeElement === null) {
      return;
    }

    if (now() >= expiresAt && !expiryAnnounced) {
      // Expiración natural (RF-02.5): disolución suave y anuncio único.
      expiryAnnounced = true;
      const expiredElement = activeElement;
      activeElement = null;
      if (auraRoot !== null) {
        auraRoot.classList.remove('elemental-aura--active', 'elemental-aura--pulse');
        auraRoot.classList.add('elemental-aura--fading'); // El CSS la desvanece y la oculta al terminar.
      }
      eventTarget.dispatchEvent(new CustomEvent('combo:aura-expired', { detail: { element: expiredElement } }));
      render();
      stopSceneIfIdle();
      return;
    }

    render();
    cancelFrame = scheduleFrame(sceneTick);
  }

  /** Arranca el bucle de escena si no está vivo. */
  function ensureSceneRunning() {
    if (cancelFrame === null) {
      cancelFrame = scheduleFrame(sceneTick);
    }
  }

  /**
   * Monta la raíz del aura en el anfitrión (el contenedor del maniquí).
   * Idempotente: montar dos veces no duplica la raíz.
   * @param {Element} hostElement
   */
  function mount(hostElement) {
    const root = ensureRoot();
    if (root.parentNode !== hostElement) {
      hostElement.appendChild(root);
    }
    eventTarget.addEventListener('combo:aura-applied', onAuraApplied);
    eventTarget.addEventListener('combo:aura-refreshed', onAuraRefreshed);
  }

  /**
   * Imbuye (o sobreescrive) el aura con el elemento dado (RF-02.1, RF-02.4).
   * El mismo elemento reinicia la ventana; uno distinto la reinicia con
   * nuevo color, sin pasar por expiración.
   * @param {string} elementId Elemento del Códice.
   * @param {number} [forcedExpiresAt] Instante de expiración impuesto (opcional).
   */
  function applyAura(elementId, forcedExpiresAt = null) {
    activeElement = elementId;
    expiresAt = forcedExpiresAt ?? (now() + AURA_RESONANCE_DURATION_MS);
    expiryAnnounced = false;
    render();
    ensureSceneRunning();
  }

  /**
   * Refresca la ventana del aura vigente a 5 s (RF-02.4).
   * @param {number} [forcedExpiresAt] Instante de expiración impuesto (opcional).
   */
  function refreshAura(forcedExpiresAt = null) {
    if (activeElement === null) {
      return;
    }
    expiresAt = forcedExpiresAt ?? (now() + AURA_RESONANCE_DURATION_MS);
    expiryAnnounced = false;
    render();
    ensureSceneRunning();
  }

  /**
   * Disipación manual inmediata (Retirar el aura): sin desvanecimiento ni
   * anuncio de expiración (no fue una expiración natural).
   */
  function dissipate() {
    activeElement = null;
    expiryAnnounced = true;
    if (auraRoot !== null) {
      auraRoot.classList.remove('elemental-aura--active', 'elemental-aura--pulse', 'elemental-aura--fading');
      auraRoot.setAttribute('hidden', '');
    }
    stopSceneIfIdle();
  }

  /**
   * Restablece el blanco a neutral (RF-02.6: «Restaurar Maniquí»).
   * Alias semántico de dissipate().
   */
  function clear() {
    dissipate();
  }

  /** ¿Vive el aura en este instante? */
  function isAuraActive() {
    if (activeElement === null) {
      return false;
    }
    if (now() >= expiresAt) {
      return false;
    }
    return true;
  }

  /** Elemento imbuydo (null si neutral). */
  function getActiveElement() {
    return isAuraActive() ? activeElement : null;
  }

  /** Milisegundos restantes de la ventana (0 si no vive). */
  function getRemainingMs() {
    if (activeElement === null) {
      return 0;
    }
    return Math.max(0, expiresAt - now());
  }

  /**
   * Retira el componente: da de baja las escuchas del bus, apaga el bucle
   * y desmonta la raíz.
   */
  function destroy() {
    eventTarget.removeEventListener('combo:aura-applied', onAuraApplied);
    eventTarget.removeEventListener('combo:aura-refreshed', onAuraRefreshed);
    if (cancelFrame !== null) {
      cancelFrame();
      cancelFrame = null;
    }
    activeElement = null;
    if (auraRoot !== null && auraRoot.parentNode !== null) {
      auraRoot.remove();
    }
    auraRoot = null;
  }

  return {
    mount,
    applyAura,
    refreshAura,
    dissipate,
    clear,
    isAuraActive,
    getActiveElement,
    getRemainingMs,
    destroy,
  };
}
