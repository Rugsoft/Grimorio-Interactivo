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
import { createClanBannerComponent, CLAN_BANNER_VARIANTS } from '../components/clanBannerComponent.js';
// La firma de la portada (SPEC-16, Tarea 4): el sello de validación.
import { createValidationSigilComponent } from '../components/landingSigilComponent.js';

/** Acción reservada que emite el CTA de consagración (contrato del orquestador). */
export const CONSECRATION_ACTION = 'joinClan';

/**
 * Descriptor del CTA tal y como SPEC-01 lo fijó, para el modo legado
 * (opción `heroCallToAction` no pasada).
 *
 * SPEC-17 retira este rótulo del camino real: «Consagrar Linaje» solo
 * describe a un peregrino, y su etiqueta prometía un umbral que no se abría
 * para quien ya estaba dentro. Se conserva intacto, y con su `aria-label`
 * histórico, **solo** para que los arneses de SPEC-01 sigan teniendo el
 * contrato que verifican. Borrarlo no arregla nada: convierte arneses legados
 * en rojo y esconde el defecto en lugar de corregirlo.
 */
const LEGACY_CONSECRATION_DESCRIPTOR = Object.freeze({
  state: 'legacy',
  label: 'Consagrar Linaje',
  ariaLabel: 'Consagrar Linaje: abre el umbral de acceso para vincular tu linaje',
  action: 'openAccess',
  target: null,
});

/**
 * Crea la vista de portada.
 *
 * @param {HTMLElement} mountRoot Punto de montaje (`<main id="app">`).
 * @param {Object} options
 * @param {Object} options.spellClient Cliente HTTP (Tarea 3.3; necesita fetchFeatured).
 * @param {Object} [options.dominionClient] Cliente del Dominio (Tarea 5.1; necesita
 *        fetchLeaderboard). Con él, la cabecera del portal exhibe el blasón del
 *        Clan Regente (SPEC-07, Tarea 5.2); sin él, el portal no cambia.
 * @param {(action: string) => void} options.onReservedAction Notifica acciones reservadas
 *        (el orquestador abrirá «Cruzar el Umbral» con la intención retenida).
 * @param {(slug: string) => void} options.onSpellSelect Notifica la selección de un destacado.
 * @param {(clanId: string) => void} [options.onRegentSelect] Notifica la selección del linaje reinante.
 * @param {(tagName: string) => HTMLElement} [options.elementFactory] Fábrica inyectable (tests).
 * @param {Document} [options.documentRef] Documento anfitrión del sello forjado
 *        del Clan Regente (arneses sin navegador).
 * @param {Readonly<object>|null} [options.heroCallToAction] CTA **ya resuelto**
 *        por `resolveHeroCallToAction` (SPEC-17, RF-17.1). Su AUSENCIA se
 *        distingue de su valor `null` a propósito: no pasarlo conserva el
 *        comportamiento de SPEC-01 (siempre «Consagrar Linaje»), que es lo que
 *        mantienen los arneses existentes; pasarlo en `null` significa «esta
 *        persona no tiene ningún acto pendiente» y el botón **no se crea**
 *        (RF-17.4). Confundir ambos convertiría cada arnés legado en rojo.
 * @param {(viewName: string) => void} [options.onNavigateRequest] Pide al
 *        orquestador navegar a una vista interna. Lo usan los estados de CTA
 *        que no pasan por el Umbral (RF-17.3).
 * @returns {Object} API: { render, destroy, setHeroCallToAction }.
 */
