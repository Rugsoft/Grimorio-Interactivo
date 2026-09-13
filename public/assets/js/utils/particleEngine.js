/**
 * particleEngine.js — Motor de partículas mágicas del Simulador de Grimorio.
 *
 * Tarea 2.1 (TASKS-05): estructura de partícula (`Particle`) y Buffer
 * Circular FIFO (`ParticlePool`) con un techo estricto de 200 partículas
 * pre-instanciadas y reciclado circular continuo.
 *
 * Las Tareas 2.2 y 2.3 ampliaron este módulo con `emitSpell(geometry, ...)`
 * (trayectorias por geometría) y los perfiles elementales con escalado
 * por Círculo Arcano.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Canvas 2D nativo; cero librerías o CDNs.
 *   - Artículo V: identificadores en inglés camelCase, comentarios en
 *     castellano.
 *
 * Cobertura: RF-03.1 (elementos), RF-03.2 (geometrías), RF-03.3 (Círculos),
 * RF-03.4 (techo de 200 + FIFO), RNF-01 (60 FPS sin picos de GC —
 * instanciación única en el arranque), RNF-02 (emisión síncrona < 100 ms),
 * RNF-05 (capacidades nativas).
 */

/** Techo canónico de partículas activas simultáneas (RF-03.4). */
export const MAX_PARTICLES = 200;

/** Semidispersión angular del cono: ±25° en radianes (RF-03.2). */
export const CONE_HALF_SPREAD = (25 * Math.PI) / 180;

/**
 * Escalado de densidad (volumen de partículas) por Círculo Arcano
 * (RF-03.3): emanaciones sutiles en el Círculo I, saturación plena en el V.
 */
export const CIRCLE_DENSITY_SCALE = { 1: 1, 2: 1.4, 3: 1.8, 4: 2.4, 5: 3.2 };

/**
 * Escalado de brillo/escala visual por Círculo Arcano (RF-03.3): el radio
 * de las partículas crece con el poder del conjuro.
 */
export const CIRCLE_BRIGHTNESS_SCALE = { 1: 1, 2: 1.15, 3: 1.3, 4: 1.5, 5: 1.75 };

/**
 * Partícula mágica individual con cinemática simple (cinemática de
 * velocidad + aceleración) y fundido lineal por tiempo de vida.
 * Sus métodos mutan estado interno: el pool la reutiliza sin crear nuevas.
 */
export class Particle {
  constructor() {
    this.reset();
  }

  /**
   * Restablece la partícula a su estado apagado (sin asignar objetos
   * nuevos: solo primitivos reutilizables).
   */
  reset() {
    this.x = 0;
    this.y = 0;
    this.vx = 0;
    this.vy = 0;
    this.ax = 0;
    this.ay = 0;
    this.scale = 1;
    this.alpha = 1;
    this.color = '#ffffff';
    this.life = 0;
    this.maxLife = 0;
    this.alive = false;
  }

  /**
   * Enciende la partícula en una posición con vector, color, escala y vida.
   * Firma extendida con aceleración (ax/ay) para las geometrías y
   * afinidades de las Tareas 2.2/2.3 (gravedad, vórtices, succión).
   */
  init(x, y, vx, vy, color, size, life, ax = 0, ay = 0) {
    this.x = x;
    this.y = y;
    this.vx = vx;
    this.vy = vy;
    this.ax = ax;
    this.ay = ay;
    this.scale = size;
    this.alpha = 1;
    this.color = color;
    this.life = life;
    this.maxLife = life;
    this.alive = true;
  }

  /** ¿Sigue viva dentro de su tiempo de vida? */
  isAlive() {
    return this.alive;
  }

  /** Coordenada X actual (solo lectura para los arneses). */
  getX() {
    return this.x;
  }

  /** Coordenada Y actual (solo lectura para los arneses). */
  getY() {
    return this.y;
  }

  /** Velocidad vertical actual (solo lectura para los arneses). */
  getVy() {
    return this.vy;
  }

  /** Alfa actual (solo lectura para los arneses). */
  getAlpha() {
    return this.alpha;
  }

