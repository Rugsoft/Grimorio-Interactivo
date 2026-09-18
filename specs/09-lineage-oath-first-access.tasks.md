# TASKS-09: Tareas de Implementación — Juramento de Linaje en el Primer Acceso

> **Especificación:** [`specs/09-lineage-oath-first-access.spec.md`](../09-lineage-oath-first-access.spec.md)
> **Plan Técnico:** [`specs/09-lineage-oath-first-access.plan.md`](../09-lineage-oath-first-access.plan.md)
> **Constitución:** [`constitution.md`](../../constitution.md) | **Directrices:** [`AGENTS.md`](../../AGENTS.md)
> **Reglas:** Tareas atómicas de 20-30 minutos, ordenadas por dependencia lógica estricta, con trazabilidad exhaustiva a RF/RNF y criterio de aceptación verificable («Hecho cuando: ...»).

---

## Fase 1: Esquema de Base de Datos, Migración y Repositorio (PDO)

- [ ] **Tarea 1.1: Migración de la columna de linaje (`sql/09_lineage_oath.sql`)**
  * **Alcance:** Crear el guion SQL idempotente (estilo `07_*`/`08_*`): añadir `users.lineage TEXT NULL` con `CHECK` del canon de 8 linajes (recreando la tabla si el dialecto no admite el `CHECK` por `ALTER`), detectar la columna existente antes de actuar, y ejecutar el **respaldo de legado** — `UPDATE users SET lineage = (SELECT c.lineage_type FROM clans c WHERE c.id = users.clan_id) WHERE lineage IS NULL AND clan_id IS NOT NULL`.
  * **Cubre:** `RF-01.5`, `RF-04.1`, caso límite 7 (exención de legados)
  * **Hecho cuando:** Ejecutar el guion dos veces consecutivas sobre SQLite no produce error ni duplica el respaldo, un usuario con `clan_id` histórico despierta con su `lineage_type` heredado, y los sin clan quedan con `lineage IS NULL`.
  * **Verificación:** `scratch/test_lineage_migration.php`

- [ ] **Tarea 1.2: Actualización del esquema maestro y semillas (`database/schema.sql`, `database/seeds.sql`)**
  * **Alcance:** Incorporar `lineage` al DDL maestro de `users` con el mismo `CHECK` del canon (coherencia guion↔esquema, lección de SPEC-08 Tarea 1.5) y añadir las 8 doctrinas canónicas (condensada e íntegra, Anexo A del plan) como semilla o fuente canónica del catálogo.
  * **Cubre:** `RF-02.1`, `RF-02.2`, `RNF-02`
  * **Hecho cuando:** Una base nueva creada desde `schema.sql` ya nace con la columna y su `CHECK`, y el catálogo de linajes sirve las 8 doctrinas en ambas granularidades en noble castellano.
  * **Verificación:** `scratch/test_lineage_migration.php` (fase de coherencia DDL) + revisión del catálogo servido

- [ ] **Tarea 1.3: Repositorio del juramento (`src/Repositories/LineageOathRepository.php`)**
  * **Alcance:** Implementar con `declare(strict_types=1);` y PDO exclusivamente preparado: `findAccountState(userId)` (linaje actual), `sealOathGuarded(userId, lineage, now)` — `UPDATE users SET lineage = :lineage WHERE id = :id AND lineage IS NULL` con `rowCount` como veredicto de carrera — y lectura del catálogo con `hasActiveClans` derivado de `clans`.
  * **Cubre:** `RF-03.1`, `RF-03.3`, `RF-02.1`
  * **Hecho cuando:** Todos los accesos usan `prepare()/execute()` con *binding* (cero concatenación), `sealOathGuarded` retorna «sellado ahora» o «ya linajado» según `rowCount`, y un segundo `sealOathGuarded` sobre cuenta linajada no muta nada.
  * **Verificación:** `scratch/test_lineage_oath_repository.php`

