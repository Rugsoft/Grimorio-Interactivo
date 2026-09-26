/**
 * userProfileBadge.js — Distintivo de perfil y estado de sesión en cabecera.
 *
 * Tarea 4.5 (TASKS-03): cuando existe vínculo activo, reemplaza el botón
 * «Cruzar el Umbral» por el distintivo del usuario y su clan, con un menú
 * desplegable arcano que ofrece ver el libro personal o disolver el
 * vínculo de sesión (individual y global). SIN opción de cambio de
 * linaje: el juramento es perpetuo (SPEC-09, RF-03.4) — ver §MENU_OPTIONS.
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
 *   - Usuario sin linaje: identidad de peregrino iniciático (SPEC-09,
 *     RF-04.3) — el rótulo solemne «Peregrino sin Linaje» declara el estado,
 *     sin clan fantasma y sin heráldica; tras jurar, el sello del linaje
 *     usa la MISMA representación heráldica que SPEC-07 (runeSealComponent).
 *
 * SPEC-09 (Tarea 3.3): el import del sello heráldico es de DATOS (líneas y
 * constantes del canon compartido con SPEC-07), no de DOM — el interior del
 * SVG se forja en su propio documento.
 */

import {
  createRuneSeal,
  RUNE_SEAL_STATES,
} from './runeSealComponent.js';

/** Índice local de los 8 Linajes Canónicos (espejo del canon de SPEC-07;
 *  misma heráldica: rulingElement y nombre ceremonial por clave). */
const LINEAGE_INDEX = new Map(Object.entries({
  primordialFlame: { name: 'Linaje de la Llama Primordial', rulingElement: 'fire' },
  celestialTides: { name: 'Linaje de las Mareas Celestiales', rulingElement: 'water' },
  eternalTempest: { name: 'Linaje de la Tempestad Eterna', rulingElement: 'lightning' },
  worldRoots: { name: 'Linaje de las Raíces del Mundo', rulingElement: 'earth' },
  dawnWinds: { name: 'Linaje de los Vientos del Alba', rulingElement: 'wind' },
  solarCrown: { name: 'Linaje de la Corona Solar', rulingElement: 'light' },
  abyssalShadows: { name: 'Linaje de las Sombras Abisales', rulingElement: 'darkness' },
  aetherWeavers: { name: 'Linaje de los Tejedores del Éter', rulingElement: 'pureArcane' },
}));

/** Opciones canónicas del menú desplegable arcano (RF-02.4, RF-07.1,
 *  RF-08.1 de SPEC-12).
 *
 * SPEC-12 (Tarea 7.1, RF-08.1): la opción «Mi morada» abre el Panel del
 * Adepto — la cámara privada de la identidad íntegra — con un solo
 * gesto y SIN nueva ceremonia ni llaves adicionales. Las tres opciones
 * ya ratificadas quedan INTACTAS en su orden y rótulos.
 *
 * SPEC-09 (RF-03.4, exclusión 2): la opción «Cambiar de linaje» quedó
 * RETIRADA de este menú. El juramento de linaje es perpetuo e irrevocable —
 * ninguna vista, acción o administrativo posterior puede ofrecer cambio ni
 * revocación del vínculo— y la ventana de tregua de SPEC-03 RF-07 (cambio
 * de CLAN) jamás llegó a materializarse en el santuario: la opción no
 * tenía flujo ni endpoint tras de sí y ofendía al vínculo de linaje
 * confundiendo vocabulario. Las disoluciones de sesión (dissolve/
 * dissolveAll) son cierre de credenciales, jamás mutación del linaje.
 */
const MENU_OPTIONS = Object.freeze([
  { action: 'openPanel', label: 'Mi morada' },
  { action: 'openGrimoire', label: 'Ver mi libro personal' },
  { action: 'dissolve', label: 'Disolver este vínculo' },
  { action: 'dissolveAll', label: 'Disolver todos mis vínculos' },
]);

/** Rótulo solemne del peregrino iniciático (SPEC-09, RF-04.3). */
const PILGRIM_LEGEND = 'Peregrino sin Linaje';

/** Rótulo neutro ante un linaje ajeno al catálogo local (degradación). */
const UNKNOWN_LINEAGE_LEGEND = 'Linaje jurado';

