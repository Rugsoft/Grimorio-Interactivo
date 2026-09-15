# TASKS-07: Tareas de Implementación — Sistema de Clanes, Linajes y Dominio Semanal del Grimorio

> **Especificación:** [`specs/07-clans-lineages.spec.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/specs/07-clans-lineages.spec.md)  
> **Plan Técnico:** [`specs/07-clans-lineages.plan.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/specs/07-clans-lineages.plan.md)  
> **Constitución:** [`constitution.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/constitution.md) | **Directrices:** [`AGENTS.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/AGENTS.md)  
> **Reglas:** Tareas atómicas de 20-30 minutos, ordenadas por dependencia lógica estricta, con trazabilidad exhaustiva a RF/RNF y criterio de aceptación verificable («Hecho cuando: ...»).

---

## Fase 1: Esquema de Base de Datos Relacional SQLite y Repositorios PDO

- [x] **Tarea 1.1: Migración DDL de tablas y claves foráneas (`sql/07_clans_lineages_schema.sql`)**
  * **Alcance:** Crear el script DDL con las tablas `clans`, `clan_members`, `clan_applications`, `weekly_cycles` y `daily_simulator_tracker`, definiendo tipos estrictos, claves foráneas a `users(id)`, índices para rankings semanales/históricos e índice único condicional para evitar múltiples afiliaciones activas simultáneas (`WHERE left_at IS NULL`).
  * **Cubre:** `RF-01.1`, `RF-01.4`, `RF-01.5`, `RF-03.2`, `RF-04.1`, `RF-05.4`, `RNF-05`, `Artículo V`
  * **Hecho cuando:** La ejecución del script SQL sobre SQLite crea las 5 tablas sin errores de sintaxis y los índices de unicidad impiden insertar dos membresías activas simultáneas para un mismo `user_id`.

- [x] **Tarea 1.2: Repositorio de Clanes (`src/Repositories/ClanRepository.php`)**
  * **Alcance:** Implementar `src/Repositories/ClanRepository.php` con `declare(strict_types=1);` y PDO preparado, dotándolo de los métodos: `createClan()`, `findById()`, `findByName()`, `isNameAvailable()`, `updateMottoAndHeraldry()`, `updateAdmissionMode()`, `updatePatriarch()`, `setStatusArchived()`, `addWeeklyAndHistoricalPoints()`, `resetAllWeeklyPointsToZero()`, `findActiveOrderedByWeeklyPointsDesc()` y `findActiveOrderedByHistoricalPointsDesc()`.
  * **Cubre:** `RF-01.2`, `RF-01.3`, `RF-01.5`, `RF-03.1`, `RF-04.3`, `RF-05.3`, `RF-05.4`, `RNF-01`
  * **Hecho cuando:** Todos los métodos de consulta y actualización ejecutan sentencias PDO preparadas, protegen nombres reservados de clanes disueltos y retornan arrays tipados o null según corresponda.

- [x] **Tarea 1.3: Repositorio de Membresías y Convalecencia (`src/Repositories/ClanMemberRepository.php`)**
  * **Alcance:** Implementar `src/Repositories/ClanMemberRepository.php` con métodos: `addMember()`, `removeMember(userId, clanId, convalescenceExpiresAt)`, `findActiveMembership(userId)`, `findMembersByClan(clanId)`, `countActiveMembers(clanId)`, `isUserInConvalescence(userId, nowUtc)`, `findPastMembershipsSince(userId, cutoffDateUtc)` y `setRole(userId, clanId, role)`.
  * **Cubre:** `RF-01.1`, `RF-01.4`, `RF-01.6`, `RF-01.8`, `RF-01.9`, `RNF-01`, `Artículo III`
  * **Hecho cuando:** Invocar `isUserInConvalescence()` devuelve `true` si la fecha de expiración es futura, y `countActiveMembers()` refleja con precisión el cupo ocupado del clan.

- [x] **Tarea 1.4: Repositorios de Solicitudes y Ciclos Semanales (`ClanApplicationRepository.php` y `WeeklyCycleRepository.php`)**
  * **Alcance:** Implementar `src/Repositories/ClanApplicationRepository.php` (creación de solicitud, conteo de solicitudes pendientes activas con tope de 3, resolución a `approved`/`rejected` y cancelación de otras pendientes al ingresar) y `src/Repositories/WeeklyCycleRepository.php` (registro histórico del ciclo dominical cerrado y consulta del Clan Regente vigente).
  * **Cubre:** `RF-01.5`, `RF-04.2`, `RF-04.4`, `RNF-01`, `RNF-04`
  * **Hecho cuando:** Un usuario no puede registrar una 4ª solicitud si ya tiene 3 pendientes, y al aprobarse una solicitud, las demás del mismo usuario se cancelan automáticamente en la misma transacción.

