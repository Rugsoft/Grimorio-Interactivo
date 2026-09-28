# SPEC-03: Autenticación, Sesiones y Control de Acceso (RBAC)

> **Estado:** Aprobada y Blindada tras Revisión QA — enmendada por SPEC-09 (juramento de linaje) y por SPEC-15 y decisiones de producto ratificadas (2026-09-28: cookie segura y expiración, ventana acumulada del limitador, recuperación fuera de servicio, seudónimo de renuncia compartido, procedencia de red confiable)   
> **Prioridad:** Fundamental (Seguridad, Gobernanza de Linajes e Identidad Arcana)  
> **Enfoque:** QUÉ y POR QUÉ (Requisitos Funcionales, Matriz RBAC y Criterios EARS)  

---

## 1. Contexto y Objetivo

### 1.1 Contexto
El **Grimorio Interactivo** es un espacio colaborativo donde coexisten lectores, creadores de conjuros y maestros evaluadores organizados en linajes mágicos que compiten por el Dominio del Grimorio. Para que esta contienda sea justa y el conocimiento permanezca protegido frente a corrupciones, suplantaciones o desequilibrios, se requiere un sistema riguroso de identidad arcana, sesiones seguras y control de acceso basado en roles (RBAC) que dé estricto cumplimiento al **Artículo III (Ética de la Moderación y Conflicto de Intereses)** y al **Artículo V (Dualidad Lingüística)** de la Constitución.

### 1.2 Objetivo
Definir las reglas de negocio y los flujos de usuario para la consagración de nuevos miembros (registro de credenciales; el juramento de linaje vive en SPEC-09), la renovación del vínculo arcano (autenticación segura y persistente), la disolución de sesiones (individual y global), la recuperación de credenciales extraviadas, la matriz de permisos para los cuatro roles del sistema, la defensa anti-fuerza bruta y anti-DoS, y la Bitácora de Auditoría pública e inmutable.

---

## 2. Usuarios y Matriz de Roles (RBAC)

El sistema reconoce cuatro rangos jerárquicos sagrados con identificadores técnicos universales en inglés (`camelCase`) y denominaciones temáticas solemnes en lengua castellana:

| Identificador Técnico | Denominación Temática | Origen / Asignación | Capacidades y Permisos Principales |
| :--- | :--- | :--- | :--- |
| `reader` | **Lector Arcano** | Usuario no autenticado (público) | Lectura pública de hechizos validados, archivos experimentales, Salón de Linajes y Bitácora de Auditoría. No puede crear contenido, votar ni unirse a clanes. |
| `editor` | **Iniciado / Editor** | Usuario consagrado afiliado a un clan | Todo lo de Lector + creación de conjuros (nacen en estado `experimental`), edición/borrado de conjuros propios no validados y gestión de su libro personal de hechizos. |
| `master` | **Maestro Validador** | Designación solemne del Admin Supremo | Todo lo de Editor + potestad de emitir firmas de validación o rechazo sobre hechizos experimentales ajenos a su propio linaje (actual y de los últimos 30 días). |
| `supremeAdmin` | **Admin Supremo** | Custodio fundador del Grimorio | Autoridad total: nombramiento y remoción de Maestros, administración de clanes, validación directa o veto justificado con registro de auditoría obligatorio. |

---

## 3. Historias de Usuario

* **HU-01 (Consagración de Credenciales):**  
  *Como* visitante que desea participar activamente en el santuario,  
  *quiero* consagrar mi vínculo eligiendo un alias único y mis credenciales,  
  *para* nacer como Editor y jurar después mi linaje en la ceremonia del primer acceso (SPEC-09).

* **HU-02 (Vínculo Arcano Duradero y Cierre Global):**  
  *Como* hechicero activo,  
  *quiero* mantener mi sesión activa durante 14 días renovables con mi actividad (hasta un tope de 30 días) y disponer de la opción de disolver todos mis vínculos a la vez,  
  *para* acceder fluidamente desde múltiples dispositivos y proteger mi cuenta si pierdo un equipo.

* **HU-03 (Imparcialidad Absoluta de Linaje e Historia):**  
  *Como* Maestro encargado de moderar hechizos,  
  *quiero* que el sistema me impida votar conjuros concebidos por miembros de mi clan actual o de mi clan de los últimos 30 días,  
  *para* cumplir con el Artículo III de la Constitución y garantizar una competencia limpia en el Dominio del Grimorio.

