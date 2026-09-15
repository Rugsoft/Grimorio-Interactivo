# TASKS-08: Tareas de Implementación — Sistema de Moderación Solemne en Dos Pasos y Consecución de Firmas

> **Especificación:** [`specs/08-moderation-two-step.spec.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/specs/08-moderation-two-step.spec.md)  
> **Plan Técnico:** [`specs/08-moderation-two-step.plan.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/specs/08-moderation-two-step.plan.md)  
> **Constitución:** [`constitution.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/constitution.md) | **Directrices:** [`AGENTS.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/AGENTS.md)  
> **Reglas:** Tareas atómicas de 20-30 minutos, ordenadas por dependencia lógica estricta, con trazabilidad exhaustiva a RF/RNF y criterio de aceptación verificable («Hecho cuando: ...»).

---

## Fase 1: Esquema de Base de Datos Relacional SQLite y Repositorios PDO

- [x] **Tarea 1.1: Migración DDL de tablas y claves foráneas (`sql/08_moderation_schema.sql`)**
  * **Alcance:** Crear el script SQL con las tablas `spell_reviews`, `master_signatures`, `objection_verdicts` y `sovereign_decrees`, definiendo tipos estrictos, claves foráneas a `spells(id)`, `users(id)` y `clans(id)`, índices para la cola de deliberación e índice único parcial para impedir firmas duplicadas activas de un mismo Maestro sobre el mismo conjuro (`WHERE is_revoked = 0`).
  * **Cubre:** `RF-01.1`, `RF-02.1`, `RF-02.5`, `RF-04.4`, `RNF-02`, `RNF-05`, `Artículo V`
  * **Hecho cuando:** La ejecución del script SQL sobre SQLite crea las 4 tablas con sus restricciones e índices sin errores de sintaxis, y un intento de insertar dos firmas no revocadas del mismo usuario sobre un mismo conjuro lanza un error de unicidad.
  * **Verificación:** `scratch/test_moderation_schema.php` — 72 asertos, 0 fallos (nueve fases: idempotencia, contrato de columnas, tipos estrictos, índices, el criterio de unicidad, retractación y pluralidad, integridad referencial con cascada y anti-deriva del DDL).

- [x] **Tarea 1.2: Repositorio de Revisiones de Conjuros (`src/Repositories/SpellReviewRepository.php`)**
  * **Alcance:** Implementar `SpellReviewRepository.php` con `declare(strict_types=1);` y PDO preparado, dotándolo de los métodos: `createOrUpdateReview()`, `findById()`, `findBySpellId()`, `findAndLockById()`, `updateStatus()`, `updateSignaturesCount()`, `countActiveReviewsByAuthor(authorId)`, `findQueueItems(filters)` y `findStaleReviews(thresholdDays)`.
  * **Cubre:** `RF-01.1`, `RF-01.2`, `RF-01.5`, `RF-01.6`, `RF-05.4`, `RNF-01`, `RNF-02`
  * **Hecho cuando:** Todos los métodos ejecutan sentencias PDO preparadas, `countActiveReviewsByAuthor()` contabiliza exactamente los conjuros en `experimental` del usuario, y `findAndLockById()` recupera la fila para bloqueo transaccional.
  * **Verificación:** `scratch/test_moderation_review_repository.php` — 81 asertos, 0 fallos (diez fases: superficie y tipado estricto, inscripción idempotente 1:1, lecturas, transiciones con marca temporal, contador de firmas, cupo del autor, bloqueo transaccional real con segundo escritor rechazado, cola del Atrio con filtros y paginación, letargo de 90 días con reloj inyectable y `parameter binding` ante entrada hostil).

- [x] **Tarea 1.3: Repositorios de Firmas y Objeciones (`MasterSignatureRepository.php` y `ObjectionVerdictRepository.php`)**
  * **Alcance:** Implementar `MasterSignatureRepository.php` (métodos `insertSignature()`, `findActiveSignatures(spellId)`, `findActiveSignaturesByMaster(masterId)`, `revokeSignature(id, reason)`) y `ObjectionVerdictRepository.php` (métodos `insertVerdict(spellId, masterId, reason)` y `findLatestVerdictBySpell(spellId)`).
  * **Cubre:** `RF-02.1`, `RF-02.4`, `RF-02.5`, `RF-03.4`, `RF-03.5`, `RF-06.2`, `RNF-01`
  * **Hecho cuando:** `findActiveSignatures()` devuelve solo las firmas con `is_revoked = 0`, y `findLatestVerdictBySpell()` retorna el texto íntegro de la última objeción formulada por un Maestro.
  * **Verificación:** `scratch/test_moderation_signature_repositories.php` — 99 asertos, 0 fallos (once fases: superficie de ambos módulos, estampado con clan retratado y glosa de 250, unicidad parcial del aval vivo con reestampado tras la retractación, censo de solo las vivas con la revocada aún en la base, censo del firmante, revocación con motivo canónico e idempotencia, anulación en bloque de `RF-02.6`, contador 0/3 y pluralidad de hermandades, umbral de 20 caracteres del dictamen, texto íntegro de la última objeción, inmutabilidad del dictamen y `parameter binding` ante entrada hostil).

