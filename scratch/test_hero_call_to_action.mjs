/**
 * test_hero_call_to_action.mjs — Arnés de la SPEC-17 (Tarea 0: ROJO).
 *
 * Verifica la resolución de la llamada a la acción del héroe a partir del
 * estado de sesión, tal y como la define `17-hero-contextual-call-to-action.spec.md`:
 *
 *   RF-17.1  Los cinco estados y su ORDEN de evaluación.
 *   RF-17.2  El rótulo nombra el acto, no el trámite.
 *   RF-17.5  Honestidad de la etiqueta accesible (WCAG 2.5.3).
 *   RNF-17.1 Función pura: sin DOM, sin red, sin temporizadores, sin efectos.
 *   RNF-17.2 Soberanía lingüística: ninguna clave técnica en los rótulos.
 *   RNF-17.4 La resolución es verificable SIN navegador.
 *
 * Fases:
 *   [0]  Superficie del módulo (aún inexistente: debe fallar).
 *   [1]  Los cinco estados y su rótulo (RF-17.1, RF-17.2).
 *   [2]  El orden de evaluación (RF-17.1: gana el primero que aplica).
 *   [3)  La ausencia de CTA (RF-17.4: sin nodo, sin hueco).
 *   [4]  Etiquetas accesibles honestas (RF-17.5).
 *   [5]  Pureza y soberanía lingüística (RNF-17.1, RNF-17.2).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): cero librerías; sin DOM, sin navegador.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *   - «No Spec, No Code»: en la Tarea 0 el módulo NO EXISTE y el arnés falla.
 *
 * Uso: node scratch/test_hero_call_to_action.mjs
 */

import { readFile } from 'node:fs/promises';

let assertsPassed = 0;
let assertsFailed = 0;
let moduleMissing = false;

/**
 * Aserta una condición y registra el veredicto.
 *
 * `requiresModule` distingue dos cosas que un arnés normal confunde: «lo
 * comprobé y se cumple» y «no pude comprobarlo». Sin el módulo, la
 * comparación `undefined !== 'Consagrar Linaje'` es cierta, y reportarla
 * como PASA sería una mentira: el requisito NO se cumple, la
 * función simplemente no existe. Por eso toda aserción que dependa del
 * módulo se marca automáticamente como no comprobable mientras falte.
 */
function assertCondition(condition, label, { requiresModule = true } = {}) {
  const verifiable = requiresModule === false || moduleMissing === false;
  if (condition && verifiable) {
    assertsPassed += 1;
    console.log(`  [PASA] ${label}`);
    return true;
  }
  assertsFailed += 1;
  if (condition && !verifiable) {
    console.log(`  [FALLA] ${label} — NO COMPROBABLE: el módulo aún no existe`);
    return false;
  }
  console.log(`  [FALLA] ${label}`);
  return false;
}

/** Aserta que una etiqueta accesible contenga el texto visible (WCAG 2.5.3). */
function assertLabelContainsVisibleText(stateName, labelText, visibleText) {
  return assertCondition(
    typeof labelText === 'string' && typeof visibleText === 'string' && visibleText !== '' && labelText.includes(visibleText),
    `La etiqueta accesible de «${visibleText}» contiene el texto visible (WCAG 2.5.3)`,
  );
}

// =====================================================================
// [0] SUPERFICIE DEL MÓDULO
// =====================================================================
console.log('== VERIFICACIÓN SPEC-17: El Botón que No Miente ==\n');
console.log('[0] Superficie del módulo heroCallToAction.js');

let heroModule = null;
try {
  heroModule = await import('../public/assets/js/components/heroCallToAction.js');
} catch (importError) {
  console.log(`  (el módulo todavía no existe: ${importError.code ?? importError.message})`);
}

