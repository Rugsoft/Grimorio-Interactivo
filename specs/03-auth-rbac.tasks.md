# TASKS-03: Tareas de Implementación — Autenticación, Sesiones y Control de Acceso (RBAC)

> **Especificación:** [`specs/03-auth-rbac.spec.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/specs/03-auth-rbac.spec.md)  
> **Plan Técnico:** [`specs/03-auth-rbac.plan.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/specs/03-auth-rbac.plan.md)  
> **Reglas:** Tareas atómicas de 20-30 minutos, ordenadas por dependencia estricta, con trazabilidad a RF/RNF y criterio de aceptación verificable.

---

## Fase 1: Esquema de Base de Datos y Modelos de Entidad (SQL & PHP 8.2+)

- [x] **Tarea 1.1: Esquema DDL para usuarios, sesiones, intentos, clanes y auditoría**
  * **Alcance:** Crear las sentencias DDL en `database/schema.sql` para las tablas `users` (con roles `reader`, `editor`, `master`, `supremeAdmin`), `user_sessions`, `login_attempts`, `clan_history` y `audit_log` con claves foráneas e índices.
  * **Cubre:** `RF-01.1`, `RF-02.1`, `RF-03.2`, `RF-06.1`, `RF-08.1`, `Artículo V`
  * **Hecho cuando:** La ejecución del script SQL crea las 5 tablas sin errores y los índices en `(ip_address, attempted_at)` y `(user_id, left_at)` quedan activos.

- [x] **Tarea 1.2: Entidad de dominio `User` inmutable y tipada**
  * **Alcance:** Implementar `src/Models/User.php` con `declare(strict_types=1);`, propiedades privadas tipadas (`id`, `alias`, `email`, `role`, `clanId`, `passwordHash`), getters, métodos de comprobación de rol (`isMaster()`, `isSupremeAdmin()`) y serialización JSON segura (omitiendo el hash).
  * **Cubre:** `RF-01.2`, `RF-05.1`, `RNF-05`, `Artículo V`
  * **Hecho cuando:** La instanciación de un objeto `User` valida estrictamente los 4 roles permitidos y `json_encode($user)` no expone nunca `passwordHash`.

- [x] **Tarea 1.3: Entidad de dominio `AuditEntry` inmutable**
  * **Alcance:** Implementar `src/Models/AuditEntry.php` con propiedades tipadas para capturar marcas temporales UTC, identidades de actuantes, rol activo, tipo de acción, entidad objetivo y motivo justificado.
  * **Cubre:** `RF-08.1`, `RNF-02`
  * **Hecho cuando:** Se puede instanciar un objeto `AuditEntry` válido y convertirlo a un array asociativo normalizado para la respuesta JSON de la bitácora.

---

## Fase 2: Servicios Backend Core (Criptografía, Sesiones y Rate-Limiting)

- [x] **Tarea 2.1: Gestor de sesiones nativas seguras (`SessionManager`)**
  * **Alcance:** Crear `src/Core/SessionManager.php` configurando cookies de sesión con `HttpOnly=true`, `SameSite=Strict`, `Path=/`, control de expiración por inactividad de 14 días y límite absoluto de 30 días contra la tabla `user_sessions`.
  * **Cubre:** `RF-02.1`, `RF-02.2`, `RNF-04`, `Artículo I`
  * **Hecho cuando:** Una sesión creada emite la cookie con las banderas de seguridad correctas y una sesión con más de 30 días de vida absoluta es rechazada forzando reautenticación.

- [x] **Tarea 2.2: Sistema de defensa anti-DoS y fuerza bruta por IP (`RateLimiter`)**
  * **Alcance:** Implementar `src/Core/RateLimiter.php` para registrar intentos en `login_attempts` y comprobar si una IP supera los 5 fallos en una ventana de 15 minutos, calculando los segundos restantes de bloqueo.
  * **Cubre:** `RF-03.2`, `RNF-03`
  * **Hecho cuando:** Tras registrar 5 intentos fallidos para una misma IP, `isBlocked($ip)` devuelve verdadero junto con el tiempo restante sin bloquear a otras IPs ni a usuarios legítimos.

