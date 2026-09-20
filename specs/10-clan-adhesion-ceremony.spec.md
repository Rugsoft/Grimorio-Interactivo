# SPEC-10 — Ceremonia de Adhesión a Clanes del Propio Linaje

> **Prioridad:** Fundamental (Pertenencia, Onboarding de Hermandades y Gobernanza de Clanes)
> **Estado:** Borrador saneado tras QA rigurosa (23 hallazgos detectados: 17 decisiones ratificadas, 4 hallazgos mecánicos parcheados, resolución de las 4 dudas abiertas) — listo para plan técnico
> **Specs relacionadas:** SPEC-07 (Clanes, Linajes y Dominio — se acata y se sirve), SPEC-09 (Juramento de Linaje — se respeta como frontera y fuente del filtro), SPEC-02 (Sistema de Diseño — se acata), SPEC-03 (RBAC y Bitácora — se acata), SPEC-01 (Portal y Navegación — se extiende)

---

## 1. Contexto y Objetivo

SPEC-09 condujo al adepto por una ceremonia solemne de juramento: contempló los Ocho Linajes Canónicos con su doctrina y heráldica, y selló uno de forma irrevocable. Ese juramento es la identidad arcana; el clan, la milicia donde la identidad se ejerce (SPEC-07). La frontera quedó ratificada en SPEC-09 (RF-04.2): *el linaje jurado es el filtro rector de la posterior adhesión a clanes*.

Hoy, sin embargo, la adhesión a una hermandad carece de experiencia propia: SPEC-07 define las mecánicas (regímenes de admisión `open`/`byApplication`, capacidad de 30 adeptos, convalecencia de 14 días, límite de 3 solicitudes pendientes), pero el adepto linajado no dispone de un lugar solemne donde **contemplar las hermandades de su linaje y contraer la membresía con conocimiento de causa** — la misma carencia que SPEC-09 sanó para el linaje.

**Objetivo:** instituir la **Ceremonia de Adhesión** bajo el rótulo canónico **«Vestíbulo de las Hermandades»**: una vista solemne y **voluntaria** donde el adepto linajado contempla únicamente los clanes activos que ruegan su linaje jurado, y contrae la membresía mediante el rito que corresponde al régimen de admisión de cada casa — ingreso inmediato solemne para clanes abiertos, petición formal escrita para clanes bajo dictamen. La contemplación jamás se bloquea; los gestos se vedan con leyenda solemne cuando el estado del adepto lo impide (convalecencia, membresía vigente).

**Principios rectores:**
1. **La pertenencia se elige con contexto, no como trámite.** Cada casa exhibe su estandarte, lema, régimen, plenitud y honor antes de admitir el gesto de ingreso.
2. **Lo vedado no se exhibe.** La ceremonia muestra solo los clanes del linaje jurado; los estandartes de otros linajes no aparecen atenuados ni vetados: sencillamente no existen para el adepto.
3. **La contemplación es libre; la sustancia, blindada.** Cualquier adepto puede entrar a contemplar; el backend veda la adhesión según el estado real (linaje, convalecencia, capacidad, membresía) — la interfaz nunca es la única guardia.
4. **El ermitaño es una condición digna, no un estado de error.** Nadie está obligado a militar; la ceremonia invita, jamás retiene.

---

## 2. Usuarios

| Usuario | Relación con esta spec |
|---|---|
| **Adepto linajado sin clan** (`editor`/`master`) | Protagonista: contempla las hermandades de su linaje y contrae membresía por el rito del régimen de cada casa. |
| **Adepto linajado ya militante** | La ceremonia le muestra su propia casa como tal, sin acción de ingreso; puede contemplar las demás sin gesto operativo. |
| **Adepto en convalecencia** (14 días, SPEC-07 RF-01.6) | Contempla libremente; sus gestos de adhesión se sustituyen por la leyenda solemne de descanso con los días restantes. |
| **Adepto sin linaje** (peregrino, SPEC-09) | Jamás accede a la ceremonia: la retención de SPEC-09 lo mantiene fuera de todo flujo del santuario. |
| **Visitante anónimo** (`reader`) | No accede a la ceremonia; su contemplación pública permanece en el Salón de los Linajes (SPEC-07 RF-06). |
| **Patriarca / Matriarca** | Fuera del alcance de esta spec en su faceta de gobierno (deliberación de solicitudes, SPEC-07); esta spec solo exige que ambos lados compartan el mismo contrato de solicitud. |

---

## 3. Historias de Usuario

* **HU-01 (Contemplación con Causa):**
  *Como* adepto linajado que aún no milita,
  *quiero* contemplar las hermandades activas de mi linaje jurado con su estandarte, lema, régimen y plenitud,
  *para* elegir casa con conocimiento de causa y no por un desplegable o un listado frío.