- [x] **Tarea 1.4: Repositorio de Decretos Soberanos e Integración de Auditoría (`ImperialDecreeRepository.php`)**
  * **Alcance:** Implementar `ImperialDecreeRepository.php` con `insertDecree(spellId, adminId, type, text)` e integrar los eventos de moderación con el canal de auditoría existente de SPEC-03 (`AuditService`, el único escritor de `audit_log`) para asentar en la bitácora inmutable cada firma, objeción, decreto imperial y caducidad.
  * **Cubre:** `RF-04.5`, `RF-06.1`, `RNF-01`, `Artículo III`, `Artículo IV`
  * **Hecho cuando:** Cada decreto imperial se persiste con su texto de justificación y se genera un registro estructurado correspondiente en la tabla de auditoría del santuario.
  * **Verificación:** `scratch/test_moderation_audit_integration.php` — 78 asertos, 0 fallos (ocho fases: superficie y obligatoriedad estructural del canal de auditoría, inscripción y lecturas del decreto, registro estructurado en `audit_log` con actor, acto, objetivo, edicto íntegro e instante compartido, umbrales del edicto y de los cuatro decretos, atomicidad probada con un disparador que hace fracasar la bitácora, inmutabilidad de la memoria, catálogo de acciones de `AuditEntry` con su rótulo castellano cruzado contra `auditLogView.js` y `parameter binding` ante edicto hostil).

---

## Fase 2: DTOs Inmutables y Servicios de Dominio Backend (PHP 8.2+)

- [ ] **Tarea 2.1: DTOs inmutables del dominio de moderación (`src/Dto/*`)**
  * **Alcance:** Crear `src/Dto/SpellReviewDto.php`, `src/Dto/MasterSignatureDto.php`, `src/Dto/ObjectionVerdictDto.php`, `src/Dto/ImperialDecreeDto.php` y `src/Dto/ModerationQueueItemDto.php` con `declare(strict_types=1);`, encapsulando propiedades `readonly` tipadas en inglés `camelCase` y serialización JSON nativa.
  * **Cubre:** `RF-01.1`, `RF-02.1`, `RF-02.5`, `RF-04.4`, `RNF-05`, `Artículo V`
  * **Hecho cuando:** Todos los DTOs se instancian con tipado estricto y su codificación con `json_encode()` produce las estructuras JSON normalizadas definidas en los contratos del plan.

- [ ] **Tarea 2.2: Validador de Ética Constitucional y Pluralidad (`ConstitutionalEthicsValidator.php`)**
  * **Alcance:** Implementar `src/Services/ConstitutionalEthicsValidator.php` con los métodos: `canMasterEvaluateSpell(masterId, originClanId, authorId)`, `validateClanPlurality(activeSignatures, incomingMasterClanId)` y `revokeConflictedSignatures(userId, newClanId, newRole)`.
  * **Cubre:** `RF-02.1`, `RF-03.1`, `RF-03.2`, `RF-03.3`, `RF-03.4`, `RF-03.5`, `RF-03.6`, `Artículo III`
  * **Hecho cuando:** Se bloquea a Maestros del mismo clan del autor, a exmiembros de los últimos 30 días, a autores intentando firmar su propia obra y a dos Maestros del mismo clan ajeno, admitiendo a múltiples ermitaños neutrales.

- [ ] **Tarea 2.3: Servicio de Gestión del Flujo de Estados y Cupos (`ModerationWorkflowService.php`)**
  * **Alcance:** Implementar `src/Services/ModerationWorkflowService.php` con las operaciones: `submitToModeration(spellId, userId)` (control de máx. 3 obras en experimental y sellado de huella), `withdrawToDraft(spellId, userId)` (anulación de firmas), `reopenAsDraft(spellId, userId)` (transición rejected -> draft con historial visible) y `checkExpiryCron()` (caducidad por letargo tras 90 días sin firmas).
  * **Cubre:** `RF-01.1`, `RF-01.2`, `RF-01.3`, `RF-01.4`, `RF-01.5`, `RF-01.6`, `RNF-04`
  * **Hecho cuando:** Un 4º envío simultáneo es rechazado, retirar a borrador revoca todas las firmas previas, y `reopenAsDraft` devuelve el conjuro a `draft` liberando el cupo del autor.

