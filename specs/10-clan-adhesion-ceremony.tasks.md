# TASKS — SPEC-10: Ceremonia de Adhesión a Clanes del Propio Linaje

> **Fuente:** `specs/10-clan-adhesion-ceremony.spec.md` + `specs/10-clan-adhesion-ceremony.plan.md`
> **Reglas:** tareas de 20–30 minutos, en orden de dependencia estricta; ninguna tarea empieza antes de que su predecesora esté en verde. Cada tarea declara los RF que cubre y una condición «Hecho cuando» verificable por comando o comprobación reproducible (Artículo VI: sin spec aprobada y sin tarea cerrada, no hay código).
> **Convención:** «Cubre» cita los RF-x/RNF-x y casos límite de la SPEC-10; los arneses viven en `scratch/` siguiendo el estilo de las familias 07/08/09.

---

## Fase 1 — Persistencia y Migración

- [x] **Tarea 1.1 — Migración `10_clan_vestibule.sql`**
  * **Qué:** migración idempotente: preflight de deduplicación (duplicados legados de `(user_id, clan_id)` a la tabla espejo `clan_applications_archive`), `CREATE UNIQUE INDEX uq_clan_application_house` y `ALTER TABLE clan_applications ADD COLUMN verdict_seen_at TEXT NULL`; actualización de `database/schema.sql`.
  * **Cubre:** `RF-03.1` (clausura), `RF-03.4` (veredicto leído), `RNF-05` (PDO/SQL nativo).
  * **Hecho cuando:** re-ejecutar la migración sobre una base ya migrada no falla ni duplica, y un segundo INSERT de `(user_id, clan_id)` existente recibe la violación del índice único.

- [x] **Tarea 1.2 — Consultas nuevas de `ClanApplicationRepository`**
  * **Qué:** `findApplicationsByUser()` (todos los estados), `hasSealedHouse()`, `markVerdictSeen()` (solo sobre estados terminales propios) y `countUnreadVerdicts()` — PDO preparado, `camelCase`, comentarios en castellano.
  * **Cubre:** `RF-03.3` (cupo y clausura), `RF-03.4` (veredicto contemplado), `RF-03.8` (inventario).
  * **Hecho cuando:** las cuatro consultas responden contra una base sembrada y `countUnreadVerdicts()` ignora las peticiones `pending` (solo terminales con `verdict_seen_at` nulo).

## Fase 2 — Guardias y Ritos Backend

- [x] **Tarea 2.1 — Cuatro códigos canónicos en `ClanGovernanceException`**
  * **Qué:** fábricas nuevas `clanLineageMismatch()`, `clanLoyaltyBound()`, `adminLineageRequired()`, `applicationHouseClosed()` — código, HTTP 403, leyenda solemne del Anexo A del plan y `recoveryAction`.
  * **Cubre:** `RF-01.1` (Admin), `RF-02.3` (lealtad), `RF-03.1` (clausura), `RF-04.1` (linaje).
  * **Hecho cuando:** cada fábrica emite su código canónico, 403 y la leyenda exacta del Anexo A.

- [x] **Tarea 2.2 — Guardias de linaje, lealtad y Admin en el rito unificado**
  * **Qué:** `ClanService::applyToClan()` estrena `ADMIN_LINEAGE_REQUIRED` → `CLAN_LOYALTY_BOUND` (sustituye a `ALREADY_AFFILIATED` en esta vía, enmienda declarada en plan §5.3) → `CLAN_LINEAGE_MISMATCH`, antes de tocar persistencia; `foundClan()` estrena Admin + linaje.
  * **Cubre:** `RF-04.1`, `RF-02.3`, `RF-01.1`, `RF-04.2` (SPEC-09 RF-04.2 gana sustancia backend).
  * **Hecho cuando:** militante hacia otra casa → `CLAN_LOYALTY_BOUND`; linaje ajeno → `CLAN_LINEAGE_MISMATCH` en ingreso y fundación; Supremo sin linaje → `ADMIN_LINEAGE_REQUIRED`; `ALREADY_AFFILIATED` sigue canónico en `foundClan`.

