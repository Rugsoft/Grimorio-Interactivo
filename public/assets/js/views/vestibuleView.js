/**
 * vestibuleView.js — La Ceremonia del Vestíbulo de las Hermandades
 * (SPEC-10, Tarea 5.5): vista orquestadora de la adhesión a clanes.
 *
 * Una carga del sobre (Endpoint 1, RNF-04), montaje de los componentes de
 * la Fase 5 (tarjeta 5.1, modal 5.2, compositor 5.3, inventario 5.4) y
 * emisión de los eventos `vestibule:*` del plan §4. La vista es la ÚNICA
 * que habla con el santuario: los componentes solo pintan estados derivados
 * del DTO y delegan gestos (Artículo II — la interfaz jamás decide).
 *
 * Responsabilidades del «Hecho cuando» (TASKS-10, Tarea 5.5):
 *   - Flujo feliz: `vestibule:catalog-loaded` con el sobre íntegro.
 *   - Fallo de catálogo: aviso «La corriente de maná se ha interrumpido»
 *     con botón «Volver a convocar» (Anexo A 7) — la ceremonia permanece
 *     operativa (RF-01.3, RF-02.2).
 *   - Acknowledge: los veredictos terminales sin leer que se EXHIBEN se
 *     contemplan (`acknowledgeVerdict`) y el apagado del rótulo del acceso
 *     viaja al shell por `vestibule:verdicts-acknowledged` (RF-03.4).
 *   - Región viva de veredictos (RNF-03): región ARIA que narra dictámenes
 *     anunciados, gestos vedados y fallos de gesto.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Module nativo; DOM puro; cero librerías.
 *   - Artículo IV/V: leyendas en noble castellano; identificadores en inglés.
 *   - Degradación elegante: sin cliente no hay gesto; sin callback de rótulo
 *     el apagado se pierde sin romper la ceremonia.
 *
 * @module views/vestibuleView
 */

import { createVestibuleClanCardComponent } from '../components/vestibuleClanCardComponent.js';
import { createAdmissionModalComponent } from '../components/admissionModalComponent.js';
import { createPetitionComposerComponent } from '../components/petitionComposerComponent.js';
import { createPetitionInventoryComponent } from '../components/petitionInventoryComponent.js';
import { ceremonialLegendFor } from '../api/vestibuleClient.js';

/** Aviso de catálogo sin respuesta (Anexo A 7 literal) y su acción. */
export const VESTIBULE_CATALOG_FAILED_LEGEND =
  'La corriente de maná se ha interrumpido: las hermandades no responden.';
export const VESTIBULE_RETRY_LABEL = 'Volver a convocar';

/** Rótulos del encabezado del Vestíbulo. */
export const VESTIBULE_VIEW_TITLE = 'Vestíbulo de las Hermandades';

/** Eventos del plan §4 que la vista emite sobre su raíz. */
export const VESTIBULE_VIEW_EVENTS = Object.freeze({
  catalogLoaded: 'vestibule:catalog-loaded',
  catalogFailed: 'vestibule:catalog-failed',
  membershipCreated: 'vestibule:membership-created',
  petitionSubmitted: 'vestibule:petition-submitted',
  petitionWithdrawn: 'vestibule:petition-withdrawn',
  verdictAnnounced: 'vestibule:verdict-announced',
  gestureDenied: 'vestibule:gesture-denied',
  verdictsAcknowledged: 'vestibule:verdicts-acknowledged',
});