assertCondition(
  heroModule !== null,
  'heroCallToAction.js es importable (RNF-17.4: la resolución se verifica sin navegador)',
  { requiresModule: false },
);
assertCondition(
  typeof heroModule?.resolveHeroCallToAction === 'function',
  'Exporta resolveHeroCallToAction(sessionState) (RNF-17.1: función pura)',
  { requiresModule: false },
);
assertCondition(
  heroModule?.HERO_CTA_STATES !== null && typeof heroModule?.HERO_CTA_STATES === 'object',
  'Exporta HERO_CTA_STATES, el catálogo congelado de los cinco estados (RF-17.1)',
  { requiresModule: false },
);

moduleMissing = typeof heroModule?.resolveHeroCallToAction !== 'function';
if (moduleMissing) {
  console.log('  → A partir de aquí cada aserción se marca como NO COMPROBABLE:');
  console.log('    sin la función, comparar contra `undefined` daría PASA por vacuidad.');
}

/**
 * Cuando el módulo todavía no existe, NO se corta el arnés: se sigue para que
 * TODAS las fases se pronuncien y el rojo nombre cada requisito incumplido,
 * que es justo lo que la Tarea 0 debe demostrar. Cortar aquí pondría tres
 * aserciones rojas y dejaría las demás sin comprobar nunca.
 *
 * El sustituto devuelve `undefined`, que no es ningún estado válido, de modo
 * que las aserciones de los cinco estados fallan de verdad. Las que son
 * tautologías con `undefined` (por ejemplo, «dos llamadas iguales dan igual»)
 * pasarían por vacuidad: por eso la corrida NUNCA puede quedar verde, porque
 * la aserción de superficie de la fase [0] sigue en rojo mientras falte el
 * módulo.
 */
const resolve = typeof heroModule?.resolveHeroCallToAction === 'function'
  ? heroModule.resolveHeroCallToAction
  : () => undefined;

/**
 * Invocación protegida: devuelve el valor o un centinela si la implementación
 * lanza, y registra el lanzamiento para que pueda asertarse con nombre.
 *
 * Motivo: un arnés que puede morir a manos del código que somete a prueba no
 * es un arnés, es una lotería. La prueba de mutación M11 —«una sesión ausente
 * revienta el héroe»— lo demostró de forma cruda: el arnés se caía por un
 * `TypeError` en la fase [1] y las 30 aserciones posteriores nunca se
 * pronunciaban. Con este envoltorio, un reventón se convierte en UN aserto
 * rojo nombrado, y el resto de la fase sigue opinando.
 */
let launchCount = 0;
let lastLaunchError = null;

function safeResolve(sessionState) {
  try {
    return resolve(sessionState);
  } catch (launchError) {
    launchCount += 1;
    lastLaunchError = launchError;
    return undefined;
  }
}

// =====================================================================
// [1] LOS CINCO ESTADOS Y SU RÓTULO
// =====================================================================
console.log('\n[1] Los cinco estados resuelven un rótulo coherente (RF-17.1, RF-17.2)');

/** Sesión de referencia: un visitante anónimo. */
const SESSION = {
  isAuthenticated: false,
  userRole: 'reader',
  userClan: null,
  userLineage: null,
};

/**
 * Construye una variante de sesión a partir de la de referencia.
 *
 * DELIBERADAMENTE sin `Object.freeze`. Una versión anterior los congelaba, y
 * eso debilitaba en silencio la comprobación de pureza: una implementación
 * que mutara la sesión no fallaba la aserción, reventaba con un `TypeError`
 * en la primera llamada. Un rojo por reventón no nombra el defecto; lo nombra
 * la aserción. El efecto se vio en la prueba de mutación M8, que ponía el
 * arnés en rojo por crashing y no por el motivo que de verdad importaba.
 */
const sessionWith = (overrides = {}) => ({ ...SESSION, ...overrides });