* **HU-02 (Ingreso Inmediato Solemne):**
  *Como* adepto linajado ante una hermandad de régimen abierto con vacantes,
  *quiero* confirmar mi ingreso en un modal solemne que nombre la casa y advierta la lealtad indivisible,
  *para* militar de inmediato sabiendo exactamente a qué me comprometo.

* **HU-03 (Petición Formal):**
  *Como* adepto linajado ante una hermandad que admite por solicitud,
  *quiero* redactar una petición formal en noble castellano y saber que quedó a la espera del dictamen del Patriarca,
  *para* postular con dignidad y sin incertidumbre sobre el estado de mi solicitud.

* **HU-04 (Arrepentimiento Antes del Dictamen):**
  *Como* postulante que cambió de parecer,
  *quiero* retirar una solicitud pendiente en cualquier momento,
  *para* liberar mi cupo de peticiones y no dejar peticiones huérfanas tras mi paso.

* **HU-05 (Contemplación sin Derecho, con Dignidad):**
  *Como* adepto en convalecencia arcana o ya militante,
  *quiero* seguir contemplando la ceremonia con mis gestos vedados por leyenda solemne,
  *para* no sentirme expulsado del santuario social mientras mi estado lo impide.

---

## 4. Requisitos Funcionales (criterios EARS)

### RF-01: La Puerta de las Hermandades — Naturaleza de la Ceremonia
* **RF-01.1 [Ubicuo]:**
  El sistema DEBERÁ ofrecer una vista solemne y **voluntaria** de adhesión, rotulada canónicamente **«Vestíbulo de las Hermandades»**, accesible para todo adepto linajado (`editor`, `master`, `supremeAdmin` linajado) por **doble vía**: rótulo propio en la navegación del santuario Y llamamiento equivalente desde el Salón de los Linajes (SPEC-07 RF-06); MIENTRAS el adepto no la visite, NINGÚN flujo del santuario DEBERÁ verse bloqueado por su existencia.
  **Criterios de aceptación:**
  * WHEN un adepto linajado navega el santuario sin visitar el Vestíbulo, THEN ninguna vista le retiene ni le redirige a él.
  * WHEN un adepto sin linaje (peregrino, SPEC-09) intenta acceder al Vestíbulo, THEN la retención de SPEC-09 lo conduce a la ceremonia del juramento; la adhesión jamás le es ofrecida.
  * WHEN un visitante anónimo (`reader`) intenta acceder al Vestíbulo, THEN el sistema le niega el paso de forma controlada y le mantiene en la contemplación pública del Salón de los Linajes (SPEC-07 RF-06).
  * WHEN el Admin Supremo sin linaje (exento del juramento por privilegio fundacional, SPEC-09) intenta acceder al Vestíbulo, THEN el sistema le niega el paso con aviso solemne controlado («El Privilegio Fundacional te exime del juramento; sin linaje jurado no hay hermandades que contemplar») y por API sus gestos reciben el error propio `ADMIN_LINEAGE_REQUIRED` — ni la retención de SPEC-09 ni el filtro de linaje le atañen.
  * WHEN el acceso al Vestíbulo se renderiza (en cualquiera de sus dos vías) y existen veredictos de dictamen no contemplados por el adepto, THEN el acceso luce el rótulo distintivo «Tienes dictámenes a la espera», que se apaga una vez contemplados dentro del Vestíbulo (RF-03.4).
* **RF-01.2 [Ubicuo]:**
  La ceremonia DEBERÁ exhibir ÚNICAMENTE los clanes en estado `active` cuyo linaje rector coincida con el linaje jurado del adepto; los clanes de otros linajes, los disueltos (`archived`) y los ocultos no DEBERÁN aparecer bajo ninguna forma (ni atenuados ni vetados). El catálogo no DEBERÁ admitir parámetro de filtro alguno: el servidor deriva el linaje de la sesión.
  **Excepción legada declarada:** SI un adepto legado milita en una casa cuyo linaje diverge del jurado (datos históricos previos a SPEC-09), ENTONCES su propia casa DEBERÁ mostrarse como entrada única especial («Tu hermandad»), pese a la divergencia, sin gesto operativo ni migración forzosa; el resto del catálogo permanece filtrado.
  **Criterios de aceptación:**
  * WHEN la ceremonia se renderiza para un adepto linajado, THEN solo aparecen casas de su linaje jurado, en estado activo — más su casa propia si el legado diverge.
  * IF un gesto de adhesión (ingreso o postulación) apunta a un clan de linaje distinto del jurado (llamada directa a la API incluida), THEN el backend lo rechaza de forma controlada con error solemne (`CLAN_LINEAGE_MISMATCH`); el error JAMÁS se emite por la lectura del catálogo, que no admite filtro.
  * WHEN el backend recibe cualquier llamada al catálogo, THEN responde con las casas derivadas de la sesión, sin aceptar jamás un parámetro de linaje externo.
