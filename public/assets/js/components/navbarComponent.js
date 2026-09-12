/**
 * navbarComponent.js — Barra de navegación persistente (Tarea 4.1).
 *
 * RF-02.1: enlaces persistentes (Inicio, Biblioteca, Salón de Linajes,
 *          Creador de Hechizos) en la cabecera del shell.
 * RF-02.3: el Creador es acción reservada para visitantes — su activación
 *          dispara onReservedAction para que el orquestador abra el diálogo
 *          «Cruzar el Umbral» reteniendo la intención (plan 4.1).
 * RF-02.4: menú desplegable arcano en pantallas estrechas, accesible por
 *          teclado (aria-expanded, aria-controls, Escape).
 *
 * Constitución:
 *   - Artículo I: ES Modules nativos, sin frameworks.
 *   - Artículo IV: textos visibles solemnes en castellano.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * Diseño: el componente recibe el <nav> raíz por inyección (testeable sin
 * navegador) y delega SIEMPRE en callbacks — nunca abre modales por su cuenta.
 */

/** Enlaces persistentes de la cabecera (orden del plan, RF-02.1). */
export const NAV_LINKS = Object.freeze([
  { view: 'landing', hash: '#/', label: 'Inicio' },
  { view: 'library', hash: '#/biblioteca', label: 'Biblioteca de Hechizos' },
  { view: 'clans', hash: '#/linajes', label: 'Salón de Linajes' },
  { view: 'creator', hash: '#/creador', label: 'Creador de Hechizos', action: 'openCreator' },
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
 *   - isAuthenticated: ¿hay vínculo activo (rol >= editor)?
 *   - onNavigate(view): navegación pública a una vista de la SPA.
 *   - onReservedAction(action): interceptación — abrir «Cruzar el Umbral».
 *   - elementFactory (opcional): fábrica de elementos (por defecto
 *     document.createElement; las pruebas inyectan la suya).
 * @returns {object} { render, destroy }.
 */
export function createNavbarComponent(navRoot, componentOptions) {
  const {
    isAuthenticated = false,
    onNavigate,
    onReservedAction,
    elementFactory = (tagName) => document.createElement(tagName),
  } = componentOptions;

  /** Elementos del shell cableados en el primer render. */
  let linksList = null;
  let toggleButton = null;
  let menuIsOpen = false;

  /** Guardia de binding: los re-renders no apilan listeners duplicados. */
  let shellListenersBound = false;

  /**
   * Activa un enlace: navega si es público, intercepta si está reservado.
   * @param {HTMLElement} linkElement Enlace pulsado.
   */
  function activateLink(linkElement) {
    const reservedAction = linkElement.getAttribute('data-action');
    const targetView = linkElement.getAttribute('data-view');

    if (reservedAction !== null && !isAuthenticated) {
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

    for (const navLink of NAV_LINKS) {
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

  return {
    render,
    destroy,
    // Expuestos para pruebas y orquestador:
    closeMobileMenu,
    handleMenuKeydown,
  };
}
