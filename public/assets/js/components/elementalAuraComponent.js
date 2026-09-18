/**
 * elementalAuraComponent.js — Halo de aura elemental del maniquí (SPEC-06).
 *
 * Tarea 3.1 (TASKS-06): renderiza sobre el blanco la capa-luz perenne
 * que abraza su silueta (RF-02.2 ratificado): en reposo pulsa en dorado
 * arcano y, al imbuirse, se tiñe del color heráldico del elemento activo;
 * al expirar la ventana de resonancia de cinco segundos regresa al dorado
 * de reposo sin desaparecer. Un pequeño contador numérico declara los
 * segundos enteros restantes (5 → 0), con refresco homogéneo (RF-02.4)
 * y disipación suave al expirar (RF-02.5). Al expirar de forma natural
 * emite el evento `combo:aura-expired {element}` del plan 4.1.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): módulo ES nativo; SVG y Custom Properties
 *     nativas; documento, reloj, planificador de cuadros y bus de eventos
 *     llegan inyectados. Cero librerías.
 *   - Artículo II: los colores heráldicos se LEEN del Códice (matriz espejo
 *     de comboResolver.js); el componente no decide colores. El dorado de
 *     reposo no se hardcodea: vive en la CSS como respaldo de --aura-color.
 *   - Artículo IV: sin texto visible propio más allá de lo semántico
 *     (aria-hidden: el aura es decorativa; los anuncios los hacen otros).
 *   - Artículo V: identificadores en inglés camelCase; documentación en
 *     castellano.
 *
 * Estructura DOM (raíz .elemental-aura):
 *   <div class="elemental-aura elemental-aura--resting elemental-aura--pulse" aria-hidden="true">
 *     <img class="elemental-aura__silhouette">
 *     <span class="elemental-aura__countdown"></span>
 *   </div>
 * La variable CSS --aura-color porta el color heráldico; los estilos viven
 * en public/assets/css/components/elemental-codex.css.
 *
 * Cobertura: RF-02.1, RF-02.2, RF-02.4, RF-02.5, RNF-03 (movimiento
 * reducido: sin pulso decorativo; el tinte y el contador permanecen).
 */

import { ELEMENTAL_MATRIX_ELEMENTS } from '../utils/comboResolver.js';

/** Ventana canónica de resonancia, en ms (RF-02.1). */
export const AURA_RESONANCE_DURATION_MS = 5000;

