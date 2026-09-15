# SPEC-07: Sistema de Clanes, Linajes y Dominio Semanal del Grimorio

> **Constitución:** [`constitution.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/constitution.md) | **Directrices:** [`AGENTS.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/AGENTS.md)  
> **Estado:** Especificación Ratificada y Endurecida tras QA  
> **Área:** Organización Social, Tradición Mágica y Competencia Meritocrática  
> **Restricción:** Definición estricta del QUÉ y el POR QUÉ. Cero detalles de implementación técnica, arquitectura o nombres de archivos.

---

## 1. Contexto y Objetivo

En las tradiciones arcanas inmortalizadas en las obras de *Frieren*, la mitología de *Tolkien* y las hermandades de *D&D*, el conocimiento mágico rara vez florece en el aislamiento individual. Se forja, custodia y enriquece a lo largo de generaciones en el seno de hermandades, escuelas ancestrales y linajes de hechiceros. Los magos consagran su lealtad a un clan para sumar su talento al legado colectivo y competir noblemente por el dominio espiritual del santuario.

El objetivo de esta especificación es definir el **Sistema de Clanes, Linajes y Dominio Semanal del Grimorio**: un marco de organización y prestigio meritocrático donde los usuarios consagrados (`editor`, `master`, `supremeAdmin`) pueden fundar hermandades, elegir un **Linaje Mágico** alineado con una afinidad elemental rectora, contribuir a la gloria colectiva acumulando **Puntos de Dominio Arcano (PDA)** mediante la forja de conjuros, la maestría en el simulador y el reconocimiento comunitario, y coronar cada semana al **Clan Regente del Santuario**, preservando inviolable el patrimonio arcano bajo el **Artículo III de la Constitución** (Ética de los Clanes y Conflicto de Intereses).

---

## 2. Actores y Usuarios

* **El Adepto Neófito (Editor / Miembro de Clan):**  
  Se afilia a una hermandad que resuena con su filosofía arcana, forja conjuros bajo su estandarte y practica combos en el simulador para sumar puntos de dominio a su clan.
* **El Patriarca o Matriarca (Líder y Fundador de Clan):**  
  Funda el clan, consagra su Linaje rector, custodia el lema y blasón heráldico, administra la entrada y salida de adeptos y lidera a su hermandad en la contienda semanal.
* **El Maestro de la Torre (Revisor / Moderador):**  
  Miembro honorable de un clan que ejerce la deliberación en moderación, sujeto al veto constitucional que le prohíbe terminantemente evaluar conjuros de su propio clan o de hermandades a las que haya pertenecido en los últimos 30 días.
* **El Visitante o Lector Anónimo (`reader`):**  
  Contempla los estandartes de los clanes, consulta la clasificación semanal y admira el Salón de los Linajes, pudiendo elogiar conjuros añadiéndolos a sus favoritos personales.

---

## 3. Historias de Usuario

* **HU-01 (Fundación y Gobierno de un Clan):**  
  *Como* mago consagrado con rango de editor o maestro,  
  *Quiero* fundar un clan asignándole un nombre canónico, un lema en noble castellano, un escudo heráldico y un Linaje Mágico rector,  
  *Para* forjar una comunidad de hechiceros bajo una misma tradición mágica y competir por el dominio del santuario.

* **HU-02 (Afiliación Exclusiva, Admisión y Convalecencia):**  
  *Como* practicante de la magia,  
  *Quiero* afiliarme a un único clan según su régimen de admisión (abierto o por solicitud) y conocer que si decido marcharme tendré un periodo de convalecencia de 14 días visible en mi perfil antes de poder unirme a otra hermandad,  
  *Para* comprometerme con lealtad genuina a mi casa mágica sin incurrir en transfuguismo oportunista.

* **HU-03 (Sinergia Temática de Linaje):**  
  *Como* adepto de una hermandad elemental,  
  *Quiero* recibir una bonificación del $+25\%$ en puntos de dominio (redondeada aritméticamente) cuando valide conjuros o ejecute combos del elemento rector de mi clan,  
  *Para* que nuestra especialización temática sea reconocida y premiada en la gloria semanal.

