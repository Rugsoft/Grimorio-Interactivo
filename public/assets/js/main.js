/**
 * main.js — Orquestador central del Grimorio Interactivo (Tarea 6.1).
 *
 * Integra todas las piezas verificadas de las Fases 3-5:
 *   - store (3.2)         → estado único y reactivo (plan 4.1).
 *   - spellClient (3.3)   → acceso REST con sobres controlados.
 *   - historyManager (3.4)→ hash #hechizo-slug, Atrás/Adelante (plan 4.3).
 *   - navbar (4.1)        → enlaces persistentes e interceptación (RF-02).
 *   - modales (4.3/4.4)   → pila ficha técnica + «Cruzar el Umbral» (plan 4.2).
 *   - vistas (5.1-5.6)    → landing, library, clans y error (RF-01/03/02/06).
 *
 * Criterio (Tarea 6.1): cargar la URL base muestra la portada, navegar por
 * la barra cambia fluidamente entre vistas sin recarga completa y un hash
 * directo `#hechizo-slug` abre la ficha técnica automáticamente (RF-04.1).
 *
 * Constitución:
 *   - Artículo I: ES Modules nativos, cero frameworks ni bundlers.
 *   - Artículo V: identificadores en inglés camelCase; comentarios castellanos.
 *
 * Inyección de dependencias: todo el grafo (store, cliente, window, document,
 * diálogos del shell) puede inyectarse para verificar sin navegador; los
 * valores por defecto resuelven el DOM real.
 */

import { createStore } from './state/store.js';
import {
  fetchFeatured as apiFetchFeatured,
  fetchSpells as apiFetchSpells,
  fetchSpellBySlug as apiFetchSpellBySlug,
  fetchClansPreview as apiFetchClansPreview,
} from './api/spellClient.js';
import { createHistoryManager, SPELL_HASH_PREFIX, parseSpellHash } from './utils/historyManager.js';
import { createNavbarComponent } from './components/navbarComponent.js';
import { createSpellDetailModalComponent } from './components/spellDetailModalComponent.js';
import { createAccessModalComponent } from './components/accessModalComponent.js';
import { createLandingView } from './views/landingView.js';
import { createLibraryView } from './views/libraryView.js';
// SPEC-01 (Tarea 5.6) legó la vista provisional de lectura pública de
// linajes (`clansPreviewView.js`); desde SPEC-07 (Tarea 6.3) la ruta del
// Salón la sirve `lineageHallView.js`, que la SUPERA con el pabellón del
// Dominio. El módulo legado permanece íntegro y verificado por su propio
// arnés, pero ya no se monta en la SPA.
import { createLineageHallView } from './views/lineageHallView.js';
import { createLineageOathView } from './views/lineageOathView.js';
import { createVestibuleView } from './views/vestibuleView.js';
import { createGrimoireCollectionView } from './views/grimoireCollectionView.js';
import { createClanView } from './views/clanView.js';
import { createClanClient } from './api/clanClient.js';
import { createErrorView } from './views/errorView.js';
import { createSpellCreatorView } from './views/spellCreatorView.js';
import { createGrimoireSimulatorView } from './views/grimoireSimulatorView.js';
import { createGrimoireClient } from './api/grimoireClient.js';
import { createDominionClient } from './api/dominionClient.js';
import {
  listDrafts as apiListDrafts,
  saveDraft as apiSaveDraft,
  updateDraft as apiUpdateDraft,
  deleteDraft as apiDeleteDraft,
  publishSpell as apiPublishSpell,
  updateExperimental as apiUpdateExperimental,
  createVariant as apiCreateVariant,
} from './api/spellCreatorClient.js';
import {
  checkSession as apiCheckSession,
  bind as apiBind,
  consecrate as apiConsecrate,
  dissolve as apiDissolve,
  dissolveAll as apiDissolveAll,
  fetchAuditLog as apiFetchAuditLog,
} from './api/authClient.js';
import {
  retainRoute as apiRetainRoute,
  fetchOathCatalog as apiFetchOathCatalog,
  sealOath as apiSealOath,} from './api/lineageOathClient.js';
import { createVestibuleClient } from './api/vestibuleClient.js';
import { createGrimoireCollectionClient } from './api/grimoireCollectionClient.js';
import { createCodexView } from './views/elementalCodexView.js';
import { createElementalMatrixClient } from './api/elementalMatrixClient.js';
import { createExperimentalHallView } from './views/experimentalHallView.js';
import { createMastersTowerView } from './views/mastersTowerView.js';
import { createModerationClient } from './api/moderationClient.js';
import { createAuditLogView } from './views/auditLogView.js';
import { createObjectionModalComponent } from './components/objectionModalComponent.js';
import { createMemoryBadgeRoot } from './components/userProfileBadge.js';
import { createConvalescenceBannerComponent } from './components/convalescenceBannerComponent.js';

/** Mapeo canónico de hash de URL a vista de la SPA. */
export const HASH_TO_VIEW_MAP = Object.freeze({
  '#/': 'landing',
  '': 'landing',
  '#/biblioteca': 'library',
  '#/codex': 'codex',
  '#/linajes': 'clans',
  '#/simulador': 'simulator',
  '#/creador': 'creator',
  '#/atrio': 'experimentalHall',
  '#/torre': 'tower',
  '#/bitacora': 'auditLog',
  // La ceremonia del primer acceso es una ruta real del enrutador
  // (deep-linkable, decisión §5.9 del plan): el error LINEAGE_OATH_REQUIRED
  // del backend y el desvío del interceptor conducen a este hash.
  '#/juramento': 'juramento',
  // El Vestíbulo de las Hermandades (SPEC-10, Tarea 4.2): ruta propia,
  // deep-linkable; el peregrino sin linaje queda retenido por el interceptor.
  '#/vestibulo': 'vestibule',
  // Mi Grimorio (SPEC-11, Tarea 5.3): el tomo personal del adepto, ruta
  // propia y deep-linkable; el peregrino sin linaje queda retenido por
  // el interceptor (SPEC-09) y el backend refuerza con 403.
  '#/grimorio': 'collection',
});

/** Mapeo canónico de vista a hash de URL. */
export const VIEW_TO_HASH_MAP = Object.freeze({
  landing: '#/',
  library: '#/biblioteca',
  codex: '#/codex',
  clans: '#/linajes',
  simulator: '#/simulador',
  creator: '#/creador',
  experimentalHall: '#/atrio',
  tower: '#/torre',
  auditLog: '#/bitacora',
  juramento: '#/juramento',
  vestibule: '#/vestibulo',
  collection: '#/grimorio',
});