- [x] **Tarea 2.3 — Molde de motivación y estampa de llegada**
  * **Qué:** validación `motivation` 20–500 en el camino `byApplication` (`INVALID_MOTIVATION`, 400); `receivedAt` opcional con tolerancia de ±30 s, sustituida por el instante del servidor si falta o desconfía (plan §3.3).
  * **Cubre:** `RF-03.1` (molde), caso límite 8 (desempate por llegada).
  * **Hecho cuando:** motivaciones de 19, 20 y 501 caracteres producen 400 solo en casas `byApplication` y `receivedAt` manipulada queda sustituida por la del servidor.

- [x] **Tarea 2.4 — `withdrawApplication()` (retirada del postulante)**
  * **Qué:** en `ClanService`: petición propia y `pending`, transacción, `status='cancelled'` + `resolved_at`, cupo liberado, asiento `CLAN_APPLICATION_WITHDRAWN`.
  * **Cubre:** `RF-03.3`, `RF-04.4`.
  * **Hecho cuando:** retirar libera el cupo de 3 y `hasSealedHouse()` pasa a `true` (la fila persiste y clausura la casa).

- [x] **Tarea 2.5 — `acknowledgeVerdict()` (veredicto contemplado)**
  * **Qué:** en `ClanService`: fija `verdict_seen_at` solo sobre petición terminal propia; idempotente (reenvío → éxito sin mutación).
  * **Cubre:** `RF-03.4`, `RF-01.1` (rótulo).
  * **Hecho cuando:** el primer acknowledge fija el instante y el segundo responde éxito sin cambiar la columna.

- [x] **Tarea 2.6 — Enmienda del dictamen (`resolveApplication`)**
  * **Qué:** rechazo exige `motive` de 20–500 (`400` si falta); inscripción del asiento `CLAN_APPLICATION_VERDICT` con identidad, estampa y motivo (Artículo III.3); la aprobación no exige motivo y anula residuales como hoy.
  * **Cubre:** `RF-03.4`, `RF-04.4`, `RF-04.5` (contrato compartido), hallazgo 23.
  * **Hecho cuando:** rechazar sin `motive` responde 400, con `motive` inscribe el asiento con su motivo, y la batería de deliberación de SPEC-08 permanece verde.

## Fase 3 — Servicio y Controlador del Vestíbulo

- [x] **Tarea 3.1 — DTOs del Vestíbulo**
  * **Qué:** `VestibuleStateDto`, `VestibuleClanDto` y `ClanPetitionDto` con las llaves `camelCase` exactas del contrato del plan §2.2 (`adeptState`, `aptitude`, `myHouse`, `gesture`, `vedadoLegend`, `verdictSeen`…).
  * **Cubre:** `RF-01.3`, `RF-01.7`, `RF-03.8`.
  * **Hecho cuando:** `jsonSerialize()` produce exactamente las llaves del contrato y un arnés de DTOs las coteja una a una.

- [x] **Tarea 3.2 — `ClanVestibuleService` (el sobre único)**
  * **Qué:** catálogo derivado de sesión (solo linaje jurado, solo `active`, sin parámetro de filtro), `myHouse` legada divergente desde `clan_members`, aptitud conjuntiva por instante con `ceilDays` réplica de `ClanMemberDto::convalescenceDaysRemaining`, `unreadVerdictsCount`, peticiones propias.
  * **Cubre:** `RF-01.2`, `RF-01.7`, `RF-03.5`, `RF-03.8`, `RNF-04` (una carga).
  * **Hecho cuando:** un solo método sirve el sobre completo; un linajado sin casas recibe el estado vacío; un legado divergente recibe su `myHouse` con `isLegacyDivergent: true`.