* **RF-01.3 [Ubicuo]:**
  Cada tarjeta de hermandad DEBERÁ mostrar, como mínimo: nombre canónico, lema heráldico, Sello Rúnico determinista (SPEC-02 RF-07; el identificador `coat_of_arms` se codifica, jamás se imprime), número de adeptos sobre la plenitud («X de 30»), régimen de admisión rotulado en castellano («Admisión abierta» / «Requiere petición formal»), honor vigente (Regente de la semana) y la condición del propio adepto respecto a esa casa, derivada de la definición de aptitud (RF-01.7): «Tu hermandad», «Pendiente de dictamen», gesto de adhesión disponible, o vedado por su leyenda solemne.
  **Criterios de aceptación:**
  * WHEN una casa es la Clan Regente vigente, THEN su tarjeta lo declara con la corona y la heráldica dorada del sistema de diseño, sin nuevos estilos ad hoc.
  * IF la carga del catálogo de clanes falla, THEN la ceremonia muestra un aviso solemne controlado («Las hermandades no responden») con acción de reintento, sin exponer trazas.
* **RF-01.4 [Dirigido por Eventos]:**
  SI el linaje jurado del adepto carece de clanes activos, ENTONCES la ceremonia DEBERÁ mostrar un estado solemne de vacío («Ninguna hermandad ruega aún tu linaje») con una invitación discreta a fundar la primera casa — como rótulo que remite a SPEC-07, sin flujo de fundación propio.
* **RF-01.5 [Ubicuo]:**
  La ceremonia DEBERÁ ser abierta a la contemplación en todo estado del adepto; solo los gestos de adhesión se vedan conforme a RF-03.
* **RF-01.6 [Ubicuo]:**
  El sistema DEBERÁ mantener esta ceremonia ajena a toda retención, bloqueo o obligatoriedad: la condición de ermitaño (adepto linajado sin clan) es legítima y perpetua mientras el adepto no decida lo contrario.
* **RF-01.7 [Definición de Aptitud]:**
  El sistema DEBERÁ declarar **apto para la adhesión** a todo adepto que cumpla conjuntamente: poseer linaje jurado, no ostentar membresía vigente en clan alguno, no hallarse en convalecencia activa, y apuntar a una casa con vacantes. La aptitud es un estado derivado del instante (jamás un flag persistente) y de ella DEBERÁN derivar los estados de tarjeta: «Tu hermandad», «Pendiente de dictamen», gesto de adhesión disponible, o vedado por leyenda solemne (RF-03.5).

### RF-02: El Rito de Ingreso Inmediato (clanes de admisión abierta)
* **RF-02.1 [Dirigido por Eventos]:**
  CUANDO un adepto apto active el gesto de ingreso sobre una casa de régimen `open` con vacantes, el sistema DEBERÁ presentar un **modal solemne de confirmación** que nombre la casa, exponga la lealtad indivisible (un solo clan por mago, SPEC-07 RF-01.1) y advierta que marchar en el futuro activará la convalecencia de 14 días, exigiendo una confirmación explícita para consumar el ingreso.
  **Criterios de aceptación:**
  * WHEN el modal convoque, THEN la advertencia de lealtad indivisible y de convalecencia futura es visible, explícita y en el cuerpo del modal (jamás letra menuda ni *tooltip*).
  * IF el adepto descarta el modal, THEN no se consume ingreso alguno y la ceremonia permanece operativa.
  * WHEN el ingreso se confirma, THEN la membresía se crea de inmediato, la tarjeta de la casa pasa a declarar «Tu hermandad», el resto de tarjetas pierden su gesto de ingreso (RF-03.5, RF-01.7) y las peticiones residuales se anulan de oficio (RF-03.7).
* **RF-02.2 [Límite y Carrera]:**
  El backend DEBERÁ revalidar la plenitud de la casa en el instante de la confirmación; SI la casa alcanzó los 30 adeptos entre la carga del catálogo y la confirmación, ENTONCES el sistema DEBERÁ rechazar el ingreso con la leyenda solemne de plenitud de SPEC-07 («La hermandad ha alcanzado su plenitud de 30 hermanos…»), dejando la ceremonia operativa para reintentar con otra casa.
