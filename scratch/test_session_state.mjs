/**
 * test_session_state.mjs — Verificación del estado de sesión reactivo (Tarea 4.2).
 *
 * Estrategia TDD: este script se escribe ANTES de extender store.js.
 * Fase roja = el store no expone aún currentUser/isAuthenticated/userRole/
 * userClan ni los eventos de sesión.
 *
 * Valida contra el criterio «Hecho cuando» de specs/03-auth-rbac.tasks.md:
 *   Iniciar o cerrar sesión actualiza de forma reactiva el estado global
 *   del cliente sin recargar la página.
 *
 * Contratos verificados (plan 2.2, Tarea 4.2):
 *   - Estado: currentUser (null = anónimo), isAuthenticated (booleano),
 *     userRole ('reader' por defecto), userClan ({ id, name } o null).
 *   - Eventos de actualización de sesión: setSession(user) y clearSession()
 *     fusionan el estado y notifican a los suscriptores (patrón Observador
 *     nativo ya existente en el store, Tarea 3.2).
 *   - El anónimo porta el rol reader (RF-05.1, AuthMiddleware del backend).
 *   - Inmutabilidad: los fragmentos de sesión nacen congelados.
 *
 * Uso: node scratch/test_session_state.mjs
 */

import { createStore } from '../public/assets/js/state/store.js';

let assertsPassed = 0;
let assertsFailed = 0;

/** Aserta una condición y registra el resultado en la bitácora de consola. */
function assertCondition(condition, description) {
  if (condition) {
    assertsPassed++;
    console.log(`  [PASA] ${description}`);
  } else {
    assertsFailed++;
    console.log(`  [FALLA] ${description}`);
  }
}

console.log('== VERIFICACION TAREA 4.2: estado de sesión en el store reactivo ==\n');

// --- FASE 1: Estado inicial de sesión ---
console.log('FASE 1: Estado inicial de sesión (visitante anónimo)');

const store = createStore();
const initialState = store.getState();

assertCondition('currentUser' in initialState, 'El estado porta currentUser');
assertCondition(initialState.currentUser === null, 'currentUser inicia en null (visitante anónimo)');
assertCondition('isAuthenticated' in initialState, 'El estado porta isAuthenticated');
assertCondition(initialState.isAuthenticated === false, 'isAuthenticated inicia en false');
assertCondition('userRole' in initialState, 'El estado porta userRole');
assertCondition(initialState.userRole === 'reader', 'userRole inicia en reader (lectura pública, RF-05.1)');
assertCondition('userClan' in initialState, 'El estado porta userClan');
assertCondition(initialState.userClan === null, 'userClan inicia en null (sin linaje)');

// --- FASE 2: CRITERIO — iniciar sesión actualiza el estado de forma reactiva ---
console.log('\nFASE 2: Criterio Hecho cuando — iniciar sesión (setSession)');

const sessionNotifications = [];
store.subscribe((newState) => {
  sessionNotifications.push({
    isAuthenticated: newState.isAuthenticated,
    alias: newState.currentUser?.alias ?? null,
    role: newState.userRole,
    clanId: newState.userClan?.id ?? null,
  });
});

// El sobre data.user que entrega authClient.checkSession()/bind() (Tarea 4.1).
const boundUser = {
  id: 'usr_9a8b7c6d',
  alias: 'FrierenElf',
  role: 'master',
  clanId: 'cln_astral',
  clanName: 'Eruditos Astrales',
};

const notificationsBeforeBind = sessionNotifications.length;
store.setSession(boundUser);

