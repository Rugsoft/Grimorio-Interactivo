/**
 * lineageHallView.js — Vista general del Salón de los Linajes (Tarea 6.3,
 * TASKS-07).
 *
 * RF-06.1: monta el pabellón del Salón —clasificación semanal en vivo,
 *   prestigio histórico perpetuo y Libro Mayor de Campeones— en la ruta
 *   pública de linajes. La contemplación es LIBRE: ni un solo control exige
 *   vínculo arcano (RF-06.1).
 * RF-06.2: sirve el catálogo de los 8 Linajes Canónicos que viste la barra de
 *   filtros elementales del componente.
 *
 * La vista es la única responsable de hablar con el santuario: consulta en
 * paralelo el catálogo de linajes (Endpoint 10) y el Salón del Dominio
 * (Endpoint 11) y entrega los sobres al componente, que solo contempla y
 * pinta (Artículo II: la gloria la computa el servidor, jamás el cliente).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Module nativo; DOM puro; cero librerías.
 *   - Artículo IV/V: leyendas en noble castellano; identificadores en inglés.
 *   - Degradación elegante (AGENTS.md 8): si el catálogo de linajes falla, el
 *     Salón sigue en pie con las claves técnicas y sin filtros; si el Salón
 *     falla, error temático con reintento; guardia anti-carreras para que una
 *     respuesta tardía de una consulta antigua jamás pinte.
 */

import { createLineageHallComponent } from '../components/lineageHallComponent.js';
import { createClanFoundationModalComponent } from '../components/clanFoundationModalComponent.js';

/** Evento del plan 4.1: alguien acaba de acreditar PDA a su hermandad. */
const POINTS_AWARDED_EVENT = 'dominion:points-awarded';

/** Evento del plan 4.1: el corte dominical proclamó al nuevo Clan Regente. */
const WEEK_CLOSED_EVENT = 'dominion:week-closed';

/**
 * Crea la vista del Salón de los Linajes.
 *
 * @param {HTMLElement} mountRoot Punto de montaje (`<main id="app">`).
 * @param {Object} options
 * @param {Object} options.dominionClient Cliente HTTP del Salón (Tarea 5.1),
 *        con `fetchLineages` y `fetchLeaderboard`.
 * @param {Object} [options.store] Almacén reactivo; la vista registra
 *        `currentView: 'clans'` al montar.
 * @param {(clanId: string) => void} [options.onClanSelect] Selección de una
 *        casa del podio o del histórico (la ficha llegará con la Tarea 6.4).
 * @param {() => void} [options.onOpenVestibule] Llamamiento al Vestíbulo de
 *        las Hermandades (SPEC-10, Tarea 4.2): CTA de la doble vía de acceso.
 * @param {(tagName: string) => HTMLElement} [options.elementFactory] Fábrica
 *        inyectable (arneses sin navegador).
 * @param {Document} [options.documentRef] Documento anfitrión de los sellos
 *        forjados de las casas (arneses sin navegador).
 * @param {EventTarget} [options.eventTarget] Bus del plan 4.1; el Salón se
 *        refresca solo cuando alguien acredita gloria (`dominion:points-awarded`)
 *        o se proclama la semana (`dominion:week-closed`), sin sondear el reloj
 *        (RNF-02). Por defecto, la raíz de montaje.
 * @returns {Object} API: { render, destroy, retry, setDominionClient }.
 */
