# SPEC-09 — Juramento de Linaje en el Primer Acceso

> **Prioridad:** Fundamental (Identidad Arcana, Onboarding y Gobernanza de Linajes)
> **Estado:** RATIFICADA — flujo SDD completo (spec revisada por QA, plan técnico y tareas). Lista para implementación según `specs/09-lineage-oath-first-access.tasks.md`.
> **Plan Técnico:** [`specs/09-lineage-oath-first-access.plan.md`](09-lineage-oath-first-access.plan.md) | **Tareas:** [`specs/09-lineage-oath-first-access.tasks.md`](09-lineage-oath-first-access.tasks.md)
> **Specs relacionadas:** SPEC-03 (Consagración y RBAC — enmendada por esta spec), SPEC-07 (Linajes y Clanes — se respeta y sirve de frontera), SPEC-01 (Portal y Navegación — se extiende), SPEC-02 (Sistema de Diseño — se acata)

---

## 1. Contexto y Objetivo

El Grimorio Interactivo organiza su comunidad en los **Ocho Linajes Mágicos Canónicos** (SPEC-07, RF-02.1): órdenes elementales perpetuas — Llama Primordial, Mareas Celestiales, Tempestad Eterna, Raíces del Mundo, Vientos del Alba, Corona Solar, Sombras Abisales y Tejedores del Éter — bajo las que luego se fundan hermandades concretas (clanes). El linaje es la identidad arcana del adepto; el clan, su milicia.

Hoy, la consagración (registro, SPEC-03) exige elegir ese vínculo dentro de un formulario denso de credenciales, mediante un desplegable de linajes sin formato ni contexto. El nuevo adepto jura lealtad sin saber qué jura: ni doctrina, ni elemento rector, ni heráldica — una decisión de identidad tomada a ciegas en el peor momento posible (el registro es para credenciales, no para vocaciones).

**Objetivo:** desplazar el juramento del linaje fuera del formulario de registro y convertirlo en una **ceremonia propia y bloqueante del primer acceso**: una pantalla solemne donde el adepto contempla los Ocho Linajes con su doctrina y heráldica, y jura uno de forma **irrevocable** antes de pisar el resto del santuario. El registro queda reducido a credenciales; la identidad se elige con conocimiento de causa.

**Principios rectores:**
1. **La identidad se elige con contexto, no en un desplegable.** Cada linaje expone su doctrina, su elemento rector y su heráldica antes de admitir un juramento.
2. **El juramento es perpetuo.** La ceremonia exige un consentimiento activo en dos pasos (modal solemne de doble confirmación); ninguna vista posterior permite revocarlo.
3. **El bloqueo es de sustancia, no de fachada.** La retención se respalda en el backend: ningún flujo del santuario es operable por cuenta sin linaje, ni siquiera atacando la API directamente.
4. **Ceremonia, no trámite.** El momento merece la solemnidad del Velo Arcano (SPEC-02): es la primera impresión del adepto consagrado.

---

## 2. Usuarios

| Usuario | Relación con esta spec |
|---|---|
| **Adepto recién consagrado** (`editor` nuevo) | Protagonista: tras registrarse e iniciar sesión, aterriza en la ceremonia mientras su linaje sea nulo. |
| **Adepto legado sin linaje** (cuenta existente anterior al despliegue que carezca de linaje) | Aterriza igualmente en la ceremonia al iniciar sesión, sin excepción, mientras su linaje sea nulo. |
| **Adepto linajado** | Exento para siempre: jamás ve la ceremonia ni padece retención alguna. |
| **Visitante anónimo** (`reader`) | No ve la ceremonia: ni registro ni portal bloqueado le atañen; solo consulta pública. |
| **Admin Supremo** (`supremeAdmin`) | **Exento por privilegio fundacional** de la ceremonia y de la retención, con o sin linaje legado; si careciera de linaje, su perfil lo declara como peregrino. La administración de linajes permanece en su dominio (SPEC-07). |
| **Maestro** (`master`) | Su designación exige linaje jurado previo (RF-05.2): jamás existe un Maestro en la ventana sin linaje. |

---

