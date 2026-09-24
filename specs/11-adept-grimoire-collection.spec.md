# SPEC-11 — Colección del Adepto (Grimorio Personal y Elogio Popular)

> **Prioridad:** Fundamental (Identidad Arcana, Progresión Personal y Dominio de Linajes)
> **Estado:** Borrador saneado tras DOBLE QA rigurosa (SDD) — 6 dudas de la primera ronda y 21 hallazgos de la segunda, resueltos y ratificados (Sección 9); listo para la tríada plan/tasks
> **Specs relacionadas:** SPEC-05 (Simulador de Grimorio — se acata, su lienzo es la sala de lectura del tomo), SPEC-07 (Clanes, Linajes y Dominio — se acata y se sirve: el Elogio Popular alimenta el Dominio semanal), SPEC-09 (Juramento de Linaje — se respeta: su retención de umbral conduce aquí), SPEC-08 (Moderación en Dos Pasos — se acata: solo lo `validado` es coleccionable), SPEC-03 (RBAC — se acata), SPEC-02 (Sistema de Diseño — se acata), SPEC-01 (Portal y Navegación — se extiende)

---

## 1. Contexto y Objetivo

El santuario ya promete lo que aún no entrega. El umbral de autenticación retiene el gesto «Ver mi libro personal» (SPEC-09) y, una vez cruzado, conduce al Simulador con el tomo de *Mis Ensayos Arcanos* — pero ese tomo lista ensayos propios, no una colección. En paralelo, la maquinaria del Dominio semanal (SPEC-07) tiene el servicio de Elogio Comunitario (`awardCommunityFavorite`, con su tabla `favorites` y su asiento de gloria en `dominion_awards`) plenamente operativo en el backend… sin endpoint REST que lo convoque ni control en el lienzo que lo dispare.

La Colección del Adepto cierra ambas brechas con una sola ceremonia de significado:

1. **El Tomo Personal** — cada adepto cultiva su propia colección de hechizos `validado` que ha jurado como suyos: los añade desde el catálogo canónico, los consulta en su grimorio personal y los convoca desde el Simulador con un gesto directo.
2. **El Elogio Popular** — cada adepto rinde homenaje a los hechizos de otros magos; ese elogio individual alimenta la métrica comunitaria que el Dominio semanal ya computa (los cinco PDA por elogio, con su bonificación de sinergia, ya existen en producción).

**Objetivo:** convertir la promesa del rótulo en operación real, y darle a la tabla `favorites` su superficie de ceremonia — sin alterar la maquinaria de Dominio ya ratificada (SPEC-07) ni la retención del umbral (SPEC-09), que se reutilizan tal cual.

## 2. Usuarios

- **Adepto linajado** (`editor`, `maestro`, `lector` o `admin_supremo` CON linaje jurado): coleccionista pleno. Añade y retira hechizos de su tomo, lo consulta, elogia hechizos de otros. Resolución de QA (hallazgo 16): **el derecho de colección y elogio emana del juramento, no del rol** — una sola regla para todos los roles.
- **Adepto sin linaje** (peregrino retenido por SPEC-09): la operación de colección **no está disponible** hasta el juramento. El gesto sobre «Añadir al tomo» reutiliza la retención de SPEC-09 — el intent se guarda y la ceremonia bloqueante conduce de vuelta. La contemplación sigue abierta (RF-05.1 de SPEC-03).
- **`lector` anónimo**: contemplación pública del catálogo validado, sin gestos de colección ni elogio.
- **`admin_supremo`**: igual que cualquier adepto en materia de colección; sin privilegios especiales sobre el tomo ajeno (la colección es íntima y personal).

## 3. Historias de Usuario

**HU-1 — La adición al tomo.** Como adepto linajado, contemplando un hechizo `validado` del catálogo canónico en el Simulador o la Biblioteca, quiero sellarlo en mi tomo personal con un gesto claro, para que mi colección refleje lo que estudio y convoco.

**HU-2 — La consulta del tomo.** Como adepto linajado, quiero abrir mi grimorio personal y ver solo los hechizos que he coleccionado — con su afinidad, coste de maná y estado — para tener mi repertorio a mano, filtrable por elemento.