export function createLandingView(mountRoot, options) {
  const {
    spellClient,
    dominionClient = null,
    onReservedAction,
    onSpellSelect,
    onRegentSelect,
    elementFactory = (tagName) => globalThis.document.createElement(tagName),
    documentRef = globalThis.document,
    onNavigateRequest,
  } = options;

  // La ausencia de la opción NO es lo mismo que su valor `null` (SPEC-17,
  // Tarea 2). Sin opción, la vista se comporta como en SPEC-01 y todos los
  // arneses legados siguen encontrándole su botón. Con `null` explícito, no
  // hay botón porque no hay acto que ofrecer.
  const usesLegacyCallToAction = !Object.prototype.hasOwnProperty.call(options, 'heroCallToAction');
  // UNA sola fuente de verdad para el descriptor efectivo. La versión previa
  // lo calculaba dentro del manejador del clic, y en modo legado devolvía
  // `null` —el clic no hacía nada—: un fallo que el arnés de SPEC-01 cazó en
  // el acto. El descriptor se resuelve al construir la vista y se guarda.
  let heroCallToAction = usesLegacyCallToAction
    ? LEGACY_CONSECRATION_DESCRIPTOR
    : options.heroCallToAction;

  /** Nodos vivos del héroe, para poder rehidratar el CTA sin repintar todo. */
  let heroSection = null;
  let heroCtaButton = null;

  /** Blasón del Clan Regente montado en la cabecera, si procede (Tarea 5.2). */
  let regentBanner = null;

  /** Sello de validación de la portada (SPEC-16, Tarea 4). */
  let sigil = null;

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

  /**
   * Monta el blasón del Clan Regente en la CABECERA del Gran Portal
   * (SPEC-07, Tarea 5.2). Solo se monta si el orquestador inyectó el cliente
   * del Dominio: sin él, el portal se renderiza exactamente como antes.
   *
   * @param {HTMLElement} view Raíz de la portada.
   */
  async function mountRegentBanner(view) {
    if (dominionClient === null) return;

    const bannerSlot = track(elementFactory('div'));
    bannerSlot.className = 'landing-view__regent';
    view.appendChild(bannerSlot);

    regentBanner = createClanBannerComponent(bannerSlot, {
      dominionClient,
      onRegentSelect,
      elementFactory,
      documentRef,
      // La portada pide la cinta de una línea (SPEC-16, RF-18.7). El Salón de
      // Linajes y la ficha de clan siguen pidiendo la ficha heráldica de
      // SPEC-07, que queda intacta.
      variant: CLAN_BANNER_VARIANTS.COMPACT,
    });
    await regentBanner.render();
  }

  /**
   * Despacha el CTA según lo que el descriptor resuelto ordene (SPEC-17).
   *
   * `openAccess` es el ÚNICO camino que abre el Umbral, y solo lo usa el estado
   * anónimo. Los demás navegan dentro del santuario porque quien los pulsa ya
   * está dentro: mandarle a «cruzar el umbral» era el callejón sin salida que
   * esta spec vino a cerrar (RF-17.3).
   *
   * @param {MouseEvent|KeyboardEvent} activationEvent Evento de activación.
   */
  function handleCallToAction(activationEvent) {
    const descriptor = heroCallToAction;
    if (descriptor === null || descriptor === undefined) return;

    if (activationEvent.type === 'keydown') {
      if (activationEvent.key !== 'Enter' && activationEvent.key !== ' ') return;
      activationEvent.preventDefault?.();
    }

    if (descriptor.action === 'openAccess') {
      // Contrato de interceptación con el orquestador (RF-01.4 → Tarea 4.4):
      // la intención queda retenida y el Umbral se abre.
      onReservedAction?.(CONSECRATION_ACTION);
      return;
    }

    // Acción de navegación interna: la vista pide el destino y el
    // orquestador decide, que es quien conoce el interceptor de retención de
    // SPEC-09 y no esta vista.
    if (typeof descriptor.target === 'string' && descriptor.target !== '') {
      onNavigateRequest?.(descriptor.target);
    }
  }

  /**
   * Pinta el botón del CTA con el descriptor dado. Nodo nuevo, en su hueco.
   *
   * @param {Readonly<object>} descriptor Descriptor resuelto del CTA.
   * @returns {HTMLElement|null} El botón forjado, o `null` si no hay CTA.
   */
  function buildCallToAction(descriptor) {
    if (descriptor === null || descriptor === undefined) return null;
    if (typeof descriptor.label !== 'string' || descriptor.label.trim() === '') return null;

    const button = track(elementFactory('button'));
    button.type = 'button';
    button.className = 'landing-hero__cta button button--primary';
    button.textContent = descriptor.label;
    // Contrato de interceptación con el orquestador (RF-01.4 → Tarea 4.4). No
    // lo lee ningún código, pero SPEC-01 lo declara y su arnés lo verifica;
    // quitarlo sin decirlo sería un cambio de contrato disfrazado de refactor.
    button.setAttribute('data-reserved', 'true');
    button.setAttribute('aria-label', descriptor.ariaLabel ?? descriptor.label);
    button.addEventListener('click', handleCallToAction);
    button.addEventListener('keydown', handleCallToAction);
    return button;
  }

  /**
   * Rehidrata el CTA sin repintar la portada (SPEC-17, RF-17.6).
   *
   * Es lo que permite que el Umbral, al completarse, cambie el rótulo de la
   * portada que tenía detrás sin recarga. La AUSENCIA también se alcanza en
   * caliente: si el nuevo descriptor es `null`, el botón se retira del DOM en
   * lugar de quedar deshabilitado (RF-17.4).
   *
   * @param {Readonly<object>|null} descriptor Descriptor ya resuelto, o `null`.
   */
  function setHeroCallToAction(descriptor) {
    if (usesLegacyCallToAction) return;
    heroCallToAction = descriptor ?? null;
    // Antes de que el héroe exista no hay nada que rehidratar: `render()`
    // leerá el descriptor nuevo al construirlo. Tocar el DOM antes de que
    // exista es justo el fallo que el riesgo nº3 anticipa.
    if (heroSection === null) return;

    const nextButton = buildCallToAction(heroCallToAction);
    if (nextButton === null) {
      heroCtaButton?.remove?.();
      heroCtaButton = null;
      return;
    }
    if (heroCtaButton === null) {
      heroSection.appendChild(nextButton);
      heroCtaButton = nextButton;
      return;
    }
    // El botón ya existe: se le cambia el rótulo y la etiqueta en su sitio, y
    // se retira el anterior para no acumular escuchas.
    heroCtaButton.remove?.();
    heroSection.appendChild(nextButton);
    heroCtaButton = nextButton;
  }

  /**
   * Construye el héroe: narrativa + CTA (RF-01.1, RF-01.4, SPEC-17 RF-17.1).
   */
  function buildHero() {
    const hero = track(elementFactory('section'));
    hero.className = 'landing-hero';

    appendTextElement(hero, 'h1', 'landing-hero__title', 'Grimorio Interactivo');
    // Una sola línea de presentación (SPEC-16, RF-18.3). El texto anterior
    // tenía dos frases y la segunda repetía en prosa lo que la página ya
    // muestra por sí sola: los pergaminos están justo debajo y el CTA dice
    // «Consagrar Linaje». Se conserva la frase que sí presenta el santuario.
    appendTextElement(
      hero,
      'p',
      'landing-hero__intro',
      'Un santuario de saber arcano, forjado por los linajes que aún recuerdan los conjuros antiguos.',
    );

    const callToAction = buildCallToAction(heroCallToAction);
    if (callToAction !== null) {
      hero.appendChild(callToAction);
      heroCtaButton = callToAction;
    }

    heroSection = hero;
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

    // La FIRMA de la portada (SPEC-16, RF-18.1 y RF-18.4): el sello de
    // validación abre el Gran Portal. Va el primero, antes que el héroe y
    // con independencia del Dominio, porque no describe un estado que
    // pueda faltar: describe el propio tomo.
    sigil = createValidationSigilComponent(view, { elementFactory, documentRef });
    sigil.render();

    // La tesis va antes del estado (SPEC-16, RF-18.1, remedio de D5): el
    // héroe se monta y se pinta ANTES de que el Dominio responda. Antes el
    // blasón se resolvía primero y la portada no mostraba nada hasta que
    // terminaba su consulta, de modo que un Dominio lento suponía una
    // portada en blanco. Ahora la espera del blasón ocurre con el héroe ya
    // en el documento.
    view.appendChild(buildHero());

    // Cinta del Regente: TERCER bloque, nunca por delante de la tesis
    // (Tarea 5.2 + SPEC-16 RF-18.1). Sin cliente del Dominio NO se cede el
    // turno y la portada conserva la sincronía de SPEC-01 (lo verifica su
    // arnés hermano).
    if (dominionClient !== null) {
      await mountRegentBanner(view);
    }

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
    // El blasón primero: cancela cualquier consulta suya en vuelo (Tarea 5.2).
    heroSection = null;
    heroCtaButton = null;
    sigil?.destroy?.();
    sigil = null;
    regentBanner?.destroy?.();
    regentBanner = null;
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

  return { render, destroy, setHeroCallToAction };
}
