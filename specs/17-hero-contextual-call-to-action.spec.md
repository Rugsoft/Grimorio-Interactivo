# SPEC-17: El Boto Que No Miente — Llamada a la Acción Contextual del Héroe

> **Prioridad:** Corrección (integridad de la promesa de la interfaz)
> **Estado:** EJECUTADA Y CERRADA (2026-09-29) — ratificada, 4/4 tareas, 12/12 criterios de §8 con evidencia. **Ninguna línea de código de esta spec se ejecutó antes de la ratificación** (AGENTS.md, «No Spec, No Code»). | **Tareas:** [`specs/17-hero-contextual-call-to-action.tasks.md`](17-hero-contextual-call-to-action.tasks.md)
> **Specs relacionadas:** SPEC-01 (Portal y Navegación — **enmendada** por esta spec, RF-01.4), SPEC-09 (Juramento de Linaje — **la invalida**, ver §1), SPEC-10 (Ceremonia de Adhesión — se reutiliza), SPEC-11 (Colección del Adepto — se reutiliza), SPEC-16 (Composición de la Portada — se respeta: el héroe ya recompuesto es la superficie que se corrige)
> **Regla de precedencia:** cuando dos specs discrepen sobre el héroe de la portada, **rige la más reciente**. Esta spec es la más reciente y por tanto **deroga `RF-01.4` de SPEC-01** en lo relativo a la llamada a la acción.

---

## 1. Contexto y Objetivo

El héroe de la portada muestra un botón rotulado **«Consagrar Linaje»** (`RF-01.4` de SPEC-01), que emite la acción reservada `joinClan`.

Ese rótulo se escribió cuando consagrar un linaje era una **elección**. SPEC-09 la convirtió en un **acto obligatorio e irreversible del primer acceso**: todo adepto sin juramento es retenido por un interceptor y conducido a la ceremonia, y ninguna vista posterior permite revocarlo. SPEC-09 no enmendó `RF-01.4`. La especificación más nueva invalidó a la anterior **por omisión**, y el botón conservó su nombre mientras su sentido se vaciaba.

### 1.1 Los tres fallos medidos

**Fallo A — el botón abre un umbral a quien ya lo cruzó.** Verificado en navegador real con una sesión viva y jurada (`Vestibulo7b — Linaje de las Sombras Abisales`): al pulsar «Consagrar Linaje» se abre el diálogo **«Cruzar el Umbral»**, con los formularios *Renovar Vínculo* y *Consagrarse*. La causa está en `main.js` `handleReservedAction()`: el único camino que consume la acción sin modal es el de los gestos del tomo (`TOME_ACTION_NAMES`), que solo reconoce `addToGrimoire` y `givePraise`. El CTA del héroe emite `joinClan` sin hechizo, así que **siempre** cae en `accessModal.open(...)`, sin condición de sesión.

**Fallo B — el botón no lleva a ninguna parte.** Tras el clic, `handleAuthenticated()` exige que la intención retenida `joinClan` traiga un `targetSlug` no vacío, y el héroe lo emite como `null`. **Ninguna rama de la cadena la consume.** Para un visitante anónimo la intención acaba absorbida por la rama `else if (userLineage === null)` → `navigate('juramento')`, pero solo *después* de registrarse. El botón es un atajo al registro, y el registro lleva igualmente a la ceremonia.

**Fallo C — la etiqueta accesible es falsa.** El `aria-label` del botón dice: *«Consagrar Linaje: abre el umbral de acceso para vincular tu linaje»*. Para un adepto jurado, el segundoHalf es inventado: no hay umbral que cruzar ni linaje que vincular. WCAG 2.5.3 exige que la etiqueta contenga el texto visible —aquello sí lo cumple— pero el resto no tiene por qué ser untrue.

**Consecuencia de conjunto:** el único estado en que el rótulo describe algo que la persona puede hacer de verdad es el **peregrino recién registrado**, y en ese estado el acto se cumple igual sin el botón, porque el interceptor de SPEC-09 lo conduce a la ceremonia. El CTA es, hoy, redundante en un caso y **muerto** en los otros.