**HU-3 — La convocatoria directa.** Como adepto linajado, quiero que cualquier hechizo de mi tomo se convoque en el Simulador con el mismo ritual que los del catálogo (modal de casta, partículas, conjuro), sin pasos intermedios, para que la colección sea práctica y no un adorno.

**HU-4 — El Elogio Popular.** Como adepto linajado, quiero elogiar el hechizo de otro mago con un gesto único y sobrio, para que su clan acumule gloria en el Dominio semanal y yo reconozca la obra que admiro.

**HU-5 — El peregrino contenida.** Como peregrino sin linaje, cuando intente coleccionar, quiero ser conducido con respeto al juramento — no rechazado con un error — conservando la intención para reanudarla tras el juramento.

## 4. Requisitos Funcionales (criterios EARS)

### RF-01: El Gesto de Adición al Tomo

- **RF-01.1** — CUANDO un adepto linajado active el gesto «Añadir al tomo» sobre un hechizo cuyo estado es `validado`, EL SISTEMA insertará la entrada en su colección personal y confirmará el sellado con un aviso discreto que nombre al hechizo.
- **RF-01.2** — CUANDO el gesto se active sobre un hechizo cuyo estado no es `validado`, EL SISTEMA negará el sellado con una leyenda solemne UNIFORME («solo lo validado entra al tomo») ante cualquier estado no validado, sin nombrar el estado concreto ni filtrar por rol (resolución de QA, hallazgo 4).
- **RF-01.3** — SI el hechizo ya figura en el tomo del adepto, CUANDO se active el gesto, EL SISTEMA responderá idempotentemente (sin duplicar la fila) y exhibirá el estado «Ya está en tu tomo». El sellado es un acto privado de estudio: **jamás acredita gloria** (resolución de QA, hallazgos 2-3; la gloria nace única y exclusivamente del elogio).
- **RF-01.4** — CUANDO un adepto sin linaje jurado active el gesto, EL SISTEMA retendrá la intención reutilizando el mecanismo de SPEC-09 con la acción `addToGrimoire` (ya existente en el catálogo de intents) y conducirá a la ceremonia del juramento; el retorno aterrizará en la vista retenida Y REANUDARÁ EL ACTO CONCRETO — la colección se completa sin que el adepto repita el gesto (resolución de QA, hallazgo 6).

### RF-02: La Consulta del Tomo Personal

- **RF-02.1** — CUANDO un adepto linajado abra su grimorio personal (nueva vista «Mi Grimorio», accesible desde la navbar con rótulo soberano y desde el rótulo «Ver mi libro personal» del umbral), EL SISTEMA presentará exclusivamente los hechizos de su colección, ordenados por el instante de adición (más reciente primero), con cada entrada portando su estado respecto al adepto (coleccionado, elogiado) ya embebido en la carga del listado.
- **RF-02.2** — CUANDO el tomo esté vacío, EL SISTEMA exhibirá un estado vacío que invite a explorar el catálogo canónico — con un gesto directo hacia la Biblioteca — sin lenguaje de error.
- **RF-02.3** — SI el adepto filtra el tomo por afinidad elemental, CUANDO el filtro esté activo, EL SISTEMA mostrará solo los hechizos de esa afinidad, conservando el orden de adición y el conteo visible del resultado.
- **RF-02.4** — CUANDO el adepto retire un hechizo de su tomo, EL SISTEMA pedirá confirmación solemne (modal), y al confirmarla eliminará la fila y actualizará el conteo sin recargar la página entera.

### RF-03: La Convocatoria desde el Tomo

- **RF-03.1** — CUANDO el adepto active el gesto de convocatoria sobre un hechizo de su tomo, EL SISTEMA abrirá el Simulador con el modal de casta del hechizo ya desplegado, reutilizando el motor de partículas y la pronunciación de SPEC-05 sin variantes nuevas.
- **RF-03.2** — SI el hechizo coleccionado perdiera el estado `validado` después del sellado, CUANDO el tomo se consulte, EL SISTEMA lo exhibirá con marcas solemnes según el MAPA ÚNICO DE ESTADOS (resolución de QA, hallazgos 12 y 21): `draft`/`experimental` visten **«obra en gestación»** (la obra madura, la convocatoria aguarda); `rejected`/`archived` visten **«obra retirada del canon»**. Ambas vedan la convocatoria y jamás eliminan la entrada de la colección por detrás.