* **HU-04 (La Contienda del Dominio Semanal):**  
  *Como* miembro activo de un clan,  
  *Quiero* ver cómo las creaciones y prácticas de mi hermandad nos sitúan en la clasificación en vivo de la semana,  
  *Para* aspirar a coronarnos como el Clan Regente y exhibir nuestro estandarte solemne en la portada del santuario.

* **HU-05 (Inviolabilidad del Patrimonio Colectivo):**  
  *Como* Patriarca de un clan,  
  *Quiero* tener la certeza de que si un miembro abandona la hermandad, los conjuros validados que forjó bajo nuestro estandarte permanecerán para siempre en nuestro legado histórico,  
  *Para* proteger el patrimonio y esfuerzo colectivo de la casa frente a deserciones.

---

## 4. Requisitos Funcionales (Notación EARS en Español)

### RF-01: Estructura de Clan, Gobierno, Membresía y Afiliación Exclusiva
* **RF-01.1 [Ubicuo]:**  
  El sistema DEBERÁ exigir que cada usuario consagrado (`editor`, `master`, `supremeAdmin`) pertenezca como máximo a **un único clan simultáneamente** (la lealtad mágica es indivisible). Los usuarios anónimos (`reader`) no podrán afiliarse ni fundar clanes.
* **RF-01.2 [Dirigido por Eventos]:**  
  CUANDO un usuario con rango `editor` o superior que no pertenezca a ningún clan ni se halle en convalecencia solicite fundar un clan, el sistema DEBERÁ requerir un **Nombre Canónico Único** (de 4 a 50 caracteres, globalmente único en el santuario), un **Lema Heráldico** en noble castellano, un **Blasón Rúnico** y la selección de uno de los ocho (8) Linajes Canónicos, asignando al fundador el rol de **Patriarca o Matriarca** (`patriarch`).
* **RF-01.3 [Ubicuo]:**  
  El sistema DEBERÁ admitir los siguientes roles canónicos dentro de cada clan:
  * **Patriarca / Matriarca (`patriarch`, líder único):** Puede actualizar el lema y blasón, configurar el régimen de admisión, aceptar o rechazar solicitudes de ingreso, expulsar adeptos y transferir la corona del liderazgo a otro adepto del clan.
  * **Adepto del Linaje (`adept`, miembro pleno):** Aporta puntos mediante sus obras y prácticas, y puede abandonar voluntariamente la hermandad en cualquier momento.
* **RF-01.4 [Límite y Capacidad]:**  
  El sistema DEBERÁ limitar la membresía de cada clan a un **máximo estricto de treinta (30) adeptos activos**, bloqueando cualquier nuevo ingreso si el clan alcanza dicha capacidad máxima.
* **RF-01.5 [Régimen de Admisión]:**  
  El sistema DEBERÁ permitir al Patriarca alternar entre dos regímenes de admisión:
  * **Abierto (`open`):** Cualquier mago apto que no esté en convalecencia puede unirse de forma inmediata mientras existan vacantes disponibles (< 30 miembros).
  * **Bajo Petición (`byApplication`):** El postulante remite una solicitud formal; el Patriarca debe deliberar y aprobarla o rechazarla. Un usuario podrá tener como máximo **tres (3) solicitudes de ingreso pendientes** simultáneas a diferentes clanes.
* **RF-01.6 [Dirigido por Eventos]:**  
  CUANDO un usuario abandone voluntariamente su clan o sea expulsado por el Patriarca, el sistema DEBERÁ activar un **Periodo de Convalecencia de catorce (14) días naturales**; MIENTRAS dicho periodo permanezca activo, el sistema DEBERÁ **bloquear terminantemente cualquier intento de ingresar a otro clan o fundar una nueva hermandad**.
* **RF-01.7 [Visibilidad Pública]:**  
  El sistema DEBERÁ mostrar con claridad en el perfil público del mago su condición de convaleciente (*«En Convalecencia Arcana: restan X días»*), visible para toda la comunidad y para los Patriarcas reclutadores.
