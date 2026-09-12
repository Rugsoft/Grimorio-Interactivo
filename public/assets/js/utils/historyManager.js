/**
 * historyManager.js — Sincronizador de historial y hash (Tarea 3.4).
 *
 * Puente entre la History API nativa y el orquestador de la SPA:
 *   - Abrir ficha  -> pushState con '#hechizo-slug' (RF-04.1).
 *   - Botón Atrás  -> popstate -> onSpellClose (RNF-05, RF-04.2).
 *   - Hash manual  -> hashchange -> onSpellOpen(slug).
 *   - Hash inicial -> apertura directa de enlaces compartidos (plan 4.3).
 *
 * Constitución:
 *   - Artículo I: History API estándar; cero routers externos.
 *   - Artículo V: funciones en inglés camelCase; comentarios en castellano.
 *
 * Diseño: el módulo recibe el `window` por inyección (testeable sin navegador)
 * y delega SIEMPRE la decisión al orquestador mediante callbacks — nunca
 * manipula el DOM por su cuenta.
 */

/** Prefijo canónico del hash de hechizo (plan 4.3). */
export const SPELL_HASH_PREFIX = '#hechizo-';

/**
 * Construye el hash canónico de un hechizo.
 * @param {string} spellSlug Slug del hechizo.
 * @returns {string} Hash tipo '#hechizo-rayo-astral'.
 */
export function buildSpellHash(spellSlug) {
  return `${SPELL_HASH_PREFIX}${spellSlug}`;
}

/**
 * Extrae el slug de un hash de hechizo, o null si el hash es ajeno.
 * @param {string} rawHash Hash completo ('#hechizo-llamas-de-frieren').
 * @returns {string|null} Slug o null.
 */
export function parseSpellHash(rawHash) {
  if (typeof rawHash !== 'string' || !rawHash.startsWith(SPELL_HASH_PREFIX)) {
    return null;
  }

  const spellSlug = rawHash.slice(SPELL_HASH_PREFIX.length);
  return spellSlug === '' ? null : spellSlug;
}

/**
 * Fábrica del sincronizador de historial.
 *
 * @param {Window} appWindow Window del navegador (o simulado en pruebas).
 * @param {object} orchestratorCallbacks Contratos con el orquestador:
 *   - onSpellOpen(slug): abrir la ficha del hechizo.
 *   - onSpellClose(): cerrar la ficha abierta.
 *   - resolveInitialHash (opción): procesar el hash con el que arrancó la página.
 * @returns {object} { openSpellDetail, closeSpellDetail, destroy }.
 */
export function createHistoryManager(appWindow, orchestratorCallbacks) {
  const {
    onSpellOpen,
    onSpellClose,
    resolveInitialHash = false,
  } = orchestratorCallbacks;

  /** Último slug notificado por apertura, para desduplicar hashchange. */
  let lastNotifiedSlug = null;

  /**
   * Procesa el hash actual de la URL y notifica al orquestador.
   * @returns {boolean} true si el hash era de hechizo y se notificó apertura.
   */
  function notifyFromCurrentHash() {
    const currentHash = appWindow.location.hash;
    const spellSlug = parseSpellHash(currentHash);

    if (spellSlug !== null) {
      onSpellOpen(spellSlug);
      return true;
    }

    return false;
  }

  /**
   * Listener de popstate: el usuario pulsó Atrás/Adelante (RNF-05).
   * La fuente de verdad es event.state (plan 4.3): la entrada de historial
   * portaba { modal: 'spellDetail', slug } solo si se abrió una ficha.
   * Esto evita duplicar notificaciones con hashchange durante navegación
   * entre hashes de hechizos.
   */
  function handlePopstate(event) {
    const entryState = event && event.state ? event.state : null;

    if (entryState && entryState.modal === 'spellDetail' && typeof entryState.slug === 'string') {
      // Adelante (o salto del historial) hacia una ficha abierta: abrirla.
      onSpellOpen(entryState.slug);
    } else {
      // La entrada de historial no porta modal: cerrar la ficha actual.
      onSpellClose();
    }
  }

  /**
   * Listener de hashchange: el hash cambió sin pasar por el gestor
   * (enlace pegado, hash escrito a mano). Durante navegación por el
   * historial el navegador dispara popstate Y hashchange; para no
   * duplicar órdenes al orquestador, solo notificamos si el nuevo hash
   * es de hechizo y NO acabamos de procesar un popstate equivalente.
   */
  function handleHashChange(event) {
    const newUrl = typeof event?.newURL === 'string' ? event.newURL : appWindow.location.href;
    const hashIndex = newUrl.indexOf('#');
    const newHash = hashIndex === -1 ? '' : newUrl.slice(hashIndex);
    const spellSlug = parseSpellHash(newHash);

    if (spellSlug !== null && spellSlug !== lastNotifiedSlug) {
      lastNotifiedSlug = spellSlug;
      onSpellOpen(spellSlug);
    }
  }

  /**
   * Abre la ficha de un hechizo: empuja la entrada de historial con el hash.
   * RF-04.1: la dirección del navegador refleja el hechizo en lectura.
   *
   * @param {string} spellSlug Slug del hechizo.
   */
  function openSpellDetail(spellSlug) {
    const spellHash = buildSpellHash(spellSlug);

    // Evitar entradas duplicadas si ya estamos en ese mismo hash.
    if (appWindow.location.hash === spellHash) {
      return;
    }

    // Registrar el slug para que el hashchange derivado del pushState
    // (navegadores que lo emiten) no re-ordene la misma apertura.
    lastNotifiedSlug = spellSlug;

    // pushState NO dispara popstate ni hashchange: notificamos por contrato
    // de este método que la URL cambia, y el orquestador ya abrió el panel
    // por su cuenta antes de llamarnos (aquí solo sincronizamos la URL).
    appWindow.history.pushState({ modal: 'spellDetail', slug: spellSlug }, '', spellHash);
  }

  /**
   * Cierra la ficha programáticamente (Escape, botón ×, acción de la vista):
   * sustituye la URL sin añadir entradas ni re-disparar callbacks (sin bucles).
   */
  function closeSpellDetail() {
    if (parseSpellHash(appWindow.location.hash) === null) {
      return; // Ya cerrado: nada que hacer.
    }

    // Nota: replaceState NO dispara popstate ni hashchange; no hace falta
    // bandera de supresión (una bandera olividada se comería el popstate
    // legítimo siguiente). La idempotencia la aportan lastNotifiedSlug
    // y la guarda de hash nulo de este mismo método.
    lastNotifiedSlug = null;

    // La URL limpia se sustituye en la entrada ACTUAL: el Atrás posterior
    // conduce al catálogo, nunca a reabrir el panel cerrado (RF-04.3).
    const cleanUrl = appWindow.location.href.split('#')[0];
    appWindow.history.replaceState(null, '', cleanUrl);
  }

  /** Retira los listeners del navegador (baja limpia del gestor). */
  function destroy() {
    appWindow.removeEventListener('popstate', handlePopstate);
    appWindow.removeEventListener('hashchange', handleHashChange);
  }

  // Registro de listeners nativos.
  appWindow.addEventListener('popstate', handlePopstate);
  appWindow.addEventListener('hashchange', handleHashChange);

  // Enlace compartido: la página arrancó con #hechizo-slug.
  if (resolveInitialHash) {
    notifyFromCurrentHash();
  }

  return {
    openSpellDetail,
    closeSpellDetail,
    destroy,
  };
}
