/**
 * navbarComponent.js — Barra de navegación persistente (Tarea 4.1).
 *
 * RF-02.1: enlaces persistentes (Inicio, Biblioteca, Salón de Linajes,
 *          Simulador de Grimorio, Creador de Hechizos) en la cabecera.
 * RF-02.3: el Creador es acción reservada para visitantes — su activación
 *          dispara onReservedAction para que el orquestador abra el diálogo
 *          «Cruzar el Umbral» reteniendo la intención (plan 4.1).
 * RF-02.4: menú desplegable arcano en pantallas estrechas, accesible por
 *          teclado (aria-expanded, aria-controls, Escape).
 *
 * Nota (defecto corregido): la bandera del vínculo NO se captura una sola
 * vez. El orquestador la sincroniza con setSession() cuando la sesión nace
 * (checkSession, «Renovar Vínculo», consagración) o muere (disolución), y
 * la activación la consulta SIEMPRE en el instante del click.
 *
 * Constitución:
 *   - Artículo I: ES Modules nativos, sin frameworks.
 *   - Artículo IV: textos visibles solemnes en castellano.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * Diseño: el componente recibe el <nav> raíz por inyección (testeable sin
 * navegador) y delega SIEMPRE en callbacks — nunca abre modales por su cuenta.
 *
 * SPEC-09 (Tarea 3.3, RF-04.3): los enlaces declaran SECCIONES del santuario,
 * no identidad — el rótulo «Peregrino sin Linaje» y la heráldica del jurado
 * viven en el distintivo de sesión (userProfileBadge), que es quien consume
 * el sobre data.user con su campo lineage.
 *
 * SPEC-10 (Tarea 4.2, RF-01.1): el enlace «Hermandades» conduce al Vestíbulo
 * y luce el distintivo «Tienes dictámenes a la espera», alimentado por el
 * contador del Endpoint 5 vía setVestibuleBadgeCount(). Es SOLO INFORMATIVO:
 * el enlace navega siempre — el distintivo jamás bloquea ni intercepta.
 *
 * SPEC-16 (enmienda de RF-02.1, Tarea 1): la cabecera agrupa los destinos en
 * tres dominios + «Inicio» suelto cuando hay ancho de escritorio, y conserva
 * la lista plana de RF-02.4 por debajo de 1024 px. La agrupación es de
 * PRESENTACIÓN: los diez `data-view` de NAV_LINKS no se tocan, de modo que
 * cualquier consumidor del contrato sigue encontrando los mismos destinos.
 */

/** Enlaces persistentes de la cabecera (orden del plan, RF-02.1). */
export const NAV_LINKS = Object.freeze([
  { view: 'landing', hash: '#/', label: 'Inicio' },
  { view: 'library', hash: '#/biblioteca', label: 'Biblioteca de Hechizos' },
  // Mi Grimorio (SPEC-11, Tarea 5.3): el rótulo SOBERANO del tomo personal.
  { view: 'collection', hash: '#/grimorio', label: 'Mi Grimorio' },
  { view: 'codex', hash: '#/codex', label: 'Códice de Afinidades' },
  { view: 'clans', hash: '#/linajes', label: 'Salón de Linajes' },
  // El Vestíbulo (SPEC-10): la gestión de hermandades del propio linaje.
  // Con sesión activa, su acceso luce el distintivo de dictámenes (RF-01.1).
  { view: 'vestibule', hash: '#/vestibulo', label: 'Hermandades', badge: 'vestibuleBadge' },
  // El Simulador es público: su Tomo Canónico se abre a todo visitante
  // (RF-01.2 de SPEC-05). El tomo privado de Ensayos se guarda en el umbral
  // del orquestador, no en el enlace.
  { view: 'simulator', hash: '#/simulador', label: 'Simulador de Grimorio' },
  { view: 'creator', hash: '#/creador', label: 'Creador de Hechizos', action: 'openCreator' },
  { view: 'experimentalHall', hash: '#/atrio', label: 'Atrio de Pruebas' },
  { view: 'auditLog', hash: '#/bitacora', label: 'Bitácora de Auditoría' },
]);