## 3. Historias de Usuario

* **HU-01 (Ceremonia del Primer Juramento):**
  *Como* adepto recién consagrado que acaba de crear sus credenciales,
  *quiero* contemplar los Ocho Linajes Canónicos con su doctrina, elemento rector y heráldica, y jurar el que resuene con mi vocación tras una doble confirmación solemne,
  *para* nacer en el santuario con una identidad elegida con conocimiento de causa y no a ciegas en un formulario.

* **HU-02 (Entrada Bloqueante con Dignidad):**
  *Como* adepto sin linaje,
  *quiero* que el portal — vistas y operaciones — me retenga hasta jurar,
  *para* que la decisión que define mi pertenencia y mi competencia semanal sea ineludible y no burlable.

* **HU-03 (Arrepentimiento Antes del Sello):**
  *Como* adepto indeciso ante tamaña responsabilidad,
  *quiero* poder abandonar la sesión sin jurar y volver más tarde a la misma ceremonia intacta,
  *para* no quedar atrapado en un juramento precipitado ni perder el acceso a mi cuenta ya creada.

* **HU-04 (Legado Sin Linaje):**
  *Como* adepto antiguo cuya cuenta precede a esta ceremonia,
  *quiero* que mi regreso me conduzca a la misma ceremonia solemne mientras no haya jurado,
  *para* quedar integrado en la gobernanza de linajes en igualdad de condiciones que los nuevos.

---

## 4. Requisitos Funcionales (criterios EARS)

### RF-01: Desplazamiento del Juramento fuera del Registro
* **RF-01.1 [Ubicuo]:**
  El sistema DEBERÁ presentar la consagración (registro) solicitando únicamente nombre de iniciado, correo electrónico y frase de paso; la selección de linaje DEBERÁ eliminarse del formulario de registro. *(Enmienda a SPEC-03 ya ejecutada: RF-01.1/01.2 y HU-01 de SPEC-03.)*
* **RF-01.2 [Dirigido por Eventos]:**
  CUANDO la consagración se complete con datos válidos, el sistema DEBERÁ crear la cuenta con rol `editor`, **sin linaje asignado** (`lineage: null`), e iniciar la sesión del usuario.
* **RF-01.3 [Ubicuo]:**
  MIENTRAS una cuenta autenticada posea linaje nulo — sin importar cuántas sesiones transcurran — el sistema DEBERÁ retenerla fuera de todo flujo del santuario.
  **Criterios de aceptación:**
  * WHEN el adepto sin linaje solicita cualquier vista del portal (biblioteca, simulador, taller, clanes, bitácora…), THEN el frontend le conduce a la ceremonia.
  * WHEN el adepto sin linaje invoca por API cualquier endpoint de escritura u operación del santuario distinto de los permitidos (RF-01.4), THEN el backend rechaza con error controlado y solemne (`LINEAGE_OATH_REQUIRED`), jamás ejecutando la operación.
  * IF la retención intercepta una ruta o llamada distinta del propio flujo de juramento, THEN la ruta o intención queda registrada en la sesión del servidor para el retorno post-juramento (RF-03.1).
* **RF-01.4 [Ubicuo]:**
  El sistema DEBERÁ admitir, para cuentas sin linaje, únicamente: la ceremonia y sus datos (catálogo de linajes, juramento), el perfil de credenciales (consulta y cambio de frase de paso), el cierre de sesión y la lectura pública que ya corresponde a `reader`.
* **RF-01.5 [Dirigido por Eventos]:**
  CUANDO el despliegue entre en vigor, todo usuario autenticado sin linaje — nuevo o legado — DEBERÁ aterrizar en la ceremonia en su siguiente inicio de sesión, sin excepción, y permanecer retenido conforme a RF-01.3 hasta jurar.
* **RF-01.6 [Ubicuo]:**
  El sistema DEBERÁ eximir de la ceremonia y de la retención a todo usuario con linaje ya jurado y al Admin Supremo (privilegio fundacional, con o sin linaje legado): sus navegaciones jamás serán interrumpidas por esta pantalla.