### RF-04: El Elogio Popular

- **RF-04.0** — EL GESTO «Elogiar» vivirá en la tarjeta del hechizo, única y compartida por la Biblioteca y el Simulador, con su estado «Ya rendiste homenaje» visible en la misma tarjeta tras el homenaje. El estado de homenaje viaja EMBEBIDO en los listados del catálogo y del tomo (resolución de QA, hallazgo 5): la tarjeta nace sabiendo su estado y sobrevive a recargas sin peticiones extra.

- **RF-04.1** — CUANDO un adepto linajado active el gesto «Elogiar» sobre un hechizo `validado` de otro autor, EL SISTEMA registrará su voto único (invariante físico `UNIQUE(user_id, spell_id)` ya existente) y exhibirá el eco solemne del homenaje.
- **RF-04.2** — CUANDO el elogio se registre, EL SISTEMA invocará el servicio de Dominio existente (`awardCommunityFavorite`) para acreditar la gloria al clan del hechizo — sin modificar la maquinaria de SPEC-07; la spec solo le construye la puerta REST y el control del lienzo.
- **RF-04.3** — SI el adepto ya elogió ese hechizo, CUANDO se active el gesto, EL SISTEMA responderá con el estado «Ya rendiste homenaje» sin contar ni asentar gloria adicional (el servicio ya lo garantiza; la spec lo hace visible en la interfaz).
- **RF-04.4** — SI el adepto milita en la casa del hechizo (incluida la propia pluma), EL SISTEMA ocultará el gesto «Elogiar» con una leyenda sobria («Un hijo de la casa no hincha la gloria de su propio estandarte»). El guardia real del servicio es de MILITANCIA, no de autoría (resolución de QA, hallazgos 10 y 11): ante una llamada forzada, el servicio vivo de SPEC-07 responde con su recibo denegado (`OWN_CLAN_FAVORITE`) ANTES de insertar fila alguna — el voto no se registra, SPEC-07 queda intocada, y la interfaz comunica la denegación con sobriedad.
- **RF-04.5** — EL GESTO «Elogiar» no se presentará jamás sobre un hechizo que no esté `validado` (incluido uno coleccionado que cayó de estado): la gloria del Dominio solo nace de obra sellada (resolución de QA, hallazgo 15). El backend responderá 409 ante una llamada forzada sobre no validado.

### RF-05: Persistencia, Contratos y Retorno

- **RF-05.1** — EL SISTEMA expondrá los endpoints REST de colección y elogio bajo el patrón del front controller, con contratos JSON en **`camelCase`** para claves de datos (Artículo V de la Constitución; resolución de QA, hallazgos 8/19 — el borrador original decía `snake_case` por error) y códigos HTTP estándar del santuario (201 al sellar, 200 en consultas, 409 en colisiones idempotentes y elogios forzados sobre no validados, 401 sin sesión, 403 sin linaje en gestos de colección, 404 ante hechizo inexistente).
- **RF-05.2** — CUANDO la sesión expire con el tomo abierto, EL SISTEMA responderá con el flujo 401 del umbral, mostrará el aviso solemne de la sesión caducada y APAGARÁ los gestos (sellado, retirada, elogio), conservando la lectura de lo ya cargado en pantalla y el filtro activo — nada se borra ante los ojos del adepto (resolución de QA, hallazgo 7).
- **RF-05.3** — LA COLECCIÓN será perpetua salvo renuncia del adepto (RF-09 de SPEC-03): su purga de cuenta elimina el tomo íntegro por cascada, y el legado de «Erudito Ancestral» jamás preservará colección ajena.
- **RF-05.4** — EL MODELO DE DATOS del tomo será una TABLA NUEVA separada de `favorites` (resolución de QA, hallazgo 1): la colección y el elogio son ritos distintos y cada invariante vive en su mesa. `favorites` queda como mesa de votos del Dominio, intocada.
- **RF-05.5** — LA RETIRADA de un hechizo por su autor se materializará como `archived` (nunca borrado físico): la cascada `ON DELETE` de `favorites` y de la tabla de colección jamás disparará por una retirada, y la memoria del adepto queda a salvo (resolución de QA, hallazgo 14). Frontera declarada con SPEC-08.

### RF-06: La Bitácora de los Dos Actos (Artículo III.3)