/**
 * Crea la vista del Vestíbulo de las Hermandades.
 *
 * @param {HTMLElement} mountRoot Punto de montaje (`<main id="app">`).
 * @param {Object} options
 * @param {Object} options.vestibuleClient Cliente HTTP del Vestíbulo
 *        (Tarea 4.1): fetchVestibule, withdrawApplication,
 *        acknowledgeVerdict, fetchUnreadVerdictsCount.
 * @param {Object} options.clanClient Cliente de adhesión (SPEC-07):
 *        applyToClan(clanId, payload) — el rito común de ambos regímenes.
 * @param {(clanId: string) => void} [options.onMembershipChanged] Aviso al
 *        shell tras un ingreso consumado (resincronización de la sesión).
 * @param {() => void} [options.onVerdictsAcknowledged] El shell apaga el
 *        rótulo del acceso (main.js: setVestibuleBadgeCount(0)).
 * @param {(tagName: string) => HTMLElement} [options.elementFactory]
 *        Fábrica inyectable (arneses sin navegador).
 * @param {Document} [options.documentRef] Documento anfitrión.
 * @param {EventTarget} [options.eventTarget] Bus del plan §4 (por defecto,
 *        la raíz de montaje).
 * @returns {Object} API: { render, destroy, retry }.
 */