* **RF-02.3 [Ubicuo]:**
  El backend DEBERÁ resolver la petición de ingreso de forma **idempotente y serializada por cuenta**: un adepto ya militante que repita el gesto (doble clic, reintento de red, segunda pestaña) recibe éxito sin mutación alguna si la casa coincide; SI el gesto apunta a otra casa, ENTONCES recibe la leyenda solemne de lealtad empeñada con error propio (`CLAN_LOYALTY_BOUND`) — «Tu lealtad ya está empeñada en [casa]» — jamás la leyenda de plenitud, que queda reservada a su caso real (RF-02.2).

### RF-03: El Rito de la Petición Formal (clanes bajo dictamen) y los Estados Vedados
* **RF-03.1 [Dirigido por Eventos]:**
  CUANDO un adepto apto active el gesto de postulación sobre una casa de régimen `byApplication`, el sistema DEBERÁ ofrecer la redacción de una **petición formal** en noble castellano — una motivación breve dirigida al Patriarca, acotada a un molde de **20 a 500 caracteres** con contador visible y leyenda solemne al exceder el molde — y remitirla al dictamen; la solicitud queda en estado «Pendiente de dictamen» visible en la tarjeta de esa casa y en el inventario consolidado (RF-03.8). CADA adepto DEBERÁ poder postular a cada casa UNA sola vez por cuenta: un rechazo o una retirada CLAUSURAN la casa para nuevas peticiones de esa cuenta.
  **Criterios de aceptación:**
  * WHEN la petición se remite, THEN la tarjeta declara «Pendiente de dictamen» y el gesto de postulación se sustituye por la posibilidad de retirarla (RF-03.3).
  * IF el adepto intenta postular a una casa con su petición ya pendiente, THEN el sistema responde por idempotencia sin duplicar la solicitud.
  * IF el adepto intenta postular a una casa ya CLAUSURADA para su cuenta (petición previa rechazada o retirada), THEN el backend lo rechaza de forma controlada con leyenda solemne que nombre la clausura — jamás contando el intento contra su cupo de 3 pendientes.
  * El sistema NO DEBERÁ automatizar juicio alguno sobre el tono del texto: el dictamen humano del Patriarca ES la revisión de la petición, y su rechazo con motivo (Artículo III.3) es su instrumento (Dogma Vanilla: cero filtros de estilo en el backend).
* **RF-03.2 [Límite]:**
  SI el adepto acumula ya tres (3) solicitudes pendientes (SPEC-07 RF-01.5), ENTONCES el sistema DEBERÁ vedar la cuarta postulación con leyenda solemne que nombre el límite y señale la retirada como camino para liberar cupo.
* **RF-03.3 [Dirigido por Eventos]:**
  CUANDO el postulante retire una solicitud pendiente, el sistema DEBERÁ anularla de forma definitiva, liberar su cupo e inscribir la retirada en la Bitácora de Auditoría; la casa queda CLAUSURADA para nuevas peticiones de esa cuenta (RF-03.1) y la tarjeta restaura su estado sin gesto de postulación.
* **RF-03.4 [Dirigido por Eventos]:**
  El sistema DEBERÁ reflejar en el Vestíbulo el veredicto del Patriarca cuando llegue: la aprobación convierte al postulante en adepto (la casa pasa a «Tu hermandad») y anula de oficio sus peticiones residuales (RF-03.7); el rechazo muestra un aviso solemne controlado, libera el cupo y deja la casa clausurada (RF-03.1). MIENTRAS existan veredictos no contemplados, el acceso al Vestíbulo DEBERÁ lucir el rótulo «Tienes dictámenes a la espera» (RF-01.1), que se apaga al ser leídos. El postulante consultará siempre el estado en este Vestíbulo; la deliberación misma pertenece a SPEC-07, que inscribe el asiento del dictamen con su motivo conforme al reparto por actor (RF-04.4).
* **RF-03.5 [Ubicuo — Estados vedados]:**
  MIENTRAS el estado del adepto impida la adhesión, el sistema DEBERÁ mostrar la ceremonia íntegra con los gestos sustituidos por su leyenda solemne:
  * **Convalecencia (SPEC-07 RF-01.6/01.7):** «En Convalecencia Arcana: restan X días de meditación» — con el cómputo de días real del backend, visible en la ceremonia; el día parcial cuenta como día pendiente (alza al entero superior: «restan 2» con un día y una hora), mientras el instante exacto de vencimiento gobierna en servidor sin redondeo.
  * **Membresía vigente:** la casa propia se declara «Tu hermandad», y TODO gesto de adhesión — ingreso y postulación — queda vedado en las demás con la leyenda de lealtad empeñada (RF-02.3); la contemplación permanece íntegra.
  **Criterios de aceptación:**
  * WHEN un adepto en convalecencia contempla la ceremonia, THEN las tarjetas aparecen completas pero con el gesto vedado por la leyenda de descanso, sin error ni modal.
  * WHEN el último día de convalecencia expira, THEN los gestos de adhesión se restauran sin intervención manual (el vencimiento es estado derivado del instante, no un flag que alguien apague).