* **RF-01.8 [Ubicuo]:**  
  En cumplimiento del **Artículo III de la Constitución**, el sistema DEBERÁ registrar inmutablemente en el historial del usuario la pertenencia a dicho clan; SI el usuario ostenta el rango de `master`, el sistema DEBERÁ **inhabilitarlo éticamente para evaluar, firmar o vetar conjuros forjados por dicho clan durante un lapso de treinta (30) días** desde su partida.
* **RF-01.9 [Inactividad Prolongada del Patriarca]:**  
  SI el Patriarca de un clan acumula **cuarenta y cinco (45) días naturales consecutivos sin iniciar sesión ni registrar actividad en el santuario**, ENTONCES el sistema DEBERÁ transferir automáticamente y de forma irrevocable la condición de Patriarca (`patriarch`) al **Adepto activo con mayor antigüedad** en la hermandad (dirimiendo empates por mayor volumen acumulado de PDA aportados); SI el clan no cuenta con más miembros activos, transicionará de forma automática al estado de disolución archivada (`archived`).

---

### RF-02: Los Ocho Linajes Mágicos Canónicos y Heráldica
* **RF-02.1 [Ubicuo]:**  
  El sistema DEBERÁ instituir de forma inmutable los siguientes **Ocho (8) Linajes Mágicos Canónicos**, vinculados a su afinidad elemental rectora e identificadores canónicos (Artículo V):
  1. **Linaje de la Llama Primordial (`primordialFlame`):** Afinidad rectora *Fuego* (`fire`).
  2. **Linaje de las Mareas Celestiales (`celestialTides`):** Afinidad rectora *Agua / Escarcha* (`water`).
  3. **Linaje de la Tempestad Eterna (`eternalTempest`):** Afinidad rectora *Rayo* (`lightning`).
  4. **Linaje de las Raíces del Mundo (`worldRoots`):** Afinidad rectora *Tierra* (`earth`).
  5. **Linaje de los Vientos del Alba (`dawnWinds`):** Afinidad rectora *Viento* (`wind`).
  6. **Linaje de la Corona Solar (`solarCrown`):** Afinidad rectora *Luz* (`light`).
  7. **Linaje de las Sombras Abisales (`abyssalShadows`):** Afinidad rectora *Oscuridad* (`darkness`).
  8. **Linaje de los Tejedores del Éter (`aetherWeavers`):** Afinidad rectora *Arcano Puro* (`pureArcane`).
* **RF-02.2 [Ubicuo]:**  
  El sistema DEBERÁ dotar a cada linaje de un marco heráldico distintivo, glifos rúnicos ancestrales y un color de estandarte ceremonial acorde a su elemento rector.
* **RF-02.3 [Ubicuo]:**  
  En estricto cumplimiento del **Artículo II de la Constitución**, el Linaje Mágico **no introducirá descuentos, sobrecostes ni ventajas numéricas en la forja de conjuros ni en la fórmula universal de maná**; la neutralidad de la forja se preserva inviolable.
* **RF-02.4 [Ubicuo]:**  
  El sistema DEBERÁ forjar el blasón de cada hermandad como **Sello Rúnico determinista** (canon de SPEC-02, RF-07): su carga central declara el Linaje Mágico rector según el canon alquímico y las muescas de su anillo codifican el identificador `coat_of_arms` de la casa. El identificador se **codifica, jamás se imprime**: ni como texto de interfaz ni dentro del nombre accesible de ningún elemento.
  * El **Clan Regente** luce anillo de oro vivo y el sello de cera presionado a las doce; la casa **`archived`** viste bronce y su anillo aparece **roto en su base** (Herencia Ancestral); la casa activa, oro antiguo. El estado se declara por metal **y** forma, nunca por color solo.
  * La etiqueta accesible del sello nombra en noble castellano la casa, su Linaje Mágico y su honor vigente, y acompaña a la marca del fundador cuando la casa es el linaje fundacional neutro (Art. III).

---