---

## Fase 2: DTOs Inmutables y Servicios de Dominio Backend (PHP 8.2+)

- [x] **Tarea 2.1: DTOs inmutables del dominio de hermandades y linajes (`src/Dto/*`)**
  * **Alcance:** Crear `src/Dto/ClanDto.php`, `src/Dto/ClanMemberDto.php`, `src/Dto/LineageDto.php`, `src/Dto/ClanApplicationDto.php`, `src/Dto/WeeklyCycleDto.php` y `src/Dto/DominionAwardDto.php` con `declare(strict_types=1);`, encapsulando atributos tipados en inglés `camelCase`, inmutabilidad con `readonly` y serialización JSON nativa.
  * **Cubre:** `RF-01.2`, `RF-01.3`, `RF-02.1`, `RF-03.4`, `RF-04.4`, `RNF-05`, `Artículo V`
  * **Hecho cuando:** Todos los DTOs se instancian con tipificación estricta y `json_encode()` genera el formato de contrato REST estipulado en el plan.

- [x] **Tarea 2.2: Servicio de Sinergia de Linajes y Redondeo Aritmético (`LineageSynergyService.php`)**
  * **Alcance:** Implementar `src/Services/LineageSynergyService.php` conteniendo el mapa inmutable de los 8 Linajes Canónicos (`primordialFlame`, `celestialTides`, `eternalTempest`, `worldRoots`, `dawnWinds`, `solarCrown`, `abyssalShadows`, `aetherWeavers`), el cálculo del $+25\%$ de sinergia temática y la aplicación de redondeo aritmético estándar `round(basePoints * 1.25)` sin alterar costes de maná en la forja.
  * **Cubre:** `RF-02.1`, `RF-02.3`, `RF-03.4`, `RNF-01`, `Artículo II`, `Artículo V`
  * **Hecho cuando:** La función `applySynergy(5, 'primordialFlame', 'fire')` retorna `6` PDA ($6.25 \rightarrow 6$) y `applySynergy(10, 'eternalTempest', 'lightning')` retorna `13` PDA ($12.5 \rightarrow 13$), mientras que elementos sin coincidencia retornan el valor base sin alteración.

- [x] **Tarea 2.3: Validador de Ética de Clanes y Veto Constitucional (`ClanEthicsValidator.php`)**
  * **Alcance:** Implementar `src/Services/ClanEthicsValidator.php` evaluando si un Maestro de la Torre (`role = 'master'`) puede deliberar o firmar un conjuro, comprobando: 1) Que el Maestro no sea miembro activo del clan del conjuro, y 2) Que no haya pertenecido a dicho clan en los últimos 30 días naturales según el historial de membresía.
  * **Cubre:** `RF-01.8`, `RNF-02`, `RNF-04`, `Artículo III`
  * **Hecho cuando:** Un Maestro perteneciente al clan del conjuro o que haya salido de él hace 29 días es rechazado con excepción de conflicto de interés, mientras que uno retirado hace 31 días es admitido.

- [x] **Tarea 2.4: Servicio de Gestión y Gobierno de Clanes (`ClanService.php`)**
  * **Alcance:** Implementar `src/Services/ClanService.php` integrando las reglas de: fundación (rango `editor`+, nombre único no disuelto), cupo estricto de 30 miembros, admisión (`open` inmediata o `byApplication` con máx. 3 pendientes), renuncias/expulsiones imponiendo 14 días de convalecencia en perfil, y evaluación de sucesión de Patriarca tras 45 días de inactividad de oficio al adepto más antiguo.
  * **Cubre:** `RF-01.1` a `RF-01.7`, `RF-01.9`, `RF-05.3`, `RF-05.4`, `RNF-01`, `RNF-04`
  * **Hecho cuando:** Se bloquea el ingreso al miembro número 31, se bloquea a postulantes en convalecencia de 14 días y se transfiere la corona al adepto más antiguo si el Patriarca supera los 45 días de inactividad.