export function createLineageHallView(mountRoot, options = {}) {
  const {
    dominionClient,
    store = null,
    onClanSelect,
    onOpenVestibule,
    // SPEC-07b (Tarea 1): el Umbral de la Fundación. El dialog anfitrión lo
    // aporta el orquestador (shell); clanClient despacha el rito; el catálogo
    // de linajes jurados alimenta la selección de sangre propia.
    clanClient = null,
    foundationDialog = null,
    elementFactory = (tagName) => globalThis.document.createElement(tagName),
    documentRef = globalThis.document,
  } = options;

  /** Bus de eventos: la gloria recién acreditada llega por evento, no por sondeo. */
  const eventTarget = options.eventTarget
    ?? (typeof globalThis.addEventListener === 'function' ? globalThis : mountRoot);

  /** Cliente HTTP vivo (conmutable en reintentos tras un fallo). */
  let activeDominionClient = dominionClient;

  /** Nodos vivos de la vista, para limpieza determinista. */
  const mountedNodes = [];

  /** Guardia anti-carreras: solo la consulta más reciente pinta el Salón. */
  let fetchSequence = 0;

  /** El Salón quedó desmontado: ninguna respuesta tardía pinta nada. */
  let isDestroyed = false;

  /** Referencias vivas. */
  let viewRoot = null;
  let hall = null;

  /** Registra un nodo como hijo de la vista para su ciclo de vida. */
  function track(node) {
    mountedNodes.push(node);
    return node;
  }

  /* =====================================================================
     El Umbral de la Fundación (SPEC-07b, Tarea 1; RF-10.1–10.5)
     ===================================================================== */

  /** El Umbral vivo (se forja perezosamente en la primera apertura). */
  let foundationModal = null;

  /** Sesión vigente según el store (o la del visitante anónimo). */
  function foundationViewerState() {
    const session = store?.getState?.() ?? {};
    const user = session?.currentUser ?? null;
    return {
      isAuthenticated: session?.isAuthenticated === true,
      role: typeof session?.userRole === 'string' ? session.userRole : 'reader',
      lineage: typeof session?.userLineage === 'string' ? session.userLineage : '',
      clanId: typeof session?.userClan?.id === 'string' ? session.userClan.id : '',
      convalescenceExpiresAt: typeof user?.convalescenceExpiresAt === 'string'
        ? user.convalescenceExpiresAt
        : '',
    };
  }

  /** ¿Sigue viva la convalecencia marcada por el instante ISO dado? */
  function hasActiveConvalescence(isoStamp) {
    if (typeof isoStamp !== 'string' || isoStamp.trim() === '') return false;
    const expiry = new Date(isoStamp);
    return !Number.isNaN(expiry.getTime()) && expiry.getTime() > Date.now();
  }

  /**
   * Veredicto de la interfaz ante el gesto (RF-10.1): quien el canon veta ve
   * el gesto INHABILITADO con su leyenda — el veto es real (RNF-04) y el
   * backend es la última muralla.
   */
  function judgeFoundationGesture() {
    const viewer = foundationViewerState();

    if (!viewer.isAuthenticated) {
      return {
        enabled: false,
        legend: 'El Umbral de la Fundación pide vínculo: conságrate o renueva el tuyo antes de alzar una casa.',
      };
    }
    if (viewer.role === 'reader') {
      return { enabled: false, legend: 'Los neófitos sin pluma no alzan estandartes: tu rango aún no alcanza la fundación.' };
    }
    if (viewer.clanId !== '') {
      return { enabled: false, legend: 'La lealtad mágica es indivisible: ya militas bajo otro estandarte.' };
    }
    if (hasActiveConvalescence(viewer.convalescenceExpiresAt)) {
      return { enabled: false, legend: 'Tu esencia aún sana en Convalecencia Arcana: espera a que remita su hechizo.' };
    }
    return { enabled: true, legend: 'Fundar una hermandad propia' };
  }

  /** Despacha el rito al santuario (RF-10.3) y consume el veredicto. */
  async function dispatchFoundation(payload) {
    if (typeof clanClient?.foundClan !== 'function') {
      foundationModal?.reportError(null);
      return;
    }

    try {
      const result = await clanClient.foundClan(payload);

      if (result?.success) {
        foundationModal?.closeAfterSuccess();
        // La casa nació: el Salón se repinta (RF-10.4) y el fundador es
        // conducido a la ficha de su estandarte si el orquestador lo permite.
        await load();
        if (typeof onClanSelect === 'function' && typeof result.data?.id === 'string') {
          onClanSelect(result.data.id);
        }
        return;
      }

      // Veto del canon: el modal permanece abierto con el borrador (RF-10.5).
      foundationModal?.reportError(result);
    } catch (foundationFailure) {
      foundationModal?.reportError(null);
    }
  }

  /** Abre el Umbral si el veredicto de la interfaz es favorable (RF-10.1). */
  function openFoundation() {
    const verdict = judgeFoundationGesture();
    if (!verdict.enabled) return; // El veto de interfaz es REAL (RNF-04).

    if (foundationModal === null && foundationDialog !== null) {
      const viewer = foundationViewerState();
      foundationModal = createClanFoundationModalComponent(foundationDialog, {
        documentRef,
        userLineage: viewer.lineage,
        onConfirm: (payload) => {
          void dispatchFoundation(payload);
        },
        onClose: null,
      });
    }

    foundationModal?.open();
  }

  /** Expone el veredicto del gesto para el arnés y las pruebas del Salón. */
  function foundationGestureVerdict() {
    return judgeFoundationGesture();
  }

  /** Consulta los dos endpoints públicos y alimenta el componente. */
  async function load() {
    const currentSequence = ++fetchSequence;

    if (typeof activeDominionClient?.fetchLeaderboard !== 'function') {
      hall?.setError(null);
      return;
    }

    // En paralelo: el catálogo de linajes es best-effort, el Salón es la autoridad.
    const lineagesPromise = typeof activeDominionClient.fetchLineages === 'function'
      ? activeDominionClient.fetchLineages()
      : Promise.resolve({ success: false });

    const [lineagesResult, hallResult] = await Promise.all([
      lineagesPromise.catch(() => ({ success: false })),
      activeDominionClient.fetchLeaderboard().catch((error) => ({
        success: false,
        status: 0,
        error: {
          code: 'MANA_STREAM_INTERRUPTED',
          message: error instanceof Error ? error.message : String(error),
        },
      })),
    ]);

    // Respuesta tardía o vista desmontada: jamás se pinta.
    if (currentSequence !== fetchSequence || isDestroyed || hall === null) return;

    if (!hallResult?.success) {
      hall.setError(hallResult?.error ?? null);
      return;
    }

    // Degradación elegante: sin catálogo, el Salón sigue en pie.
    if (lineagesResult?.success && Array.isArray(lineagesResult.data)) {
      hall.setLineages(lineagesResult.data);
    }

    hall.setHall(hallResult.data ?? null);
  }

  /**
   * Refresco dirigido por evento (plan 4.1): la gloria recién acreditada en
   * la Cámara de Conjuración —o el corte dominical— relanza la consulta del
   * Salón, de modo que el podio semanal en vivo refleje el nuevo marcador sin
   * sondear el reloj (RNF-02). Es SOLO LECTURA: ningún evento decide montos.
   */
  function handleDominionAwarded() {
    if (isDestroyed || hall === null) return;
    void load();
  }

  /** Reintenta la última consulta tras un corte de corriente (RNF). */
  async function retry() {
    fetchSequence += 1; // invalida respuestas en vuelo del cliente fallido.
    hall?.render();
    await load();
  }

  /** Conmuta el cliente del Salón (reintentos con otro canal). */
  function setDominionClient(nextDominionClient) {
    activeDominionClient = nextDominionClient;
  }

  /**
   * Monta la vista completa. Idempotente y de SOLO LECTURA: ningún control
   * exige sesión (RF-06.1).
   */
  async function render() {
    if (isDestroyed) return;

    destroy(false);

    viewRoot = track(elementFactory('section'));
    // El tomo central acota el Salón a la anchura canónica del santuario.
    viewRoot.className = 'lineage-hall-view grimoire-tomo-container';
    mountRoot.appendChild(viewRoot);

    // El Salón pasa a ser la vista activa del store (plan 4.1).
    store?.setState?.({ currentView: 'clans' });

    hall = createLineageHallComponent(viewRoot, {
      onRetry: () => {
        void retry();
      },
      onClanSelect: typeof onClanSelect === 'function' ? onClanSelect : undefined,
      // Doble vía de acceso al Vestíbulo (SPEC-10, Tarea 4.2).
      onOpenVestibule: typeof onOpenVestibule === 'function' ? onOpenVestibule : undefined,
      // El Umbral de la Fundación (SPEC-07b, RF-10.1): el gesto vive en la
      // cabecera del Salón cuando la vista aporta el veredicto de la sesión.
      foundationGesture: foundationDialog !== null
        ? { open: openFoundation, verdict: foundationGestureVerdict }
        : undefined,
      elementFactory,
      documentRef,
    });
    hall.render();

    // El Salón escucha el bus mientras vive: la gloria acreditada en la
    // Cámara de Conjuración y la proclamación dominical lo refrescan.
    if (typeof eventTarget?.addEventListener === 'function') {
      eventTarget.addEventListener(POINTS_AWARDED_EVENT, handleDominionAwarded);
      eventTarget.addEventListener(WEEK_CLOSED_EVENT, handleDominionAwarded);
    }

    await load();
  }

  /**
   * Retira la vista del punto de montaje y libera sus nodos.
   * @param {boolean} [removeFromMount=true] El render interno lo invoca con
   *        false para limpiar la pintura previa antes de volver a montar.
   */
  function destroy(removeFromMount = true) {
    fetchSequence += 1; // invalida respuestas en vuelo.

    if (typeof eventTarget?.removeEventListener === 'function') {
      eventTarget.removeEventListener(POINTS_AWARDED_EVENT, handleDominionAwarded);
      eventTarget.removeEventListener(WEEK_CLOSED_EVENT, handleDominionAwarded);
    }

    hall?.destroy?.();
    hall = null;

    // El Umbral vive mientras viva la vista (RF-10.7: su borrador perece
    // con ella, jamás con un descarte).
    foundationModal?.destroy?.();
    foundationModal = null;

    for (const node of mountedNodes.splice(0)) {
      node.remove?.();
    }
    viewRoot = null;

    if (removeFromMount) {
      for (const child of [...(mountRoot.children ?? [])]) {
        if (String(child.className ?? '').includes('lineage-hall-view')) {
          child.remove?.();
        }
      }
    }

    if (removeFromMount) isDestroyed = true;
  }

  return { render, destroy, retry, setDominionClient, foundationGestureVerdict };
}
