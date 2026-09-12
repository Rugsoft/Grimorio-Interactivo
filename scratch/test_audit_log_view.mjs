/**
 * test_audit_log_view.mjs — Verificación de la Vista pública de la Bitácora
 * de Auditoría Arcana (Tarea 5.1 de SPEC-03).
 *
 * Estrategia TDD: este script se escribe ANTES que auditLogView.js.
 * Fase roja = el módulo public/assets/js/views/auditLogView.js no existe.
 *
 * Valida contra el criterio «Hecho cuando» de specs/03-auth-rbac.tasks.md:
 *   Cualquier usuario puede consultar la bitácora pública, filtrar por
 *   clan y leer las justificaciones de cada validación o veto.
 *
 * Contratos verificados (RF-08.2, Art. III, plan Endpoint 6):
 *   - Tabla paginada de veredictos: alias del actuante, rol, acción,
 *     objetivo y marca temporal por fila (datos de fetchAuditLog, Tarea 4.1).
 *   - Sellos de clan: el filtro clanId acota los veredictos de un linaje.
 *   - Motivos solemnes: la justificación de cada validación/veto se lee
 *     íntegra en la fila (Artículo III, transparencia).
 *   - Paginación: controles anterior/siguiente con metadatos del plan
 *     (page, limit, totalItems, totalPages).
 *   - Publicidad: la vista no exige sesión; anónimo y consagrado ven lo mismo.
 *   - Art. I: DOM nativo sin innerHTML; Art. IV/V: leyendas solemnes,
 *     identificadores camelCase.
 *
 * Uso: node scratch/test_audit_log_view.mjs
 */

import { createAuditLogView } from '../public/assets/js/views/auditLogView.js';

let assertsPassed = 0;
let assertsFailed = 0;
let uncaughtErrors = 0;

process.on('uncaughtException', () => { uncaughtErrors++; });
process.on('unhandledRejection', () => { uncaughtErrors++; });

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

// --- DOM simulado (patrón consolidado de las Tareas 4.x/5.x) ---
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    listeners: {},
    classes: new Set(),
    _textContent: '',
    parentElement: null,
    value: '',
    disabled: false,
    setAttribute(name, v) { this.attributes[name] = String(v); },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    hasAttribute(name) { return name in this.attributes; },
    removeAttribute(name) { delete this.attributes[name]; },
    addEventListener(eventName, listener) { (this.listeners[eventName] ??= []).push(listener); },
    removeEventListener(eventName, listener) { this.listeners[eventName] = (this.listeners[eventName] ?? []).filter((l) => l !== listener); },
    dispatch(eventName, eventObject = {}) {
      for (const listener of this.listeners[eventName] ?? []) {
        listener({ type: eventName, preventDefault() {}, stopPropagation() {}, currentTarget: this, target: this, ...eventObject });
      }
    },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    remove() {
      if (!this.parentElement) return;
      const index = this.parentElement.children.indexOf(this);
      if (index >= 0) this.parentElement.children.splice(index, 1);
      this.parentElement = null;
    },
    get textContent() { return this._textContent; },
    set textContent(value) { this._textContent = String(value); },
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1)'); },
    set innerHTML(value) { throw new Error(`PROHIBIDO innerHTML: '${String(value).slice(0, 40)}'`); },
    focus() {},
  };
  return element;
}

/** Búsqueda recursiva por id / clase. */
function queryById(node, elementId, found = []) {
  for (const child of node.children) {
    if (child.getAttribute('id') === elementId) found.push(child);
    queryById(child, elementId, found);
  }
  return found;
}
function queryByClass(node, className, found = []) {
  for (const child of node.children) {
    if (child.classes.has(className)) found.push(child);
    queryByClass(child, className, found);
  }
  return found;
}
const byId = (root, elementId) => queryById(root, elementId)[0] ?? null;
const byClass = (root, className) => queryByClass(root, className)[0] ?? null;
const allByClass = (root, className) => queryByClass(root, className);

/** Busca nodos hoja cuyo texto contenga la aguja dada. */
function findByText(node, needle, found = []) {
  if (node.textContent.includes(needle) && node.children.length === 0) found.push(node);
  for (const child of node.children) findByText(child, needle, found);
  return found;
}

/**
 * Cliente fetchAuditLog simulado: entrega la página del Endpoint 6.
 * Los metadatos y los ítems cambian según los parámetros recibidos.
 */
