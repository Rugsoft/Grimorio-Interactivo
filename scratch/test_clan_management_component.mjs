/**
 * test_clan_management_component.mjs — Arnés de la Tarea 6.2 (TASKS-07).
 *
 * Verifica sobre `public/assets/js/components/clanManagementComponent.js` el
 * criterio «Hecho cuando»:
 *   «El Patriarca puede alternar el régimen de admisión, admitir una solicitud
 *    actualizando el cupo a la vista y transferir el liderazgo con diálogo
 *    solemne de confirmación.»
 *
 * Fases:
 *   [1]  Superficie del módulo y Dogma Vanilla.
 *   [2]  El panel es exclusivo de la corona y de las casas vivas (RF-01.3).
 *   [3]  Cupo a la vista: x/30 con su barra y su proclama (RF-01.4).
 *   [4]  Régimen de admisión conmutable (RF-01.5).
 *   [5]  Muda heráldica: lema y blasón, con defensas previas (RF-01.3).
 *   [6]  Cola de solicitudes: deliberar engrosa o rechaza (RF-01.5).
 *   [7]  Cupo colmado: la autoridad es el santuario (RF-01.4).
 *   [8]  Expulsión con diálogo solemne (RF-01.3, RF-01.6).
 *   [9]  Cesión de la corona con diálogo solemne (RF-01.3, RF-01.9).
 *   [10] Concurrencia, degradación, XSS y ciclo de vida.
 *
 * Constitución:
 *   - Artículo I: cero librerías; DOM simulado propio.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 */

import { readFile } from 'node:fs/promises';
import {
  createClanManagementComponent,
  MEMBER_LIMIT,
  ADMISSION_OPEN,
  ADMISSION_BY_APPLICATION,
  DECISION_APPROVE,
  DECISION_REJECT,
  MOTTO_MAX_LENGTH,
  COAT_OF_ARMS_MAX_LENGTH,
  ADMISSION_MODE_LABELS,
  MANAGEMENT_TITLE,
  CROWN_RESERVED_NOTICE,
  QUOTA_FULL_NOTICE,
  ARCHIVED_NOTICE,
  PANEL_LEGENDS,
} from '../public/assets/js/components/clanManagementComponent.js';

let assertsPassed = 0;
let assertsFailed = 0;

/** Aserta una condición y registra el resultado. */
function assertCondition(condition, description) {
  if (condition) {
    assertsPassed += 1;
    console.log(`  [PASA] ${description}`);
  } else {
    assertsFailed += 1;
    console.log(`  [FALLA] ${description}`);
  }
}

/** Elemento DOM mínimo simulado (innerHTML PROHIBIDO: su accesor lanza). */
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    value: '',
    focusCount: 0,
    _textContent: '',
    parentElement: null,
    setAttribute(name, value) { this.attributes[name] = String(value); },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    removeAttribute(name) { delete this.attributes[name]; },
    hasAttribute(name) { return name in this.attributes; },
    addEventListener(eventName, listener) { (this.listeners[eventName] ??= []).push(listener); },
    removeEventListener(eventName, listener) {
      this.listeners[eventName] = (this.listeners[eventName] ?? []).filter((l) => l !== listener);
    },
    dispatch(eventName) {
      const event = {
        type: eventName,
        defaultPrevented: false,
        currentTarget: this,
        target: this,
        preventDefault() { this.defaultPrevented = true; },
        stopPropagation() {},
      };
      for (const listener of [...(this.listeners[eventName] ?? [])]) listener(event);
      return event;
    },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    remove() {
      if (!this.parentElement) return;
      const index = this.parentElement.children.indexOf(this);
      if (index >= 0) this.parentElement.children.splice(index, 1);
      this.parentElement = null;
    },
    set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)); },
    get className() { return [...this.classes].join(' '); },
    get textContent() {
      return `${this._textContent}${this.children.map((child) => child.textContent).join('')}`;
    },
    set textContent(value) {
      for (const child of this.children.splice(0)) child.parentElement = null;
      this._textContent = String(value);
    },
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1: XSS); usar textContent'); },
    set innerHTML(value) { throw new Error(`PROHIBIDO innerHTML (AGENTS.md 6.1): se intentó escribir '${String(value).slice(0, 40)}'`); },
    focus() { this.focusCount += 1; },
    querySelector() { return null; },
  };
  element.classList = {
    _owner: element,
    add(...names) { names.forEach((n) => this._owner.classes.add(n)); },
    remove(...names) { names.forEach((n) => this._owner.classes.delete(n)); },
    contains(name) { return this._owner.classes.has(name); },
  };
  return element;
}

const fakeElementFactory = (tagName) => createFakeElement(tagName);

/** Barrido recursivo por clase sobre el DOM simulado. */
function queryByClass(node, className, found = []) {
  for (const child of node.children) {
    if (child.classes.has(className)) found.push(child);
    queryByClass(child, className, found);
  }
  return found;
}
const byClass = (root, className) => queryByClass(root, className)[0] ?? null;
const allByClass = (root, className) => queryByClass(root, className);

