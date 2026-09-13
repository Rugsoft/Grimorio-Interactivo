/**
 * speechService.js — Servicio de voz nativo del Simulador de Grimorio.
 *
 * Tarea 2.4 (TASKS-05): encapsula la Síntesis litúrgica (`SpeechSynthesis`,
 * declamación en `es-ES` con cadencia solemne) y el Reconocimiento fonético
 * (`SpeechRecognition` / `webkitSpeechRecognition`) con tolerancia de
 * palabras clave, todo con degradación grácil ante falta de soporte o
 * denegación de permisos.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Web Speech API nativa; cero librerías
 *     o CDNs. Los dobles del navegador se inyectan por opciones para que
 *     el servicio sea falsable en arneses sin DOM.
 *   - Artículo IV (Velo Arcano): los mensajes de error son avisos solemnes
 *     en noble castellano, jamás tecnicismos expuestos.
 *   - Artículo V: identificadores en inglés camelCase; locuciones y
 *     mensajes en castellano.
 *
 * Cobertura: RF-04.1 (cánticos en castellano), RF-04.2 («Escuchar Cántico»),
 * RF-04.3 (invocación por voz con tolerancia), RF-04.4 (degradación
 * grácil), RNF-04 (soberanía lingüística).
 */

/** Constantes canónicas de la declamación solemne (plan 3.4). */
export const RECITATION_LANG = 'es-ES';
export const RECITATION_RATE = 0.85;
export const RECITATION_PITCH = 0.95;

/** Mensajes solemnes de degradación (Artículo IV). */
const MESSAGES = {
  synthesisUnsupported: 'El oráculo de la voz reposa en silencio: este navegador no sabe declamar cánticos.',
  recognitionUnsupported: 'El oráculo del sonido reposa en silencio: este navegador no sabe escuchar conjuros.',
  micDenied: 'El oráculo del sonido ha sido silenciado: concede permiso al micrófono para conjurar con la voz.',
  micUnavailable: 'No se ha hallado el oráculo del sonido: no hay micrófono disponible en este artilugio.',
  networkError: 'La niebla interrumpe al oráculo del sonido: revisa tu vínculo y prueba de nuevo.',
  recognitionError: 'El oráculo del sonido se ha ofuscado: inténtalo de nuevo.',
};

/**
 * Factoría del servicio de voz. Sin opciones, resuelve las APIs nativas
 * del navegador (`window.speechSynthesis`, `SpeechSynthesisUtterance`,
 * `SpeechRecognition` o `webkitSpeechRecognition`).
 *
 * @param {object} [options] - `synth`, `Utterance`, `Recognition` (dobles
 *   inyectables), `lang`, `rate`, `pitch` canónicos.
 * @returns {object} servicio con reciteSpell, matchesSpellInvocation,
 *   startListening, stopAll y sondas de soporte.
 */