### RF-03: Sistema de Puntuación del Dominio Semanal (PDA)
* **RF-03.1 [Dirigido por Eventos]:**  
  CUANDO un conjuro forjado por un adepto del clan alcance el estado `validated` (ratificado con 3 firmas de Maestros), el sistema DEBERÁ otorgar al clan **Puntos de Dominio Arcano (PDA)** según la siguiente escala por Círculo:
  $$\text{PDA} = 100 + (\text{Círculo Arcano} \times 20)$$
  *(Círculo I = 120 PDA; Círculo II = 140 PDA; Círculo III = 160 PDA; Círculo IV = 180 PDA; Círculo V = 200 PDA).*
* **RF-03.2 [Dirigido por Eventos]:**  
  CUANDO un adepto ejecute con éxito una reacción de combo elemental en la Cámara de Conjuración (SPEC-05 / SPEC-06), el sistema DEBERÁ otorgar **diez (+10) PDA al clan**, acotado estrictamente a un **techo máximo de cincuenta (50) PDA por adepto al día** (`dailySimulatorPoints`). Dicho techo diario se reiniciará de forma automática a las **00:00:00 UTC** de cada medianoche.
* **RF-03.3 [Dirigido por Eventos]:**  
  CUANDO un usuario ajeno al clan guarde en su libreta de *Favoritos* un conjuro sellado del clan, el sistema DEBERÁ otorgar **cinco (+5) PDA**, contabilizando un único voto computable por usuario para neutralizar granjas de puntuación.
* **RF-03.4 [Ubicuo y Redondeo]:**  
  SI el conjuro validado, el combo ejecutado o el conjuro elogiado coincide con la **afinidad rectora del Linaje del clan**, ENTONCES el sistema DEBERÁ aplicar automáticamente una **bonificación de sinergia temática del veinticinco por ciento ($+25\%$ de PDA)** sobre el valor base de la acción, aplicando **redondeo aritmético estándar al entero más cercano** (`round`: decimales $\ge 0.5$ redondean hacia arriba; ej.: $5 \times 1.25 = 6.25 \rightarrow \mathbf{6\text{ PDA}}$; $10 \times 1.25 = 12.5 \rightarrow \mathbf{13\text{ PDA}}$).
* **RF-03.5 [Atribución de Conjuros en Moderación]:**  
  SI un conjuro fue forjado y enviado a moderación por un autor bajo el estandarte de un clan, y con posterioridad dicho autor abandona o es expulsado de la hermandad antes de concluir la deliberación, CUANDO el conjuro alcance el estado `validated`, los PDA correspondientes **se acreditarán íntegramente al clan bajo cuyo estandarte fue forjado y sometido a revisión**.

---

### RF-04: Ciclo Semanal, Reinicio y Proclamación del Clan Soberano
* **RF-04.1 [Ubicuo]:**  
  El sistema DEBERÁ regir la contienda del Dominio Semanal en ciclos periódicos que concluyen cada **domingo a las 23:59:59 UTC**.
* **RF-04.2 [Dirigido por Eventos]:**  
  CUANDO el reloj arcano alcance las 00:00:00 UTC del lunes, el sistema DEBERÁ proclamar formalmente al clan con mayor puntuación acumulada como el **«Clan Regente del Santuario»** de la semana que inicia.
* **RF-04.3 [Dirigido por Eventos]:**  
  En el instante de la proclamación, el sistema DEBERÁ **reiniciar a cero (0) los contadores de PDA Semanales (`weeklyPoints`) de todos los clanes**, sumando los puntos obtenidos por cada hermandad a su respectivo marcador de **Puntuación Histórica Total (`historicalPoints`)**.
* **RF-04.4 [Estado]:**  
  MIENTRAS un clan ostente el título de Clan Regente durante los 7 días de su mandato:
  * Su estandarte heráldico, lema y blasón se exhibirán de forma prominente con corona dorada en el Gran Portal del santuario (SPEC-01).
  * Todos los conjuros validados creados por dicho clan lucirán un **ribete ceremonial dorado** en las páginas del Grimorio.
  * Su victoria quedará inscrita de forma perpetua en el **Salón de los Linajes** con la marca temporal, nombre del clan, patriarca en funciones y PDA alcanzados.