/** Barrido recursivo por atributo. */
function queryByAttribute(node, attribute, found = []) {
  for (const child of node.children) {
    if (child.getAttribute(attribute) !== null) found.push(child);
    queryByAttribute(child, attribute, found);
  }
  return found;
}
const allByAttribute = (root, attribute) => queryByAttribute(root, attribute);

/** Búsqueda recursiva por id. */
function findById(root, elementId) {
  for (const child of root.children) {
    if (child.getAttribute('id') === elementId) return child;
    const found = findById(child, elementId);
    if (found) return found;
  }
  return null;
}

/** Awaits pendientes: deja correr las promesas del componente. */
const settle = async (rounds = 6) => {
  for (let i = 0; i < rounds; i += 1) await Promise.resolve();
  await new Promise((resolve) => setTimeout(resolve, 0));
};

const VIEWER = Object.freeze({ id: 'usr_fundador', alias: 'Frieren', role: 'editor' });

/** ClanDto del contrato del backend (Endpoint 3). */
function buildClan(overrides = {}) {
  return {
    id: 'cln_ember',
    slug: 'custodios-de-la-llama',
    name: 'Custodios de la Llama',
    motto: 'En la ceniza renace la llama inmortal',
    coatOfArms: 'rune-ignis',
    lineageType: 'primordialFlame',
    admissionMode: ADMISSION_BY_APPLICATION,
    status: 'active',
    patriarchId: 'usr_fundador',
    weeklyPoints: 170,
    historicalPoints: 620,
    memberCount: 3,
    memberLimit: MEMBER_LIMIT,
    lastActivityAt: '2026-09-10T10:00:00Z',
    createdAt: '2026-06-01T00:00:00Z',
    updatedAt: '2026-09-10T10:00:00Z',
    ...overrides,
  };
}

/** ClanMemberDto del contrato del backend. */
function buildMember(userId, userAlias, role, overrides = {}) {
  return {
    id: `mem_${userId}`,
    clanId: 'cln_ember',
    userId,
    userAlias,
    role,
    joinedAt: '2026-06-02T10:00:00Z',
    leftAt: null,
    convalescenceExpiresAt: null,
    isActive: true,
    ...overrides,
  };
}

const FOUNDING_MEMBERS = [
  buildMember('usr_fundador', 'Frieren', 'patriarch', { joinedAt: '2026-06-01T00:00:00Z' }),
  buildMember('usr_heiter', 'Heiter', 'adept'),
  buildMember('usr_fern', 'Fern', 'adept'),
];

/** ClanApplicationDto del contrato del backend. */
function buildApplication(id, userId, userAlias, overrides = {}) {
  return {
    id,
    clanId: 'cln_ember',
    clanName: 'Custodios de la Llama',
    userId,
    userAlias,
    status: 'pending',
    createdAt: '2026-09-01T10:00:00Z',
    resolvedAt: null,
    isPending: true,
    ...overrides,
  };
}

/**
 * Doble del cliente del gobierno (Tarea 5.1): registra las órdenes cursadas y
 * responde con sobres controlados, sin jamás lanzar.
 */
function createFakeClanClient(responders = {}) {
  const calls = [];
  const answer = (method, fallback, ...args) => {
    const responder = responders[method];
    const envelope = typeof responder === 'function' ? responder(...args) : fallback;

    return Promise.resolve(envelope);
  };

  return {
    calls,
    updateClan(clanId, payload) {
      calls.push({ method: 'updateClan', clanId, payload });

      return answer('updateClan', { success: true, status: 200, data: buildClan(payload) }, clanId, payload);
    },
    resolveApplication(clanId, applicationId, decision) {
      calls.push({ method: 'resolveApplication', clanId, applicationId, decision });

      return answer(
        'resolveApplication',
        {
          success: true,
          status: 200,
          data: { mode: decision === DECISION_APPROVE ? 'active' : 'rejected', application: null, membership: null },
        },
        clanId,
        applicationId,
        decision,
      );
    },
    expelMember(clanId, userId) {
      calls.push({ method: 'expelMember', clanId, userId });

      return answer('expelMember', { success: true, status: 200, data: { userId } }, clanId, userId);
    },
    transferLeadership(clanId, newPatriarchId) {
      calls.push({ method: 'transferLeadership', clanId, newPatriarchId });

      return answer('transferLeadership', { success: true, status: 200, data: { newPatriarchId } }, clanId, newPatriarchId);
    },
  };
}

/** Monta el panel con un sobre dado y devuelve sus piezas. */
function mountPanel({ clan = buildClan(), members = FOUNDING_MEMBERS, applications = [], viewer = VIEWER, client = null, options = {} } = {}) {
  const root = createFakeElement('div');
  const component = createClanManagementComponent(root, {
    clanClient: client,
    viewer,
    elementFactory: fakeElementFactory,
    ...options,
  });

  return { root, component, client, context: { clan, members, applications, viewer } };
}

const componentSource = await readFile(
  new URL('../public/assets/js/components/clanManagementComponent.js', import.meta.url),
  'utf8',
);

console.log('\n== ARNÉS — PANEL DE GOBIERNO DEL PATRIARCA (Tarea 6.2, SPEC-07) ==\n');

