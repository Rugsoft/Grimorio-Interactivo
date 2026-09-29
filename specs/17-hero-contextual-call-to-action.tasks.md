# Tareas — SPEC-17: El Botón que No Miente (CTA contextual del héroe)

> Especificación: [`17-hero-contextual-call-to-action.spec.md`](17-hero-contextual-call-to-action.spec.md)
> **Ratificada:** 2026-09-29. Ninguna tarea de código arranca antes de esa fecha.
> **Doctrina:** «No Spec, No Code». La Tarea 0 es obligatoria y precede a cualquier implementación: sin rojo previo no hay prueba de que la prueba sirva.

---

## Fase 0 — La prueba que debe fallar antes de codificar

- [x] **Tarea 0 — Arnés rojo de la resolución contextual**
  *Cubre:* RF-17.1 (los cinco estados y su orden), RF-17.2, RF-17.5, RNF-17.1, RNF-17.2, RNF-17.4.
  *Alcance:* crear `scratch/test_hero_call_to_action.mjs` que importe `resolveHeroCallToAction` y `HERO_CTA_STATES` de un módulo **aún inexistente**, y falle nombrando cada requisito incumplido. Sin DOM simulado: la función es pura (RNF-17.4).
  *Hecho cuando:* el arnés **falla** con aserciones rojas que nombran los cinco estados, el orden de evaluación, la ausencia de CTA y la limpieza de las etiquetas accesibles.
  *Ejecución (2026-09-29):* **EJECUTADA — 0 PASA / 34 FALLA, exit 1.**
  - `scratch/test_hero_call_to_action.mjs` (NUEVO) importa `heroCallToAction.js`, que **aún no existe**: el arnés falla en la fase [0] y **sigue**. No corta ahí, porque cortar dejaría sin pronunciarse a las otras cinco fases, que es justo lo que un arnés rojo debe demostrar.
  - Las 34 aserciones se alzan sobre los cinco estados, el orden de evaluación (incluido que el estado 5 anula al 4, y que un linaje vacío se trata como peregrino), la ausencia de CTA, las etiquetas accesibles, la pureza, la degradación ante una sesión ausente y la soberanía lingüística.
  - **Primera corrección, durante la redacción:** la versión inicial declaraba *PASA* cinco aserciones que no comprobaban nada. Sin la función, `undefined !== 'Consagrar Linaje'` es cierto, y reportarlo como cumplido es mentir: el requisito no se cumple, la función simplemente no existe. Se añadió un flag `moduleMissing` y un parámetro `requiresModule`, de modo que toda aserción dependiente del módulo se marca **NO COMPROBABLE** mientras falte. Es el mismo falso verde que en SPEC-16, responsable de que una prueba devolviera cero en vez de «ninguno».
  - **Segunda corrección, y es la que más importa:** la primera versión **reventaba** con `TypeError: Cannot convert undefined or null to object` en `Object.keys(null)`. Un arnés que revienta no dice qué se rompió — es exactamente el fallo que hundió a `test_intent_give_praise` en el cierre de SPEC-16, y lo había repetido a unas pocas líneas. Corregido con guarda explícita: ahora el arnés **afirma** y muere con 34 marcas.
  - La corrida no puede quedar verde mientras falte el módulo: la aserción de superficie de la fase [0] sigue en rojo aunque el resto pasara por vacuidad.

---

## Fase 1 — La resolución