- [x] **Tarea 2.5: Servicio de Dominio Semanal y Cierre Dominical (`WeeklyDominionService.php`)**
  * **Alcance:** Implementar `src/Services/WeeklyDominionService.php` con la liquidación de PDA: por Círculo ($100 + C \times 20$), combos de simulador (+10 PDA con techo de 50 PDA diarios reiniciado a las 00:00:00 UTC), favoritos (+5 PDA), atribución de conjuros en moderación al clan originario, y el cierre dominical a las 23:59:59 UTC con resolución de desempate determinista (1º conjuros validados en semana, 2º timestamp), reseteo de puntos semanales a 0 y archivo histórico.
  * **Cubre:** `RF-03.1`, `RF-03.2`, `RF-03.3`, `RF-03.5`, `RF-04.1` a `RF-04.5`, `RF-05.1`, `RNF-01`, `RNF-02`
  * **Hecho cuando:** El cierre semanal dirime empates favoreciendo primero al clan con más conjuros validados en la semana, resetea `weekly_points` a 0 para todos los clanes y añade los puntos al total histórico.

---

## Fase 3: Controladores REST y Contratos de Endpoints (PHP 8.2+)

- [x] **Tarea 3.1: Controlador de Linajes Canónicos (`LineageController.php`)**
  * **Alcance:** Implementar `src/Controllers/LineageController.php` para la ruta `GET /api/v1/lineages`, exponiendo el array inmutable de los 8 linajes con identificadores en inglés `camelCase`, títulos ceremoniales en castellano, elementos rectores y códigos heráldicos.
  * **Cubre:** `RF-02.1`, `RF-02.2`, `RNF-03`, `RNF-05`, `Artículo V`
  * **Hecho cuando:** La petición `GET /api/v1/lineages` responde con HTTP 200 y una lista JSON de exactamente 8 linajes canónicos.

- [x] **Tarea 3.2: Controlador de Clanes, Gobernanza y Postulaciones (`ClanController.php`)**
  * **Alcance:** Implementar `src/Controllers/ClanController.php` gestionando los endpoints REST: `POST /api/v1/clans` (fundación), `GET /api/v1/clans` (catálogo y filtros), `GET /api/v1/clans/{id}` (detalle), `PATCH /api/v1/clans/{id}` (lema, blasón, régimen), `POST /api/v1/clans/{id}/applications` (postulación), `POST /api/v1/clans/{id}/applications/{appId}/resolve` (aprobar/rechazar), `POST /api/v1/clans/{id}/leave` (renuncia), `POST /api/v1/clans/{id}/expel/{userId}` (expulsión) y `POST /api/v1/clans/{id}/transfer-leadership` (corona).
  * **Cubre:** `RF-01.1` a `RF-01.7`, `RF-05.3`, `RF-05.4`, `RNF-01`, `RNF-04`
  * **Hecho cuando:** Todos los endpoints responden con los códigos de estado HTTP estipulados en el plan (200, 201, 400, 403, 404, 409, 422) y aplican las validaciones de permisos y convalecencia.

- [x] **Tarea 3.3: Controlador de Dominio Semanal y Clasificaciones (`DominionController.php`)**
  * **Alcance:** Implementar `src/Controllers/DominionController.php` para `GET /api/v1/dominion/leaderboard` (clasificación en vivo, acumulado histórico, Clan Regente y registro cronológico) y `POST /api/v1/dominion/cron-cycle-close` (cierre determinista protegido por cabecera secreta).
  * **Cubre:** `RF-04.1` a `RF-04.4`, `RF-06.1`, `RF-06.2`, `RNF-01`
  * **Hecho cuando:** La ruta del leaderboard devuelve el podio semanal ordenado descendentemente por PDA y la ruta de cierre dominical ejecuta la proclamación y reseteo sin errores.

---

## Fase 4: Suite de Pruebas Automatizadas Backend CLI