### 1.2 Objetivo

Que **cada rótulo del héroe nombre un acto que quien lo mira puede(realmente) realizar**, y que ningún usuario conectado vea nunca un diálogo de acceso. Cuando no quede ningún acto pendiente, el héroe **omite** CTA: la ausencia es la información correcta.

---

## 2. Alcance y Exclusiones

### 2.1 Alcance

- La **resolución del CTA** del héroe a partir del estado de sesión (`isAuthenticated`, `userLineage`, `userClan`, `userRole`).
- El **rótulo, la acción y la etiqueta accesible** de cada variante.
- El **cierre del callejón sin salida**: ninguna acción del héroe abrirá jamás el diálogo de acceso a un usuario ya conectado.
- La **rehidratación** del CTA cuando la sesión cambia sin que se vuelva a renderizar la vista (inicio de sesión desde el propio Umbral).
- Su arnés y las adaptaciones de los arneses hermanos que codifiquen el rótulo antiguo.

### 2.2 Exclusiones (y por qué)

| Excluido | Motivo |
|---|---|
| Revocar el juramento | SPEC-09 lo declara perpetuo por principio. Abrirlo aquí lo desmentiría. |
| Cambiar el texto de SPEC-01 RF-01.1 o `landing-hero__title` | La recomposición de RF-01.1 ya la hizo SPEC-16 y está verificada. Esta spec toca **un solo nodo**: el CTA. |
| Rediseñar el héroe, el sello o la cinta | SPEC-16 los cerró y midió. Meter aquí un cambio visual sería alcance (…) y volvería a abrir lo que ya no se tocó. |
| Tocar `src/` o `database/` | El estado de sesión ya viaja al frontend en el sobre de `auth/me`; no hace falta tocar la API. |
| Sustituir el CTA por navegación interna pura | Ver §7, caso límite 4: la decisión está argumentada y es del custodio. |

---

## 3. Actores

| Actor | Relación con esta spec |
|---|---|
| **Visitante anónimo** | Ve el umbral. El juramento llegará solo; el CTA no debe prometer una elección que no está haciendo. |
| **Peregrino** (sesión activa, `userLineage === null`) | Único actor para quien «Consagrar Linaje» es verdad. Para él, ese es el CTA correcto. |
| **Adepto jurado sin hermandad** (`userLineage !== null`, `userClan === null`) | Ya cumplió el acto irrevocable. Lo que le queda es **otro** acto real: vincularse a una Hermandad (SPEC-10). |
| **Adepto con hermandad** | No le queda nada. El héroe no debe ofrecerle nada. |
| **`admin_supremo`** | Exento del interceptor de SPEC-09 y no es adepto. Sin CTA. |

---

## 4. Requisitos Funcionales (EARS)

### RF-17.1 — Resolución del CTA [Ubicuo]

> EL SISTEMA DEBERÁ resolver **un único** estado de CTA a partir de la sesión, evaluado en este orden y parando en el primero que aplique:

| # | Estado | Condición | Rótulo visible | Acción | Destino |
|---|---|---|---|---|---|
| 1 | Anónimo | `isAuthenticated === false` | **«Cruzar el Umbral»** | `openAccess` | Diálogo de acceso y registro |
| 2 | Peregrino | `isAuthenticated && userLineage === null` | **«Consagrar Linaje»** | `navigate` | Ceremonia del juramento (`juramento`) |
| 3 | Jurado suelto | `userLineage !== null && userClan === null` | **«Vincularse a una Hermandad»** | `navigate` | `vestibule` |
| 4 | Con hermandad | `userClan !== null` | — | — | Sin CTA |
| 5 | Custodio | `userRole === 'supremeAdmin'` | — | — | Sin CTA |