function buildAuditClientStub() {
  const calls = [];
  return {
    calls,
    /** Última página servida (para simular filtros). */
    fetchAuditLog: async (params = {}) => {
      calls.push({ ...params });
      const requestedClan = params.clanId ?? null;

      // Con filtro de clan: solo el veredicto CLAN_MODIFY del linaje dado.
      if (requestedClan === 'cln_astral') {
        return {
          success: true,
          status: 200,
          data: {
            items: [
              { id: 142, actorAlias: 'HeiterElSabio', actorRole: 'master', actionType: 'CLAN_MODIFY', targetEntityType: 'clan', targetEntityId: 'cln_astral', justification: 'Motto actualizado por decisión del consejo.', createdAt: '2026-09-12T14:22:10Z' },
            ],
            pagination: { page: 1, limit: 25, totalItems: 1, totalPages: 1 },
          },
        };
      }

      // Sin filtro: 3 veredictos solemnes de ejemplo (página 1 de 2).
      return {
        success: true,
        status: 200,
        data: {
          items: [
            { id: 144, actorAlias: 'HeiterElSabio', actorRole: 'master', actionType: 'SIGN_VALIDATE', targetEntityType: 'spell', targetEntityId: 'spl_llamas_frieren', justification: 'Composición matemática de maná equilibrada y componentes rigurosamente descritos.', createdAt: '2026-09-12T14:22:10Z' },
            { id: 143, actorAlias: 'SupremoArquitecto', actorRole: 'supremeAdmin', actionType: 'ADMIN_VETO', targetEntityType: 'spell', targetEntityId: 'spl_tiempo_cero', justification: 'Manipulación temporal prohibida por el canon del santuario.', createdAt: '2026-09-12T13:00:00Z' },
            { id: 142, actorAlias: 'HeiterElSabio', actorRole: 'master', actionType: 'PROMOTE_MASTER', targetEntityType: 'user', targetEntityId: 'usr_eisen', justification: 'Tres firmas rigurosas consecutivas.', createdAt: '2026-09-11T10:00:00Z' },
          ],
          pagination: { page: 1, limit: 25, totalItems: 4, totalPages: 2 },
        },
      };
    },
  };
}

console.log('== VERIFICACION TAREA 5.1 (SPEC-03): auditLogView.js ==\n');

// --- FASE 0: Existencia y superficie ---
console.log('FASE 0: Superficie de la vista');

const mount0 = createFakeElement('main');
const client0 = buildAuditClientStub();
const view0 = createAuditLogView(mount0, { auditClient: client0, elementFactory: (tag) => createFakeElement(tag) });

assertCondition(typeof view0.render === 'function', 'render() está disponible');
assertCondition(typeof view0.destroy === 'function', 'destroy() está disponible');

// --- FASE 1: CRITERIO — consulta pública con tabla paginada ---
console.log('\nFASE 1: Criterio Hecho cuando — bitácora pública paginada');

const mount1 = createFakeElement('main');
const client1 = buildAuditClientStub();
const view1 = createAuditLogView(mount1, { auditClient: client1, elementFactory: (tag) => createFakeElement(tag) });
await view1.render();

assertCondition(client1.calls.length === 1, 'La vista consume fetchAuditLog al montarse (Tarea 4.1)');
assertCondition(client1.calls[0]?.page === 1 && client1.calls[0]?.limit === 25, 'La petición pide la primera página con el límite del plan (25)');

// Alias de los maestros visibles (identidades de los actuantes).
assertCondition(findByText(mount1, 'HeiterElSabio').length > 0, 'La identidad del maestro actuante es visible (RF-08.2)');
assertCondition(findByText(mount1, 'SupremoArquitecto').length > 0, 'La identidad del admin actuante es visible');

// Acciones y objetivos por fila.
assertCondition(findByText(mount1, 'SIGN_VALIDATE').length > 0, 'Las acciones canónicas se muestran (SIGN_VALIDATE)');
assertCondition(findByText(mount1, 'ADMIN_VETO').length > 0, 'Los vetos del Admin Supremo se muestran (ADMIN_VETO)');
assertCondition(findByText(mount1, 'spl_llamas_frieren').length > 0, 'El objetivo de cada veredicto se muestra (targetEntityId)');

// CRITERIO: las justificaciones íntegras son legibles.
assertCondition(
  findByText(mount1, 'Composición matemática de maná equilibrada').length > 0,
  'La justificación de la validación es legible (Art. III)',
);
assertCondition(
  findByText(mount1, 'Manipulación temporal prohibida').length > 0,
  'El motivo del veto es legible (Art. III)',
);