- [x] **Tarea 4.1: Suite automatizada de pruebas CLI (`scratch/test_clans_dominion.php`)**
  * **Alcance:** Crear `scratch/test_clans_dominion.php` ejecutando en SQLite en memoria:
    1. Cálculo de PDA por Círculo ($100 + C \times 20$).
    2. Sinergia del $+25\%$ con redondeo aritmético `round()` (casos 6.25 $\rightarrow$ 6 y 12.5 $\rightarrow$ 13).
    3. Bloqueo al miembro 31 (límite de 30 adeptos).
    4. Bloqueo a la 4ª solicitud pendiente de un usuario.
    5. Bloqueo de 14 días naturales de convalecencia ante intentos de ingreso o fundación.
    6. Veto constitucional de 30 días a Maestros evaluadores (Artículo III).
    7. Reinicio de los 50 PDA del simulador a las 00:00:00 UTC.
    8. Desempate semanal determinista (1º conjuros validados, 2º timestamp).
    9. Sucesión automática por 45 días de inactividad del Patriarca.
    10. Bloqueo de usurpación del nombre canónico de un clan archivado.
  * **Cubre:** `RF-01.1` a `RF-05.4`, `RNF-01`, `RNF-02`, `Plan Sec. 6.1`
  * **Hecho cuando:** La invocación `php scratch/test_clans_dominion.php` supera el 100% de los 10 bloques de prueba con código de salida 0 y reporte exhaustivo en consola.

---

## Fase 5: Clientes de API y Componentes UI Vanilla ES Modules

- [x] **Tarea 5.1: Clientes nativos de API (`clanClient.js` y `dominionClient.js`)**
  * **Alcance:** Desarrollar `public/assets/js/api/clanClient.js` y `public/assets/js/api/dominionClient.js` utilizando `fetch` nativo sin librerías, con manejo de excepciones y traducción a mensajes ceremoniales en castellano.
  * **Cubre:** `RF-01.1` a `RF-01.6`, `RF-04.1`, `RF-06.1`, `RNF-03`, `RNF-05`, `Artículo I`
  * **Hecho cuando:** Los clientes realizan las llamadas asíncronas a los endpoints backend gestionando tokens Bearer y propagando respuestas estructuradas.

- [x] **Tarea 5.2: Componente del Clan Regente en Portal (`clanBannerComponent.js`)**
  * **Alcance:** Desarrollar `public/assets/js/components/clanBannerComponent.js` para renderizar en el Gran Portal (SPEC-01) el blasón del Clan Regente semanal, lema heráldico, linaje elemental y corona dorada ceremonial.
  * **Cubre:** `RF-04.4`, `RF-02.2`, `RF-02.4`, `RNF-03`
  * **Hecho cuando:** La cabecera del portal muestra de forma destacada el escudo y lema del Clan Soberano de la semana actual con atributos ARIA accesibles.
  * **Reforja de heráldica (Ronda de Diseño):** el blasón dejó de imprimir el identificador técnico y se **forja** como Sello Rúnico (SPEC-02 RF-07) con el estado del reinante: oro vivo y sello de cera a las doce (`RF-02.4`).

- [x] **Tarea 5.3: Componente de aviso de Convalecencia Arcana (`convalescenceBannerComponent.js`)**
  * **Alcance:** Desarrollar `public/assets/js/components/convalescenceBannerComponent.js` para incrustar en el perfil del mago el indicador de descanso obligatorio (*«En Convalecencia Arcana: restan X días de meditación»*), deshabilitando los botones de ingreso o fundación mientras esté activo.
  * **Cubre:** `RF-01.6`, `RF-01.7`, `RNF-03`
  * **Hecho cuando:** Un usuario en convalecencia ve en su perfil el contador de días restantes y las opciones de afiliación a nuevos clanes aparecen deshabilitadas con la leyenda ceremonial.

---

## Fase 6: Vistas de Inmersión, Panel del Patriarca y Salón de los Linajes

- [x] **Tarea 6.1: Hojas de estilos ceremoniales CSS3 (`clan-heraldry.css` y `lineage-hall.css`)**
  * **Alcance:** Crear `public/assets/css/components/clan-heraldry.css` y `public/assets/css/components/lineage-hall.css` con variables CSS3, marcos heráldicos para los 8 linajes, animaciones de brillo dorado para el Clan Regente y la clase `.spell-card-regent-border` para el ribete dorado de los conjuros del clan soberano.
  * **Cubre:** `RF-02.2`, `RF-04.4`, `RNF-03`
  * **Hecho cuando:** Los componentes heráldicos se adaptan responsive a móvil y escritorio, y los conjuros del Clan Regente lucen el ribete dorado brillante ceremonial.

