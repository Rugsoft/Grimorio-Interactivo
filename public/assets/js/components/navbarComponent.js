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
 */

/** Enlaces persistentes de la cabecera (orden del plan, RF-02.1). */
export const NAV_LINKS = Object.freeze([
  { view: 'landing', hash: '#/', label: 'Inicio' },
  { view: 'library', hash: '#/biblioteca', label: 'Biblioteca de Hechizos' },
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

/**
 * Fábrica del componente de navegación.
 *
 * @param {HTMLElement} navRoot Elemento <nav id="siteNav"> del shell.
 * @param {object} componentOptions Contratos y fábricas:
 *   - isAuthenticated: ¿hay vínculo activo (rol >= editor)? Bandera INICIAL:
 *     el orquestador la mantiene viva con setSession().
 *   - onNavigate(view): navegación pública a una vista de la SPA.
 *   - onReservedAction(action): interceptación — abrir «Cruzar el Umbral».
 *   - elementFactory (opcional): fábrica de elementos (por defecto
 *     document.createElement; las pruebas inyectan la suya).
 * @returns {object} { render, setSession, destroy, closeMobileMenu, handleMenuKeydown }.
 */
export function createNavbarComponent(navRoot, componentOptions) {
  const {
    isAuthenticated = false,
    userRole = null,
    onNavigate,
    onReservedAction,
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
   * Escape sobre la navegación recoge el menú (accesibilidad, RNF-03).
   * @param {KeyboardEvent} keydownEvent Evento de teclado del nav.
   */
  function handleMenuKeydown(keydownEvent) {
    if (keydownEvent.key === 'Escape') {
      closeMobileMenu();
      toggleButton?.focus();
    }
  }

  /**
   * Pinta los enlaces persistentes y cablea el botón del menú.
   * Idempotente: puede relanzarse al cambiar el rol (visitante/autenticado).
   */
  function render() {
    linksList = linksList ?? navRoot.querySelector('#navLinks');
    toggleButton = toggleButton ?? navRoot.querySelector('#navToggle');

    // Reconstrucción limpia de la lista (el rol puede haber cambiado).
    // replaceChildren() es la vía nativa segura: HTMLCollection.length es
    // de solo lectura en el DOM real; el simulado carece del método.
    if (typeof linksList.replaceChildren === 'function') {
      linksList.replaceChildren();
    } else {
      // DOM simulado: remove() puede no existir; el splicing garantiza el
      // vaciado aunque el arnés carezca del método nativo.
      for (const childNode of [...(linksList.children ?? [])]) {
        if (typeof childNode.remove === 'function') {
          childNode.remove();
        } else {
          const childIndex = linksList.children.indexOf(childNode);
          if (childIndex !== -1) linksList.children.splice(childIndex, 1);
        }
      }
    }

    const activeLinks = [...NAV_LINKS];
    if (currentUserRole !== null && currentUserRole !== undefined) {
      for (const condLink of CONDITIONAL_NAV_LINKS) {
        if (condLink.requiredRoles.includes(currentUserRole)) {
          activeLinks.push(condLink);
        }
      }
    }

    for (const navLink of activeLinks) {
      // Fábrica inyectable: el navegador usa document.createElement;
      // las pruebas suministran su DOM simulado.
      const linkElement = elementFactory('a');
      linkElement.setAttribute('href', navLink.hash);
      linkElement.setAttribute('data-view', navLink.view);
      linkElement.textContent = navLink.label;

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
      linksList.appendChild(linkElement);
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

  /** Baja limpia de listeners del componente. */
  function destroy() {
    for (const linkElement of linksList?.children ?? []) {
      linkElement.removeEventListener('click', handleLinkActivation);
      linkElement.removeEventListener('keydown', handleLinkActivation);
    }
    toggleButton?.removeEventListener('click', toggleMobileMenu);
    navRoot.removeEventListener('keydown', handleMenuKeydown);
    shellListenersBound = false;
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
    handleMenuKeydown,
    setVestibuleBadgeCount,
  };
}
