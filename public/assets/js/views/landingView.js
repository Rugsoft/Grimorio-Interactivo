/**
 * landingView.js — Vista de Portada del Grimorio Interactivo (Tarea 5.1).
 *
 * RF-01.1: narrativa introductoria solemne del santuario.
 * RF-01.4: botón destacado «Consagrar Linaje» — acción RESERVADA que el
 *          orquestador intercepta para desplegar el diálogo «Cruzar el
 *          Umbral» (accessModalComponent, Tarea 4.4) reteniendo la intención.
 * RF-01.5: galería con los 3 hechizos destacados (más recientes validados o
 *          pergaminos primordiales) obtenidos de `GET /portal/featured`,
 *          reutilizando spellCardComponent (Tarea 4.2).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Modules nativos; composición por
 *     inyección de dependencias (cliente HTTP y fábrica de elementos) para
 *     verificar sin navegador.
 *   - Artículo IV: textos solemnes en castellano.
 *   - Artículo V: identificadores en inglés camelCase; comentarios castellanos.
 *
 * Seguridad (AGENTS.md 6.1): esta vista jamás construye markup con strings;
 * delega el render de datos en spellCardComponent (textContent puro).
 */

import { createSpellCardComponent } from '../components/spellCardComponent.js';

/** Acción reservada que emite el CTA de consagración (contrato del orquestador). */
export const CONSECRATION_ACTION = 'joinClan';

/**
 * Crea la vista de portada.
 *
 * @param {HTMLElement} mountRoot Punto de montaje (`<main id="app">`).
 * @param {Object} options
 * @param {Object} options.spellClient Cliente HTTP (Tarea 3.3; necesita fetchFeatured).
 * @param {(action: string) => void} options.onReservedAction Notifica acciones reservadas
 *        (el orquestador abrirá «Cruzar el Umbral» con la intención retenida).
 * @param {(slug: string) => void} options.onSpellSelect Notifica la selección de un destacado.
 * @param {(tagName: string) => HTMLElement} [options.elementFactory] Fábrica inyectable (tests).
 * @returns {Object} API: { render, destroy }.
 */
