/**
 * spellDetailModalComponent.js — Ficha técnica superpuesta (Tarea 4.3).
 *
 * RF-04.1: panel <dialog> superpuesto; el scroll del catálogo NO se toca
 *          (showModal nativo no desplaza el fondo).
 * RF-04.2: cierre con Escape, botón × o cancel del backdrop, siempre
 *          notificando al orquestador (historyManager/store, Tareas 3.4/3.2).
 * RF-04.3: rescate de foco — regresa a la tarjeta de origen; si el filtro la
 *          retiró del DOM, al título de la biblioteca (plan 5.3, caso límite 4).
 * RNF-03:  focus trap — Tab/Shift+Tab confinados a los enfocables del diálogo.
 * RF-05.2: la acción reservada dentro de la ficha notifica onReservedAction
 *          SIN cerrarse: el «Cruzar el Umbral» (Tarea 4.4) se apila encima.
 *
 * Seguridad (AGENTS.md 6.1): el SpellDetailDto se renderiza vía textContent.
 *
 * Constitución:
 *   - Artículo I: <dialog> estándar (plan, Decisión 1); cero librerías.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 */

/**
 * Crea el componente de la ficha técnica superpuesta.
 *
 * @param {HTMLDialogElement} dialogElement <dialog id="spellDetailModal"> del shell.
 * @param {object} componentOptions Opciones e inyecciones:
 *   - onClose(): notificación de cierre al orquestador (store/history).
 *   - onReservedAction(action, slug): acción reservada dentro de la ficha.
 *   - onSignSpell(slug): firma solemne legítima (SPEC-03, Tarea 5.2).
 *   - originElement: tarjeta que abrió la ficha (para el rescate de foco).
 *   - documentRef / windowRef: inyecciones para pruebas (document/window).
 * @returns {object} { open, close, destroy }.
 */
