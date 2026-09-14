/**
 * elementalWheelComponent.js — Rueda Rúnica octogonal del Códice (SPEC-06).
 *
 * Tarea 3.2 (TASKS-06): dibuja en SVG nativo el octógono de los ocho
 * elementos canónicos (RF-01.1), ilumina rúnicamente los filamentos de
 * conexión al posar el cursor o pulsar un glifo (RF-01.2) y despliega la
 * lámina del Códice con el nombre litúrgico, la descripción mitológica y
 * el efecto táctico de cada reacción compatible — todo en noble castellano
 * (RNF-04), con datos leídos del catálogo del Códice (Artículo II).
 *
 * Tarea 3.3 (TASKS-06): adaptabilidad móvil (RF-01.1) — en pantallas
 * reducidas (<768 px) el octógono conmuta a un Selector Radial Táctil
 * compacto (radio orbital menor, glifos con área táctil de 44 px) asistido
 * por una Lámina de Acordeón Rúnico: una lista vertical de cabeceras por
 * elemento con exclusión mutua (una sola lámina abierta), sin solapamientos
 * ni desbordamiento horizontal. La conmutación es viva: voltea la media
 * query en caliente y reconstruye el DOM preservando la selección fija.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): SVG nativo y módulo ES; documento y
 *     catálogo llegan inyectados. Cero dependencias y cero fetch.
 *   - Artículo II: geometría, colores y aristas son datos del Códice.
 *   - Artículo IV: prosa mitológica en castellano solemne.
 *   - Artículo V: identificadores en inglés camelCase; documentación en
 *     castellano.
 *
 * Estructura DOM (raíz .elemental-wheel):
 *   <div class="elemental-wheel">
 *     <svg> <g.elemental-wheel__filament data-source data-target>* </g>
 *           <g.elemental-wheel__glyph data-element data-angle data-color data-glyph role=button tabindex=0><title>* </g>
 *     </svg>
 *     <div class="elemental-wheel__plate" aria-live="polite">…</div>
 *   </div>
 *
 * Cobertura: RF-01.1, RF-01.2, RNF-03 (geometría + título accesible),
 * RNF-04 (castellano solemne), RNF-01 (geometría determinista).
 */

/** Centro y radio canónicos del octógono (viewBox 200×200). */
const WHEEL_CENTER = { x: 100, y: 100 };
/** Radio orbital de escritorio. */
const GLYPH_ORBIT_RADIUS = 78;
/** Radio orbital compacto del selector táctil móvil. */
const MOBILE_ORBIT_RADIUS = 58;
/** Umbral canónico del viewport móvil, en px (RF-01.1). */
const MOBILE_BREAKPOINT_PX = 768;

/**
 * Crea la Rueda Rúnica.
 *
 * @param {object} [options]
 *   - document: fábrica DOM inyectable (por defecto, document global).
 *   - catalog: catálogo del Códice ({elements, reactions}) — el del
 *     Endpoint 1 o la matriz espejo de comboResolver.js. Obligatorio.
 * @returns {object} API: mount, highlightElement, clearHighlight,
 *   getSelectedElement, destroy.
 */