* **RF-04.5 [Desempate]:**  
  SI dos o más clanes concluyen el ciclo semanal empatados en el primer puesto con la misma cantidad exacta de PDA, ENTONCES el sistema DEBERÁ resolver el desempate mediante los siguientes criterios canónicos:
  1. *Primer Criterio:* Mayor número total de conjuros validados aportados durante la semana en curso.
  2. *Segundo Criterio:* El clan que haya alcanzado primero en el tiempo dicha puntuación (marca temporal canónica `timestamp` UTC anterior).

---

### RF-05: Inviolabilidad del Patrimonio del Clan, Protección de Nombres y Herencia Ancestral
* **RF-05.1 [Ubicuo]:**  
  En estricto cumplimiento del **Artículo III de la Constitución**, los conjuros ratificados (`status = 'validated'`) son **patrimonio inviolable del clan bajo cuyo estandarte fueron concebidos**. Si el autor abandona o es expulsado del clan:
  * El conjuro mantendrá el crédito del autor original (*«Forjado por Mago X»*), pero **permanecerá indisolublemente asignado al clan original**.
  * La partida de un adepto no restará retroactivamente los PDA que este aportó al clan durante la semana en curso ni en la puntuación histórica.
* **RF-05.2 [Ubicuo]:**  
  Los borradores privados (`status = 'draft'`) pertenecen a la libreta personal del autor; siguen acompañando a su cuenta y se vincularán a su nuevo clan únicamente cuando se publiquen tras expirar los 14 días de convalecencia.
* **RF-05.3 [Dirigido por Eventos]:**  
  CUANDO un clan sea disuelto por su Patriarca o quede desprovisto de adeptos (cero miembros tras la partida del líder), el sistema DEBERÁ transicionar su estado a **`archived` (Disuelto / Herencia Ancestral)**; sus conjuros validados **jamás serán eliminados** y se preservarán perpetuamente en el Gran Tomo bajo la distinción honorífica de **«Herencia Ancestral»**.
* **RF-05.4 [Protección de Memoria Histórica]:**  
  El Nombre Canónico de un clan disuelto (`archived`) quedará **inmortalizado y reservado perpetuamente** en los anales del santuario; el sistema DEBERÁ bloquear terminantemente cualquier intento de reutilizar o usurpar dicho nombre por una nueva hermandad.

---

### RF-06: Salón de los Linajes y Clasificación Pública
* **RF-06.1 [Ubicuo]:**  
  El sistema DEBERÁ exponer una vista solemne denominada **«Salón de los Linajes»**, accesible para todos los usuarios y visitantes anónimos, que exhiba:
  * La clasificación en vivo del Dominio Semanal en curso (puesto, estandarte, linaje, PDA semanales y número de conjuros sellados).
  * La clasificación de Prestigio Histórico de todos los tiempos.
  * El Libro Mayor de Campeones con el historial cronológico de todas las semanas concluidas y sus clanes regentes.
* **RF-06.2 [Ubicuo]:**  
  El sistema DEBERÁ permitir filtrar el Salón de los Linajes por Linaje Mágico rector, facilitando la contemplación de las casas dedicadas a cada una de las 8 artes elementales.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Determinismo e Integridad en el Cómputo):**  
  El cálculo de PDA semanales y la proclamación del Clan Regente se ejecutarán de forma ciega, determinista y auditable en el servidor a las 00:00:00 UTC, sin margen para la discrecionalidad técnica ni la alteración manual.
* **RNF-02 (Anti-Manipulación y Techos de Seguridad):**  
  El sistema impedirá que una misma cuenta o dirección de origen registre más de un voto de favorito computable sobre un mismo conjuro, y acotará la práctica del simulador a un techo estricto e infranqueable de 50 PDA diarios por miembro, reiniciado a las 00:00:00 UTC.
* **RNF-03 (El Velo Arcano y la Soberanía Lingüística):**  
  Todos los nombres de linajes, lemas, rangos nobiliarios (*Patriarca*, *Adepto*), estados (*Convaleciente*, *Regente*, *Herencia Ancestral*) y descripciones deberán formularse con solemnidad literaria en noble castellano (Artículos IV y V de la Constitución).
