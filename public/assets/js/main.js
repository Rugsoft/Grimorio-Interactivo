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
import {
  listDrafts as apiListDrafts,
  saveDraft as apiSaveDraft,
  updateDraft as apiUpdateDraft,
  deleteDraft as apiDeleteDraft,
  publishSpell as apiPublishSpell,
  updateExperimental as apiUpdateExperimental,
  createVariant as apiCreateVariant,
} from './api/spellCreatorClient.js';

/**
 * Crea la aplicación orquestada.
 *
 * @param {Object} options
 * @param {HTMLElement} [options.appRoot] `<main id="app">` del shell.
 * @param {HTMLElement} [options.navRoot] `<nav id="siteNav">` del shell.
 * @param {HTMLDialogElement} [options.spellDetailDialog] `<dialog>` Nivel 1 (plan 4.2).
 * @param {HTMLDialogElement} [options.accessDialog] `<dialog>` Nivel 2 (plan 4.2).
 * @param {Object} [options.spellClient] Cliente HTTP (inyectable en pruebas).
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
  let detailModal = null;
  let accessModal = null;
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
   * @param {'landing'|'library'|'clans'|'error'} viewName Vista destino.
   */
  async function navigate(viewName) {
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
  }

  /**
   * Autenticación completada (enganche SPEC-03): la intención pendiente se
   * consume (RF-05.3). El diálogo de acceso se cierra por sí solo.
   */
  function handleAuthenticated() {
    store.clearPendingIntent();
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
      onAuthenticate: handleAuthenticated,
      onRegister: handleAuthenticated,
      documentRef,
    });

    // Historial y enlaces directos (plan 4.3): con resolveInitialHash el
    // gestor notifica el hash inicial #hechizo-slug al instante (RF-04.1).
    historyManager = createHistoryManager(windowRef, {
      onSpellOpen: handleSpellHistoryOpen,
      onSpellClose: handleSpellHistoryClose,
      resolveInitialHash: true,
    });

    // Barra de navegación persistente (RF-02.1).
    navbar = createNavbarComponent(navRoot, {
      isAuthenticated: false, // SPEC-03 sustituirá esto por la sesión real.
      onNavigate: (viewName) => navigate(viewName),
      onReservedAction: (action) => handleReservedAction(action),
      elementFactory,
    });
    navbar.render();

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
    navbar?.destroy?.();
    detailModal?.destroy?.();
    accessModal?.destroy?.();
    historyManager?.destroy?.();
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