- [ ] **Tarea 2.4: Servicio de Deliberación Colegiada y Consagración Atómica (`MasterDeliberationService.php`)**
  * **Alcance:** Implementar `src/Services/MasterDeliberationService.php` integrando: `signSpell(spellId, masterId, gloss)` (validación de glosa $\le 250$ car., verificación ética, transacción atómica y consagración automática al alcanzar la 3ª firma con acreditación de PDA vía `WeeklyDominionService`), `retractSignature(spellId, masterId, reason)` y `objectSpell(spellId, masterId, reason)` (validación de motivo $\ge 20$ car. y paso a `rejected`).
  * **Cubre:** `RF-02.1`, `RF-02.2`, `RF-02.3`, `RF-02.4`, `RF-02.5`, `RF-02.6`, `RNF-02`
  * **Hecho cuando:** La 3ª firma transiciona el conjuro de forma atómica a `validated`, acredita los PDA a su clan originario, y una objeción válida cancela firmas previas transicionando la obra a `rejected`.

- [ ] **Tarea 2.5: Servicio de Intervención del Administrador Supremo (`SovereignAdminService.php`)**
  * **Alcance:** Implementar `src/Services/SovereignAdminService.php` con: `executeSovereignValidation(spellId, adminId, text)` (restringido exclusivamente a estado `experimental`, bloqueo a obras del clan propio y edicto $\ge 20$ car.), `executeSovereignRescue(spellId, adminId, targetStatus, text)` (reinicio con $0/3$ firmas si va a `experimental`), y `executeSovereignArchive(spellId, adminId, text, deductPoints)` (degradación póstuma y deducción de PDA).
  * **Cubre:** `RF-04.1`, `RF-04.2`, `RF-04.3`, `RF-04.4`, `RF-04.5`, `Artículo II`, `Artículo III.2`
  * **Hecho cuando:** Se rechaza cualquier intento de validar borradores en `draft` o conjuros del propio clan del Administrador, y el rescate a `experimental` reinicia las firmas en $0/3$.

---

## Fase 3: Controladores REST y Contratos de Endpoints (PHP 8.2+)

- [ ] **Tarea 3.1: Controlador del Flujo de Moderación y Catálogo del Atrio (`ModerationController.php`)**
  * **Alcance:** Implementar `src/Controllers/ModerationController.php` exponiendo: `POST /api/v1/moderation/spells/{id}/submit`, `POST /api/v1/moderation/spells/{id}/withdraw`, `POST /api/v1/moderation/spells/{id}/reopen` y `GET /api/v1/moderation/experimental`.
  * **Cubre:** `RF-01.1` a `RF-01.5`, `RF-05.1`, `RF-05.3`, `RNF-01`, `RNF-03`
  * **Hecho cuando:** Las rutas responden con los códigos HTTP 200, 400, 403, 409 y devuelven el catálogo del Atrio con las insignias de advertencia litúrgica.

- [ ] **Tarea 3.2: Controlador de la Torre de Deliberación de Maestros (`MasterDeliberationController.php`)**
  * **Alcance:** Implementar `src/Controllers/MasterDeliberationController.php` exponiendo: `GET /api/v1/moderation/queue`, `POST /api/v1/moderation/spells/{id}/sign`, `POST /api/v1/moderation/spells/{id}/retract` y `POST /api/v1/moderation/spells/{id}/object`.
  * **Cubre:** `RF-02.1` a `RF-02.6`, `RF-03.1`, `RF-03.2`, `RF-05.4`, `RNF-02`
  * **Hecho cuando:** La cola inyecta el flag `hasEthicalConflict` para el Maestro autenticado y las acciones de firma y objeción aplican las restricciones canónicas de longitud y permisos.