/** Enlaces restringidos por rol judicial (SPEC-08 RF-05.4, master y supremeAdmin). */
export const CONDITIONAL_NAV_LINKS = Object.freeze([
  { view: 'tower', hash: '#/torre', label: 'Torre de Deliberación', requiredRoles: ['master', 'supremeAdmin'] },
]);

/** Acciones reservadas que el visitante no puede ejecutar sin vínculo (RF-05.1). */
export const VISITOR_LINK_ACTIONS = Object.freeze({
  openCreator: 'openCreator',
});

/** Índice `view -> enlace` para que los grupos NUNCA dupliquen ni pierdan destinos. */
const NAV_LINK_INDEX = new Map(NAV_LINKS.map((navLink) => [navLink.view, navLink]));

/**
 * Resuelve una lista de vistas a enlaces reales de NAV_LINKS. Un `view`
 * desconocido es un error de programme en el acto, no un destino perdido en
 * silencio: la grouping jamais puede tragarse un destino.
 */
function resolveLinks(views) {
  return Object.freeze(views.map((view) => {
    const link = NAV_LINK_INDEX.get(view);
    if (link === undefined) {
      throw new Error(`NAV_GROUPS declara la vista desconocida «${view}»`);
    }
    return link;
  }));
}

/**
 * Los tres dominios de la cabecera (SPEC-16, RF-16.2).
 *
 * El orden de los grupos codifica el orden del DISCURSO, no el de los datos:
 * primero se lee (lo que el santuario ya sabe), después se pertenece (los
 * linajes del lector) y por último se trabaja (las herramientas del oficio).
 *
 * «Inicio» permanece como grupo suelto de un solo destino: sin submenú, su
 * rótulo navega directamente (caso límite 2 de la SPEC-16). La Torre de
 * Deliberación, cuando el rol la concede, se aloja en «Sala de Trabajo»
 * (RF-16.7) porque es una herramienta del oficio, no un lugar de lectura.
 */
export const NAV_GROUPS = Object.freeze([
  Object.freeze({ id: 'inicio', label: 'Inicio', links: resolveLinks(['landing']) }),
  Object.freeze({ id: 'biblioteca', label: 'Biblioteca', links: resolveLinks(['library', 'collection', 'codex']) }),
  Object.freeze({ id: 'linajes', label: 'Linajes', links: resolveLinks(['clans', 'vestibule']) }),
  Object.freeze({ id: 'oficio', label: 'Sala de Trabajo', links: resolveLinks(['simulator', 'creator', 'experimentalHall', 'auditLog']) }),
]);

/** Modos de disposición de la cabecera (SPEC-16, RF-16.1 y RF-16.5). */
export const NAV_LAYOUTS = Object.freeze({ GROUPED: 'grouped', FLAT: 'flat' });

/** Prefijo de id de los submenús de grupo (contrato estable para aria-controls). */
const GROUP_MENU_ID_PREFIX = 'navGroupMenu_';

/** Id del panel de un grupo: el rótulo lo declara en aria-controls. */
export function navGroupMenuId(groupId) {
  return `${GROUP_MENU_ID_PREFIX}${groupId}`;
}

/**
 * Fábrica del componente de navegación.
 *
 * @param {HTMLElement} navRoot Elemento <nav id="siteNav"> del shell.
 * @param {object} componentOptions Contratos y fábricas:
 *   - isAuthenticated: ¿hay vínculo activo (rol >= editor)? Bandera INICIAL:
 *     el orquestador la mantiene viva con setSession().
 *   - userRole: rol técnico del usuario autenticado.
 *   - onNavigate(view): navegación pública a una vista de la SPA.
 *   - onReservedAction(action): interceptación — abrir «Cruzar el Umbral».
 *   - layout (opcional): 'grouped' (por defecto) o 'flat' (SPEC-16 RF-16.5).
 *   - elementFactory (opcional): fábrica de elementos (por defecto
 *     document.createElement; las pruebas inyectan la suya).
 * @returns {object} { render, setSession, destroy, closeMobileMenu,
 *   handleMenuKeydown, setVestibuleBadgeCount, setLayout, closeGroupMenu }.
 */