/**
 * Crea la aplicación orquestada.
 *
 * @param {Object} options
 * @param {HTMLElement} [options.appRoot] `<main id="app">` del shell.
 * @param {HTMLElement} [options.navRoot] `<nav id="siteNav">` del shell.
 * @param {HTMLDialogElement} [options.spellDetailDialog] `<dialog>` Nivel 1 (plan 4.2).
 * @param {HTMLDialogElement} [options.accessDialog] `<dialog>` Nivel 2 (plan 4.2).
 * @param {Object} [options.spellClient] Cliente HTTP (inyectable en pruebas).
 * @param {Object} [options.authClient] Cliente de autenticación SPEC-03 (inyectable en pruebas).
 * @param {Object} [options.grimoireClient] Cliente del grimorio SPEC-05 (inyectable en pruebas).
 * @param {Object} [options.dominionClient] Cliente del Dominio SPEC-07, para el
 *        blasón del Clan Regente del Gran Portal (Tarea 5.2; inyectable en pruebas).
 * @param {HTMLElement} [options.badgeRoot] Contenedor del distintivo de sesión de la cabecera.
 * @param {HTMLElement} [options.arcaneNoticeRoot] Franja del perfil del mago
 *        donde se despliega el aviso de Convalecencia Arcana (Tarea 5.3).
 * @param {Window} [options.windowRef] Ventana (inyectable en pruebas).
 * @param {Document} [options.documentRef] Documento (inyectable en pruebas).
 * @returns {Object} API: { boot, store, navigate, openSpellDetailBySlug, destroy }.
 */