> El orden importa: el estado 5 se evalúa **antes** que cualquier otro y anula al 4, porque un `admin_supremo` no porta hermandad pero tampoco es un adepto a quien valga la pena ofrecerle una.
>
> **Corrección de la Tarea 2 (2026-09-29).** Esta tabla decía para el estado 2 `openAccess` (con intención) con destino «Umbral, que retiene y conduce a `juramento`». La Tarea 2 rectificó la acción a `navigate` directo a `juramento` y la razón está en `RF-17.3`: mandar a un peregrino a **cruzar** un umbral que tiene detrás es exactamente el callejón sin salida que esta spec vino a demoler, y su único resultado era un diálogo de acceso para quien ya tiene sesión. La tabla se corrige para que la spec no exija en su letra lo que su propio `RF-17.3` prohíbe. La corrección afecta al contrato, no al código: el código ya era el nuevo.

### RF-17.2 — El rótulo nombra el acto, no el trámite

> CADA rótulo DEBERÁ describir **la acción que el usuario puede realizar en ese instante**, no el mecanismo que la ejecuta. En particular, el estado 1 **no** se rotulará «Consagrar Linaje»: esa persona aún no ha elegido nada, y el juramento no es una decisión que pueda tomar en la portada sino una consecuencia de registrarse.

### RF-17.3 — Prohibición absoluta del callejón sin salida

> CUANDO una acción del héroe se emita con una sesión ya activa, el sistema **NO DEBERÁ** abrir el diálogo de acceso bajo ninguna circunstancia. Si la acción no puede consumirse, el sistema **NO DEBERÁ** retener una intención que ninguna rama resolverá: o la consume, o la descarta. Una intención retenida sin consumidor es un fallo, no un estado válido.

### RF-17.4 — Sin CTA no hay hueco

> SI el estado resuelto es 4 o 5, el héroe **NO DEBERÁ** reserving espacio vacío, ni un botón deshabilitado, ni un esqueleto, ni un margen que simule su ausencia. La rejilla del héroe se recompondrá sin él. La razón se expone en §7, caso límite 5.

### RF-17.5 — Honestidad de la etiqueta accesible

> CADA CTA **DEBERÁ** llevar un `aria-label` cuya segunda mitad describa **el efecto real** del pulsado en ese estado, sin nombrar ninguna puerta que no se vaya a abrir. El prefijo DEBERÁ contener el texto visible (WCAG 2.5.3). Para el estado 3, el efecto real es *abrir el Vestíbulo de las Hermandades*; para el estado 1, *abrir el diálogo de acceso y registro*.

### RF-17.6 — Rehidratación

> CUANDO la sesión cambie sin que la vista se vuelva a renderizar —en particular, al completar el acceso desde el propio Umbral con la portada detrás—, el CTA **DEBERÁ** reevaluarse y reflejar el nuevo estado **sin exigir recarga**. La ausencia del CTA (estado 4) también es un estado que debe alcanzarse en caliente.

### RF-17.7 — La cinta no sustituye al CTA

> El CTA ausente **NO DEBERÁ** ser compensado con adorno nuevo en el sello, la cinta del Regente o la galería (RF-18.4 de SPEC-16: la firma se gasta en un sitio). La portada sin CTA se sostiene sobre su tesis y su firma, que es lo que ya se verificó.

---

## 5. Requisitos No Funcionales

* **RNF-17.1 (Sin dependencias):** la resolución será una función pura sobre el estado. Cero librerías, cero red, cero temporizadores.
* **RNF-17.2 (Soberanía lingüística):** los tres rótulos van en castellano solemne. Ninguna clave técnica (`joinClan`, `userLineage`, `vestibule`, `supremeAdmin`) será visible ni aparecerá en texto alternativo.
* **RNF-17.3 (Superficie acotada):** la spec toca **exactamente** un fichero de producción nuevo o modificado en JS de vista, más `main.js` para la rehidratación, más los CSS estrictamente necesarios. Queda prohibido tocar `tokens.css` (riesgo nº4 de TASKS-16) y prohibido tocar cualquier hoja de la recomposición de SPEC-16 más allá del selector del CTA.
* **RNF-17.4 (Arnés sin navegador):** la resolución del CTA será una función **exportada y pura**, de modo que pueda verificarse sin DOM simulado ni navegador. Es la diferencia entre una decisión de producto y un acertijo de integración.
* **RNF-17.5 (Presupuesto de impacto):** el CTA ausente no puede desplazar la composición de la portada en más de 0 px respecto del estado con CTA, más allá del propio botón.