  /**
   * Integra la cinemática durante `dt` segundos y calcula el fundido.
   * Al agotarse la vida, la partícula se apaga.
   */
  update(dt) {
    if (!this.alive) {
      return;
    }
    // Aceleración primero: la velocidad del cuadro ya la incluye.
    this.vx += this.ax * dt;
    this.vy += this.ay * dt;
    this.x += this.vx * dt;
    this.y += this.vy * dt;

    this.life -= dt;
    if (this.life <= 0) {
      this.alive = false;
      return;
    }
    // Fundido lineal: alfa proporcional a la vida restante.
    this.alpha = this.life / this.maxLife;
  }

  /**
   * Dibuja la partícula viva en el contexto Canvas 2D (círculo con su
   * color elemental y alfa de fundido). Las muertas jamás tocan el lienzo.
   */
  draw(ctx) {
    if (!this.alive) {
      return;
    }
    ctx.save();
    ctx.globalAlpha = this.alpha;
    ctx.fillStyle = this.color;
    ctx.beginPath();
    ctx.arc(this.x, this.y, this.scale, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();
  }
}

/**
 * Perfiles elementales (RF-03.1, plan 3.1): paleta canónica exacta más
 * moduladores de cinemática aplicados en la emisión:
 *   - gravity: aceleración vertical constante (negativa = ascienden).
 *   - drag: fricción inicial de la velocidad (aire caliente,agua densa).
 *   - swirl: aceleración tangencial (vórtice helicoidal / órbita).
 *   - suction: aceleración centrípeta hacia el núcleo del blanco.
 *   - lateralWave: impulso perpendicular sinusoidal (ondas fluidas).
 *   - fracture: impulso fractal instantáneo aleatorio (arcos de plasma).
 *   - boost: multiplicador de celeridad (haces prismáticos de luz).
 */
export const ELEMENTAL_PROFILES = {
  fire: {
    palette: ['#ff4500', '#ffa500', '#ffffff'],
    gravity: -120,
    drag: 0.2,
    swirl: 0,
    suction: 0,
    lateralWave: 0,
    fracture: 0,
    boost: 1,
  },
  water: {
    palette: ['#00bfff', '#1e90ff', '#e0ffff'],
    gravity: 30,
    drag: 0.1,
    swirl: 0,
    suction: 0,
    lateralWave: 110,
    fracture: 0,
    boost: 1,
  },
  lightning: {
    palette: ['#9932cc', '#00ffff', '#ffffff'],
    gravity: 0,
    drag: 0,
    swirl: 0,
    suction: 0,
    lateralWave: 0,
    fracture: 340,
    boost: 1,
  },
  earth: {
    palette: ['#8b4513', '#d2b48c', '#ffd700'],
    gravity: 280,
    drag: 0,
    swirl: 0,
    suction: 0,
    lateralWave: 0,
    fracture: 0,
    boost: 1,
  },
  wind: {
    palette: ['#2e8b57', '#66cdaa', '#e0eee0'],
    gravity: -20,
    drag: 0,
    swirl: 55,
    suction: 0,
    lateralWave: 0,
    fracture: 0,
    boost: 1,
  },
  light: {
    palette: ['#ffd700', '#fffaf0', '#ffffff'],
    gravity: 0,
    drag: 0,
    swirl: 0,
    suction: 0,
    lateralWave: 0,
    fracture: 0,
    boost: 1.35,
  },
  darkness: {
    palette: ['#4b0082', '#1c1c1c', '#8a2be2'],
    gravity: 0,
    drag: 0,
    swirl: 30,
    suction: 150,
    lateralWave: 0,
    fracture: 0,
    boost: 1,
  },
  pureArcane: {
    palette: ['#4169e1', '#7b68ee', '#f0f8ff'],
    gravity: 0,
    drag: 0,
    swirl: 40,
    suction: 0,
    lateralWave: 0,
    fracture: 0,
    boost: 1,
  },
};

/**
 * Partícula mágica individual con cinemática simple (cinemática de
  return ((angle % (Math.PI * 2)) + Math.PI * 2) % (Math.PI * 2);
}

/**
 * PRNG determinista (mulberry32). Permite arneses reproducibles y evita
 * depender de Math.random en los tests (el motor acepta un generador
 * externo y degrada a Math.random en producción).
 */
function createDefaultRandom() {
  return Math.random;
}

/**
 * Perfiles de emisión por geometría de hechizo (RF-03.2). Cada perfil es
 * una función pura que, con el PRNG y las opciones, produce la lista de
 * partículas crudas (posición, velocidad, aceleración) a entregar al pool.
 *
 *   - singleTarget / touch: proyectil directo (con leve parábola por
 *     gravedad suave) desde el origen hacia el corazón del maniquí.
 *   - cone: abanico angular θ ∈ [θ₀ − 25°, θ₀ + 25°] con velocidad radial
 *     decreciente (más ancho el ángulo, más débil el impulso).
 *   - line: haz colimado horizontal de alta velocidad con estela densa.
 *   - sphere: deflagración radial omnidireccional centrada en el blanco
 *     con aceleración hacia el exterior.
 */
export const SPELL_GEOMETRIES = {
  singleTarget(random, origin, target, options) {
    return buildProjectileBurst(random, origin, target, options, { parabolic: true });
  },
  touch(random, origin, target, options) {
    return buildProjectileBurst(random, origin, target, options, { parabolic: false });
  },
  cone(random, origin, target, options) {
    const particles = [];
    const baseAngle = Math.atan2(target.y - origin.y, target.x - origin.x);
    for (let i = 0; i < options.count; i++) {
      // Dispersión uniforme dentro del abanico θ₀ ± 25°.
      const spread = (random() * 2 - 1) * CONE_HALF_SPREAD;
      const angle = baseAngle + spread;
      // Velocidad radial decreciente: cuanto más abierto el ángulo,
      // más débil el impulso (hasta 60% de pérdida en el borde).
      const falloff = 1 - (Math.abs(spread) / CONE_HALF_SPREAD) * 0.6;
      const speed = options.speed * (0.7 + random() * 0.3) * falloff;
      particles.push({
        x: origin.x,
        y: origin.y,
        vx: Math.cos(angle) * speed,
        vy: Math.sin(angle) * speed,
        ax: 0,
        ay: 0,
      });
    }
    return particles;
  },
  line(random, origin, target, options) {
    const particles = [];
    // El haz cruza transversalmente en horizontal (RF-03.2): se emite en
    // la dirección del objetivo proyectada al eje X, con mínima estela.
    const direction = target.x >= origin.x ? 1 : -1;
    for (let i = 0; i < options.count; i++) {
      const speed = options.speed * (0.85 + random() * 0.15);
      particles.push({
        x: origin.x + (random() - 0.5) * 8, // núcleo colimado estrecho
        y: origin.y + (random() - 0.5) * 8,
        vx: direction * speed,
        vy: (random() - 0.5) * options.speed * 0.08, // estela vertical mínima
        ax: 0,
        ay: 0,
      });
    }
    return particles;
  },
  sphere(random, origin, target, options) {
    const particles = [];
    const centerX = target.x;
    const centerY = target.y;
    for (let i = 0; i < options.count; i++) {
      const angle = random() * Math.PI * 2; // θ ∈ [0, 2π) omnidireccional
      const speed = options.speed * (0.6 + random() * 0.4);
      particles.push({
        x: centerX,
        y: centerY,
        vx: Math.cos(angle) * speed,
        vy: Math.sin(angle) * speed,
        ax: Math.cos(angle) * options.speed * 0.8, // aceleración exterior
        ay: Math.sin(angle) * options.speed * 0.8,
      });
    }
    return particles;
  },
};

/**
 * Ráfaga de proyectiles directos hacia el blanco. Con `parabolic` añade
 * una gravedad suave que curva el vuelo sin desviar el rumbo neto.
 */
function buildProjectileBurst(random, origin, target, options, { parabolic }) {
  const particles = [];
  const baseAngle = Math.atan2(target.y - origin.y, target.x - origin.x);
  for (let i = 0; i < options.count; i++) {
    const speed = options.speed * (0.9 + random() * 0.2);
    const jitter = (random() - 0.5) * 0.06; // mínima dispersión de foco
    const angle = baseAngle + jitter;
    particles.push({
      x: origin.x,
      y: origin.y,
      vx: Math.cos(angle) * speed,
      vy: Math.sin(angle) * speed,
      ax: 0,
      ay: parabolic ? 60 : 0, // gravedad suave de parábola (px/s²)
    });
  }
  return particles;
}

/**
 * Buffer Circular FIFO (`ParticlePool`) — Object Pool de exactamente 200
 * partículas pre-instanciadas en el arranque. Las emisiones sobrescriben
 * la partícula más antigua (cabeza circular), de modo que en pleno bucle
 * de renderizado no ocurre ninguna asignación `new Particle()` (RNF-01).
 */
export class ParticlePool {
  constructor(maxParticles = MAX_PARTICLES) {
    this.maxParticles = maxParticles;
    // Instanciación única: el array y sus 200 partículas nacen aquí y
    // conservan identidad durante toda la sesión (cero GC en el bucle).
    this.pool = new Array(maxParticles);
    for (let i = 0; i < maxParticles; i++) {
      this.pool[i] = new Particle();
    }
    this.headIndex = 0;
    this.activeCount = 0;
  }

