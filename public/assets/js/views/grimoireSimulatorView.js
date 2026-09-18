/**
 * grimoireSimulatorView.js — Vista principal del Simulador de Grimorio.
 *
 * Tarea 5.1 (TASKS-05): orquesta la Cámara de Conjuración como un santuario
 * único. Ensambla el Tomo Arcano, el Lienzo con sus partículas, el Maniquí de
 * entrenamiento, la Bitácora de Pruebas y los sellos ceremoniales (táctil,
 * «Escuchar Cántico» y «Micrófono de Conjuración»), comunicando a todos ellos
 * por el bus de eventos DOM desacoplado del plan 4.1.
 *
 * Eventos del bus (plan 4.1):
 *   - `grimoire:cast-spell`     → `{ spell, triggerMethod: 'click' | 'voice' }`
 *   - `grimoire:page-change`    → `{ spell, pageNumber, totalPages }`
 *   - `grimoire:spell-impact`   → `{ spell, targetCoordinates }`
 *   - `grimoire:dummy-reset`    → `{ reason: 'user' | 'regenerated' }`
 *   - `grimoire:speech-triggered` → `{ recognizedPhrase, spellMatched }`
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Modules nativos, Canvas 2D, Web Speech
 *     API y RAF; cero librerías, empaquetadores o CDNs. Los dobles del
 *     navegador se inyectan por opciones para que la vista sea falsable en
 *     arneses sin DOM.
 *   - Artículo III (Ética de linajes): la vista jamás altera estado del
 *     santuario; el banco de pruebas es un sandbox ilimitado (RF-02.2).
 *   - Artículo IV (Velo Arcano): todos los anuncios y leyendas son avisos
 *     solemnes en noble castellano.
 *   - Artículo V (Dualidad Lingüística): identificadores en inglés camelCase;
 *     documentación, rótulos y anuncios en castellano.
 *
 * Cobertura: RF-01 a RF-06, RNF-01 a RNF-05.
 */

import { createGrimoireBookComponent, ROMAN_CIRCLES } from '../components/grimoireBookComponent.js';
import { createElementalAuraComponent } from '../components/elementalAuraComponent.js';
import { createComboResolver, createSpellImpactQueue } from '../utils/comboResolver.js';
import { createStunlockManager } from '../utils/stunlockManager.js';
import { ParticlePool } from '../utils/particleEngine.js';
import { createArcaneCanvasComponent } from '../components/arcaneCanvasComponent.js';
import { createCombatDummyComponent, DUMMY_MAX_HEALTH } from '../components/combatDummyComponent.js';
import { createFloatingCombatTextComponent } from '../components/floatingCombatTextComponent.js';
import { createSpeechService } from '../utils/speechService.js';
import { createTestLogStorage } from '../utils/testLogStorage.js';

/** Modos del conmutador rúnico del catálogo (RF-01.2). */
export const CATALOG_MODES = Object.freeze({
  canonical: 'canonical',
  essays: 'essays',
});

/** Rótulos solemnes del control de masas (RF-05.2). */
export const CROWD_CONTROL_LABELS = Object.freeze({
  stun: '¡Aturdido!',
  root: '¡Enraizado!',
  slow: '¡Ralentizado!',
});

/** Clase del temblor ceremonial de la Cámara al recibir un impacto (RF-05.1). */
export const IMPACT_TREMOR_CLASS = 'grimoire-simulator__stage--tremor';

/** Duración del temblor ceremonial en milisegundos. */
export const IMPACT_TREMOR_MS = 240;

/** Conjuros solicitados por hoja del catálogo. */
export const CATALOG_PAGE_LIMIT = 10;

/**
 * Traducción de efectos tácticos del Códice (SPEC-06) a ataduras del
 * maniquí (SPEC-05): la neblina y el enraizamiento son controles blandos
 * que el banco de pruebas representa como ralentización; el Hard CC de la
 * reacción (hardStun) ata directamente (RF-05.1).
 */
const CODEX_EFFECT_TO_CC = Object.freeze({
  blindnessMist: 'slow',
  rootAndSlow: 'slow',
  hardStun: 'stun',
  freezeParalysis: 'stun', // Congelación del Códice: parálisis motora completa (2 s).
});

/** Trituración de barrera canónica de la Fractura Basáltica (RF-04.2). */
const CODEX_BARRIER_SHATTER = 50;

/**
 * Motivo canónico del recibo de dominio cuando el adepto ya colmó su techo
 * diario de práctica (SPEC-07, RF-03.2). Espejo de
 * `DominionAwardDto::REASON_DAILY_SIMULATOR_CAP_REACHED`.
 */
const SIMULATOR_CAP_REASON = 'DAILY_SIMULATOR_CAP_REACHED';

/**
 * PRNG determinista mulberry32 para las deflagraciones de combo (RNF-01):
 * la semilla fija garantiza que dos detonaciones de la misma reacción
 * con los mismos parámetros pinten idéntica coreografía de estelas.
 * @param {number} seed Semilla entera.
 * @returns {() => number} Generador en [0, 1).
 */