/** Roles con potestad de moderación, para el contador de sesión (RF-08.2). */
const MODERATION_ROLES = Object.freeze(new Set(['master', 'supremeAdmin']));

/**
 * Leyenda accesible del rol del vinculado, en noble castellano (Artículo V).
 *
 * @param {string} role Rol técnico de la sesión.
 * @returns {string} Nombre solemne del oficio.
 */
function roleLegend(role) {
  switch (role) {
    case 'editor':
      return 'Adepto';
    case 'master':
      return 'Maestro del Códice';
    case 'supremeAdmin':
      return 'Admin Supremo';
    default:
      return 'Lector';
  }
}

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
 * Búsqueda recursiva de descendientes que porten una clase (compatible
 * con el DOM simulado del arnés: doble vía classList y atributo class).
 */
function findDescendantsByClass(root, className, found = []) {
  for (const child of root.children ?? []) {
    if (
      child.classList?.contains?.(className)
      || (typeof child.getAttribute === 'function'
        && String(child.getAttribute('class') ?? '').split(/\s+/).includes(className))
    ) found.push(child);
    findDescendantsByClass(child, className, found);
  }
  return found;
}

/**
 * Crea el componente del distintivo de sesión.
 *
 * @param {HTMLElement} badgeRoot Contenedor de sesión de la cabecera
 *        (aloja el botón «Cruzar el Umbral» del shell).
 * @param {Object} options
 * @param {() => void} [options.onCrossThreshold] Activación del umbral (anónimo).
 * @param {() => void} [options.onOpenPanel] Abrir «Mi morada», el Panel del
 *        Adepto (Tarea 7.1, RF-08.1 de SPEC-12): un solo gesto, sin
 *        nueva ceremonia ni llaves adicionales.
 * @param {() => void} [options.onOpenGrimoire] Ver el libro personal (RF-07.1).
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
  /** El oyente del bus de la efigie (una sola vez, sin fugas). */
  let avatarListenerBound = false;
  /** Bandera del oyente de documento (burbujeo real, cierre SPEC-12). */
  let documentAvatarListenerBound = false;

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

  /** ¿Vive el nodo dentro del distintivo? Recorrido de padres que funciona
   *  tanto en el DOM real (parentNode) como en los DOM simulados de los
   *  arneses (parentElement), sin depender de Element.contains. */
  function isInsideBadge(node) {
    if (node === null || node === undefined) return false;
    if (node === badgeElement) return true;
    let current = node.parentNode ?? node.parentElement ?? null;
    while (current !== null && current !== undefined) {
      if (current === badgeElement) return true;
      current = current.parentNode ?? current.parentElement ?? null;
    }
    return false;
  }

  /** Cierre por clic externo (usabilidad): un gesto fuera del distintivo
   *  repliega el menú, como espera cualquier menú desplegable. El listener
   *  vive en el contenedor raíz (badgeRoot) para no fugar listeners globales
   *  y se retira junto al resto en destroy(). */
  function handleOutsideClick(event) {
    if (!menuIsOpen) return;
    if (isInsideBadge(event.target)) return;
    closeMenu();
  }

  /** Despliega el menú con gestión de foco accesible. */
  function openMenu() {
    menuIsOpen = true;
    toggleButton?.setAttribute('aria-expanded', 'true');
    if (menuElement) {
      menuElement.removeAttribute('aria-hidden');
      const firstOption = menuElement.children?.[0]?.children?.[0] ?? menuElement.children?.[0];
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
      return;
    }
    // Navegación de menú (RNF-03, WCAG): flechas circulan por las opciones,
    // Inicio/Fin saltan a los extremos y Tab abandona el menú repliegándolo.
    if (!menuIsOpen || !menuElement) return;
    const optionButtons = (menuElement.children ?? [])
      .map((optionItem) => optionItem.children?.[0])
      .filter((child) => child !== null && child !== undefined);
    const currentIndex = optionButtons.indexOf(event.target);
    if (currentIndex < 0) return;
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault?.();
      const step = event.key === 'ArrowDown' ? 1 : -1;
      const nextIndex = (currentIndex + step + optionButtons.length) % optionButtons.length;
      optionButtons[nextIndex]?.focus();
    } else if (event.key === 'Home') {
      event.preventDefault?.();
      optionButtons[0]?.focus();
    } else if (event.key === 'End') {
      event.preventDefault?.();
      optionButtons[optionButtons.length - 1]?.focus();
    } else if (event.key === 'Tab') {
      closeMenu();
    }
  }

  /** Cablea (una sola vez) el listener de Escape sobre el contenedor. */
  let keydownBound = false;
  function bindKeydown() {
    if (keydownBound) return;
    keydownBound = true;
    badgeRoot.addEventListener('keydown', handleKeydown);
  }

  /** Cablea (una sola vez) el listener de clic externo sobre el contenedor. */
  let outsideClickBound = false;
  function bindOutsideClick() {
    if (outsideClickBound) return;
    outsideClickBound = true;
    badgeRoot.addEventListener('click', handleOutsideClick);
  }

  /**
   * Forja el distintivo de perfil con su menú desplegable arcano.
   *
   * @param {object} user Sobre data.user: { id, alias, role, clanId, clanName, lineage }.
   */
  function buildBadge(user) {
    withdrawThresholdButton();

    badgeElement = documentRef.createElement?.('div');
    badgeElement.setAttribute('id', 'userProfileBadge');
    badgeElement.setAttribute('class', 'user-profile');
    badgeRoot.appendChild(badgeElement);

    // Identidad del linaje (SPEC-09, RF-04.3): peregrino sin heráldica o
    // linajado con su sello. La bandera vive en el INSTANTE de la forja.
    const oathLineage = typeof user.lineage === 'string' && user.lineage !== ''
      ? user.lineage
      : null;
    const lineageProfile = oathLineage !== null ? LINEAGE_INDEX.get(oathLineage) ?? null : null;

    toggleButton = documentRef.createElement?.('button');
    toggleButton.setAttribute('id', 'userProfileToggle');
    toggleButton.setAttribute('type', 'button');
    toggleButton.setAttribute('aria-haspopup', 'true');
    toggleButton.setAttribute('aria-expanded', 'false');
    toggleButton.setAttribute('aria-controls', 'userProfileMenu');
    const hasClan = user.clanId !== '' && typeof user.clanName === 'string' && user.clanName !== '';
    // El rótulo visible: alias + clan (SPEC-07), o alias + estado solemne.
    // El sufijo « — menú» del nombre accesible anuncia el desplegable SIN
    // contaminar el rótulo visible ( WCAG: el contenido del aria-label
    // debe contener el texto visible del botón).
    const displayLegend = hasClan
      ? `${user.alias} — ${user.clanName}`
      : oathLineage !== null
        ? `${user.alias} — ${lineageProfile?.name ?? UNKNOWN_LINEAGE_LEGEND}`
        : `${user.alias} — ${PILGRIM_LEGEND}`;
    // Identidad accesible completa (nombre accesible + oficio + convo);
    // el rol técnico jamás se imprime en el rótulo visible (Art. V).
    const ariaLabel = `${displayLegend} — ${roleLegend(String(user.role ?? ''))}${
      MODERATION_ROLES.has(String(user.role ?? '')) ? ' (facultado para moderar)' : ''
    }. Abrir el menú arcano`;
    toggleButton.setAttribute('aria-label', ariaLabel);
    toggleButton.textContent = displayLegend;
    toggleButton.addEventListener('click', toggleMenu);
    badgeElement.appendChild(toggleButton);

    // La efigie del vínculo (SPEC-12, Tarea 7.3, RF-03.3): el avatar
    // propio se proyecta SOLO en la cabecera del propio adepto (jamás
    // ante terceros, exclusión 5). Nace con la marca de la sesión
    // hidratada (que YA porta avatarKind/Reference/Url desde la
    // enmienda del contrato data.user) y RE-PINTA por evento sin
    // recarga de página.
    const avatarNode = documentRef.createElement?.('span');
    avatarNode.setAttribute('class', 'user-profile__avatar');
    const avatarKind = String(user.avatarKind ?? 'default');
    const avatarReference = String(user.avatarReference ?? '');
    avatarNode.setAttribute('data-avatar-kind', avatarKind);
    avatarNode.setAttribute('data-avatar-reference', avatarReference);
    avatarNode.setAttribute('aria-hidden', 'true'); // decorativa: el nombre accesible ya declara la identidad
    applyAvatarImage(avatarNode, avatarKind, avatarReference, typeof user.avatarUrl === 'string' ? user.avatarUrl : null, user);
    badgeElement.appendChild(avatarNode);

    // La heráldica del linaje jurado (SPEC-09, RF-04.3): el MISMO sello
    // forjado por SPEC-07 para la ficha del linaje (role: 'lineage').
    // El sello es decorativo: el nombre accesible del distintivo ya declara
    // el linaje, así que aria-hidden evita el doble anuncio a los lectores.
    if (oathLineage !== null) {
      const seal = createRuneSeal({
        houseName: String(user.alias ?? ''),
        coatOfArms: `rune_lineage_${oathLineage}`,
        rulingElement: String(lineageProfile?.rulingElement ?? ''),
        lineageName: String(lineageProfile?.name ?? UNKNOWN_LINEAGE_LEGEND),
        state: RUNE_SEAL_STATES.ACTIVE,
        role: 'lineage',
        document: documentRef,
      });
      seal.setAttribute('class', 'user-profile__seal');
      seal.setAttribute('aria-hidden', 'true');
      badgeElement.appendChild(seal);
    }

    // Menú desplegable arcano con las opciones canónicas (RF-02.4).
    // Semántica de menú (WCAG/APG): role=menu en el desplegable y role=none
    // en los li porta-botones (el rol menuitem vive en el propio botón).
    menuElement = documentRef.createElement?.('ul');
    menuElement.setAttribute('id', 'userProfileMenu');
    menuElement.setAttribute('role', 'menu');
    menuElement.setAttribute('aria-hidden', 'true');
    badgeElement.appendChild(menuElement);

    for (const menuOption of MENU_OPTIONS) {
      const optionItem = documentRef.createElement?.('li');
      if (!optionItem) continue;
      optionItem.setAttribute('role', 'none');
      const optionButton = documentRef.createElement?.('button');
      if (!optionButton) continue;
      optionButton.setAttribute('type', 'button');
      optionButton.setAttribute('role', 'menuitem');
      optionButton.setAttribute('data-action', menuOption.action);
      optionButton.setAttribute('tabindex', '-1');
      optionButton.textContent = menuOption.label;
      optionButton.addEventListener('click', () => {
        // Cada opción delega en el orquestador y repliega el menú.
        const callbackMap = {
          openPanel: options.onOpenPanel,
          openGrimoire: options.onOpenGrimoire,
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

    // Escape y clic externo repliegan el menú; el keydown de teclado de menú
    // burbujea desde la opción enfocada (listeners sobre el contenedor).
    bindKeydown();
    bindOutsideClick();
  }

  /**
   * CRITERIO T4.5: con vínculo activo, la cabecera reemplaza el botón
   * «Cruzar el Umbral» por el distintivo del usuario y su clan.
   *
   * @param {object|null} user Sobre data.user del authClient (Tarea 4.1).
   */
  /**
   * Repinta de efigie POR EVENTO (SPEC-12, Tarea 7.3, RF-03.4, plan
   * §4.2): la vista del panel emite `panel:avatar-changed` tras cada
   * alta/retiro y el distintivo re-viste su nodo SIN recarga de página
   * ni sondeo. Jamás muta el store: la sesión hidratada manda en el
   * siguiente checkSession (caso límite 19: sin sincronismo vivo).
   *
   * La EFIGIE PROPIA se pinta por Custom Property (--avatar-image, Tarea
   * 7.3): la URL de servicio (plan §2.3) llega por el detail o, en su
   * defecto, se deriva de la referencia — la imagen vive tras guardia
   * de sesión, jamás en una superficie pública.
   *
   * @param {CustomEvent} event Evento del bus con { kind, reference, url? }.
   */
  function handleAvatarChanged(event) {
    if (isDestroyed || currentUser === null) return;
    const detail = event?.detail ?? {};
    const avatarNode = findDescendantsByClass(badgeRoot, 'user-profile__avatar')[0] ?? null;
    if (!avatarNode) return;
    const kind = String(detail.kind ?? 'default');
    const reference = String(detail.reference ?? '');
    avatarNode.setAttribute('data-avatar-kind', kind);
    avatarNode.setAttribute('data-avatar-reference', reference);
    avatarNode.replaceChildren?.();
    applyAvatarImage(avatarNode, kind, reference, typeof detail.url === 'string' ? detail.url : null, currentUser);
  }

  /**
   * Viste (o desviste) la imagen de la efigie en el nodo de cabecera:
   * la PROPIA porta pintura (--avatar-image, URL de servicio del plan
   * §2.3); la del CANON forja su sello rúnico dentro del nodo (el MISMO
   * arte de SPEC-02 RF-07 que el picker exhibe); el canónico por
   * defecto queda en reposo noble.
   *
   * @param {Element} avatarNode Nodo de la efigie.
   * @param {string} kind kind canónico: own | catalog | default.
   * @param {string} reference Referencia del contrato cerrado.
   * @param {string|null} url URL de servicio de la efigie propia, si se conoce.
   * @param {User} user Sesión hidratada (linaje jurado para el sello).
   */
  function applyAvatarImage(avatarNode, kind, reference, url, user) {
    if (kind !== 'own' && kind !== 'catalog') {
      avatarNode.removeAttribute('style');
      return;
    }
    if (kind === 'own') {
      const resolvedUrl = url !== null && url !== ''
        ? url
        : `/api/v1/panel/avatar/image?v=${encodeURIComponent(reference)}`;
      avatarNode.setAttribute('style', `--avatar-image: url('${resolvedUrl}')`);
      return;
    }
    // Efigie heráldica del canon: el sello nace del sufijo del
    // linaje en la referencia (seal_primordialFlame → fire…). Las
    // efigies no heráldicas (custodio, peregrino) degradan al
    // ouroboros del Arcano Puro — jamás un cuadro vacío.
    const lineageKey = String(reference ?? '').replace(/^seal_/, '');
    const lineageProfile = LINEAGE_INDEX.get(lineageKey) ?? null;
    try {
      const seal = createRuneSeal({
        houseName: String(user?.alias ?? ''),
        coatOfArms: `rune_avatar_${String(reference ?? '')}`,
        rulingElement: String(lineageProfile?.rulingElement ?? 'pureArcane'),
        state: RUNE_SEAL_STATES.ACTIVE,
        role: 'house',
        document: documentRef,
      });
      seal.setAttribute('class', 'user-profile__avatar-seal');
      avatarNode.appendChild?.(seal);
    } catch {
      // Arnés sin createElementNS: el nodo conserva su marco noble.
    }
  }

  /** Liga el oyente del bus UNA sola vez (sin fugas por re-render). */
  function bindAvatarListener() {
    if (avatarListenerBound || typeof badgeRoot.addEventListener !== 'function') return;
    badgeRoot.addEventListener('panel:avatar-changed', handleAvatarChanged);
    // Doble vía de recepción (hallazgo del cierre manual, Tarea 7.3):
    // en el DOM vivo el picker emite sobre la vista del panel (rama
    // `main`) y el evento BURBUJEA hasta `document` — jamás alcanza a
    // `badgeRoot` (cabecera, rama hermana). El oyente de documento
    // recoge el repinto real; el de `badgeRoot` conserva el contrato
    // directo del arnés. `documentRef` es inyectable (tests) y puede
    // carecer de addEventListener en los shims: guardia defensiva.
    documentRef?.addEventListener?.('panel:avatar-changed', handleAvatarChanged);
    documentAvatarListenerBound = true;
    avatarListenerBound = true;
  }

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
    badgeRoot.removeEventListener('click', handleOutsideClick);
    outsideClickBound = false;
    // El oyente del bus de la efigie se retira con el componente (sin fugas).
    if (avatarListenerBound) {
      badgeRoot.removeEventListener('panel:avatar-changed', handleAvatarChanged);
      avatarListenerBound = false;
    }
    if (documentAvatarListenerBound) {
      documentRef?.removeEventListener?.('panel:avatar-changed', handleAvatarChanged);
      documentAvatarListenerBound = false;
    }
    removeBadgeNodes();
    // El botón del umbral del shell se restaura para dejar la cabecera
    // tal y como estaba antes de montar el componente.
    restoreThresholdButton();
    currentUser = null;
  }

  // Estado inicial: se asume visitante anónimo (el shell trae el umbral).
  // El oyente del bus de la efigie se liga aquí: UNA vez por componente,
  // independiente de los re-renders del distintivo (sin fugas).
  restoreThresholdButton();
  bindAvatarListener();

  return {
    setUser,
    clearUser,
    destroy,
  };
}