* **HU-04 (Protección contra Intrusiones Místicas sin Bloqueo de Cuentas):**  
  *Como* custodio de la seguridad,  
  *quiero* que tras 5 intentos fallidos acumulados en una ventana de 15 minutos se congele temporalmente la procedencia/IP atacante con respuestas neutras anti-enumeración,  
  *para* repeler ataques de fuerza bruta sin permitir que terceros provoquen la denegación de servicio a usuarios legítimos.  
  *[Enmienda ratificada, 2026-09-28: la ventana es ACUMULADA, no de fallos «consecutivos»; ver RF-03.2.]*

* **HU-05 (Recuperación y Preservación del Legado):**  
  *Como* miembro consagrado,  
  *quiero* poder recuperar mi palabra secreta extraviada mediante un pergamino seguro a mi correo y saber que si alguna vez renuncio al vínculo, mis hechizos validados se conservarán como legado anónimo del clan,  
  *para* no temer por la pérdida de mi cuenta ni por la mutilación de la biblioteca colectiva.  
  *[Enmienda ratificada, 2026-09-28: la recuperación de contraseña queda FUERA DE SERVICIO hasta que se ratifique un canal de entrega seguro; ver RF-04. La preservación del legado se refina en RF-09.3 y RF-09.4.]*

* **HU-06 (Transparencia Total en la Bitácora de Auditoría):**  
  *Como* cualquier persona de la comunidad (sea visitante o iniciado),  
  *quiero* consultar el registro inmutable de todas las firmas, vetos y promociones de los moderadores,  
  *para* auditar con total transparencia la integridad y los motivos de cada decisión en el compendio.

---

## 4. Requisitos Funcionales (Notación EARS en Español)

### RF-01: Proceso de Consagración (Registro de Credenciales)
> **[Enmendado por SPEC-09 — Juramento de Linaje en el Primer Acceso.]** La selección de linaje/clan en el registro queda retirada: la identidad arcana se jura en la ceremonia bloqueante del primer acceso (SPEC-09), con doctrina, heráldica e irrevocabilidad manifiestas.
* **RF-01.1 [Ubicuo]:**  
  El sistema DEBERÁ requerir para la consagración de un nuevo miembro únicamente: un nombre de iniciado (alias único de 3 a 30 caracteres alfanuméricos), un correo electrónico válido y una frase de paso secreta (mínimo 8 caracteres, permitiendo frases en lenguaje natural sin forzar símbolos arbitrarios). La selección de linaje o clan NO FORMA PARTE del registro.
* **RF-01.2 [Dirigido por Eventos]:**  
  CUANDO el usuario complete la consagración con datos válidos, el sistema DEBERÁ crear la cuenta, asignarle el rol técnico de `editor`, dejarla **sin linaje asignado** (linaje nulo; el juramento pertenece a SPEC-09) e iniciar de forma automática su sesión de usuario.
* **RF-01.3 [No Deseado / Excepción]:**  
  SI el alias o el correo propuesto ya se encuentran en uso, ENTONCES el sistema DEBERÁ responder con una notificación genérica y neutral que no permita a un observador externo verificar la existencia de identidades registradas, canalizando el aviso mediante un correo discreto a la cuenta original si corresponde.

### RF-02: Renovación, Persistencia y Disolución del Vínculo («Sesiones»)
* **RF-02.1 [Dirigido por Eventos]:**  
  CUANDO el usuario ingrese sus credenciales válidas en «Renovar Vínculo», el sistema DEBERÁ autenticar al miembro e iniciar una sesión con una vigencia temporal inicial de exactamente catorce (14) días.
* **RF-02.2 [Estado]:**  
  MIENTRAS el usuario mantenga actividad en el santuario, el sistema DEBERÁ renovar automáticamente la vigencia de los 14 días a partir de su última acción, hasta alcanzar un **límite de vida absoluto de treinta (30) días consecutivos**, tras el cual se exigirá una reautenticación solemne.
* **RF-02.3 [Ubicuo]:**  
  El sistema DEBERÁ permitir que una misma cuenta mantenga sesiones activas concurrentes en múltiples dispositivos y navegadores sin invalidarse entre sí.