* **RF-01.7 [Dirigido por Eventos]:**
  SI el adepto cerró sesión a mitad de ceremonia, ENTONCES su siguiente autenticación DEBERÁ devolverle a la ceremonia intacta (el estado de linaje nulo es persistente y el bloqueo no es un evento de un solo uso).

### RF-02: La Ceremonia — Presentación de los Ocho Linajes
* **RF-02.1 [Ubicuo]:**
  La pantalla de juramento DEBERÁ exhibir los Ocho Linajes Canónicos (SPEC-07, RF-02.1) en tarjetas heráldicas que muestren, como mínimo: nombre solemne, glifo/blasón rúnico, color de estandarte ceremonial, afinidad elemental rectora y una **doctrina condensada** (1–2 frases en noble castellano) derivada del texto canónico del linaje.
  **Criterios de aceptación:**
  * WHEN la ceremonia se renderiza, THEN las ocho tarjetas aparecen con su heráldica y doctrina condensada, sin datos de clanes concretos.
  * IF un linaje carece de clanes activos que lo rueguen, THEN su tarjeta permanece plenamente elegible y exhibe una nota discreta («Sin hermandades activas»), sin atenuar su dignidad.
  * IF la carga del catálogo de linajes falla, THEN la ceremonia muestra un aviso solemne controlado («El canon no responde») con acción de reintento, sin exponer trazas y sin liberar la retención (RF-05.1).
* **RF-02.2 [Dirigido por Eventos]:**
  CUANDO el adepto seleccione una tarjeta, el sistema DEBERÁ expandirla revelando la **doctrina íntegra** (2–4 frases canónicas del linaje) y la afirmación solemne del juramento (texto ceremonial en primera persona que nombre al linaje elegido). Tarjeta contraída y expandida derivan del mismo texto canónico único por linaje; no existen dos doctrinas independientes.
* **RF-02.3 [Dirigido por Eventos]:**
  CUANDO el adepto pulse la acción de jurar sobre un linaje expandido, el sistema DEBERÁ presentar un **modal solemne de doble confirmación** que muestre el texto íntegro del juramento en primera persona junto a la advertencia de perpetuidad, exigiendo una segunda confirmación explícita («Sellar el juramento») para consumar el vínculo.
  **Criterios de aceptación:**
  * WHEN el modal se muestra, THEN la advertencia de irrevocabilidad es visible, explícita e ineludible (jamás letra menuda ni *tooltip*).
  * IF el adepto descarta el modal, THEN no se consume juramento alguno y la ceremonia permanece operativa.

### RF-03: El Juramento — Confirmación Irrevocable
* **RF-03.1 [Dirigido por Eventos]:**
  CUANDO el juramento se selle en el modal de doble confirmación, el sistema DEBERÁ vincular la cuenta a ese linaje de forma **permanente**, registrar el acto en la Bitácora de Auditoría (SPEC-03) y conducir al adepto a la ruta que la retención registró en su sesión (RF-01.3); si no existe ruta retenida o caducó con la sesión, al portal de inicio.
* **RF-03.2 [Dirigido por Eventos]:**
  SI la petición de juramento falla (error del santuario, sesión caducada), ENTONCES el sistema DEBERÁ responder con un aviso controlado y solemne sin exponer trazas internas, conservando la ceremonia operativa para el reintento (reautenticando si la sesión caducó).
* **RF-03.3 [Ubicuo]:**
  El backend DEBERÁ resolver la petición de juramento de forma **idempotente y serializada por cuenta**: un linaje ya vinculado jamás muta.
  **Criterios de aceptación:**
  * WHEN una cuenta sin linaje sella un juramento, THEN el vínculo se crea y la Bitácora registra usuario y linaje jurado.
  * WHEN un segundo envío con el MISMO linaje llega a una cuenta ya linajada (doble clic, reintento de red), THEN el backend responde éxito sin mutación alguna (idempotencia).
  * WHEN un envío con un linaje DISTINTO llega a una cuenta ya linajada, THEN el backend lo rechaza de forma controlada con aviso solemne (sin mutación).
  * WHEN dos juramentos de linajes distintos están en vuelo simultáneamente para la misma cuenta (dos pestañas), THEN el backend los serializa: el primero confirma y el segundo se resuelve con las reglas anteriores — un solo ganador determinista.
  * WHEN cualquier reedición del juramento llega de un Admin Supremo o de un adepto ya linajado, THEN se aplica la misma idempotencia: el vínculo jamás se reescribe ni se revoca.