- **RF-06.1** — CUANDO el adepto selle un hechizo en su tomo, EL SISTEMA asentará el acto en la Bitácora como acto canónico nuevo, con su discriminador propio y su estampa temporal (patrón de SPEC-10).
- **RF-06.2** — CUANDO el adepto rinda homenaje con gloria acreditada, EL SISTEMA asentará el elogio en la Bitácora: un acto que mueve PDA de Dominio exige constancia de quién movió la gloria (resolución de QA, hallazgo 20).

## 5. Requisitos No Funcionales

- **RNF-01 — Latencia de consulta:** la apertura del tomo personal responderá en menos de 100 ms de backend (mismo presupuesto que el RNF-02 de SPEC-06), con índice dedicado sobre `(user_id, added_at)` en la tabla de colección.
- **RNF-02 — Dogma Vanilla:** sin dependencias externas; PDO con consultas exclusivamente preparadas; ES Modules nativos en el frontend; el motor de partículas del Simulador no se duplica ni se toca.
- **RNF-03 — Soberanía lingüística:** todo rótulo, leyenda y aviso de la superficie de colección en castellano noble, sin referencias técnicas visibles (RF-xx, códigos de error); el guard de soberanía lingüística de SPEC-03 (RNF-05) se extiende a los nuevos módulos.
- **RNF-04 — Accesibilidad:** los gestos de colección y elogio serán operables por teclado, con `aria-pressed` en los conmutadores de estado y foco visible; el modal de retirada respeta el patrón de foco de SPEC-02.
- **RNF-05 — Movimiento reducido:** las animaciones de sellado y eco de elogio se degradan a transición de opacidad bajo `prefers-reduced-motion` (patrón ratificado en SPEC-06 y SPEC-10).

## 6. Casos Límite

1. **Peregrino intenta coleccionar o elogiar** — ambos gestos se retienen con las acciones `addToGrimoire` (ya existente en el catálogo de SPEC-09) y `givePraise` (nueva, enmienda menor y explícita a SPEC-09), y tras el juramento el retorno aterriza en la vista retenida Y reanuda el acto concreto (resolución de QA, hallazgo 9).
2. **Hechizo retirado del catálogo por su autor tras ser coleccionado** — la retirada se materializa como `archived` (nunca borrado físico, RF-05.5): el tomo conserva la entrada con la marca **«obra retirada del canon»**, la cascada jamás dispara y la entrada no se disuelve por detrás.
3. **Elogio de pluma propia** — el gesto nace oculto con leyenda sobria (RF-04.4); el backend responde con su recibo denegado sin registrar voto, como defensa de profundidad.
4. **Colección de gran volumen** — la vista del tomo pagina a 50 entradas por página, conservando el orden de adición y el conteo total visible.
5. **Doble pestaña / gesto concurrente** — el índice `UNIQUE` físico garantiza una sola fila; la respuesta idempotente (RF-01.3) hace el resto.
6. **Sesión expirada con el tomo abierto** — RF-05.2: 401, aviso solemne, gestos apagados, lectura y filtro conservados.
7. **Hechizo `validado` que vuelve a `experimental`** — RF-03.2: marca «obra en gestación», convocatoria y elogio vedados (RF-04.5), colección íntegra.
8. **Elogio justo en el cierre del ciclo semanal** — el servicio de Dominio ya determina el ciclo con su instante; la spec no añade lógica de ciclo.
9. **Retirada del tomo con elogio previo** — el elogio es un acto de gloria para el clan del autor, no una pata de la colección: la retirada del tomo no lo toca y la tarjeta conserva «Ya rendiste homenaje» en el catálogo (resolución de QA, hallazgo 13).
10. **Última entrada de una página intermedia retirada** — si la página corriente queda vacía y no es la primera, la vista reanuda en la página válida más cercana conservando el filtro: sin huecos ni pantallas fantasma (resolución de QA, hallazgo 17).
11. **Cambio de clan del elogiador tras el elogio** — la gloria es un asiento histórico del instante en que nació: la revisión retroactiva por cambio de militancia es patrimonio exclusivo de SPEC-07 (resolución de QA, hallazgo 18; frontera declarada).
12. **Adepto con rol `lector`** — el derecho emana del juramento, no del rol (Sección 2, hallazgo 16): un lector con linaje colecciona y elogia; quien carezca de linaje es retenido hacia el juramento.