* **RNF-04 (Transparencia y Bitácora Pública de Auditoría):**  
  Toda fundación de clan, expulsión de adeptos, renuncia, sucesión por inactividad, disolución y coronación de dominio semanal deberá inscribirse de forma inmutable en la Bitácora de Auditoría pública del santuario (SPEC-03 / Artículo III).
* **RNF-05 (Dogma Vanilla y Dualidad Lingüística):**  
  El módulo de clanes, linajes y dominio semanal se implementará íntegramente sin librerías externas; los identificadores técnicos, esquemas de base de datos y endpoints se formularán en inglés `camelCase` (`clanId`, `patriarchId`, `lineageType`, `dominionPoints`, `weeklyRank`, `convalescenceExpiresAt`, `dailySimulatorPoints`), preservando la interfaz en noble castellano (Artículos I y V de la Constitución).

---

## 6. Casos Límite y Situaciones Excepcionales

1. **Renuncia o Partida Voluntaria del Patriarca / Matriarca:**  
   Si el Patriarca desea abandonar el clan, el sistema le exigirá transferir previamente la corona de liderazgo a otro adepto del clan; SI el Patriarca es el único miembro restante y decide marcharse, el clan se disuelve automáticamente pasando a estado `archived` y sus conjuros pasan a Herencia Ancestral.
2. **Inactividad Prolongada del Patriarca (45 Días):**  
   Si el Patriarca acumula 45 días naturales consecutivos de inactividad, el sistema ejecuta de oficio la sucesión al adepto con mayor antigüedad en el clan (o mayor PDA si hay empate), garantizando que la casa mágica no quede acéfala ni bloqueada.
3. **Intento de Afiliación Durante la Convalecencia de 14 Días:**  
   Si un usuario intenta solicitar ingreso o fundar un clan durante sus 14 días de convalecencia, el sistema rechazará la acción emitiendo una leyenda ceremonial de descanso: *«Tu esencia mágica aún se encuentra en convalecencia tras disolver tu juramento anterior (restan X días de meditación)»*.
4. **Límite de Solicitudes Pendientes (3 Clanes):**  
   Si un usuario ya tiene 3 solicitudes activas a clanes con régimen `byApplication`, el sistema no le permitirá postular a un cuarto clan hasta que retire una de sus solicitudes o un Patriarca la resuelva.
5. **Capacidad Máxima Alcanzada (30 Miembros):**  
   Si un clan con 30 miembros recibe una solicitud o un usuario intenta unirse (en régimen abierto), el sistema informará solemnemente: *«La hermandad ha alcanzado su plenitud de 30 hermanos. No es posible admitir nuevos adeptos en este ciclo»*.
6. **Maestro de Clan que Intenta Evaluar Conjuro Incompatible:**  
   Si un Maestro intenta emitir una firma sobre un conjuro de su clan actual o de un clan al que perteneció hace menos de 30 días, el sistema bloqueará la acción arrojando un error ético: *«Conflicto de intereses: No es lícito a un Maestro juzgar las obras nacidas bajo su propio estandarte o linajes recientes»* (Artículo III).
7. **Conjuros Forjados por Usuarios que Nunca Pertenecieron a un Clan:**  
   Si un autor sin clan forja un conjuro, este se clasifica como obra de *«Mago Ermitaño / Erudito Libre»*; al ser validado no aporta PDA a ningún clan, pero enriquece el Gran Tomo Canónico. Si el autor se afilia posteriormente a un clan, sus conjuros pasados como ermitaño no se transfieren al clan para evitar compras retroactivas de méritos.
8. **Cambio de Semana Durante una Petición Activa:**  
   Toda acción concluida antes de las 23:59:59.999 UTC se computa a la semana saliente; cualquier acción registrada a partir de las 00:00:00.000 UTC se suma de forma atómica a la nueva semana.

---

## 7. Fuera de Alcance (Exclusiones Explícitas)

1. **Economía Monetaria, Cuotas y Tesorerías de Oro:**  
   Los clanes no gestionan depósitos bancarios, tasas de membresía ni compra de mejoras con moneda virtual o real; el único motor de crecimiento es el mérito intelectual y práctico (PDA).