export function createLandingView(mountRoot, options) {
  const {
    spellClient,
    onReservedAction,
    onSpellSelect,
    elementFactory = (tagName) => document.createElement(tagName),
  } = options;

  /** Nodos vivos de la vista, para limpieza determinista en destroy(). */
  const mountedNodes = [];

  /** Registra un nodo como hijo de la vista para su ciclo de vida. */
  function track(node) {
    mountedNodes.push(node);
    return node;
  }

  /** Añade un nodo de texto seguro (nunca innerHTML, AGENTS.md 6.1). */
  function appendTextElement(parent, tagName, className, text) {
    const node = elementFactory(tagName);
    node.className = className;
    node.textContent = text;
    parent.appendChild(node);
    return track(node);
  }

  /** Manejador del CTA «Consagrar Linaje»: emite la acción reservada. */
  function handleConsacration(activationEvent) {
    if (activationEvent.type === 'keydown') {
      if (activationEvent.key !== 'Enter' && activationEvent.key !== ' ') return;
      activationEvent.preventDefault?.();
    }
    onReservedAction?.(CONSECRATION_ACTION);
  }

  /**
   * Construye el héroe: narrativa + CTA de consagración (RF-01.1, RF-01.4).
   */
  function buildHero() {
    const hero = track(elementFactory('section'));
    hero.className = 'landing-hero';

    appendTextElement(hero, 'h1', 'landing-hero__title', 'Grimorio Interactivo');
    appendTextElement(
      hero,
      'p',
      'landing-hero__intro',
      'Un santuario de saber arcano, forjado por los linajes que aún recuerdan los conjuros antiguos. Consulta los pergaminos validados por los Maestros, o conságrate y deja tu huella en el grimorio eterno.',
    );

    const consacrationButton = track(elementFactory('button'));
    consacrationButton.type = 'button';
    consacrationButton.className = 'landing-hero__cta button button--primary';
    consacrationButton.textContent = 'Consagrar Linaje';
    // Contrato de interceptación con el orquestador (RF-01.4 → Tarea 4.4):
    consacrationButton.setAttribute('data-reserved', 'true');
    consacrationButton.setAttribute(
      'aria-label',
      'Consagrar Linaje: abre el umbral de acceso para vincular tu linaje',
    );
    consacrationButton.addEventListener('click', handleConsacration);
    consacrationButton.addEventListener('keydown', handleConsacration);
    hero.appendChild(consacrationButton);

    return hero;
  }

  /**
   * Construye la sección contenedora de la galería de destacados (RF-01.5).
   */
  function buildFeaturedSection() {
    const section = track(elementFactory('section'));
    section.className = 'landing-view__featured';
    section.setAttribute('aria-label', 'Hechizos destacados del santuario');

    appendTextElement(section, 'h2', 'landing-view__featured-title', 'Pergaminos Destacados');
    appendTextElement(
      section,
      'p',
      'landing-view__featured-hint',
      'Los tres conjuros más recientes validados por los Maestros, junto a los pergaminos primordiales de génesis.',
    );

    return section;
  }

  /**
   * Renderiza las tarjetas de destacados reutilizando spellCardComponent.
   * @param {HTMLElement} featuredSection Sección contenedora ya montada.
   * @param {Array<object>} spells Lista de SpellSummaryDto retornada por la API.
   */
  function renderFeaturedCards(featuredSection, spells) {
    const grid = track(elementFactory('div'));
    grid.className = 'landing-view__grid';
    featuredSection.appendChild(grid);

    for (const spellSummaryDto of spells) {
      const card = createSpellCardComponent(spellSummaryDto, {
        onSpellSelect: (slug, originElement) => onSpellSelect?.(slug, originElement),
        elementFactory,
      });
      grid.appendChild(card);
      track(card);
    }
  }

  /**
   * Monta la vista completa: héroe + galería de los 3 destacados.
   * Idempotente: limpia el montaje previo antes de renderizar.
   */
  async function render() {
    // Idempotencia: la re-renderización no duplica nodos.
    destroy(false);

    const view = track(elementFactory('div'));
    // El tomo central acota la portada a 1280 px (Tarea 2.1, RF-05.1).
    view.className = 'landing-view grimoire-tomo-container';
    mountRoot.appendChild(view);

    view.appendChild(buildHero());

    const featuredSection = buildFeaturedSection();
    view.appendChild(featuredSection);

    // Estado de invocación mientras la API responde (plan 6, degradación).
    const loading = appendTextElement(
      featuredSection,
      'p',
      'landing-view__loading',
      'Invocando los pergaminos destacados…',
    );

    const result = await spellClient.fetchFeatured();

    // La vista puede haber sido destruida mientras la petición volaba.
    if (!mountedNodes.includes(view)) return;

    loading.remove();

    // Error controlado del cliente HTTP (jamás lanza, Tarea 3.3).
    if (!result.success) {
      const errorBlock = track(elementFactory('div'));
      errorBlock.className = 'landing-view__error';
      errorBlock.setAttribute('role', 'alert');

      appendTextElement(
        errorBlock,
        'p',
        'landing-view__error-message',
        'La corriente de maná se ha interrumpido: los pergaminos destacados no responden.',
      );

      const retryButton = track(elementFactory('button'));
      retryButton.type = 'button';
      retryButton.className = 'landing-view__error-retry button button--secondary';
      retryButton.textContent = 'Reintentar invocación';
      retryButton.addEventListener('click', () => render());
      errorBlock.appendChild(retryButton);

      featuredSection.appendChild(errorBlock);
      return;
    }

    renderFeaturedCards(featuredSection, result.data);
  }

  /**
   * Retira la vista del punto de montaje y libera sus nodos.
   * @param {boolean} [removeFromRoot=true] El render interno lo invoca con
   *        false para limpiar la renderización previa antes de volver a montar.
   */
  function destroy(removeFromRoot = true) {
    for (const node of mountedNodes.splice(0)) {
      node.remove?.();
    }
    if (removeFromRoot) {
      // Barrido de seguridad: retira cualquier resto de vista previa del montaje.
      for (const child of [...(mountRoot.children ?? [])]) {
        if (String(child.className ?? '').includes('landing-')) {
          child.remove?.();
        }
      }
    }
  }

  return { render, destroy };
}