* **RF-03.4 [Ubicuo]:**
  NINGUNA vista, acción o administrativo posterior DEBERÁ ofrecer cambio o revocación del linaje jurado; la única operación de escritura permitida sobre el vínculo es el juramento inicial de una cuenta con linaje nulo. La cuenta purgada y re-creada nace sin linaje y jura de nuevo: el vínculo muere con la cuenta y no hay herencia entre cuentas.
* **RF-03.5 [Dirigido por Eventos]:**
  CUANDO el adepto aún indeciso abandone la sesión desde la ceremonia, el sistema DEBERÁ permitir el cierre de sesión sin juramento, conservando la cuenta creada y el estado «sin linaje».

### RF-04: Coherencia con la Gobernanza de Clanes (frontera con SPEC-07)
* **RF-04.1 [Ubicuo]:**
  El juramento de linaje DEBERÁ ser independiente de la pertenencia a clan: jurar un linaje no afilia a ningún clan, ni consume vacantes, ni activa convalecencias.
* **RF-04.2 [Ubicuo]:**
  El linaje jurado DEBERÁ ser el filtro rector de la posterior adhesión a clanes conforme a SPEC-07: los clanes que el adepto pueda fundar o solicitar pertenecerán a su linaje jurado.
* **RF-04.3 [Ubicuo]:**
  MIENTRAS el linaje sea nulo, la identidad visible del adepto DEBERÁ declarar el estado solemne de **peregrino iniciático** (rótulo «Peregrino sin Linaje», sin heráldica) en perfil y rótulos del santuario; tras el juramento, la identidad DEBERÁ integrar la heráldica del linaje con la misma representación que usa SPEC-07.
* **RF-04.4 [Ubicuo]:**
  La convalecencia de clan (SPEC-07, RF-01.6) DEBERÁ carecer de toda interacción con el juramento de linaje: son vínculos distintos, y un adepto en convalecencia sin linaje jura normalmente (su penitencia solo ata a clanes).

### RF-05: Retención de Sustancia (Backend)
* **RF-05.1 [Ubicuo]:**
  El backend DEBERÁ denegar toda operación de escritura y de gestión a cuentas con linaje nulo, salvo: el juramento mismo, la consulta del catálogo de linajes, el perfil de credenciales y el cierre de sesión; la lectura pública inherente al rol `editor` (equivalente a `reader`) permanece operativa. La denegación jamás libera la retención ante fallos de carga de la ceremonia: sin datos del canon no hay juramento, y sin juramento no hay santuario.
* **RF-05.2 [Ubicuo]:**
  El sistema DEBERÁ exigir linaje jurado previo para la designación de Maestro (`master`): nadie es elevado al oficio validador desde la ventana sin linaje, garantizando que el conflicto de intereses del Artículo III tenga siempre un sujeto ético determinado.
* **RF-05.3 [Ubicuo]:**
  La ruta retenida para el retorno post-juramento DEBERÁ residir en la sesión del servidor (no en la URL ni en el cliente), caducando con la sesión; su contenido se sanea para admitir únicamente rutas internas del portal.

---

## 5. Requisitos No Funcionales

