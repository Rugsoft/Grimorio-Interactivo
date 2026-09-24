/**
 * test_particle_engine_elements.mjs — Arnés TDD de la Tarea 2.3 (TASKS-05).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica, sobre `public/assets/js/utils/particleEngine.js`:
 *   [1] API: perfiles elementales exportados y validación estricta
 *       (elemento o círculo desconocido → RangeError sin emitir nada).
 *   [2] Paletas: las 8 afinidades portan exactamente los 3 colores
 *       canónicos del plan 3.1 y las partículas emiten solo esos tonos.
 *   [3] Física distintiva (contra línea base neutra de igual semilla):
 *       fuego asciende, tierra cae, oscuridad succiona, viento gira
 *       helicoidal, agua ondea lateral, rayo estremece (varianza alta),
 *       luz acelera recta y arcano puro orbita.
 *   [4] Escalado por Círculo (RF-03.3): un Círculo V emite más volumen
 *       (densidad) y mayor brillo/escala que un Círculo I.
 *   [5] Contrato: el techo de 200 prevalece ante Círculo V desatado y
 *       las geometrías de la Tarea 2.2 siguen operando por elemento.
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

/** PRNG determinista (mulberry32). */
function mulberry32(seed) {
  let a = seed >>> 0;
  return function () {
    a |= 0; a = (a + 0x6D2B79F5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

const ORIGIN = { x: 100, y: 300 };
const TARGET = { x: 500, y: 200 };

/** Emite una esfera elemental e integra `dt` segundos; devuelve las vivas. */
function castElementalBurst(engine, element, seed, { count = 40, dt = 1, circle, geometry = 'sphere' } = {}) {
  const pool = new engine.ParticlePool();
  pool.emitSpell(geometry, ORIGIN, TARGET, {
    color: '#ffffff', count, speed: 200, size: 3, life: 5,
    element, circle, random: mulberry32(seed),
  });
  pool.updateAndRender(createFakeCtx(), dt);
  return pool.getParticles().filter((p) => p.isAlive());
}

/** Media de una magnitud sobre las partículas. */
function mean(values) {
  return values.reduce((sum, v) => sum + v, 0) / values.length;
}

console.log('== ARNÉS TDD — Tarea 2.3: Perfiles elementales y Círculos ==');

const engine = await import('../public/assets/js/utils/particleEngine.js');
const { ParticlePool, ELEMENTAL_PROFILES, CIRCLE_DENSITY_SCALE, CIRCLE_BRIGHTNESS_SCALE } = engine;

const ALL_ELEMENTS = ['fire', 'water', 'lightning', 'earth', 'wind', 'light', 'darkness', 'pureArcane'];

const EXPECTED_PALETTES = {
  fire: ['#ff4500', '#ffa500', '#ffffff'],
  water: ['#00bfff', '#1e90ff', '#e0ffff'],
  lightning: ['#9932cc', '#00ffff', '#ffffff'],
  earth: ['#8b4513', '#d2b48c', '#ffd700'],
  wind: ['#2e8b57', '#66cdaa', '#e0eee0'],
  light: ['#ffd700', '#fffaf0', '#ffffff'],
  darkness: ['#4b0082', '#1c1c1c', '#8a2be2'],
  pureArcane: ['#4169e1', '#7b68ee', '#f0f8ff'],
};

console.log('[1] API elemental y validación estricta');

assert(typeof ELEMENTAL_PROFILES === 'object' && ELEMENTAL_PROFILES !== null, 'el módulo exporta ELEMENTAL_PROFILES');
assert(ALL_ELEMENTS.every((k) => typeof ELEMENTAL_PROFILES[k] === 'object'), 'las 8 afinidades elementales están definidas');

const probe = new ParticlePool();
let threwElement = false;
try {
  probe.emitSpell('sphere', ORIGIN, TARGET, { count: 5, life: 1, element: 'plasma' });
} catch (error) { threwElement = error instanceof RangeError; }
assert(threwElement, 'un elemento desconocido lanza RangeError');

let threwCircle = false;
try {
  probe.emitSpell('sphere', ORIGIN, TARGET, { count: 5, life: 1, circle: 7 });
} catch (error) { threwCircle = error instanceof RangeError; }
assert(threwCircle, 'un círculo fuera del canon (7) lanza RangeError');
assert(probe.getActiveCount() === 0, 'ninguna emisión fantasma con parámetros inválidos');

console.log('[2] Paletas canónicas del plan 3.1');

for (const element of ALL_ELEMENTS) {
  const palette = ELEMENTAL_PROFILES[element].palette;
  const exact = EXPECTED_PALETTES[element].every((c) => palette.includes(c)) && palette.length === 3;
  assert(exact, `paleta de ${element}: ${palette.join(', ')}`);

  const particles = castElementalBurst(engine, element, 5, { count: 12 });
  const inPalette = particles.every((p) => palette.includes(p.color));
  assert(inPalette, `las partículas de ${element} emiten solo tonos de su paleta`);
}

console.log('[3] Física distintiva por afinidad (vs. línea base neutra)');

// Línea base: esfera neutra con la misma semilla — la deflagración radial
// es simétrica, así que sus medias verticales y distancias son de referencia.
const neutral = castElementalBurst(engine, undefined, 11, { count: 40 });

{
  // FUEGO: gravedad negativa — ascienden (media de y por debajo del blanco).
  const fire = castElementalBurst(engine, 'fire', 11, { count: 40 });
  const neutralMeanY = mean(neutral.map((p) => p.getY()));
  const fireMeanY = mean(fire.map((p) => p.getY()));
  assert(fireMeanY < TARGET.y - 30, `ascuas ascendentes (media y = ${fireMeanY.toFixed(1)} < ${TARGET.y})`);
  assert(fireMeanY < neutralMeanY, 'el fuego sube más que la explosión neutra');
}

{
  // TIERRA: gravedad pronunciada — caen con fuerza (media de y muy por debajo).
  const earth = castElementalBurst(engine, 'earth', 11, { count: 40 });
  const neutralMeanY = mean(neutral.map((p) => p.getY()));
  const earthMeanY = mean(earth.map((p) => p.getY()));
  assert(earthMeanY > TARGET.y + 80, `esquirlas con gravedad pesada (media y = ${earthMeanY.toFixed(1)})`);
  assert(earthMeanY > neutralMeanY + 60, 'la tierra cae más que la línea base');
}

{
  // OSCURIDAD: succión centrípeta — la aceleración frena la expansión
  // radial y reúne las partículas más cerca del núcleo que la base neutra.
  const darkness = castElementalBurst(engine, 'darkness', 11, { count: 40 });
  const dist = (p) => Math.hypot(p.getX() - TARGET.x, p.getY() - TARGET.y);
  const neutralMeanDist = mean(neutral.map(dist));
  const darkMeanDist = mean(darkness.map(dist));
  assert(darkMeanDist < neutralMeanDist, `zarcillos centrípetos (media radial ${darkMeanDist.toFixed(1)} < ${neutralMeanDist.toFixed(1)})`);
}

{
  // VIENTO: vórtice helicoidal — rotación angular consistente alrededor
  // del blanco (el ángulo polar medio gira de forma apreciable).
  const wind = castElementalBurst(engine, 'wind', 11, { count: 40 });
  const angleOf = (p) => Math.atan2(p.getY() - TARGET.y, p.getX() - TARGET.x);
  // La rotación media no puede anularse por simetría: tomamos el cambio
  // angular medio absoluto por partícula respecto a su ángulo inicial
  // (que para la esfera sembrada es reproducible con la misma semilla).
  const neutralAngles = neutral.map(angleOf);
  const windAngles = wind.map(angleOf);
  const rotations = windAngles.map((a, i) => Math.abs(a - neutralAngles[i]));
  const meanRotation = mean(rotations);
  assert(meanRotation > 0.08, `vórtice helicoidal (rotación angular media = ${meanRotation.toFixed(3)} rad)`);
}

{
  // AGUA: ondas fluidas — desvío lateral respecto a la línea base.
  // Con la misma semilla, la partícula i del agua comparte geometría con
  // la i neutra: la onda añade un impulso perpendicular que las separa.
  const water = castElementalBurst(engine, 'water', 11, { count: 40 });
  const deviations = water.map((p, i) =>
    Math.hypot(p.getX() - neutral[i].getX(), p.getY() - neutral[i].getY())
  );
  assert(mean(deviations) > 30, `ondas sinusoidales laterales (desvío medio vs base = ${mean(deviations).toFixed(1)} px)`);
}

{
  // RAYO: estremece — la varianza de celeridades se dispara por los
  // impulsos fractales aleatorios de los arcos de plasma.
  const lightning = castElementalBurst(engine, 'lightning', 11, { count: 40 });
  const speeds = lightning.map((p) => Math.hypot(p.getX() - TARGET.x, p.getY() - TARGET.y));
  const m = mean(speeds);
  const variance = mean(speeds.map((s) => (s - m) ** 2));
  const neutralSpeeds = neutral.map((p) => Math.hypot(p.getX() - TARGET.x, p.getY() - TARGET.y));
  const nm = mean(neutralSpeeds);
  const neutralVariance = mean(neutralSpeeds.map((s) => (s - nm) ** 2));
  assert(variance > neutralVariance * 2, `arcos de plasma estremecidos (varianza ${variance.toFixed(0)} vs ${neutralVariance.toFixed(0)})`);
}

{
  // LUZ: haces prismáticos de alta celeridad — más velocidad neta que la base.
  const light = castElementalBurst(engine, 'light', 11, { count: 40 });
  const lightMeanDist = mean(light.map((p) => Math.hypot(p.getX() - TARGET.x, p.getY() - TARGET.y)));
  const neutralMeanDist = mean(neutral.map((p) => Math.hypot(p.getX() - TARGET.x, p.getY() - TARGET.y)));
  assert(lightMeanDist > neutralMeanDist * 1.15, `alta celeridad lumínica (${lightMeanDist.toFixed(1)} vs ${neutralMeanDist.toFixed(1)})`);
}

{
  // ARCANO PURO: constelaciones orbitantes — giro helicoidal suave y
  // sostenido alrededor del núcleo (componente tangencial viva).
  const arcane = castElementalBurst(engine, 'pureArcane', 11, { count: 40 });
  const angleOf = (p) => Math.atan2(p.getY() - TARGET.y, p.getX() - TARGET.x);
  const neutralAngles = neutral.map(angleOf);
  const rotations = arcane.map((p, i) => Math.abs(angleOf(p) - neutralAngles[i]));
  assert(mean(rotations) > 0.04, `glifos orbitantes (rotación media = ${mean(rotations).toFixed(3)} rad)`);
}

console.log('[4] Escalado por Círculo Arcano (RF-03.3)');

{
  // Densidad: el Círculo V emite claramente más partículas que el I.
  const circleOne = new ParticlePool();
  const emittedOne = circleOne.emitSpell('sphere', ORIGIN, TARGET, {
    color: '#ffffff', count: 10, life: 5, circle: 1, random: mulberry32(3),
  });
  const circleFive = new ParticlePool();
  const emittedFive = circleFive.emitSpell('sphere', ORIGIN, TARGET, {
    color: '#ffffff', count: 10, life: 5, circle: 5, random: mulberry32(3),
  });
  assert(emittedOne === 10, `Círculo I conserva el volumen base (${emittedOne})`);
  assert(emittedFive > emittedOne * 2, `Círculo V desata mayor volumen (${emittedFive} > ${emittedOne * 2})`);

  // Escala y monotonicidad del canon de densidad.
  const densities = [1, 2, 3, 4, 5].map((c) => CIRCLE_DENSITY_SCALE[c]);
  assert(densities.every((d, i) => i === 0 || d > densities[i - 1]), 'la densidad crece monótonamente del Círculo I al V');
  assert(CIRCLE_DENSITY_SCALE[1] === 1, 'el Círculo I parte de la escala neutra 1.0');

  // Brillo: el Círculo V dibuja partículas de mayor radio (escala).
  const brightFive = new ParticlePool();
  brightFive.emitSpell('sphere', ORIGIN, TARGET, {
    color: '#ffffff', count: 4, life: 5, size: 3, circle: 5, random: mulberry32(13),
  });
  const ctxFive = createFakeCtx();
  brightFive.updateAndRender(ctxFive, 0.016);
  const radiusFive = Math.max(...ctxFive.calls.filter((c) => c.name === 'arc').map((c) => c.args[2]));
  assert(radiusFive > 3, `saturación lumínica del Círculo V (radio ${radiusFive.toFixed(2)} > base 3)`);
  assert(typeof CIRCLE_BRIGHTNESS_SCALE[5] === 'number' && CIRCLE_BRIGHTNESS_SCALE[5] > 1, 'canon de brillo definido con Círculo V > 1');
}

console.log('[5] Contrato: techo FIFO y combinación con geometrías');

{
  // Círculo V desatado contra el techo: la densidad jamás rompe el cupo.
  const pool = new ParticlePool();
  pool.emitSpell('cone', ORIGIN, TARGET, {
    color: '#ff4500', count: 120, life: 5, circle: 5, element: 'fire', random: mulberry32(29),
  });
  assert(pool.getActiveCount() === 200, 'un cono ígneo de Círculo V se recorta al techo FIFO de 200');

  // Las geometrías de la Tarea 2.2 siguen intactas por elemento.
  const spear = new ParticlePool();
  spear.emitSpell('singleTarget', ORIGIN, TARGET, {
    color: '#8b4513', count: 6, life: 5, element: 'earth', random: mulberry32(31),
  });
  assert(spear.getActiveCount() === 6, 'proyectil telúrico sobre singleTarget opera con normalidad');
}

console.log('[6] Normalización de afinidades huérfanas (corrector de impacto)');

{
  // Alias del canon: variantes léxicas e históricas del catálogo emiten
  // con el perfil del elemento canónico (paleta del canon, no neutra).
  const ALIASES = { air: 'wind', shadow: 'darkness', arcane: 'pureArcane', ice: 'water' };
  for (const [alias, canonical] of Object.entries(ALIASES)) {
    const pool = new ParticlePool();
    let threwAlias = false;
    try {
      pool.emitSpell('sphere', ORIGIN, TARGET, {
        color: '#ffffff', count: 6, life: 5, element: alias, random: mulberry32(7),
      });
    } catch { threwAlias = true; }
    assert(threwAlias === false, `la afinidad histórica '${alias}' emite sin RangeError`);
    assert(pool.getActiveCount() === 6, `'${alias}' emite con el perfil de '${canonical}'`);

    const emittedColors = pool.getParticles()
      .filter((p) => p.isAlive())
      .map((p) => p.color);
    const canonicalPalette = ELEMENTAL_PROFILES[canonical].palette;
    assert(emittedColors.every((c) => canonicalPalette.includes(c)), `'${alias}' usa la paleta canónica de '${canonical}'`);
  }

  // Degradación neutra: 'none' y vacía emiten maná neutro (color pedido).
  for (const orphan of ['none', '']) {
    const pool = new ParticlePool();
    let threwOrphan = false;
    try {
      pool.emitSpell('sphere', ORIGIN, TARGET, {
        color: '#f0f8ff', count: 6, life: 5, element: orphan, random: mulberry32(9),
      });
    } catch { threwOrphan = true; }
    assert(threwOrphan === false, `la afinidad '${orphan === '' ? 'vacía' : orphan}' emite sin RangeError (maná neutro)`);
    assert(pool.getActiveCount() === 6, `'${orphan === '' ? 'vacía' : orphan}' emite el volumen pedido`);
    const neutralColors = pool.getParticles()
      .filter((p) => p.isAlive())
      .map((p) => p.color);
    assert(neutralColors.every((c) => c === '#f0f8ff'), `emisión neutra de '${orphan === '' ? 'vacía' : orphan}' respeta el color pedido`);
  }

  // Desconocida genuina: sigue blindada con RangeError y cero emisión.
  const unknownPool = new ParticlePool();
  let threwUnknown = false;
  try {
    unknownPool.emitSpell('sphere', ORIGIN, TARGET, { count: 6, life: 5, element: 'plasma' });
  } catch (error) { threwUnknown = error instanceof RangeError; }
  assert(threwUnknown, 'una afinidad genuinamente desconocida sigue lanzando RangeError');
  assert(unknownPool.getActiveCount() === 0, 'sin emisión fantasma ante afinidad desconocida');
}

console.log('== RESUMEN ==');
console.log(`Asertos superados: ${passed}, fallidos: ${failed}`);
if (failed === 0) {
  console.log('RESULTADO: EXITO — Perfiles elementales y Círculos listos (Tarea 2.3).');
  process.exit(0);
} else {
  console.log('RESULTADO: DENEGADO — hay asertos incumplidos.');
  process.exit(1);
}