export function createGrimoireApp(options = {}) {
  const {
    appRoot = globalThis.document?.getElementById?.('app'),
    navRoot = globalThis.document?.getElementById?.('siteNav'),
    spellDetailDialog = globalThis.document?.getElementById?.('spellDetailModal'),
    accessDialog = globalThis.document?.getElementById?.('accessModal'),
    spellClient = {
      fetchFeatured: apiFetchFeatured,
      fetchSpells: apiFetchSpells,
      fetchSpellBySlug: apiFetchSpellBySlug,
      fetchClansPreview: apiFetchClansPreview,
    },
    badgeRoot = globalThis.document?.getElementById?.('navSessionSlot'),
    arcaneNoticeRoot = globalThis.document?.getElementById?.('arcaneNoticeSlot'),
    authClient = {
      checkSession: apiCheckSession,
      bind: apiBind,
      consecrate: apiConsecrate,
      dissolve: apiDissolve,
      dissolveAll: apiDissolveAll,
    },
    lineageOathClient = {
      retainRoute: apiRetainRoute,
      fetchOathCatalog: apiFetchOathCatalog,
      sealOath: apiSealOath,
    },
    oathDialog = globalThis.document?.getElementById?.('oathModal'),
    grimoireClient = createGrimoireClient(),
    dominionClient = createDominionClient(),
    clanClient = createClanClient(),
    elementalMatrixClient = createElementalMatrixClient(),
    moderationClient = createModerationClient(),
    auditClient = { fetchAuditLog: apiFetchAuditLog },
    windowRef = globalThis.window,
    documentRef = globalThis.document,
  } = options;

  const elementFactory = (tagName) => documentRef.createElement(tagName);

  /** Estado único de la aplicación (plan 4.1). */
  const store = createStore();

  /** Vista actualmente montada: { name, instance }. */
  let currentView = { name: null, instance: null };

  /** Escucha activa de hashchange para conmutación de vistas por URL. */
  let windowHashListener = null;

  /** Instancias de infraestructura creadas en boot(). */
  let navbar = null;

  /**
   * Vigilante del vínculo para la cabecera: la navbar nace con una bandera
   * y la sesión puede nacer (checkSession, «Renovar Vínculo», consagración)
   * o morir (disolución) en cualquier momento de la SPA. Sin esta guarda, el
   * enlace reservado seguía interceptando al erudito YA vinculado.
   */
  let unsubscribeSessionWatch = null;
  let navbarSessionFlag = false;
  let detailModal = null;
  let accessModal = null;
  let sessionBadge = null;
  let convalescenceNotice = null;
  let historyManager = null;

  /**
   * Rótulo de dictámenes a la espera (SPEC-10, Tarea 4.2 — RF-01.1):
   * distintivo del acceso al Vestíbulo en la cabecera. Se alimenta del
   * Endpoint 5 (unread-count) tras cada mudanza de sesión y se APAGA en
   * cuanto el Vestíbulo contempla sus veredictos — sin bloquear nunca la
   * navegación: su fallo es inocuo (best-effort).
   */
  const vestibuleClient = createVestibuleClient();
  // Mi Grimorio (SPEC-11, Tarea 5.3): el cliente del tomo personal.
  const grimoireCollectionClient = createGrimoireCollectionClient();
  let vestibuleBadgeCount = 0;
  let errorView = null;
  let isDestroyed = false;
  /** Bandera del listener `oath:sealed` del bus del shell (SPEC-09). */
  let windowOathSealedListener = false;

  /**
   * Desmonta la vista activa, si existe. Toda la SPA es de una vista a la vez.
   */
  function destroyCurrentView() {
    currentView.instance?.destroy?.();
    currentView = { name: null, instance: null };
  }

  /**
   * Enrutador frontend (plan 4.1): cambia de vista sin recarga completa.
   * @param {'landing'|'library'|'clans'|'creator'|'simulator'|'error'} viewName Vista destino.
   * @param {Object} [navigateOptions]
   * @param {'canonical'|'essays'} [navigateOptions.catalogMode] Tomo del Simulador.
   */
  /**
   * Vistas que un «Peregrino sin Linaje» puede pisar sin juramento
   * (lista blanca del plan §3.2): la propia ceremonia, el portal de
   * inicio y la gestión de credenciales a través del diálogo de acceso.
   * Todo lo demás queda retenido (RF-01.3).
   */
  const OATH_EXEMPT_VIEWS = Object.freeze(['landing', 'juramento', 'error']);

  /**
   * Interceptor de retención (SPEC-09, Tarea 3.2 — RF-01.3, RNF-04):
   * un «Peregrino sin Linaje» (sesión activa, lineage null, rol distinto
   * de supremeAdmin) que pida una vista no exenta es desviado a la
   * ceremonia del juramento, reteniendo su ruta en la sesión del servidor
   * para el retorno (RF-05.3). La decisión es LOCAL e instantánea vía el
   * store hidratado por auth/session (sin round-trip extra); la retención
   * de SUSTANCIA sigue siendo del backend (403 LINEAGE_OATH_REQUIRED).
   *
   * @param {string} requestedView Vista pedida por el navegante.
   * @returns {string} La vista que realmente debe montarse.
   */
  function resolveOathRetention(requestedView) {
    const state = store.getState();
    if (state.isAuthenticated !== true) return requestedView;
    if (state.userRole === 'supremeAdmin') return requestedView; // RF-01.6
    if (state.userLineage !== null) return requestedView;        // linajado
    if (OATH_EXEMPT_VIEWS.includes(requestedView)) return requestedView;

    // Retener la ruta ANTES de desviar (RF-05.3). El fallo del envío es
    // inocuo: jamás interrumpe el desvío hacia la ceremonia.
    const requestedHash = VIEW_TO_HASH_MAP[requestedView] ?? null;
    if (requestedHash !== null) {
      void lineageOathClient.retainRoute(requestedHash);
    }
    return 'juramento';
  }

  async function navigate(viewName, navigateOptions = {}) {
    if (isDestroyed) return;

    // El interceptor va ANTES de desmontar la vista actual: si retiene, la
    // vista viva no parpadea y la ceremonia se monta sobre el punto limpio.
    const effectiveView = resolveOathRetention(viewName);

    destroyCurrentView();

    // El orquestador es el dueño del punto de montaje: retira TODO el
    // contenido previo (incluido el placeholder de pre-hidratación del
    // shell «Abriendo el santuario…») antes de montar la nueva vista.
    if (typeof appRoot.replaceChildren === 'function') {
      appRoot.replaceChildren();
    } else {
      for (const childNode of [...(appRoot.children ?? [])]) {
        if (typeof childNode.remove === 'function') {
          childNode.remove();
        } else {
          const childIndex = appRoot.children.indexOf(childNode);
          if (childIndex !== -1) appRoot.children.splice(childIndex, 1);
        }
      }
    }

    // El estado refleja la vista EFECTIVA (la ceremonia si hubo retención);
    // el desvío hacia ella ocurre al final, tras resolver el caso del hash.
    store.setState({ currentView: effectiveView });

    // Retención activa: la vista solicitada no se monta (ni un frame).
    // El despacho continúa con la vista EFECTIVA — la ceremonia (Tarea 4.3,
    // caso 'juramento' más abajo), que es una vista real del enrutador.
    if (effectiveView !== viewName) {
      viewName = effectiveView;
    }

    if (viewName === 'landing') {
      const landingView = createLandingView(appRoot, {
        spellClient,
        // Blasón del Clan Regente en la cabecera del portal (SPEC-07, Tarea 5.2).
        dominionClient,
        onReservedAction: handleReservedAction,
        onSpellSelect: (slug, originElement) => openSpellDetailBySlug(slug, { originElement }),
        elementFactory,
        // El blasón se forja como SVG en línea (SPEC-02 RF-07): el documento viaja.
        documentRef,
      });
      currentView = { name: effectiveView, instance: landingView };
      await landingView.render();
      return;
    }

    if (viewName === 'library') {
      const libraryView = createLibraryView(appRoot, {
        store,
        spellClient,
        onSpellSelect: (slug, originElement) => openSpellDetailBySlug(slug, { originElement }),
        // El Tomo consulta al Salón quién reina para ceñir el ribete dorado a
        // los conjuros de su casa (SPEC-07, RF-04.4). Best-effort.
        dominionClient,
        elementFactory,
      });
      currentView = { name: effectiveView, instance: libraryView };
      await libraryView.render();
      return;
    }

    if (viewName === 'clans') {
      // Salón de los Linajes (SPEC-07, Tarea 6.3): pabellón del Dominio con
      // su clasificación semanal en vivo, su prestigio perpetuo y su Libro
      // Mayor de Campeones. Contemplación pública: ningún control exige
      // vínculo. La vista consulta los Endpoints 10 y 11 por su cuenta.
      const hallView = createLineageHallView(appRoot, {
        dominionClient,
        store,
        // Pulsar una casa del podio abre su ficha (Tarea 6.4).
        onClanSelect: (clanId) => {
          void navigate('clan', { clanId });
        },
        // Doble vía de acceso al Vestíbulo (SPEC-10, Tarea 4.2): el peregrino
        // sin linaje será retenido por el interceptor, como toda vista de gestión.
        onOpenVestibule: () => {
          void navigate('vestibule');
        },
        elementFactory,
        // Los sellos del podio se forjan en el documento del orquestador.
        documentRef,
      });
      currentView = { name: effectiveView, instance: hallView };
      await hallView.render();
      return;
    }

    if (viewName === 'clan') {
      // Ficha de la hermandad y su legado ancestral (SPEC-07, Tarea 6.4).
      // Contemplación pública: el visitante anónimo ve la casa entera y, si
      // quiere postular, el gesto conduce al umbral de acceso.
      const clanView = createClanView(appRoot, {
        clanClient,
        clanId: String(navigateOptions.clanId ?? ''),
        store,
        dominionClient,
        onReservedAction: handleReservedAction,
        onSpellSelect: (slug, originElement) => openSpellDetailBySlug(slug, { originElement }),
        onMembershipChanged: () => {
          // El vínculo del mago mudó: la cabecera y el aviso se resincronizan.
          void apiCheckSessionWrapper();
        },
        elementFactory,
        // El sello de la casa se forja en el documento del orquestador.
        documentRef,
      });
      currentView = { name: effectiveView, instance: clanView };
      await clanView.render();
      return;
    }

    if (viewName === 'creator') {
      // Taller de Hechizos (SPEC-04): la vista orquesta cliente, controles,
      // desglose en vivo y cajón de borradores. La navbar retiene la
      // intención (openCreator) para visitantes; al llegar aquí ya hay sesión.
      const creatorView = createSpellCreatorView(appRoot, {
        spellCreatorClient: {
          listDrafts: apiListDrafts,
          saveDraft: apiSaveDraft,
          updateDraft: apiUpdateDraft,
          deleteDraft: apiDeleteDraft,
          publishSpell: apiPublishSpell,
          updateExperimental: apiUpdateExperimental,
          createVariant: apiCreateVariant,
        },
        elementFactory,
      });
      currentView = { name: effectiveView, instance: creatorView };
      await creatorView.render();
      return;
    }

    if (viewName === 'simulator') {
      // Simulador de Grimorio (SPEC-05). El Tomo Canónico es público para
      // cualquier visitante; el tomo privado de Ensayos Arcanos exige un
      // vínculo consagrado (RF-01.2), de modo que la guardia retiene la
      // intención, despliega «Cruzar el Umbral» y deja al visitante en el
      // Tomo Canónico en lugar de ante un pergamino en blanco.
      const requestsEssays = navigateOptions.catalogMode === 'essays';
      const hasSession = store.getState().isAuthenticated === true;
      if (requestsEssays && !hasSession) {
        handleReservedAction('openGrimoire');
      }
      const simulatorView = createGrimoireSimulatorView(appRoot, {
        grimoireClient,
        elementFactory,
        document: documentRef,
        initialMode: requestsEssays && hasSession ? 'essays' : 'canonical',
        // CONVOCATORIA DESDE EL TOMO (SPEC-11, RF-03.1): la vista ilumina
        // la página del hechizo convocado tras cargar su catálogo. El motor
        // de partículas y la pronunciación de SPEC-05 quedan INTACTOS —
        // sin variantes nuevas (frontera del plan §1.1).
        initialSpellSlug: typeof navigateOptions.spellSlug === 'string' ? navigateOptions.spellSlug : null,
        // Gloria de hermandad de cada reacción detonada (SPEC-07, RF-03.2):
        // el orquestador aporta la sesión y el cliente; la Cámara solo narra.
        awardSimulatorPractice: (comboElement) => awardSimulatorPractice(comboElement),
      });
      currentView = { name: effectiveView, instance: simulatorView };
      await simulatorView.render();
      return;
    }

    if (viewName === 'codex') {
      // Códice de Afinidades (SPEC-06, Tarea 5.2): Rueda Rúnica y matriz elemental.
      const codexView = createCodexView(appRoot, {
        elementalMatrixClient,
        elementFactory,
        document: documentRef,
      });
      currentView = { name: effectiveView, instance: codexView };
      await codexView.render();
      return;
    }

    if (viewName === 'experimentalHall' || viewName === 'atrio') {
      // Atrio de los Arcanos Experimentales (SPEC-08, Tarea 6.4, RF-05.1).
      // Contemplación libre: la comunidad prueba conjuros en deliberación.
      const hallView = createExperimentalHallView(appRoot, {
        moderationClient,
        store,
        onSpellTest: () => {
          void navigate('simulator');
        },
        elementFactory,
        documentRef,
      });
      currentView = { name: 'experimentalHall', instance: hallView };
      await hallView.render();
      return;
    }

    if (viewName === 'tower' || viewName === 'torre') {
      // Torre de Deliberación (SPEC-08, Tarea 6.4, RF-05.4).
      // Vista exclusiva de Maestros y Administrador Supremo; RBAC con redirección.
      const towerView = createMastersTowerView(appRoot, {
        moderationClient,
        store,
        onSignatureIntent: async (spellId) => {
          await moderationClient?.signSpell?.(spellId);
        },
        onObjectionIntent: async (spellId) => {
          const modal = createObjectionModalComponent(appRoot, {
            spellId,
            documentRef,
          });
          const verdict = await modal.open();
          if (verdict?.confirmed && verdict.reason) {
            await moderationClient?.objectSpell?.(spellId, verdict.reason);
          }
        },
        onAccessDenied: () => {
          void navigate('library');
        },
        elementFactory,
        documentRef,
      });
      currentView = { name: 'tower', instance: towerView };
      await towerView.render();
      return;
    }

    if (viewName === 'auditLog' || viewName === 'bitacora') {
      // Bitácora de Auditoría Arcana (SPEC-03, Tarea 5.1).
      // Consulta pública e inmutable de veredictos, firmas, vetos y decretos.
      const logView = createAuditLogView(appRoot, {
        auditClient,
        elementFactory,
        onNavigate: (target) => {
          void navigate(target);
        },
      });
      currentView = { name: 'auditLog', instance: logView };
      await logView.render();
      return;
    }

    if (viewName === 'juramento') {
      // La Ceremonia del Juramento de Linaje (SPEC-09, Tarea 4.3, RF-02.1):
      // pantalla solemne y bloqueante del primer acceso. Solo llega aquí un
      // peregrino confirmado (la guarda de resolveOathRetention desvía) o una
      // vista exenta de la lista blanca. La vista emite `oath:sealed`/`oath:
      // failed` y el veredicto lo conduce handleOathSealed (Tarea 3.2).
      const oathView = createLineageOathView(appRoot, {
        lineageOathClient,
        oathDialog,
        elementFactory,
        documentRef,
      });
      currentView = { name: 'juramento', instance: oathView };
      await oathView.render();
      return;
    }

    if (viewName === 'vestibule') {
      // Vestíbulo de las Hermandades (SPEC-10, Tarea 5.5): ceremonia de
      // adhesión a clanes del propio linaje. El peregrino sin linaje jamás
      // llega aquí: el interceptor de retención lo desvía a «juramento».
      const vestibuleView = createVestibuleView(appRoot, {
        vestibuleClient,
        clanClient,
        // Un ingreso consumado muda el vínculo del mago: la cabecera y los
        // rótulos del shell se resincronizan (plan §4).
        onMembershipChanged: () => {
          void apiCheckSessionWrapper();
        },
        // Los veredictos contemplados apagan el rótulo del acceso (RF-03.4).
        onVerdictsAcknowledged: () => {
          setVestibuleBadgeCount(0);
        },
        elementFactory,
        documentRef,
      });
      currentView = { name: 'vestibule', instance: vestibuleView };
      await vestibuleView.render();
      return;
    }

    if (viewName === 'collection') {
      // Mi Grimorio (SPEC-11, Tarea 5.3): el tomo personal del adepto.
      // El peregrino sin linaje jamás llega aquí: el interceptor de
      // retención lo desvía a «juramento» y el backend refuerza con 403.
      const collectionView = createGrimoireCollectionView(appRoot, {
        collectionClient: grimoireCollectionClient,
        // La invitación del tomo vacío conduce a la Biblioteca (RF-02.2).
        onNavigateToLibrary: (hash) => {
          void navigate('library');
        },
        // CONVOCATORIA DESDE EL TOMO (SPEC-11, RF-03.1, Tarea 5.4): una
        // entrada viva abre el Simulador con su página ya iluminada. La
        // vista del tomo solo emite este gesto con `tomeMark` 'living'.
        onSummonSpell: (slug) => {
          void navigate('simulator', { spellSlug: slug });
        },
        elementFactory,
        documentRef,
      });
      currentView = { name: 'collection', instance: collectionView };
      await collectionView.render();
      return;
    }

    if (viewName === 'error') {
      // La vista de error se monta bajo demanda vía showSpellError();
      // navegar aquí sin estado concreto muestra el genérico de rescate.
      showSpellError(null);
    }
  }

  /**
   * Acredita al clan del adepto la gloria de una reacción de combo elemental
   * recién detonada en la Cámara de Conjuración (SPEC-07, RF-03.2).
   *
   * Frontera declarada: el orquestador es quien conoce el vínculo arcano y el
   * cliente del Dominio; la vista solo narra el recibo que aquí se retorne. El
   * techo diario de 50 PDA por adepto y su reinicio a las 00:00:00 UTC los
   * decide SIEMPRE el santuario: aquí no se recorta, acumula ni descuenta nada.
   *
   * Un visitante anónimo (el Tomo Canónico es público) practica combos sin que
   * se curse orden alguna: sin casa no hay gloria que acreditar.
   *
   * @param {string} comboElement Afinidad elemental del conjuro entrante.
   * @returns {Promise<Object|null>} Sobre del santuario, o null si no procede.
   */
  async function awardSimulatorPractice(comboElement) {
    if (store.getState().isAuthenticated !== true) return null;
    if (typeof dominionClient?.awardSimulatorCombo !== 'function') return null;

    try {
      const envelope = await dominionClient.awardSimulatorCombo(comboElement);

      // Efecto dirigido por eventos (plan 4.1): el Salón en vivo y —cuando lo
      // haya— el Tomo reaccionan al nuevo marcador sin sondear el reloj.
      if (envelope?.success === true) {
        dispatchDominionEvent('dominion:points-awarded', {
          clanId: String(envelope.data?.clanId ?? ''),
          comboElement: String(envelope.data?.comboElement ?? ''),
          dailyCap: Number(envelope.data?.dailyCap ?? 0) || 0,
          awardedPoints: Number(envelope.data?.award?.awardedPoints ?? 0) || 0,
          hasSynergy: envelope.data?.award?.hasSynergy === true,
          reason: envelope.data?.award?.reason ?? null,
        });
      }

      return envelope;
    } catch {
      // Corte de maná: la conjuración no se detiene por la gloria perdida.
      return null;
    }
  }

  /**
   * Despacha un evento del bus arcano del plan 4.1 desde la raíz de la SPA:
   * burbujea hasta el `window`, donde escuchan las vistas y los componentes.
   *
   * @param {string} eventName Nombre canónico del evento.
   * @param {Object} detail Carga útil del evento.
   */
  function dispatchDominionEvent(eventName, detail) {
    if (typeof appRoot?.dispatchEvent !== 'function') return;

    const event = typeof CustomEvent === 'function'
      ? new CustomEvent(eventName, { detail, bubbles: true })
      : { type: eventName, detail, bubbles: true };

    appRoot.dispatchEvent(event);
  }

  /**
   * Monta la vista de rescate (RF-06) para un sobre de error concreto.
   * @param {Object|null} errorEnvelope Sobre estándar del plan 2.4 (o null).
   */
  function showSpellError(errorEnvelope) {
    if (isDestroyed) return;
    destroyCurrentView();
    store.setState({ currentView: 'error' });

    errorView ??= createErrorView(appRoot, {
      onResetFilters: () => {
        store.setState({
          activeFilters: { query: '', schools: [], maxMana: null, includeExperimental: false },
        });
        navigate('library');
      },
      onReturnToLibrary: () => navigate('library'),
      // El reintento repite la última acción fallida (ficha) o re-renderiza:
      onRetry: () => navigate(lastFailedView ?? 'library'),
      elementFactory,
    });

    // Con sobre: errorView deduce kind del código (notFound/red). Sin sobre:
    // rescate genérico de red (degradación elegante de la Tarea 5.5).
    errorView.render(errorEnvelope ? { errorEnvelope } : { kind: 'networkError' });
  }

  /** Última vista desde la que se pidió una ficha (para reintentos). */
  let lastFailedView = 'library';

  /** Tarjeta de origen de la última ficha abierta (rescate de foco, RF-04.3). */
  let lastOriginCard = null;

  /**
   * Solicita la ficha técnica de un slug y la despliega (RF-04.1).
   * Punto único de entrada para selección de tarjeta y hashes directos.
   *
   * @param {string} spellSlug Slug del hechizo.
   * @param {Object} [options]
   * @param {boolean} [options.pushHistory=true] false cuando el historial ya
   *        registró la entrada (hashchange/popstate del historyManager, 3.4).
   */
  async function openSpellDetailBySlug(spellSlug, { pushHistory = true, originElement = null } = {}) {
    if (isDestroyed) return;
    const result = await spellClient.fetchSpellBySlug(spellSlug);

    if (result.success) {
      store.setState({ activeModal: { type: 'spellDetail', data: result.data } });
      // El origen (tarjeta pulsada) viaja a la ficha para el rescate de foco
      // (RF-04.3, plan 5.3). Los hashes directos no tienen tarjeta: null.
      lastOriginCard = originElement;
      detailModal.open(result.data, { originElement });
      if (pushHistory) {
        historyManager.openSpellDetail(spellSlug);
      }
      return;
    }

    // RF-06.2: pergamino desvanecido → vista de rescate con retorno directo.
    // La vista de error consume el sobre COMPLETO del plan 2.4 (success+error).
    showSpellError(result);
  }

  /**
   * Cierre de la ficha técnica (Escape/×/orquestación): sincroniza el store
   * y el historial sin abandonar la web (RF-04.2, RNF-05).
   */
  function handleDetailModalClosed() {
    if (store.getState().activeModal.type !== 'spellDetail') return;
    store.setState({ activeModal: { type: null, data: null } });
    historyManager.closeSpellDetail();
  }

  /**
   * Callbacks del historyManager (Tarea 3.4): el botón Atrás y los hashes
   * externos llegan aquí ya decididos (plan 4.3: event.state manda).
   */
  function handleSpellHistoryOpen(slug) {
    openSpellDetailBySlug(slug, { pushHistory: false });
  }
  function handleSpellHistoryClose() {
    if (detailModal && spellDetailDialog.open) {
      detailModal.close(); // dispara 'close' → handleDetailModalClosed.
    } else {
      handleDetailModalClosed();
    }
  }

  /**
   * Interceptación de acciones reservadas (RF-02.3, RF-01.4, RF-05.2):
   * retiene la intención en el store y despliega «Cruzar el Umbral».
   * SPEC-09: el registro ya no puebla selector de linaje alguno —
   * el juramento sucede en la ceremonia del primer acceso (RF-01.1).
   * @param {string} action 'openCreator' | 'joinClan' | 'addToGrimoire' | ...
   * @param {string|null} [targetSlug=null] Slug implicado, si lo hay.
   */
  function handleReservedAction(action, targetSlug = null) {
    if (isDestroyed) return;
    store.setState({ pendingIntent: { action, targetSlug } });
    accessModal.open({ action, targetSlug });
  }

  /**
   * Autenticación completada (SPEC-03): asienta la sesión, consume la
   * intención pendiente (RF-05.3) y restablece la navegación retenida
   * (p. ej. el Taller de Hechizos tras «Cruzar el Umbral»).
   *
   * @param {Object|null} user Sobre data.user del authClient (null = fallo).
   */
  function handleAuthenticated(user = null) {
    if (user !== null && typeof user === 'object') {
      store.setSession(user);
      sessionBadge?.setUser(user);
      // El perfil llega con el sobre de sesión: si porta la marca de
      // convalecencia (RF-01.7), el aviso se despliega y el veto se aplica.
      convalescenceNotice?.setConvalescence(user);
    }
    const retainedIntent = store.getState().pendingIntent;
    store.clearPendingIntent();
    // Restauración de la intención: solo acciones con vista propia. La
    // guarda del interceptor (resolveOathRetention) decide en cada desvío:
    // si la cuenta es peregrina, la vista pedida conduce a la ceremonia.
    if (retainedIntent?.action === 'openCreator') {
      void navigate('creator');
    } else if (retainedIntent?.action === 'openGrimoire') {
      // «Ver mi libro personal» retenido en el umbral: ahora con vínculo,
      // el Simulador abre directamente el tomo privado (RF-01.2).
      void navigate('simulator', { catalogMode: 'essays' });
    } else if (retainedIntent?.action === 'joinClan' && typeof retainedIntent.targetSlug === 'string' && retainedIntent.targetSlug !== '') {
      // Postulación retenida en el umbral (RF-01.5): ya con vínculo, la ficha
      // de la casa vuelve a montarse con su gesto de ingreso disponible.
      void navigate('clan', { clanId: retainedIntent.targetSlug });
    } else if (store.getState().userLineage === null) {
      // Peregrino sin intención retenida (registro nuevo o vínculo legado
      // renovado): aterriza en la ceremonia (RF-01.2, DoD de SPEC-09).
      void navigate('juramento');
    }
  }

  /**
   * El veredicto del juramento (SPEC-09, Tarea 3.2 — RF-01.7, RF-03.1):
   * la ceremonia notifica `oath:sealed` en el bus del shell con
   * { lineage, retainedRoute }; el orquestador actualiza el store (el
   * interceptor deja de retener al instante) y conduce al retorno: la
   * ruta retenida si existe y es interna, el portal de inicio en caso
   * contrario.
   *
   * @param {CustomEvent} event Evento del bus con detail { lineage, retainedRoute }.
   */
  /**
   * Consulta el contador de veredictos sin leer (SPEC-10, Endpoint 5) y
   * comanda al navbar el estado del distintivo «Tienes dictámenes a la
   * espera» (RF-01.1). Best-effort: el corte de maná apaga el distintivo
   * sin interrumpir la navegación (el rótulo jamás es un portero).
   *
   * @returns {Promise<void>}
   */
  async function refreshVestibuleBadge() {
    if (isDestroyed) return;
    if (store.getState().isAuthenticated !== true) {
      setVestibuleBadgeCount(0);
      return;
    }
    try {
      const envelope = await vestibuleClient.fetchUnreadVerdictsCount();
      if (isDestroyed) return;
      // Éxito O dictamen controlado: el contador solo crece con un 200.
      setVestibuleBadgeCount(
        envelope?.success === true ? Number(envelope.data?.unreadVerdictsCount ?? 0) || 0 : 0,
      );
    } catch {
      setVestibuleBadgeCount(0);
    }
  }

  /**
   * Fija el número de dictámenes sin leer y repinta el distintivo del
   * acceso al Vestíbulo en la cabecera. El apagado tras el contemplado
   * (RF-03.4) vuelve a pasar por aquí: la vista del Vestíbulo emitirá el
   * evento `vestibule:verdicts-acknowledged` en el bus del shell.
   *
   * @param {number} count Veredictos terminales sin contemplar.
   */
  function setVestibuleBadgeCount(count) {
    const nextCount = Number(count) || 0;
    if (nextCount === vestibuleBadgeCount) return; // idempotente
    vestibuleBadgeCount = nextCount;
    navbar?.setVestibuleBadgeCount?.(nextCount);
  }

  function handleOathSealed(event) {
    const detail = event?.detail ?? {};
    const lineage = typeof detail.lineage === 'string' && detail.lineage !== '' ? detail.lineage : null;

    // El store se actualiza con el linaje jurado SIN recargar: el
    // interceptor (resolveOathRetention) lee el estado vivo y libera al
    // adepto en el mismo gesto que conduce al retorno (RNF-04).
    const sessionUser = store.getState().currentUser;
    if (sessionUser !== null && lineage !== null) {
      store.setSession({ ...sessionUser, lineage });
      sessionBadge?.setUser({ ...sessionUser, lineage });
    }

    // Retorno: ruta retenida saneada, o el portal de inicio (RF-03.1).
    const retainedRoute = typeof detail.retainedRoute === 'string' && detail.retainedRoute.startsWith('#/')
      ? detail.retainedRoute
      : null;
    const targetView = retainedRoute !== null
      ? (HASH_TO_VIEW_MAP[retainedRoute] ?? 'landing')
      : 'landing';
    void navigate(targetView);
  }

  /**
   * «Ver mi libro personal» (RF-07.1 de SPEC-03): con vínculo abre el Tomo de
   * Ensayos; sin él, retiene la intención y despliega «Cruzar el Umbral».
   */
  function handleOpenGrimoire() {
    if (isDestroyed) return;
    if (store.getState().isAuthenticated === true) {
      void navigate('simulator', { catalogMode: 'essays' });
      return;
    }
    handleReservedAction('openGrimoire');
  }

  /**
   * Verificación inicial de sesión: el sobre decide el estado del store y
   * del badge (Tarea 4.2). Los fallos de red degradan a visitante en silencio.
   */
  async function apiCheckSessionWrapper() {
    const envelope = await authClient.checkSession();
    const sessionUser = envelope?.data?.user ?? null;
    if (envelope?.success === true && envelope?.data?.authenticated === true && sessionUser !== null) {
      store.setSession(sessionUser);
      sessionBadge?.setUser(sessionUser);
      convalescenceNotice?.setConvalescence(sessionUser);
    }
  }

  /**
   * Envío de «Renovar Vínculo» (plan Endpoint 2): llama a bind() con las
   * credenciales del shell. Éxito → handleAuthenticated; fallo → mensaje
   * del backend en el diálogo (fuente única de verdad), que permanece abierto.
   *
   * @param {object} credentials { alias, passphrase } del shell.
   * @param {Object|null} intent Intención pendiente retenida por el modal.
   */
  async function handleBindSubmit(credentials, intent) {
    // Las credenciales viajan con los nombres de campo del shell
    // (loginName/loginPassword — contrato del componente de acceso).
    const envelope = await authClient.bind(
      credentials.loginName ?? credentials.alias ?? '',
      credentials.loginPassword ?? credentials.passphrase ?? '',
    );
    if (envelope.success === true) {
      handleAuthenticated(envelope.data?.user ?? null);
      return;
    }
    // El diálogo ya se cerró tras notificar: se exhibe el mensaje del
    // backend (fuente única de verdad) y se reabre con la intención intacta.
    accessModal.reportError(envelope);
    reportAccessError(envelope);
    accessModal.open(intent);
  }

  /**
   * Envío de «Consagrarse» (plan Endpoint 1, enmienda SPEC-09): llama a
   * consecrate() con el contrato { alias, email, passphrase }. El registro
   * ya no porta `clanId`: la cuenta nace peregrina y jura linaje en la
   * ceremonia del primer acceso (RF-01.1, RF-01.2). Sin correo en el
   * shell, el campo email viaja vacío y el backend responde 400
   * INVALID_REGISTRATION_DATA (contrato ciego).
   *
   * @param {object} credentials { alias, passphrase, email? } del shell.
   * @param {Object|null} intent Intención pendiente retenida por el modal.
   */
  async function handleConsecrateSubmit(credentials, intent) {
    const envelope = await authClient.consecrate({
      alias: credentials.registerName ?? credentials.alias ?? '',
      email: credentials.registerEmail ?? credentials.email ?? '',
      passphrase: credentials.registerPassword ?? credentials.passphrase ?? '',
    });
    if (envelope.success === true) {
      handleAuthenticated(envelope.data?.user ?? null);
      return;
    }
    accessModal.reportError(envelope);
    reportAccessError(envelope);
    accessModal.open(intent);
  }

  /**
   * Exhibe el mensaje de error del backend dentro del diálogo (RF-03.1):
   * nodo accesible <p role="alert"> creado una sola vez y reutilizado.
   *
   * @param {Object|null} errorEnvelope Sobre estándar { success, error }.
   */
  function reportAccessError(errorEnvelope) {
    const message = typeof errorEnvelope?.error?.message === 'string' && errorEnvelope.error.message !== ''
      ? errorEnvelope.error.message
      : 'La corriente de maná no pudo procesar la petición.';
    let errorNode = typeof accessDialog.querySelector === 'function'
      ? accessDialog.querySelector('#accessModalError')
      : null;
    if (errorNode === null) {
      errorNode = documentRef.createElement('p');
      errorNode.setAttribute('id', 'accessModalError');
      errorNode.setAttribute('role', 'alert');
      accessDialog.appendChild(errorNode);
    }
    errorNode.textContent = message;
  }

  /**
   * Disolución de vínculos (RF-02.4) desde el menú del badge.
   * @param {'dissolve'|'dissolveAll'} variant Variante solicitada.
   */
  async function handleDissolve(variant) {
    const envelope = variant === 'dissolveAll'
      ? await authClient.dissolveAll()
      : await authClient.dissolve();
    if (envelope.success === true) {
      store.clearSession();
      sessionBadge?.clearUser();
      convalescenceNotice?.clear();
    } else {
      accessModal.reportError(envelope);
    }
  }

  /**
   * Arranque de la aplicación: infraestructura, enrutado inicial y, si la
   * URL llega con `#hechizo-slug`, apertura automática de la ficha (RF-04.1).
   */
  async function boot() {
    // Pila de modales (plan 4.2): ficha (Nivel 1) y acceso (Nivel 2).
    detailModal = createSpellDetailModalComponent(spellDetailDialog, {
      onClose: handleDetailModalClosed,
      onReservedAction: handleReservedAction,
      documentRef,
      windowRef,
    });
    accessModal = createAccessModalComponent(accessDialog, {
      onAuthenticate: handleBindSubmit,
      onRegister: handleConsecrateSubmit,
      onError: () => {}, // El mensaje lo pinta reportAccessError (nodo accesible).
      documentRef,
    });

    // Distintivo de sesión (Tarea 4.5): reemplaza «Cruzar el Umbral» por el
    // badge del vinculado y delega las disoluciones en el orquestador.
    sessionBadge = badgeRoot !== null && badgeRoot !== undefined
      ? createMemoryBadgeRoot(badgeRoot, {
          onCrossThreshold: () => handleReservedAction('crossThreshold'),
          onOpenGrimoire: () => handleOpenGrimoire(),
          onChangeClan: () => handleReservedAction('changeClan'),
          onDissolve: () => handleDissolve('dissolve'),
          onDissolveAll: () => handleDissolve('dissolveAll'),
          documentRef,
        })
      : null;

    // Aviso de Convalecencia Arcana en el perfil del mago (Tarea 5.3,
    // RF-01.6/RF-01.7). Sin franja en el shell el aviso simplemente no se
    // monta: degradación elegante (el veto sigue viviendo en el backend).
    convalescenceNotice = arcaneNoticeRoot !== null && arcaneNoticeRoot !== undefined
      ? createConvalescenceBannerComponent(arcaneNoticeRoot, {
          bus: windowRef,
          elementFactory,
        })
      : null;

    // Verificación de sesión al arrancar (Tarea 4.2): la cookie HttpOnly
    // decide el estado real; el badge y el store se sincronizan sin recarga.
    if (authClient?.checkSession) {
      void apiCheckSessionWrapper();
    }

    // El veredicto del juramento (SPEC-09, Tarea 3.2): la ceremonia anuncia
    // `oath:sealed` en el bus del shell y el orquestador actualiza store y
    // navegación sin recarga (RF-01.7, RF-03.1).
    if (typeof windowRef?.addEventListener === 'function') {
      windowRef.addEventListener('oath:sealed', handleOathSealed);
      windowOathSealedListener = true;
    }

    // Historial y enlaces directos (plan 4.3): con resolveInitialHash el
    // gestor notifica el hash inicial #hechizo-slug al instante (RF-04.1).
    historyManager = createHistoryManager(windowRef, {
      onSpellOpen: handleSpellHistoryOpen,
      onSpellClose: handleSpellHistoryClose,
      resolveInitialHash: true,
    });

    // Barra de navegación persistente (RF-02.1). Nace con la bandera VIVA
    // del store (checkSession() puede haber resuelto antes del primer
    // pintado) y se mantiene sincronizada con cada cambio de vínculo y rol.
    navbarSessionFlag = store.getState().isAuthenticated === true;
    let navbarRole = store.getState().userRole;
    navbar = createNavbarComponent(navRoot, {
      isAuthenticated: navbarSessionFlag,
      userRole: navbarRole,
      onNavigate: (viewName) => navigate(viewName),
      onReservedAction: (action) => handleReservedAction(action),
      elementFactory,
    });
    navbar.render();

    unsubscribeSessionWatch = store.subscribe((nextState) => {
      const nextFlag = nextState.isAuthenticated === true;
      const nextRole = nextState.userRole;
      if (nextFlag === navbarSessionFlag && nextRole === navbarRole) return;
      navbarSessionFlag = nextFlag;
      navbarRole = nextRole;
      navbar?.setSession(nextFlag, nextRole);
    });

    // Identidad de sesión (SPEC-09, Tarea 3.3 — RF-04.3): el badge es la
    // VISTA del store, no un receptor suelto. La suscripción repinta el
    // distintivo en CADA mutación de sesión (consecración, vinculación,
    // juramento sellado, disolución), sin recarga y sin duplicar el
    // repintado manual que el orquestador ya hacía en puntos dispersos.
    // setSession de set de store es idempotente a nivel de componente:
    // setUser reconstruye solo si el sobre cambia de identidad.
    unsubscribeSessionWatch = store.subscribe((nextState) => {
      const sessionUser = nextState.currentUser;
      sessionBadge?.setUser(sessionUser);
    });

    // Rótulo de dictámenes (SPEC-10, Tarea 4.2 — RF-01.1): la mudanza de
    // identidad (nace o muere el vínculo, linaje jurado) relanza la consulta
    // del contador. Best-effort: su fallo jamás bloquea la navegación.
    unsubscribeSessionWatch = store.subscribe(() => {
      void refreshVestibuleBadge();
    });

    /** Resuelve la vista correspondiente a un hash de navegación (#/...). */
    function resolveViewFromHash(rawHash) {
      if (typeof rawHash !== 'string') return null;
      const hash = rawHash.trim();
      if (hash.startsWith(SPELL_HASH_PREFIX)) return null;
      return HASH_TO_VIEW_MAP[hash] ?? null;
    }

    // Escucha activa de navegación por hash en la ventana (Atrás/Adelante y enlaces directos).
    windowHashListener = function handleWindowHashChange() {
      const targetView = resolveViewFromHash(windowRef?.location?.hash);
      if (targetView !== null && targetView !== currentView.name) {
        void navigate(targetView);
      }
    };
    windowRef?.addEventListener?.('hashchange', windowHashListener);

    // Enrutado inicial: la ruta por defecto del santuario es la portada,
    // salvo que la URL porte un hash de vista válido (#/biblioteca, #/atrio, #/torre, #/codex, #/bitacora).
    // Un hash directo #hechizo-slug montará la portada de fondo mientras el
    // historyManager (resolveInitialHash) despliega la ficha por su cuenta (plan 4.3).
    const initialViewFromHash = resolveViewFromHash(windowRef?.location?.hash);
    await navigate(initialViewFromHash ?? 'landing');
  }

  /**
   * Apagado limpio: desmonta vista e infraestructura (RNF-05).
   */
  function destroy() {
    if (isDestroyed) return;
    isDestroyed = true;
    destroyCurrentView();
    errorView?.destroy?.();
    unsubscribeSessionWatch?.();
    if (windowHashListener !== null && typeof windowRef?.removeEventListener === 'function') {
      windowRef.removeEventListener('hashchange', windowHashListener);
      windowHashListener = null;
    }
    if (windowOathSealedListener && typeof windowRef?.removeEventListener === 'function') {
      windowRef.removeEventListener('oath:sealed', handleOathSealed);
      windowOathSealedListener = false;
    }
    navbar?.destroy?.();
    detailModal?.destroy?.();
    accessModal?.destroy?.();
    historyManager?.destroy?.();
    convalescenceNotice?.destroy?.();
  }

  return {
    boot,
    store,
    navigate,
    openSpellDetailBySlug,
    destroy,
  };
}

// Arranque del navegador real: el shell carga este módulo como ES6
// (Tarea 2.1); en pruebas, createGrimoireApp se invoca con inyecciones.
const isBrowserRuntime = typeof globalThis.window !== 'undefined'
  && typeof globalThis.document !== 'undefined'
  && globalThis.document?.getElementById?.('app') != null;

if (isBrowserRuntime) {
  const grimoireApp = createGrimoireApp();
  grimoireApp.boot();
}