export function createNavbarComponent(navRoot, componentOptions) {
  const {
    isAuthenticated = false,
    userRole = null,
    onNavigate,
    onReservedAction,
    layout = NAV_LAYOUTS.GROUPED,
    elementFactory = (tagName) => document.createElement(tagName),
  } = componentOptions;

  /**
   * Bandera viva del vínculo. Se consulta en el INSTANTE de la activación,
   * nunca se captura de una vez: si la cabecera quedara creyendo que todo
   * visitante es anónimo, interceptaría el Taller a un erudito ya vinculado.
   */
  let sessionIsAuthenticated = isAuthenticated === true;
  let currentUserRole = userRole;

  /** Elementos del shell cableados en el primer render. */
  let linksList = null;
  let toggleButton = null;
  let menuIsOpen = false;

  /** Disposición viva de la cabecera (SPEC-16): el orquestador la cambia
   *  con matchMedia al cruzar el quiebre de 1024 px. */
  let currentLayout = layout === NAV_LAYOUTS.FLAT ? NAV_LAYOUTS.FLAT : NAV_LAYOUTS.GROUPED;

  /** Id del grupo cuyo submenú está desplegado (null = ninguno). */
  let openGroupId = null;

  /** Guardia de binding: los re-renders no apilan listeners duplicados. */
  let shellListenersBound = false;

  /**
   * Dictámenes sin contemplar (SPEC-10, RF-01.1). 0 = distintivo apagado.
   * La autoridad del número vive en el orquestador (Endpoint 5); aquí solo
   * se pinta lo que se declara.
   */
  let vestibuleBadgeCount = 0;

  /** Elemento del distintivo una vez renderizado. */
  let vestibuleBadgeElement = null;

  /** Marca el nodo como escuchable en el barrido de destroy(). */
  function trackListener(node) {
    mountedNodes.add(node);
  }

  /** Nodos con listeners vivos, para la baja limpia de destroy(). */
  const mountedNodes = new Set();

  /**
   * Repinta el distintivo del acceso al Vestíbulo. Idempotente: sin cambio
   * de cifra no toca el DOM; apagado (0) vacía el portador (el nodo vive
   * dentro del enlace y sobrevive a los ciclos de encendido/apagado).
   */
  function renderVestibuleBadge() {
    if (vestibuleBadgeElement === null) return;
    const nextCount = Number(vestibuleBadgeCount) || 0;
    if (nextCount <= 0) {
      // Apagado: sin texto ni cifra; el portador permanece vacío y oculto
      // (el CSS del kit gobierna su visibilidad por [data-count]).
      vestibuleBadgeElement.textContent = '';
      vestibuleBadgeElement.removeAttribute('data-count');
      return;
    }
    vestibuleBadgeElement.textContent = `Tienes ${nextCount} ${nextCount === 1 ? 'dictamen' : 'dictámenes'} a la espera`;
    vestibuleBadgeElement.setAttribute('data-count', String(nextCount));
  }

  /**
   * Activa un enlace: navega si es público, intercepta si está reservado.
   * @param {HTMLElement} linkElement Enlace pulsado.
   */
  function activateLink(linkElement) {
    const reservedAction = linkElement.getAttribute('data-action');
    const targetView = linkElement.getAttribute('data-view');

    if (reservedAction !== null && !sessionIsAuthenticated) {
      // RF-02.3: el orquestador abrirá «Cruzar el Umbral» y el store
      // retendrá la intención (pendingIntent.action = openCreator).
      onReservedAction?.(reservedAction);
    } else if (targetView !== null) {
      // Navegación pública (o Creador ya autenticado): la vista cierra el menú.
      closeMobileMenu();
      closeGroupMenu();
      onNavigate?.(targetView);
    }
  }

  /**
   * Manejador unificado de activación por click o teclado.
   * @param {KeyboardEvent|MouseEvent} activationEvent Evento del enlace.
   */
  function handleLinkActivation(activationEvent) {
    const linkElement = activationEvent.currentTarget ?? activationEvent.target;

    // Teclado: Enter o Space activan (los clicks llegan por 'click').
    if (activationEvent.type === 'keydown' && activationEvent.key !== 'Enter' && activationEvent.key !== ' ') {
      return;
    }
    if (activationEvent.type === 'keydown') {
      activationEvent.preventDefault();
    }

    activateLink(linkElement);
  }

  /**
   * Conmuta el menú móvil y refleja el estado en aria-expanded (RF-02.4).
   */
  function toggleMobileMenu() {
    menuIsOpen = !menuIsOpen;
    toggleButton.setAttribute('aria-expanded', menuIsOpen ? 'true' : 'false');

    // layout.css (Tarea 2.3) gobierna la visibilidad por [data-open="true"].
    if (menuIsOpen) {
      linksList.setAttribute('data-open', 'true');
    } else {
      linksList.removeAttribute('data-open');
    }
  }

  /** Recoge el menú móvil (al navegar o pulsar Escape). */
  function closeMobileMenu() {
    if (!menuIsOpen) {
      return;
    }
    menuIsOpen = false;
    toggleButton?.setAttribute('aria-expanded', 'false');
    linksList?.removeAttribute('data-open');
  }

  /**
   * Recoge el submenú de grupo desplegado y devuelve el foco a su rótulo
   * (SPEC-16, RF-16.4). Sin grupo abierto es un no-op: el foco no debe saltar
   * a ninguna parte si el usuario solo estaba escribiendo en la Biblioteca.
   */
  function closeGroupMenu(options = {}) {
    if (openGroupId === null) return;
    const groupIdBeingClosed = openGroupId;
    openGroupId = null;

    for (const trigger of findAllByClass(linksList, 'site-nav__group-trigger')) {
      if (trigger.getAttribute('data-group') !== groupIdBeingClosed) continue;
      trigger.setAttribute('aria-expanded', 'false');
      const panel = findById(linksList, navGroupMenuId(groupIdBeingClosed));
      if (panel !== null) {
        panel.setAttribute('aria-hidden', 'true');
        panel.removeAttribute('data-open');
      }
      if (options.restoreFocus === true) {
        trigger.focus?.();
      }
    }
  }

  /**
   * Escape sobre la navegación recoge el submenú si hay uno abierto, y si no
   * el menú móvil, devolviendo el foco al control que los abrió (RNF-03).
   * @param {KeyboardEvent} keydownEvent Evento de teclado del nav.
   */
  function handleMenuKeydown(keydownEvent) {
    if (keydownEvent.key !== 'Escape') return;
    // La prioridad es el submenú: es lo que el usuario acaba de abrir.
    if (openGroupId !== null) {
      closeGroupMenu({ restoreFocus: true });
      return;
    }
    closeMobileMenu();
    toggleButton.focus();
  }

  /**
   * Clic o teclado sobre el rótulo de un grupo (SPEC-16, RF-16.3):
   * despliega su submenú; si ya estaba desplegado, navega al primer
   * destino del grupo — un rótulo que solo abre y cierra obliga a dos
   * gestos para llegar a nada.
   */
  function handleGroupTriggerActivation(activationEvent) {
    const trigger = activationEvent.currentTarget ?? activationEvent.target;
    if (activationEvent.type === 'keydown' && activationEvent.key !== 'Enter' && activationEvent.key !== ' ') {
      return;
    }
    if (activationEvent.type === 'keydown') {
      activationEvent.preventDefault();
    }

    const groupId = trigger.getAttribute('data-group');
    const panelId = trigger.getAttribute('aria-controls');
    const panel = panelId === null ? null : findById(linksList, panelId);

    if (openGroupId === groupId) {
      const firstLink = panel === null ? null : firstAnchorIn(panel);
      if (firstLink !== null) {
        trigger.setAttribute('aria-expanded', 'false');
        if (panel !== null) {
          panel.setAttribute('aria-hidden', 'true');
          panel.removeAttribute('data-open');
        }
        openGroupId = null;
        activateLink(firstLink);
        return;
      }
    }

    closeGroupMenu();
    openGroupId = groupId;
    trigger.setAttribute('aria-expanded', 'true');
    if (panel !== null) {
      panel.setAttribute('aria-hidden', 'false');
      panel.setAttribute('data-open', 'true');
    }
  }

  /**
   * Forja un enlace de navegación con su distintivo de dictámenes cuando le
   * corresponde (SPEC-10). Mismo contrato de siempre: `data-view`,
   * `data-action` y el hash de ruta.
   */
  function buildLinkElement(navLink) {
    const linkElement = elementFactory('a');
    linkElement.setAttribute('href', navLink.hash);
    linkElement.setAttribute('data-view', navLink.view);
    linkElement.textContent = navLink.label;
    // Muelle de rol de menú (WCAG/APG): el rol vive en el enlace.
    linkElement.setAttribute('role', 'menuitem');
    linkElement.setAttribute('tabindex', '-1');

    if (navLink.action !== undefined) {
      // Acción reservada: señal explícita para store/orquestador (RF-05.2).
      linkElement.setAttribute('data-action', navLink.action);
      linkElement.setAttribute('data-reserved', 'true');
    }

    if (navLink.badge === 'vestibuleBadge') {
      // Distintivo del rótulo (SPEC-10, RF-01.1): portador informático
      // accesible; nace APAGADO y el orquestador lo enciende con datos.
      const badgeElement = elementFactory('span');
      badgeElement.setAttribute('class', 'nav-link__badge');
      badgeElement.setAttribute('data-badge', 'vestibuleBadge');
      badgeElement.setAttribute('role', 'status');
      linkElement.appendChild(badgeElement);
      vestibuleBadgeElement = badgeElement;
      renderVestibuleBadge();
    }

    linkElement.addEventListener('click', handleLinkActivation);
    linkElement.addEventListener('keydown', handleLinkActivation);
    trackListener(linkElement);
    return linkElement;
  }

  /**
   * Forja un <li> por cada enlace (modo plano de RF-02.4).
   */
  function buildFlatLinkItem(navLink) {
    const item = elementFactory('li');
    item.setAttribute('class', 'site-nav__item');
    item.setAttribute('role', 'none');
    item.appendChild(buildLinkElement(navLink));
    return item;
  }

  /**
   * Forja un grupo de dominio: rótulo, panel y sus enlaces (SPEC-16).
   * Un grupo de un solo destino NO lleva submenú (caso límite 2).
   */
  function buildGroupItem(group, extraLinks = []) {
    const groupLinks = [...group.links, ...extraLinks];
    const item = elementFactory('li');
    item.setAttribute('class', 'site-nav__group');
    item.setAttribute('data-group', group.id);

    // Grupo de un solo destino: el rótulo ES el enlace (sin clic inútil).
    if (groupLinks.length === 1) {
      const linkElement = buildLinkElement(groupLinks[0]);
      linkElement.setAttribute('class', 'site-nav__link site-nav__group-link');
      item.appendChild(linkElement);
      return item;
    }

    const menuId = navGroupMenuId(group.id);

    const trigger = elementFactory('button');
    trigger.setAttribute('type', 'button');
    trigger.setAttribute('class', 'site-nav__group-trigger');
    trigger.setAttribute('data-group', group.id);
    trigger.setAttribute('aria-expanded', 'false');
    trigger.setAttribute('aria-controls', menuId);
    trigger.textContent = group.label;
    trigger.addEventListener('click', handleGroupTriggerActivation);
    trigger.addEventListener('keydown', handleGroupTriggerActivation);
    trackListener(trigger);
    item.appendChild(trigger);

    const panel = elementFactory('ul');
    panel.setAttribute('id', menuId);
    panel.setAttribute('class', 'site-nav__group-menu');
    panel.setAttribute('role', 'menu');
    panel.setAttribute('aria-hidden', 'true');
    panel.setAttribute('aria-label', group.label);
    for (const link of groupLinks) {
      const linkItem = elementFactory('li');
      linkItem.setAttribute('role', 'none');
      linkItem.appendChild(buildLinkElement(link));
      panel.appendChild(linkItem);
    }
    item.appendChild(panel);

    return item;
  }

  /**
   * Los enlaces condicionales que el rol vigente concede, y el grupo que
   * los aloja (SPEC-16, RF-16.7). La Torre de Deliberación va a «Sala de
   * Trabajo» porque es herramienta del oficio, no lugar de lectura.
   */
  function conditionalLinksForGroup(groupId) {
    if (currentUserRole === null || currentUserRole === undefined) return [];
    return CONDITIONAL_NAV_LINKS.filter(
      (condLink) => condLink.requiredRoles.includes(currentUserRole) && navGroupForConditional(condLink) === groupId,
    );
  }

  /**
   * Descubre los enlaces que el render actual debe pintar, agrupados o
   * planos. Se calcula una vez por render para que `setSession` y `render`
   * no puedan discrepar sobre qué destinos existen.
   */
  function buildDestinationPlan() {
    const conditionalLinks = currentUserRole === null || currentUserRole === undefined
      ? []
      : CONDITIONAL_NAV_LINKS.filter((condLink) => condLink.requiredRoles.includes(currentUserRole));
    return conditionalLinks;
  }

  /**
   * Vacía la lista de forma nativa cuando se puede; el splicing cubre el
   * DOM simulado de los arneses, que carece de replaceChildren.
   */
  function clearLinksList() {
    if (linksList === null) return;
    if (typeof linksList.replaceChildren === 'function') {
      linksList.replaceChildren();
      return;
    }
    for (const childNode of [...(linksList.children ?? [])]) {
      if (typeof childNode.remove === 'function') {
        childNode.remove();
      } else {
        const childIndex = linksList.children.indexOf(childNode);
        if (childIndex !== -1) linksList.children.splice(childIndex, 1);
      }
    }
  }

  /**
   * Pinta los enlaces persistentes y cablea el botón del menú.
   * Idempotente: puede relanzarse al cambiar el rol (visitante/autenticado)
   * o la disposición (escritorio/móvil).
   */
  function render() {
    linksList = linksList ?? navRoot.querySelector('#navLinks');
    toggleButton = toggleButton ?? navRoot.querySelector('#navToggle');

    // Reconstrucción limpia (el rol y la disposición pueden haber cambiado).
    clearLinksList();
    openGroupId = null;

    const conditionalLinks = buildDestinationPlan();

    if (currentLayout === NAV_LAYOUTS.FLAT) {
      // RF-02.4: por debajo del quiebre, la lista completa y plana de siempre.
      linksList.setAttribute('class', 'site-nav__links site-nav__links--flat');
      const flatLinks = [...NAV_LINKS, ...conditionalLinks];
      for (const navLink of flatLinks) {
        linksList.appendChild(buildFlatLinkItem(navLink));
      }
    } else {
      // RF-16.2: tres dominios + «Inicio» suelto.
      linksList.setAttribute('class', 'site-nav__links site-nav__groups');
      for (const group of NAV_GROUPS) {
        linksList.appendChild(buildGroupItem(group, conditionalLinksForGroup(group.id)));
      }
    }

    if (toggleButton !== null && !shellListenersBound) {
      shellListenersBound = true;
      toggleButton.addEventListener('click', toggleMobileMenu);
      // Defensa de accesibilidad: el shell porta aria-controls; si faltara,
      // el componente lo asegura (RF-02.4).
      if (!toggleButton.hasAttribute('aria-controls') && linksList !== null) {
        toggleButton.setAttribute('aria-controls', linksList.getAttribute('id') ?? 'navLinks');
      }
      navRoot.addEventListener('keydown', handleMenuKeydown);
    }
  }

  /**
   * Sincroniza la cabecera con el vínculo vivo y el rol judicial (RF-02.1, RF-02.3, RF-05.4).
   *
   * Idempotente: si la bandera y el rol no cambian, no toca el DOM. Cuando cambian
   * (sesión que nace o muere, o rol que muta), vuelve a pintar la lista —`render()` está
   * declarado idempotente justo para esto— dejando enlaces nuevos con sus
   * manejadores limpios, sin apilar listeners.
   *
   * @param {boolean} isAuthenticated ¿hay vínculo consagrado activo?
   * @param {string|null} [role=null] Rol técnico del usuario autenticado.
   */
  function setSession(isAuthenticated, role = null) {
    const nextFlag = isAuthenticated === true;
    if (nextFlag === sessionIsAuthenticated && role === currentUserRole) {
      return;
    }
    sessionIsAuthenticated = nextFlag;
    currentUserRole = role;

    // Si la lista aún no se ha pintado (primer render pendiente), la bandera
    // queda asentada para que el primer render ya nazca con el estado real.
    if (linksList !== null) {
      render();
    }
  }

  /**
   * Cambia la disposición de la cabecera (SPEC-16, RF-16.5). El orquestador
   * la llama al cruzar el quiebre de 1024 px: en escritorio rigen los grupos,
   * en pantalla estrecha la lista plana de RF-02.4.
   * @param {string} nextLayout 'grouped' | 'flat'.
   */
  function setLayout(nextLayout) {
    const normalized = nextLayout === NAV_LAYOUTS.FLAT ? NAV_LAYOUTS.FLAT : NAV_LAYOUTS.GROUPED;
    if (normalized === currentLayout) return;
    currentLayout = normalized;
    if (linksList !== null) {
      render();
    }
  }

  /** Baja limpia de listeners del componente. */
  function destroy() {
    for (const node of mountedNodes) {
      node.removeEventListener?.('click', handleLinkActivation);
      node.removeEventListener?.('keydown', handleLinkActivation);
      node.removeEventListener?.('click', handleGroupTriggerActivation);
      node.removeEventListener?.('keydown', handleGroupTriggerActivation);
    }
    mountedNodes.clear();
    toggleButton?.removeEventListener('click', toggleMobileMenu);
    navRoot.removeEventListener('keydown', handleMenuKeydown);
    shellListenersBound = false;
    openGroupId = null;
  }

  /**
   * Fija la cifra del rótulo de dictámenes (SPEC-10, RF-01.1) y repinta el
   * distintivo. Idempotente; el orquestador es la única autoridad del número.
   *
   * @param {number} count Veredictos terminales sin contemplar (0 = apagado).
   */
  function setVestibuleBadgeCount(count) {
    const nextCount = Number(count) || 0;
    if (nextCount === vestibuleBadgeCount) return;
    vestibuleBadgeCount = nextCount;
    renderVestibuleBadge();
  }

  return {
    render,
    setSession,
    destroy,
    // Expuestos para pruebas y orquestador:
    closeMobileMenu,
    closeGroupMenu,
    handleMenuKeydown,
    setVestibuleBadgeCount,
    setLayout,
  };
}