## Fase 2: Servicios de Dominio y Middleware de Retención (Backend)

- [ ] **Tarea 2.1: Catálogo ceremonial (`src/Services/LineageCatalogService.php` + DTOs)**
  * **Alcance:** Crear el servicio del canon inmutable (§5.3 del plan) con `getOathCatalog(accountLineage)`, retornando los 8 `LineageProfileDto` (`id`, `name`, `glyph`, `bannerColor`, `rulingElement`, `doctrineCondensed`, `doctrineFull`, `hasActiveClans`) y el estado de cuenta (`pilgrim` | linaje). Sin tabla administrable: la inmutabilidad es de servicio.
  * **Cubre:** `RF-02.1`, `RF-02.2`, caso límite 5 (canon inmutable), `RNF-02`
  * **Hecho cuando:** El servicio retorna exactamente 8 fichas con las claves del contrato del plan (§2.2, Endpoint 1), `hasActiveClans` refleja la existencia de clanes `active` por linaje, y no existe método alguno de mutación en su API.
  * **Verificación:** `scratch/test_lineage_catalog_service.php`

- [ ] **Tarea 2.2: Servicio del juramento (`src/Services/LineageOathService.php`)**
  * **Alcance:** Implementar `sealOath(userId, lineageId, actorRole)`: validación de canon (única validación, caso límite 5), delegación en `sealOathGuarded`, re-evaluación tras carrera (`rowCount = 0` → re-lee y resuelve por idempotencia), exención del Admin Supremo (`OATH_FORBIDDEN_ROLE`), asiento `LINEAGE_OATH_SWORN` en `AuditService` (catálogo cerrado + rótulo castellano «Juramento de Linaje sellado») y consumo de la ruta retenida de la sesión.
  * **Cubre:** `RF-03.1`, `RF-03.3`, `RF-03.4`, `RF-01.6`, `RNF-06`
  * **Hecho cuando:** Mismo linaje reenviado responde éxito sin mutación; linaje distinto lanza `OathConflict`; dos `sealOath` entrelazados producen un solo ganador determinista; el Admin Supremo recibe `OathForbiddenRole`; y cada sellado feliz genera un asiento de bitácora con actor, acto y estampa temporal.
  * **Verificación:** `scratch/test_lineage_oath_service.php`

- [ ] **Tarea 2.3: Middleware de retención (`src/Middleware/LineageOathMiddleware.php`)**
  * **Alcance:** Implementar la guardia de sustancia: para sesión válida con `lineage IS NULL` y rol ≠ `supremeAdmin`, denegar toda ruta de gestión no incluida en la lista blanca (canon ceremonial, juramento, ruta retenida, perfil de credenciales, logout, lectura pública) con 403 `LINEAGE_OATH_REQUIRED` + mensaje solemne; retener en `$_SESSION['retainedRoute']` la ruta solicitada saneada (lista blanca de vistas internas de `main.js`, jamás URLs externas); dejar pasar a linajados y Supremo sin comprobación adicional.
  * **Cubre:** `RF-01.3`, `RF-01.4`, `RF-01.6`, `RF-05.1`, `RF-05.3`
  * **Hecho cuando:** Un peregrino recibe 403 `LINEAGE_OATH_REQUIRED` en cualquier ruta de gestión, la ruta solicitada interna queda en su sesión, una URL externa se descarta, un linajado jamás ve la guardia y el Supremo navega exento con o sin linaje.
  * **Verificación:** `scratch/test_lineage_oath_middleware.php`

- [ ] **Tarea 2.4: Guardia de designación de Maestro (`src/Services/SovereignAdminService.php`)**
  * **Alcance:** Añadir la guardia de RF-05.2 a `promoteMaster` (y al flujo equivalente del `SovereignAdminController`): rechazo solemne si la cuenta candidata tiene `lineage IS NULL`, con mensaje en noble castellano y sin exponer trazas.
  * **Cubre:** `RF-05.2`, Artículo III
  * **Hecho cuando:** Intentar ascender a un peregrino fracasa con error controlado solemne, la cuenta queda sin cambiar y el ascenso de un linajado sigue funcionando intacto.
  * **Verificación:** `scratch/test_lineage_oath_service.php` (fase de designación)