- [ ] **Tarea 3.3: Controlador de Decretos Soberanos y Caducidad (`SovereignAdminController.php`)**
  * **Alcance:** Implementar `src/Controllers/SovereignAdminController.php` exponiendo: `POST /api/v1/moderation/sovereign/validate`, `POST /api/v1/moderation/sovereign/rescue`, `POST /api/v1/moderation/sovereign/archive` y `POST /api/v1/moderation/cron-check-expiry`.
  * **Cubre:** `RF-01.6`, `RF-04.1` a `RF-04.5`, `RNF-01`
  * **Hecho cuando:** Las rutas verifican el rol `supremeAdmin`, exigen el texto del edicto imperial y ejecutan la tarea programada de caducidad tras 90 días.

---

## Fase 4: Suite de Pruebas Automatizadas Backend CLI

- [ ] **Tarea 4.1: Suite automatizada de pruebas de moderación en CLI (`scratch/test_moderation_workflow.php`)**
  * **Alcance:** Crear `scratch/test_moderation_workflow.php` ejecutando sobre SQLite en memoria la verificación de los 11 escenarios críticos del plan:
    1. Transición `draft` $\rightarrow$ `experimental` sellando huella matemática.
    2. Consagración automática exactamente en la 3ª firma y liquidación de PDA.
    3. Bloqueo ético por clan del autor y ex-clan de los últimos 30 días (Art. III).
    4. Bloqueo de auto-firma para autores con rango `master` o `supremeAdmin`.
    5. Regla de pluralidad: bloqueo a 2 Maestros del mismo clan ajeno, admisión de ermitaños.
    6. Veto de calidad por objeción ($\ge 20$ car.) pasando de inmediato a `rejected` y retiro del Atrio.
    7. Re-apertura formal (`reopenAsDraft`) a `draft` con historial visible y liberación de cupo.
    8. Bloqueo ante el 4º conjuro concurrente del autor.
    9. Anulación automática de firma previa por conflicto sobrevenido o degradación de rango ($N-1$).
    10. Bloqueo de Firma Soberana desde `draft` y bloqueo de Firma Soberana al clan del propio Administrador.
    11. Caducidad automática a `rejected` tras 90 días de inactividad.
  * **Cubre:** `RF-01.1` a `RF-06.2`, `RNF-01` a `RNF-05`, `Plan Sec. 6.1`
  * **Hecho cuando:** La ejecución `php scratch/test_moderation_workflow.php` supera el 100% de los 11 bloques de asertos con código de salida 0.

---

## Fase 5: Clientes de API y Componentes UI Vanilla ES Modules

- [ ] **Tarea 5.1: Cliente HTTP fetch nativo de moderación (`moderationClient.js`)**
  * **Alcance:** Desarrollar `public/assets/js/api/moderationClient.js` utilizando `fetch` nativo sin librerías externas, gestionando tokens Bearer, propagación de errores litúrgicos en castellano y métodos para envíos, firmas, objeciones y decretos.
  * **Cubre:** `RF-01.1` a `RF-04.5`, `RNF-03`, `RNF-05`, `Artículo I`
  * **Hecho cuando:** El cliente realiza las invocaciones asíncronas a todos los endpoints del backend procesando las respuestas normalizadas.

- [ ] **Tarea 5.2: Componente público del Atrio de Pruebas (`experimentalHallComponent.js`)**
  * **Alcance:** Desarrollar `public/assets/js/components/experimentalHallComponent.js` para renderizar en el catálogo público los conjuros en deliberación, luciendo el marco rúnico de advertencia (*«En Deliberación Arcana — Obra en Fase de Prueba»*), medidor circular de firmas ($0/3, 1/3, 2/3$) y botón de prueba en simulador.
  * **Cubre:** `RF-05.1`, `RF-05.2`, `RF-05.3`, `RNF-03`
  * **Hecho cuando:** El componente muestra únicamente conjuros en `experimental`, deshabilitando la generación de puntos y permitiendo su lanzamiento en el simulador.

- [ ] **Tarea 5.3: Componente del Panel de la Torre de Deliberación (`mastersTowerComponent.js`)**
  * **Alcance:** Desarrollar `public/assets/js/components/mastersTowerComponent.js` exclusivo para Maestros y Administradores, con filtros por círculo/elemento, cola por antigüedad, botón de firma (deshabilitado con alerta ceremonial si hay conflicto de clan) y botón de objeción.
  * **Cubre:** `RF-02.1`, `RF-02.2`, `RF-03.3`, `RF-05.4`, `RNF-03`
  * **Hecho cuando:** Un Maestro con conflicto ético ve el botón de firma deshabilitado con la leyenda *«Veto Constitucional: Hermandad Incompatible»*, mientras que uno apto puede firmar u objetar.

---

## Fase 6: Modales Litúrgicos, Vistas de Subsanación y Hojas de Estilo CSS3