* **RF-02.4 [Dirigido por Eventos]:**  
  CUANDO el usuario active «Disolver Vínculo», el sistema DEBERÁ destruir la sesión en el dispositivo actual y restituirlo al rol de `reader`; SI el usuario activa «Disolver todos los vínculos activos», ENTONCES el sistema DEBERÁ revocar de forma inmediata todas las sesiones activas asociadas a la cuenta en todos los dispositivos.
* **RF-02.5 [Ubicuo] [Enmienda SPEC-15 ratificada — Bandera `Secure` de la cookie]:**  
  El sistema DEBERÁ emitir la cookie de vínculo (`grimorio_session`) con los atributos `Secure`, `HttpOnly`, `SameSite=Strict` y `Path=/`. La bandera `Secure` se determinará EXCLUSIVAMENTE a partir de una señal directa del servidor (p. ej. `$_SERVER['HTTPS']` en despliegues con TLS terminado en el propio servidor web) o de una configuración de despliegue explícita (`GRIMORIO_COOKIE_SECURE=true`); NUNCA a partir de cabeceras controlables por el cliente (p. ej. `X-Forwarded-Proto`). El desarrollo local sobre HTTP plano podrá preservar la emisión sin `Secure` para no bloquear el entorno de pruebas.
* **RF-02.6 [Dirigido por Eventos] [Enmienda SPEC-15 ratificada — Expiración de la cookie al revocar]:**  
  CUANDO la disolución global («Disolver todos los vínculos activos») o la renuncia al vínculo (RF-09) se complete con éxito en el servidor, el sistema DEBERÁ devolver en esa misma respuesta una cabecera `Set-Cookie` que expire la cookie portadora usando los mismos atributos de alcance (nombre `grimorio_session`, `Path=/`, `HttpOnly`, `SameSite=Strict`), de modo que el navegador la descarte de inmediato y el token revocado deje de circular. Una revocación fallida NO DEBERÁ producir respuesta de éxito ni caducar la cookie de una sesión ajena. La expiración se verificará sobre HTTP real (cabecera `Set-Cookie` observada), no mediante introspección en CLI.

### RF-03: Seguridad contra Intrusión, Anti-Enumeración y Anti-DoS
* **RF-03.1 [No Deseado / Excepción]:**  
  SI el usuario introduce credenciales no coincidentes al intentar renovar el vínculo, ENTONCES el sistema DEBERÁ emitir una respuesta genérica de rechazo (*«Las runas no reconocen este vínculo o la palabra secreta es errónea»*) con un tiempo de respuesta computacional uniforme.
* **RF-03.2 [No Deseado / Excepción] [Enmienda ratificada — ventana acumulada]:**  
  SI se registran cinco (5) intentos fallidos de acceso procedentes de una misma dirección o cliente DENTRO de una ventana de quince (15) minutos, ENTONCES el sistema DEBERÁ congelar temporalmente las solicitudes de dicha procedencia durante quince (15) minutos (*«La corriente de maná se ha sobrecargado por exceso de intentos; el umbral permanecerá cerrado durante 15 minutos»*), **sin bloquear la cuenta de usuario legítima** para impedir ataques de denegación de servicio a administradores o maestros. La ventana es **ACUMULADA**, no de fallos consecutivos: un acceso exitoso NO DEBERÁ borrar los fallos aún vigentes dentro de la ventana, y solo el transcurso del tiempo los purga.

### RF-04: Recuperación de Acceso («Pergamino de Restablecimiento»)
> **[Enmienda ratificada por decisión de producto, 2026-09-28 — FUERA DE SERVICIO.]** La recuperación de contraseña queda **deshabilitada** hasta que se apruebe y configure un canal de entrega seguro del pergamino (no existe hoy transporte de correo operativo ni entrega verificable del token). En consecuencia, RF-04.1 y RF-04.2 quedan **en suspenso normativo**: no deberá existir endpoint de recuperación activo ni interfaz operativa cableada (el componente huérfano de la Torre deberá permanecer desconectado o ser retirado en la próxima tarea que lo toque). Su reactivación exigirá una enmienda futura que norme el canal de entrega; no forma parte del alcance de SPEC-15.
* **RF-04.1 [Dirigido por Eventos] [SUSPENDIDO]:**  
  CUANDO un usuario solicite la recuperación de credenciales mediante su correo registrado, el sistema DEBERÁ generar un «Pergamino de Restablecimiento» (enlace firmado criptográficamente de un solo uso con vigencia de sesenta minutos) y remitirlo al correo electrónico sin alterar el estado actual de la cuenta.