export function createSpellDetailModalComponent(dialogElement, componentOptions = {}) {
  const {
    onClose,
    onReservedAction,
    onSignSpell,
    originElement = null,
    documentRef = globalThis.document,
    windowRef = globalThis.window,
  } = componentOptions;

  /** Cuerpo y botón de cierre cableados desde el shell (index.html). */
  let detailBody = dialogElement.querySelector('#spellDetailBody');
  let closeButton = dialogElement.querySelector('#spellDetailClose');

  /** Slug del hechizo mostrado (para la acción reservada). */
  let displayedSlug = null;

  /** Guardia de binding: los re-renders no apilan listeners del shell. */
  let shellListenersBound = false;

  /** Contexto de firma de la apertura actual (SPEC-03, Tarea 5.2):
   *  { signer: User|null, clanConflict: ClanConflictVerdict|null }. */
  let currentSignContext = null;

  /**
   * Helper de texto seguro: nodo con clase y contenido literal.
   */
  function createTextElement(tagName, className, safeText) {
    const element = documentRef.createElement(tagName);
    element.className = className;
    element.textContent = safeText; // Jamás innerHTML (XSS).
    return element;
  }

  /**
   * Renderiza el SpellDetailDto (plan 2.2) dentro del cuerpo del diálogo.
   * @param {object} spellDetailDto DTO completo del hechizo.
   */
  function renderSpellDetail(spellDetailDto) {
    displayedSlug = spellDetailDto.slug;
    // Reconstrucción limpia por apertura. replaceChildren() es la vía
    // nativa segura: HTMLCollection.length es de solo lectura en el DOM real;
    // el simulado puede carecer del método o de remove().
    if (typeof detailBody.replaceChildren === 'function') {
      detailBody.replaceChildren();
    } else {
      for (const childNode of [...(detailBody.children ?? [])]) {
        if (typeof childNode.remove === 'function') {
          childNode.remove();
        } else {
          const childIndex = detailBody.children.indexOf(childNode);
          if (childIndex !== -1) detailBody.children.splice(childIndex, 1);
        }
      }
    }

    // Encabezado: nombre, insignias y resumen.
    detailBody.appendChild(
      createTextElement('h2', 'spell-detail__name', spellDetailDto.name)
    );

    const badgesRow = documentRef.createElement('div');
    badgesRow.className = 'spell-detail__badges';
    badgesRow.appendChild(
      createTextElement('span', 'spell-card__badge', spellDetailDto.magicSchoolLabel)
    );
    badgesRow.appendChild(
      createTextElement('span', 'spell-card__badge spell-card__badge--mana', `${spellDetailDto.manaCost} maná`)
    );
    detailBody.appendChild(badgesRow);

    detailBody.appendChild(
      createTextElement('p', 'spell-detail__summary', spellDetailDto.summary)
    );

    // Texto íntegro: aquí NO hay clamp (plan, Caso límite 2).
    detailBody.appendChild(
      createTextElement('p', 'spell-detail__description', spellDetailDto.description)
    );

    // Componentes arcanos (plan 2.2): verbal, somático y material.
    const componentsSection = documentRef.createElement('section');
    componentsSection.className = 'spell-detail__components';

    const componentLabels = {
      verbal: 'Fórmula verbal',
      somatic: 'Gesto somático',
      material: 'Reliquia material',
    };
    for (const [componentKey, componentLabel] of Object.entries(componentLabels)) {
      const componentValue = spellDetailDto.components?.[componentKey] ?? '';
      if (componentValue === '') continue;

      const componentLine = documentRef.createElement('p');
      componentLine.className = 'spell-detail__component-line';
      const labelNode = createTextElement('strong', 'spell-detail__component-label', `${componentLabel}: `);
      componentLine.appendChild(labelNode);
      componentLine.appendChild(createTextElement('span', 'spell-detail__component-value', componentValue));
      componentsSection.appendChild(componentLine);
    }
    detailBody.appendChild(componentsSection);

    // Acción reservada: «Añadir a mi Grimorio» (RF-05.2, plan 7.2).
    const reserveButton = documentRef.createElement('button');
    reserveButton.type = 'button';
    reserveButton.className = 'rescue-button spell-detail__reserve';
    reserveButton.textContent = 'Añadir a mi Grimorio';
    reserveButton.addEventListener('click', () => {
      // La ficha NO se cierra: el acceso se apila encima (RF-05.2).
      onReservedAction?.('addToGrimoire', displayedSlug);
    });
    detailBody.appendChild(reserveButton);

    renderSignAction(spellDetailDto);
  }

  /**
   * SPEC-03 (Tarea 5.2): renderiza la acción de firma solemne de la ficha
   * técnica, aplicando el veredicto de conflicto de intereses que dicta
   * el backend (ClanConflictService, Tarea 2.4 — fuente única de verdad).
   *
   * Doble barrera del Artículo III: si el veredicto veta la firma, el botón
   * nace deshabilitado con la advertencia solemne visible, y el click forzado
   * no dispara la acción (la barrera definitiva vive en el servidor).
   *
   * @param {object} spellDetailDto DTO del hechizo con authorId y clanId.
   */
  function renderSignAction(spellDetailDto) {
    const signContext = currentSignContext;

    // Sin contexto de firma (anónimo, o el orquestador aún no portaba el
    // veredicto): la ficha no inventa ni predice vetos — no renderiza nada.
    if (!signContext || typeof signContext !== 'object') return;

    const { signer, clanConflict } = signContext;
    if (!signer || signer.role !== 'master') return; // Solo Maestros firman (RF-05.1).
    // Fuente única de verdad (Art. III): sin veredicto del backend la ficha
    // no predice ni inventa — no renderiza acción de firma alguna.
    if (!clanConflict || typeof clanConflict !== 'object') return;

    const signButton = documentRef.createElement('button');
    signButton.type = 'button';
    signButton.className = 'spell-detail__sign';
    signButton.setAttribute('data-action', 'signSpell');
    signButton.textContent = 'Emitir firma solemne';

    const verdict = clanConflict;
    const isVetoed = verdict.isAllowed === false;
    const vetoReason = typeof verdict.reason === 'string' ? verdict.reason : '';

    if (isVetoed) {
      // CRITERIO T5.2: botón bloqueado + advertencia mística visible.
      signButton.disabled = true;
      signButton.setAttribute('aria-disabled', 'true');
      // El motivo solemne del backend viaja íntegro (RF-06.1): alimenta
      // tanto la advertencia visible como la accesibilidad del botón.
      signButton.setAttribute('aria-label', `Firma vetada: ${vetoReason}`);

      const warningLine = documentRef.createElement('p');
      warningLine.className = 'spell-detail__sign-warning';
      warningLine.setAttribute('role', 'alert');
      warningLine.textContent = vetoReason;
      detailBody.appendChild(warningLine);

      // Doble barrera (RF-06.2): incluso forzando el click, la firma no
      // viaja; el veto es irreversible en el backend.
      signButton.addEventListener('click', (clickEvent) => {
        clickEvent?.preventDefault?.();
        clickEvent?.stopPropagation?.();
      });
      detailBody.appendChild(signButton);
      return;
    }

    // Firma legítima: delegación al orquestador (que llamará al endpoint
    // de firmas; el backend re-verificará el conflicto de todos modos).
    signButton.addEventListener('click', () => {
      onSignSpell?.(displayedSlug);
    });
    detailBody.appendChild(signButton);
  }

  /**
   * Focus trap (RNF-03, plan 5.3): Tab y Shift+Tab ciclan dentro del diálogo.
   * @param {KeyboardEvent} keydownEvent Evento de teclado del diálogo.
   */
  function trapFocus(keydownEvent) {
    if (keydownEvent.key !== 'Tab') return;

    const focusableSelector = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
    const focusableElements = [...dialogElement.querySelectorAll(focusableSelector)];
    if (focusableElements.length === 0) return;

    const firstElement = focusableElements[0];
    const lastElement = focusableElements[focusableElements.length - 1];
    const currentElement = documentRef.activeElement;

    if (keydownEvent.shiftKey && (currentElement === firstElement || currentElement === dialogElement)) {
      // Ciclo inverso: del primero al último.
      keydownEvent.preventDefault();
      lastElement.focus();
    } else if (!keydownEvent.shiftKey && currentElement === lastElement) {
      // Ciclo directo: del último al primero.
      keydownEvent.preventDefault();
      firstElement.focus();
    }
  }

  /**
   * RF-04.3: rescate de foco al cerrar — tarjeta de origen o título de la
   * biblioteca si la tarjeta desapareció del DOM (plan 5.3).
   */
  function restoreFocus() {
    // Origen de la apertura AÚN VIVO en el DOM (RF-04.3, plan 5.3: si el
    // filtrado lo retiró, enfocarlo sería dejar el foco en un nodo huérfano).
    // En el DOM real isConnected es false al desmontar; en el simulador,
    // parentElement === null tras remove().
    const originIsConnected = currentOriginElement
      && (currentOriginElement.isConnected === true
        || (currentOriginElement.isConnected === undefined && currentOriginElement.parentElement !== null));
    if (originIsConnected && typeof currentOriginElement.focus === 'function') {
      currentOriginElement.focus();
      return;
    }

    // Foco de rescate: encabezado de la biblioteca (RF-04.3).
    const libraryTitle = documentRef.getElementById?.('libraryHeaderTitle');
    if (libraryTitle && typeof libraryTitle.focus === 'function') {
      libraryTitle.setAttribute('tabindex', '-1');
      libraryTitle.focus();
      return;
    }

    // Última muralla: el propio diálogo retiene el foco (modal vivo).
    dialogElement.focus?.();
  }

  /** Cierra la ficha y notifica al orquestador (fuente única de cierre). */
  function close() {
    if (!dialogElement.open) {
      return;
    }

    // dialog.close() dispara 'close', que centraliza el rescate y onClose.
    dialogElement.close();
  }

  /** Cables los listeners del shell una única vez. */
  function bindShellListeners() {
    if (shellListenersBound) {
      return;
    }
    shellListenersBound = true;

    // RNF-03: trap de foco sobre el diálogo completo.
    dialogElement.addEventListener('keydown', (keydownEvent) => {
      if (keydownEvent.key === 'Escape') {
        // El navegador cierra el dialog con Escape; 'close' centraliza el resto.
        return;
      }
      trapFocus(keydownEvent);
    });

    // RF-04.2: Escape nativo + botón × convergen en 'close'.
    dialogElement.addEventListener('close', () => {
      restoreFocus();
      onClose?.();
    });

    // Botón de cierre del shell.
    closeButton?.addEventListener('click', close);
  }

  /**
   * Abre la ficha con el DTO dado (RF-04.1): superpuesta, sin tocar el scroll
   * del catálogo (showModal no desplaza el fondo ni lee/escribe scrollY).
   *
   * @param {object} spellDetailDto SpellDetailDto del plan 2.2.
   */
  /**
   * Tarjeta de origen VIVA para el rescate de foco (RF-04.3). Se actualiza
   * en cada apertura: el orquestador la conoce en el momento de la llamada.
   */
  let currentOriginElement = originElement;

  function open(spellDetailDto, { originElement: openOriginElement = null, signer = null, clanConflict = null } = {}) {
    if (dialogElement.open) {
      return; // Idempotente: ya desplegada.
    }

    if (openOriginElement) {
      currentOriginElement = openOriginElement;
    }

    // Contexto de firma solemne (Tarea 5.2): el veredicto de conflicto
    // lo decide SIEMPRE el backend; la ficha solo lo traduce a interfaz.
    currentSignContext = { signer, clanConflict };

    renderSpellDetail(spellDetailDto);
    bindShellListeners();

    // showModal: backdrop + pila nativa; el scroll del fondo permanece intacto.
    dialogElement.showModal();

    // Foco inicial dentro del modal (RNF-03): primer enfocable.
    const firstFocusable = dialogElement.querySelector('button, [href], input, [tabindex]:not([tabindex="-1"])');
    firstFocusable?.focus();
  }

  /** Baja limpia (el orquestador la usa al desmontar la SPA). */
  function destroy() {
    shellListenersBound = false;
    if (dialogElement.open) {
      dialogElement.close();
    }
  }

  // La referencia windowRef queda reservada para mejoras de scroll lock
  // en motores sin <dialog> (degradación elegante, checklist AGENTS.md).
  void windowRef;

  return { open, close, setOriginElement: (originNode) => { currentOriginElement = originNode; }, setSignContext: (signContext) => { currentSignContext = signContext; }, destroy };
}