## 7. Fuera de Alcance (Exclusiones Explícitas)

- Compartición externa de colecciones (enlaces públicos, exportación, redes).
- Ranking o escaparate de colecciones entre adeptos — el tomo es íntimo.
- Catálogo de criaturas y artefactos (libro futuro de SPEC-01; su propia spec).
- Gobernanza interna de clanes (patrimonio de SPEC-07/SPEC-10).
- Cualquier cambio a la maquinaria de Dominio, ciclos, PDA o sinergias (SPEC-07 queda intocada; solo se le construye la puerta REST del elogio).
- Notificaciones push o correos por elogio recibido.
- Revisión retroactiva de gloria por cambio de militancia del elogiador (patrimonio de SPEC-07; frontera declarada en el caso límite 11).

## 8. Criterios de Finalización y Aceptación

- [ ] RF-01 al RF-05 implementados con cobertura de arnés propio (PHP y Node según superficie), en verde y sin regresiones.
- [ ] El guard de soberanía lingüística extiende su auditoría a los módulos nuevos de colección (RNF-03).
- [ ] El arnés de latencia certifica la consulta del tomo bajo el presupuesto de RNF-01.
- [ ] La regresión completa del santuario (familias auth, clanes, dominio, simulador) en verde tras la integración.
- [ ] Recorrido manual en navegador documentado (evidencia del flujo completo: añadir → consultar → convocar → elogiar, y del peregrino retenido). **Evidencia nombrada:** `specs/11-adept-grimoire-collection.evidence-9.2.md` (los diez pasos del plan §6.3, con las seis incidencias corregidas de §10.1 y los seis hallazgos registrados de §10.2).
- [ ] Los hallazgos de la ronda de QA previa a la implementación quedan ratificados en esta spec con su registro de resoluciones (Sección 9).

## 9. Registro de Resoluciones de Diseño

### Primera ronda de QA (2026-09-22)

| # | Hallazgo | Resolución ratificada |
|---|---|---|
| 1 | Elogio del peregrino (caso límite 1) | Retención unificada: ambos gestos pasan por la ceremonia de SPEC-09; la intención se reanuda tras el juramento. |
| 2 | Hechizo retirado del catálogo (caso límite 2) | El tomo conserva la entrada con marca; jamás se disuelve por detrás. |
| 3 | Paginación del tomo (caso límite 4) | 50 entradas por página, con conteo total visible. |
| 4 | Ubicación del gesto «Elogiar» | Tarjeta única compartida por Biblioteca y Simulador; estado del homenaje visible en la misma tarjeta (RF-04.0). |
| 5 | Ubicación de la vista del tomo | Ruta propia «Mi Grimorio» con rótulo soberano en la navbar; el intent «Ver mi libro personal» del umbral apunta aquí, no al Simulador. |
| 6 | Elogio de pluma propia | Gesto oculto con leyenda sobria (RF-04.4 enmendado); el backend conserva la tolerancia idempotente como defensa de profundidad. |

### Segunda ronda de QA (2026-09-22) — hallazgos 1–21