  /** Número total de huecos del pool (techo canónico: 200). */
  getSize() {
    return this.maxParticles;
  }

  /** Partículas activas simultáneas en este instante. */
  getActiveCount() {
    return this.activeCount;
  }

  /** Referencia directa al array interno (solo lectura en arneses). */
  getParticles() {
    return this.pool;
  }

  /**
   * Emite una partícula reutilizando el hueco circular más antiguo.
   * SI el pool está saturado, ENTONCES la sobrescritura FIFO fuerza la
   * extinción inmediata de la partícula más antigua (RF-03.4).
   */
  emit(x, y, vx, vy, color, size, life, ax = 0, ay = 0) {
    const particle = this.pool[this.headIndex];
    particle.init(x, y, vx, vy, color, size, life, ax, ay);
    this.headIndex = (this.headIndex + 1) % this.maxParticles;
    if (this.activeCount < this.maxParticles) {
      this.activeCount++;
    }
  }

  /**
   * Integra y dibuja todas las partículas vivas del pool durante `dt`
   * segundos. El recuento activo desciende cuando una partícula expira.
   */
  updateAndRender(ctx, dt) {
    for (let i = 0; i < this.maxParticles; i++) {
      const particle = this.pool[i];
      if (particle.alive) {
        particle.update(dt);
        if (!particle.alive && this.activeCount > 0) {
          this.activeCount--;
        }
        particle.draw(ctx);
      }
    }
  }