* **RF-04.2 [Dirigido por Eventos] [SUSPENDIDO]:**  
  CUANDO el usuario acceda mediante un enlace de restablecimiento válido y defina una nueva frase de paso, el sistema DEBERÁ actualizar las credenciales, invalidar el enlace utilizado y revocar preventivamente todas las sesiones activas previas.

### RF-05: Matriz de Control de Acceso (RBAC) y Jerarquía Sagrada
* **RF-05.1 [Ubicuo]:**  
  El sistema DEBERÁ aplicar las autorizaciones según los 4 roles canónicos:
  * `reader`: Acceso de lectura pública al catálogo, archivos experimentales, Salón de Linajes y Bitácora de Auditoría.
  * `editor`: Todo lo de `reader` + redacción de conjuros (nacen en estado `experimental`), edición y borrado de conjuros propios no validados y gestión de libro personal.
  * `master`: Todo lo de `editor` + emisión de firmas solemnes de validación o rechazo sobre conjuros experimentales no sujetos a incompatibilidad.
  * `supremeAdmin`: Todo lo de `master` + nombramiento y degradación de Maestros, gestión de clanes y validación o veto directo justificado.
* **RF-05.2 [No Deseado / Excepción]:**  
  SI un usuario intenta invocar una acción restringida no autorizada para su rol activo, ENTONCES el sistema DEBERÁ denegar la operación emitiendo un estado temático de jerarquía insuficiente (*«Tus conocimientos aún no alcanzan la jerarquía necesaria para invocar este poder»*).

### RF-06: Salvaguarda Constitucional de Conflicto de Intereses (Artículo III)
* **RF-06.1 [Estado]:**  
  MIENTRAS un Maestro examine un conjuro experimental cuyo autor pertenezca a su mismo clan, o a un clan al que el Maestro haya pertenecido en los últimos treinta (30) días, o haya sido creado por él mismo, el sistema DEBERÁ deshabilitar los controles de firma mostrando la advertencia: *«El vínculo de sangre nubla el juicio: un Maestro no puede juzgar el trabajo de su propio linaje»*.
* **RF-06.2 [No Deseado / Excepción]:**  
  SI la capa de autorización recibe una solicitud de firma donde el clan del Maestro (actual o de los últimos 30 días) coincide con el clan del autor del conjuro, o donde el Maestro es el creador, ENTONCES el sistema DEBERÁ rechazarla de manera estricta e irreversible.

### RF-07: Lealtad de Linaje, Tregua Semanal y Atribución de Puntos
* **RF-07.1 [Ubicuo]:**  
  El sistema DEBERÁ bloquear el cambio de clan de cualquier usuario durante el transcurso del ciclo semanal de competición, habilitando el cambio voluntario exclusivamente durante una **ventana de tregua de veinticuatro (24) horas** tras el cómputo del Dominio del Grimorio.
* **RF-07.2 [Ubicuo]:**  
  El sistema DEBERÁ mantener los puntos históricos aportados por un usuario permanentemente adscritos a su clan original (el cambio de linaje no traslada ni resta puntos de semanas pasadas).
* **RF-07.3 [Estado]:**  
  MIENTRAS un conjuro experimental se encuentre en moderación y su autor sea transferido de clan o suspendido, el sistema DEBERÁ conservar la atribución de los puntos de dicho conjuro para el **clan al que pertenecía el autor en el momento de su concepción**.

> **Nota de alcance (derivación formal):** La materialización completa de RF-07.1 y RF-07.2 (ventana de tregua de 24 horas y conservación de puntos por ciclo semanal) es indisociable del cómputo semanal del Dominio del Grimorio, y por tanto queda **derivada formalmente a `SPEC-07` (Linajes y Dominio)**, conforme a la cláusula 7 (Fuera de Alcance). RF-07.3 se materializa de forma incremental dentro de esta spec: `clan_history` (Tarea 1.1) preserva el historial de linajes con sus marcas de entrada/salida y `ClanConflictService` (Tarea 2.4) lo consulta; la atribución y preservación de puntos por concepción del conjuro se completará junto al motor de Dominio de SPEC-07.