/**
 * Ticks del bucle de escena, en ms (≈60 fps sin exigir 60 reales).
 */
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
  /** @type {Element|null} Capa-luz silueta que abraza la efigie (RF-02.2). */
  let auraRootSilhouette = null;
  /** @type {Element|null} Rótulo del contador numérico de la ventana. */
  let auraRootCountdown = null;
  /** Último segundo entero pintado (evita escrituras en vano por tick). */
  let lastCountdownSeconds = -1;
  /** Elemento, o null si el blanco está neutral (reposo dorado). */
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

    // Capa-luz silueta (RF-02.2, criterio ratificado): la propia efigie
    // del blanco clonada como resplandor que abraza sombrero, brazos y
    // base (jamás una geometría circular). El tinte lo gobierna
    // --aura-color: dorado arcano de reposo, heráldico al imbuirse.
    const silhouette = document.createElement('img');
    silhouette.setAttribute('src', 'assets/img/heretic-scarecrow.png');
    silhouette.setAttribute('alt', '');
    silhouette.setAttribute('draggable', 'false');
    silhouette.setAttribute('class', 'elemental-aura__silhouette');
    auraRootSilhouette = silhouette;

    // Contador numérico de la ventana (RF-02.2 ratificado): segundos
    // enteros restantes (5 → 0), actualizado por el bucle de escena.
    const countdown = document.createElement('span');
    countdown.setAttribute('class', 'elemental-aura__countdown');
    countdown.textContent = '';
    auraRootCountdown = countdown;

    auraRoot = document.createElement('div');
    auraRoot.setAttribute('class', 'elemental-aura');
    auraRoot.setAttribute('aria-hidden', 'true'); // Decorativa: los anuncios los hace la región viva.
    auraRoot.appendChild(silhouette);
    auraRoot.appendChild(countdown);

    return auraRoot;
  }

  /**
   * Pinta el estado del aura sobre la raíz (clases, color y contador).
   */
  function render() {
    if (auraRoot === null) {
      return;
    }

    const remainingMs = getRemainingMs();
    const auraIsActive = activeElement !== null && remainingMs > 0;

    // Estados exclusivos: --resting (perenne, dorado) vs --active (tinte
    // heráldico). El cambio de clase dispara la transición suave del CSS.
    // (add/remove y no toggle: contrato mínimo del DOM inyectable.)
    if (auraIsActive) {
      auraRoot.classList.add('elemental-aura--active');
      auraRoot.classList.remove('elemental-aura--resting');
    } else {
      auraRoot.classList.remove('elemental-aura--active');
      auraRoot.classList.add('elemental-aura--resting');
    }

    // RNF-03: sin pulso decorativo bajo movimiento reducido; el pulso
    // acompaña tanto al reposo dorado (3.5 s, hermana de la efigie) como
    // al tinte activo (1.6 s).
    if (prefersReducedMotion()) {
      auraRoot.classList.remove('elemental-aura--pulse');
    } else {
      auraRoot.classList.add('elemental-aura--pulse');
    }

    if (auraIsActive) {
      auraRoot.style.setProperty('--aura-color', heraldicColorOf(activeElement));
      // El tinte también se publica en el anfitrión: la respiración de la
      // efigie (hermana del aura en el DOM) lo lee para armonizarse con
      // el color heráldico vigente y no disputarle el protagonismo.
      auraRoot.parentNode?.style?.setProperty?.('--aura-color', heraldicColorOf(activeElement));

      // Contador numérico (RF-02.2 ratificado): segundos enteros que
      // restan (5 → 0). Se escribe solo cuando cambia la cifra para no
      // manipular el DOM en cada tick del bucle de escena.
      const secondsLeft = Math.max(0, Math.ceil(remainingMs / 1000));
      if (secondsLeft !== lastCountdownSeconds && auraRootCountdown !== null) {
        auraRootCountdown.textContent = String(secondsLeft);
        lastCountdownSeconds = secondsLeft;
      }
    } else if (activeElement === null) {
      // Reposo perenne (RF-02.2 ratificado): la capa-luz permanece en
      // dorado arcano; el contador se apaga junto a la ventana.
      auraRoot.style.setProperty('--aura-color', '');
      lastCountdownSeconds = -1;
      if (auraRootCountdown !== null) {
        auraRootCountdown.textContent = '';
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
      // Expiración natural (RF-02.5): regreso suave al dorado de reposo
      // (la capa-luz jamás desaparece) y anuncio único.
      expiryAnnounced = true;
      const expiredElement = activeElement;
      activeElement = null;
      if (auraRoot !== null) {
        auraRoot.classList.remove('elemental-aura--active', 'elemental-aura--pulse');
        auraRoot.classList.add('elemental-aura--resting'); // El CSS transiciona al dorado.
        auraRoot.style.setProperty('--aura-color', '');
        auraRoot.parentNode?.style?.removeProperty?.('--aura-color');
      }
      // Con bubbles: el bus de combos burbujea hasta el anfitrión (contrato
      // compartido con los eventos del resolutor) para que la vista y los
      // arneses ajenos oigan la expiración natural.
      eventTarget.dispatchEvent(new CustomEvent('combo:aura-expired', { detail: { element: expiredElement }, bubbles: true }));
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
    // Perenne (RF-02.2 ratificado): la capa-luz nace en reposo dorado y
    // jamás se oculta; solo el tinte y el contador siguen a la ventana.
    // El pulso dorado de reposo arranca con el propio montaje.
    root.classList.add('elemental-aura--resting');
    if (!prefersReducedMotion()) {
      root.classList.add('elemental-aura--pulse');
    }
    root.removeAttribute('hidden');
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
      auraRoot.classList.remove('elemental-aura--active', 'elemental-aura--pulse');
      auraRoot.classList.add('elemental-aura--resting'); // Regreso al dorado perenne.
      auraRoot.style.setProperty('--aura-color', '');
      auraRoot.parentNode?.style?.removeProperty?.('--aura-color');
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
    auraRootSilhouette = null;
    auraRootCountdown = null;
    lastCountdownSeconds = -1;
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