- [x] **Tarea 6.2: Componente del Panel de Gestión del Patriarca (`clanManagementComponent.js`)**
  * **Alcance:** Desarrollar `public/assets/js/components/clanManagementComponent.js` permitiendo al Patriarca: modificar lema y blasón, conmutar régimen de admisión (`open` / `byApplication`), visualizar el indicador de ocupación (x/30), aceptar o rechazar solicitudes pendientes, expulsar adeptos y transferir la corona de liderazgo.
  * **Cubre:** `RF-01.3`, `RF-01.4`, `RF-01.5`, `RF-01.9`
  * **Hecho cuando:** El Patriarca puede alternar el régimen de admisión, admitir una solicitud actualizando el cupo a la vista y transferir el liderazgo con diálogo solemne de confirmación.

- [x] **Tarea 6.3: Componente y Vista del Salón de los Linajes (`lineageHallComponent.js` y `lineageHallView.js`)**
  * **Alcance:** Desarrollar `public/assets/js/components/lineageHallComponent.js` y `public/assets/js/views/lineageHallView.js` presentando: podio ceremonial en vivo de la semana en curso, conmutador de filtros por los 8 linajes elementales, tabla del Prestigio Histórico perpetuo y el Libro Mayor de Campeones Pasados.
  * **Cubre:** `RF-04.4`, `RF-06.1`, `RF-06.2`, `RF-02.4`, `RNF-03`
  * **Reforja de heráldica (Ronda de Diseño):** los blasones del podio y los glifos de los ocho filtros se forjan como Sello Rúnico (SPEC-02 RF-07): la clave del linaje viaja en la huella del anillo y en el nombre accesible, jamás impresa (`RF-02.4`).
  * **Hecho cuando:** El usuario puede alternar entre clasificación semanal e histórica, y filtrar las hermandades pulsando en el icono rúnico de cualquiera de los 8 linajes.

- [x] **Tarea 6.4: Vista de Detalle de Clan y Legado Ancestral (`clanView.js`)**
  * **Alcance:** Desarrollar `public/assets/js/views/clanView.js` que visualice el blasón del clan, lema, Patriarca, lista de miembros activos, botón de unirse/postularse (sujeto a cupo de 30 y convalecencia), botón de renuncia para adeptos, y catálogo de conjuros sellados bajo su sello (distinguiendo la «Herencia Ancestral» si el clan está archivado).
  * **Cubre:** `RF-01.2`, `RF-01.4`, `RF-01.5`, `RF-05.1`, `RF-05.2`, `RF-05.3`, `RF-05.4`, `RF-02.4`
  * **Reforja de heráldica (Ronda de Diseño):** el blasón de la ficha se forja como Sello Rúnico (SPEC-02 RF-07) y declara el estado de la casa por metal **y** forma: oro antiguo la viva, bronce con anillo roto la disuelta (`RF-02.4`).
  * **Hecho cuando:** La vista muestra los conjuros validados del clan independientemente de si los autores siguen en la hermandad, y marca con el sello de «Herencia Ancestral» si el clan está en estado `archived`.

---

## Fase 7: Verificación Integral, Cierre Dominical y Trazabilidad End-to-End

- [x] **Tarea 7.1: Integración cruzada con Portal, Simulador y Tomo de Conjuros**
  * **Alcance:** Conectar la acreditación de 10 PDA en combos del simulador (SPEC-05/06) respetando el techo de 50 PDA diarios a las 00:00:00 UTC, asociar los conjuros validados creados por miembros a su clan (SPEC-04) y proyectar el ribete dorado ceremonial en las fichas de conjuros del Clan Regente en el Tomo.
  * **Cubre:** `RF-03.1`, `RF-03.2`, `RF-03.5`, `RF-04.4`, `RNF-01`, `RNF-02`
  * **Hecho cuando:** La ejecución de un combo en el simulador acredita puntos al clan del usuario reflejándose en el ranking semanal en vivo y deteniéndose al alcanzar el tope diario de 50 PDA.

- [x] **Tarea 7.2: Verificación completa de suite de pruebas y cierre de especificación**
  * **Alcance:** Ejecutar todas las pruebas unitarias y de integración backend (`test_clans_dominion.php`), comprobar la ausencia total de librerías externas o dependencias npm, validar la tipificación estricta en PHP 8.2+ y verificar la conformidad con los Artículos I, II, III, IV y V de la Constitución.
  * **Cubre:** `RF-01.1` a `RF-06.2`, `RNF-01` a `RNF-05`, `Criterios de Finalización de SPEC-07`
  * **Hecho cuando:** Todos los asertos automatizados pasan con éxito y se confirma que la tríada canónica (`spec.md`, `plan.md`, `tasks.md`) de SPEC-07 se encuentra totalmente alineada y lista para la ejecución.