2. **Guerra de Clanes y Asedios Territoriales en Tiempo Real:**  
   Se excluyen mecánicas de conquista de mapas, destrucción de fortalezas y batallas multijugador masivas; el dominio se dirime puramente mediante el saber y la maestría en el grimorio.
3. **Tratados de Alianza, Vasallaje y Federaciones Multiclan:**  
   No se admiten pactos formales entre clanes ni fusiones corporativas; cada hermandad es una entidad soberana e independiente.
4. **Proceso y Flujo de Deliberación de Moderación (Las 3 Firmas):**  
   Aunque el veto de 30 días condiciona a los evaluadores y los PDA se asignan al clan de origen, la gestión de colas de revisión, votos concurrentes y dictámenes solemnes queda **estrictamente fuera de alcance**, siendo la competencia exclusiva de **SPEC-08 (Moderación Solemne en Dos Pasos y Consecución de Firmas)**.

---

## 8. Criterios de Finalización y Aceptación

* [ ] Un usuario consagrado (`editor`, `master`, `supremeAdmin`) solo puede pertenecer a un único clan a la vez.
* [ ] La capacidad máxima de miembros por clan está acotada estrictamente a treinta (30) adeptos.
* [ ] El régimen de admisión es configurable por el Patriarca (`open` o `byApplication`), permitiendo un máximo de 3 solicitudes pendientes por usuario.
* [ ] La fundación de un clan requiere nombre único no utilizado previamente, lema en castellano, blasón y elección de uno de los 8 Linajes Canónicos.
* [ ] Abandonar o ser expulsado de un clan impone un periodo de convalecencia de 14 días para re-afiliarse o fundar otro clan, visible en el perfil público.
* [ ] Se registra el historial de pertenencia y se aplica el veto ético de 30 días para que Maestros evalúen obras de sus clanes recientes.
* [ ] Se transfiere automáticamente el liderazgo tras 45 días de inactividad del Patriarca al adepto de mayor antigüedad.
* [ ] Se reconocen los 8 Linajes Mágicos alineados a las 8 afinidades elementales sin alterar costes de maná en la forja.
* [ ] Se aplica una bonificación de sinergia de linaje del $+25\%$ en PDA con redondeo aritmético estándar al entero más cercano.
* [ ] La validación de conjuros aporta $100 + (\text{Círculo} \times 20)$ PDA acreditados al clan donde se originó la obra.
* [ ] La práctica en el simulador aporta $+10$ PDA por combo ejecutado con un tope estricto de 50 PDA diarios por adepto reiniciado a las 00:00:00 UTC.
* [ ] Los favoritos de la comunidad otorgan $+5$ PDA con límite de un voto computable por usuario.
* [ ] El ciclo semanal concluye los domingos a las 23:59:59 UTC, proclamando al Clan Regente e iniciando a 0 PDA los contadores semanales.
* [ ] El empate en el primer puesto semanal se dirime primero por mayor número de conjuros validados en la semana y segundo por marca temporal anterior.
* [ ] El Clan Regente exhibe su estandarte en el Gran Portal, ribete dorado en sus conjuros y crónica perpetua en el Salón de Linajes.
* [ ] Los conjuros validados son patrimonio inviolable del clan en que nacieron y nunca se transfieren al salir el autor.
* [ ] Los clanes disueltos pasan a `archived`, su nombre queda permanentemente reservado y sus conjuros validados se preservan como «Herencia Ancestral».
* [ ] El Salón de los Linajes permite consultar la clasificación semanal en vivo, la histórica total y el Libro Mayor de Campeones.
* [ ] Cero dependencias externas y cumplimiento riguroso del Dogma Vanilla, el Velo Arcano y el Dualismo Lingüístico.
* [ ] El blasón de cada hermandad y el sello de cada linaje se forjan como Sello Rúnico determinista (SPEC-02 RF-07): el identificador `coat_of_arms` jamás se imprime, el estado se declara por metal y forma, y la casa disuelta viste bronce con el anillo roto.

---

## 9. Registro de Resoluciones de Diseño