- [ ] **Tarea 2.5: Enmienda de la consagración (`src/Services/AuthService.php`, `src/Controllers/AuthController.php`)**
  * **Alcance:** `consecrate()` deja de recibir/vincular `clanId` (lo ignora en silencio si llega — §5.8 del plan), crea la cuenta con `lineage: null`, y el contrato de respuesta 201 pasa a `user.lineage` sin `clanName` ni `clanId`; `bind()`/`auth/me` exponen `lineage` para la hidratación del store.
  * **Cubre:** `RF-01.1`, `RF-01.2`, enmienda SPEC-03 (RF-01.1/01.2, HU-01)
  * **Hecho cuando:** Una consagración sin `clanId` crea cuenta `editor` con `lineage: null` y sesión iniciada; una consagración con `clanId` legado la ignora sin error; `GET /api/v1/auth/me` retorna `lineage`.
  * **Verificación:** `scratch/test_lineage_consecration.php`

- [ ] **Tarea 2.6: Controlador y rutas (`src/Controllers/LineageOathController.php`, `public/index.php`)**
  * **Alcance:** Implementar el controlador de los 3 endpoints (§2.2 del plan): `GET /api/v1/lineage/oath-catalog`, `POST /api/v1/lineage/oath` (con verificación CSRF y mapeo de excepciones del servicio a 200/400/401/403), `POST /api/v1/lineage/retained-route` (204, saneamiento); registrar rutas y encadenar `AuthMiddleware → RbacMiddleware → LineageOathMiddleware` en las rutas de gestión existentes.
  * **Cubre:** `RF-02.1`, `RF-03.1`, `RF-03.2`, `RF-05.1`, `RF-05.3`
  * **Hecho cuando:** Los tres endpoints responden con los contratos y códigos exactos del plan ante entradas válidas y hostiles, y todas las rutas de gestión pasan por la cadena de tres middlewares.
  * **Verificación:** `scratch/test_lineage_oath_controller.php`

## Fase 3: Frontend — Datos, Interceptor e Identidad (Vanilla ES Modules)

- [ ] **Tarea 3.1: Cliente del juramento (`public/assets/js/api/lineageOathClient.js`)**
  * **Alcance:** Cliente `fetch` de los 3 endpoints: `fetchOathCatalog()`, `sealOath(lineageId)` y `retainRoute(route)`; mapeo de códigos 200/204/400/401/403 a veredictos estructurados (`{ status, lineage, retainedRoute, errorCode }`), con envío del token CSRF en la mutación.
  * **Cubre:** `RF-02.1`, `RF-03.1`, `RF-03.2`
  * **Hecho cuando:** Cada código HTTP del contrato produce el veredicto estructurado correspondiente y ningún método lanza excepción no controlada ante 4xx/5xx.
  * **Verificación:** `scratch/test_lineage_oath_client.mjs`

- [ ] **Tarea 3.2: Store e interceptor de retención (`public/assets/js/store.js`, `main.js`)**
  * **Alcance:** Hidratar `sessionUser.lineage` desde `auth/me`; en `navigate()` aplicar la guarda del §3.2 del plan (peregrino + vista no blanca → retener ruta vía API y desviar a `juramento`); añadir la vista `juramento` a la lista blanca del enrutador; tras `oath:sealed`, actualizar el store y navegar a `retainedRoute` o al portal.
  * **Cubre:** `RF-01.3`, `RF-01.7`, `RF-03.1`, `RNF-04`
  * **Hecho cuando:** Un peregrino que pide `#/creador` acaba en la ceremonia con su ruta retenida; tras sellar, aterriza en `#/creador`; un linajado navega sin un solo round-trip adicional y el Supremo jamás es retenido.
  * **Verificación:** `scratch/test_lineage_retention_nav.mjs`

