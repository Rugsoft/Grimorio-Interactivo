/**
 * userProfileBadge.js — Distintivo de perfil y estado de sesión en cabecera.
 *
 * Tarea 4.5 (TASKS-03): cuando existe vínculo activo, reemplaza el botón
 * «Cruzar el Umbral» por el distintivo del usuario y su clan, con un menú
 * desplegable arcano que ofrece ver el libro personal, cambiar de clan en
 * tregua o disolver el vínculo (individual y global).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM nativo; los nodos se forjan con
 *     createElement y textContent — innerHTML está PROHIBIDO (AGENTS.md 6.1).
 *   - Artículo IV (El Velo Arcano): leyendas solemnes en castellano.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * Diseño:
 *   - setUser(user) / clearUser() consumen el sobre data.user del authClient
 *     (Tarea 4.1) y el estado del store (Tarea 4.2): el componente jamás
 *     llama a la API por su cuenta — delega SIEMPRE en callbacks del
 *     orquestador (mismo contrato que navbarComponent, Tarea 4.1 TASKS-01).
 *   - Accesibilidad (RNF-03): aria-haspopup/aria-expanded/aria-label con el
 *     alias, cierre por Escape y por selección de opción, foco al distintivo.
 *   - Usuario sin linaje: se muestra solo el alias (nunca un clan fantasma).
 */

/** Opciones canónicas del menú desplegable arcano (RF-02.4, RF-07.1). */
const MENU_OPTIONS = Object.freeze([
  { action: 'openGrimoire', label: 'Ver mi libro personal' },
  { action: 'changeClan', label: 'Cambiar de linaje' },
  { action: 'dissolve', label: 'Disolver este vínculo' },
  { action: 'dissolveAll', label: 'Disolver todos mis vínculos' },
]);

/**
 * Búsqueda recursiva de un descendiente por atributo id (compatible con
 * el DOM simulado del arnés).
 */
function findDescendantById(root, elementId) {
  for (const child of root.children ?? []) {
    if (child.getAttribute?.('id') === elementId) return child;
    const found = findDescendantById(child, elementId);
    if (found) return found;
  }
  return null;
}

/**
 * Crea el componente del distintivo de sesión.
 *
 * @param {HTMLElement} badgeRoot Contenedor de sesión de la cabecera
 *        (aloja el botón «Cruzar el Umbral» del shell).
 * @param {Object} options
 * @param {() => void} [options.onCrossThreshold] Activación del umbral (anónimo).
 * @param {() => void} [options.onOpenGrimoire] Ver el libro personal (RF-07.1).
 * @param {() => void} [options.onChangeClan] Cambiar de linaje (RF-07).
 * @param {() => void} [options.onDissolve] Disolver el vínculo actual (RF-02.4).
 * @param {() => void} [options.onDissolveAll] Disolver todos los vínculos (RF-02.4).
 * @param {Document} [options.documentRef] Documento inyectable (tests).
 * @returns {Object} API: { setUser, clearUser, destroy }.
 */