---

## 6. Casos Límite

| # | Caso | Regla |
|---|---|---|
| 1 | Sesión viva jurada, sin hermandad, en `#/linajes` | El CTA dice «Vincularse a una Hermandad» y navega a `vestibule`. No es lo mismo que «fundar»: laério de fundación es un acto distinto de SPEC-07b y solo se ofrece a quien no tiene hermandad **y** la ceremonia del Umbral lo habilita. |
| 2 | El Umbral se abre desde el CTA y el visitante se registra allí | Al completar el acceso, el CTA debe pasar de «Cruzar el Umbral» a «Consagrar Linaje» **en caliente** (RF-17.6), no esperar a un re-render. |
| 3 | Peregrino con intención retenida `joinClan` que ya tenía `targetSlug` (herencia de SPEC-01) | La rama existente `navigate('clan', {clanId})` **no se toca**: es la postulación a un clan concreto desde su ficha, no el CTA del héroe. Esta spec no la reescribe. |
| 4 | ¿Debería el CTA ser navegación interna pura en vez de pasar por el Umbral? | **Descartado, y el motivo se escribe:** la acción `joinClan` está cableada en `handleReservedAction` desde SPEC-01 y SPEC-09 añade que el Umbral retiene la intención del peregrino. Reutilizar ese cableado es lo que hace que el flujo de registro y el de consagración no se desincronicen nunca. Reescribirlo aquí crearía un segundo camino al mismo destino, que es como se rompe la coherencia. |
| 5 | ¿Un botón deshabilitado con el rótulo «Ya has consagrado tu linaje» sería mejor que ningún botón? | **Descartado, y el motivo también:** un rótulo que anuncia un hecho no es una llamada a la acción, y un control deshabilitado no explica por qué lo está. La información —*ya has consagrado*— ya vive en el distintivo de cabecera, que declara el linaje en su desplegable (RF-17.1 de SPEC-16). Duplicarla en un control inerte sería gritar lo que el distintivo ya dice. |

---

## 7. Contrato de Superficie (nombres exactos)

| Símbolo | Módulo | Papel |
|---|---|---|
| `HERO_CTA_STATES` | **NUEVO** `heroCallToAction.js` | Mapa congelado de los 5 estados con su rótulo, su etiqueta y su acción |
| `resolveHeroCallToAction(sessionState)` | **NUEVO** `heroCallToAction.js` | Función pura: estado de sesión → descriptor de CTA o `null`. Sin DOM, sin red, sin efectos |
| `createLandingView(..., heroCallToAction)` | `landingView.js` | **Opción nueva**: el CTA ya resuelto. Ausente o `null` → se resuelve por omisión desde `sessionState` para no romper los arneses existentes |

| Selector | Fichero | Papel |
|---|---|---|
| `.landing-hero__cta` | `library.css` | Se conserva el nombre; cambia su rótulo y su presencia según el estado |
| `.landing-hero__cta[hidden]` | `library.css` | Garantía explícita de ausencia real, no de hueco reservado |

**Punto de contacto con el orquestador:** `main.js` construye el descriptor con `resolveHeroCallToAction(store.getState())` al montar la portada y lo reevalúa al cambiar la sesión, cumpliendo RF-17.6.

---

## 8. Criterios de Aceptación (verificables)