* **RF-03.6 [Ubicuo — Sustancia blindada]:**
  El backend DEBERÁ ser la guardia última de todos los vedados: linaje (RF-01.2), convalecencia, membresía vigente, plenitud, límite de peticiones y clausura por casa (RF-03.1) se revalidan en servidor en cada gesto, jamás confiados a la interfaz.
* **RF-03.7 [Dirigido por Eventos — Peticiones Residuales]:**
  CUANDO la membresía de un adepto nazca por cualquier vía (ingreso inmediato o dictamen favorable), el sistema DEBERÁ anular de oficio TODAS sus demás peticiones pendientes, inscribiendo cada anulación en la Bitácora con la causa solemne («la lealtad indivisible absuelve las peticiones huérfanas») y liberando el cupo íntegro; NINGUNA petición residual DEBERÁ sobrevivir a la militancia.
* **RF-03.8 [Ubicuo — Inventario Consolidado]:**
  El Vestíbulo DEBERÁ alojar un apéndice solemne «Tus peticiones pendientes: N de 3» que liste cada solicitud del adepto con su casa y estado (pendiente, dictamen recibido), con retirada directa desde la lista; el límite de 3 y la clausura por casa (RF-03.1) DEBERÁN ser visibles en él.

### RF-04: Coherencia y Fronteras (SPEC-07 / SPEC-09)
* **RF-04.1 [Ubicuo]:**
  El filtro de linaje DEBERÁ derivar del linaje jurado en `users` (SPEC-09): el adepto jamás podrá ingresar ni postular — ni por interfaz ni por API directa — a un clan de linaje distinto del suyo.
* **RF-04.2 [Ubicuo]:**
  La ceremonia DEBERÁ respetar todas las mecánicas vigentes de SPEC-07 sin redefinirlas: unicidad de membresía, capacidad de 30, regímenes de admisión, límite de 3 solicitudes, convalecencia de 14 días, veto ético de 30 días para Maestros y patrimonio inviolable de conjuros.
* **RF-04.3 [Ubicuo]:**
  La ceremonia DEBERÁ carecer de toda interacción con el juramento de linaje (SPEC-09): jurar no afilia, afiliar no muta el juramento; la retención de SPEC-09 precede siempre a esta ceremonia.
* **RF-04.4 [Dirigido por Eventos — Reparto por Actor]:**
  La trazabilidad de la adhesión DEBERÁ repartirse por actor: esta spec inscribe los actos del postulante — ingreso inmediato, petición formal, retirada, anulación de residuales y veredicto recibido — en la Bitácora de Auditoría (Artículo III / SPEC-07 RNF-04), extendiendo su catálogo de actos; el asiento del DICTAMEN con su motivo lo inscribe el lado deliberante conforme a SPEC-07, en cumplimiento del Artículo III.3 (todo veredicto audita identidad, estampa temporal y motivo). Ningún acto queda sin dueño y ninguno se inscribe dos veces.
* **RF-04.5 [Ubicuo]:**
  El contrato de la petición formal DEBERÁ ser único y compartido por ambos extremos del rito (postulante y Patriarca): esta spec fija el lado del postulante; SPEC-07 gobierna la deliberación sobre el mismo contrato, sin formatos divergentes.

---

## 5. Requisitos No Funcionales

* **RNF-01 (Solemnidad del Velo Arcano):** La ceremonia seguirá el sistema de diseño (SPEC-02): heráldica y Sellos Rúnicos deterministas, oro arcano sobre tintas oscuras, tokens sin literales de color; la experiencia de contempleción ha de ser digna de la decisión que aloja.
* **RNF-02 (Soberanía Lingüística):** Rótulos, leyendas, modales y peticiones, íntegramente en noble castellano (Artículos IV y V); identificadores técnicos en inglés (`clanId`, `admissionMode`, `pendingPetitions`, `convalescenceExpiresAt`, `memberCount`). El vocabulario evitará jerga técnica: «dictamen», no «estado del request».
* **RNF-03 (Accesibilidad WCAG 2.1 AA):** Navegación completa por teclado (tarjetas enfocables, modales operables con Enter/espaciadora, foco atrapado en el modal y devuelto al cerrarlo), regiones vivas ARIA que anuncien el veredicto de cada gesto, contraste ≥ 4.5:1 y respeto a `prefers-reduced-motion`.
* **RNF-04 (Rendimiento):** El catálogo de hermandades del linaje se sirve en una sola carga (el conjunto de casas de un linaje es reducido); ningún gesto añade recargas completas de vista; la revalidación de vedados y el veredicto de cada gesto no DEBERÁN añadir latencia mensurable en el orden de la de cualquier control de sesión existente (mismo patrón de medida que RNF-04 de SPEC-09).
* **RNF-05 (Dogma Vanilla):** Implementación íntegra sin dependencias externas: PDO con consultas preparadas, ES Modules nativos, cero CDNs (Artículo I).
* **RNF-06 (Trazabilidad):** Ingresos, peticiones, retiradas y veredictos quedan inscritos de forma inmutable en la Bitácora pública, con identidad, casa y estampa temporal (RF-04.4).