- [x] **Tarea 2.3: Servicio de autenticación, hashing y recuperación (`AuthService`)**
  * **Alcance:** Desarrollar `src/Services/AuthService.php` implementando `consecrate()`, `bind()`, `dissolve()`, `dissolveAll()`, `requestRecovery()` y `resetPassword()`, utilizando `password_hash` con BCRYPT (coste 12) y verificación temporal constante con hash señuelo.
  * **Cubre:** `RF-01.1`, `RF-01.2`, `RF-01.3`, `RF-02.4`, `RF-03.1`, `RF-04.1`, `RF-04.2`, `RNF-01`, `RNF-03`
  * **Hecho cuando:** Las contraseñas se almacenan hasheadas con BCRYPT, las credenciales erróneas tardan un tiempo uniforme en responder y `dissolveAll()` invalida todas las sesiones de un usuario en base de datos.

- [x] **Tarea 2.4: Servicio de verificación de conflicto de intereses entre linajes (`ClanConflictService`)**
  * **Alcance:** Crear `src/Services/ClanConflictService.php` con el método `canMasterSignSpell(User $master, Spell $spell)` comprobando que el maestro no sea el autor, no pertenezca al clan actual del conjuro y no haya pertenecido a dicho clan en los últimos 30 días consultando `clan_history`.
  * **Cubre:** `RF-06.1`, `RF-06.2`, `RF-07.3`, `Artículo III`
  * **Hecho cuando:** La llamada al método rechaza la firma si el maestro es del mismo clan o si perteneció a ese clan hace menos de 30 días, devolviendo el motivo solemne de incompatibilidad.

- [x] **Tarea 2.5: Servicio de registro inmutable de auditoría (`AuditService`)**
  * **Alcance:** Implementar `src/Services/AuditService.php` con métodos para registrar firmas, vetos, promociones y alteraciones de clan exclusivamente mediante sentencias `INSERT` (sin `UPDATE` ni `DELETE`) y para consultar la bitácora con paginación y filtros.
  * **Cubre:** `RF-08.1`, `RF-08.2`, `RNF-02`, `Artículo III`
  * **Hecho cuando:** Cada acción solemne genera un registro con marca UTC en `audit_log` y se pueden recuperar las entradas paginadas ordenadas cronológicamente de forma descendente.

---

## Fase 3: Middleware de Seguridad y Controladores REST

- [x] **Tarea 3.1: Middleware de autenticación e inyección de contexto (`AuthMiddleware`)**
  * **Alcance:** Crear `src/Middleware/AuthMiddleware.php` que lea la cookie de sesión, valide su vigencia en `user_sessions`, actualice `last_activity_at` e inyecte la entidad `User` activa en el contexto de la petición (o un usuario anónimo `reader` si no hay sesión).
  * **Cubre:** `RF-02.1`, `RF-02.2`, `RNF-04`
  * **Hecho cuando:** Las peticiones autenticadas tienen acceso al objeto `$request->getUser()` con latencia de resolución menor a 50 ms.

- [x] **Tarea 3.2: Middleware de control de acceso por roles (`RbacMiddleware`)**
  * **Alcance:** Desarrollar `src/Middleware/RbacMiddleware.php` para interceptar rutas y validar que el rol técnico del usuario satisfaga los privilegios exigidos (`reader`, `editor`, `master`, `supremeAdmin`), respondiendo con código HTTP `403 Forbidden` y leyenda de jerarquía insuficiente en caso contrario.
  * **Cubre:** `RF-05.1`, `RF-05.2`
  * **Hecho cuando:** Un usuario con rol `editor` intentando acceder a una ruta de `master` recibe un error `403 Forbidden` con el mensaje místico correspondiente.