- [ ] **Tarea 6.1: Modales de Objeción y Edicto Imperial (`objectionModalComponent.js` e `imperialDecreeModalComponent.js`)**
  * **Alcance:** Desarrollar `public/assets/js/components/objectionModalComponent.js` (con validador dinámico decreciente que exige $\ge 20$ caracteres en castellano antes de habilitar el envío) e `imperialDecreeModalComponent.js` (para decretos soberanos de validación, rescate o archivo).
  * **Cubre:** `RF-02.5`, `RF-04.5`, `RNF-03`
  * **Hecho cuando:** Los modales bloquean el botón de confirmación si el texto tiene menos de 20 caracteres y emiten los eventos correspondientes tras la confirmación solemne.

- [ ] **Tarea 6.2: Componente de Subsanación y Re-apertura para Autores (`spellCorrectionComponent.js`)**
  * **Alcance:** Desarrollar `public/assets/js/components/spellCorrectionComponent.js` para la libreta privada del creador, exhibiendo el pergamino de observaciones del Maestro cuando una obra está en `rejected` y el botón ceremonial **«Reabrir como Borrador»** (`reopenAsDraft`).
  * **Cubre:** `RF-01.4`, `RF-06.2`, `RNF-03`
  * **Hecho cuando:** El autor puede consultar el motivo exacto del rechazo y, al pulsar en reabrir, el conjuro pasa a `draft` habilitando el formulario de edición.

- [ ] **Tarea 6.3: Hojas de estilos ceremoniales CSS3 (`moderation.css`)**
  * **Alcance:** Crear `public/assets/css/components/moderation.css` con variables de fantasía oscura, marcos de pergamino ámbar para advertencias del Atrio, efectos dorados de consagración, sellos rúnicos de veto y animaciones `@keyframes` nativas.
  * **Cubre:** `RF-05.1`, `RF-05.4`, `RNF-03`, `Artículo IV`
  * **Hecho cuando:** Los componentes de moderación se renderizan con coherencia visual mística y se adaptan responsive a móvil y escritorio sin librerías externas.

- [ ] **Tarea 6.4: Vistas integradas del Atrio y la Torre (`experimentalHallView.js` y `mastersTowerView.js`)**
  * **Alcance:** Desarrollar `public/assets/js/views/experimentalHallView.js` (vista pública comunitaria del Atrio) y `public/assets/js/views/mastersTowerView.js` (vista solemne de la Torre con enrutamiento y control de acceso RBAC para `master` y `supremeAdmin`).
  * **Cubre:** `RF-01.1`, `RF-05.1`, `RF-05.4`
  * **Hecho cuando:** Los usuarios no autorizados son redirigidos si intentan entrar a la Torre, y los visitantes pueden explorar y filtrar libremente el Atrio de Pruebas.

---

## Fase 7: Verificación Integral, Concurrencia y Cierre de la Tríada Canónica

- [ ] **Tarea 7.1: Integración cruzada con Simulador, Gran Tomo y Dominio Semanal**
  * **Alcance:** Conectar la invocación de conjuros experimentales en el simulador sin acreditar puntos (SPEC-05), la inserción de conjuros validados en el Gran Tomo Canónico (SPEC-04) y la liquidación automática de PDA al clan originario tras la 3ª firma (SPEC-07).
  * **Cubre:** `RF-02.3`, `RF-05.2`, `RF-05.3`, `RNF-01`, `RNF-02`
  * **Hecho cuando:** Al alcanzarse la 3ª firma de un conjuro en moderación, el conjuro aparece inmediatamente en el Gran Tomo y los puntos de dominio se suman al ranking semanal del clan originario en tiempo real.

- [ ] **Tarea 7.2: Verificación completa de suite de pruebas y certificación de cierre**
  * **Alcance:** Ejecutar la suite completa CLI (`test_moderation_workflow.php`), verificar la ausencia total de dependencias npm o Composer, validar la tipificación estricta en PHP 8.2+ y comprobar el cumplimiento exhaustivo de los 17 Criterios de Finalización de SPEC-08 y los 7 Artículos de la Constitución.
  * **Cubre:** `RF-01.1` a `RF-06.2`, `RNF-01` a `RNF-05`, `Criterios de Finalización de SPEC-08`
  * **Hecho cuando:** Todos los asertos automatizados pasan con éxito (código de salida 0) y la Tríada Canónica de SPEC-08 (`spec.md`, `plan.md`, `tasks.md`) queda completamente alineada y lista para la ejecución.
