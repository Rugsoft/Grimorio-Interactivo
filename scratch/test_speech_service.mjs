/**
 * test_speech_service.mjs — Arnés TDD de la Tarea 2.4 (TASKS-05).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica, sobre `public/assets/js/utils/speechService.js`:
 *   [1] API y factoría inyectable (los dobleces nativos se pasan por
 *       opciones; sin ellos, el módulo consulta los globals del navegador).
 *   [2] reciteSpell(): declama con SpeechSynthesisUtterance en es-ES,
 *       rate 0.85 y pitch 0.95; cancela locuciones previas antes de hablar
 *       (RF-04.2); sin soporte, termina en silencio y devuelve false.
 *   [3] Degradación grácil (RF-04.4): sin synth, sin recognition o con
 *       reconocimiento en error (`not-allowed`, `service-not-allowed`),
 *       los métodos responden con estado explícito y jamás lanzan.
 *   [4] matchesSpellInvocation() (RF-04.3): coincidencia directa por
 *       nombre canónico o fórmula ceremonial; tolerancia fonética con
 *       al menos 2 palabras clave significativas (> 3 letras); rechazo
 *       de frases ajenas.
 *   [5] stopAll(): detiene síntesis y reconocimiento a la vez (RF-06.3
 *       prepara aquí su suspensión por visibilidad).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Web Speech API nativa, cero librerías.
 *   - Artículo IV/V: mensajes solemnes en castellano; API en inglés.
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

console.log('== ARNÉS TDD — Tarea 2.4: speechService (síntesis + reconocimiento) ==');

let service;
try {
  service = await import('../public/assets/js/utils/speechService.js');
} catch (error) {
  console.log(`  FATAL — no se pudo importar speechService.js: ${error.message}`);
  console.log('== RESUMEN == Fase roja: la implementación aún no existe.');
  process.exit(1);
}

const SPELL = {
  name: 'Llamas del Alba',
  incantationFormula: '¡Llamas del alba, descended y consumid la penumbra!',
  keywords: ['llamas', 'alba', 'penumbra'],
};

/** Doble de SpeechSynthesis con registro de locuciones. */
function createFakeSynth({ supported = true } = {}) {
  const utterances = [];
  const cancelled = [];
  return {
    utterances,
    cancelled,
    speakCount: 0,
    speaking: false,
    cancel() { this.cancelled.push(true); this.speaking = false; },
    speak(utterance) {
      this.speakCount++;
      this.utterances.push(utterance);
      this.speaking = true;
    },
    get supported() { return supported; },
  };
}

/** Doble de SpeechSynthesisUtterance que retiene sus propiedades. */
function createFakeUtteranceCtor() {
  return function FakeUtterance(text) {
    this.text = text;
    this.lang = '';
    this.rate = 1;
    this.pitch = 1;
    this.volume = 1;
  };
}

/** Doble de SpeechRecognition configurable (soporte, escucha y errores). */
function createFakeRecognitionCtor({ supported = true, failOnStart = null } = {}) {
  const instances = [];
  class FakeRecognition {
    constructor() {
      this.lang = '';
      this.continuous = false;
      this.interimResults = false;
      this.maxAlternatives = 1;
      this.onresult = null;
      this.onerror = null;
      this.onend = null;
      this.started = false;
      instances.push(this);
    }
    start() {
      if (failOnStart) {
        const error = new Error(failOnStart);
        error.name = failOnStart;
        throw error;
      }
      this.started = true;
    }
    stop() { this.started = false; }
    abort() { this.started = false; }
  }
  FakeRecognition.instances = instances;
  FakeRecognition.supported = supported;
  return FakeRecognition;
}

console.log('[1] API y factoría inyectable');

assert(typeof service.createSpeechService === 'function', 'exporta createSpeechService(options)');

const synth = createFakeSynth();
const Utterance = createFakeUtteranceCtor();
const Recognition = createFakeRecognitionCtor();
const speaker = service.createSpeechService({ synth, Utterance, Recognition, lang: 'es-ES', rate: 0.85, pitch: 0.95 });
assert(typeof speaker.reciteSpell === 'function', 'expone reciteSpell(incantation)');
assert(typeof speaker.matchesSpellInvocation === 'function', 'expone matchesSpellInvocation(transcript, spell)');
assert(typeof speaker.startListening === 'function', 'expone startListening(onMatched)');
assert(typeof speaker.stopAll === 'function', 'expone stopAll()');
assert(typeof speaker.isSynthesisSupported === 'function', 'expone isSynthesisSupported()');
assert(typeof speaker.isRecognitionSupported === 'function', 'expone isRecognitionSupported()');

console.log('[2] reciteSpell — síntesis litúrgica (RF-04.1/04.2)');

assert(speaker.isSynthesisSupported() === true, 'reporta síntesis soportada con el doble inyectado');
const recited = speaker.reciteSpell(SPELL.incantationFormula);
assert(recited === true, 'devuelve true al declamar con soporte');
assert(synth.speakCount === 1, 'una única locución emitida');
assert(synth.utterances[0].text === SPELL.incantationFormula, 'el texto locutado es la fórmula ceremonial íntegra');
assert(synth.utterances[0].lang === 'es-ES', 'dialecto castellano es-ES');
assert(synth.utterances[0].rate === 0.85, 'cadencia solemne rate 0.85');
assert(synth.utterances[0].pitch === 0.95, 'tono profundo pitch 0.95');
assert(synth.cancelled.length === 1, 'cancela la locución previa antes de hablar');

