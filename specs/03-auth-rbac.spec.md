# SPEC-03: Autenticación, Sesiones y Control de Acceso (RBAC)

> **Estado:** Aprobada y Blindada tras Revisión QA  
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
  *quiero* que tras 5 intentos fallidos consecutivos se congele temporalmente la procedencia/IP atacante con respuestas neutras anti-enumeración,  
  *para* repeler ataques de fuerza bruta sin permitir que terceros provoquen la denegación de servicio a usuarios legítimos.

* **HU-05 (Recuperación y Preservación del Legado):**  
  *Como* miembro consagrado,  
  *quiero* poder recuperar mi palabra secreta extraviada mediante un pergamino seguro a mi correo y saber que si alguna vez renuncio al vínculo, mis hechizos validados se conservarán como legado anónimo del clan,  
  *para* no temer por la pérdida de mi cuenta ni por la mutilación de la biblioteca colectiva.

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

### RF-03: Seguridad contra Intrusión, Anti-Enumeración y Anti-DoS
* **RF-03.1 [No Deseado / Excepción]:**  
  SI el usuario introduce credenciales no coincidentes al intentar renovar el vínculo, ENTONCES el sistema DEBERÁ emitir una respuesta genérica de rechazo (*«Las runas no reconocen este vínculo o la palabra secreta es errónea»*) con un tiempo de respuesta computacional uniforme.
* **RF-03.2 [No Deseado / Excepción]:**  
  SI se registran cinco (5) intentos fallidos consecutivos de acceso procedentes de una misma dirección o cliente, ENTONCES el sistema DEBERÁ congelar temporalmente las solicitudes de dicha procedencia durante quince (15) minutos (*«La corriente de maná se ha sobrecargado por exceso de intentos; el umbral permanecerá cerrado durante 15 minutos»*), **sin bloquear la cuenta de usuario legítima** para impedir ataques de denegación de servicio a administradores o maestros.

### RF-04: Recuperación de Acceso («Pergamino de Restablecimiento»)
* **RF-04.1 [Dirigido por Eventos]:**  
  CUANDO un usuario solicite la recuperación de credenciales mediante su correo registrado, el sistema DEBERÁ generar un «Pergamino de Restablecimiento» (enlace firmado criptográficamente de un solo uso con vigencia de sesenta minutos) y remitirlo al correo electrónico sin alterar el estado actual de la cuenta.
* **RF-04.2 [Dirigido por Eventos]:**  
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

- [x] La consagración exige únicamente alias único, correo válido y frase de paso segura, otorgando el rol `editor`: la cuenta nace PEREGRINA (sin linaje ni clan), pues la identidad arcana se jura en la ceremonia bloqueante del primer acceso (enmienda de SPEC-09). *Evidencia: `scratch/test_auth_service.php`, `scratch/test_lineage_consecration.php`.*
- [ ] La sesión tiene vigencia de 14 días renovables con actividad hasta un tope absoluto de 30 días, con soporte multidispositivo.
- [ ] Existe la opción de «Disolver Vínculo» (dispositivo actual) y «Disolver todos los vínculos activos» (global).
- [ ] Tras 5 intentos fallidos consecutivos de una procedencia/IP, el acceso se congela durante 15 minutos sin bloquear cuentas legítimas.
- [ ] Existe el flujo de «Pergamino de Restablecimiento» por correo (1 hora de vigencia de un solo uso).
- [ ] La matriz RBAC aplica estrictamente los 4 roles técnicos (`reader`, `editor`, `master`, `supremeAdmin`) con rechazo temático.
- [ ] El conflicto de intereses bloquea en interfaz y en autorización a Maestros del mismo clan o que hayan pertenecido a dicho clan en los últimos 30 días.
- [ ] Los cambios de clan se restringen a la ventana de tregua de 24 horas y los puntos históricos quedan adscritos al clan de origen.
- [ ] La Bitácora de Auditoría es 100% pública, inmutable y auditable por cualquier persona.
- [ ] Al eliminar una cuenta, los conjuros validados se preservan como legado anónimo del clan sin romper la biblioteca.
- [ ] Se cumple estrictamente la dualidad lingüística y el velo arcano en castellano.

---

## 9. Dudas Abiertas

* *(Ninguna)*: Todos los aspectos de seguridad anti-DoS, límites de sesión, conflicto de intereses histórico, preservación del legado del clan y dualidad técnica han quedado plenamente normados y blindados.