- [ ] **Tarea 3.3: Identidad del peregrino (`userProfileBadge.js`, `navbarComponent.js`)**
  * **Alcance:** Rótulo «Peregrino sin Linaje» (sin heráldica) para `lineage: null` y blasón del linaje jurado (heráldica compartida con SPEC-07) tras el juramento; textos castellanos, `textContent` puro (sin `innerHTML`).
  * **Cubre:** `RF-04.3`, `RNF-02`
  * **Hecho cuando:** El badge del peregrino muestra el rótulo solemne sin blasón y el del linajado muestra su heráldica, ambos sin llamadas extra ni XSS posible.
  * **Verificación:** `scratch/test_lineage_retention_nav.mjs` (fase de identidad)

## Fase 4: Frontend — La Ceremonia (Vista, Tarjetas y Modal)

- [ ] **Tarea 4.1: Tarjeta heráldica (`public/assets/js/components/lineageCardComponent.js`)**
  * **Alcance:** Componente contraído/expandido: contraída con nombre, glifo, estandarte, elemento rector y doctrina condensada; expandida con doctrina íntegra, botón «Jurar» y nota discreta «Sin hermandades activas» cuando `hasActiveClans = false`; enfocable y operable por teclado (Enter/espaciadora); emite `oath:lineage-expanded`.
  * **Cubre:** `RF-02.1`, `RF-02.2`, `RNF-05`
  * **Hecho cuando:** Las 8 tarjetas renderizan su heráldica y condensada, la expansión revela la íntegra y el botón, la nota aparece solo en linajes sin clanes activos y todo es operable sin ratón.
  * **Verificación:** `scratch/test_lineage_oath_view.mjs`

- [ ] **Tarea 4.2: Modal solemne de doble confirmación (`public/assets/js/components/oathModalComponent.js`)**
  * **Alcance:** `<dialog>` nativo con el juramento en primera persona nombrando al linaje, advertencia de perpetuidad visible e ineludible (RNF-03), botón «Sellar el juramento» (segunda confirmación explícita) y descarte seguro (botón/Escape); foco atrapado dentro y devuelto al elemento originador al cerrar; anuncios ARIA; emite `oath:confirmation-opened/dismissed/confirmed`.
  * **Cubre:** `RF-02.3`, `RF-03.1`, `RNF-03`, `RNF-05`
  * **Hecho cuando:** El modal exige segunda pulsación explícita, el descarte no consume nada, el foco jamás escapa mientras está abierto y regresa al cerrarlo, y la región viva anuncia apertura y veredicto.
  * **Verificación:** `scratch/test_lineage_oath_modal.mjs`

- [ ] **Tarea 4.3: Vista de la ceremonia (`public/assets/js/views/lineageOathView.js`)**
  * **Alcance:** Orquestador: carga del canon vía cliente, rejilla solemne de las 8 tarjetas, flujo expandida → modal → `sealOath`, estados de carga/«Sellando…»/fallo (aviso solemne «El canon no responde» + reintento sin liberar retención), emisión de `oath:sealed`/`oath:failed` con el bus `CustomEvent` del §4 del plan, y manejo del veredicto (retorno a `retainedRoute` o portal).
  * **Cubre:** `RF-02.1`, `RF-02.2`, `RF-03.1`, `RF-03.2`, `RNF-04`
  * **Hecho cuando:** El flujo completo peregrino→sellado funciona de punta a punta contra el cliente falso, el fallo de canon muestra el aviso con reintento manteniendo la retención, y `oath:sealed` porta `{ lineage, retainedRoute }`.
  * **Verificación:** `scratch/test_lineage_oath_view.mjs`