* **[RESUELTO — Ronda 1 / Pregunta 1] Gobierno y Convalecencia:** Ratificada pertenencia única por mago, fundación por editores/maestros y periodo de convalecencia de **catorce (14) días** para unirse a otro clan o fundar uno nuevo (manteniéndose los 30 días de veto ético para firmas de Maestros bajo el Artículo III).
* **[RESUELTO — Ronda 1 / Pregunta 2] Los 8 Linajes Canónicos:** Ratificados los 8 linajes vinculados a las afinidades elementales, con bonificación temática del $+25\%$ en PDA sin alterar costes de maná en la forja (respeto pleno al Artículo II).
* **[RESUELTO — Ronda 1 / Pregunta 3] Dinámica de Puntuación:** Ratificado modelo multi-factor con escala por Círculo ($100 + \text{Círculo} \times 20$), combos en el simulador (+10 PDA con techo de 50 PDA diarios por adepto) y favoritos (+5 PDA con control anti-granja).
* **[RESUELTO — Ronda 1 / Pregunta 4] Ciclo y Prestigio:** Ratificado ciclo semanal cerrado los domingos a las 23:59:59 UTC, reinicio a 0 PDA semanal y honores honoríficos estrictos (Estandarte en el Portal, ribete dorado y Salón de Linajes).
* **[RESUELTO — Ronda 1 / Pregunta 5] Inviolabilidad del Patrimonio:** Ratificada la permanencia perpetua de conjuros validados en el clan de origen, conservación de borradores con el autor, no sustracción de puntos y salvaguarda como «Herencia Ancestral» ante disolución.
* **[RESUELTO — Ronda 1 / Pregunta 6] Fronteras del Alcance:** Ratificada la exclusión de economía monetaria, guerras PvP de asedio, alianzas complejas y delegación del proceso de 3 firmas a SPEC-08.
* **[RESUELTO — Ronda QA / Pregunta 1] Capacidad, Admisión y Visibilidad:** Capacidad máxima fijada en 30 miembros por clan. Régimen de admisión configurable por el Patriarca (`open` / `byApplication`, con límite de 3 solicitudes pendientes). Estado de convalecencia visible solemnemente en el perfil público.
* **[RESUELTO — Ronda QA / Pregunta 2] Precisión Numérica y Atribución en Moderación:** Redondeo aritmético estándar al entero más próximo (`round`) para el $+25\%$ de sinergia de linaje. Los conjuros en deliberación acreditan sus PDA al clan bajo cuyo estandarte fueron forjados aunque el autor haya marchado. Corte de 50 PDA diarios en simulador a las 00:00:00 UTC.
* **[RESUELTO — Ronda QA / Pregunta 3] Sucesión Dinástica y Memoria Histórica:** Inactividad del Patriarca fijada en 45 días naturales consecutivos para sucesión automática al adepto más antiguo. Nombres de clanes disueltos protegidos e inmortalizados a perpetuidad como «Herencia Ancestral» (nunca reutilizables). Criterios de desempate semanal: 1º Mayor volumen de conjuros validados en la semana; 2º Marca temporal anterior.
* **[RESUELTO — Ronda de Diseño / Pregunta 1] La Heráldica Forjada:** Ratificado que el blasón de una casa no se imprima como su clave técnica (`RUNE_TIDE_SPIRAL`), sino que se **forje** como Sello Rúnico determinista: la carga declara el linaje, las muescas del anillo codifican el `coat_of_arms` por huella FNV-1a de 32 bits y el metal del anillo declara el estado (oro antiguo, oro vivo con cera para el Regente, bronce con anillo roto para la casa disuelta). Lo que el sello codifica, no lo deletrea.
* **[RESUELTO — Ronda QA / Pregunta 4] Dualismo Lingüístico (Artículo V):** Ratificados los identificadores canónicos en inglés: linajes (`primordialFlame`, `celestialTides`, `eternalTempest`, `worldRoots`, `dawnWinds`, `solarCrown`, `abyssalShadows`, `aetherWeavers`), roles (`patriarch`, `adept`), estados de clan (`active`, `archived`), estados de mago (`active`, `convalescent`) y métricas (`weeklyPoints`, `historicalPoints`, `dailySimulatorPoints`).