| # | Hallazgo | Resolución ratificada |
|---|---|---|
| 1 | Modelo de datos indefinido | Tabla NUEVA separada de `favorites` (RF-05.4); cada rito, su mesa. |
| 2-3 | Colección mezclada con gloria | Ritos totalmente separados: el sellado jamás acredita gloria (RF-01.3 enmendado). |
| 4 | Leyenda de estados sin público definido | Leyenda UNIFORME ante cualquier no validado, sin revelar estado ni filtrar por rol (RF-01.2 enmendado). |
| 5 | Contrato del estado «Ya rendiste homenaje» | Estado embebido en los listados del catálogo y del tomo (RF-04.0 enmendado). |
| 6 | Retorno del peregrino sin semántica de acto | Ruta + reanudación del acto concreto (RF-01.4 enmendado). |
| 7 | «Lectura pública» del tomo tras 401 | Aviso solemne + gestos apagados, lectura y filtro conservados (RF-05.2 enmendado). |
| 8/19 | `snake_case` vs. Artículo V | **camelCase** (Artículo V); RF-05.1 corregido. |
| 9 | Nombres de intents contra el catálogo vivo | `addToGrimoire` reutilizado tal cual; `givePraise` nueva (enmienda menor y explícita a SPEC-09). |
| 10 | Voto de pluma propia contra el servicio vivo | Se respeta el servicio de SPEC-07: recibo denegado ANTES de insertar fila; spec corregida. |
| 11 | Guardia real de militancia vs. pluma propia | El gesto se oculta por MILITANCIA en la casa del hechizo, no solo por autoría (RF-04.4 enmendado). |
| 12 | Marcas sin mapa a estados reales | Mapa único: draft/experimental → «obra en gestación»; rejected/archived → «obra retirada del canon» (RF-03.2). |
| 13 | Retirada del tomo con elogio previo | Elogio perpetuo: cada rito vive su vida (caso límite 9). |
| 14 | Cascada `ON DELETE` rompe la memoria | Retirada = `archived`, nunca borrado físico (RF-05.5). |
| 15 | Elogio sobre no validados | Gesto oculto siempre; 409 ante llamada forzada (RF-04.5 nuevo). |
| 16 | Rol `lector` sin comportamiento definido | El linaje manda, no el rol (Sección 2 enmendada). |
| 17 | Página intermedia vaciada | Reanudar en la última página viva conservando el filtro (caso límite 10). |
| 18 | Gloria tras cambio de clan | Fuera de alcance: frontera con SPEC-07 declarada (caso límite 11, Sección 7). |
| 20 | Bitácora ausente | Asiento de sellado y elogio como actos canónicos (RF-06 nuevo). |
| 21 | «En revisión» ofende al Velo Arcano | Marcas solemnes «obra en gestación» / «obra retirada del canon» (RF-03.2). |

## 10. Tercera ronda — Recorrido manual de la Tarea 9.2 (2026-09-23)

Evidencia completa en `specs/11-adept-grimoire-collection.evidence-9.2.md` (los diez
pasos del plan §6.3).

### 10.1 Incidencias corregidas en el acto

| # | Hallazgo | Resolución ratificada |
|---|---|---|
| 1 | El gesto del tomo con **sesión viva** abría «Cruzar el Umbral» (un linajado debía cruzar el umbral otra vez para sellar) | El orquestador despacha el acto directo con la sesión viva y narra el desenlace (RF-01.1, RF-04.0) |
| 2 | El **peregrino** con sesión viva recibía el modal de acceso — contra la letra de RF-01.4 — y la ficha tapaba la ceremonia al conducirlo a ella | Retención + ceremonia, con la ficha retirada antes de navegar; el arnés de la Tarea 6.1 se realinea al contrato de la spec (RF-01.4) |
| 3 | Los gestos internos de la tarjeta **burbujeaban** hasta la activación: «Elogiar»/«Retirar del tomo» abrían además el Simulador con la obra | Guardia anti-burbujeo en la activación de la tarjeta, con tres asertos de regresión (RF-04.0, RF-02.4) |
| 4 | El nombre accesible anunciaba «escuela **undefined**» y la insignia de escuela nacía vacía en el Tomo | La tarjeta OMITE la parte que el DTO no porta; jamás una cáscara vacía (RNF-03) |
| 5 | `.lineage-card__expansion` vencía el atributo `[hidden]`: la ceremonia mostraba doctrina íntegra y **«Jurar» en las 8 tarjetas plegadas** | Regla `[hidden]` explícita, la misma disciplina que el resto de la ceremonia (RF-02.2 de SPEC-09) |
| 6 | El anuncio de impacto sin efecto encadenaba «no altera al maniquí **al maniquí de pruebas**» | El anuncio sin fragmentos narra «el maniquí de pruebas permanece intacto» (RNF-03) |
| 7 | **`#/grimorio` no era retenible** para el peregrino: ni `RETAINABLE_VIEWS` ni el mapa hash→vista del middleware conocían «collection», así que la retención del tomo se descartaba en silencio (lo delató el guard de paridad de mapas de SPEC-09, 17/18 en rojo) | `'#/grimorio' => 'collection'`, con el mismo precedente que `#/vestibulo` (SPEC-10); el guard de paridad vuelve a 18/18 (RF-02.1) |

### 10.2 Hallazgos registrados — requieren enmienda ratificada (No Spec, No Code)