- [x] **Tarea 3.3 — Actos de adhesión en la Bitácora**
  * **Qué:** cinco actos en el catálogo cerrado de `AuditEntry` (`CLAN_MEMBER_JOINED`, `CLAN_APPLICATION_SUBMITTED`, `CLAN_APPLICATION_WITHDRAWN`, `CLAN_APPLICATION_RESIDUALS_ANNULLED`, `CLAN_APPLICATION_VERDICT`) con rótulos castellanos; inscripciones en ingreso, remisión, retirada y cada residual anulada.
  * **Cubre:** `RF-04.4` (reparto por actor), `RF-03.7` (asientos de residuales), `RNF-06`.
  * **Hecho cuando:** el aserto de catálogo cerrado de SPEC-03 pasa con los cinco actos y su rotulación, y cada acto queda inscrito por su actor.

- [x] **Tarea 3.4 — `VestibuleController` y registro de rutas**
  * **Qué:** `show()`, `withdraw()`, `acknowledgeVerdict()`, `unreadCount()`; rutas registradas en `public/index.php` detrás de `AuthMiddleware → RbacMiddleware → LineageOathMiddleware`.
  * **Cubre:** `RF-01.1`, `RF-03.3`, `RF-03.4`, `RNF-04`.
  * **Hecho cuando:** los cuatro endpoints responden según contrato con la pila real de middleware y el peregrino sin linaje jamás los alcanza (`LINEAGE_OATH_REQUIRED` de SPEC-09 precede).

## Fase 4 — Cliente y Esqueleto Frontend

- [x] **Tarea 4.1 — `vestibuleClient.js`**
  * **Qué:** cliente `fetch` nativo (`credentials: 'same-origin'`) de los cuatro endpoints, con mapeo de 200/400/403/404/409 a veredictos de la vista portando las leyendas canónicas.
  * **Cubre:** `RF-02.2`, `RF-03.2`, `RNF-05`.
  * **Hecho cuando:** cada código de error se traduce a la leyenda del Anexo A y ninguna respuesta interna expone trazas.

- [x] **Tarea 4.2 — Ruta, doble vía y rótulo de dictámenes**
  * **Qué:** `#/vestibulo → 'vestibule'` en `main.js`; rótulo «Hermandades» en `navbarComponent`; llamamiento en `lineageHallView`; distintivo «Tienes dictámenes a la espera» alimentado por `unread-count` y apagado tras acknowledge.
  * **Cubre:** `RF-01.1`, `RF-01.6`, `RF-03.4`.
  * **Hecho cuando:** ambas vías conducen al mismo Vestíbulo, el rótulo luce solo con veredictos sin leer y se apaga al contemplarlos sin bloquear navegación alguna.

## Fase 5 — Componentes y Vista

- [x] **Tarea 5.1 — `vestibuleClanCardComponent`**
  * **Qué:** tarjeta solemne: lema, Sello Rúnico (`runeSealComponent`, metal y forma por estado), «X de 30», régimen rotulado en castellano, corona del Regente (estilos del kit de SPEC-07, sin ad hoc), estado del adepto y UN gesto o su leyenda vedada; estado vacío con invitación discreta; enfocable con Enter/espaciadora.
  * **Cubre:** `RF-01.2`, `RF-01.3`, `RF-01.4`, `RF-03.5`, `RNF-03`.
  * **Hecho cuando:** los cinco estados de tarjeta (`none`, `join`, `petition`, `pending`, `own`) se pintan desde el DTO y el estado vacío muestra «Ninguna hermandad ruega aún tu linaje» con la invitación discreta.

- [x] **Tarea 5.2 — `admissionModalComponent`**
  * **Qué:** `<dialog>` nativo: nombre de la casa, lealtad indivisible y advertencia de convalecencia futura EN EL CUERPO, confirmación explícita, foco atrapado y devuelto, Escape = descarte seguro, región viva.
  * **Cubre:** `RF-02.1`, `RNF-03`.
  * **Hecho cuando:** el descarte no muta nada, el foco vuelve a la tarjeta y las advertencias son visibles sin letra menuda ni tooltip.