export function createVestibuleView(mountRoot, options = {}) {
  const {
    vestibuleClient,
    clanClient,
    onMembershipChanged,
    onVerdictsAcknowledged,
    documentRef = globalThis.document,
  } = options;

  // El default de la fábrica deriva del documento INYECTADO (los arneses
  // carecen de `globalThis.document`; hallazgo de los arneses 5.3/5.4).
  const elementFactory = options.elementFactory
    ?? ((tagName) => documentRef.createElement(tagName));

  /** Bus de eventos del plan §4. */
  const eventTarget = options.eventTarget ?? mountRoot;

  /** Nodos vivos de la vista, para limpieza determinista. */
  const mountedNodes = [];

  /** Guardia anti-carreras: solo el sobre más reciente pinta. */
  let fetchSequence = 0;
  let isDestroyed = false;

  /** Referencias vivas. */
  let viewRoot = null;
  let cardsHost = null;
  let liveRegion = null;
  let catalogErrorBox = null;
  let admissionModal = null;
  let composerHost = null;
  let inventory = null;

  /** Sobre vivo (fuente única de los repintados sin recarga, RNF-04). */
  let currentState = null;

  /** Registra un nodo como hijo de la vista. */
  function track(node) {
    mountedNodes.push(node);
    return node;
  }

  /** Emite un evento del plan §4 sobre el bus de la vista. */
  function emit(eventName, detail = {}) {
    const CustomEventCtor = globalThis.CustomEvent;
    if (typeof CustomEventCtor === 'function') {
      eventTarget.dispatchEvent(new CustomEventCtor(eventName, { detail, bubbles: true }));
    }
  }

  /** Narra en la región viva (RNF-03). */
  function announce(message) {
    if (liveRegion !== null) {
      liveRegion.textContent = '';
      liveRegion.textContent = String(message);
    }
  }

  // -------------------------------------------------------------------
  // Gestos — la vista consume las intenciones de los componentes.
  // -------------------------------------------------------------------

  /**
   * Rito común de adhesión (plan §5.1): ingreso inmediato tras la
   * confirmación solemne del modal (Tarea 5.2) o petición formal remitida
   * desde el compositor (Tarea 5.3). El despacho del régimen lo decide el
   * santuario (RF-01.7: la interfaz jamás decide).
   */
  async function performAdmission(clanId, motivation = null) {
    const result = await clanClient.applyToClan(
      motivation !== null ? { clanId, motivation } : clanId,
    );

    if (result?.success !== true) {
      const legend = ceremonialLegendFor(result);
      announce(legend);
      emit(VESTIBULE_VIEW_EVENTS.gestureDenied, {
        code: result?.error?.code ?? 'UNKNOWN',
        legend,
      });
      return false;
    }

    const outcome = result.data ?? {};
    if (outcome.status === 'active') {
      // Ingreso consumado: membresía nacida, residuales anuladas de oficio.
      announce(`Tu ingreso en la casa queda sellado.`);
      emit(VESTIBULE_VIEW_EVENTS.membershipCreated, { clanId });
      onMembershipChanged?.();
    } else {
      announce('Tu petición aguarda el dictamen del Patriarca.');
      emit(VESTIBULE_VIEW_EVENTS.petitionSubmitted, { clanId });
    }

    await reload(); // Rehidratación íntegra: tarjeta, inventario y cupo.
    return true;
  }

  /** Retirada directa desde la tarjeta o el inventario (RF-03.3). */
  async function performWithdraw(applicationId) {
    if (typeof vestibuleClient?.withdrawApplication !== 'function') return false;

    const result = await vestibuleClient.withdrawApplication(
      currentState?.petitionClanId?.get(applicationId) ?? '',
      applicationId,
    );

    if (result?.success !== true) {
      const legend = ceremonialLegendFor(result);
      announce(legend);
      emit(VESTIBULE_VIEW_EVENTS.gestureDenied, {
        code: result?.error?.code ?? 'UNKNOWN',
        legend,
      });
      return false;
    }

    inventory?.removePetition(applicationId);
    announce('Tu petición ha sido retirada.');
    emit(VESTIBULE_VIEW_EVENTS.petitionWithdrawn, { applicationId });
    await reload();
    return true;
  }

  /**
   * Contemplación de veredictos (RF-03.4): por cada petición terminal SIN
   * leer que se exhibe, se contempla su dictamen (Endpoint 4, idempotente)
   * y se avisa al shell del apagado del rótulo del acceso.
   */
  async function acknowledgeVisibleVerdicts(petitions) {
    const unread = (Array.isArray(petitions) ? petitions : [])
      .filter((petition) => petition?.status !== 'pending' && petition?.verdictSeen !== true);

    if (unread.length === 0) return;

    for (const petition of unread) {
      // Narración inmediata en la región viva (RNF-03)…
      const decision = petition.status === 'approved' ? 'favorable' : 'desfavorable';
      announce(`La casa ${petition.clanName ?? ''} ha pronunciado un dictamen ${decision}.`);
      emit(VESTIBULE_VIEW_EVENTS.verdictAnnounced, {
        applicationId: String(petition.applicationId ?? ''),
        decision: petition.status,
      });
      // …y contemplación best-effort en el santuario (idempotente).
      if (typeof vestibuleClient?.acknowledgeVerdict === 'function') {
        try {
          await vestibuleClient.acknowledgeVerdict(String(petition.applicationId ?? ''));
        } catch {
          // El corte de maná no rompe la ceremonia: el rótulo seguirá vivo.
        }
      }
    }

    // El shell apaga el distintivo «Tienes dictámenes a la espera».
    onVerdictsAcknowledged?.();
    emit(VESTIBULE_VIEW_EVENTS.verdictsAcknowledged, {
      acknowledgedCount: unread.length,
    });
  }

  /** Aviso de gesto vedado desde el DTO (leyendas de RF-03.5). */
  function handleDeniedLegend(legend) {
    announce(legend);
  }

  // -------------------------------------------------------------------
  // Carga y pintura
  // -------------------------------------------------------------------

  /**
   * Pinta el catálogo desde el sobre vivo. Las tarjetas se PINTAN desde el
   * DTO (`gesture`, `vedadoLegend`) — la vista jamás recalcula estados.
   */
  function renderClans() {
    if (cardsHost === null || currentState === null) return;

    while (cardsHost.children.length > 0) {
      cardsHost.removeChild(cardsHost.children[0]);
    }

    const clans = Array.isArray(currentState.clans) ? currentState.clans : [];

    if (clans.length === 0) {
      // Estado vacío de la Tarea 5.1 (RF-01.4).
      cardsHost.appendChild(createVestibuleClanCardComponent.emptyState?.().element
        ?? buildFallbackEmptyState());
      return;
    }

    for (const clanDto of clans) {
      const card = createVestibuleClanCardComponent(clanDto, {
        onGesture: handleCardGesture,
        documentRef,
      });
      cardsHost.appendChild(card.element);
    }
  }

  /** Estado vacío de respaldo si el componente no exporta fábrica propia. */
  function buildFallbackEmptyState() {
    const box = elementFactory('p');
    box.className = 'vestibule-empty__legend';
    box.textContent = 'Ninguna hermandad ruega aún tu linaje.';
    return box;
  }

  /** Conduce la intención de la tarjeta al rito que corresponda. */
  function handleCardGesture(clanId, gesture) {
    if (gesture === 'withdraw') {
      const petition = (currentState?.petitions ?? [])
        .find((entry) => entry?.clanId === clanId && entry?.status === 'pending');
      if (petition) void performWithdraw(String(petition.applicationId ?? ''));
      return;
    }

    if (gesture === 'petition') {
      openComposer(clanId);
      return;
    }

    if (gesture === 'join') {
      const clanDto = (currentState?.clans ?? []).find((entry) => entry?.clanId === clanId);
      admissionModal?.open({
        clanId,
        clanName: String(clanDto?.name ?? clanId),
        originElement: null,
      });
    }
  }

  /** Despliega el molde de la petición formal (Tarea 5.3) para una casa. */
  function openComposer(clanId) {
    if (composerHost === null) return;
    composerHost.replaceChildren?.();
    if (composerHost.children) composerHost.children.length = 0;

    const composer = createPetitionComposerComponent({
      clanId,
      clanName: String((currentState?.clans ?? []).find((c) => c?.clanId === clanId)?.name ?? clanId),
      documentRef,
      windowRef: globalThis.window,
      onSubmit: (submittedClanId, motivation) => {
        composerHost.replaceChildren?.();
        if (composerHost.children) composerHost.children.length = 0;
        void performAdmission(submittedClanId, motivation);
      },
      onCancel: () => {
        composerHost.replaceChildren?.();
        if (composerHost.children) composerHost.children.length = 0;
      },
    });
    composerHost.appendChild(composer.element);
    composer.focus?.();
  }

  /** Índice petición → casa, para la retirada desde el inventario. */
  function indexPetitionClans(petitions) {
    const index = new Map();
    for (const petition of Array.isArray(petitions) ? petitions : []) {
      index.set(String(petition?.applicationId ?? ''), String(petition?.clanId ?? ''));
    }
    return index;
  }

  /** Monta el esqueleto de la vista (encabezado, host de tarjetas, regiones). */
  function buildSkeleton() {
    viewRoot = track(elementFactory('section'));
    viewRoot.className = 'vestibule-view grimoire-tomo-container';

    const heading = elementFactory('h2');
    heading.className = 'vestibule-view__title';
    heading.textContent = VESTIBULE_VIEW_TITLE;
    viewRoot.appendChild(heading);

    // Región viva de veredictos y gestos (RNF-03).
    liveRegion = elementFactory('p');
    liveRegion.className = 'vestibule-view__live';
    liveRegion.setAttribute('role', 'status');
    liveRegion.setAttribute('aria-live', 'polite');
    viewRoot.appendChild(liveRegion);

    // Aviso de catálogo sin respuesta + reintento (Anexo A 7, RF-02.2).
    catalogErrorBox = elementFactory('div');
    catalogErrorBox.className = 'vestibule-view__catalog-error';
    catalogErrorBox.setAttribute('role', 'alert');
    catalogErrorBox.setAttribute('hidden', '');
    const errorLegend = elementFactory('p');
    errorLegend.textContent = VESTIBULE_CATALOG_FAILED_LEGEND;
    catalogErrorBox.appendChild(errorLegend);
    const retryButton = elementFactory('button');
    retryButton.type = 'button';
    retryButton.className = 'vestibule-view__retry';
    retryButton.textContent = VESTIBULE_RETRY_LABEL;
    retryButton.addEventListener('click', () => {
      void retry();
    });
    catalogErrorBox.appendChild(retryButton);
    viewRoot.appendChild(catalogErrorBox);

    // Anfitriones de los componentes de la Fase 5.
    cardsHost = elementFactory('div');
    cardsHost.className = 'vestibule-view__cards';
    viewRoot.appendChild(cardsHost);

    composerHost = elementFactory('div');
    composerHost.className = 'vestibule-view__composer';
    viewRoot.appendChild(composerHost);

    // Inventario consolidado (Tarea 5.4) con retirada directa.
    inventory = createPetitionInventoryComponent([], {
      elementFactory,
      onWithdraw: (applicationId) => {
        void performWithdraw(applicationId);
      },
    });
    viewRoot.appendChild(inventory.element);

    // Diálogo nativo del modal solemne de ingreso (Tarea 5.2).
    const dialogElement = elementFactory('dialog');
    dialogElement.className = 'admission-modal';
    viewRoot.appendChild(dialogElement);
    admissionModal = createAdmissionModalComponent(dialogElement, {
      onConfirm: (clanId) => {
        void performAdmission(clanId, null);
      },
      documentRef,
      windowRef: globalThis.window,
    });

    mountRoot.appendChild(viewRoot);
  }

  /** Una carga del sobre (RNF-04): catálogo, aptitud e inventario juntos. */
  async function load() {
    const currentSequence = ++fetchSequence;

    if (typeof vestibuleClient?.fetchVestibule !== 'function') {
      if (catalogErrorBox !== null) catalogErrorBox.removeAttribute('hidden');
      return;
    }

    const result = await vestibuleClient.fetchVestibule().catch((error) => ({
      success: false,
      status: 0,
      error: { code: 'MANA_STREAM_INTERRUPTED', message: error instanceof Error ? error.message : String(error) },
    }));

    if (currentSequence !== fetchSequence || isDestroyed) return;

    if (result?.success !== true) {
      // Ceremonia operativa: aviso solemne + reintento, sin desmontar nada.
      catalogErrorBox?.removeAttribute('hidden');
      announce(VESTIBULE_CATALOG_FAILED_LEGEND);
      emit(VESTIBULE_VIEW_EVENTS.catalogFailed, {});
      return;
    }

    catalogErrorBox?.setAttribute('hidden', '');
    currentState = result.data ?? {};
    currentState.petitionClanId = indexPetitionClans(currentState.petitions);

    renderClans();
    inventory?.setPetitions(Array.isArray(currentState.petitions) ? currentState.petitions : []);

    emit(VESTIBULE_VIEW_EVENTS.catalogLoaded, { state: currentState });

    // Los veredictos sin leer se exhibidos se contemplan (RF-03.4).
    await acknowledgeVisibleVerdicts(currentState.petitions);
  }

  /** Rehidratación sin recarga (tras ingreso, petición o retirada). */
  async function reload() {
    if (isDestroyed) return;
    const currentSequence = ++fetchSequence;

    const result = await vestibuleClient.fetchVestibule().catch(() => ({ success: false }));
    if (currentSequence !== fetchSequence || isDestroyed || result?.success !== true) return;

    currentState = result.data ?? {};
    currentState.petitionClanId = indexPetitionClans(currentState.petitions);
    renderClans();
    inventory?.setPetitions(Array.isArray(currentState.petitions) ? currentState.petitions : []);
  }

  /** Reintento tras fallo de catálogo («Volver a convocar»). */
  async function retry() {
    fetchSequence += 1;
    catalogErrorBox?.setAttribute('hidden', '');
    await load();
  }

  /** Monta la vista y carga el sobre. Idempotente. */
  async function render() {
    if (isDestroyed) return;

    destroy(false);

    buildSkeleton();
    await load();
  }

  /** Retira la vista del punto de montaje y libera sus nodos. */
  function destroy(removeFromMount = true) {
    fetchSequence += 1;

    admissionModal?.destroy?.();
    admissionModal = null;
    inventory?.destroy?.();
    inventory = null;

    for (const node of mountedNodes.splice(0)) {
      node.remove?.();
    }
    viewRoot = null;
    cardsHost = null;
    liveRegion = null;
    catalogErrorBox = null;
    composerHost = null;
    currentState = null;

    if (removeFromMount) {
      for (const child of [...(mountRoot.children ?? [])]) {
        if (String(child.className ?? '').includes('vestibule-view')) {
          child.remove?.();
        }
      }
      isDestroyed = true;
    }
  }

  return { render, destroy, retry };
}