// Estado 1 — Anónimo. La prueba de que el rótulo NO es «Consagrar Linaje».
const anonymous = safeResolve(sessionWith());
assertCondition(
  anonymous !== null && anonymous !== undefined,
  'Estado 1 (anónimo): recibe una llamada a la acción (RF-17.1)',
);
assertCondition(
  typeof anonymous?.label === 'string' && anonymous.label.trim() !== '',
  'Estado 1 (anónimo): el rótulo es una cadena no vacía (RF-17.2)',
);
assertCondition(
  anonymous?.label !== 'Consagrar Linaje',
  'Estado 1 (anónimo): NO se rotula «Consagrar Linaje» — aún no ha elegido nada (RF-17.2)',
);

// Estado 2 — Peregrino. El único caso donde el rótulo antiguo sí era verdad.
const pilgrim = safeResolve(
  sessionWith({ isAuthenticated: true, userRole: 'editor' }),
);
assertCondition(
  pilgrim?.label === 'Consagrar Linaje',
  'Estado 2 (peregrino): el rótulo es «Consagrar Linaje», el único acto que puede hacer (RF-17.2)',
);

// Estado 3 — Jurado sin hermandad. Lo que le queda abierto es otro acto.
const swornLoose = safeResolve(
  sessionWith({ isAuthenticated: true, userRole: 'editor', userLineage: 'abyssalShadows' }),
);
assertCondition(
  typeof swornLoose?.label === 'string' && swornLoose.label.trim() !== '',
  'Estado 3 (jurado sin hermandad): recibe una llamada a la acción (RF-17.1)',
);
assertCondition(
  swornLoose?.label !== 'Consagrar Linaje',
  'Estado 3 (jurado sin hermandad): NO se rotula «Consagrar Linaje» — el juramento es irrevocable (RF-17.2)',
);

// Estado 4 — Con hermandad. No queda nada por ofrecer.
const swornBound = safeResolve(
  sessionWith({
    isAuthenticated: true,
    userRole: 'editor',
    userLineage: 'abyssalShadows',
    userClan: { id: 'cln_ensayo', name: 'Casa de Ensayo' },
  }),
);
assertCondition(
  swornBound === null || swornBound === undefined,
  'Estado 4 (con hermandad): NO hay llamada a la acción (RF-17.1, RF-17.4)',
);

// Estado 5 — El custodio no es un adepto a quien valga la pena ofrecerle nada.
const regent = safeResolve(
  sessionWith({ isAuthenticated: true, userRole: 'supremeAdmin' }),
);
assertCondition(
  regent === null || regent === undefined,
  'Estado 5 (admin_supremo): NO hay llamada a la acción aunque no porte hermandad (RF-17.1)',
);

// =====================================================================
// [2] EL ORDEN DE EVALUACIÓN
// =====================================================================
console.log('\n[2] El orden de evaluación: gana el primer estado que aplica (RF-17.1)');

// El regente puede estar jurado Y con hermandad. Si el orden evaluara el
// estado 4 antes que el 5, el custodio recibiría un CTA de hermandad.
const regentFull = safeResolve(
  sessionWith({
    isAuthenticated: true,
    userRole: 'supremeAdmin',
    userLineage: 'solarCrown',
    userClan: { id: 'cln_ensayo', name: 'Casa de Ensayo' },
  }),
);
assertCondition(
  regentFull === null || regentFull === undefined,
  'El estado 5 anula al 4: un custodio jurado y con hermandad no recibe CTA (RF-17.1)',
);

// El estado 4 no puede resurrectar a un anónimo: un `userClan` sin sesión
// es basura de estado, no una hermandad. Si la función se fiara del clan
// antes que de la autenticación, le ofrecería vincularse a una hermandad
// a alguien que ni siquiera ha entrado.
const anonymousWithClan = safeResolve(
  sessionWith({ isAuthenticated: false, userClan: { id: 'cln_fantasma', name: 'Clan Fantasma' } }),
);
assertCondition(
  anonymousWithClan?.label === anonymous?.label,
  'La autenticación manda sobre un clan residual: el anónimo recibe el rótulo del anónimo (RF-17.1)',
);