- [ ] **Tarea 4.4: Velo Arcano de la ceremonia (`public/assets/css/components/lineage-oath.css`)**
  * **Alcance:** Hoja de estilos de la ceremonia con tokens de SPEC-02 (oro arcano sobre tintas oscuras, tipografía ceremonial), tarjetas heráldicas con foco visible ≥ 4.5:1, estados del modal y bloque `@media (prefers-reduced-motion: reduce)` sin transiciones.
  * **Cubre:** `RNF-01`, `RNF-05`
  * **Hecho cuando:** La ceremonia viste los tokens del grimorio sin colores fuera de catálogo, el foco de teclado es visible con contraste AA y con movimiento reducido no hay transición alguna.
  * **Verificación:** revisión visual + inspección de estilos en el arnés de la vista

## Fase 5: Integración, Enmienda del Registro y Verificación Final

- [ ] **Tarea 5.1: Retirada del selector de linaje del registro (`accessModalComponent.js`)**
  * **Alcance:** Eliminar el desplegable de clan/linaje y su validación obligatoria del flujo de registro (enmienda SPEC-03); el payload de registro ya no porta `clanId`; textos de la pestaña de consagración actualizados al nuevo flujo («tras consagrarte, jurarás tu linaje en el umbral del santuario»).
  * **Cubre:** `RF-01.1`, enmienda SPEC-03 (HU-01)
  * **Hecho cuando:** El formulario de registro solo solicita alias, correo y frase de paso, no existe referencia alguna a `clanId` en su flujo, y el registro real contra el servidor de demo crea la cuenta y aterriza en la ceremonia.
  * **Verificación:** `scratch/test_lineage_retention_nav.mjs` (fase de registro) + verificación manual

- [ ] **Tarea 5.2: Catálogo cerrado de la Bitácora (`AuditService`, `auditLogView.js`)**
  * **Alcance:** Inscribir `LINEAGE_OATH_SWORN` en el catálogo cerrado de acciones (sin inventar actos) con su rótulo castellano «Juramento de Linaje sellado», manteniendo el aserto de rotulación cruzada (SPEC-03, TASK-08).
  * **Cubre:** `RF-03.1`, `RNF-06`, Artículo III.3
  * **Hecho cuando:** El asiento del juramento aparece en la Bitácora pública con su rótulo castellano, y el aserto que cruza catálogo ↔ vista sigue en verde.
  * **Verificación:** `scratch/test_lineage_oath_service.php` + batería de bitácora existente

- [ ] **Tarea 5.3: Batería de regresión cruzada y verificación manual (protocolo del §6.3 del plan)**
  * **Alcance:** Ejecutar la batería completa (los 8 arneses nuevos + los existentes de auth/clanes/bitácora que tocan `users`) y el protocolo manual de 7 puntos del plan (registro nuevo, retención por URL, modal completo, reingreso, doble pestaña, fallo de canon simulado, teclado y movimiento reducido).
  * **Cubre:** DoD completo de la SPEC-09 (sección 8)
  * **Hecho cuando:** 0 fallos en toda la batería, las 8 casillas del DoD de la spec pueden marcarse con evidencia, y la consola del navegador queda limpia durante ceremonia, fallo simulado y navegación bloqueada.
  * **Verificación:** salida de la batería + checklist manual del §6.3

---

## Notas de dependencia

- **1.1 → 1.2 → 1.3 → 2.x:** el repositorio necesita la columna; el servicio necesita el repositorio; el middleware necesita saber leer `lineage` de la cuenta.
- **2.5 puede ejecutarse en paralelo con 2.2–2.4** (solo toca el flujo de consagración), pero **2.6** depende de todos los de Fase 2.
- **3.1 → 3.2 → 3.3 → 4.x:** el cliente alimenta store/interceptor y ceremonia; las tareas 3.3 y 4.1 son independientes entre sí.
- **5.1 requiere 2.5** (backend ya tolera el registro sin linaje) y **5.3 cierra** con todo lo anterior.
- Las doctrinas del **Anexo A del plan** están [RATIFICADAS]: la Tarea 1.2 las inscribe textualmente.