---

## 6. Casos Límite

1. **Carrera de plenitud:** la casa se llena entre la carga del catálogo y la confirmación del ingreso — el backend rechaza con la leyenda de plenitud y la ceremonia permanece operativa (RF-02.2).
2. **Casa disuelta o cambiada de régimen en vuelo:** si la casa muta (a `archived`, o de `open` a `byApplication`) entre la carga y el gesto, el backend rechaza con leyenda de estado mudado («La casa ha mudado sus puertas: hoy exige petición formal») y el frontend refresca la tarjeta al régimen real; el gesto sobre una casa que ya no existe así jamás se convierte en otro compromiso.
3. **Petición a casa disuelta antes del dictamen:** la solicitud se resuelve como rechazada por causa solemne («La hermandad se ha disuelto»), liberando el cupo del postulante.
4. **Dictamen sobre casa en plenitud:** el Patriarca no puede aprobar un ingreso que exceda los 30; el backend lo impide y la solicitud permanece pendiente para deliberación posterior.
5. **Retirada y dictamen concurrentes:** si el Patriarca delibera mientras el postulante retira, el backend serializa por solicitud: un solo desenlace determinista (o ingresa, o queda retirada; jamás ambas).
6. **Doble envío / pestañas múltiples:** ingreso y petición se resuelven idempotentemente por cuenta y casa (RF-02.3, RF-03.1).
7. **Adepto legado militante en clan de linaje distinto:** existe por datos históricos previos a SPEC-09; conforme a la excepción legada de RF-01.2, su casa propia aparece como entrada única especial («Tu hermandad») pese a la divergencia de linaje, sin gesto de cambio ni migración forzosa, mientras el resto del catálogo permanece filtrado por su linaje jurado.
8. **Última vacante disputada por dos ingresos concurrentes:** el backend serializa por casa — gana la petición con estampa temporal de llegada UTC más antigua (mismo criterio que el segundo desempate de SPEC-07 RF-04.5); el otro recibe la leyenda de plenitud.
9. **Sesión caducada a mitad de rito:** la confirmación responde con aviso solemne y, tras reautenticar, la ceremonia reaparece intacta.
10. **Peregrino sin linaje que ataca la API de adhesión directamente:** la retención de SPEC-09 (`LINEAGE_OATH_REQUIRED`) precede a cualquier veredicto de esta spec.
11. **Cuenta purgada y re-creada:** la nueva cuenta nace ermitaña; ninguna membresía ni solicitud hereda entre cuentas.
12. **Admin Supremo sin linaje:** exento del juramento por privilegio fundacional (SPEC-09); el Vestíbulo le niega el paso con aviso solemne y sus gestos por API reciben `ADMIN_LINEAGE_REQUIRED` (RF-01.1) — ni la retención de SPEC-09 ni el filtro de linaje le atañen.
13. **Re-postulación sobre casa clausurada:** tras un rechazo o una retirada, la casa queda clausurada para esa cuenta (RF-03.1); el intento de re-postular se rechaza de forma controlada sin consumir cupo (RF-03.1).
14. **Aceptación con peticiones residuales:** al nacer la membresía por cualquier vía, todas las demás peticiones pendientes del adepto se anulan de oficio con inscripción en la Bitácora (RF-03.7) — ningún militante conserva peticiones en vuelo.
15. **Sucesión dinástica con petición en vuelo:** la petición liga a la HERMANDAD, no al Patriarca: si el líder inactivo es sucedido de oficio (SPEC-07 RF-01.9), el sucesor hereda la deliberación pendiente tal cual; la petición no se anula ni se re-cuenta.

---

## 7. Fuera de Alcance (Exclusiones Explícitas)