function createDeterministicComboRandom(seed) {
  let state = seed | 0;
  return () => {
    state = (state + 0x6d2b79f5) | 0;
    let t = Math.imul(state ^ (state >>> 15), 1 | state);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

/** Anclaje y proporciones de la Cámara de Conjuración (RF-02.1). */
const CAST_ORIGIN_RATIO = Object.freeze({ x: 0.12, y: 0.78 });
const CAST_TARGET_RATIO = Object.freeze({ x: 0.70, y: 0.50 });
const FALLBACK_CANVAS = Object.freeze({ width: 800, height: 400 });

/**
 * Crea la vista del Simulador de Grimorio.
 *
 * El tomo inicial lo fija `options.initialMode`: el orquestador abre el Tomo
 * Canónico para cualquier visitante y reserva los Ensayos Arcanos al vínculo
 * consagrado (RF-01.2). La vista, aun así, degrada sola si el santuario
 * responde 401.
 *
 * @param {HTMLElement} mountRoot Punto de montaje (`<main id="app">`).
 * @param {Object} options
 * @param {Object} options.grimoireClient Cliente HTTP del grimorio (Tarea 4.1).
 * @param {Object} [options.speechService] Servicio vocal (Tarea 2.4).
 * @param {Object} [options.testLogStorage] Bitácora persistente (Tarea 4.3).
 * @param {Storage|null} [options.storage] Almacén local nativo (inyectable).
 * @param {(tagName: string) => HTMLElement} [options.elementFactory] Fábrica de nodos.
 * @param {Document} [options.document] Documento del navegador (inyectable).
 * @param {HTMLCanvasElement} [options.canvas] Lienzo de conjuración (inyectable).
 * @param {CanvasRenderingContext2D} [options.ctx] Contexto 2D (inyectable).
 * @param {(cb: Function) => number} [options.raf] Planificador de cuadros.
 * @param {(id: number) => void} [options.caf] Cancelador de cuadros.
 * @param {{now: () => number}} [options.clock] Reloj monótono.
 * @param {MediaQueryList|null} [options.motionQuery] Consulta de movimiento reducido.
 * @param {Object|null} [options.speechSynthesis] Doble de síntesis (RF-06.3).
 * @param {'canonical'|'essays'} [options.initialMode] Tomo que abre la vista;
 *   el orquestador lo decide tras la guardia del libro personal (RF-01.2).
 * @param {(comboElement: string) => Promise<object|null>} [options.awardSimulatorPractice]
 *   Acredita al clan del adepto la gloria de la reacción recién detonada
 *   (SPEC-07, RF-03.2). Devolverá el sobre del santuario o null si no hay
 *   vínculo que acreditar. Sin este contrato la Cámara de Conjuración
 *   funciona exactamente igual, solo que sin gloria de hermandad.
 * @returns {Object} API: { render, destroy, switchCatalog, nextPage,
 *   previousPage, castCurrentSpell, restoreDummy, reciteCurrentSpell,
 *   toggleMicrophone, getAnnouncements, getState }.
 */
export function createGrimoireSimulatorView(mountRoot, options = {}) {
  const {
    grimoireClient,
    storage = null,
    elementFactory = (tagName) => document.createElement(tagName),
  } = options;

  const doc = options.document ?? (typeof document !== 'undefined' ? document : null);
  const defaultWindow = typeof window !== 'undefined' ? window : null;
  const raf = options.raf ?? ((callback) => defaultWindow?.requestAnimationFrame?.(callback) ?? 0);
  const caf = options.caf ?? ((id) => defaultWindow?.cancelAnimationFrame?.(id));
  /** Envoltura de cuadros para planificadores inyectables ((callback) => cancel). */
  const rafWrapper = (callback) => {
    const frameId = raf(() => callback());
    return () => caf(frameId);
  };
  const clock = options.clock ?? {
    now: () => (typeof performance !== 'undefined' ? performance.now() : Date.now()),
  };
  const now = () => (typeof clock.now === 'function' ? clock.now() : clock.now);
  const motionQuery = options.motionQuery
    ?? (defaultWindow?.matchMedia ? defaultWindow.matchMedia('(prefers-reduced-motion: reduce)') : null);
  const speechSynthesis = options.speechSynthesis ?? defaultWindow?.speechSynthesis ?? null;
  const speechService = options.speechService ?? createSpeechService({
    synth: speechSynthesis,
    Utterance: defaultWindow?.SpeechSynthesisUtterance ?? null,
    Recognition: defaultWindow?.SpeechRecognition ?? defaultWindow?.webkitSpeechRecognition ?? null,
  });
  const testLog = options.testLogStorage ?? createTestLogStorage({
    storage: storage ?? (typeof localStorage !== 'undefined' ? localStorage : null),
  });

  /** Tomo con el que abre la vista (RF-01.2): canónico si no se indica otro. */
  const initialMode = options.initialMode === CATALOG_MODES.essays
    ? CATALOG_MODES.essays
    : CATALOG_MODES.canonical;

  /** Estado de orquestación de la vista. */
  const state = {
    mode: initialMode,
    spells: [],
    currentSpell: null,
    pageNumber: 1,
    totalPages: 1,
    announcements: [],
    sceneRunning: false,
    microphoneListening: false,
    tremorUntil: 0,
    voiceResting: false,
  };

  /** Identificador del vuelo de escena vigente (bucle propio de la vista). */
  let sceneRafId = 0;
  let lastSceneTimestamp = 0;
  let tomeInstance = null;

  // ---------------------------------------------------------------------
  // Construcción de la estructura (sin innerHTML, AGENTS.md 6.1).
  // ---------------------------------------------------------------------

  /** Crea un nodo con clase y texto seguro. */
  function createTextElement(tagName, className, safeText = '') {
    const element = elementFactory(tagName);
    element.className = className;
    element.textContent = safeText;
    return element;
  }

  const root = elementFactory('section');
  root.className = 'grimoire-simulator';
  root.setAttribute('aria-label', 'Cámara de Conjuración del grimorio');

  // --- Barra de controles ceremoniales ---
  const controls = elementFactory('div');
  controls.className = 'grimoire-simulator__controls';

  const canonicalOption = createTextElement('button', 'grimoire-simulator__catalog-switch grimoire-simulator__catalog-switch--canonical', 'Tomo Canónico');
  canonicalOption.type = 'button';
  canonicalOption.setAttribute('aria-pressed', 'true');
  const essaysOption = createTextElement('button', 'grimoire-simulator__catalog-switch grimoire-simulator__catalog-switch--essays', 'Mis Ensayos Arcanos');
  essaysOption.type = 'button';
  essaysOption.setAttribute('aria-pressed', 'false');

  const touchSeal = createTextElement('button', 'grimoire-simulator__seal grimoire-simulator__seal--cast', 'Invocar Conjuro');
  touchSeal.type = 'button';
  touchSeal.setAttribute('aria-label', 'Invocar el conjuro de la página sobre el maniquí');

  const listenSeal = createTextElement('button', 'grimoire-simulator__seal grimoire-simulator__seal--listen', 'Escuchar Cántico');
  listenSeal.type = 'button';
  listenSeal.setAttribute('aria-label', 'Escuchar la declamación solemne de la fórmula');

  const microphoneSeal = createTextElement('button', 'grimoire-simulator__seal grimoire-simulator__seal--microphone', 'Micrófono de Conjuración');
  microphoneSeal.type = 'button';
  microphoneSeal.setAttribute('aria-label', 'Conjurar pronunciando el nombre o la fórmula del conjuro');

  const restoreSeal = createTextElement('button', 'grimoire-simulator__seal grimoire-simulator__seal--restore', 'Restaurar Maniquí');
  restoreSeal.type = 'button';
  restoreSeal.setAttribute('aria-label', 'Restablecer el maniquí y limpiar la bitácora');

  controls.appendChild(canonicalOption);
  controls.appendChild(essaysOption);
  controls.appendChild(touchSeal);
  controls.appendChild(listenSeal);
  controls.appendChild(microphoneSeal);
  controls.appendChild(restoreSeal);
  root.appendChild(controls);

  // --- Cámara de Conjuración: lienzo + maniquí, alojados en la lámina derecha ---
  const stage = elementFactory('div');
  stage.className = 'grimoire-simulator__stage';
  const tomeHost = elementFactory('div');
  tomeHost.className = 'grimoire-simulator__tome';
  stage.appendChild(tomeHost);
  root.appendChild(stage);

  const cameraSlot = elementFactory('div');
  cameraSlot.className = 'grimoire-book__camera-slot';

  const canvas = options.canvas ?? elementFactory('canvas');
  canvas.className = 'arcane-canvas';
  cameraSlot.appendChild(canvas);
  const ctx = options.ctx ?? canvas.getContext?.('2d') ?? null;

  const dummyHost = elementFactory('div');
  dummyHost.className = 'grimoire-simulator__dummy-host';
  cameraSlot.appendChild(dummyHost);

  // --- Bitácora de Pruebas (RF-05.3) ---
  const logbook = elementFactory('aside');
  logbook.className = 'grimoire-simulator__logbook';
  logbook.setAttribute('aria-label', 'Bitácora de Pruebas');
  const logbookTitle = createTextElement('h3', 'grimoire-simulator__logbook-title', 'Bitácora de Pruebas');
  const logbookList = elementFactory('ol');
  logbookList.className = 'grimoire-simulator__logbook-list';
  logbook.appendChild(logbookTitle);
  logbook.appendChild(logbookList);
  root.appendChild(logbook);

  // --- Región viva de anuncios accesibles (RF-06.4) ---
  const announcer = createTextElement('p', 'grimoire-simulator__announcer', '');
  announcer.setAttribute('role', 'status');
  announcer.setAttribute('aria-live', 'polite');
  root.appendChild(announcer);

  // ---------------------------------------------------------------------
  // Componentes de la Cámara (Tareas 3.1 a 3.3) y Códice Elemental
  // (SPEC-06, Tarea 4.1): el maniquí conserva su estado entre páginas
  // (RF-02.3), y el halo del aura vive en su mismo contenedor para viajar
  // con la Cámara al hojear.
  // ---------------------------------------------------------------------
  const dummy = createCombatDummyComponent({
    host: dummyHost,
    clock,
    createElement: elementFactory,
  });

  /** Bus de combos: el propio shell de la vista (o el doble del arnés). */
  const comboEventTarget = options.comboEventTarget ?? root;

  /** Salvaguarda Anti-Stunlock (RF-05.2/05.3) gobernada por el reloj de la vista. */
  const stunlockManager = createStunlockManager({
    now,
    eventTarget: comboEventTarget,
  });

  /** Motor de combos en cliente: espejo del Códice, paridad con el backend. */
  const comboResolver = createComboResolver({
    now,
    eventTarget: comboEventTarget,
    stunlockManager,
  });

  /**
   * Estado elemental vigente del blanco (TargetAuraState, plan 2.2):
   * residencia del aura entre páginas — jamás se purga al hojear (RF-02.3).
   */
  let activeAuraElement = null;

  /** La vista quedó desmontada: ninguna gloria tardía narra ya nada. */
  let isDestroyed = false;

  /** Cola determinista FIFO de impactos (plan 3.3): un impacto por cuadro. */
  const impactQueue = createSpellImpactQueue({
    resolveImpact: (impact) => {
      // El estado del aura se lee VIVO en el cuadro de resolución, jamás
      // la instantánea del encolado: la cola drena un impacto por cuadro
      // y, durante ese lapso, el aura puede expirar (RF-02.7) o haber sido
      // aplicada por un impacto anterior de la ráfaga (RF-05.4: el primer
      // impacto imbuye y el segundo detona SOBRE esa imbuición). Resolver
      // contra la copia congelada reproduciría el desdoblamiento de estado
      // que el Hallazgo 13 de SPEC-06 desterró para el halo.
      impact.activeAura = activeAuraElement ?? '';
      const verdict = comboResolver.resolveImpact(impact);
      // El estado del aura sigue al veredicto: la única fuente de verdad.
      activeAuraElement = verdict.resultingAura ?? null;
      // Elementos intervinientes (RF-06.3): capturados del impacto encolado
      // — el aura previa y el elemento entrante — sin alterar el contrato
      // de once claves del veredicto (paridad byte a byte con el backend).
      applyComboVerdict(verdict, impact.meta, {
        activeAura: impact.activeAura ?? null,
        incoming: impact.incomingSpell?.element ?? null,
      });
    },
    now,
    scheduleFrame: rafWrapper,
  });

  /** Halo de aura montado sobre el contenedor del maniquí. */
  const elementalAura = createElementalAuraComponent({
    document: doc,
    now,
    eventTarget: comboEventTarget,
    prefersReducedMotion: () => Boolean(motionQuery?.matches),
    // El bucle de escena del aura late en el MISMO planificador que la
    // vista (cuadros, no timers): su expiración natural queda sincronizada
    // con el resto de la Cámara y obedece a los mismos arneses.
    scheduleFrame: rafWrapper,
  });
  elementalAura.mount(dummyHost);

  // La expiración natural del halo (RF-02.5) también es verdad para la
  // vista: su copia del estado elemental se purga con ella. Sin esta
  // sincronía, un aura caducada seguía viviendo en `activeAuraElement`
  // y el resolutor interpretaba impactos posteriores como refresco
  // (mismo elemento, sin efecto visible) o combo (elemento distinto)
  // sobre un blanco que en pantalla ya estaba en reposo dorado.
  comboEventTarget.addEventListener('combo:aura-expired', () => {
    activeAuraElement = null;
  });

  /**
   * Pool dedicado a las deflagraciones de combo (SPEC-06, Tarea 4.2):
   * independiente del pool del lienzo para no disputar su cupo a los
   * proyectiles; se integra en el mismo contexto 2D. Con ctx ausente
   * (arneses sin lienzo) queda en null y la deflagración se omite.
   */
  let comboDetonationPool = null;
  if (ctx) {
    comboDetonationPool = new ParticlePool(60);
    comboDetonationPool.renderWithContext = ctx; // integración en el bucle de escena
  }
  /** PRNG determinista de las deflagraciones (RNF-01): semilla fija. */
  const comboDetonationRandom = createDeterministicComboRandom(20260914);
  const floatingTexts = createFloatingCombatTextComponent({ ctx, clock });
  const arcane = createArcaneCanvasComponent({
    canvas,
    ctx,
    document: doc,
    raf,
    caf,
    clock,
    motionQuery,
    speechSynthesis,
    onImpact: handleImpact,
  });

  // ---------------------------------------------------------------------
  // Bus de eventos desacoplado (plan 4.1).
  // ---------------------------------------------------------------------

  /** Despacha un evento del bus desde la raíz de la vista (burbujea al shell). */
  function dispatchBus(name, detail) {
    if (typeof root.dispatchEvent !== 'function') return;
    const event = typeof CustomEvent === 'function'
      ? new CustomEvent(name, { detail, bubbles: true })
      : { type: name, detail, bubbles: true };
    root.dispatchEvent(event);
  }

  /** Inscribe un anuncio en la región viva y en la crónica del arnés. */
  function announce(message) {
    const solemn = String(message ?? '');
    state.announcements.push(solemn);
    announcer.textContent = solemn;
  }

  // ---------------------------------------------------------------------
  // Catálogo, montaje del Tomo y navegación (RF-01, RF-02.3).
  // ---------------------------------------------------------------------

  /** Monta (o vuelve a montar) el Tomo con el catálogo dado. */
  function mountTome(spells) {
    tomeHost.replaceChildren();
    tomeInstance = createGrimoireBookComponent({
      root: tomeHost,
      cameraSlot,
      spells,
      onPageChange: handlePageChange,
      createElement: elementFactory,
    });
  }

  /** Carga el catálogo del modo solicitado y monta el Tomo (RF-01.2). */
  async function loadCatalog(mode) {
    const response = await grimoireClient.fetchSpells({
      mode,
      limit: CATALOG_PAGE_LIMIT,
    });
    if (!response?.success) {
      if (mode === CATALOG_MODES.essays) {
        // Aislamiento de ensayos (RF-01.2): sin vínculo consagrado, el tomo
        // canónico se reclama como refugio y el aviso es solemne, jamás
        // técnico. El visitante nunca queda ante un pergamino en blanco.
        state.mode = CATALOG_MODES.canonical;
        syncCatalogSwitch();
        announce('Los Ensayos Arcanos solo se abren a los eruditos consagrados: el Tomo Canónico permanece abierto.');
        return loadCatalog(CATALOG_MODES.canonical);
      }
      state.spells = [];
      mountTome([]);
      announce('El santuario no ha respondido: el pergamino aguarda en blanco.');
      return false;
    }

    const spells = response.data?.spells ?? [];
    state.mode = mode;
    state.spells = spells;
    state.pageNumber = 1;
    state.currentSpell = spells[0] ?? null;
    state.totalPages = Math.max(1, spells.length);
    mountTome(spells);
    syncCatalogSwitch();
    announce(mode === CATALOG_MODES.essays
      ? `El tomo se abre en tus Ensayos Arcanos: ${spells.length} conjuro(s) en prueba.`
      : `El tomo se abre en el Catálogo Canónico: ${spells.length} conjuro(s) ratificado(s).`);
    // La primera página debe anunciarse como cualquier otra transición.
    if (state.currentSpell) {
      handlePageChange({ spell: state.currentSpell, pageNumber: 1, totalPages: state.totalPages });
    }
    return true;
  }

  /** Refleja el modo activo en el conmutador rúnico (RF-01.2). */
  function syncCatalogSwitch() {
    const canonical = state.mode === CATALOG_MODES.canonical;
    canonicalOption.classList.toggle('grimoire-simulator__catalog-switch--active', canonical);
    essaysOption.classList.toggle('grimoire-simulator__catalog-switch--active', !canonical);
    canonicalOption.setAttribute('aria-pressed', String(canonical));
    essaysOption.setAttribute('aria-pressed', String(!canonical));
  }

  /**
   * Transición de página del Tomo (RF-01.3/01.4).
   * El maniquí conserva su estado (RF-02.3) y los rótulos flotantes no
   * cruzan de lámina (RF-05.2).
   */
  function handlePageChange({ spell, pageNumber, totalPages }) {
    state.currentSpell = spell ?? null;
    state.pageNumber = pageNumber ?? 1;
    state.totalPages = totalPages ?? 1;
    dummy.onPageChange();
    floatingTexts.clear();
    dispatchBus('grimoire:page-change', {
      spell: state.currentSpell,
      pageNumber: state.pageNumber,
      totalPages: state.totalPages,
    });
    if (state.currentSpell) {
      const romano = ROMAN_CIRCLES[state.currentSpell.circle] ?? '?';
      announce(`Página ${state.pageNumber} de ${state.totalPages}: ${state.currentSpell.name}, Círculo ${romano}.`);
    }
  }

  /** Hojear hacia adelante (sólo si el Tomo puede hacerlo). */
  function nextPage() {
    tomeInstance?.nextPage();
    return state.pageNumber;
  }

  /** Hojear hacia atrás (sólo si el Tomo puede hacerlo). */
  function previousPage() {
    tomeInstance?.previousPage();
    return state.pageNumber;
  }

  /** Conmuta entre el Tomo Canónico y los Ensayos Arcanos (RF-01.2). */
  async function switchCatalog(mode) {
    const target = mode === CATALOG_MODES.essays ? CATALOG_MODES.essays : CATALOG_MODES.canonical;
    if (target === state.mode) return true;
    return loadCatalog(target);
  }

  // ---------------------------------------------------------------------
  // Invocación, impacto y bitácora (RF-02, RF-03, RF-05, RF-06.4).
  // ---------------------------------------------------------------------

  /** Medidas vigentes del lienzo (con respaldo si aún no hay maquetación). */
  function canvasMetrics() {
    const width = Number(canvas?.clientWidth || canvas?.width || FALLBACK_CANVAS.width);
    const height = Number(canvas?.clientHeight || canvas?.height || FALLBACK_CANVAS.height);
    return { width, height };
  }

  /** ¿El usuario ha pedido movimiento reducido? (RF-06.1) */
  function prefersReducedMotion() {
    return Boolean(motionQuery?.matches);
  }

  /** Ajusta el lienzo al tamaño de su lámina (sin reasignar cada cuadro). */
  function syncCanvasSize() {
    if (!canvas) return;
    const { width, height } = canvasMetrics();
    const roundedWidth = Math.round(width);
    const roundedHeight = Math.round(height);
    if (canvas.width !== roundedWidth) canvas.width = roundedWidth;
    if (canvas.height !== roundedHeight) canvas.height = roundedHeight;
  }

  /** Dispara el temblor ceremonial de la Cámara (RF-05.1). */
  function triggerTremor(circle) {
    if (prefersReducedMotion()) return;
    // Proporcional al Círculo Arcano: I contenido, V con temblor pleno.
    const intensity = Math.min(1, Math.max(1, Number(circle) || 1) / 5);
    stage.style.setProperty('--tremor-intensity', intensity.toFixed(2));
    stage.classList.add(IMPACT_TREMOR_CLASS);
    state.tremorUntil = now() + IMPACT_TREMOR_MS;
  }

  /**
   * Invoca el conjuro de la página vigente (RF-03, RF-04.5).
   * @returns {Promise<boolean>} true si el conjuro se desató.
   */
  async function castCurrentSpell({ triggerMethod = 'click' } = {}) {
    const spell = state.currentSpell;
    if (!spell) {
      announce('No hay conjuro iluminado en esta página: hoja el tomo antes de conjurar.');
      return false;
    }
    dispatchBus('grimoire:cast-spell', { spell, triggerMethod });
    syncCanvasSize();
    const metrics = canvasMetrics();
    const origin = { x: metrics.width * CAST_ORIGIN_RATIO.x, y: metrics.height * CAST_ORIGIN_RATIO.y };
    const target = { x: metrics.width * CAST_TARGET_RATIO.x, y: metrics.height * CAST_TARGET_RATIO.y };
    try {
      arcane.castSpell(spell, {
        geometry: spell.areaType ?? 'singleTarget',
        origin,
        target,
        count: 14,
      });
    } catch {
      // Corrector de impacto: un fallo imprevisto del motor jamás debe
      // morir en silencio como promesa rechazada — se anuncia con
      // dignidad y el conjuro se da por fallido ante el adepto.
      announce(`El conjuro «${spell.name}» se dispersó sin alcanzar el maniquí: inténtalo de nuevo.`);
      return false;
    }
    triggerTremor(spell.circle);
    return true;
  }

  /**
   * Aplica un veredicto del Códice al banco de pruebas: traduce el contrato
   * de once claves al del maniquí (SPEC-05) y despacha los registros
   * visuales, de bitácora y accesibles (RF-05.2, RF-06.4).
   *
   * @param {object} verdict Veredicto del resolutor (única fuente de verdad).
   * @param {{spell: object, targetCoordinates: {x: number, y: number}}} meta
   * @param {{activeAura: string|null, incoming: string|null}|null} [comboElements]
   *   Elementos intervinientes capturados del impacto encolado (RF-06.3).
   */
  function applyComboVerdict(verdict, { spell, targetCoordinates }, comboElements = null) {
    const effects = spell?.effects ?? {};

    // Traducción del veredicto al contrato del maniquí: el daño efectivo ya
    // viaja ampliado; el CC duro se suprime con la onda de choque de la
    // salvaguarda; la trituración del Códice anula la barrera sin excedente
    // (RF-04.2) y la duración de las ataduras la gobierna el Códice.
    const isHardCcSuppressed = verdict.stunlockTriggered === true;
    const tacticalEffect = verdict.tacticalEffectApplied;
    const codexEffect = CODEX_EFFECT_TO_CC[tacticalEffect];
    // La duración de toda atadura derivada del Códice viaja en el veredicto
    // (hardStun 1.5 s, neblina/enraizamiento 3 s); null para las ajenas.
    const codexDurationMs = codexEffect !== undefined ? verdict.effectDurationMs : null;
    const crowdControlType = isHardCcSuppressed
      ? null
      : (codexEffect ?? effects.crowdControlType);
    const crowdControlDurationMs = (codexEffect !== undefined && !isHardCcSuppressed)
      ? codexDurationMs
      : null;
    const barrierShatter = verdict.reactionId === 'basalticFracture' ? CODEX_BARRIER_SHATTER : 0;
    // Penetración del Colapso Crepuscular (RF-04.2, Hallazgo 6): el daño puro
    // ignora el 100% de la barrera y toca la salud; el escudo queda intacto.
    const isBarrierPiercing = tacticalEffect === 'barrierPiercing';

    const result = dummy.applySpellImpact({
      name: spell?.name,
      effects: {
        damage: verdict.effectiveDamage,
        healing: Number(effects.healing ?? 0) || 0,
        barrier: Number(effects.barrier ?? 0) || 0,
        crowdControlType: crowdControlType ?? 'none',
        ...(crowdControlDurationMs !== null ? { crowdControlDurationMs } : {}),
        ...(barrierShatter > 0 ? { barrierShatter } : {}),
        ...(isBarrierPiercing ? { pierceBarrier: true } : {}),
      },
    });

    // Deflagración bicromática y Texto Flotante Monumental (SPEC-06,
    // Tarea 4.2, RF-06.1/06.2): solo la detonación de una reacción fusiona
    // las estelas de ambos elementos y corona el rótulo ceremonial.
    if (verdict.isReaction === true) {
      // Gloria de hermandad (SPEC-07, Tarea 7.1): la reacción recién detonada
      // devenga PDA al clan del adepto (RF-03.2). La vista NO decide el monto
      // ni el techo diario —eso es del santuario—: cursa la orden y narra el
      // recibo que retorne.
      void creditSimulatorPractice(comboElements?.incoming ?? '');

      const comboTarget = {
        x: Number(targetCoordinates?.x ?? 0),
        y: Number(targetCoordinates?.y ?? 0),
      };
      if (!prefersReducedMotion() && comboDetonationPool !== null) {
        comboDetonationPool.emitComboDetonation(comboTarget, {
          colorA: verdict.colorA,
          colorB: verdict.colorB,
          random: comboDetonationRandom,
        });
      }
      floatingTexts.spawnMonumentalText({
        reactionName: verdict.reactionName ?? '',
        damage: result.damageApplied > 0 ? result.damageApplied : 0,
      }, comboTarget);
    }

    // El veredicto es la única fuente de verdad: si la reacción consumió
    // el aura, el halo se disipa sin esperar su expiración (RF-03.4).
    if (verdict.clearedAura === true) {
      elementalAura.dissipate();
    }

    // Textos flotantes escalonados sobre el torso del maniquí (RF-05.2).
    floatingTexts.spawnImpactTexts({
      spellName: spell?.name,
      damage: result.damageApplied,
      healing: Number(effects.healing ?? 0) || 0,
      barrier: Number(effects.barrier ?? 0) || 0,
      crowdControlType: result.crowdControlApplied,
      fullHealthLegend: result.fullHealthLegend,
    }, {
      x: Number(targetCoordinates?.x ?? 0),
      y: Number(targetCoordinates?.y ?? 0),
    });

    // Bitácora persistente del banco de pruebas (RF-05.3, plan 2.2).
    // La detonación añade los campos de combo del Códice (RF-06.3,
    // Tarea 4.3): etiqueta distintiva, elementos intervinientes y daño
    // total asestado — todos espejo del veredicto, única fuente de verdad.
    const comboTag = composeComboTag(verdict);
    testLog.recordImpact({
      spellName: spell?.name ?? 'Conjuro sin nombre',
      elementalAffinity: spell?.elementalAffinity ?? 'pureArcane',
      circle: Number(spell?.circle ?? 1),
      manaCost: Number(spell?.manaCost ?? 0),
      damageDealt: result.damageApplied,
      barrierAbsorbed: result.barrierAbsorbed,
      healingApplied: Number(effects.healing ?? 0) || 0,
      crowdControlApplied: result.crowdControlApplied ?? 'none',
      dummyRemainingHealth: result.remainingHealth,
      ...(comboTag !== null ? {
        comboTag,
        comboElements: {
          activeAura: comboElements?.activeAura ?? null,
          incoming: comboElements?.incoming ?? null,
        },
        comboDamageDealt: result.damageApplied,
      } : {}),
    });
    refreshLogbook();

    dispatchBus('grimoire:spell-impact', {
      spell,
      targetCoordinates: { x: Number(targetCoordinates?.x ?? 0), y: Number(targetCoordinates?.y ?? 0) },
    });
    announce(composeImpactAnnouncement(spell, result, verdict));
    // Anuncio accesible canónico de la detonación (RF-06.4, Tarea 4.3):
    // se emite tras el anuncio del impacto para que los lectores de
    // pantalla prioricen la reacción recién desatada.
    if (verdict?.isReaction === true) {
      announce(composeReactionAnnouncement(verdict, result));
    }
  }

  /**
   * Cursa la gloria de hermandad de un combo recién detonado (SPEC-07, RF-03.2).
   *
   * El elemento que se declara es el del CONJURO ENTRANTE —la firma del
   * propio adepto—, no el del aura prestada del blanco: la sinergia temática
   * premia la especialización de quien lanza, y el santuario sigue siendo
   * quien la evalúa contra la afinidad rectora del linaje.
   *
   * Degradación elegante (AGENTS.md 8): sin contrato inyectado, sin vínculo
   * arcano o con la red caída, la Cámara de Conjuración sigue igual de viva;
   * simplemente no se narra gloria alguna. Con el techo diario colmado el
   * recibo llega con cero PDA y su motivo canónico, y la vista lo canta como
   * lo que es: una jornada de práctica ya colmada, jamás un error.
   *
   * @param {string} comboElement Afinidad elemental del conjuro entrante.
   */
  async function creditSimulatorPractice(comboElement) {
    if (typeof options.awardSimulatorPractice !== 'function') return;

    try {
      const envelope = await options.awardSimulatorPractice(String(comboElement ?? ''));
      if (isDestroyed) return; // La vista murió mientras la petición volaba.
      if (envelope?.success !== true) return;

      const award = envelope.data?.award ?? {};
      const awardedPoints = Number(award.awardedPoints ?? 0) || 0;

      if (awardedPoints > 0) {
        const remainder = Number(award.dailyQuotaRemaining ?? 0) || 0;
        const synergy = award.hasSynergy === true ? ' (sinergia de linaje)' : '';
        announce(
          `La hermandad cobra ${awardedPoints} PDA${synergy}; restan ${remainder} para el techo diario.`,
        );
        return;
      }

      if (award.reason === SIMULATOR_CAP_REASON) {
        announce('La hermandad ya colmó su techo diario de 50 PDA: la práctica de hoy no devenga más gloria.');
      }
    } catch {
      // Corte de maná: la gloria se pierde en silencio; la conjuración no.
    }
  }

  /**
   * Resuelve el impacto del conjuro sobre el maniquí (RF-05.1/05.2/05.3)
   * y detona el Códice Elemental (SPEC-06, Tarea 4.1).
   *
   * Flujo: la cola FIFO (plan 3.3) resuelve un impacto por cuadro en
   * estricto orden de llegada; cada resolución actualiza el estado del
   * aura — única fuente de verdad — y aplica su veredicto al banco.
   *
   * @param {{spell: object, targetCoordinates: {x: number, y: number}}} detail
   */
  function handleImpact({ spell, targetCoordinates }) {
    const effects = spell?.effects ?? {};
    impactQueue.enqueue({
      activeAura: activeAuraElement ?? '',
      incomingSpell: {
        id: spell?.id ?? null,
        element: spell?.elementalAffinity ?? 'pureArcane',
        baseDamage: Number(effects.damage ?? 0) || 0,
        baseHealing: Number(effects.healing ?? 0) || 0,
        baseBarrier: Number(effects.barrier ?? 0) || 0,
        crowdControlType: effects.crowdControlType ?? 'none',
      },
      stunlockImmune: stunlockManager.isImmuneToHardCc(),
      meta: { spell, targetCoordinates },
    });
  }

  /** Compone el anuncio accesible del impacto (RF-06.4). */
  function composeImpactAnnouncement(spell, result, verdict = null) {
    const effects = spell?.effects ?? {};
    const fragments = [];
    if (result.damageApplied > 0) {
      fragments.push(`inflige ${result.damageApplied} puntos de daño`);
    }
    if (Number(effects.healing ?? 0) > 0) {
      fragments.push(`restaura ${effects.healing} puntos de salud`);
    }
    if (Number(effects.barrier ?? 0) > 0) {
      fragments.push(`alza una barrera de ${effects.barrier} puntos`);
    }
    if (result.crowdControlApplied) {
      fragments.push(`lo deja ${CROWD_CONTROL_LABELS[result.crowdControlApplied] ?? 'atado por el conjuro'}`);
    }
    // La detonación del Códice encabeza el anuncio con el nombre solemne
    // de la reacción (la etiqueta completa de la bitácora es Tarea 4.3).
    if (verdict?.isReaction === true && verdict.reactionName) {
      return `¡${verdict.reactionName}! Lanzado ${spell?.name ?? 'conjuro desconocido'}: ${fragments.join(', ') || 'no altera al maniquí'}`;
    }
    const summary = fragments.length > 0 ? fragments.join(', ') : 'no altera al maniquí';
    return `Lanzado ${spell?.name ?? 'conjuro desconocido'}: ${summary} al maniquí de pruebas`;
  }

  /**
   * Etiqueta distintiva del combo para la Bitácora (RF-06.3, Tarea 4.3):
   * «[Combo: <nombre solemne>]» solo cuando el veredicto detona una
   * reacción; null en imbuición, refresco o sobreescritura.
   */
  function composeComboTag(verdict) {
    if (verdict?.isReaction !== true || !verdict.reactionName) {
      return null;
    }
    return `[Combo: ${verdict.reactionName}]`;
  }

  /**
   * Anuncio accesible de la detonación (RF-06.4, Tarea 4.3): fórmula
   * canónica «Reacción desatada: <nombre> inflige <daño> puntos de daño
   * al maniquí de pruebas», distinta del Texto Flotante Monumental —
   * la región viva narra, el lienzo exclama.
   */
  function composeReactionAnnouncement(verdict, result) {
    const reactionName = verdict.reactionName ?? 'reacción arcana';
    const damageDealt = Number(result?.damageApplied ?? 0);
    if (damageDealt > 0) {
      return `Reacción desatada: ${reactionName} inflige ${damageDealt} puntos de daño al maniquí de pruebas`;
    }
    return `Reacción desatada: ${reactionName} no altera la salud del maniquí de pruebas`;
  }

  /** Repinta el panel de la Bitácora de Pruebas (RF-05.3). Las filas de
   *  detonación encabezan con la etiqueta distintiva del combo (RF-06.3,
   *  Tarea 4.3). */
  function refreshLogbook() {
    const entries = testLog.getEntries();
    const rows = entries.map((entry) => {
      const row = elementFactory('li');
      row.className = 'grimoire-simulator__logbook-entry';
      const hour = String(entry.timestamp ?? '').slice(11, 16);
      const comboLabel = typeof entry.comboTag === 'string' && entry.comboTag.length > 0
        ? `${entry.comboTag} `
        : '';
      row.textContent = `${hour} — ${comboLabel}${entry.spellName}: ${entry.damageDealt} de daño`
        + ` · maná ${entry.manaCost}`
        + ` · maniquí ${entry.dummyRemainingHealth}/${DUMMY_MAX_HEALTH} PV`;
      return row;
    });
    logbookList.replaceChildren(...rows);
  }

  /** Restablece el maniquí y la bitácora (RF-02.6). El aura y la
   *  inmunidad anti-stunlock se disipan con él (SPEC-06). */
  function restoreDummy() {
    dummy.restore();
    elementalAura.clear();
    activeAuraElement = null;
    stunlockManager.reset();
    impactQueue.clear();
    floatingTexts.clear();
    comboDetonationPool?.reset();
    testLog.clear();
    refreshLogbook();
    dispatchBus('grimoire:dummy-reset', { reason: 'user' });
    announce(`Maniquí restaurado: ${DUMMY_MAX_HEALTH} puntos de salud, barreras disipadas y bitácora limpia.`);
  }

  // ---------------------------------------------------------------------
  // Sellos vocales (RF-04).
  // ---------------------------------------------------------------------

  /** Declama la fórmula litúrgica del conjuro vigente (RF-04.2). */
  function reciteCurrentSpell() {
    const spell = state.currentSpell;
    if (!spell) {
      announce('No hay conjuro iluminado cuya fórmula declamar.');
      return false;
    }
    const recited = speechService.reciteSpell(spell.incantationFormula ?? spell.name ?? '');
    if (!recited) {
      setVoiceResting(true);
      announce('El oráculo de la voz reposa en silencio: este navegador no sabe declamar cánticos.');
      return false;
    }
    announce(`El tomo declama la fórmula de ${spell.name}.`);
    return true;
  }

  /** Marca los sellos vocales en reposo ceremonial (RF-04.4). */
  function setVoiceResting(resting) {
    state.voiceResting = Boolean(resting);
    if (resting) {
      microphoneSeal.classList.add('grimoire-simulator__seal--resting');
      microphoneSeal.setAttribute('aria-disabled', 'true');
      listenSeal.classList.add('grimoire-simulator__seal--resting');
      listenSeal.setAttribute('aria-disabled', 'true');
    } else {
      microphoneSeal.classList.remove('grimoire-simulator__seal--resting');
      microphoneSeal.removeAttribute('aria-disabled');
      listenSeal.classList.remove('grimoire-simulator__seal--resting');
      listenSeal.removeAttribute('aria-disabled');
    }
  }

  /** Sonda el soporte vocal y ajusta los sellos al arrancar (RF-04.4). */
  function probeVoiceSupport() {
    const synthesisReady = speechService.isSynthesisSupported?.() ?? false;
    const recognitionReady = speechService.isRecognitionSupported?.() ?? false;
    if (!synthesisReady) {
      listenSeal.classList.add('grimoire-simulator__seal--resting');
      listenSeal.setAttribute('aria-disabled', 'true');
    }
    if (!recognitionReady) {
      setVoiceResting(true);
    }
  }

  /** Abre o cierra la escucha del micrófono de conjuración (RF-04.3). */
  function toggleMicrophone() {
    if (state.microphoneListening) {
      speechService.stopAll();
      state.microphoneListening = false;
      announce('El oráculo del sonido reposa: la escucha ha concluido.');
      return false;
    }
    const spell = state.currentSpell;
    if (!spell) {
      announce('No hay conjuro iluminado que pronunciar.');
      return false;
    }
    const started = speechService.startListening(spell, handleSpeechMatch, handleSpeechError);
    state.microphoneListening = Boolean(started);
    if (!started) {
      setVoiceResting(true);
      return false;
    }
    announce(`El oráculo escucha: pronuncia «${spell.name}» o su fórmula ceremonial.`);
    return true;
  }

  /** El oráculo ha entendido una invocación (RF-04.3 y bus `speech-triggered`). */
  function handleSpeechMatch(activeSpell, transcript = '') {
    const matched = typeof speechService.matchesSpellInvocation === 'function'
      ? speechService.matchesSpellInvocation(transcript, activeSpell)
      : true;
    state.microphoneListening = false;
    dispatchBus('grimoire:speech-triggered', {
      recognizedPhrase: transcript,
      spellMatched: matched,
    });
    if (!matched) {
      announce('El oráculo no reconoció las palabras rituales: repite el nombre del conjuro o su fórmula.');
      return;
    }
    announce(`El oráculo ha reconocido «${transcript}»: el conjuro se desata.`);
    void castCurrentSpell({ triggerMethod: 'voice' });
  }

  /** El oráculo ha fallado o el permiso ha sido denegado (RF-04.4). */
  function handleSpeechError(message) {
    state.microphoneListening = false;
    setVoiceResting(true);
    announce(message);
  }

  // ---------------------------------------------------------------------
  // Bucle de escena: maniquí, rótulos flotantes y temblor ceremonial.
  // ---------------------------------------------------------------------

  /** Un cuadro del bucle propio de la vista (integra escena y overlays). */
  function sceneFrame() {
    const instant = now();
    const dt = lastSceneTimestamp
      ? Math.min((instant - lastSceneTimestamp) / 1000, 0.05)
      : 0.016;
    lastSceneTimestamp = instant;

    syncCanvasSize();
    dummy.tick();
    floatingTexts.updateAndRender(dt);
    // Las deflagraciones de combo viven en el mismo bucle de escena.
    if (comboDetonationPool !== null) {
      comboDetonationPool.updateAndRender(ctx, dt);
    }

    if (state.tremorUntil && instant >= state.tremorUntil) {
      state.tremorUntil = 0;
      stage.classList.remove(IMPACT_TREMOR_CLASS);
    }

    if (state.sceneRunning) {
      sceneRafId = raf(sceneFrame);
    }
  }

  /** Arranca el bucle de escena (idempotente). */
  function startScene() {
    if (state.sceneRunning) return;
    state.sceneRunning = true;
    lastSceneTimestamp = 0;
    sceneRafId = raf(sceneFrame);
  }

  /** Detiene el bucle de escena. */
  function stopScene() {
    if (!state.sceneRunning) return;
    state.sceneRunning = false;
    caf(sceneRafId);
  }

  // ---------------------------------------------------------------------
  // Sensibilidad, gestos y ciclo de vida.
  // ---------------------------------------------------------------------

  /** Gestos táctiles de paso de página (RF-01.3). */
  let touchStartX = null;
  const SWIPE_THRESHOLD_PX = 40;

  function handleTouchStart(event) {
    const touch = event?.touches?.[0];
    touchStartX = touch ? Number(touch.clientX) : null;
  }

  function handleTouchEnd(event) {
    if (touchStartX === null) return;
    const touch = event?.changedTouches?.[0];
    const endX = touch ? Number(touch.clientX) : null;
    if (endX === null) {
      touchStartX = null;
      return;
    }
    const delta = endX - touchStartX;
    touchStartX = null;
    if (Math.abs(delta) < SWIPE_THRESHOLD_PX) return;
    if (delta < 0) {
      nextPage();
    } else {
      previousPage();
    }
  }

  /** El oráculo enmudece y el lienzo descansa cuando la pestaña se oculta (RF-06.3). */
  function handleVisibilityChange() {
    if (doc?.hidden) {
      stopScene();
      arcane.stop();
      speechService.stopAll();
      state.microphoneListening = false;
      return;
    }
    startScene();
    arcane.start();
  }

  /** Cablea los sellos y gestos de la vista. */
  function bindControls() {
    canonicalOption.addEventListener('click', () => { void switchCatalog(CATALOG_MODES.canonical); });
    essaysOption.addEventListener('click', () => { void switchCatalog(CATALOG_MODES.essays); });
    touchSeal.addEventListener('click', () => { void castCurrentSpell({ triggerMethod: 'click' }); });
    listenSeal.addEventListener('click', () => { reciteCurrentSpell(); });
    microphoneSeal.addEventListener('click', () => { toggleMicrophone(); });
    restoreSeal.addEventListener('click', () => { restoreDummy(); });
    tomeHost.addEventListener('touchstart', handleTouchStart);
    tomeHost.addEventListener('touchend', handleTouchEnd);
    // Enlaces rúnicos de la ficha (SPEC-06, Tarea 4.4, RF-01.3): el glifo
    // de afinidad del Tomo enfoca el Códice en su elemento — los
    // filamentos del elemento quedan iluminados por highlightElement.
    tomeHost.addEventListener('grimoire:codex-focus', handleCodexFocus);
    doc?.addEventListener?.('visibilitychange', handleVisibilityChange);
  }

  /**
   * Enfoca el Códice en el elemento solicitado por un enlace rúnico de
   * la ficha (RF-01.3, Tarea 4.4). Si la vista recibió una instancia
   * viva de la Rueda Rúnica (inyectable en arneses y futura vista
   * ceremonial del Códice — Tarea 5.2), la preselecciona con sus
   * enlaces iluminados; el evento burbujeante queda además a
   * disposición del orquestador del portal.
   *
   * @param {{detail?: {elementId?: string}}} event
   */
  function handleCodexFocus(event) {
    const elementId = String(event?.detail?.elementId ?? '');
    if (elementId === '') return;
    options.elementalWheel?.highlightElement?.(elementId);
  }

  /**
   * Monta la vista: carga el Tomo Canónico y arranca los bucles.
   */
  async function render() {
    mountRoot.appendChild(root);
    bindControls();
    probeVoiceSupport();
    refreshLogbook();
    syncCatalogSwitch();
    await loadCatalog(initialMode);
    arcane.start();
    startScene();
  }

  /** Desmonta la vista liberando bucles y listeners. */
  function destroy() {
    isDestroyed = true;
    stopScene();
    arcane.stop();
    elementalAura.destroy();
    comboDetonationPool = null;
    speechService.stopAll?.();
    doc?.removeEventListener?.('visibilitychange', handleVisibilityChange);
    tomeHost.removeEventListener?.('touchstart', handleTouchStart);
    tomeHost.removeEventListener?.('touchend', handleTouchEnd);
    root.remove();
  }

  /**
   * Fotografía del estado de orquestación (para arneses y diagnóstico).
   * @returns {Object}
   */
  function getState() {
    const dummyState = dummy.getState();
    const entries = testLog.getEntries();
    return {
      mode: state.mode,
      currentSpell: state.currentSpell,
      pageNumber: state.pageNumber,
      totalPages: state.totalPages,
      // Espejo de la navegación acotada (RF-01.4): el Tomo notifica cada
      // transición por `onPageChange`, de modo que sus extremos coinciden
      // con los índices vigentes del catálogo filtrado.
      prevDisabled: state.totalPages === 0 || state.pageNumber <= 1,
      nextDisabled: state.totalPages === 0 || state.pageNumber >= state.totalPages,
      dummy: {
        health: dummyState.health,
        maxHealth: dummyState.maxHealth,
        barrier: dummyState.barrier,
        activeCC: dummyState.activeCC,
        state: dummyState.state,
      },
      // TargetAuraState del plan 2.2: el aura elemental y su cuenta atrás
      // (RF-02.3) — la persistencia entre páginas es la ausencia de purga.
      elementalAura: {
        element: elementalAura.getActiveElement(),
        active: elementalAura.isAuraActive(),
        remainingMs: elementalAura.getRemainingMs(),
      },
      stunlockImmunity: stunlockManager.isImmuneToHardCc(),
      logEntries: entries,
      logbookEntries: logbookList.children.length,
      floatingTexts: floatingTexts.getActiveCount() + floatingTexts.getPendingCount(),
      activeParticles: arcane.getActiveParticleCount(),
      densityScale: arcane.getDensityScale(),
      sceneRunning: state.sceneRunning,
      canvasRunning: arcane.isRunning(),
      reducedMotion: prefersReducedMotion(),
      tremoring: stage.classList.contains(IMPACT_TREMOR_CLASS),
      microphoneListening: state.microphoneListening,
      voiceSealResting: state.voiceResting,
      tactileSealEnabled: !touchSeal.disabled,
      announcements: [...state.announcements],
    };
  }

  /** Crónica de anuncios accesibles emitidos (RF-06.4). */
  function getAnnouncements() {
    return [...state.announcements];
  }

  return {
    render,
    destroy,
    switchCatalog,
    nextPage,
    previousPage,
    castCurrentSpell,
    restoreDummy,
    reciteCurrentSpell,
    toggleMicrophone,
    getAnnouncements,
    getState,
  };
}