// Segunda declamación: vuelve a cancelar (no se acumulan cánticos).
speaker.reciteSpell(SPELL.incantationFormula);
assert(synth.cancelled.length === 2, 'cada nueva declamación cancela la anterior (2 cancels)');
assert(synth.speakCount === 2, 'y emite exactamente una locución nueva (2 speaks)');

console.log('[3] Degradación grácil (RF-04.4)');

{
  // Síntesis sin soporte: termina en silencio, sin lanzar.
  const silent = service.createSpeechService({ synth: null, Utterance, Recognition });
  assert(silent.isSynthesisSupported() === false, 'sin synth reporta no soportado');
  let threw = false;
  let result = null;
  try { result = silent.reciteSpell(SPELL.incantationFormula); } catch { threw = true; }
  assert(!threw && result === false, 'reciteSpell sin soporte devuelve false sin lanzar');

  // Reconocimiento sin soporte: estado explícito y escucha rechazada.
  const deaf = service.createSpeechService({ synth, Utterance, Recognition: null });
  assert(deaf.isRecognitionSupported() === false, 'sin Recognition reporta no soportado');
  let listenThrew = false;
  let listenResult = null;
  try { listenResult = deaf.startListening(() => {}); } catch { listenThrew = true; }
  assert(!listenThrew && listenResult === false, 'startListening sin soporte devuelve false sin lanzar');

  // Micrófono denegado: el callback de error recibe el mensaje solemne.
  const denied = createFakeRecognitionCtor({ failOnStart: 'not-allowed' });
  const blocked = service.createSpeechService({ synth, Utterance, Recognition: denied });
  let reportedError = null;
  const outcome = blocked.startListening(SPELL, () => {}, (message) => { reportedError = message; });
  assert(outcome === false, 'startListening con micrófono denegado devuelve false');
  assert(typeof reportedError === 'string' && reportedError.length > 0, `avisa con un mensaje solemne («${(reportedError || '').slice(0, 40)}…»)`);
}

console.log('[4] matchesSpellInvocation — tolerancia fonética (RF-04.3)');

assert(speaker.matchesSpellInvocation('lanzo llamas del alba contra el maniquí', SPELL) === true, 'coincidencia directa por nombre canónico');
assert(speaker.matchesSpellInvocation('¡llamas del alba, descended y consumid la penumbra!', SPELL) === true, 'coincidencia por fórmula ceremonial íntegra');
assert(speaker.matchesSpellInvocation('descended llamas y alba', SPELL) === true, 'tolerancia: 2 palabras clave (> 3 letras) bastan');
assert(speaker.matchesSpellInvocation('que las alba llamas ardan', SPELL) === true, 'tolerancia: palabras clave en desorden');
assert(speaker.matchesSpellInvocation('convierte el agua en vino', SPELL) === false, 'rechaza una frase ajena al conjuro');
assert(speaker.matchesSpellInvocation('llamas solamente', SPELL) === false, 'una sola palabra clave no basta (mínimo 2)');
assert(speaker.matchesSpellInvocation('', SPELL) === false, 'transcripción vacía rechazada');

// Palabras cortas (<= 3 letras) no cuentan como significativas.
const SHORT = { name: 'Rayo X Uno', incantationFormula: 'zz aa', keywords: [] };
assert(service.createSpeechService({ synth, Utterance, Recognition }).matchesSpellInvocation('rayo x uno', SHORT) === true, 'nombre íntegro cuenta aunque no tenga palabras largas');

console.log('[5] startListening y stopAll — ciclo de escucha');

{
  const listening = createFakeRecognitionCtor();
  const listener = service.createSpeechService({ synth, Utterance, Recognition: listening });
  const outcome = listener.startListening(SPELL, () => {}, () => {});
  assert(outcome === true, 'startListening con soporte devuelve true');
  const instance = listening.instances[0];
  assert(instance.lang === 'es-ES', 'la escucha se configura en es-ES');
  assert(typeof instance.onresult === 'function' && typeof instance.onerror === 'function', 'onresult y onerror registrados');
  assert(instance.started === true, 'el reconocimiento arrancó a escuchar');

  // Transcripción reconocida con nombre → callback con el conjuro validado.
  let matched = null;
  listener.startListening(SPELL, (spell) => { matched = spell; }, () => {});
  const activeInstance = listening.instances[1];
  activeInstance.onresult({ results: [[{ transcript: 'invoco llamas del alba' }]] });
  assert(matched !== null && matched.name === SPELL.name, 'el callback recibe el conjuro reconocido');

  // stopAll aborta la escucha y la síntesis.
  listener.stopAll();
  assert(activeInstance.started === false, 'stopAll aborta el reconocimiento');
  assert(synth.speaking === false || synth.cancelled.length >= 2, 'stopAll silencia la síntesis pendiente');
}

console.log('== RESUMEN ==');
console.log(`Asertos superados: ${passed}, fallidos: ${failed}`);
if (failed === 0) {
  console.log('RESULTADO: EXITO — speechService listo para la Cámara de Conjuración (Tarea 2.4).');
  process.exit(0);
} else {
  console.log('RESULTADO: DENEGADO — hay asertos incumplidos.');
  process.exit(1);
}
