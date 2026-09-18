/**
 * store.js — Gestor de estado reactivo en memoria pura (Tarea 3.2).
 *
 * Patrón Observador nativo: createStore() retorna un almacén con getState(),
 * setState() y subscribe(). Cero dependencias (Artículo I).
 *
 * Constitución:
 *   - Artículo I: solo Web/JS estándar; sin frameworks reactivos.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * Contratos del plan 4.1:
 *   - Estado: currentView, catalogSpells, activeFilters, pagination,
 *     activeModal, pendingIntent.
 *   - RF-05.4: la intención pendiente reside SOLO en memoria volátil:
 *     este módulo jamás toca localStorage (si el navegador lo bloquea,
 *     nada falla: la intención vive y muere con la página).
 *
 * Inmutabilidad: cada setState produce un objeto nuevo (fusión superficial)
 * congelado, para que componentes y comparaciones nunca pisen estado ajeno.
 */

/** Vistas del enrutador frontend (plan 4.1). */
export const VIEW_NAMES = ['landing', 'library', 'clans', 'error'];

/** Tope de bloque del catálogo (RF-03.7: 50 pergaminos por tanda). */
export const CATALOG_PAGE_SIZE = 50;

/**
 * Construye el estado inicial conforme al plan 4.1.
 * @returns {object} Estado raíz fresco.
 */
function createInitialState() {
  return {
    /** Vista activa de la SPA. */
    currentView: 'landing',

    /** Tarjetas de catálogo acumuladas (bloques de 50). */
    catalogSpells: [],

    /** Filtros activos del catálogo (RF-03). */
    activeFilters: {
      query: '',
      schools: [],
      maxMana: null,
      includeExperimental: false,
    },

    /** Paginación incremental (RF-03.7). */
    pagination: {
      offset: 0,
      limit: CATALOG_PAGE_SIZE,
      hasMore: true,
      isLoading: false,
    },

    /** Modal activo: { type: null | 'spellDetail' | 'access', data }. */
    activeModal: {
      type: null,
      data: null,
    },

    /** Intención interceptada por el diálogo de acceso (RF-05.2). */
    pendingIntent: {
      action: null,
      targetSlug: null,
    },

    /** Estado de sesión del cliente (Tarea 4.2, SPEC-03).
     *  currentUser porta el sobre data.user de authClient (Tarea 4.1);
     *  null = visitante anónimo. El anónimo porta el rol reader (RF-05.1). */
    currentUser: null,
    isAuthenticated: false,
    userRole: 'reader',
    userClan: null,
    /** El vínculo perpetuo del Juramento de Linaje (SPEC-09, Tarea 3.2).
     *  null = «Peregrino sin Linaje»: el interceptor de navigate() retiene
     *  al portador hacia la ceremonia (RF-01.3). La decisión es local e
     *  instantánea (RNF-04): sin round-trip extra al hidratar. */
    userLineage: null,
  };
}

/**
 * Congela profundamente el estado: el objeto y sus anidados no se mutan.
 * @param {unknown} value Valor a congelar.
 * @returns {unknown} El mismo valor, sellado contra mutación.
 */
function deepFreeze(value) {
  if (value !== null && typeof value === 'object') {
    for (const propertyKey of Object.keys(value)) {
      deepFreeze(value[propertyKey]);
    }
    Object.freeze(value);
  }
  return value;
}

/**
 * Fábrica del almacén reactivo.
 * Cada llamada crea un almacén independiente (aislamiento para pruebas).
 */
export function createStore() {
  let currentState = deepFreeze(createInitialState());
  const subscribers = new Set();

  /**
   * Retorna el estado actual (congelado).
   * @returns {object} Estado raíz inmutable.
   */
  function getState() {
    return currentState;
  }

  /**
   * CRITERIO T3.2: fusión superficial del fragmento dado sobre el estado,
   * con notificación a todos los suscriptores registrados.
   *
   * @param {object} partialState Fragmento de estado a fusionar (superficial).
   */
  function setState(partialState) {
    // Fusión superficial: los objetos anidados se reemplazan completos
    // (quien quiera tocar activeFilters entrega uno nuevo; nunca muta).
    const nextState = deepFreeze({ ...currentState, ...partialState });

    // El nuevo estado se asienta ANTES de avisar: los suscriptores leen
    // siempre el estado ya actualizado al consultarlo dentro del callback.
    currentState = nextState;

    for (const notifySubscriber of subscribers) {
      notifySubscriber(currentState);
    }
  }

  /**
   * Registra un suscriptor de cambios de estado.
   * @param {(state: object) => void} listener Callback con el nuevo estado.
   * @returns {() => void} Función de baja del suscriptor.
   */
  function subscribe(listener) {
    subscribers.add(listener);
    return function unsubscribe() {
      subscribers.delete(listener);
    };
  }

  /**
   * Abono incremental del catálogo (RF-03.7): concatena la nueva tanda
   * de pergaminos sin mutar el array previo.
   * @param {Array<object>} nextSpells Página nueva del catálogo.
   */
  function appendCatalogSpells(nextSpells) {
    setState({
      catalogSpells: [...currentState.catalogSpells, ...nextSpells],
    });
  }

  /**
   * Restablece la intención pendiente tras completar la acción (RF-05.3).
   */
  function clearPendingIntent() {
    setState({
      pendingIntent: { action: null, targetSlug: null },
    });
  }

  /**
   * CRITERIO T4.2: registra la sesión abierta en el estado global.
   * Fusión superficial + notificación a suscriptores: iniciar sesión
   * actualiza de forma reactiva la interfaz sin recargar la página.
   *
   * El sobre data.user viene del authClient (Tarea 4.1): { id, alias,
   * role, clanId, clanName }. El fragmento userClan se normaliza a
   * { id, name } o null cuando el usuario no porta linaje.
   *
   * @param {object|null} user Entidad del vinculado (sobre del backend).
   */
  function setSession(user) {
    const sessionUser = user !== null && typeof user === 'object' ? user : null;
    const clanId = typeof sessionUser?.clanId === 'string' ? sessionUser.clanId : '';
    const clanName = typeof sessionUser?.clanName === 'string' ? sessionUser.clanName : '';
    // El linaje jurado viaja en el sobre de sesión (auth/me, Tarea 2.5):
    // solo una cadena no vacía del canon declara vínculo; todo lo demás
    // (ausente, null, vacío) es peregrino.
    const lineage = typeof sessionUser?.lineage === 'string' && sessionUser.lineage !== ''
      ? sessionUser.lineage
      : null;

    setState({
      currentUser: sessionUser,
      isAuthenticated: sessionUser !== null,
      userRole: typeof sessionUser?.role === 'string' && sessionUser.role !== '' ? sessionUser.role : 'reader',
      // Sin clan real no se fabrica un clan fantasma: null es la ausencia.
      userClan: clanId !== '' ? { id: clanId, name: clanName } : null,
      userLineage: lineage,
    });
  }

  /**
   * CRITERIO T4.2: cierra la sesión en el estado global. El anónimo
   * degrada al rol reader de lectura pública (RF-05.1), espejando el
   * comportamiento del AuthMiddleware del backend (SPEC-03, Tarea 3.1).
   */
  function clearSession() {
    setState({
      currentUser: null,
      isAuthenticated: false,
      userRole: 'reader',
      userClan: null,
      userLineage: null,
    });
  }

  return {
    getState,
    setState,
    subscribe,
    appendCatalogSpells,
    clearPendingIntent,
    setSession,
    clearSession,
  };
}