export function createElementalWheelComponent(options = {}) {
  // Fallback al documento global vía globalThis: una const local con el
  // mismo nombre no puede referenciarse a sí misma (TDZ), ni siquiera con
  // typeof, así que el respaldo se lee del objeto global.
  const document = options.document ?? globalThis.document;
  const catalog = options.catalog;
  if (!catalog || !Array.isArray(catalog.elements)) {
    throw new TypeError('La Rueda Rúnica exige el catálogo del Códice ({elements, reactions}).');
  }

  /**
   * Consulta de medios inyectable: devuelve el objeto con matches y
   * addEventListener (por defecto, matchMedia del viewport real). El arnés
   * la voltea en caliente para simular conmutaciones de dispositivo.
   */
  const mobileMediaQuery = options.matchMedia
    ?? (typeof window !== 'undefined' && typeof window.matchMedia === 'function'
      ? () => window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT_PX}px)`)
      : null);

  const elementById = new Map(catalog.elements.map((element) => [element.id, element]));
  /** @type {Element|null} */
  let wheelRoot = null;
  /** @type {string|null} Selección fija por pulso. */
  let selectedElementId = null;
  /** ¿Layout móvil vigente? */
  let mobileLayout = false;
  /** Elemento con su lámina de acordeón abierta en móvil. */
  let openAccordionElementId = null;
  /** Consulta de medios viva y su escucha (para la conmutación en vivo). */
  let activeMediaQuery = null;
  /** @type {Function|null} */
  let onMediaChange = null;

  /** ¿Está activo el layout móvil? */
  function isMobileLayout() {
    return mobileLayout;
  }

  /**
   * Posición polar de un glifo: el i-ésimo de los ocho, en ángulos de 45°
   * a partir de la cúspide (−90°). Determinista (RNF-01); el radio orbital
   * se compacta en móvil.
   * @param {number} index
   */
  function glyphPosition(index) {
    const orbitRadius = mobileLayout ? MOBILE_ORBIT_RADIUS : GLYPH_ORBIT_RADIUS;
    const angleDegrees = index * 45;
    const angleRadians = ((angleDegrees - 90) * Math.PI) / 180;
    return {
      x: WHEEL_CENTER.x + orbitRadius * Math.cos(angleRadians),
      y: WHEEL_CENTER.y + orbitRadius * Math.sin(angleRadians),
      angle: angleDegrees,
    };
  }

  /**
   * Reacciones duales en las que participa el elemento (el catalizador
   * unario jamás enciende filamentos duales).
   * @param {string} elementId
   */
  function dualReactionsOf(elementId) {
    return catalog.reactions.filter((reaction) => !reaction.isCatalyst && reaction.elements.includes(elementId));
  }

  /**
   * Emite un evento del plan 4.1 sobre el target.
   * @param {string} type
   * @param {object} detail
   */
  function emit(type, detail) {
    eventTarget.dispatchEvent(new CustomEvent(type, { detail }));
  }

  /**
   * Enciende los filamentos que conectan el elemento dado con sus pares
   * reactivos; con null, apaga todos.
   * @param {string|null} elementId
   */
  function renderFilaments(elementId) {
    if (wheelRoot === null) {
      return;
    }
    for (const filament of wheelRoot.querySelectorAll('.elemental-wheel__filament')) {
      const source = filament.getAttribute('data-source');
      const target = filament.getAttribute('data-target');
      const connects = elementId !== null && (source === elementId || target === elementId);
      if (connects) {
        filament.classList.add('elemental-wheel__filament--lit');
      } else {
        filament.classList.remove('elemental-wheel__filament--lit');
      }
    }
  }

  /**
   * Pinta la lámina del Códice para el elemento dado (o la retira).
   * En escritorio: lámina fija bajo la rueda. En móvil, la lámina vive en
   * el acordeón (esta función solo actúa en escritorio).
   * @param {string|null} elementId
   */
  function renderPlate(elementId) {
    if (wheelRoot === null || mobileLayout) {
      return;
    }
    let plate = wheelRoot.querySelector('.elemental-wheel__plate');
    if (elementId === null) {
      plate?.remove();
      return;
    }

    if (plate === null) {
      plate = document.createElement('div');
      plate.setAttribute('class', 'elemental-wheel__plate');
      plate.setAttribute('aria-live', 'polite');
      wheelRoot.appendChild(plate);
    }

    const element = elementById.get(elementId);

    const plateChildren = [];

    const title = document.createElement('h3');
    title.setAttribute('class', 'elemental-wheel__plate-title');
    title.textContent = element?.name ?? elementId;
    plateChildren.push(title);

    // Reutiliza el volcado de reacciones del acordeón (misma prosa).
    const reactionHost = document.createElement('div');
    renderReactionsInto(reactionHost, elementId);
    for (const child of reactionHost.children) {
      plateChildren.push(child);
    }

    // Reconstrucción limpia de la lámina (sin innerHTML: Dogma Vanilla/XSS).
    while (plate.children.length > 0) {
      plate.children[0].remove();
    }
    for (const child of plateChildren) {
      plate.appendChild(child);
    }
  }

  /**
   * Ilumina los filamentos y despliega la lámina del elemento dado.
   * @param {string} elementId
   */
  function highlightElement(elementId) {
    selectedElementId = elementId;
    renderFilaments(elementId);
    renderPlate(elementId);
    // Móvil (Tarea 3.3): la lámina vive en el acordeón — la preselección
    // despliega además la hoja del elemento solicitado (RF-01.3, Tarea
    // 4.4: «sus enlaces iluminados» incluyen la lámina en todo viewport).
    if (mobileLayout && elementId !== null) {
      openAccordionElementId = elementId;
      renderAccordionState();
    }
  }

  /** Despeja la selección, los filamentos y la lámina. */
  function clearHighlight() {
    selectedElementId = null;
    renderFilaments(null);
    renderPlate(null);
    if (mobileLayout) {
      openAccordionElementId = null;
      renderAccordionState();
    }
  }

  /** Elemento con selección fija (null si ninguno). */
  function getSelectedElement() {
    return selectedElementId;
  }

  /**
   * Construye el SVG del octógono con sus filamentos y glifos.
   */
  function buildWheelSvg() {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 200 200');
    svg.setAttribute('class', mobileLayout ? 'elemental-wheel__svg elemental-wheel__svg--compact' : 'elemental-wheel__svg');
    svg.setAttribute('data-orbit-radius', String(mobileLayout ? MOBILE_ORBIT_RADIUS : GLYPH_ORBIT_RADIUS));

    // Filamentos primero (debajo de los glifos).
    for (const reaction of catalog.reactions) {
      if (reaction.isCatalyst || reaction.elements.length !== 2) {
        continue;
      }
      const [sourceId, targetId] = reaction.elements;
      const sourceIndex = catalog.elements.findIndex((element) => element.id === sourceId);
      const targetIndex = catalog.elements.findIndex((element) => element.id === targetId);
      if (sourceIndex < 0 || targetIndex < 0) {
        continue;
      }
      const source = glyphPosition(sourceIndex);
      const target = glyphPosition(targetIndex);

      const filament = document.createElementNS('http://www.w3.org/2000/svg', 'line');
      filament.setAttribute('x1', String(source.x));
      filament.setAttribute('y1', String(source.y));
      filament.setAttribute('x2', String(target.x));
      filament.setAttribute('y2', String(target.y));
      filament.setAttribute('class', 'elemental-wheel__filament');
      filament.setAttribute('data-source', sourceId);
      filament.setAttribute('data-target', targetId);
      filament.setAttribute('data-reaction', reaction.id);
      svg.appendChild(filament);
    }

    // Glifos: círculo heráldico + título accesible + sigla geométrica.
    catalog.elements.forEach((element, index) => {
      const position = glyphPosition(index);
      const glyph = document.createElementNS('http://www.w3.org/2000/svg', 'g');
      glyph.setAttribute('class', 'elemental-wheel__glyph');
      glyph.setAttribute('data-element', element.id);
      glyph.setAttribute('data-angle', String(position.angle));
      glyph.setAttribute('data-color', element.color);
      glyph.setAttribute('data-glyph', element.glyph);
      glyph.setAttribute('role', 'button');
      glyph.setAttribute('tabindex', '0');
      if (mobileLayout) {
        // RNF-03: área táctil de 44 px garantizada por el CSS en móvil.
        glyph.classList.add('elemental-wheel__touch-target');
      }

      const title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
      title.textContent = element.name;
      glyph.appendChild(title);

      const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
      circle.setAttribute('cx', String(position.x));
      circle.setAttribute('cy', String(position.y));
      circle.setAttribute('r', '14');
      circle.setAttribute('class', 'elemental-wheel__glyph-disc');
      glyph.appendChild(circle);

      const label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
      label.setAttribute('x', String(position.x));
      label.setAttribute('y', String(position.y + 4));
      label.setAttribute('text-anchor', 'middle');
      label.setAttribute('class', 'elemental-wheel__glyph-label');
      // RNF-03: la sigla del glifo distingue por geometría además del color.
      label.textContent = element.glyph.replace('rune-', '').slice(0, 3).toUpperCase();
      glyph.appendChild(label);

      glyph.addEventListener('mouseenter', () => {
        if (selectedElementId === null) {
          renderFilaments(element.id);
        }
      });
      glyph.addEventListener('mouseleave', () => {
        if (selectedElementId === null) {
          renderFilaments(null);
        }
      });
      glyph.addEventListener('click', () => {
        highlightElement(element.id);
      });
      glyph.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault?.();
          highlightElement(element.id);
        }
      });

      svg.appendChild(glyph);
    });

    return svg;
  }

  /** Escucha del bus (el target se resuelve en mount). */
  let eventTarget = null;
  /** @type {Function|null} */
  let onAuraApplied = null;

  /**
   * Abre (o pliega, si ya está abierta) la lámina de acordeón del elemento.
   * Exclusión mutua: a lo sumo una lámina desplegada.
   * @param {string} elementId
   */
  function toggleAccordion(elementId) {
    openAccordionElementId = openAccordionElementId === elementId ? null : elementId;
    renderAccordionState();
  }

  /**
   * Sincroniza aria-expanded y las láminas abiertas del acordeón.
   */
  function renderAccordionState() {
    if (wheelRoot === null) {
      return;
    }
    for (const header of wheelRoot.querySelectorAll('.elemental-wheel__accordion-header')) {
      const elementId = header.getAttribute('data-element');
      header.setAttribute('aria-expanded', elementId === openAccordionElementId ? 'true' : 'false');
    }
    for (const candidate of wheelRoot.querySelectorAll('.elemental-wheel__accordion-panel')) {
      if (candidate.getAttribute('data-element') === openAccordionElementId) {
        candidate.classList.add('elemental-wheel__accordion-panel--open');
        candidate.removeAttribute('hidden');
      } else {
        candidate.classList.remove('elemental-wheel__accordion-panel--open');
        candidate.setAttribute('hidden', '');
      }
    }
  }

  /**
   * Construye el acordeón rúnico móvil: lista vertical de cabeceras con su
   * lámina de reacciones plegada (exclusión mutua al desplegar).
   */
  function buildAccordion() {
    const accordion = document.createElement('div');
    accordion.setAttribute('class', 'elemental-wheel__accordion');

    for (const element of catalog.elements) {
      const header = document.createElement('button');
      header.setAttribute('class', 'elemental-wheel__accordion-header elemental-wheel__touch-target');
      header.setAttribute('data-element', element.id);
      header.setAttribute('role', 'button');
      header.setAttribute('tabindex', '0');
      header.setAttribute('aria-expanded', 'false');
      header.textContent = element.name;
      header.addEventListener('click', () => toggleAccordion(element.id));
      header.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault?.();
          toggleAccordion(element.id);
        }
      });
      accordion.appendChild(header);

      const panel = document.createElement('div');
      panel.setAttribute('class', 'elemental-wheel__accordion-panel');
      panel.setAttribute('data-element', element.id);
      panel.setAttribute('hidden', '');
      renderReactionsInto(panel, element.id);
      accordion.appendChild(panel);
    }

    return accordion;
  }

  /**
   * Vuelca la lámina de reacciones (en castellano, RNF-04) en el contenedor.
   * @param {Element} container
   * @param {string} elementId
   */
  function renderReactionsInto(container, elementId) {
    const reactions = dualReactionsOf(elementId);
    if (reactions.length === 0) {
      const note = document.createElement('p');
      note.setAttribute('class', 'elemental-wheel__plate-empty');
      const catalyst = catalog.reactions.find((reaction) => reaction.isCatalyst && reaction.elements.includes(elementId));
      note.textContent = catalyst
        ? `${catalyst.name}: ${catalyst.description} (factor de amplificación 1.25, extensión de controles +1000 ms). Este glifo no enciende filamentos duales: es catalizador universal.`
        : 'Este glifo no guarda reacciones duales en el Códice: sin reacciones por ahora.';
      container.appendChild(note);
      return;
    }

    for (const reaction of reactions) {
      const entry = document.createElement('article');
      entry.setAttribute('class', 'elemental-wheel__plate-reaction');

      const reactionName = document.createElement('h4');
      reactionName.textContent = reaction.name;
      entry.appendChild(reactionName);

      const effect = document.createElement('p');
      effect.setAttribute('class', 'elemental-wheel__plate-effect');
      effect.textContent = `Efecto: ${reaction.tacticalEffect} · Factor: ×${reaction.damageMultiplier} · Duración: ${reaction.effectDurationMs} ms`;
      entry.appendChild(effect);

      const description = document.createElement('p');
      description.setAttribute('class', 'elemental-wheel__plate-description');
      description.textContent = reaction.description;
      entry.appendChild(description);

      container.appendChild(entry);
    }
  }

  /**
   * Reconstruye el DOM interno según el modo vigente, preservando la
   * selección fija y el estado del acordeón (conmutación en vivo).
   */
  function rebuildForCurrentLayout() {
    if (wheelRoot === null) {
      return;
    }
    const keepSelection = selectedElementId;
    const keepOpen = openAccordionElementId;
    while (wheelRoot.children.length > 0) {
      wheelRoot.children[0].remove();
    }

    if (mobileLayout) {
      wheelRoot.classList.add('elemental-wheel--mobile');
    } else {
      wheelRoot.classList.remove('elemental-wheel--mobile');
    }
    wheelRoot.appendChild(buildWheelSvg());

    if (mobileLayout) {
      wheelRoot.appendChild(buildAccordion());
      openAccordionElementId = keepSelection ?? keepOpen;
      if (openAccordionElementId !== null) {
        renderAccordionState();
      }
    } else {
      openAccordionElementId = null;
      if (keepSelection !== null) {
        renderFilaments(keepSelection);
        renderPlate(keepSelection);
      }
    }
  }

  /**
   * Monta la rueda en el anfitrión dado (idempotente) y queda a la escucha
   * de la media query para conmutar en vivo sin perder la selección.
   * @param {Element} hostElement
   */
  function mount(hostElement) {
    if (wheelRoot !== null) {
      if (wheelRoot.parentNode !== hostElement) {
        hostElement.appendChild(wheelRoot);
      }
      return;
    }

    mobileLayout = mobileMediaQuery?.().matches ?? false;
    wheelRoot = document.createElement('div');
    wheelRoot.setAttribute('class', mobileLayout ? 'elemental-wheel elemental-wheel--mobile' : 'elemental-wheel');
    wheelRoot.appendChild(buildWheelSvg());
    if (mobileLayout) {
      wheelRoot.appendChild(buildAccordion());
    }
    hostElement.appendChild(wheelRoot);

    // Conmutación viva: reconstruye el DOM al cambiar el viewport.
    if (mobileMediaQuery !== null) {
      activeMediaQuery = mobileMediaQuery();
      onMediaChange = () => {
        const nextMobile = activeMediaQuery.matches;
        if (nextMobile !== mobileLayout) {
          mobileLayout = nextMobile;
          rebuildForCurrentLayout();
        }
      };
      activeMediaQuery.addEventListener?.('change', onMediaChange);
    }

    // El bus (si existe) ilumina al volcar el estado del maniquí.
    eventTarget = options.eventTarget ?? null;
    if (eventTarget !== null) {
      onAuraApplied = (event) => {
        const element = event.detail?.element;
        if (typeof element === 'string' && elementById.has(element)) {
          highlightElement(element);
          if (mobileLayout) {
            openAccordionElementId = element;
            renderAccordionState();
          }
        }
      };
      eventTarget.addEventListener('combo:aura-applied', onAuraApplied);
    }
  }

  /**
   * Retira la rueda y da de baja sus escuchas.
   */
  function destroy() {
    if (activeMediaQuery !== null && onMediaChange !== null) {
      activeMediaQuery.removeEventListener?.('change', onMediaChange);
    }
    activeMediaQuery = null;
    onMediaChange = null;
    if (eventTarget !== null && onAuraApplied !== null) {
      eventTarget.removeEventListener('combo:aura-applied', onAuraApplied);
    }
    onAuraApplied = null;
    selectedElementId = null;
    openAccordionElementId = null;
    mobileLayout = false;
    if (wheelRoot !== null && wheelRoot.parentNode !== null) {
      wheelRoot.remove();
    }
    wheelRoot = null;
  }

  return {
    mount,
    highlightElement,
    clearHighlight,
    getSelectedElement,
    isMobileLayout,
    destroy,
  };
}
