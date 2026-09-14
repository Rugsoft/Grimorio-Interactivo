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
import { createClansPreviewView } from './views/clansPreviewView.js';
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
} from './api/authClient.js';
import { createMemoryBadgeRoot } from './components/userProfileBadge.js';
import { createConvalescenceBannerComponent } from './components/convalescenceBannerComponent.js';

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
    grimoireClient = createGrimoireClient(),
    dominionClient = createDominionClient(),
    windowRef = globalThis.window,
    documentRef = globalThis.document,
  } = options;

  const elementFactory = (tagName) => documentRef.createElement(tagName);

  /** Estado único de la aplicación (plan 4.1). */
  const store = createStore();

  /** Vista actualmente montada: { name, instance }. */
  let currentView = { name: null, instance: null };

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
  let errorView = null;
  let isDestroyed = false;

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
  async function navigate(viewName, navigateOptions = {}) {
    if (isDestroyed) return;
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

    store.setState({ currentView: viewName });

    if (viewName === 'landing') {
      const landingView = createLandingView(appRoot, {
        spellClient,
        // Blasón del Clan Regente en la cabecera del portal (SPEC-07, Tarea 5.2).
        dominionClient,
        onReservedAction: handleReservedAction,
        onSpellSelect: (slug, originElement) => openSpellDetailBySlug(slug, { originElement }),
        elementFactory,
      });
      currentView = { name: viewName, instance: landingView };
      await landingView.render();
      return;
    }

    if (viewName === 'library') {
      const libraryView = createLibraryView(appRoot, {
        store,
        spellClient,
        onSpellSelect: (slug, originElement) => openSpellDetailBySlug(slug, { originElement }),
        elementFactory,
      });
      currentView = { name: viewName, instance: libraryView };
      await libraryView.render();
      return;
    }

    if (viewName === 'clans') {
      const clansView = createClansPreviewView(appRoot, {
        store,
        spellClient,
        elementFactory,
      });
      currentView = { name: viewName, instance: clansView };
      await clansView.render();
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
      currentView = { name: viewName, instance: creatorView };
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
      });
      currentView = { name: viewName, instance: simulatorView };
      await simulatorView.render();
      return;
    }

    if (viewName === 'error') {
      // La vista de error se monta bajo demanda vía showSpellError();
      // navegar aquí sin estado concreto muestra el genérico de rescate.
      showSpellError(null);
    }
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
   * @param {string} action 'openCreator' | 'joinClan' | 'addToGrimoire' | ...
   * @param {string|null} [targetSlug=null] Slug implicado, si lo hay.
   */
  function handleReservedAction(action, targetSlug = null) {
    if (isDestroyed) return;
    store.setState({ pendingIntent: { action, targetSlug } });
    accessModal.open({ action, targetSlug });
    // El selector de linaje exige el catálogo vivo (RF-01.1).
    void refreshClansForModal();
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
    // Restauración de la intención: solo acciones con vista propia.
    if (retainedIntent?.action === 'openCreator') {
      void navigate('creator');
    } else if (retainedIntent?.action === 'openGrimoire') {
      // «Ver mi libro personal» retenido en el umbral: ahora con vínculo,
      // el Simulador abre directamente el tomo privado (RF-01.2).
      void navigate('simulator', { catalogMode: 'essays' });
    }
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
   * Envío de «Consagrarse» (plan Endpoint 1): llama a consecrate() con el
   * contrato { alias, email, passphrase, clanId }. El clan es obligatorio
   * (RF-01.1); sin correo en el shell, el campo email viaja vacío y el
   * backend responde 400 INVALID_REGISTRATION_DATA (contrato ciego).
   *
   * @param {object} credentials { alias, passphrase, clanId? } del shell.
   * @param {Object|null} intent Intención pendiente retenida por el modal.
   */
  async function handleConsecrateSubmit(credentials, intent) {
    // Contrato del Endpoint 1: { alias, email, passphrase, clanId }. El
    // correo llega del campo registerEmail del shell; el linaje del
    // selector obligatorio que el modal añade a las credenciales (RF-01.1).
    const envelope = await authClient.consecrate({
      alias: credentials.registerName ?? credentials.alias ?? '',
      email: credentials.registerEmail ?? credentials.email ?? '',
      passphrase: credentials.registerPassword ?? credentials.passphrase ?? '',
      clanId: credentials.clanId ?? '',
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
   * Puebla el selector OBLIGATORIO de linaje (RF-01.1) con los clanes del
   * catálogo (RF-02.2 del portal). Idempotente: se repuebla en cada apertura.
   */
  async function refreshClansForModal() {
    if (typeof accessModal.populateClans !== 'function') return;
    const envelope = await spellClient.fetchClansPreview();
    if (envelope?.success === true && Array.isArray(envelope.data)) {
      accessModal.populateClans(envelope.data.map((clan) => ({ id: clan.id, name: clan.name })));
    }
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

    // Historial y enlaces directos (plan 4.3): con resolveInitialHash el
    // gestor notifica el hash inicial #hechizo-slug al instante (RF-04.1).
    historyManager = createHistoryManager(windowRef, {
      onSpellOpen: handleSpellHistoryOpen,
      onSpellClose: handleSpellHistoryClose,
      resolveInitialHash: true,
    });

    // Barra de navegación persistente (RF-02.1). Nace con la bandera VIVA
    // del store (checkSession() puede haber resuelto antes del primer
    // pintado) y se mantiene sincronizada con cada cambio de vínculo.
    navbarSessionFlag = store.getState().isAuthenticated === true;
    navbar = createNavbarComponent(navRoot, {
      isAuthenticated: navbarSessionFlag,
      onNavigate: (viewName) => navigate(viewName),
      onReservedAction: (action) => handleReservedAction(action),
      elementFactory,
    });
    navbar.render();

    unsubscribeSessionWatch = store.subscribe((nextState) => {
      const nextFlag = nextState.isAuthenticated === true;
      if (nextFlag === navbarSessionFlag) return;
      navbarSessionFlag = nextFlag;
      navbar?.setSession(nextFlag);
    });

    // Enrutado inicial: la ruta por defecto del santuario es la portada.
    // Un hash directo #hechizo-slug montará la portada de fondo mientras el
    // historyManager (resolveInitialHash) despliega la ficha por su cuenta
    // (criterio 6.1, plan 4.3).
    await navigate('landing');
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