// =====================================================================
// FASE 1 — Superficie y Dogma Vanilla
// =====================================================================
console.log('[FASE 1] Superficie del módulo y Dogma Vanilla (Artículo I)');

assertCondition(typeof createClanManagementComponent === 'function', 'El módulo exporta la fábrica del panel');
assertCondition(MEMBER_LIMIT === 30, 'El cupo espejo del canon son treinta adeptos (RF-01.4)');
assertCondition(MANAGEMENT_TITLE === 'Gobierno de la Hermandad', 'El panel declara su título ceremonial');
assertCondition(
  ADMISSION_OPEN === 'open' && ADMISSION_BY_APPLICATION === 'byApplication',
  'Los dos regímenes canónicos se declaran con su clave técnica (RF-01.5)',
);
assertCondition(
  (componentSource.match(/^\s*import\s/gm) ?? []).length === 1
  && componentSource.includes("from '../api/clanClient.js'"),
  'El panel solo importa la leyenda ceremonial del cliente: ninguna librería externa',
);
assertCondition(!/\bfetch\s*\(/.test(componentSource), 'El panel no llama a la API por su cuenta: cursa órdenes por el cliente inyectado');
assertCondition(!/\.innerHTML\s*=/.test(componentSource), 'Cero asignaciones a innerHTML (AGENTS.md 6.1): el DOM se forja con textContent');
assertCondition(!/(setInterval|setTimeout)\s*\(/.test(componentSource), 'Sin temporizadores ni sondeo (RNF-02)');

// =====================================================================
// FASE 2 — El panel es exclusivo de la corona
// =====================================================================
console.log('\n[FASE 2] Exclusividad de la corona y de las casas vivas (RF-01.3)');

const emptyMount = mountPanel({ client: createFakeClanClient() });
assertCondition(emptyMount.component.render() === false, 'Sin sobre de casa el panel no se despliega');
assertCondition(emptyMount.root.children.length === 0, 'Y no forja un solo nodo (degradación elegante)');

const outsiderMount = mountPanel({
  client: createFakeClanClient(),
  viewer: { id: 'usr_heiter', alias: 'Heiter', role: 'editor' },
});
assertCondition(outsiderMount.component.render(outsiderMount.context) === false, 'Quien no ciñe la corona no obtiene panel de gobierno');
const outsiderSealed = byClass(outsiderMount.root, 'clan-management__sealed');
assertCondition(outsiderSealed?.textContent === CROWN_RESERVED_NOTICE, 'El forastero lee la proclama de reserva de la corona');
assertCondition(outsiderSealed?.getAttribute('role') === 'status', 'La proclama es un estado anunciado');
assertCondition(
  allByAttribute(outsiderMount.root, 'data-action').length === 0
  && allByAttribute(outsiderMount.root, 'data-decision').length === 0,
  'Ni un solo control de gobierno se forja para el forastero',
);
assertCondition(
  allByAttribute(outsiderMount.root, 'data-mode').length === 0,
  'Tampoco el conmutador de régimen: el panel no es un escaparate para ajenos',
);

const archivedMount = mountPanel({
  client: createFakeClanClient(),
  clan: buildClan({ status: 'archived' }),
});
assertCondition(archivedMount.component.render(archivedMount.context) === false, 'Una casa disuelta no admite gobierno');
assertCondition(
  byClass(archivedMount.root, 'clan-management__sealed')?.textContent === ARCHIVED_NOTICE,
  'El panel proclama la Herencia Ancestral de la casa disuelta',
);
assertCondition(allByAttribute(archivedMount.root, 'data-action').length === 0, 'La casa disuelta no ofrece control de gobierno alguno');

const crownedMount = mountPanel({ client: createFakeClanClient() });
assertCondition(crownedMount.component.render(crownedMount.context) === true, 'El Patriarca obtiene su panel de gobierno');
assertCondition(crownedMount.component.isCrowned() === true, 'isCrowned() confirma la corona del vinculado');
assertCondition(
  findById(crownedMount.root, 'clanManagement')?.getAttribute('role') === 'region',
  'El panel es una región ARIA etiquetada (RNF-03)',
);
assertCondition(
  crownedMount.root.textContent.includes('Custodios de la Llama'),
  'El panel nombra la casa que gobierna',
);

// =====================================================================
// FASE 3 — Cupo a la vista
// =====================================================================
console.log('\n[FASE 3] Cupo a la vista: x/30 con su barra y su proclama (RF-01.4)');

const tenureMount = mountPanel({ client: createFakeClanClient() });
tenureMount.component.render(tenureMount.context);
const quotaLegend = byClass(tenureMount.root, 'clan-management__quota');
assertCondition(quotaLegend?.textContent === '3 / 30 adeptos', 'El censo se anuncia «3 / 30 adeptos»');
assertCondition(quotaLegend?.getAttribute('data-member-count') === '3', 'El censo publica el recuento vivo');
assertCondition(quotaLegend?.getAttribute('data-member-limit') === '30', 'El censo publica el cupo canónico');

const quotaBar = byClass(tenureMount.root, 'clan-management__quota-bar');
assertCondition(quotaBar?.getAttribute('role') === 'progressbar', 'La ocupación es una barra accesible');
assertCondition(quotaBar?.getAttribute('aria-valuenow') === '3', 'La barra declara el valor vivo');
assertCondition(quotaBar?.getAttribute('aria-valuemax') === '30', 'La barra declara el techo de treinta');
assertCondition(
  quotaBar?.getAttribute('style') === '--quota-fill: 10%',
  'La barra viste su medida por Custom Property (3/30 = 10%)',
);

const fullMount = mountPanel({
  client: createFakeClanClient(),
  clan: buildClan({ memberCount: MEMBER_LIMIT }),
  members: Array.from({ length: MEMBER_LIMIT }, (unused, index) => buildMember(
    index === 0 ? 'usr_fundador' : `usr_adepto_${index}`,
    index === 0 ? 'Frieren' : `Adepto ${index}`,
    index === 0 ? 'patriarch' : 'adept',
  )),
});
fullMount.component.render(fullMount.context);
const fullNotice = byClass(fullMount.root, 'clan-management__quota-full');
assertCondition(fullNotice?.textContent === QUOTA_FULL_NOTICE, 'La casa colmada proclama su plenitud');
assertCondition(fullNotice?.getAttribute('data-quota-state') === 'full', 'La plenitud se marca como estado del censo');
assertCondition(
  byClass(fullMount.root, 'clan-management__quota-bar')?.getAttribute('aria-valuenow') === '30',
  'La barra del censo colmado llega a treinta',
);

// =====================================================================
// FASE 4 — Régimen de admisión
// =====================================================================
console.log('\n[FASE 4] Régimen de admisión conmutable (RF-01.5)');

const regimeClient = createFakeClanClient();
const regimeMount = mountPanel({ client: regimeClient });
regimeMount.component.render(regimeMount.context);

const regimeOptions = allByAttribute(regimeMount.root, 'data-mode');
assertCondition(regimeOptions.length === 2, 'El conmutador ofrece los dos regímenes canónicos');
const byApplicationOption = regimeOptions.find((option) => option.getAttribute('data-mode') === ADMISSION_BY_APPLICATION);
const openOption = regimeOptions.find((option) => option.getAttribute('data-mode') === ADMISSION_OPEN);
assertCondition(byApplicationOption?.getAttribute('aria-pressed') === 'true', 'El régimen vigente («bajo petición») se anuncia pulsado');
assertCondition(openOption?.getAttribute('aria-pressed') === 'false', 'El régimen ajeno no se anuncia pulsado');
assertCondition(
  byClass(regimeMount.root, 'clan-management__admission-legend')?.textContent === ADMISSION_MODE_LABELS[ADMISSION_BY_APPLICATION],
  'La leyenda del régimen vigente se declara por escrito',
);

byApplicationOption.dispatch('click');
await settle();
assertCondition(regimeClient.calls.length === 0, 'Pulsar el régimen YA vigente no cursa orden alguna (idempotencia)');

openOption.dispatch('click');
assertCondition(regimeClient.calls.length === 1, 'Pulsar el otro régimen cursa una única orden');
assertCondition(regimeClient.calls[0].method === 'updateClan', 'La orden viaja por el Endpoint 4 (muda de hermandad)');
assertCondition(regimeClient.calls[0].clanId === 'cln_ember', 'La orden apunta a la casa gobernada');
assertCondition(
  Object.keys(regimeClient.calls[0].payload).length === 1 && regimeClient.calls[0].payload.admissionMode === ADMISSION_OPEN,
  'La orden muda SOLO el régimen: el PATCH no reescribe la casa entera',
);
await settle();
assertCondition(
  allByAttribute(regimeMount.root, 'data-mode').find((o) => o.getAttribute('data-mode') === ADMISSION_OPEN)?.getAttribute('aria-pressed') === 'true',
  'Tras la muda, el régimen abierto queda anunciado como vigente',
);
assertCondition(
  byClass(regimeMount.root, 'clan-management__admission-legend')?.textContent === ADMISSION_MODE_LABELS[ADMISSION_OPEN],
  'La leyenda acompaña al nuevo régimen',
);
assertCondition(
  byClass(regimeMount.root, 'clan-management__status')?.textContent.includes('mudado'),
  'El panel proclama la muda del régimen',
);

// =====================================================================
// FASE 5 — Muda heráldica
// =====================================================================
console.log('\n[FASE 5] Muda heráldica: lema y blasón (RF-01.3)');

const heraldryClient = createFakeClanClient();
const heraldryMount = mountPanel({ client: heraldryClient });
heraldryMount.component.render(heraldryMount.context);

const mottoInput = findById(heraldryMount.root, 'clanMottoInput');
const coatInput = findById(heraldryMount.root, 'clanCoatInput');
const heraldryForm = byClass(heraldryMount.root, 'clan-management__heraldry-form');
assertCondition(mottoInput?.value === 'En la ceniza renace la llama inmortal', 'El formulario llega con el lema vigente');
assertCondition(coatInput?.value === 'rune-ignis', 'El formulario llega con el blasón vigente');
assertCondition(mottoInput?.getAttribute('maxlength') === String(MOTTO_MAX_LENGTH), 'El lema acota su extensión al canon del esquema');
assertCondition(coatInput?.getAttribute('maxlength') === String(COAT_OF_ARMS_MAX_LENGTH), 'El blasón acota su extensión al canon del esquema');

mottoInput.value = '';
heraldryForm.dispatch('submit');
await settle();
assertCondition(heraldryClient.calls.length === 0, 'Un lema en blanco no gasta corriente de maná: la defensa es previa');
assertCondition(
  byClass(heraldryMount.root, 'clan-management__alert')?.textContent === PANEL_LEGENDS.mottoRequired,
  'Y la alerta explica el porqué sin salir del panel',
);

mottoInput.value = 'x'.repeat(MOTTO_MAX_LENGTH + 1);
heraldryForm.dispatch('submit');
await settle();
assertCondition(heraldryClient.calls.length === 0, 'Un lema fuera de extensión tampoco cursa orden');

mottoInput.value = 'Herederos del fulgor que nunca muere';
coatInput.value = '';
heraldryForm.dispatch('submit');
await settle();
assertCondition(heraldryClient.calls.length === 0, 'Un blasón en blanco tampoco cursa orden');
assertCondition(
  byClass(heraldryMount.root, 'clan-management__alert')?.textContent === PANEL_LEGENDS.coatRequired,
  'La alerta del blasón en blanco es la prevista',
);

coatInput.value = 'rune_solar_crest';
heraldryForm.dispatch('submit');
await settle();
assertCondition(heraldryClient.calls.length === 1, 'La heráldica válida cursa su orden');
assertCondition(
  heraldryClient.calls[0].payload.motto === 'Herederos del fulgor que nunca muere'
  && heraldryClient.calls[0].payload.coatOfArms === 'rune_solar_crest',
  'La orden porta el lema y el blasón mudados',
);
assertCondition(
  byClass(heraldryMount.root, 'clan-management__status')?.textContent.includes('heráldica'),
  'El panel proclama la muda heráldica',
);
assertCondition(
  findById(heraldryMount.root, 'clanMottoInput')?.value === 'Herederos del fulgor que nunca muere',
  'El formulario repintado exhibe ya el lema nuevo',
);

const vetoClient = createFakeClanClient({
  updateClan: () => ({
    success: false,
    status: 403,
    error: { code: 'NOT_PATRIARCH', message: 'Solo quien ciñe la corona puede gobernar esta hermandad.', recoveryAction: 'NONE' },
  }),
});
const vetoMount = mountPanel({ client: vetoClient });
vetoMount.component.render(vetoMount.context);
findById(vetoMount.root, 'clanMottoInput').value = 'Otro lema';
byClass(vetoMount.root, 'clan-management__heraldry-form').dispatch('submit');
await settle(10);
assertCondition(
  byClass(vetoMount.root, 'clan-management__alert')?.textContent === 'Solo quien ciñe la corona puede gobernar esta hermandad.',
  'El veredicto del santuario se exhibe con su propia leyenda (fuente única de verdad)',
);
assertCondition(byClass(vetoMount.root, 'clan-management__alert')?.getAttribute('role') === 'alert', 'El fallo es una alerta accesible');

// =====================================================================
// FASE 6 — Cola de solicitudes
// =====================================================================
console.log('\n[FASE 6] Cola de solicitudes: deliberar engrosa o rechaza (RF-01.5)');

const emptyJournalMount = mountPanel({ client: createFakeClanClient() });
emptyJournalMount.component.render(emptyJournalMount.context);
assertCondition(
  byClass(emptyJournalMount.root, 'clan-management__journal-empty')?.textContent === PANEL_LEGENDS.journalEmpty,
  'Sin postulaciones, el panel lo dice en vez de mostrar una lista vacía',
);

const journalClient = createFakeClanClient({
  resolveApplication: (clanId, applicationId, decision) => ({
    success: true,
    status: 200,
    data: {
      mode: decision === DECISION_APPROVE ? 'active' : 'rejected',
      application: null,
      membership: decision === DECISION_APPROVE ? buildMember('usr_stark', 'Stark', 'adept') : null,
    },
  }),
});
const journalMount = mountPanel({
  client: journalClient,
  applications: [buildApplication('app_uno', 'usr_stark', 'Stark')],
});
journalMount.component.render(journalMount.context);

const applicationItems = allByClass(journalMount.root, 'clan-management__application');
assertCondition(applicationItems.length === 1, 'La cola exhibe la postulación pendiente');
assertCondition(applicationItems[0]?.getAttribute('data-application-id') === 'app_uno', 'Cada postulación porta su sello (app_*)');
assertCondition(
  byClass(journalMount.root, 'clan-management__applicant')?.textContent === 'Stark',
  'La cola nombra al postulante',
);
assertCondition(
  allByAttribute(journalMount.root, 'data-decision').length === 2,
  'Cada postulación ofrece aceptar y rechazar',
);

const approveButton = allByAttribute(journalMount.root, 'data-decision').find((b) => b.getAttribute('data-decision') === DECISION_APPROVE);
approveButton.dispatch('click');
await settle();
assertCondition(journalClient.calls.length === 1, 'Aceptar el juramento cursa una orden');
assertCondition(
  journalClient.calls[0].method === 'resolveApplication'
  && journalClient.calls[0].applicationId === 'app_uno'
  && journalClient.calls[0].decision === DECISION_APPROVE,
  'La orden delibera el veredicto `approve` sobre el sello correcto (Endpoint 6)',
);
assertCondition(
  byClass(journalMount.root, 'clan-management__quota')?.getAttribute('data-member-count') === '4',
  'CRITERIO: al admitir, el cupo a la vista sube a 4 adeptos',
);
assertCondition(
  allByClass(journalMount.root, 'clan-management__member').length === 4,
  'Y el censo nominal exhibe al nuevo hermano',
);
assertCondition(
  allByClass(journalMount.root, 'clan-management__application').length === 0,
  'La postulación deliberada abandona la cola',
);
assertCondition(
  byClass(journalMount.root, 'clan-management__status')?.textContent.includes('Stark'),
  'El panel proclama el juramento aceptado',
);

const rejectClient = createFakeClanClient();
const rejectMount = mountPanel({
  client: rejectClient,
  applications: [buildApplication('app_dos', 'usr_serie', 'Serie')],
});
rejectMount.component.render(rejectMount.context);
allByAttribute(rejectMount.root, 'data-decision')
  .find((b) => b.getAttribute('data-decision') === DECISION_REJECT)
  .dispatch('click');
await settle();
assertCondition(rejectClient.calls[0]?.decision === DECISION_REJECT, 'Rechazar cursa el veredicto `reject`');
assertCondition(
  byClass(rejectMount.root, 'clan-management__quota')?.getAttribute('data-member-count') === '3',
  'El rechazo NO engrosa el censo',
);
assertCondition(
  allByClass(rejectMount.root, 'clan-management__application').length === 0,
  'La solicitud rechazada también abandona la cola',
);
assertCondition(
  byClass(rejectMount.root, 'clan-management__status')?.textContent.includes('rechazada'),
  'El panel proclama el rechazo',
);

// =====================================================================
// FASE 7 — Cupo colmado
// =====================================================================
console.log('\n[FASE 7] Cupo colmado: la autoridad es el santuario (RF-01.4)');

const fullMembers = Array.from({ length: MEMBER_LIMIT }, (unused, index) => buildMember(
  index === 0 ? 'usr_fundador' : `usr_adepto_${index}`,
  index === 0 ? 'Frieren' : `Adepto ${index}`,
  index === 0 ? 'patriarch' : 'adept',
));
const saturatedClient = createFakeClanClient({
  resolveApplication: () => ({
    success: false,
    status: 409,
    error: {
      code: 'CLAN_QUOTA_EXCEEDED',
      message: 'La hermandad ha alcanzado su plenitud de treinta hermanos.',
      recoveryAction: 'NONE',
    },
  }),
});
const saturatedMount = mountPanel({
  client: saturatedClient,
  clan: buildClan({ memberCount: MEMBER_LIMIT }),
  members: fullMembers,
  applications: [buildApplication('app_tres', 'usr_desafiante', 'Desafiante')],
});
saturatedMount.component.render(saturatedMount.context);
allByAttribute(saturatedMount.root, 'data-decision')
  .find((b) => b.getAttribute('data-decision') === DECISION_APPROVE)
  .dispatch('click');
await settle(10);
assertCondition(
  byClass(saturatedMount.root, 'clan-management__alert')?.textContent === 'La hermandad ha alcanzado su plenitud de treinta hermanos.',
  'El 409 del santuario habla con su propia leyenda',
);
assertCondition(
  allByClass(saturatedMount.root, 'clan-management__application').length === 1,
  'La postulación rechazada por plenitud PERMANECE en la cola',
);
assertCondition(
  byClass(saturatedMount.root, 'clan-management__quota')?.getAttribute('data-member-count') === '30',
  'El censo no se altera: la casa sigue en treinta',
);
assertCondition(
  byClass(saturatedMount.root, 'clan-management__quota-full')?.textContent === QUOTA_FULL_NOTICE,
  'Y la proclama de plenitud sigue en pie',
);

// =====================================================================
// FASE 8 — Expulsión con diálogo solemne
// =====================================================================
console.log('\n[FASE 8] Expulsión con diálogo solemne (RF-01.3, RF-01.6)');

const expelClient = createFakeClanClient();
const expelMount = mountPanel({ client: expelClient });
expelMount.component.render(expelMount.context);

const patriarchRow = allByClass(expelMount.root, 'clan-management__member')
  .find((row) => row.getAttribute('data-member-role') === 'patriarch');
assertCondition(
  byClass(patriarchRow, 'clan-management__crown')?.getAttribute('aria-label') === 'Corona de Patriarca',
  'La fila del Patriarca exhibe su corona',
);
assertCondition(
  byAttributeWithin(patriarchRow, 'data-action', 'expelMember') === null,
  'Al Patriarca NO se le ofrece expulsarse a sí mismo (Artículo III)',
);

/** Busca un descendiente por atributo/valor dentro de un nodo (sin querySelector). */
function byAttributeWithin(root, attribute, value) {
  for (const child of root?.children ?? []) {
    if (child.getAttribute(attribute) === value) return child;
    const found = byAttributeWithin(child, attribute, value);
    if (found) return found;
  }
  return null;
}

const expelButton = allByAttribute(expelMount.root, 'data-action').find((b) => b.getAttribute('data-action') === 'expelMember');
assertCondition(expelButton !== undefined, 'A los adeptos sí se les ofrece la expulsión');
assertCondition(expelButton?.getAttribute('data-member-id') === 'usr_heiter', 'El control porta el identificador del adepto');

expelButton.dispatch('click');
await settle();
assertCondition(expelClient.calls.length === 0, 'La expulsión NO se cursa antes de la confirmación solemne');
const confirmation = byClass(expelMount.root, 'clan-management__confirmation');
assertCondition(confirmation?.getAttribute('role') === 'alertdialog', 'El diálogo es un `alertdialog` (RNF-03)');
assertCondition(confirmation?.getAttribute('aria-modal') === 'true', 'El diálogo se declara modal');
assertCondition(
  confirmation?.getAttribute('aria-labelledby') === 'clanConfirmationTitle'
  && byClass(expelMount.root, 'clan-management__confirmation-title')?.textContent === 'Dictar Expulsión',
  'El diálogo se titula y se etiqueta a sí mismo',
);
assertCondition(
  byClass(expelMount.root, 'clan-management__confirmation-message')?.textContent.includes('Heiter')
  && byClass(expelMount.root, 'clan-management__confirmation-message')?.textContent.includes('Convalecencia'),
  'El diálogo nombra al adepto y advierte de su convalecencia',
);
assertCondition(
  byClass(expelMount.root, 'clan-management__confirmation-accept')?.focusCount === 1,
  'El foco nace en la confirmación, jamás en el desistimiento (RNF-03)',
);

byClass(expelMount.root, 'clan-management__confirmation-cancel').dispatch('click');
await settle();
assertCondition(expelClient.calls.length === 0, 'Desistir del gesto NO cursa orden alguna');
assertCondition(byClass(expelMount.root, 'clan-management__confirmation') === null, 'El diálogo se retira al desistir');

expelButton.dispatch('click');
await settle();
byClass(expelMount.root, 'clan-management__confirmation-accept').dispatch('click');
await settle(10);
assertCondition(expelClient.calls.length === 1, 'Confirmar el gesto cursa la única orden');
assertCondition(
  expelClient.calls[0].method === 'expelMember' && expelClient.calls[0].userId === 'usr_heiter',
  'La orden expulsa al adepto señalado (Endpoint 8)',
);
assertCondition(
  allByClass(expelMount.root, 'clan-management__member').length === 2,
  'El censo nominal pierde al expulsado',
);
assertCondition(
  byClass(expelMount.root, 'clan-management__quota')?.getAttribute('data-member-count') === '2',
  'El cupo a la vista desciende a 2',
);
assertCondition(
  byClass(expelMount.root, 'clan-management__status')?.textContent.includes('Heiter'),
  'El panel proclama la expulsión',
);

// =====================================================================
// FASE 9 — Cesión de la corona con diálogo solemne
// =====================================================================
console.log('\n[FASE 9] Cesión de la corona con diálogo solemne (RF-01.3, RF-01.9)');

const crownClient = createFakeClanClient();
const crownMount = mountPanel({ client: crownClient });
crownMount.component.render(crownMount.context);

const transferButton = allByAttribute(crownMount.root, 'data-action').find((b) => b.getAttribute('data-action') === 'transferLeadership');
assertCondition(transferButton?.getAttribute('data-member-id') === 'usr_heiter', 'El panel ofrece ceder la corona a un adepto concreto');

transferButton.dispatch('click');
await settle();
assertCondition(crownClient.calls.length === 0, 'La corona NO muda antes de la confirmación solemne');
assertCondition(
  byClass(crownMount.root, 'clan-management__confirmation-title')?.textContent === 'Ceder la Corona',
  'El diálogo se titula «Ceder la Corona»',
);
assertCondition(
  byClass(crownMount.root, 'clan-management__confirmation-message')?.textContent.includes('Heiter')
  && byClass(crownMount.root, 'clan-management__confirmation-message')?.textContent.includes('irrevocable'),
  'El diálogo nombra al heredero y advierte de lo irrevocable del gesto',
);
assertCondition(
  byClass(crownMount.root, 'clan-management__confirmation-accept')?.textContent === 'Ceder la corona',
  'El botón solemniza el gesto («Ceder la corona»)',
);

byClass(crownMount.root, 'clan-management__confirmation-accept').dispatch('click');
await settle(10);
assertCondition(
  crownClient.calls[0]?.method === 'transferLeadership' && crownClient.calls[0]?.newPatriarchId === 'usr_heiter',
  'La orden cede la corona al heredero designado (Endpoint 9)',
);
assertCondition(
  byClass(crownMount.root, 'clan-management__status')?.textContent.includes('Heiter'),
  'El panel proclama a quien ciñe ya la corona',
);
assertCondition(
  byClass(crownMount.root, 'clan-management__sealed')?.textContent === CROWN_RESERVED_NOTICE,
  'Cedida la corona, el panel se sella: el cedente ya no gobierna (RF-01.3)',
);
assertCondition(
  allByAttribute(crownMount.root, 'data-action').length === 0,
  'Y no le queda control de gobierno alguno',
);

const heirMount = mountPanel({
  client: createFakeClanClient(),
  clan: buildClan({ patriarchId: 'usr_heiter' }),
  viewer: { id: 'usr_heiter', alias: 'Heiter', role: 'editor' },
});
assertCondition(heirMount.component.render(heirMount.context) === true, 'El nuevo Patriarca hereda el panel completo');
assertCondition(
  allByAttribute(heirMount.root, 'data-action').some((b) => b.getAttribute('data-action') === 'transferLeadership'),
  'Y puede volver a ceder la corona: la sucesión no es un gesto único',
);

// =====================================================================
// FASE 10 — Concurrencia, degradación, XSS y ciclo de vida
// =====================================================================
console.log('\n[FASE 10] Concurrencia, degradación, XSS y ciclo de vida');

const raceClient = createFakeClanClient();
const raceMount = mountPanel({
  client: raceClient,
  applications: [buildApplication('app_uno', 'usr_stark', 'Stark')],
});
raceMount.component.render(raceMount.context);
const raceApprove = allByAttribute(raceMount.root, 'data-decision')
  .find((b) => b.getAttribute('data-decision') === DECISION_APPROVE);
raceApprove.dispatch('click');
raceApprove.dispatch('click');
await settle(10);
assertCondition(raceClient.calls.length === 1, 'Dos gestos seguidos cursan UNA sola orden (guardia de concurrencia)');

const offlineMount = mountPanel({ client: null });
assertCondition(offlineMount.component.render(offlineMount.context) === true, 'Sin cliente el panel se exhibe en modo contemplativo');
assertCondition(
  byClass(offlineMount.root, 'clan-management__alert')?.textContent === PANEL_LEGENDS.noClient,
  'Y declara por escrito que ninguna orden puede cursarse',
);
allByAttribute(offlineMount.root, 'data-mode')
  .find((b) => b.getAttribute('data-mode') === ADMISSION_OPEN)
  .dispatch('click');
await settle();
assertCondition(
  byClass(offlineMount.root, 'clan-management__alert')?.textContent === PANEL_LEGENDS.noClient,
  'Pulsar sin corriente de maná no rompe nada: solo lo advierte',
);

const errorClient = createFakeClanClient({
  updateClan: () => Promise.reject(new Error('corte de red')),
});
const errorMount = mountPanel({ client: errorClient });
errorMount.component.render(errorMount.context);
allByAttribute(errorMount.root, 'data-mode')
  .find((b) => b.getAttribute('data-mode') === ADMISSION_OPEN)
  .dispatch('click');
await settle(10);
assertCondition(
  byClass(errorMount.root, 'clan-management__alert')?.textContent === 'La corriente de maná hacia el santuario de hermandades se ha interrumpido.',
  'Un corte inesperado se traduce a su leyenda ceremonial: jamás escapa una excepción hacia la vista',
);
assertCondition(errorClient.calls.length === 1, 'Y el panel sigue en pie tras el corte',
);

const hostileAlias = '</span><script>alert("xss")</script>';
const hostileMount = mountPanel({
  client: createFakeClanClient(),
  applications: [buildApplication('app_hostil', 'usr_hostil', hostileAlias)],
  members: [
    buildMember('usr_fundador', 'Frieren', 'patriarch'),
    buildMember('usr_hostil', hostileAlias, 'adept'),
  ],
});
hostileMount.component.render(hostileMount.context);
assertCondition(
  hostileMount.root.textContent.includes(hostileAlias),
  'El alias hostil se exhibe como TEXTO literal, jamás interpretado como marcado',
);
assertCondition(
  allByClass(hostileMount.root, 'clan-management__applicant').length === 1,
  'El marcado hostil no forja nodos ajenos a la cola',
);

const lifeMount = mountPanel({ client: createFakeClanClient() });
lifeMount.component.render(lifeMount.context);
const firstPaint = lifeMount.root.children.length;
lifeMount.component.render(lifeMount.context);
lifeMount.component.render(lifeMount.context);
assertCondition(lifeMount.root.children.length === firstPaint, 'El re-render es idempotente: el panel no se duplica');
assertCondition(firstPaint === 1, 'El panel vive en un solo contenedor por montaje');

lifeMount.component.destroy();
assertCondition(lifeMount.root.children.length === 0, 'destroy() retira el panel del árbol');
assertCondition(lifeMount.component.render(lifeMount.context) === false, 'Una escritura tardía tras destroy() no pinta nada');
assertCondition(lifeMount.root.children.length === 0, 'El contenedor permanece limpio');
lifeMount.component.destroy();
assertCondition(lifeMount.root.children.length === 0, 'destroy() es idempotente');
assertCondition(lifeMount.component.getClan() === null, 'Tras destroy() no queda ficha alguna en memoria');

// =====================================================================
// Veredicto
// =====================================================================
console.log(`\n${'-'.repeat(64)}`);
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);
console.log(`Veredicto: ${assertsFailed === 0 ? 'TODO EN ORDEN — el criterio «Hecho cuando» se cumple' : 'HAY FALLOS'}`);
process.exit(assertsFailed === 0 ? 0 : 1);