### RF-08: Bitácora Inmutable de Auditoría Arcana (Transparencia Pública)
* **RF-08.1 [Ubicuo]:**  
  El sistema DEBERÁ registrar de forma imborrable cada acción de firma de moderación, rechazo, validación directa, veto, nombramiento de Maestro o alteración de clanes, almacenando: marca temporal UTC, identificador y alias del actuante, rol técnico, acción ejecutada, conjuro/clan afectado y motivo en texto noble.
* **RF-08.2 [Ubicuo]:**  
  El sistema DEBERÁ poner la Bitácora de Auditoría a disposición de consulta pública para cualquier usuario (`reader`, `editor`, `master`, `supremeAdmin`) con capacidades de filtrado cronológico y por linaje.

> **Ampliación ratificada (TASK-08, RF-06.1).** El catálogo cerrado de acciones admite once actos nuevos de la moderación solemne en dos pasos: `MODERATION_SUBMITTED` (elevación a deliberación), `MODERATION_WITHDRAWN` (retiro a la libreta), `MODERATION_REOPENED` (reapertura como borrador), `SIGNATURE_RETRACTED`, `SIGNATURE_ANNULMENT` (anulación de oficio), `SPELL_CONSECRATED` (consagración por tercera firma), `MODERATION_EXPIRED` (caducidad por letargo), `SOVEREIGN_VALIDATION`, `SOVEREIGN_RESCUE`, `SOVEREIGN_ARCHIVE` (el destierro póstumo de RF-04.4) y `SOVEREIGN_POINTS_DEDUCTED` (el EFECTO de ese destierro: la deducción retroactiva de la gloria del linaje, que el Artículo III.3 exige poder auditar con su aritmética exacta y que la Tarea 2.5 inscribe sobre la HERMANDAD que pierde los puntos). La firma de consagración de un Maestro sigue inscribiéndose como `SIGN_VALIDATE` y el Dictamen de Objeción como `SIGN_REJECT`, los actos que este catálogo ya nombraba. La ampliación NO abre la puerta a actos inventados —sigue siendo un catálogo cerrado, validado en el nacimiento de cada asiento— y no altera ninguna de las garantías de inmutabilidad: la bitácora sigue siendo INSERT puro, sin enmienda ni purga. Como la Bitácora es pública, cada acto nuevo se rotula en castellano en `public/assets/js/views/auditLogView.js`, y un aserto automatizado exige que no exista acto del catálogo sin nombre en la lengua del santuario (Artículos IV y V).

### RF-09: Derecho al Olvido y Preservación del Legado del Clan
* **RF-09.1 [Dirigido por Eventos]:**  
  CUANDO un miembro consagrado solicite la eliminación definitiva de su cuenta («Renuncia al Vínculo»), el sistema DEBERÁ purgar de forma irreversible sus datos personales, credenciales y borradores no validados.
* **RF-09.2 [Ubicuo]:**  
  El sistema DEBERÁ conservar permanentemente en el catálogo público todos los conjuros que ya hayan sido validados con anterioridad, reasignando su autoría al seudónimo solemne de *«Erudito Ancestral (Legado Anónimo)»* para salvaguardar la integridad de la biblioteca colectiva y la puntuación de su clan.
* **RF-09.3 [Ubicuo] [Enmienda SPEC-15 ratificada — Seudónimo público común]:**  
  El seudónimo público *«Erudito Ancestral (Legado Anónimo)»* DEBERÁ ser COMÚN y compartido por todas las cuentas renunciadas: ante la comunidad, toda autoría legada se exhibirá bajo ese único rótulo sin distinción de origen. Para respetar la unicidad técnica del alias (`users.alias` UNIQUE), el sistema DEBERÁ mantener internamente identidades de renuncia únicas no colisionables (p. ej. alias reservados con sufijo opaco) cuya representación pública sea siempre el seudónimo común. La segunda y sucesivas renuncias NO DEBERÁN fallir por colisión de unicidad del alias.
* **RF-09.4 [Dirigido por Eventos] [Enmienda SPEC-15 ratificada — Purga de borradores y cierre del vínculo]:**  
  CUANDO se complete la renuncia, el sistema DEBERÁ, además de lo exigido por RF-09.1: (a) purgar los borradores de conjuros (estado `draft`) del renunciante, materializando íntegramente la purga de «borradores no validados» ya exigida por RF-09.1; y (b) devolver la expiración de la cookie portadora conforme a RF-02.6, de modo que el navegador descarte el vínculo de la cuenta disuelta.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Criptografía Robusta en Reposo):**  
  Las frases de paso se almacenarán aplicando algoritmos de derivación de claves criptográficamente seguros y computacionalmente calibrados; queda prohibido almacenar credenciales en texto plano.