* **RNF-01 (Solemnidad del Velo Arcano):** La ceremonia seguirá el sistema de diseño (SPEC-02): heráldica de los linajes, oro arcano sobre tintas oscuras, tipografía ceremonial; una primera impresión digna del juramento que se solicita.
* **RNF-02 (Soberanía Lingüística):** Doctrinas, juramento, advertencias y avisos, íntegramente en noble castellano (Artículo V); identificadores técnicos en inglés. El vocabulario ceremonial evitará jerga técnica moderna (los «modales» y «reintentos» se vestirán de liturgia: «sello», «el canon no responde»…).
* **RNF-03 (Claridad de la Irrevocabilidad):** La advertencia de perpetuidad DEBERÁ ser visible, explícita e ineludible dentro del modal de doble confirmación; jamás escondida en letra menuda ni en un aviso flotante auxiliar.
* **RNF-04 (Rendimiento):** La ceremonia se renderiza con los datos de linajes servidos por el santuario en una sola carga; la retención (frontend y backend) no añadirá sobrecoste perceptible al usuario con linaje ya jurado ni a la verificación de bloqueo, y el criterio de aceptación es que la comprobación de linaje no añada latencia mensurable en el orden de la de cualquier control de sesión existente.
* **RNF-05 (Accesibilidad WCAG 2.1 AA):** Contraste ≥ 4.5:1, navegación completa por teclado (tarjetas enfocables, expansión y modal operables con Enter/barra espaciadora, foco atrapado en el modal y devuelto al cerrarlo), regiones vivas ARIA que anuncien la selección, el modal y el veredicto del juramento, y respeto a `prefers-reduced-motion`.
* **RNF-06 (Trazabilidad):** Todo juramento consumado queda registrado de forma inmutable en la Bitácora de Auditoría (Artículo III), con identidad del adepto, linaje jurado y estampa temporal.
* **RNF-07 (Ámbito de la Degradación):** El portal es una SPA de módulos ES nativos (Artículo I): sin JavaScript el santuario entero es inoperante por diseño, y la ceremonia no introduce ningún requisito de degradación adicional al ya asumido por el portal.

---

## 6. Casos Límite

1. **Sesión cerrada a mitad de ceremonia:** la cuenta queda con linaje nulo; el reingreso conduce de nuevo a la ceremonia (RF-01.3, RF-01.7).
2. **Pestañas múltiples — navegación:** si el adepto jura en una pestaña, las demás reflejarán el linaje ya jurado en su siguiente carga de vista; jamás permitirán un segundo juramento.
3. **Pestañas múltiples — juramentos concurrentes de linajes distintos:** el backend serializa las peticiones por cuenta; el primero confirma y el segundo se resuelve por idempotencia (éxito si coincide, rechazo solemne si difiere). Un solo ganador determinista (RF-03.3).
4. **Doble envío del mismo juramento (doble clic / reintento de red):** idempotente — éxito sin mutación si el linaje coincide; rechazo solemne si difiere (RF-03.3).
5. **Canon inmutable:** los Ocho Linajes no admiten altas, bajas, suspensiones ni variación administrativa de estado alguno (exclusión 5 y SPEC-07); la validación en confirmación es exclusivamente la existencia del linaje en el canon. No existe «carrera de administración» sobre linajes.
6. **Linaje sin clanes activos:** permanece elegible con nota discreta; la identidad de linaje no depende de la existencia de hermandades (RF-02.1).
7. **Adepto legado con linaje ya asignado históricamente:** queda exento para siempre de la ceremonia (RF-01.6).
8. **Admin Supremo:** exento por privilegio fundacional, con o sin linaje legado; si careciera de él, su identidad visible lo declara peregrino (RF-01.6, RF-04.3).
9. **Sesión caducada sobre la ceremonia:** la confirmación responde con aviso solemne y, tras reautenticar, la ceremonia reaparece intacta (RF-03.2).
10. **Fallo de carga del catálogo de linajes:** aviso solemne controlado con reintento; la retención se mantiene (RF-02.1, RF-05.1).
11. **Adepto en convalecencia sin linaje:** jura con normalidad; la convalecencia solo ata a clanes (RF-04.4).
12. **Cuenta purgada y re-creada por la misma persona:** la nueva cuenta nace sin linaje y jura de nuevo; no existe herencia de linaje entre cuentas (RF-03.4).
13. **Ruta retenida caducada:** si la sesión expiró antes del juramento, la ruta retenida muere con ella y el retorno aterriza en el portal de inicio (RF-03.1, RF-05.3).
14. **Maestro designado:** jamás existe un Maestro sin linaje — la designación exige juramento previo (RF-05.2).

---

## 7. Fuera de Alcance (Exclusiones Explícitas)