| # | Hallazgo | Destino |
|---|---|---|
| 8 | **CRÍTICO — La retención de ruta del juramento jamás persiste:** no hay `session_start()` en el repositorio y la retención escribe/lee `$_SESSION` (array por petición). Verificado en vivo: sello con `retainedRoute: null` y retorno al portal (RF-03.1 de SPEC-09 incumplido); los arneses no lo ven porque escriben y leen en el mismo proceso | Enmienda a SPEC-09: persistir la ruta retenida en la sesión real (`user_sessions` vía `SessionManager`) |
| 9 | **RF-04.0 incompleto:** el gesto compartido no vive en las tarjetas de la Biblioteca ni del Simulador (el listado del catálogo no porta `adeptState` y la vista no cablea el gesto); hoy vive en la ficha y en el Tomo | Enmienda a SPEC-11 (Tareas 5.x/6.x) |
| 10 | `elementalAffinityLabel` no viaja en ningún DTO: la insignia elemental de toda tarjeta rotula «Arcano Puro» aunque el hechizo sea de rayo o viento | Enmienda cruzada (SPEC-05/06) |
| 11 | El rótulo «Ver mi libro personal» del umbral sigue abriendo el Simulador, contra la decisión §5 y RF-02.1; el arnés de SPEC-05 ratifica hoy ese contrato | Enmienda cruzada + realineación del arnés |
| 12 | Carrera de arranque: en un deep-link, la vista no exenta se monta antes de que `auth/session` hidrate el store, y el peregrino escapa del desvío en el primer pintado | Enmienda a SPEC-09 (puerta de arranque) |

### 10.3 Cierre de los hallazgos 8–12 (2026-09-23) — enmiendas implementadas y ratificadas

Los cinco hallazgos de §10.2 quedan CERRADOS con su enmienda implementada y su
arnés de regresión propio:

| # | Enmienda ratificada | Cobertura de arnés |
|---|---|---|
| 8 | **Persistencia de la retención (SPEC-09 enmendado):** la ruta retenida vive en el vínculo (`user_sessions.retained_route`) vía `SessionManager`, no en `$_SESSION`; saneada a la vista, caduca con el vínculo y la disolución la arrastra | `test_lineage_retained_route_persistence.php` (14/14, entre procesos) · `test_lineage_oath_middleware.php` (20/20) · `test_lineage_oath_controller.php` (24/24) |
| 9 | **RF-04.0 completo:** el catálogo `GET /api/v1/spells` embebe `adeptState` (`collected`/`praised`/`praiseAllowed`, camelCase) para el adepto linajado — jamás para anónimo ni peregrino — y el gesto compartido vive en las tarjetas de la Biblioteca y en la página iluminada del libro del Simulador; la marca viva se repinta sin recargar tras un acto confirmado | `test_catalog_adept_state.php` (14/14) · `test_library_view.mjs` Fase 6 (41/41) · `test_grimoire_simulator_view.mjs` Fase H8b (122/122) |
| 10 | **Etiqueta elemental canónica (enmienda cruzada SPEC-05/06):** el mapa único `Spell::ELEMENTAL_AFFINITY_LABELS` (espejo exacto del `ELEMENT_NAMES` del cliente) viaja en los DTOs de resumen y detalle; la tarjeta viste el elemento DECLARADO y solo cae al mapa por escuela cuando el hechizo no declara afinidad; el neutro y las afinidades ajenas rotulan «Arcano Puro» | `test_elemental_affinity_labels.php` (22/22) · `test_spell_card.mjs` Fase 6 (28/28) |
| 11 | **«Ver mi libro personal» apunta a Mi Grimorio (RF-02.1):** el gesto del umbral y el intent retenido conducen a `#/grimorio`, jamás al Simulador de ensayos; el arnés de ruta de SPEC-05 se realinea al contrato enmendado | `test_simulator_route.mjs` (42/42, fases C y D realineadas) |
| 12 | **Puerta de arranque (SPEC-09 enmendado):** con un deep-link a una vista no exenta, el arranque ESPERA a que `auth/session` hidrate el store antes del primer montaje; el peregrino es recibido por la ceremonia con su ruta retenida, el linajado entra directo y la lectura pública anónima no espera a nadie | `test_intent_give_praise.mjs` Fase 6 (35/35) |