// Un linaje vacío NO es un linaje jurado: es peregrino. La cadena vacía
// sería la forma más sutil de perder al peregrino y mandarlo a un estado
// en el que no puede jurar.
const emptyLineage = safeResolve(
  sessionWith({ isAuthenticated: true, userRole: 'editor', userLineage: '' }),
);
assertCondition(
  emptyLineage?.label === 'Consagrar Linaje',
  'Un linaje vacío se trata como peregrino, no como jurado (RF-17.1, caso límite)',
);

// =====================================================================
// [3] LA AUSENCIA DE CTA
// =====================================================================
console.log('\n[3] Sin CTA no hay hueco (RF-17.4, RF-17.7)');

// La ausencia se declara con `null` y NO con un descriptor vacío: un
// descriptor sin rótulo obligaría a la vista a pintar un botón sin nombre,
// que es exactamente el hueco que la spec prohíbe.
const noCtaMarker = swornBound;
assertCondition(
  noCtaMarker === null || noCtaMarker === undefined,
  'La ausencia se declara con null, no con un descriptor vacío (RF-17.4)',
);
assertCondition(
  noCtaMarker === null
    || noCtaMarker === undefined
    || Object.keys(noCtaMarker).length === 0,
  'No se devuelve un descriptor a medio rellenar cuando no hay CTA (RF-17.4)',
);

// =====================================================================
// [4] ETIQUETAS ACCESIBLES HONESTAS
// =====================================================================
console.log('\n[4] Etiquetas accesibles honestas (RF-17.5)');

const labelledStates = [
  ['anónimo', anonymous],
  ['peregrino', pilgrim],
  ['jurado suelto', swornLoose],
];

for (const [stateName, descriptor] of labelledStates) {
  assertCondition(
    typeof descriptor?.label === 'string' && descriptor.label.trim() !== '',
    `Estado ${stateName}: el descriptor nombra un rótulo visible (RF-17.5)`,
  );
  assertLabelContainsVisibleText(
    `Estado ${stateName}`,
    descriptor?.ariaLabel,
    descriptor?.label ?? '∅',
  );
  assertCondition(
    typeof descriptor?.ariaLabel === 'string' && descriptor.ariaLabel.length > (descriptor?.label?.length ?? 0),
    `Estado ${stateName}: la etiqueta añade el efecto real, no solo repite el rótulo (RF-17.5)`,
  );
}

// El rótulo antiguo prometía un umbral. Para un usuario ya vinculado no puede
// prometer ninguna puerta que no se vaya a abrir.
const NO_DEBE_DECIR = ['umbral de acceso', 'vincular tu linaje'];
for (const [stateName, descriptor] of labelledStates) {
  const hayFalsa = NO_DEBE_DECIR.some((phrase) => (descriptor?.ariaLabel ?? '').includes(phrase));
  assertCondition(
    stateName === 'peregrino' || hayFalsa === false,
    `Estado ${stateName}: la etiqueta no nombra una puerta que no se abrirá (RF-17.5)`,
  );
}

// =====================================================================
// [5] PUREZA Y SOBERANÍA LINGÜÍSTICA
// =====================================================================
console.log('\n[5] Pureza y soberanía lingüística (RNF-17.1, RNF-17.2)');

// La resolución es una FUNCIÓN PURA: mismos datos, mismo resultado, sin que
// el resultado dependa del reloj ni de un contador. Dos llamadas seguidas
// deben ser idénticas, y una llamada no debe mutar su entrada.
const repeated = safeResolve(sessionWith());
assertCondition(
  JSON.stringify(repeated) === JSON.stringify(anonymous),
  'La resolución es pura: dos llamadas con el mismo estado dan el mismo resultado (RNF-17.1)',
);