export function createSpeechService(options = {}) {
  const globalWindow = typeof window !== 'undefined' ? window : globalThis;

  const synth = options.synth ?? globalWindow.speechSynthesis ?? null;
  const Utterance = options.Utterance ?? globalWindow.SpeechSynthesisUtterance ?? null;
  const RecognitionCtor = options.Recognition
    ?? globalWindow.SpeechRecognition
    ?? globalWindow.webkitSpeechRecognition
    ?? null;

  const lang = options.lang ?? RECITATION_LANG;
  const rate = options.rate ?? RECITATION_RATE;
  const pitch = options.pitch ?? RECITATION_PITCH;

  let recognition = null;

  /** ¿Hay síntesis vocal disponible? */
  function isSynthesisSupported() {
    return Boolean(synth && Utterance);
  }

  /** ¿Hay reconocimiento fonético disponible? */
  function isRecognitionSupported() {
    return Boolean(RecognitionCtor);
  }

  /**
   * Declama en voz alta la fórmula litúrgica (RF-04.2). Cancela cualquier
   * cántico previo para no acumular locuciones.
   * @returns {boolean} true si la declamación arrancó; false en degradación.
   */
  function reciteSpell(incantation) {
    if (!isSynthesisSupported()) {
      return false; // Degradación grácil: silencio sin alertas (RF-04.4).
    }
    synth.cancel(); // La nueva invocación sustituye al cántico anterior.
    const utterance = new Utterance(incantation);
    utterance.lang = lang;
    utterance.rate = rate;
    utterance.pitch = pitch;
    synth.speak(utterance);
    return true;
  }

  /**
   * Valida una transcripción vocal contra el conjuro activo (RF-04.3):
   * acepta el nombre canónico íntegro, la fórmula ceremonial o al menos
   * DOS palabras clave significativas (más de 3 letras).
   * @returns {boolean} true si la frase desencadena la invocación.
   */
  function matchesSpellInvocation(spokenTranscript, spell) {
    const cleanSpoken = String(spokenTranscript ?? '').toLowerCase().trim();
    if (!cleanSpoken) {
      return false;
    }
    const spellName = String(spell?.name ?? '').toLowerCase().trim();
    const formula = String(spell?.incantationFormula ?? '').toLowerCase().trim();

    // 1. Coincidencia directa con el nombre completo o la fórmula.
    if ((spellName && cleanSpoken.includes(spellName)) || (formula && cleanSpoken.includes(formula))) {
      return true;
    }

    // 2. Tolerancia fonética: 2+ palabras significativas (> 3 letras).
    const keywords = spellName.split(' ').filter((word) => word.length > 3);
    const matches = keywords.filter((word) => cleanSpoken.includes(word));
    return matches.length >= 2;
  }

  /** Mensaje solemne según el tipo de fallo del oráculo. */
  function solemnMessageForError(errorName) {
    switch (errorName) {
      case 'not-allowed':
      case 'service-not-allowed':
        return MESSAGES.micDenied;
      case 'audio-capture':
        return MESSAGES.micUnavailable;
      case 'network':
        return MESSAGES.networkError;
      default:
        return MESSAGES.recognitionError;
    }
  }

  /**
   * Comienza a escuchar conjuros (RF-04.3). En cada resultado, si la
   * transcripción valida contra el conjuro activo, llama a `onMatched`.
   * @param {(spell: object) => void} onMatched - callback de invocación.
   * @param {(message: string) => void} [onError] - aviso solemne de fallo.
   * @returns {boolean} true si la escucha arrancó; false en degradación.
   */
  function startListening(activeSpell, onMatched, onError = () => {}) {
    if (!isRecognitionSupported()) {
      onError(MESSAGES.recognitionUnsupported);
      return false;
    }
    stopListening();
    const instance = new RecognitionCtor();
    instance.lang = lang;
    instance.continuous = false;
    instance.interimResults = false;
    instance.maxAlternatives = 1;

    instance.onresult = (event) => {
      const transcript = event.results?.[0]?.[0]?.transcript ?? '';
      if (matchesSpellInvocation(transcript, activeSpell)) {
        onMatched(activeSpell);
      }
    };
    instance.onerror = (event) => {
      onError(solemnMessageForError(event?.error ?? ''));
    };

    try {
      instance.start();
    } catch (error) {
      onError(solemnMessageForError(error?.name ?? ''));
      return false;
    }
    recognition = instance;
    return true;
  }

  /** Aborta la escucha activa (si la hubiera). */
  function stopListening() {
    if (recognition) {
      try {
        recognition.abort();
      } catch {
        // El oráculo ya descansaba: nada que hacer.
      }
      recognition = null;
    }
  }

  /**
   * Silencia el servicio por completo (síntesis + reconocimiento): usado
   * al cambiar de página y por la suspensión por visibilidad (RF-06.3).
   */
  function stopAll() {
    stopListening();
    if (isSynthesisSupported()) {
      synth.cancel();
    }
  }

  return {
    reciteSpell,
    matchesSpellInvocation,
    startListening,
    stopAll,
    isSynthesisSupported,
    isRecognitionSupported,
  };
}