export function createMemoryBadgeRoot(badgeRoot, options = {}) {
  const documentRef = options.documentRef ?? globalThis.document;

  /** Usuario del vínculo activo (null = anónimo). */
  let currentUser = null;

  /** El botón «Cruzar el Umbral» del shell, retirado al autenticarse. */
  let thresholdButton = null;

  /** Distintivo y menú forjados (retirados en clearUser/destroy). */
  let badgeElement = null;
  let menuElement = null;
  let toggleButton = null;
  let menuIsOpen = false;
  let isDestroyed = false;

  /**
   * Retira el distintivo del árbol (los nodos forjados no vuelven a usarse).
   */
  function removeBadgeNodes() {
    badgeElement?.remove();
    badgeElement = null;
    menuElement = null;
    toggleButton = null;
    menuIsOpen = false;
  }

  /** Restaura el botón «Cruzar el Umbral» del shell con su delegación. */
  function restoreThresholdButton() {
    if (thresholdButton === null) {
      thresholdButton = findDescendantById(badgeRoot, 'navCrossThreshold');
      if (thresholdButton === null) {
        const forged = documentRef.createElement?.('button');
        if (!forged) return;
        forged.setAttribute('id', 'navCrossThreshold');
        forged.setAttribute('data-action', 'crossThreshold');
        forged.textContent = 'Cruzar el Umbral';
        thresholdButton = forged;
        badgeRoot.appendChild(thresholdButton);
      }
      thresholdButton.addEventListener('click', () => {
        if (typeof options.onCrossThreshold === 'function') options.onCrossThreshold();
      });
    }
    if (thresholdButton.parentElement !== badgeRoot) {
      badgeRoot.appendChild(thresholdButton);
    }
  }

  /** Retira el botón del umbral del árbol (conservado para restaurarlo). */
  function withdrawThresholdButton() {
    thresholdButton ??= findDescendantById(badgeRoot, 'navCrossThreshold');
    thresholdButton?.remove();
  }

  /** Repliega el menú y refleja el estado en aria-expanded. */
  function closeMenu() {
    menuIsOpen = false;
    toggleButton?.setAttribute('aria-expanded', 'false');
    if (menuElement) menuElement.setAttribute('aria-hidden', 'true');
  }

  /** Despliega el menú con gestión de foco accesible. */
  function openMenu() {
    menuIsOpen = true;
    toggleButton?.setAttribute('aria-expanded', 'true');
    if (menuElement) {
      menuElement.removeAttribute('aria-hidden');
      const firstOption = menuElement.children?.[0];
      if (firstOption) firstOption.focus();
    }
  }

  /** Conmuta el menú desplegable. */
  function toggleMenu() {
    if (menuIsOpen) {
      closeMenu();
    } else {
      openMenu();
    }
  }

  /** Escape sobre la cabecera repliega el menú (accesibilidad, RNF-03).
   *  El listener vive en el CONTENEDOR (badgeRoot) porque en el DOM real
   *  el keydown burbujea hacia arriba desde la opción enfocada. */
  function handleKeydown(event) {
    if (event.key === 'Escape' && menuIsOpen) {
      closeMenu();
      toggleButton?.focus();
    }
  }

  /** Cablea (una sola vez) el listener de Escape sobre el contenedor. */
  let keydownBound = false;
  function bindKeydown() {
    if (keydownBound) return;
    keydownBound = true;
    badgeRoot.addEventListener('keydown', handleKeydown);
  }

  /**
   * Forja el distintivo de perfil con su menú desplegable arcano.
   *
   * @param {object} user Sobre data.user: { id, alias, role, clanId, clanName }.
   */
  function buildBadge(user) {
    withdrawThresholdButton();

    badgeElement = documentRef.createElement?.('div');
    badgeElement.setAttribute('id', 'userProfileBadge');
    badgeElement.setAttribute('class', 'user-profile');
    badgeRoot.appendChild(badgeElement);

    // Distintivo con alias y clan (RF-07.1). Sin linaje real no hay clan
    // fantasma: solo el alias (RF-01.1).
    toggleButton = documentRef.createElement?.('button');
    toggleButton.setAttribute('id', 'userProfileToggle');
    toggleButton.setAttribute('type', 'button');
    toggleButton.setAttribute('aria-haspopup', 'true');
    toggleButton.setAttribute('aria-expanded', 'false');
    const ariaLabel = user.clanId !== '' && typeof user.clanName === 'string' && user.clanName !== ''
      ? `Sesión de ${user.alias} del linaje ${user.clanName}. Abrir el menú arcano`
      : `Sesión de ${user.alias}. Abrir el menú arcano`;
    toggleButton.setAttribute('aria-label', ariaLabel);
    toggleButton.textContent = user.clanId !== '' && user.clanName
      ? `${user.alias} — ${user.clanName}`
      : user.alias;
    toggleButton.addEventListener('click', toggleMenu);
    badgeElement.appendChild(toggleButton);

    // Menú desplegable arcano con las opciones canónicas (RF-02.4).
    menuElement = documentRef.createElement?.('ul');
    menuElement.setAttribute('id', 'userProfileMenu');
    menuElement.setAttribute('aria-hidden', 'true');
    badgeElement.appendChild(menuElement);

    for (const menuOption of MENU_OPTIONS) {
      const optionItem = documentRef.createElement?.('li');
      if (!optionItem) continue;
      const optionButton = documentRef.createElement?.('button');
      if (!optionButton) continue;
      optionButton.setAttribute('type', 'button');
      optionButton.setAttribute('data-action', menuOption.action);
      optionButton.textContent = menuOption.label;
      optionButton.addEventListener('click', () => {
        // Cada opción delega en el orquestador y repliega el menú.
        const callbackMap = {
          openGrimoire: options.onOpenGrimoire,
          changeClan: options.onChangeClan,
          dissolve: options.onDissolve,
          dissolveAll: options.onDissolveAll,
        };
        closeMenu();
        if (typeof callbackMap[menuOption.action] === 'function') {
          callbackMap[menuOption.action]();
        }
      });
      optionItem.appendChild(optionButton);
      menuElement.appendChild(optionItem);
    }

    // Escape repliega el menú (listener sobre el contenedor: burbujeo).
    bindKeydown();
  }

  /**
   * CRITERIO T4.5: con vínculo activo, la cabecera reemplaza el botón
   * «Cruzar el Umbral» por el distintivo del usuario y su clan.
   *
   * @param {object|null} user Sobre data.user del authClient (Tarea 4.1).
   */
  function setUser(user) {
    if (isDestroyed) return;
    const sessionUser = user !== null && typeof user === 'object' ? user : null;
    if (sessionUser === null || !sessionUser.alias) {
      clearUser();
      return;
    }

    // Re-render idempotente: se retira el distintivo previo.
    removeBadgeNodes();
    currentUser = sessionUser;
    buildBadge(currentUser);
  }

  /**
   * Vuelve al modo anónimo: retira distintivo y menú, y restaura el botón
   * «Cruzar el Umbral» con su delegación (interceptación RF-05.2).
   */
  function clearUser() {
    if (isDestroyed) return;
    currentUser = null;
    removeBadgeNodes();
    restoreThresholdButton();
  }

  function destroy() {
    if (isDestroyed) return;
    isDestroyed = true;
    badgeRoot.removeEventListener('keydown', handleKeydown);
    keydownBound = false;
    removeBadgeNodes();
    // El botón del umbral del shell se restaura para dejar la cabecera
    // tal y como estaba antes de montar el componente.
    restoreThresholdButton();
    currentUser = null;
  }

  // Estado inicial: se asume visitante anónimo (el shell trae el umbral).
  restoreThresholdButton();

  return {
    setUser,
    clearUser,
    destroy,
  };
}