- [x] **Tarea 5.3 — `petitionComposerComponent`**
  * **Qué:** `textarea` del kit de controles (SPEC-02 RF-08) con contador vivo 0/500, mínimo 20 para remitir y leyendas solemnes del molde (Anexo A 6).
  * **Cubre:** `RF-03.1`, `RNF-03`.
  * **Hecho cuando:** remitir exige 20–500 caracteres, el contador se ve mientras se escribe y ninguna regla de estilo filtra el texto (solo el molde).

- [x] **Tarea 5.4 — `petitionInventoryComponent`**
  * **Qué:** apéndice «Tus peticiones pendientes: N de 3» con estado de cada solicitud, retirada directa y veredictos sin leer marcados.
  * **Cubre:** `RF-03.8`, `RF-03.3`, `RF-03.4`.
  * **Hecho cuando:** retirar desde la lista actualiza tarjeta e inventario sin recarga y el límite de 3 y la clausura son visibles.

- [x] **Tarea 5.5 — `vestibuleView` (orquestador)**
  * **Qué:** una carga del sobre, montaje de componentes, emisión de los eventos `vestibule:*` del plan §4, aviso «Las hermandades no responden» con reintento, acknowledge de veredictos contemplados, región viva de veredictos.
  * **Cubre:** `RF-01.3`, `RF-02.2`, `RF-02.3`, `RF-03.4`, `RNF-03`, `RNF-04`.
  * **Hecho cuando:** el flujo feliz emite los eventos del plan en orden, el fallo de catálogo mantiene la ceremonia operativa con reintento y los veredictos vistos apagan el rótulo del acceso.

## Fase 6 — Vestimenta CSS

- [x] **Tarea 6.1 — `vestibule.css` (Velo Arcano del Vestíbulo)**
  * **Qué:** hoja nueva solo con tokens de `tokens.css` (cero literales de color), consumo del Kit de Controles (`controls.css`, SPEC-02 RF-08), registro en `components.css`, cobertura total de clases emitidas.
  * **Cubre:** `RNF-01`, `RNF-03`, `RNF-05`.
  * **Hecho cuando:** `node scratch/test_css_coverage.mjs` pasa sin huérfanos y un grep de la hoja no halla literales de color.

## Fase 7 — Arneses Backend

- [ ] **Tarea 7.1 — `scratch/test_vestibule_service.php`**
  * **Cubre:** `RF-01.1`, `RF-01.2`, `RF-01.7`, `RF-03.5`, `RF-03.8`.
  * **Hecho cuando:** en verde: catálogo solo del linaje jurado y `active`; `myHouse` legada; aptitud por instante; días con alza; `unreadVerdictsCount`; Supremo sin linaje → 403 `ADMIN_LINEAGE_REQUIRED`; peregrino jamás servido.

- [ ] **Tarea 7.2 — `scratch/test_clan_admission_guards.php`**
  * **Cubre:** `RF-04.1`, `RF-02.3`, `RF-01.1`, `RF-04.2`.
  * **Hecho cuando:** en verde: los tres guardias en ambas vías, `ALREADY_AFFILIATED` intacto en fundación, convalecencia con su leyenda, y jamás `CLAN_LINEAGE_MISMATCH` por lectura de catálogo.

- [ ] **Tarea 7.3 — `scratch/test_clan_application_closure.php`**
  * **Cubre:** `RF-03.1`, `RF-03.3`, caso límite 13.
  * **Hecho cuando:** en verde: rechazo y retirada clausuran sin consumir cupo; re-postulación → 403 `APPLICATION_HOUSE_CLOSED`; bordes del molde 19/20/501.

- [ ] **Tarea 7.4 — `scratch/test_clan_admission_race.php`**
  * **Cubre:** `RF-02.2`, `RF-02.3`, casos límite 1, 2, 5, 6, 8.
  * **Hecho cuando:** en verde: última vacante con desempate por estampa de llegada más antigua; casa que muta jamás convierte el gesto; doble envío idempotente; retirada y dictamen concurrentes con un solo desenlace.