/* ---------------------------------------------------------------------
   Barridos sobre el DOM que funcionan en el navegador y en los DOM
   simulados de los arneses (que carecen de querySelectorAll).
   --------------------------------------------------------------------- */

/**
 * ¿El nodo porta la clase? El DOM real responde por classList; los DOM
 * simulados de los arneses guardan las clases en `classes` (vía el setter
 * `className`) o en el atributo `class` (vía setAttribute, que es la vía
 * que usa este componente). Consultar los tres evita falsos negativos.
 */
function nodeHasClass(node, className) {
  if (node?.classes?.has?.(className) === true) return true;
  if (node?.classList?.contains?.(className) === true) return true;
  const attributeClass = node?.getAttribute?.('class');
  if (typeof attributeClass !== 'string' || attributeClass === '') return false;
  return attributeClass.split(/\s+/).filter(Boolean).includes(className);
}

/** Primer descendiente que porta la clase dada. */
function findByClass(root, className) {
  for (const child of root?.children ?? []) {
    if (nodeHasClass(child, className)) return child;
    const found = findByClass(child, className);
    if (found !== null) return found;
  }
  return null;
}

/** Todos los descendientes que portan la clase dada. */
function findAllByClass(root, className) {
  const found = [];
  for (const child of root?.children ?? []) {
    if (nodeHasClass(child, className)) found.push(child);
    found.push(...findAllByClass(child, className));
  }
  return found;
}

/** Descendiente con el id dado. */
function findById(root, elementId) {
  for (const child of root?.children ?? []) {
    if (child.getAttribute?.('id') === elementId) return child;
    const found = findById(child, elementId);
    if (found !== null) return found;
  }
  return null;
}

/** Primer enlace (<a>) del subárbol, en orden de documento. */
function firstAnchorIn(root) {
  for (const child of root?.children ?? []) {
    if (child.tagName === 'A') return child;
    const found = firstAnchorIn(child);
    if (found !== null) return found;
  }
  return null;
}

/**
 * Casa un enlace condicional con el grupo que debe-lo alojar (SPEC-16,
 * RF-16.7). La Torre de Deliberación es herramienta del oficio.
 */
function navGroupForConditional(condLink) {
  return condLink.view === 'tower' ? 'oficio' : 'oficio';
}