1. **Fundación de clanes:** nombre, lema, blasón, linaje rector y alta de casas nuevas — SPEC-07 (RF-01.2). Esta spec solo invita a ella como rótulo en el estado vacío.
2. **Gobierno del Patriarca:** bandeja de solicitudes recibidas, deliberación, aprobación/rechazo, expulsiones y transferencia de corona — SPEC-07. Esta spec solo fija el lado del postulante y exige el contrato compartido (RF-04.5).
3. **Renuncia y salida del clan:** el abandono voluntario y su convalecencia derivada son mecánica de SPEC-07; esta spec solo advierte de su existencia en el modal de ingreso.
4. **PDA, sinergias de linaje, ciclo semanal y proclamación del Regente:** SPEC-07 en su integridad.
5. **Notificaciones push o correo** al postulante cuando llegue el dictamen: la ceremonia y el perfil son los lugares de consulta del estado.
6. **Cambio de clan directo** (militar en una casa mientras se pertenece a otra): jamás existe; la salida y su convalecencia preceden siempre a una nueva adhesión (SPEC-07).
7. **Guerra de clanes, alianzas y economía:** exclusiones heredadas de SPEC-07.

---

## 8. Criterios de Finalización y Aceptación

* [ ] La ceremonia es una vista solemne voluntaria, rotulada «Vestíbulo de las Hermandades» y accesible por doble vía (navegación y Salón de los Linajes): ningún flujo del santuario se bloquea por su existencia y el ermitaño es una condición legítima.
* [ ] La aptitud para la adhesión es la definición conjuntiva de RF-01.7 (linaje jurado, sin membresía, sin convalecencia, casa con vacantes), derivada del instante y nunca un flag persistente.
* [ ] Solo los adeptos linajados acceden; peregrinos sin linaje quedan retenidos por SPEC-09 y los anónimos permanecen en el Salón.
* [ ] La ceremonia exhibe únicamente los clanes activos del linaje jurado; el backend rechaza con `CLAN_LINEAGE_MISMATCH` cualquier catálogo o gesto hacia otro linaje, incluso por API directa.
* [ ] Cada tarjeta muestra nombre, lema, Sello Rúnico determinista, plenitud (X de 30), régimen rotulado en castellano y honor vigente, con la heráldica de regente cuando corresponda.
* [ ] El ingreso en casas abiertas exige modal solemne con advertencia visible de lealtad indivisible y convalecencia futura, y se consume con una confirmación explícita.
* [ ] La postulación en casas bajo dictamen exige petición formal escrita en castellano (molde de 20 a 500 caracteres con contador visible), respeta el límite de 3 pendientes, admite retirada, clausura la casa tras rechazo o retirada (una sola petición por casa y cuenta) y refleja el veredicto del Patriarca con el rótulo «Tienes dictámenes a la espera» en el acceso hasta ser leído.
* [ ] Al nacer la membresía por cualquier vía, todas las demás peticiones pendientes del adepto se anulan de oficio con inscripción en la Bitácora (RF-03.7).
* [ ] El inventario consolidado «Tus peticiones pendientes: N de 3» lista las solicitudes del adepto con retirada directa desde la lista (RF-03.8).
* [ ] Los estados vedados (convalecencia, membresía vigente) sustituyen los gestos por leyendas solemnes sin bloquear la contemplación, con cómputo real de días restantes.
* [ ] Plenitud, linaje, convalecencia, membresía y límite de peticiones se revalidan en servidor en cada gesto; la interfaz nunca es la única guardia.
* [ ] Los actos del postulante (ingreso, petición, retirada, anulaciones, veredicto recibido) quedan inscritos en la Bitácora conforme al reparto por actor (RF-04.4); el asiento del dictamen con su motivo lo inscribe el lado deliberante conforme a SPEC-07 (Artículo III.3).
* [ ] Cero dependencias externas, Dogma Vanilla, Velo Arcano con tokens (sin literales de color), accesibilidad WCAG 2.1 AA y Soberanía Lingüística rigurosas.

---

## 9. Registro de Resoluciones de Diseño

