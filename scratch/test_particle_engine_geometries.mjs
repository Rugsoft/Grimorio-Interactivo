/**
 * test_particle_engine_geometries.mjs — Arnés TDD de la Tarea 2.2 (TASKS-05).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica, sobre `public/assets/js/utils/particleEngine.js`:
 *   [1] API: `emitSpell(geometry, origin, target, options)` existe y
 *       devuelve el número de partículas emitidas.
 *   [2] singleTarget/touch: vector directo del origen hacia el corazón
 *       del maniquí (ángulo de velocidad ≈ ángulo origen→objetivo).
 *   [3] cone: dispersión angular cónica dentro de θ₀ ± 25° con velocidad
 *       radial decreciente.
 *   [4] line: haz colimado de alta velocidad sobre el eje origen →
 *       blanco que atraviesa al maniquí y continúa de largo.
 *   [5] sphere: deflagración radial omnidireccional centrada en el blanco
 *       (posiciones en el centro, ángulos cubriendo [0, 2π)).
 *   [6] Contrato del pool: techo de 200 respetado ante ráfagas enormes;
 *       geometría desconocida degrada a proyectil directo sin emitir
 *       errores (corrector de impacto).
 *   [7] Vuelo balístico diferido (corrector de impacto): singleTarget,
 *       touch y line cruzan el blanco exactamente al agotar su vuelo
 *       puro, sin desvíos por gravedad ni parábola.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Canvas 2D nativo, cero dependencias.
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
  };
}

/** PRNG determinista (mulberry32) para reproducibilidad del arnés. */
function mulberry32(seed) {
  let a = seed >>> 0;
  return function () {
    a |= 0; a = (a + 0x6D2B79F5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

/** Normaliza un ángulo al intervalo [0, 2π). */
function normalizeAngle(angle) {
  return ((angle % (Math.PI * 2)) + Math.PI * 2) % (Math.PI * 2);
}

/** Diferencia angular mínima entre dos ángulos (en radianes). */
function angleDelta(a, b) {
  const diff = Math.abs(normalizeAngle(a) - normalizeAngle(b));
  return Math.min(diff, Math.PI * 2 - diff);
}

console.log('== ARNÉS TDD — Tarea 2.2: Geometrías cinemáticas de hechizo ==');

const engine = await import('../public/assets/js/utils/particleEngine.js');
const { ParticlePool } = engine;

const ORIGIN = { x: 100, y: 300 };
const TARGET = { x: 500, y: 200 }; // torso del maniquí

console.log('[1] API de emisión por geometría');

const probe = new ParticlePool();
assert(typeof probe.emitSpell === 'function', 'el pool expone emitSpell(geometry, origin, target, options)');

const emitted = probe.emitSpell('singleTarget', ORIGIN, TARGET, {
  color: '#ff4500', count: 12, speed: 250, size: 3, life: 2, random: mulberry32(1),
});
assert(emitted === 12, 'devuelve el número de partículas emitidas (12)');
assert(probe.getActiveCount() === 12, 'las 12 partículas quedan activas en el pool');

console.log('[2] singleTarget / touch — vector directo hacia el objetivo');

const baseAngle = Math.atan2(TARGET.y - ORIGIN.y, TARGET.x - ORIGIN.x);
{
  const pool = new ParticlePool();
  pool.emitSpell('singleTarget', ORIGIN, TARGET, {
    color: '#ffa500', count: 10, speed: 200, size: 3, life: 2, random: mulberry32(7),
  });
  const particles = pool.getParticles().filter((p) => p.isAlive());
  assert(particles.length === 10, '10 proyectiles activos');

  // La dirección de vuelo se mide integrando la velocidad (dt=1): el
  // desplazamiento neto debe apuntar al objetivo.
  const ctx = createFakeCtx();
  pool.updateAndRender(ctx, 1);
  let allAimAtTarget = true;
  for (const p of particles) {
    const travel = Math.atan2(p.getY() - ORIGIN.y, p.getX() - ORIGIN.x);
    // Tolerancia amplia: la parábola (gravedad) curva el vuelo, pero el
    // rumbo neto sigue hacia el blanco.
    if (angleDelta(travel, baseAngle) > Math.PI / 6) allAimAtTarget = false;
  }
  assert(allAimAtTarget, 'todos los proyectiles viajan hacia el corazón del maniquí (±30°)');

  // touch comparte la misma física de vector directo.
  const touch = new ParticlePool();
  touch.emitSpell('touch', ORIGIN, TARGET, { color: '#1e90ff', count: 8, life: 2, random: mulberry32(9) });
  assert(touch.getActiveCount() === 8, 'la geometría touch emite igualmente (alias de singleTarget)');
}

console.log('[3] cone — abanico angular θ ∈ [θ₀ − 25°, θ₀ + 25°]');

{
  const pool = new ParticlePool();
  pool.emitSpell('cone', ORIGIN, TARGET, {
    color: '#2e8b57', count: 30, speed: 220, size: 2, life: 2, random: mulberry32(21),
  });
  const particles = pool.getParticles().filter((p) => p.isAlive());
  assert(particles.length === 30, '30 partículas del abanico activas');

  const halfSpread = (25 * Math.PI) / 180; // ±25° en radianes
  const ctx = createFakeCtx();
  pool.updateAndRender(ctx, 1); // integramos para leer la dirección de vuelo

  let withinCone = true;
  const speeds = [];
  for (const p of particles) {
    const travel = Math.atan2(p.getY() - ORIGIN.y, p.getX() - ORIGIN.x);
    if (angleDelta(travel, baseAngle) > halfSpread + 1e-6) withinCone = false;
    speeds.push(Math.hypot(p.getX() - ORIGIN.x, p.getY() - ORIGIN.y));
  }
  assert(withinCone, 'todas las partículas vuelan dentro del cono ±25° respecto a θ₀');

  // Velocidad radial decreciente: los rangos de velocidad deben variar
  // (no todos los rayos van a la misma celeridad).
  const minSpeed = Math.min(...speeds);
  const maxSpeed = Math.max(...speeds);
  assert(maxSpeed - minSpeed > 20, `velocidad radial decreciente presente (rango ${minSpeed.toFixed(1)}–${maxSpeed.toFixed(1)} px)`);

  // Diversidad angular real: el abanico no degenera en un único rayo.
  const angles = particles.map((p) => normalizeAngle(Math.atan2(p.getY() - ORIGIN.y, p.getX() - ORIGIN.x)));
  const uniqueAngles = new Set(angles.map((a) => Math.round(a * 100)));
  assert(uniqueAngles.size > 5, `el abanico cubre múltiples direcciones (${uniqueAngles.size} ángulos distintos)`);
}

console.log('[4] line — haz colimado sobre el eje origen → blanco');

{
  const pool = new ParticlePool();
  pool.emitSpell('line', ORIGIN, TARGET, {
    color: '#00ffff', count: 16, speed: 600, size: 2, life: 1, random: mulberry32(33),
  });
  const particles = pool.getParticles().filter((p) => p.isAlive());
  assert(particles.length === 16, '16 partículas del haz activas');

  const beamAngle = Math.atan2(TARGET.y - ORIGIN.y, TARGET.x - ORIGIN.x);
  const ctx = createFakeCtx();
  pool.updateAndRender(ctx, 0.5);
  let alignedBeam = true;
  for (const p of particles) {
    const travel = Math.atan2(p.getY() - ORIGIN.y, p.getX() - ORIGIN.x);
    // Estela esencialmente paralela al rumbo: el haz atraviesa al maniquí.
    if (angleDelta(travel, beamAngle) > Math.PI / 12) alignedBeam = false; // ±15°
  }
  assert(alignedBeam, 'el haz viaja sobre el eje origen → blanco (dispersión < 15°)');

  // Alta velocidad: en 0.5 s deben recorrer una distancia notable.
  const minTravel = Math.min(...particles.map((p) => Math.abs(p.getX() - ORIGIN.x)));
  assert(minTravel > 150, `alta velocidad colimada (mín. ${minTravel.toFixed(1)} px en 0.5 s)`);
}

console.log('[5] sphere — deflagración radial centrada en el blanco');

{
  const pool = new ParticlePool();
  pool.emitSpell('sphere', ORIGIN, TARGET, {
    color: '#4169e1', count: 24, speed: 180, size: 3, life: 2, random: mulberry32(51),
  });
  const particles = pool.getParticles().filter((p) => p.isAlive());
  assert(particles.length === 24, '24 partículas de la detonación activas');

  // Nacen todas en el centro del blanco.
  const allAtCenter = particles.every((p) => Math.hypot(p.getX() - TARGET.x, p.getY() - TARGET.y) < 1e-6);
  assert(allAtCenter, 'la deflagración nace centrada en el maniquí');

  const ctx = createFakeCtx();
  pool.updateAndRender(ctx, 1);
  const angles = particles.map((p) => normalizeAngle(Math.atan2(p.getY() - TARGET.y, p.getX() - TARGET.x)));
  // Omnidireccional: dividimos el círculo en 8 octantes; la explosión
  // determinista (sembrada) debe tocar al menos 6.
  const octants = new Set(angles.map((a) => Math.floor(a / (Math.PI / 4))));
  assert(octants.size >= 6, `explosión omnidireccional (cubre ${octants.size} de 8 octantes)`);

  // Aceleración hacia el exterior: tras integrar, cada partícula se ha
  // alejado del centro (la velocidad radial crece con dt).
  const movedOutward = particles.every((p) => Math.hypot(p.getX() - TARGET.x, p.getY() - TARGET.y) > 10);
  assert(movedOutward, 'todas las esquirlas se alejan del blanco (aceleración exterior)');
}

console.log('[6] Contrato del pool: techo, validación y Dogma Vanilla');

{
  // Ráfaga desmesurada: el FIFO absorbe sin desbordar el cupo de 200.
  const pool = new ParticlePool();
  pool.emitSpell('sphere', ORIGIN, TARGET, {
    color: '#8a2be2', count: 500, life: 5, random: mulberry32(77),
  });
  assert(pool.getActiveCount() === 200, 'una ráfaga de 500 jamás supera el techo de 200 activas');
  assert(pool.getSize() === 200, 'el pool conserva su tamaño canónico');

  // Geometría desconocida (corrector de impacto): degrada a proyectil
  // directo sin lanzar — la vista jamás queda disuelta en silencio.
  const emittedDegraded = pool.emitSpell('meteorShower', ORIGIN, TARGET, { color: '#ffffff', count: 5, life: 1, random: mulberry32(79) });
  assert(emittedDegraded === 5, 'una geometría desconocida degrada a proyectil directo (5 emisiones)');

  // Las partículas emitidas viven con el color elemental pedido.
  const colored = new ParticlePool();
  colored.emitSpell('singleTarget', ORIGIN, TARGET, { color: '#d2b48c', count: 5, life: 2, random: mulberry32(91) });
  assert(colored.getParticles().every((p) => !p.isAlive() || p.color === '#d2b48c'), 'las partículas portan el color elemental indicado');
}

console.log('[7] Vuelo balístico diferido: el proyectil cruza el blanco (corrector de impacto)');

{
  // El proyectil singleTarget con gravedad de parábola DIFERIDA debe
  // cruzar el blanco exactamente al agotar su vuelo puro.
  const originT7 = { x: 0, y: 200 };
  const pool = new ParticlePool();
  pool.emitSpell('singleTarget', originT7, TARGET, {
    color: '#ffa500', count: 8, speed: 200, size: 3, life: 5, random: mulberry32(97),
  });
  const particles = pool.getParticles().filter((p) => p.isAlive());
  assert(particles.every((p) => p.getDeferral() > 0), 'todo proyectil porta su vuelo balístico diferido');

  // Integra aproximándose al blanco (distancia nominal 500 px / velocidad ~200 px/s ≈ 2.5 s).
  const ctx = createFakeCtx();
  pool.updateAndRender(ctx, 1.8);
  const midFlight = particles.map((p) => Math.hypot(p.getX() - TARGET.x, p.getY() - TARGET.y));

  pool.updateAndRender(ctx, 0.6); // t=2.4s: alcanza la vecindad inmediata del blanco
  const beforeCross = particles.map((p) => Math.hypot(p.getX() - TARGET.x, p.getY() - TARGET.y));
  assert(Math.max(...beforeCross) < 60, `antes del cruce todos vuelan pegados al rumbo (máx. ${Math.max(...beforeCross).toFixed(1)} px del blanco)`);
  assert(beforeCross.every((d, i) => d < midFlight[i]), 'el proyectil AVANZA hacia el blanco en vuelo puro');

  // Tras cruzar (t > 2.5s), estallan los moduladores diferidos y la
  // gravedad de parábola toma el mando curvando la estela hacia abajo.
  pool.updateAndRender(ctx, 0.8);
  const past = particles.map((p) => p.getY());
  assert(Math.max(...past) > TARGET.y + 20, 'post-cruce la gravedad diferida curva la estela (identidad visual conservada)');
}

console.log('== RESUMEN ==');
console.log(`Asertos superados: ${passed}, fallidos: ${failed}`);
if (failed === 0) {
  console.log('RESULTADO: EXITO — Geometrías cinemáticas listas (Tarea 2.2).');
  process.exit(0);
} else {
  console.log('RESULTADO: DENEGADO — hay asertos incumplidos.');
  process.exit(1);
}