- [x] **Tarea 1 — `heroCallToAction.js`: la función pura**
  *Cubre:* RF-17.1, RF-17.2, RF-17.5, RNF-17.1, RNF-17.2, RNF-17.4.
  *Alcance:* módulo nuevo con `HERO_CTA_STATES` (congelado) y `resolveHeroCallToAction(sessionState)`. Sin DOM, sin red, sin temporizadores, sin efectos. Cinco estados con rótulo, `ariaLabel` y acción.
  *Hecho cuando:* el arnés de la Tarea 0 pone en verde las fases [0] a [5]; `node --check` limpio; cero identificadores técnicos en los rótulos.
  *Ejecución (2026-09-29):* **EJECUTADA — arnés 37 PASA / 0 FALLA; 112/112 arneses en verde.**
  - `public/assets/js/components/heroCallToAction.js` (NUEVO): `HERO_CTA_ACTIONS`,
    `HERO_CTA_STATES` (congelado, los cinco estados) y `resolveHeroCallToAction()`.
    Función pura: sin DOM, sin red, sin temporizadores, sin estado propio. Cero
    identificadores técnicos en rótulos ni etiquetas; los nombres de acción viven
    solo en el descriptor, que nadie ve.
  - El orden de evaluación es el contrato y está comentado dentro de la función
    con el motivo de cada parada: el custodio primero (si no, recibiría una
    llamada a vincularse a una hermandad que como custodio no puede usar), la
    autenticación antes del clan (un `userClan` residual con la sesión cerrada es
    basura de estado, no una hermandad), y la cadena vacía como peregrino (la
    forma más sutil de perderlo: si se tomara por juramento, su única salida
    sería un enlace que no existe).
  - **Corrección de la spec antes de codificar:** la tabla de §9 declaraba para
    el peregrino una etiqueta accesible que **no contenía su texto visible**, con
    lo que violaba WCAG 2.5.3 —el mismo requisito que impone RF-17.5. Una spec
    que exige una cosa y cuya tabla la incumple enseña el error en lugar de
    vetarlo. Las tres etiquetas empiezan ahora por su texto visible.
  - **Prueba de mutación: 12/12 detectadas, y esto es lo que de verdad valida el
    arnés.** Un arnés en verde no prueba nada por sí solo; hay que comprobar
    que se pone rojo. Se mutó la implementación doce veces —restaurar el CTA
    antiguo, invertir el orden de las paradas, tomar la cadena vacía por
    juramento, devolver un descriptor vacío en vez de `null`, colar la clave
    `vestibule` en un rótulo, dejar el catálogo sin congelar, mutar la sesión,
    reventar con una sesión ausente— y **las doce se detectaron**.
  - **La prueba de mutación encontró tres fallos REALES del arnés, ninguno de la
    implementación:**
    1. `sessionWith()` congelaba las sesiones. Una implementación que mutara la
       sesión no fallaba la aserción de pureza: reventaba con un `TypeError` en
       la primera llamada. La aserción pasaba por la congelación, no por la
       pureza. Ahora las sesiones del arnés no van congeladas, y hay una aserción
       que vigila que no vuelvan a estarlo.
    2. El arnés **podía morir a manos del código que somete a prueba**. Con la
       mutación que lo hace reventar, las 30 aserciones posteriores nunca se
       pronunciaban. Se añadió un envoltorio `safeResolve` que convierte cada
       lanzamiento en un veredicto nombrado, más una aserción global: *«la
       resolución no lanzó en ninguna de las 12 llamadas»*. Verificado: **cero
       trazas de pila** en las doce mutaciones.
    3. El clasificador de la propia prueba de mutación daba un falso positivo
       (`REVENTÓN`) porque buscaba `TypeError` en la salida, y el arnés ya lo
       imprimía **atrapado**. Un instrumento que miente sobre el estado del
       instrumento es peor que no tenerlo.
  - Nota de método: la primera tanda de mutaciones dio tres resultados que
    parecían buenos y no lo eran. M3 salió verde porque la mutación **nunca se
    aplicó** —el escapado del shell la dejó intacta—, y M1 dio 0/34 en lugar de
    36/1 porque el arnés aún cortaba de golpe. A partir de ahí cada mutación
    **verifica que se aplicó** antes de leer su resultado: una prueba de
    mutación que no comprueba su propia mutación no demuestra nada.