* **RNF-02 (Inmutabilidad Estricta de Auditoría):**  
  Ningún registro de la bitácora podrá ser modificado, editado o purgado, garantizando la trazabilidad histórica exigida por el Artículo III de la Constitución.
* **RNF-03 (Mitigación de Ataques Temporales):**  
  El proceso de verificación de credenciales devolverá respuestas con una duración uniforme independiente de si el alias/correo existe o no en la base de datos.
* **RNF-04 (Latencia de Autorización):**  
  La verificación de validez de sesión y rol en cada interacción de la API deberá resolverse en menos de 50 milisegundos.
* **RNF-05 (Soberanía Lingüística y Dualidad Constitucional):**  
  Identificadores de roles y claves técnicas en inglés `camelCase` (`reader`, `editor`, `master`, `supremeAdmin`); y toda la experiencia visible en pantalla, advertencias y motivos expresados en noble castellano.
* **RNF-06 (Procedencia de Red Confiable) [Enmienda SPEC-15 ratificada]:**  
  La dirección de procedencia empleada por el control anti-fuerza bruta (RF-03.2) se determinará EXCLUSIVAMENTE a partir del par conectado (`REMOTE_ADDR`) validado como IPv4/IPv6. Las cabeceras reenviadas (p. ej. `X-Forwarded-For`) se IGNORARÁN salvo que el par conectado pertenezca a una lista de proxies confiables declarada explícitamente en la configuración de despliegue (canal `env.php`, `GRIMORIO_TRUSTED_PROXY_IPS`); por defecto la lista es VACÍA: ninguna cabecera controlable por el cliente podrá determinar la procedencia efectiva ni la identidad registrada por el limitador. Si el hosting acreditara en el futuro proxies intermedios con saneamiento verificado, la lista solo podrá poblarse con evidencia registrada en TASKS-15.

---

## 6. Casos Límite y Reglas de Contingencia

1. **Ascenso o degradación en tiempo real:**  
   La comprobación de rol se efectúa en cada solicitud sobre el almacén de datos; si el Admin Supremo promueve a un Editor a Maestro, sus facultades de firma entran en vigor de inmediato sin requerir re-conexión.
2. **Inhabilitación o suspensión de un Clan:**  
   Si un clan es suspendido, sus miembros quedan en estado de *«Linaje en Suspenso»* (pueden leer y editar borradores propios, pero sus conjuros experimentales quedan congelados en revisión hasta la reasignación o rehabilitación del clan).
3. **Expiración de la sesión durante la redacción de un conjuro:**  
   Si la sesión expira mientras se redacta un conjuro, el cliente debe retener el borrador en memoria local y abrir el diálogo «Renovar Vínculo», permitiendo reanudar la acción sin pérdida de texto.
4. **Firmas concurrentes sobre el mismo conjuro:**  
   Si dos Maestros intentan emitir la tercera firma simultáneamente, el sistema procesa la que llegue primero como la firma definitoria de validación y transforma la segunda en una ratificación honorífica complementaria sin generar estados inconsistentes.

---

## 7. Fuera de Alcance (Out of Scope)

* Las fórmulas matemáticas del coste de maná (cubiertas en `SPEC-04`).
* El simulador de grimorio con partículas Canvas y Web Speech API (cubierto en `SPEC-05`).
* El cálculo semanal del ranking de Dominio del Grimorio (cubierto en `SPEC-07`), junto con la ventana de tregua de 24 horas para el cambio de linaje y la conservación/traslado de puntos históricos (RF-07.1, RF-07.2 y RF-07.3, derivados formalmente a `SPEC-07`).
* La implementación física de tablas SQL o manejo específico de cookies (reservados al Plan Técnico).

---

## 8. Criterios de Finalización (Definition of Done)

