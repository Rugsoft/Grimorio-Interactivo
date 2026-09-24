/**
 * test_particle_engine_pool.mjs — Arnés TDD de la Tarea 2.1 (TASKS-05).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica, sobre `public/assets/js/utils/particleEngine.js`:
 *   [1] Instanciación única: el pool nace con 200 partículas pre-forged
 *       y el array interno jamás muta de identidad (cero `new Particle()`
 *       durante emisión/render).
 *   [2] Techo estricto de 200: emitir 500 partículas seguidas nunca deja
 *       más de 200 activas y no lanza errores (RF-03.4).
 *   [3] Reciclado FIFO: al saturar, la partícula más antigua es la primera
 *       reutilizada (sobrescrita) en la siguiente emisión.
 *   [4] Cinemática: integración de velocidad/aceleración por dt, extinción
 *       por tiempo de vida y descontor del contador activo.
 *   [5] Render: la partícula viva dibuja en el contexto (color, radio por
 *       escala, alfa) y la muerta no toca el lienzo.
 *   [6] Reset y estadísticas: `reset()` devuelve el pool al estado inicial
 *       y `getActiveCount()` es coherente en todo momento.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Canvas 2D nativo, sin dependencias.
 *   - Artículo V: identificadores en inglés camelCase, leyendas castellanas.
 */

let passed = 0;
let failed = 0;

/** Comprueba una condición y registra el aserto. */
function assert(condition, label) {
  if (condition) {
    passed++;
    console.log(`  OK   ${label}`);
  } else {
    failed++;
    console.log(`  FALLA ${label}`);
  }
}

/** Contexto 2D falso que solo cuenta llamadas (sin DOM). */
function createFakeCtx() {
  const calls = [];
  const record = (name) => (...args) => calls.push({ name, args });
  return {
    calls,
    save: record('save'),
    restore: record('restore'),
    beginPath: record('beginPath'),
    arc: record('arc'),
    fill: record('fill'),
    set fillStyle(value) { calls.push({ name: 'fillStyle', args: [value] }); },
    set globalAlpha(value) { calls.push({ name: 'globalAlpha', args: [value] }); },
    set fillStyleWrite(v) {},
  };
}

/** Construye el objeto global mínimo que el módulo pueda consultar. */
function installBrowserShims() {
  globalThis.window = globalThis.window ?? globalThis;
  globalThis.document = globalThis.document ?? undefined;
}

console.log('== ARNÉS TDD — Tarea 2.1: Particle + ParticlePool (Ring Buffer FIFO) ==');

installBrowserShims();

let engine;
try {
  engine = await import('../public/assets/js/utils/particleEngine.js');
} catch (error) {
  console.log(`  FATAL — no se pudo importar particleEngine.js: ${error.message}`);
  console.log('== RESUMEN == Fase roja: la implementación aún no existe.');
  process.exit(1);
}

const { Particle, ParticlePool } = engine;

console.log('[1] Instanciación única y geometría del pool');

assert(typeof Particle === 'function', 'exporta la clase Particle');
assert(typeof ParticlePool === 'function', 'exporta la clase ParticlePool');

const pool = new ParticlePool();
assert(pool.getSize() === 200, 'el pool nace con exactamente 200 partículas pre-instanciadas');
assert(pool.getActiveCount() === 0, 'arranca con 0 partículas activas');

const poolArrayRef = pool.getParticles();
pool.emit(10, 20, 1, 2, '#ff4500', 3, 1);
pool.updateAndRender(createFakeCtx(), 0.016);
assert(pool.getParticles() === poolArrayRef, 'el array interno conserva su identidad (cero reasignaciones)');
assert(pool.getParticles().every((p) => p instanceof Particle), 'todas las entradas siguen siendo Particle pre-forged');

console.log('[2] Techo estricto de 200 activas (RF-03.4)');

const saturated = new ParticlePool();
for (let i = 0; i < 500; i++) {
  saturated.emit(i, i, 0, 0, '#ffffff', 2, 1000); // vida larga: ninguna muere
}
assert(saturated.getActiveCount() === 200, 'tras 500 emisiones continuadas hay exactamente 200 activas');
assert(saturated.getSize() === 200, 'el pool jamás crece del techo de 200');

console.log('[3] Reciclado FIFO — la más antigua se reutiliza primero');

const fifo = new ParticlePool();
// Tres emisiones con marcas reconocibles (posición única por partícula).
fifo.emit(111, 0, 0, 0, '#111111', 1, 1000);
fifo.emit(222, 0, 0, 0, '#222222', 1, 1000);
fifo.emit(333, 0, 0, 0, '#333333', 1, 1000);
assert(fifo.getActiveCount() === 3, 'tres partículas activas antes de saturar');

const positionsBefore = fifo.getParticles().map((p) => p.getX());
// Saturamos el resto del cupo (197 emisiones más) para forzar el ciclo.
// Marcas desde 400 para no colisionar con x=111/222/333 del sondeo FIFO.
for (let i = 0; i < 197; i++) fifo.emit(400 + i, 0, 0, 0, '#444444', 1, 1000);
assert(fifo.getActiveCount() === 200, 'pool saturado con 200 activas');