// La sesión de esta comprobación NO va congelada a propósito. Si estuviera
// congelada, la aserción passaría porque el objeto NO PUEDE mutarse, no porque
// la función no intente mutarlo: una prueba que pasa por la congelación no
// prueba nada.
const mutableSession = { ...SESSION };
const snapshot = JSON.stringify(mutableSession);
safeResolve(mutableSession);
assertCondition(
  JSON.stringify(mutableSession) === snapshot,
  'La resolución NO muta la sesión que recibe (RNF-17.1)',
);
assertCondition(
  Object.isFrozen(mutableSession) === false,
  'La comprobación de pureza usa una sesión DESCONGELADA: si no, la aserción de arriba passaría por la congelación (defecto de arnés encontrado por prueba de mutación)',
);

// Una sesión ausente o corrupta no puede reventar la portada: degradar a
// «anónimo» es mejor que dejar el héroe sin pintar.
//
// La llamada va envuelta porque el requisito ES que no reviente: si la
// implementación revienta, el arnés debe decirlo con una aserción roja y
// seguir, no morir aquí y perder el resto de la fase. Fue la mutación M11 la
// que lo destapó.
let noState;
let degradedWithoutThrowing = true;
try {
  noState = resolve(undefined);
} catch (callError) {
  degradedWithoutThrowing = false;
  noState = null;
  console.log(`  (la resolución lanzó: ${callError.constructor?.name ?? 'Error'})`);
}
assertCondition(
  degradedWithoutThrowing,
  'Una sesión ausente NO revienta: degrada a un estado (RNF-17.1)',
);
assertCondition(
  noState !== null && noState !== undefined,
  'Una sesión ausente degrada a un estado, en vez de reventar (RNF-17.1)',
);
assertCondition(
  noState?.label === anonymous?.label,
  'Una sesión ausente se resuelve como el anónimo: el mismo rótulo (RNF-17.1)',
);

// Soberanía lingüística (RNF-17.2): ninguna clave técnica en rótulo ni etiqueta.
const CLAVES_TECNICAS = [
  'joinClan', 'userLineage', 'userClan', 'vestibule', 'juramento', 'supremeAdmin',
  'isAuthenticated', 'openAccess', 'navigate', 'data-view', 'data-action', 'null', 'undefined',
];
let technicalKeysFound = [];
for (const [stateName, descriptor] of labelledStates) {
  const surface = `${descriptor?.label ?? ''} ${descriptor?.ariaLabel ?? ''}`;
  const found = CLAVES_TECNICAS.filter((key) => surface.includes(key));
  if (found.length > 0) technicalKeysFound.push(`${stateName}: ${found.join(', ')}`);
}
assertCondition(
  technicalKeysFound.length === 0,
  `Ningún rótulo ni etiqueta contiene una clave técnica (RNF-17.2)${
    technicalKeysFound.length > 0 ? ` — filtró: ${technicalKeysFound.join(' | ')}` : ''
  }`,
);

// El catálogo de estados es un contrato congelado: si alguien lo muta desde
// fuera, la vista puede pintar un rótulo que nadie ha especificado.
assertCondition(
  Object.isFrozen(heroModule?.HERO_CTA_STATES) === true,
  'HERO_CTA_STATES está congelado (RNF-17.1)',
);

// La resolución no debe reventar NUNCA en las fases anteriores. Esta
// aserción global convierte cualquier `TypeError` atrapado por `safeResolve`
// en un veredicto nombrado, en vez de dejar un `undefined` silencioso que
// hace fallar aserciones por el motivo equivocado (la mutación M11 lo
// demostró: sin esto, el arnés se caía y no opinaba de nada).
assertCondition(
  launchCount === 0,
  `La resolución no lanzó en ninguna de las ${'12'} llamadas de las fases [1]–[5] (RNF-17.1)${
    lastLaunchError !== null ? ` — último: ${lastLaunchError.constructor?.name ?? 'Error'}` : ''
  }`,
);

// =====================================================================
// RESUMEN
// =====================================================================
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — el botón solo nombra actos que se pueden hacer.');
} else {
  console.log('RESULTADO: DENEGADO — revisa los asertos marcados.');
}

process.exit(assertsFailed === 0 ? 0 : 1);