- [x] La consagración exige únicamente alias único, correo válido y frase de paso segura, otorgando el rol `editor`: la cuenta nace PEREGRINA (sin linaje ni clan), pues la identidad arcana se jura en la ceremonia bloqueante del primer acceso (enmienda de SPEC-09). La respuesta ante identidad reclamada es neutra (409 anti-enumeración) y el titular de la identidad recibe un aviso discreto cuyo contenido nace de `AuthService::buildDuplicateOwnerNotice()` — función pura que nombra al titular, jamás al pretendiente, y queda tras la puerta anti-DoS. *Evidencia: `scratch/test_auth_service.php`, `scratch/test_lineage_consecration.php`, `scratch/test_discreet_duplicate_notice.php` (20/0).*
- [ ] La sesión tiene vigencia de 14 días renovables con actividad hasta un tope absoluto de 30 días, con soporte multidispositivo.
- [ ] Existe la opción de «Disolver Vínculo» (dispositivo actual) y «Disolver todos los vínculos activos» (global).
- [ ] Tras 5 intentos fallidos acumulados por una procedencia/IP dentro de una ventana de 15 minutos (los éxitos no purgan fallos vigentes; enmienda ratificada de RF-03.2), el acceso se congela durante 15 minutos sin bloquear cuentas legítimas.
- [x] La recuperación de contraseña («Pergamino de Restablecimiento») permanece FUERA DE SERVICIO por decisión ratificada (2026-09-28): sin endpoint activo ni UI cableada hasta que una enmienda futura norme el canal de entrega. *(Enmienda de RF-04; el criterio original de existencia del flujo queda suspendido con ella.)*
- [ ] La matriz RBAC aplica estrictamente los 4 roles técnicos (`reader`, `editor`, `master`, `supremeAdmin`) con rechazo temático.
- [x] El conflicto de intereses bloquea en interfaz y en autorización a Maestros del mismo clan o que hayan pertenecido a dicho clan en los últimos 30 días: las tres leyendas literales del validador (linaje actual, histórico de 30 días, propia pluma) viajan intactas del backend a la alerta viva de la Torre, el botón de firma nace inhabilitado (`disabled` + `aria-disabled`) y el gesto vetado jamás alcanza el bus. *Evidencia: `scratch/test_ethical_conflict_ui.mjs` (22/0), `scratch/test_clan_conflict.php`, `scratch/test_auth_rbac.php`.*
- [x] El vínculo de sesión viaja como credencial que blinda las mutaciones (AGENTS.md 6.1, CSRF): la cookie porta íntegras sus cuatro banderas (HttpOnly, SameSite=Strict, Path=/, Max-Age de 14 días), las mutaciones sin credencial jamás mutan, un token falsificado no resuelve sesión y la contemplación pública no se degrada. *Evidencia: `scratch/test_csrf_cookie_shield.php` (19/0, sonda HTTP real), `scratch/test_auth_rbac.php`, `scratch/test_security_audit.php`.*
- [x] Si la sesión expira durante la redacción de un conjuro (caso límite 3), el borrador se retiene en memoria local, el 401 jamás lo borra ni blanquea el formulario, y la reanudación del vínculo lo restaura íntegro para guardar sin pérdida de texto. *Evidencia: `scratch/test_draft_session_expiry.mjs` (22/0), `scratch/test_spell_creator_view.mjs`.*
- [ ] Los cambios de clan se restringen a la ventana de tregua de 24 horas y los puntos históricos quedan adscritos al clan de origen.
- [ ] La Bitácora de Auditoría es 100% pública, inmutable y auditable por cualquier persona.
- [ ] Al eliminar una cuenta, los conjuros validados se preservan como legado anónimo del clan sin romper la biblioteca, bajo el seudónimo público común «Erudito Ancestral (Legado Anónimo)» con identidades internas únicas (RF-09.3), purgando además los borradores `draft` del renunciante (RF-09.4) y expirando su cookie (RF-02.6).
- [x] Se cumple estrictamente la dualidad lingüística y el velo arcano en castellano: cero literales de UI en inglés y cero referencias técnicas (RF-xx, RNF-xx, Art., SPEC-xx) en los módulos de la superficie de autenticación; claves JSON en camelCase; roles técnicos rotulados en castellano solo en la capa de presentación. *Evidencia: `scratch/test_auth_language_sovereignty.php` (21/0), `scratch/test_audit_log_view.mjs`.*

---

## 9. Dudas Abiertas

* *(Ninguna)*: Todos los aspectos de seguridad anti-DoS, límites de sesión, conflicto de intereses histórico, preservación del legado del clan y dualidad técnica han quedado plenamente normados y blindados.