- [x] **Tarea 3.3: Controlador de autenticación y sesiones (`AuthController`)**
  * **Alcance:** Implementar en `src/Controllers/AuthController.php` los métodos para `/api/v1/auth/consecrate`, `/api/v1/auth/bind`, `/api/v1/auth/dissolve`, `/api/v1/auth/dissolve-all`, `/api/v1/auth/session` y los endpoints de recuperación.
  * **Cubre:** `RF-01`, `RF-02`, `RF-03`, `RF-04`
  * **Hecho cuando:** Todos los endpoints de autenticación responden con las estructuras JSON tipadas y los códigos HTTP 200, 201, 400, 401 y 429 estipulados en el plan.

- [x] **Tarea 3.4: Controlador de consulta de la Bitácora de Auditoría (`AuditController`)**
  * **Alcance:** Crear `src/Controllers/AuditController.php` con el método para `GET /api/v1/audit/log`, soportando parámetros query de paginación (`page`, `limit`) y filtros por clan (`clanId`).
  * **Cubre:** `RF-08.2`
  * **Hecho cuando:** La petición a `/api/v1/audit/log` devuelve la lista paginada de eventos de auditoría siendo accesible tanto para visitantes anónimos como para usuarios consagrados.

- [x] **Tarea 3.5: Script automatizado de pruebas de integración (`test_auth_rbac.php`)**
  * **Alcance:** Desarrollar `scratch/test_auth_rbac.php` validando secuencialmente: consagración, login con emisión de cookie segura, matriz RBAC de los 4 roles, bloqueo por conflicto de intereses a 30 días, rate-limiting de 5 fallos y disolución global de sesiones.
  * **Cubre:** `RF-01 a RF-08`, `Plan Sec. 5.1`
  * **Hecho cuando:** La ejecución por CLI de `php scratch/test_auth_rbac.php` concluye con código de salida 0 y todos los asertos en verde.

---

## Fase 4: Componentes de Interfaz y Cliente Frontend (Vanilla JS)

- [x] **Tarea 4.1: Cliente HTTP para autenticación y auditoría (`authClient.js`)**
  * **Alcance:** Crear `public/assets/js/api/authClient.js` con funciones nativas `consecrate(data)`, `bind(identity, passphrase)`, `dissolve()`, `dissolveAll()`, `checkSession()` y `fetchAuditLog(params)` con gestión de cookies automáticas (`credentials: 'same-origin'`).
  * **Cubre:** `RF-01`, `RF-02`, `RF-08`
  * **Hecho cuando:** El cliente efectúa peticiones HTTP nativas a la API y traduce los códigos de estado en objetos reactivos controlados en el frontend.

- [x] **Tarea 4.2: Integración del estado de sesión en el Store reactivo**
  * **Alcance:** Actualizar `public/assets/js/state/store.js` para registrar `currentUser`, `isAuthenticated`, `userRole` y `userClan`, emitiendo eventos de actualización de sesión para los componentes de interfaz.
  * **Cubre:** `RF-02.1`, `RF-02.4`
  * **Hecho cuando:** Iniciar o cerrar sesión actualiza de forma reactiva el estado global del cliente sin recargar la página.

- [x] **Tarea 4.3: Componente del diálogo «Cruzar el Umbral» (Formularios de Vínculo)**
  * **Alcance:** Crear `public/assets/js/components/accessModalComponent.js` con las pestañas *«Renovar Vínculo»* y *«Consagrarse»* (incluyendo el selector obligatorio de clan activo), gestión de foco accesible y mensajes anti-enumeración.
  * **Cubre:** `RF-01.1`, `RF-02.1`, `RF-03.1`, `RF-03.2`
  * **Hecho cuando:** El modal permite alternar fluidamente entre login y registro con selección obligatoria de clan, bloqueando el botón temporalmente si la API devuelve el código 429 de sobrecarga de maná.

- [x] **Tarea 4.4: Componente de recuperación de credenciales (`recoveryModalComponent.js`)**
  * **Alcance:** Crear `public/assets/js/components/recoveryModalComponent.js` para solicitar el Pergamino de Restablecimiento e introducir la nueva frase de paso si se accede mediante un enlace con token.
  * **Cubre:** `RF-04.1`, `RF-04.2`
  * **Hecho cuando:** El usuario puede solicitar el restablecimiento y visualizar la notificación temática de que el pergamino ha sido remitido.