// Marcas temporales de cada veredicto.
assertCondition(findByText(mount1, '2026-09-12T14:22:10Z').length > 0, 'La estampa temporal UTC de cada acción es visible');

// Paginación: los metadatos del plan se reflejan en la interfaz.
assertCondition(
  findByText(mount1, '2').length > 0 || allByClass(mount1, 'audit-log__pagination').length > 0,
  'La paginación se refleja en la interfaz (totalPages del Endpoint 6)',
);
const nextButton1 = byId(mount1, 'auditNextPage');
assertCondition(nextButton1 !== null, 'El control de página siguiente existe');

// --- FASE 2: CRITERIO — filtro por clan (sellos de linaje) ---
console.log('\nFASE 2: Criterio Hecho cuando — filtro por clan');

const mount2 = createFakeElement('main');
const client2 = buildAuditClientStub();
const view2 = createAuditLogView(mount2, { auditClient: client2, elementFactory: (tag) => createFakeElement(tag) });
await view2.render();

const clanFilter2 = byId(mount2, 'auditClanFilter');
assertCondition(clanFilter2 !== null, 'La vista ofrece el filtro de clan (auditClanFilter)');

// El orquestador/usuario electa el linaje y dispara la consulta filtrada.
client2.calls.length = 0;
await view2.setClanFilter('cln_astral');

assertCondition(client2.calls.some((call) => call.clanId === 'cln_astral'), 'El filtro de clan viaja en la petición (clanId, RF-08.2)');
assertCondition(findByText(mount2, 'CLAN_MODIFY').length > 0, 'El sello del linaje (veredicto de clan) se muestra filtrado');
assertCondition(findByText(mount2, 'Motto actualizado por decisión del consejo').length > 0, 'El motivo solemne del veredicto de clan es legible');

// Volver al filtro vacío restaura la bitácora completa.
client2.calls.length = 0;
await view2.setClanFilter(null);
assertCondition(!client2.calls.some((call) => 'clanId' in call && call.clanId !== null), 'Sin clan electo, la consulta no porta filtro');

// --- FASE 3: Publicidad e imparcialidad (anónimo = consagrado) ---
console.log('\nFASE 3: Consulta sin credenciales (publicidad, Art. III)');

const mount3 = createFakeElement('main');
const client3 = buildAuditClientStub();
const view3 = createAuditLogView(mount3, { auditClient: client3, elementFactory: (tag) => createFakeElement(tag) });
await view3.render();

// La vista no consulta el estado de sesión ni exige credenciales: su única
// dependencia es el cliente público fetchAuditLog (RF-08.2, Art. III).
assertCondition(
  client3.calls.length === 1 && client3.calls[0]?.page === 1,
  'La bitácora se consulta sin credenciales ni condiciones de sesión (acceso público)',
);

// --- FASE 4: Error controlado y ciclo de vida ---
console.log('\nFASE 4: Fallo de red controlado y destroy()');

const mount4 = createFakeElement('main');
const failingClient = {
  fetchAuditLog: async () => ({
    success: false,
    status: 0,
    error: { code: 'MANA_STREAM_INTERRUPTED', message: 'La corriente de maná se ha interrumpido.', recoveryAction: 'RETRY' },
  }),
};
const view4 = createAuditLogView(mount4, { auditClient: failingClient, elementFactory: (tag) => createFakeElement(tag) });
await view4.render();

assertCondition(
  findByText(mount4, 'La corriente de maná se ha interrumpido.').length > 0,
  'Un fallo de red se muestra como error controlado (sin explosión)',
);
assertCondition(byId(mount4, 'auditRetryButton') !== null, 'La vista ofrece reintento (recoveryAction RETRY)');

view1.destroy();
assertCondition(mount1.children.length === 0, 'destroy() deja el punto de montaje limpio');

// --- Centinela y resumen ---
console.log('\n== CENTINELA DE CONSOLA ==');
assertCondition(uncaughtErrors === 0, `Excepciones no capturadas en consola: ${uncaughtErrors} (exige 0)`);

console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log("\nRESULTADO: EXITO — La Tarea 5.1 cumple su criterio 'Hecho cuando'.");
  process.exit(0);
}

console.log('\nRESULTADO: FALLO — Corregir los asertos marcados con [FALLA].');
process.exit(1);