> **Verificación de la Tarea 3 (2026-09-29).** Los doce criterios están ejecutados y con evidencia. La evidencia de navegador se tomó sobre `php -S` con la base sandbox `scratch/spec17_browser.sqlite` (`scratch/spec17_browser_fixtures.php` siembra un mago por estado), y la sonda `HTMLDialogElement.prototype.show/showModal` parcheada cuenta **toda** apertura de diálogo, para que «no se abre el Umbral» sea una medición y no una impresión.

- [x] `resolveHeroCallToAction` devuelve el rótulo «Cruzar el Umbral» para el anónimo y «Consagrar Linaje» para el peregrino.
      *Evidencia:* `scratch/test_hero_call_to_action.mjs` — **37/0**. Navegador real: anónimo → `.landing-hero__cta` = «Cruzar el Umbral»; peregrino recién consagrado desde el Umbral → «Consagrar Linaje».
- [x] Devuelve «Vincularse a una Hermandad» para el jurado sin hermandad, y `null` para el que ya tiene hermandad.
      *Evidencia:* `test_hero_call_to_action.mjs` (37/0) y `test_landing_view.mjs` (35/0). Navegador real: usuario jurado sin hermandad → «Vincularse a una Hermandad»; usuario con hermandad → `document.querySelectorAll('.landing-hero__cta').length === 0`.
- [x] `admin_supremo` recibe `null` aunque no porte hermandad.
      *Evidencia:* `test_hero_call_to_action.mjs` (37/0). Navegador real: `role: supremeAdmin`, `lineage: primordialFlame`, `clanId: null` → 0 nodos de CTA, héroe de 150 px.
- [x] La evaluación se detiene en el primer estado que aplica: un `admin_supremo` jurado **no** recibe CTA de hermandad.
      *Evidencia:* la **prueba de mutación de la Tarea 1** (12/12 mutaciones detectadas) incluye M2 «orden custodio» y M3 «clan antes que autenticación», que rompen precisamente este criterio. Navegador: el custodio sin hermandad no recibe CTA.
- [x] Pulsar el CTA con sesión viva **no** abre el diálogo de acceso en ningún estado.
      *Evidencia:* navegador real, contador de aperturas de diálogo. Estado 3 (jurado suelto): `aperturas 0 → 0`, navega al Vestíbulo. Estado 2 (peregrino): `aperturas 0 → 0`, navega a «El Umbral de los Linajes». Estados 4 y 5: no hay nodo que pulsar. En el estado 1 el diálogo **sí** se abre, y es lo que el estado 1 declara.
- [x] No queda ninguna intención retenida sin consumir tras pulsar el CTA en cualquiera de los cinco estados.
      *Evidencia:* navegador real en el estado 2, que es el único que retiene intención: tras «Consagrarse» desde el Umbral, `#accessModal.open === false` (no se reabre, que es lo que ocurriría con una intención huérfana) y el peregrino aterriza en la ceremonia. Estados 3/4/5 no retienen: emiten `navigate` o nada. La cadena completa la cubre `test_main_orchestrator.mjs` FASE 6b (35/0).
- [x] El estado 4/5 no deja nodo en el DOM ni hueco de rejilla: la altura del héroe varía solo en la altura del propio botón.
      *Evidencia:* navegador real. Con CTA (estados 1, 2, 3): `.landing-hero` = **210 px**, hijos `title · intro · cta`. Sin CTA (estados 4 y 5): `.landing-hero` = **150 px**, hijos `title · intro`, `querySelectorAll('.landing-hero__cta').length === 0`, cero botones en el héroe. Los **60 px** de diferencia son exactamente los **44 px** del botón + **8 px** de su margen + **8 px** del `gap` de rejilla que desaparecen con él: no queda ni un píxel reservado (RNF-17.5).
- [x] El `aria-label` de cada variante contiene el texto visible y describe el efecto real; ninguno menciona una puerta que no se abra.
      *Evidencia:* barrido sobre las 6 cadenas de `heroCallToAction.js` (3 rótulos + 3 etiquetas): **todas contienen su texto visible y ninguna contiene** `joinClan`, `userLineage`, `vestibule`, `supremeAdmin`, `openAccess` ni `navigate` — 0 problemas. Navegador real: las tres etiquetas medidas en el DOM coinciden con §9.