// La siguiente emisión debe sobrescribir la MÁS ANTIGUA (la de x=111).
fifo.emit(999, 0, 0, 0, '#555555', 1, 1000);
assert(fifo.getParticles().some((p) => p.getX() === 999), 'la nueva emisión queda registrada en el pool');
assert(!fifo.getParticles().some((p) => p.getX() === 111), 'la partícula más antigua (x=111) fue extinguida y reciclada');
assert(fifo.getParticles().some((p) => p.getX() === 222), 'la segunda más antigua (x=222) sobrevive (orden FIFO respetado)');
assert(fifo.getActiveCount() === 200, 'el contador activo permanece en 200 tras reciclar');

console.log('[4] Cinemática — integración, extinción y contador');

const kin = new ParticlePool();
kin.emit(0, 0, 10, 0, '#00bfff', 2, 1); // vx=10, vida 1s
kin.emit(50, 50, 0, 0, '#8b4513', 2, 1); // aceleración vertical por defecto 0

kin.updateAndRender(createFakeCtx(), 0.5); // medio segundo
const [pVelocity, pStatic] = kin.getParticles();
assert(Math.abs(pVelocity.getX() - 5) < 1e-9, 'x integra la velocidad (x = x + vx*dt = 5)');
assert(pVelocity.isAlive(), 'la partícula sigue viva con vida 0.5 restante');

kin.updateAndRender(createFakeCtx(), 0.5); // la vida llega a cero
assert(!pVelocity.isAlive(), 'la partícula se extingue al agotarse su tiempo de vida');
assert(!pStatic.isAlive(), 'la partícula estática también expira con el mismo dt acumulado');

kin.updateAndRender(createFakeCtx(), 0.1); // cuadro posterior a la extinción
assert(kin.getActiveCount() === 0, 'el contador activo desciende a 0 tras la extinción');

// Aceleración: emisión con ay > 0 mueve la velocidad (gravedad terrestre, Tarea 2.3).
const accel = new ParticlePool();
accel.emit(0, 0, 0, 0, '#8b4513', 2, 1, 0, 100); // ay=100 px/s^2
accel.updateAndRender(createFakeCtx(), 1);
const pAccel = accel.getParticles()[0];
assert(Math.abs(pAccel.getVy() - 100) < 1e-9, 'vy integra la aceleración (vy = vy + ay*dt = 100)');
assert(Math.abs(pAccel.getY() - 100) < 1e-9, 'y integra la velocidad ya acelerada (y = 0 + 100*1)');

// Alfa: se desvanece linealmente con la vida.
const fader = new ParticlePool();
fader.emit(0, 0, 0, 0, '#4169e1', 2, 2);
fader.updateAndRender(createFakeCtx(), 1); // vida restante 1 de 2
assert(Math.abs(fader.getParticles()[0].getAlpha() - 0.5) < 1e-9, 'alfa decae linealmente con la vida (0.5)');

console.log('[5] Render — dibujo de vivas y silencio de muertas');

const renderPool = new ParticlePool();
renderPool.emit(120, 80, 0, 0, '#ff4500', 4, 5);
const ctx = createFakeCtx();
renderPool.updateAndRender(ctx, 0.016);
const names = ctx.calls.map((c) => c.name);
assert(names.includes('beginPath') && names.includes('arc') && names.includes('fill'), 'una partícula viva dibuja su arco y lo rellena');
const arcCall = ctx.calls.find((c) => c.name === 'arc');
assert(arcCall.args[0] === 120 && arcCall.args[1] === 80 && arcCall.args[2] === 4, 'el arco usa x, y y el radio de la escala (4)');
assert(ctx.calls.some((c) => c.name === 'fillStyle' && c.args[0] === '#ff4500'), 'el color elemental se aplica como fillStyle');
assert(ctx.calls.some((c) => c.name === 'globalAlpha'), 'la alfa se aplica con globalAlpha (fundido)');
assert(ctx.calls.filter((c) => c.name === 'save').length >= 1 && ctx.calls.filter((c) => c.name === 'restore').length >= 1, 'el estado del contexto se guarda y restaura (sin contaminar el lienzo)');

const deadCount = renderPool.getActiveCount();
renderPool.updateAndRender(createFakeCtx(), 10); // dt enorme: extingue y no dibuja
assert(renderPool.getActiveCount() === 0, `tras la extinción el pool queda con 0 activas (antes ${deadCount})`);
const ctxDead = createFakeCtx();
renderPool.updateAndRender(ctxDead, 0.016);
assert(ctxDead.calls.filter((c) => c.name === 'arc').length === 0, 'una partícula muerta no toca el lienzo');

console.log('[6] Reset y coherencia de estadísticas');

const resetPool = new ParticlePool();
for (let i = 0; i < 250; i++) resetPool.emit(i, i, 1, 1, '#2e8b57', 2, 500);
assert(resetPool.getActiveCount() === 200, 'pool saturado antes del reset');
resetPool.reset();
assert(resetPool.getActiveCount() === 0, 'reset() devuelve el contador activo a 0');
assert(resetPool.getParticles().every((p) => !p.isAlive()), 'todas las partículas quedan extinguidas tras reset()');
assert(resetPool.getSize() === 200, 'reset() conserva el pool pre-instanciado (no reasigna)');

console.log('== RESUMEN ==');
console.log(`Asertos superados: ${passed}, fallidos: ${failed}`);
if (failed === 0) {
  console.log('RESULTADO: EXITO — Particle + ParticlePool listos para el motor (Tarea 2.1).');
  process.exit(0);
} else {
  console.log('RESULTADO: DENEGADO — hay asertos incumplidos.');
  process.exit(1);
}