- [x] **Tarea 2 — La vista: rótulo, ausencia y rehidratación**
  *Cubre:* RF-17.1, RF-17.3, RF-17.4, RF-17.6, RF-17.7, RNF-17.5.
  *Alcance:* `landingView.js` acepta el CTA resuelto (opción nueva; ausente → comportamiento actual, para no romper arneses), pinta el rótulo y la etiqueta, y **omite el nodo** cuando el descriptor es `null`, sin hueco reservado. `main.js` resuelve con `store.getState()`, lo inyecta y lo reevalúa al cambiar la sesión.
  *Hecho cuando:* el arnés pone en verde; en navegador real, los cinco estados se ven correctos y el inicio de sesión desde el Umbral cambia el CTA **sin recarga**.
  *Ejecución (2026-09-29):* **EJECUTADA — 112/112 arneses en verde; cadena completa verificada.**
  - `landingView.js` acepta `heroCallToAction` y `onNavigateRequest`, y expone
    `setHeroCallToAction()` para la rehidratación. `main.js` resuelve con
    `resolveHeroCallToAction(store.getState())`, lo inyecta y se suscribe al store.
  - **La ausencia de la opción NO es lo mismo que su valor `null`.** No pasarla
    conserva el comportamiento de SPEC-01 (siempre «Consagrar Linaje») para que
    los arneses legados sigan teniendo su contrato; pasarla en `null` significa
    «no hay acto pendiente» y el nodo **no se crea**. Confundir ambos habría
    puesto en rojo a todos los arneses de SPEC-01 sin motivo.
  - **Rectificación de la Tarea 1 al cablear:** el peregrino ya NO pasa por el
    Umbral. `RF-17.3` prohíbe abrir el diálogo de acceso con sesión activa, así
    que su acción es navegar a `juramento` y la etiqueta se corrigió («abre la
    ceremonia…»). En la Tarea 1 lo había dejado en `openAccess` porque la
    resolución pura no tenía por qué saberlo; el cableado lo reveló.
  - **Bug propio cazado por el arnés de SPEC-01:** el manejador del clic
    calculaba el descriptor efectivo *dentro* del manejador, y en modo legado
    devolvía `null` —el clic no hacía nada y el botón quedaba inerte en los
    arneses de SPEC-01—. Se unificó en una sola variable resuelta al construir la
    vista. Cuatro aserciones de `test_landing_view` lo redeemieron en el acto.
  - `data-reserved` se restauró: SPEC-01 lo declara y su arnés lo verifica, y
    **ningún código lo lee**. Quitarlo habría sido un cambio de contrato
    disfrazado de refactor.
  - **La rehidratación se probó en la cadena COMPLETA** (store → suscriptor →
    resolución → DOM) con una fase nueva en `test_main_orchestrator.mjs`: ocho
    cambios de sesión seguidos y el CTA nace, cambia de rótulo, se retira y no
    se acumula. La vista (`test_landing_view`, +15 aserciones) y la función pura
    (`test_hero_call_to_action`, 37) cubren las dos puntas; ese eslabón solo se
    puede ver con la app montada.
  - **Navegador real:** con una sesión jurada y sin hermandad, el héroe muestra
    «Vincularse a una Hermandad» con su etiqueta; al pulsarlo navega al
    **Vestíbulo de las Hermandades** y `#accessModal` **no** se abre. Antes
    abría «Cruzar el Umbral».
  - **Defecto preexistente observado y NO corregido (alcance de más):** las tres
    suscripciones de sesión de `main.js` sobrescriben la misma variable de baja,
    así que en `destroy()` solo se da de baja la última y **las otras dos
    sobreviven al desmontaje**. La suscripción de SPEC-17 usa variable propia
    para no sumarle una cuarta, pero arreglar las otras es trabajo de otra tarea.
  - **Riesgo nº3 materializado dentro del propio arnés:** la fase de rehidratación
    se ejecutaba sobre la app **ya destruida** (la había colocado tras la fase de
    `destroy()`), y fallaba por el motivo equivocado. El guard que lo impedía
    —«solo rehidrato si la portada es la vista viva»— estaba haciendo su trabajo
    correctamente y la prueba era la que estaba mal. Reordenada y con una
    aserción que verifica que la portada está montada ANTES de medir.

---

## Fase 2 — Cierre

- [x] **Tarea 3 — Regresión, criterios y diff**
  *Cubre:* §8 (12 criterios), RNF-17.3.
  *Alcance:* ejecutar los **112** arneses `.mjs` de `scratch/` —no solo los conocidos—, el guard de tokens, y `git diff --stat` para confirmar que no entra `src/`, `database/` ni `tokens.css`. Marcar los criterios en su estado verdadero.
  *Hecho cuando:* todo en verde (exit 0), criterios con evidencia, diff revisado y resumen al custodio.
  *Ejecución (2026-09-29):* **EJECUTADA — 112/112 arneses en verde, 12/12 criterios con evidencia, cero intrusos en el diff.**
  - **Regresión completa, los 112 y no los conocidos:** `for f in scratch/*.mjs; do node "$f"; done` con registro del código de salida de cada uno. **112 PASS / 0 FAIL**, todos en 0. Los tres de SPEC-17: `test_hero_call_to_action` 37/0, `test_landing_view` 35/0, `test_main_orchestrator` 35/0. `test_portal_composition` (SPEC-16) sigue en «Todas las aserciones pasan»: la recomposición de la portada no se ha tocado.
  - **Guard de tokens:** `node scratch/audit_css_ghost_tokens.mjs` → «SIN tokens fantasma». Y `library.css` con **0 hex crudos** (el único cambio de CSS es `.landing-hero__cta[hidden]`, que usa tokens).
  - **Diff:** `git diff --stat` + `git diff --name-only HEAD` + `git ls-files --others --exclude-standard`, filtrados por `^(src/|database/)|tokens\.css$` → **NINGUNO**. 7 ficheros modificados y 4 nuevos; la superficie de producción son **tres**: `heroCallToAction.js` (nuevo), `landingView.js`, `main.js`, más el selector del CTA en `library.css`.
  - **Evidencia de navegador, con base propia:** `scratch/spec17_browser_fixtures.php` siembra en `scratch/spec17_browser.sqlite` un mago por estado (peregrino, jurado suelto, con hermandad, custodio) con frase de paso real, porque las semillas de demostración traen `password_hash` no verificable y no se puede abrir sesión con ellas. Se sirvió con `php -S` en **puerto dinámico** (el 8000 está ocupado por otro proyecto) y `GRIMORIO_DB_DSN` en ruta **Windows** (`sqlite:C:/…`): con ruta `/c/…` de Git Bash, PHP resuelve `C:\c\…`, abre una base **distinta** y todo falla con `INVALID_CREDENTIALS` sin que nada lo denuncie.
  - **La sonda que convierte una impresión en medición:** se parcheó `HTMLDialogElement.prototype.show/showModal` para registrar **toda** apertura de diálogo. Sin ella, «no se abre el Umbral» era una mirada. Con ella: estado 3 `aperturas 0 → 0` y estado 2 `aperturas 0 → 0`; el estado 1 sí abre, y es lo que declara.
  - **Alturas medidas (RNF-17.5):** `.landing-hero` con CTA = **210 px**; sin CTA (estados 4 y 5) = **150 px**, con `querySelectorAll('.landing-hero__cta').length === 0` y los hijos reducidos a `title · intro`. Los **60 px** de diferencia son **44** del botón + **8** de margen + **8** de `gap`: no queda ni un píxel reservado.
  - **Rehidratación en caliente, demostrada sin recarga:** se sembró `window.__spec17Alive` antes de iniciar sesión; el marcador siguió vivo tras el acceso y el CTA pasó de «Cruzar el Umbral» a «Vincularse a una Hermandad» solo.
  - **Dos incoherencias de la spec corregidas aquí, no en el código:** la tabla de RF-17.1 seguía diciendo para el peregrino `openAccess` (con intención) hacia el Umbral, y la etiqueta de §9 seguía la redacción previa. Ambas describían lo que la Tarea 2 ya había rectificado —el peregrino va **derecho** a `juramento`— y una spec que corrige su propio código en la letra, pero no en sus tablas, enseña el error en lugar de vetarlo.
  - **Dos hallazgos fuera de alcance, escritos en §8.1 y NO corregidos:** (a) `POST /api/v1/auth/bind` nunca devuelve `clanId` porque `forgeUserPayload` busca la clave `clanId` en una fila cuyas columnas son `clan_id` — contradice la premisa de la exclusión §2.2 y merece SPEC-18 antes de tocar PHP; (b) las tres `store.subscribe` de `main.js` siguen sobrescribiendo `unsubscribeSessionWatch` (defecto preexistente, anotado en la Tarea 2).