assertCondition(typeof store.setSession === 'function', 'setSession(user) está disponible en el almacén');
assertCondition(
  store.getState().isAuthenticated === true,
  'setSession actualiza isAuthenticated a true sin recargar la página',
);
assertCondition(
  store.getState().currentUser?.alias === 'FrierenElf' && store.getState().currentUser?.id === 'usr_9a8b7c6d',
  'setSession deposita la entidad user en currentUser',
);
assertCondition(store.getState().userRole === 'master', 'userRole refleja el rol del vinculado (master)');
assertCondition(
  store.getState().userClan?.id === 'cln_astral' && store.getState().userClan?.name === 'Eruditos Astrales',
  'userClan porta { id, name } del linaje del vinculado',
);
assertCondition(
  sessionNotifications.length === notificationsBeforeBind + 1,
  'setSession notifica a los suscriptores (evento reactivo, patrón Observador nativo)',
);
assertCondition(
  sessionNotifications.at(-1)?.alias === 'FrierenElf' && sessionNotifications.at(-1)?.role === 'master',
  'El suscriptor recibe el nuevo estado de sesión ya actualizado',
);

// Normalización defensiva: usuario sin clan (clanId vacío) → userClan null.
store.setSession({ id: 'usr_solitario', alias: 'Andarin', role: 'editor', clanId: '', clanName: '' });
assertCondition(
  store.getState().isAuthenticated === true && store.getState().userClan === null,
  'Un usuario sin linaje queda autenticado con userClan en null (sin clan fantasma)',
);

// --- FASE 3: CRITERIO — cerrar sesión actualiza de forma reactiva ---
console.log('\nFASE 3: Criterio Hecho cuando — cerrar sesión (clearSession)');

const notificationsBeforeClear = sessionNotifications.length;
store.clearSession();

assertCondition(typeof store.clearSession === 'function', 'clearSession() está disponible en el almacén');
assertCondition(store.getState().isAuthenticated === false, 'clearSession actualiza isAuthenticated a false sin recargar');
assertCondition(store.getState().currentUser === null, 'clearSession deposita currentUser en null');
assertCondition(store.getState().userRole === 'reader', 'clearSession degrada userRole a reader (lectura pública)');
assertCondition(store.getState().userClan === null, 'clearSession limpia userClan');
assertCondition(
  sessionNotifications.length === notificationsBeforeClear + 1,
  'clearSession notifica a los suscriptores (evento reactivo de cierre)',
);

// --- FASE 4: Inmutabilidad y aislamiento ---
console.log('\nFASE 4: Inmutabilidad del estado de sesión');

const frozenStore = createStore();
frozenStore.setSession(boundUser);
const frozenState = frozenStore.getState();

assertCondition(Object.isFrozen(frozenState), 'El estado raíz permanece congelado (contrato del store, Tarea 3.2)');
assertCondition(Object.isFrozen(frozenState.currentUser), 'El fragmento currentUser nace congelado (sin mutaciones externas)');

let mutationRejected = true;
try {
  frozenState.currentUser.alias = 'AliasPirata';
  if (frozenState.currentUser.alias === 'AliasPirata') {
    mutationRejected = false;
  }
} catch {
  // En strict mode la escritura lanza: también es rechazo válido.
}
assertCondition(mutationRejected || frozenState.currentUser.alias === 'FrierenElf', 'Una mutación externa de currentUser no prospera');

// Aislamiento: dos almacenes con sesiones independientes.
const isolatedStore = createStore();
assertCondition(
  isolatedStore.getState().isAuthenticated === false && frozenStore.getState().isAuthenticated === true,
  'Cada createStore() mantiene su sesión aislada (sin estado global compartido)',
);

// Rol fuera del canon: se respeta lo que dicta el backend (fuente única),
// sin validación duplicada en el cliente — el backend ya valida el canon.
frozenStore.setSession({ id: 'usr_x', alias: 'RolePortador', role: 'editor', clanId: 'cln_ember', clanName: 'Guardianes de Ascuas' });
assertCondition(
  frozenStore.getState().userRole === 'editor' && frozenStore.getState().userClan?.id === 'cln_ember',
  'Cambios de rol y clan entre sesiones se reflejan en cada setSession',
);

// --- Resumen final ---
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log("\nRESULTADO: EXITO — La Tarea 4.2 cumple su criterio 'Hecho cuando'.");
  process.exit(0);
}

console.log('\nRESULTADO: DENEGADO — Corregir los asertos marcados con [FALLA].');
process.exit(1);