1. **Adhesión a clanes concretos:** elegir, solicitar o fundar clan, regímenes de admisión, vacantes, convalecencias, expulsiones y PDA — todo pertenece a SPEC-07. Esta spec termina en el juramento del linaje.
2. **Cambio o revocación del linaje:** el juramento es perpetuo; no existe flujo de cambio ni de renuncia (RF-03.4).
3. **Registro de clanes por parte de linajes sin representación:** la creación de hermandades sigue regida por SPEC-07 (RF-01.2).
4. **Pantallas de «bienvenida» genéricas, tutoriales o recorridos guiados** posteriores al juramento: fuera de esta spec.
5. **Cambios en los Ocho Linajes Canónicos** (altas, bajas, suspensiones, doctrinas): el canon es inmutable por SPEC-07 y por esta spec (caso límite 5); ninguna operación administrativa lo muta.
6. **Recuperación de credenciales y flujos de autenticación:** SPEC-03.
7. **Ciclo de vida de cuentas (purga, suspensión, re-creación y su historial ético):** gestión del Admin Supremo fuera del alcance técnico de esta spec; esta spec solo declara que el vínculo de linaje muere con la cuenta (RF-03.4).

---

## 8. Criterios de Finalización y Aceptación

* [ ] El formulario de registro solicita únicamente alias, correo y frase de paso (sin desplegable de linajes), conforme a la SPEC-03 enmendada.
* [ ] Todo usuario autenticado sin linaje — nuevo o legado — aterriza en la ceremonia y permanece retenido mientras su linaje sea nulo, sin límite de sesiones.
* [ ] La retención es de sustancia: el backend rechaza con `LINEAGE_OATH_REQUIRED` toda operación no permitida de una cuenta sin linaje, incluyendo llamadas directas a la API.
* [ ] Las ocho tarjetas heráldicas muestran nombre, blasón, estandarte, elemento rector y doctrina condensada; la expansión revela la doctrina íntegra y el texto del juramento.
* [ ] El juramento exige modal solemne de doble confirmación con advertencia de perpetuidad visible e ineludible.
* [ ] El juramento sellado vincula la cuenta de forma permanente con registro en la Bitácora de Auditoría, resuelve idempotentemente los reenvíos y serializa los concurrentes.
* [ ] Tras jurar, el adepto aterriza en la ruta retenida en su sesión o, en su defecto, en el portal de inicio.
* [ ] El cierre de sesión sin jurar conserva la cuenta y devuelve a la ceremonia en el reingreso; la identidad visible del peregrino declara «Peregrino sin Linaje».
* [ ] Ninguna vista o endpoint posterior permite cambio o revocación del linaje jurado.
* [ ] La designación de Maestro exige linaje jurado previo.
* [ ] La ceremonia respeta WCAG 2.1 AA (teclado, contraste, ARIA con foco atrapado en el modal, movimiento reducido) y el Velo Arcano de SPEC-02.
* [ ] La consola queda limpia (sin errores) durante toda la ceremonia, el fallo de carga simulado y la navegación bloqueada.

---

## 9. Registro de Decisiones (dudas abiertas resueltas)

* **[RESUELTA] Retorno tras jurar:** la ruta intentada se retiene en la sesión del servidor y, tras jurar, el adepto aterriza en ella; sin ruta retenida o con sesión caducada, en el portal de inicio (RF-03.1, RF-05.3).
* **[RESUELTA] Doctrinas de los linajes:** la spec fija la estructura (condensada de 1–2 frases en tarjeta, íntegra de 2–4 frases en expansión, ambas derivadas de un texto canónico único por linaje). La autoría de los 8 textos canónicos se abordará en el plan técnico como borrador para revisión del Arquitecto.
* **[RESUELTA] Salud visual de la ceremonia:** puramente doctrinal y atemporal — sin contadores de adeptos ni métricas demográficas (RF-02.1).
* **[RESUELTA] Admin Supremo:** exento por privilegio fundacional de la ceremonia y la retención, con o sin linaje legado (RF-01.6); su identidad visible lo declara peregrino si careciera de linaje (RF-04.3).