- [x] Al iniciar sesión desde el Umbral con la portada detrás, el CTA refleja el nuevo estado sin recarga.
      *Evidencia:* navegador real. Se sembró `window.__spec17Alive` antes de iniciar sesión; tras el acceso el marcador **sigue vivo** (la página no se recargó) y el CTA pasó de «Cruzar el Umbral» a «Vincularse a una Hermandad» con la etiqueta correcta, sin tocar el DOM a mano. El eslabón completo lo cubre FASE 6b de `test_main_orchestrator.mjs`, ocho cambios de sesión encadenados.
- [x] Ningún rótulo contiene `joinClan`, `userLineage`, `vestibule` ni `supremeAdmin`.
      *Evidencia:* el mismo barrido de arriba (0 coincidencias) y `test_hero_call_to_action.mjs` (37/0), que incluye la mutación M6 «clave técnica» como detector.
- [x] Los 112 arneses `.mjs` de `scratch/` siguen en verde, incluidos `test_landing_view`, `test_portal_composition` y `test_main_orchestrator`.
      *Evidencia:* los **112** ejecutados uno a uno (`node scratch/*.mjs`): **112 PASS / 0 FAIL**, todos con código de salida 0. `test_hero_call_to_action` 37/0, `test_landing_view` 35/0, `test_main_orchestrator` 35/0, `test_portal_composition` «Todas las aserciones pasan». Guard de tokens: `audit_css_ghost_tokens.mjs` → «SIN tokens fantasma», y 0 hex crudos en `library.css`.
- [x] `git diff --stat` no lista ningún fichero bajo `src/` ni `database/`, ni `tokens.css`.
      *Evidencia:* `git diff --stat` + `git diff --name-only HEAD` + `git ls-files --others --exclude-standard` filtrados por `^(src/|database/)|tokens\.css$` → **NINGUNO**. La superficie son 12 ficheros: 2 specs, 2 docs, 1 módulo nuevo, 2 de producción (`landingView.js`, `main.js`), 1 CSS, 3 arneses y 1 sonda de navegador.

### 8.1 Dos hallazgos que la Tarea 3 deja escritos (ninguno pertenece a SPEC-17)

1. **`POST /api/v1/auth/bind` nunca devuelve `clanId`** (`src/Controllers/AuthController.php`, `forgeUserPayload`): el cierre busca la clave `'clanId'` en una fila cuyas columnas son `clan_id`, así que cae siempre al respaldo `$activeUser?->getClanId()`, que en `bind` es `null`. `GET /api/v1/auth/session` sí lo devuelve bien. Consecuencia observable: un adepto **con hermandad** que entra por el Umbral ve durante unos segundos el CTA «Vincularse a una Hermandad» hasta la siguiente hidratación de sesión. No lo arregla esta spec porque §2.2 excluye `src/` de forma expresa; lo que la exclusión daba por cierto —*«el estado de sesión ya viaja al frontend en el sobre de `auth/me`»*— es verdad para `session` y falso para `bind`. **Requiere su propia spec (SPEC-18) antes de tocar una línea de PHP.**
2. **Las tres `store.subscribe` de `main.js` sobrescriben `unsubscribeSessionWatch`**, así que en `destroy()` solo se da de baja la última y las otras dos sobreviven al desmontaje. Anotado ya en la Tarea 2; confirmado al leer `main.js` en esta tarea. La suscripción de SPEC-17 usa variable propia para no sumarle una cuarta.

---

## 9. Copia Ratificada

Los tres rótulos son texto de producto, no redacción decorativa, y se aprueban como parte de la spec:

| Estado | Texto del botón | Etiqueta accesible |
|---|---|---|
| Anónimo | **Cruzar el Umbral** | «Cruzar el Umbral: abre el diálogo de acceso y registro. Al vincularte, jurarás tu linaje en la ceremonia.» |
| Peregrino | **Consagrar Linaje** | «Consagrar Linaje: abre la ceremonia en la que jurarás tu linaje de forma irrevocable.» |
| Jurado suelto | **Vincularse a una Hermandad** | «Vincularse a una Hermandad: abre el Vestíbulo, donde puedes pedir el ingreso en una casa de tu linaje.» |

> Ninguna de las tres promete algo que el sistema no haga. Las tres dicen en voz alta qué ocurre **después** del clic, que es donde el rótulo antiguo mentía.
>
> **Corrección de la Tarea 1 (2026-09-29).** La primera redacción de esta tabla ponía en la etiqueta «abre el umbral de acceso: al entrar, la ceremonia te conducirá a jurar tu linaje», que **no contenía el texto visible** y por tanto violaba WCAG 2.5.3 —justo el requisito que RF-17.5 impone, y el que el arnés de la Tarea 0 comprueba. Una spec que exige una cosa y cuya tabla la incumple es peor que no tenerla: enseña el error en lugar de vetar lo que ella misma exige. Las tres etiquetas empiezan ahora por su texto visible y añaden el efecto real.

---

## 10. Ficheros Previstos (superficie estimada)

```
public/assets/js/components/heroCallToAction.js   ← NUEVO (función pura, sin DOM)
public/assets/js/views/landingView.js             ← opción heroCallToAction + rehidratación
public/assets/js/main.js                          ← resolver y reevaluar con la sesión
public/assets/css/components/library.css          ← solo el selector del CTA
scratch/test_hero_call_to_action.mjs              ← NUEVO (función pura: sin navegador)
scratch/test_landing_view.mjs                     ← ampliado (+15, fases 7 y 8)
scratch/test_main_orchestrator.mjs                ← ampliado (+8, fase 6b: la cadena completa)
scratch/spec17_browser_fixtures.php               ← NUEVO (sonda de navegador, no producto)
```

> **Superficie real medida en la Tarea 3:** 12 ficheros, de los cuales **cuatro** son de producción (`heroCallToAction.js`, `landingView.js`, `main.js`, `library.css`), dos son docs (`AGENTS.md`, `README.md`), dos son estas specs y cuatro son arneses o sondas. `scratch/spec17_browser_fixtures.php` siembra un mago por estado en una base **sandbox** para que los criterios de §8 puedan medirse en un navegador de verdad: las semillas de demostración traen `password_hash` no verificable y no permiten abrir sesión. No es código de producto ni entra en el despliegue.

**Fuera de alcance, verificado por `git diff --stat`:** `src/`, `database/`, `tokens.css`, `layout.css`, `clans.css`, `navbarComponent.js`, `userProfileBadge.js`, `clanBannerComponent.js`, `landingSigilComponent.js`.

---

## 11. Nota de Precedencia (por qué esta spec existe)

`RF-01.4` de SPEC-01 no es un requisito malo: era correcto cuando escritas. Lo que cambió fue SPEC-09, que hizo el juramento **obligatorio e irreversible** sin enmendarlo. El proyecto ya tiene el patrón para esto —SPEC-09 enmendó SPEC-03, SPEC-07b enmendó SPEC-07, SPEC-16 enmendó SPEC-01— y esta spec lo aplica por tercera vez.

La lección que deja escrita, porque se repite: **una spec que vuelve irreversible un acto que otra spec ofrecía como elección debe enmendar esa otra spec en el mismo commit.** Cuando no lo hizo, el código se quedó con el rótulo viejo y el sentido nuevo, y ningún arnés lo detectó porque todos comprobaban que el botón *disparaba* su acción, no que la acción *significara* algo para quien la pulsa. Ese hueco de verificación es el que esta spec cierra: `RF-17.4` y el criterio de la altura del héroe existen para que «no hacer nada» sea una decisión **medible**, no una ausencia.