  /**
   * Apaga todas las partículas conservando el pool pre-instanciado
   * (usado al cambiar de página del grimorio o al restaurar el maniquí).
   */
  reset() {
    for (let i = 0; i < this.maxParticles; i++) {
      this.pool[i].reset();
    }
    this.headIndex = 0;
    this.activeCount = 0;
  }

  /**
   * Desata la dispersión geométrica de un conjuro (RF-03.2, Tarea 2.2)
   * modulada por afinidad elemental y Círculo Arcano (RF-03.1/03.3, Tarea 2.3).
   *
   * @param {string} geometry - `singleTarget`, `touch`, `cone`, `line` o
   *   `sphere`; cualquier otra clave lanza RangeError sin emitir nada.
 * @param {{x: number, y: number}} origin - punto de lanzamiento.
   * @param {{x: number, y: number}} target - corazón del maniquí.
   * @param {object} [options] - `count` (nº base de partículas), `speed`,
   *   `size`, `life` (s), `color` (solo sin elemento), `element` (una de
   *   las 8 afinidades), `circle` (1-5) y `random` (PRNG inyectable).
   * @returns {number} partículas emitidas en esta invocación.
   */
  emitSpell(geometry, origin, target, options = {}) {
    const profile = SPELL_GEOMETRIES[geometry];
    if (typeof profile !== 'function') {
      throw new RangeError(`Geometría de hechizo desconocida: ${geometry}`);
    }
    // Validación elemental temprana (antes de emitir nada).
    let elementProfile = null;
    if (options.element !== undefined) {
      elementProfile = ELEMENTAL_PROFILES[options.element];
      if (!elementProfile) {
        throw new RangeError(`Afinidad elemental desconocida: ${options.element}`);
      }
    }
    if (options.circle !== undefined) {
      if (!Number.isInteger(options.circle) || options.circle < 1 || options.circle > 5) {
        throw new RangeError(`Círculo Arcano fuera del canon (1-5): ${options.circle}`);
      }
    }

    const settings = {
      count: 12,
      speed: 250,
      size: 3,
      life: 1.5,
      color: '#ffffff',
      random: createDefaultRandom(),
      ...options,
    };

    // RF-03.3 — Escalado por Círculo: densidad (volumen) y brillo (radio).
    const circle = settings.circle ?? 1;
    const count = Math.max(1, Math.round(settings.count * CIRCLE_DENSITY_SCALE[circle]));
    const size = settings.size * CIRCLE_BRIGHTNESS_SCALE[circle];

    // RF-03.1 — Modulación elemental: el color sale de la paleta canónica
    // y la cinemática del perfil se fusiona con la aceleración geométrica.
    const elemental = elementProfile;
    const speed = settings.speed * (elemental?.boost ?? 1);

    const particles = profile(settings.random, origin, target, { ...settings, count, size, speed });
    for (const p of particles) {
      let { vx, vy } = p;
      if (elemental) {
        // Fricción elemental inicial.
        const drag = elemental.drag || 0;
        vx *= 1 - drag;
        vy *= 1 - drag;

        // Ondulación lateral (agua): impulso perpendicular sinusoidal
        // calculado con la velocidad original (sin contaminación cruzada).
        if (elemental.lateralWave) {
          const currentSpeed = Math.hypot(vx, vy) || 1;
          const perpX = (-vy / currentSpeed);
          const perpY = (vx / currentSpeed);
          const wave = elemental.lateralWave * settings.random();
          vx += perpX * wave;
          vy += perpY * wave;
        }

        // Estremezimiento fractal (rayo): impulso instantáneo aleatorio
        // que dispara la varianza de celeridades (arcos de plasma).
        if (elemental.fracture) {
          const angle = settings.random() * Math.PI * 2;
          const impulse = elemental.fracture * (0.5 + settings.random());
          vx += Math.cos(angle) * impulse;
          vy += Math.sin(angle) * impulse;
        }
      }

      // Aceleración total = geométrica + elemental (gravedad, vórtice
      // tangencial y succión centrípeta). El vórtice y la succión se
      // calculan contra la DIRECCIÓN DE LA VELOCIDAD (proxy radial):
      // en la geometría sphere las partículas nacen en el blanco, donde
      // el radio posicional es nulo y la velocidad es la única referencia.
      let ax = p.ax;
      let ay = p.ay + (elemental?.gravity ?? 0);
      if (elemental?.swirl || elemental?.suction) {
        const velocity = Math.hypot(vx, vy) || 1;
        const radialX = vx / velocity;
        const radialY = vy / velocity;
        if (elemental.swirl) {
          // Aceleración tangencial perpendicular al radio (vórtice).
          ax += -radialY * elemental.swirl;
          ay += radialX * elemental.swirl;
        }
        if (elemental.suction) {
          // Aceleración centrípeta: frena la expansión hacia el núcleo.
          ax -= radialX * elemental.suction;
          ay -= radialY * elemental.suction;
        }
      }

      // Color: de la paleta canónica si hay elemento; si no, el pedido.
      const color = elemental
        ? elemental.palette[Math.floor(settings.random() * elemental.palette.length)]
        : settings.color;

      this.emit(p.x, p.y, vx, vy, color, size, settings.life, ax, ay);
    }
    return particles.length;
  }
}