- [ ] **Tarea 7.5 — `scratch/test_clan_vestibule_audit.php`**
  * **Cubre:** `RF-04.4`, `RF-03.7`, `RF-04.5`, `RNF-06`, hallazgo 23.
  * **Hecho cuando:** en verde: reparto por actor verificado (los cinco actos, cada uno por su actor), un asiento por residual anulada, dictamen con motivo, y rechazo sin `motive` → 400.

- [ ] **Tarea 7.6 — `scratch/test_vestibule_migration.php`**
  * **Cubre:** `RF-03.1`, `RF-03.4`, `RNF-05`.
  * **Hecho cuando:** en verde: migración idempotente al re-ejecutar, deduplicación a archivo espejo, índice único presente, acknowledge idempotente.

## Fase 8 — Arneses Frontend

- [ ] **Tarea 8.1 — `scratch/test_vestibule_view.mjs` y `scratch/test_vestibule_card_states.mjs`**
  * **Cubre:** `RF-01.2`, `RF-01.3`, `RF-01.4`, `RF-01.5`, `RF-03.5`.
  * **Hecho cuando:** en verde: render completo de tarjeta (sello, plenitud, régimen, corona), doble vía, estado vacío, fallo de catálogo con reintento, y los cinco estados de tarjeta pintados desde el DTO con la contemplación jamás bloqueada.

- [ ] **Tarea 8.2 — `scratch/test_admission_modal.mjs` y `scratch/test_petition_composer.mjs`**
  * **Cubre:** `RF-02.1`, `RF-03.1`, `RNF-03`.
  * **Hecho cuando:** en verde: advertencias en el cuerpo del modal, descarte sin mutación, foco atrapado y devuelto, Escape, ARIA, `prefers-reduced-motion`, molde con contador vivo.

- [ ] **Tarea 8.3 — `scratch/test_petition_inventory.mjs` y `scratch/test_vestibule_client.mjs`**
  * **Cubre:** `RF-03.3`, `RF-03.4`, `RF-03.8`, `RF-02.2`, `RF-03.2`.
  * **Hecho cuando:** en verde: inventario con retirada directa y veredictos sin leer, rótulo que se apaga al contemplar, y mapeo íntegro de códigos HTTP a leyendas canónicas.

## Fase 9 — Cierre y Verificación

- [ ] **Tarea 9.1 — Regresión cruzada de la batería**
  * **Qué:** realineamiento de los arneses de SPEC-07 que asertaban `ALREADY_AFFILIATED` sobre `applyToClan` (enmienda plan §5.3) y ejecución íntegra de la batería de clanes/dominio + cobertura CSS.
  * **Cubre:** `RF-04.2`, `RNF-05`.
  * **Hecho cuando:** la batería de clanes íntegra y `test_css_coverage.mjs` pasan en verde tras el realineamiento, sin regresiones en deliberación (SPEC-08) ni dominio.

- [ ] **Tarea 9.2 — Verificación manual en navegador**
  * **Qué:** los 8 pasos del plan §6.3 contra el servidor de demo (doble vía, ritos, dictamen, vedados, retención del peregrino, Supremo sin linaje, teclado, movimiento reducido).
  * **Cubre:** `RNF-01`, `RNF-03`, `RNF-04`, casos límite 9, 10, 12.
  * **Hecho cuando:** el recorrido completo queda documentado con evidencia y la consola del navegador queda limpia.

- [ ] **Tarea 9.3 — Arnés de cierre formal `scratch/test_spec10_closure.php`**
  * **Qué:** al estilo de los cierres 07/08: cruce spec↔plan↔tasks, guardias del Dogma Vanilla (`declare(strict_types=1)`, parameter binding, cero CDNs, cero literales de color), Artículos II–V, batería íntegra y rendición de cuentas de los 10 criterios de finalización de la SPEC-10 con la suite que ejercita cada uno.
  * **Cubre:** todos los RF/RNF (certificación de cierre).
  * **Hecho cuando:** el arnés declara «SPEC-10 queda formalmente cerrada» con cero suites en rojo y los criterios de la Sección 8 de la spec con evidencia nombrada.