---

## Riesgos y Contingencias Registrados

1. **Los arneses hermanos codifican el rótulo antiguo.** `test_landing_view` y `test_main_orchestrator` afirman la existencia del CTA y, en algún caso, su texto. Con cinco estados, un aserto que exija «Consagrar Linaje» **siempre** se rompe en cuanto se implemente el estado contextual. Se adaptarán al contrato nuevo de forma deliberada y documentada, nunca relajándose: la adaptación debe seguir exigiendo que *algún* CTA coherente exista para el peregrino, no que el rótulo sea cualquiera. **Riesgo nº1 de la entrega**, y predecesor directo del que costó dos materializaciones en SPEC-16.
2. **Los estados 4 y 5 rompen aserciones de presencia.** Un arnés que exija «el CTA existe» fallará por diseño cuando el CTA desaparece. La comprobación correcta es condicional al estado, no incondicional.
3. **La rehidratación es el punto más frágil.** `main.js` ya tiene un `store` con suscriptores; una reevaluación mal inicializada puede intentar tocar el CTA antes de que la vista exista. Mitigación: comprobar que la vista montada es la portada antes de tocar el DOM.
4. **Riesgo de descontrol de alcance.** Esta spec toca **un nodo**. Si al implementarla aparece la tentación de retocar el sello, la cinta o el título del héroe, es alcance de más: pertenece a SPEC-16, que está cerrada y medida. Se anota como deuda y no se ejecuta.
5. **`tokens.css` es intocable.** Si un rótulo o un estado necesita un color nuevo, se usa el token existente más próximo y, si de verdad no hay, se anota para una spec de tokens. Nunca se añade una variable aquí.

---

## Incidente de Herramienta Registrado

*Al cerrar la Tarea 0 se destruyó este mismo fichero.* Un `preg_replace` con un patrón inválido devolvió `null`, y ese `null` se escribió sobre el disco: el fichero quedó con **0 líneas**. La receta del desastre fue juntar dos operaciones —una que puede fallar y una que sobrescribe— en la misma línea sin comprobar el resultado intermedio.

*Regla que sale de aquí:* **`preg_replace` devuelve `null` si el patrón no compila.** Antes de pasar su resultado a `file_put_contents` hay que comprobar que no es `null`, o usar `str_replace`, que devuelve la cadena intacta cuando no encuentra nada. Una operación de texto nunca debe poder dejar un fichero vacío sin que se note en el mismo comando. El fichero se reescribió íntegro y se verificó su contenido; el daño no costó trabajo previo más allá de reescribirlo.