- [x] **Tarea 4.5: Componente de perfil y estado de sesión en cabecera (`userProfileBadge.js`)**
  * **Alcance:** Crear `public/assets/js/components/userProfileBadge.js` para renderizar el alias del usuario, su clan y un menú desplegable arcano con opciones para ver su libro personal, cambiar de clan en tregua o disolver el vínculo.
  * **Cubre:** `RF-02.4`, `RF-07.1`
  * **Hecho cuando:** Al estar autenticado, la cabecera reemplaza el botón «Cruzar el Umbral» por el distintivo del usuario y su clan con opción de disolución individual y global.

---

## Fase 5: Vista de Auditoría y Verificación Integral

- [x] **Tarea 5.1: Vista pública de la Bitácora de Auditoría Arcana (`auditLogView.js`)**
  * **Alcance:** Crear `public/assets/js/views/auditLogView.js` con tabla paginada de decisiones de moderación, sellos de clan, identidades de maestros y motivos solemnes de cada veredicto.
  * **Cubre:** `RF-08.2`, `Artículo III`
  * **Hecho cuando:** Cualquier usuario puede consultar la bitácora pública, filtrar por clan y leer las justificaciones de cada validación o veto.

- [x] **Tarea 5.2: Verificación E2E de Conflicto de Intereses en la Interfaz**
  * **Alcance:** Integrar en la ficha técnica del conjuro la comprobación de clan: si el usuario activo es un Maestro del mismo clan del autor (o de los últimos 30 días), el botón de firma se muestra deshabilitado con la advertencia: *«El vínculo de sangre nubla el juicio...»*.
  * **Cubre:** `RF-06.1`, `Artículo III`
  * **Hecho cuando:** Un Maestro visualiza el botón de firma bloqueado con la advertencia mística al consultar conjuros de su propio linaje.

- [x] **Tarea 5.3: Auditoría integral de seguridad y Dogma Vanilla**
  * **Alcance:** Certificar ausencia de paquetes Composer y librerías externas, comprobación de cookies `HttpOnly`/`SameSite=Strict`, tiempos de respuesta idénticos anti-timing attacks y preservación del legado del clan al eliminar una cuenta.
  * **Cubre:** `RF-09.1`, `RF-09.2`, `RNF-01 a RNF-05`, `Artículo I`, `Artículo III`, `Artículo V`
  * **Hecho cuando:** El script de auditoría confirma que las cookies son seguras, la base de datos no expone contraseñas en texto claro y no existe ninguna dependencia externa en el proyecto.

- [x] **Tarea 5.4 (cierre): Servicio y endpoint REST de Renuncia al Vínculo (`renounceAccount`)**
  * **Alcance:** Exponer como flujo de usuario la secuencia canónica de derecho al olvido validada en la Tarea 5.3: `AuthService::renounceAccount()` anonimiza la cuenta (seudónimo «Erudito Ancestral», correo y hash opacos, pergamino purgado), revoca todas las sesiones y preserva el legado de `clan_history`; `AuthController::renounceAccount()` expone `POST /api/v1/auth/renounce-account` (401 sin vínculo portador, 409 ante doble renuncia, 200 con leyenda solemne) y registra la renuncia en la bitácora como `ACC_LINK_RENOUNCED`. El front controller registra las rutas de autenticación (Endpoints 1-5 + renuncia).
  * **Cubre:** `RF-09.1`, `RF-09.2`, `RF-08.1`, `Artículo III`
  * **Hecho cuando:** La renuncia con vínculo portador responde 200, purga los datos personales preservando el legado del linaje, invalida todas las sesiones y la bitácora queda con el veredicto `ACC_LINK_RENOUNCED` — verificado por `php scratch/test_renounce_account.php` (21 asertos, exit 0).