* **[RESUELTO — Ronda de desambiguación, P1] Alcance:** la ceremonia cubre SOLO la adhesión; la fundación de clanes queda en SPEC-07 (RF-01.2) y solo aparece como invitación en el estado vacío.
* **[RESUELTO — Ronda de desambiguación, P2] Naturaleza:** vista solemne VOLUNTARIA; nadie es retenido y el ermitaño es condición legítima.
* **[RESUELTO — Ronda de desambiguación, P3] Regímenes:** cada régimen con su rito propio — ingreso inmediato solemne para `open`, petición formal escrita para `byApplication`.
* **[RESUELTO — Ronda de desambiguación, P4] Filtro de linaje:** solo clanes del linaje jurado; lo vedado no se exhibe.
* **[RESUELTO — Ronda de desambiguación, P5] Estados vedados:** contemplación siempre abierta; los gestos se vedan con leyenda solemne (convalecencia, membresía vigente).
* **[RESUELTO — Ronda de desambiguación, P6] Frontera del dictamen:** solo el lado del postulante, con contrato de solicitud compartido con SPEC-07.
* **[RESUELTO — Hallazgos 1 y 2] Aptitud conjuntiva:** apto = linajado + sin membresía + sin convalecencia + casa con vacantes; los estados de tarjeta derivan de esa definición (RF-01.7).
* **[RESUELTO — Hallazgo 3] Guardia de linaje:** el catálogo no admite filtro (linaje derivado de sesión); `CLAN_LINEAGE_MISMATCH` se emite solo en gestos hacia linaje ajeno (RF-01.2).
* **[RESUELTO — Hallazgo 4] Leyenda de lealtad:** el gesto del militante hacia otra casa recibe leyenda propia con error `CLAN_LOYALTY_BOUND` — «Tu lealtad ya está empeñada en [casa]» — y jamás la de plenitud (RF-02.3).
* **[RESUELTO — Hallazgo 5] Régimen mutado en vuelo:** rechazo solemne con refresco de tarjeta; el gesto jamás se convierte en otro compromiso (caso límite 2).
* **[RESUELTO — Hallazgo 6] Desempate de vacante:** gana la estampa temporal de llegada UTC más antigua, alineado con el segundo criterio de SPEC-07 RF-04.5 (caso límite 8).
* **[RESUELTO — Hallazgo 7 y Duda abierta 2] Aviso del dictamen:** rótulo «Tienes dictámenes a la espera» en el acceso hasta ser leído (RF-01.1, RF-03.4); sin notificaciones push ni correo.
* **[RESUELTO — Hallazgo 8] Inventario de peticiones:** apéndice consolidado «Tus peticiones pendientes: N de 3» dentro del Vestíbulo, con retirada directa (RF-03.8).
* **[RESUELTO — Hallazgo 9] Cómputo de días:** el día parcial cuenta como día pendiente (techo); el instante exacto gobierna en servidor sin redondeo (RF-03.5).
* **[RESUELTO — Hallazgos 10, 12, 15] Parches mecánicos:** RNF-04 reescrito con patrón de medida de SPEC-09; referencia cruzada de RF-02.1 corregida a RF-03.5; paréntesis de Herencia Ancestral retirado de RF-01.3.
* **[RESUELTO — Hallazgo 11] Legado divergente:** excepción declarada en RF-01.2 — la casa propia del adepto legado se muestra como entrada única especial; el resto del catálogo permanece filtrado (caso límite 7).
* **[RESUELTO — Hallazgos 13 y 19] Admin Supremo sin linaje:** acceso vedado con aviso solemne y error propio `ADMIN_LINEAGE_REQUIRED` por API (RF-01.1, caso límite 12).
* **[RESUELTO — Hallazgo 14] Auditoría del veredicto:** reparto por actor — esta spec inscribe los actos del postulante; el dictamen con motivo, el lado deliberante conforme a SPEC-07 (RF-04.4).
* **[RESUELTO — Hallazgo 16] Re-postulación:** UNA sola petición por casa y cuenta; rechazo o retirada clausuran la casa sin consumir cupo (RF-03.1).
* **[RESUELTO — Hallazgo 17] Peticiones residuales:** anulación automática solemne al nacer la membresía, con inscripción en Bitácora (RF-03.7).
* **[RESUELTO — Hallazgo 18] Militante postulando:** la membresía veda TODO gesto de adhesión con la leyenda de lealtad empeñada (RF-03.5).
* **[RESUELTO — Hallazgo 20] Sucesión durante petición:** la petición liga a la hermandad; el sucesor hereda la deliberación pendiente (caso límite 15).
* **[RESUELTO — Hallazgos 21 y 22] Gobierno del texto:** el Patriarca es la única guardia del tono; cero filtros automáticos de estilo en el backend (Dogma Vanilla, RF-03.1).
* **[RESUELTO — Hallazgo 23] Motivo en la auditoría (Artículo III.3):** el asiento del dictamen porta identidad, estampa temporal y motivo, inscrito por el lado deliberante (RF-04.4).
* **[RESUELTO — Duda abierta 1] Motivación acotada:** molde de 20 a 500 caracteres con contador visible y leyenda solemne al exceder (RF-03.1).
* **[RESUELTO — Duda abierta 3] Entrada canónica:** doble vía — rótulo propio en la navegación y llamamiento desde el Salón de los Linajes, ambos con el rótulo de dictámenes pendientes (RF-01.1).
* **[RESUELTO — Duda abierta 4] Denominación:** «Vestíbulo de las Hermandades» como rótulo de la vista; el acto sigue llamándose «ceremonia de adhesión» en Bitácora y textos del rito.
