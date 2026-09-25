# TASKS-12: Tareas del Panel del Adepto

> **Especificación:** [`specs/12-user-panel.spec.md`](12-user-panel.spec.md) | **Plan:** [`specs/12-user-panel.plan.md`](12-user-panel.plan.md)  
> **Convención:** tareas de 20–30 min, ordenadas por dependencia. Cada tarea nombra los RF/RNF que cubre y cierra con un «Hecho cuando» verificable por arnés o checklist.  
> **Patrón del santuario:** cada tarea de backend lleva su arnés `scratch/test_*.php` escrito ANTES que la implementación (TDD, como TASKS-09/10/11); cada tarea de frontend, su `scratch/test_*.mjs` con DOM simulado.

---

## FASE 1 — Persistencia y Vitrina (fundación)

- [x] **Tarea 1.1 — Migración de la columna `avatar`**
  *Cubre:* RF-03.1–RF-03.5 (persistencia), RNF-02.  
  *Alcance:* `sql/12_user_panel.sql` (ALTER idempotente SQLite + MySQL con comentario constitucional del patrón 09), columna en el `CREATE TABLE users` de `database/schema.sql` (coherencia guion↔esquema), y arnés `scratch/test_user_panel_migration.php` que verifica la columna en ambas dialectos y la idempotencia de doble aplicación.  
  **Hecho cuando:** el arnés de migración pasa en SQLite y MySQL (doble aplicación sin error) y la columna `users.avatar` TEXT NULL existe con su comentario. 

- [x] **Tarea 1.2 — `UserPanelRepository` (lecturas de vitrina)**
  *Cubre:* RF-02.1–RF-02.4, RF-07.1–RF-07.3, RNF-02.  
  *Alcance:* lecturas con PDO preparado de identidad, linaje, membresía (desde `clan_members`, SPEC-07), convalecencia (`convalescenceExpiresAt` espejo de `ClanVestibuleService`), contadores del tomo, firmas del Maestro y gloria semanal; la ÚNICA escritura de `avatar` (UPDATE con guard `WHERE id = :userId`). Arnés `scratch/test_user_panel_repository.php` (siembra mínima + asertos por fila).  
  **Hecho cuando:** el arnés del repositorio pasa y ninguna consulta usa concatenación de strings (solo prepare/execute con parámetros).

- [x] **Tarea 1.3 — Fecha del juramento desde la bitácora**
  *Cubre:* RF-02.1 (estampa del juramento), principio rector 2.  
  *Alcance:* consulta de `audit_log` por `LINEAGE_OATH_SWORN` del propio `user_id` (índice `idx_audit_actor`), dentro del repositorio de 1.2; peregrino → `swornAt: null`. Asertos integrados en el arnés del repositorio.  
  **Hecho cuando:** el arnés del repositorio aserta que un linajado con asiento obtiene su estampa y un peregrino obtiene null.

- [x] **Tarea 1.4 — `UserPanelDto` + `GET /api/v1/panel`**
  *Cubre:* RF-01.1, RF-01.2, RF-01.3, RF-01.5, RF-02.1–RF-02.4, RF-07.1–RF-07.3.  
  *Alcance:* DTO autocontenido (mapa espejo de `roleLabel`, patrón de `GrimoirePageDto`), controlador con 401/200, registro de ruta en `public/index.php`. Arnés `scratch/test_user_panel_vitrina.php` con sus 8 fases (§6.1 del plan): linajado completo sin identificadores crudos, peregrino con `avatarRestricted:true`, 401 anónimo, privacidad estricta, roleLabel ×4, linaje legado, clan archivado, Supremo sin linaje.  
  **Hecho cuando:** las 8 fases del arnés de vitrina pasan y la respuesta jamás contiene el user_id de otro adepto sembrado.

- [x] **Tarea 1.5 — Retención del peregrino refrendada por el backend**
  *Cubre:* RF-01.3, RNF-06, hallazgo 12 del QA.  
  *Alcance:* guardia central del controlador del panel: si `lineage === null && role !== 'supremeAdmin'`, toda escritura (avatar, frase NO — la frase es del catálogo cerrado del peregrino y queda habilitada) responde 403 `LINEAGE_OATH_REQUIRED` con el sobre canónico. Asertos en el arnés de vitrina.  
  **Hecho cuando:** el arnés aserta que un peregrino recibe 403 `LINEAGE_OATH_REQUIRED` en POST/DELETE de avatar y 200 en lectura de vitrina y credenciales.

---

## FASE 2 — El Avatar (catálogo + efigie propia)

- [x] **Tarea 2.1 — Catálogo canónico de avatares**
  *Cubre:* RF-03.1, duda 5 sellada.  
  *Alcance:* `src/Services/AvatarService.php` con el catálogo en código (efigies/heráldicas del canon existente: 8 sellos de linaje + canónicos del santuario), `AvatarCatalogDto`, `GET /api/v1/panel/avatars` con `restricted` para el peregrino y `AVATAR_CATALOG_UNAVAILABLE` (500) ante fallo. Arnés `scratch/test_avatar_service.php` fase [1] y fase de catálogo.  
  **Hecho cuando:** el arnés aserta el catálogo servido con su `current` y `restricted` correcto por estado de cuenta, y el fallo simulado de catálogo responde 500 con el código canónico sin trazas.

- [x] **Tarea 2.2 — Alta de efigie propia (validación + encuadre)**
  *Cubre:* RF-03.2, RF-03.3, RNF-04, §7b.  
  *Alcance:* `POST /api/v1/panel/avatar` modo `own`: validación de formato (png/jpg/webp), peso ≤ 2 MiB, lados ≤ 1024 px, encuadre ceremonial 512×512, fichero en `storage/avatars/` con nombre aleatorio NO derivado del alias. Códigos: `INVALID_AVATAR_FORMAT`, `AVATAR_TOO_LARGE`, `AVATAR_DIMENSIONS_EXCEEDED`, 413. Arnés fase [3].  
  **Hecho cuando:** el arnés aserta las tres familias de rechazo con su código que NOMBRA el motivo, el vigente intacto y el fichero no creado.

- [x] **Tarea 2.3 — Aceptación atómica + asiento de bitácora**
  *Cubre:* RF-03.6, RNF-05, casos límite 15/16.  
  *Alcance:* transacción fichero→UPDATE→INSERT `AVATAR_SELF_MODIFIED` (ampliación mínima del catálogo cerrado en `AuditEntry.php`, §5.1 del plan); rollback con limpieza de huérfano si falla la bitácora; re-subida idéntica → `AVATAR_IDENTICAL` sin asiento (hash del resultado). Arnés fases [1,2,4,7] + caso límite 18.  
  **Hecho cuando:** el arnés aserta alta exitosa con asiento, fallo simulado de almacenamiento/bitácora sin mutación ni huérfanos, y re-subida idéntica rechazada sin asiento.

- [x] **Tarea 2.4 — Elección del catálogo y retiro al canónico**
  *Cubre:* RF-03.1 (efecto inmediato), RF-03.4, RF-03.5, caso límite 5.  
  *Alcance:* modo `catalog` del POST (asiento si cambia la vigente; inocuo si ya es la vigente) y `DELETE /api/v1/panel/avatar` (retiro → canónico, borrado del fichero propio). Arnés fases [5,8].  
  **Hecho cuando:** el arnés aserta elección de catálogo con asiento, re-elección de la vigente sin asiento, y retiro con retorno a `kind:"default"` y fichero propio borrado.

- [x] **Tarea 2.5 — Degradación ante fichero inaccesible**
  *Cubre:* RF-03.5 (jamás sin efigie), caso límite 15.  
  *Alcance:* en la lectura de vitrina y catálogo, si `avatar = own:f` y `f` no es accesible, servir el canónico con bandera discreta de indisponibilidad (sin mutar la fila). Arnés con fichero borrado a mano tras el alta.  
  **Hecho cuando:** el arnés aserta que con el fichero físico eliminado la vitrina responde `kind:"default"` + bandera y la fila de `users.avatar` NO cambia.

---

## FASE 3 — La Custodia de la Frase de Paso

- [x] **Tarea 3.1 — `changePassphraseAuthenticated` (éxito con disolución)**
  *Cubre:* RF-04.2, RF-04.3, RNF-06.  
  *Alcance:* método en `AuthService` compartiendo hasheo y solidez con `resetPassphrase`, pero en transacción: UPDATE del hash + DELETE de `user_sessions` del usuario EXCEPTO la sesión actual; asiento `PASSPHRASE_SELF_CHANGED` (ampliación del catálogo cerrado, §5.1 del plan). Arnés `scratch/test_passphrase_change.php` fase [1] y [6].  
  **Hecho cuando:** el arnés aserta hash cambiado, demás sesiones disueltas, sesión actual viva, asiento inscrito y la frase ausente de bitácora y cuerpo de asiento.

- [x] **Tarea 3.2 — El fallo ciego (una sola respuesta para tres causas)**
  *Cubre:* RF-04.1 (aviso sin pistas).  
  *Alcance:* veredicto único `PASSPHRASE_CHANGE_FAILED` para: frase actual errónea, nuevas que difieren, solidez insuficiente. Arnés fase [2] que aserta que los TRES cuerpos de respuesta son byte a byte idénticos.  
  **Hecho cuando:** el arnés aserta la igualdad byte a byte de las tres respuestas de fallo y que ninguna nombra la causa.

- [x] **Tarea 3.3 — Frase idéntica y reenvío idempotente**
  *Cubre:* RF-04.1 (salvedades honestas), casos límite 12/17, hallazgo 16 del QA.  
  *Alcance:* `PASSPHRASE_IDENTICAL` (nueva = vigente, sin asiento, comprobada ANTES del fallo ciego) y veredicto `idempotentReceipt` (actual ya vigente + nuevas coincidentes → 200 con estampa del cambio previo, sin asiento). Arnés fases [3,4].  
  **Hecho cuando:** el arnés aserta el rechazo específico de la idéntica sin asiento y el recibo idempotente con estampa previa sin asiento nuevo.

- [x] **Tarea 3.4 — Endpoint y sesión caducada**
  *Cubre:* RF-01.4, RF-04.1, RF-04.2, caso límite 2.  
  *Alcance:* `POST /api/v1/panel/passphrase` en el controlador del panel (401 sin mutación parcial si la sesión caducó a mitad). Arnés fase [5].  
  **Hecho cuando:** el arnés aserta 401 con sesión caducada sin mutación del hash y el endpoint completo cableado en `public/index.php`.

---

## FASE 4 — La Lente de Bitácora Personal

- [x] **Tarea 4.1 — Consulta de pertenencia paginada**
  *Cubre:* RF-06.1, RF-06.2, hallazgo 8/12 del QA (solo actos con el adepto como sujeto).  
  *Alcance:* `GET /api/v1/panel/ledger?cursor=` con el filtro SQL parametrizado del plan §2.7 (actor = yo ∨ target user = yo ∨ obra propia), 20 asientos por página con cursor opaco, orden inverso cronológico, `actionLabel` castellano del mapa de `AuditEntry`. Arnés `scratch/test_personal_ledger.php` fases [1,2,6].  
  **Hecho cuando:** el arnés aserta presencia de los actos propios, ausencia de los ajenos y de los colectivos del clan sin el adepto como sujeto, y paginación estable sin duplicados.

- [x] **Tarea 4.2 — Terceros, vacío y privacidad**
  *Cubre:* RF-06.3, RF-06.4, RF-01.1.  
  *Alcance:* narración de actos con terceros conforme a lo público (sin datos personales de más), `entries: []` ante vacío, y 403 si se solicita la lente de una identidad ajena (el endpoint jamás acepta `userId` ajeno). Arnés fases [3,4,5,7].  
  **Hecho cuando:** el arnés aserta la firma ajena sobre obra propia narrada sin exceso de datos del firmante, el vacío con `entries: []` y el 403 ante identidad ajena.

---

## FASE 5 — Frontend: la Vista del Panel

- [x] **Tarea 5.1 — Cliente API y esqueleto de la vista**
  *Cubre:* RF-02.1, RNF-02.  
  *Alcance:* `public/assets/js/api/userPanelClient.js` (5 llamadas, sobre canónico de errores) y `public/assets/js/views/userPanelView.js` con secciones (vitrina, avatar, frase, convalecencia, bitácora, obras) renderizadas con `createElement`/`textContent` (innerHTML prohibido). Arnés `scratch/test_user_panel_view.mjs` fases [1,2].  
  **Hecho cuando:** el arnés mjs aserta la resolución de `#/morada → panel`, la renderización completa de la vitrina y el centinela de innerHTML en verde.

- [x] **Tarea 5.2 — Estados del peregrino en la vista**
  *Cubre:* RF-01.3, RF-08.2, principio rector 3.  
  *Alcance:* secciones vestidas como pendientes del juramento con conducción vía `panel:restricted-section-activated` → `navigate('juramento')`; credenciales plenamente operativas. Arnés fase [3].  
  **Hecho cuando:** el arnés mjs aserta que el peregrino ve las secciones pendientes, que activar el avatar emite el evento de conducción y que el formulario de frase queda operativo.

- [x] **Tarea 5.3 — Guardia de rótulos prohibidos (RF-03.4 de SPEC-09)**
  *Cubre:* RF-08.3.  
  *Alcance:* asertos de ausencia en el DOM del panel: jamás «Cambiar de linaje», jamás cambio de alias/correo, jamás baja (patrón de FASE 2 del arnés del distintivo); los enlaces de gestión (clan, tomo, renuncia) conducen, no duplican. Arnés fase [4].  
  **Hecho cuando:** el arnés mjs aserta la ausencia de los tres rótulos prohibidos y la presencia de las conducciones a las cámaras canónicas.

- [x] **Tarea 5.4 — Accesibilidad estructural de la vista**
  *Cubre:* RNF-03, RNF-07.  
  *Alcance:* jerarquía de encabezados, región viva única del panel, foco devuelto tras diálogos, hoja `user-panel.css` solo con tokens (auditoría de literales). Arnés fase [5] + verificación de literales.  
  **Hecho cuando:** el arnés mjs aserta la región viva única, la devolución de foco y la hoja sin literales de color fuera de tokens.

---

## FASE 6 — Componentes del Panel

- [ ] **Tarea 6.1 — `avatarPickerComponent`**
  *Cubre:* RF-03.1–RF-03.5, RNF-03, casos límite 14/15.  
  *Alcance:* rejilla del catálogo con `aria-pressed` en la vigente, zona de subida con `input[type=file]` real + previsualización + marco cuadrado, aviso solemne por código de error, repinta de cabecera vía `panel:avatar-changed`, aviso+reintento ante fallo de catálogo, degradación canónica ante fichero corrupto. Arnés `scratch/test_avatar_picker.mjs` fases [1–6].  
  **Hecho cuando:** las 6 fases del arnés del picker pasan (incluidas las degradaciones 14 y 15 de la spec).

- [ ] **Tarea 6.2 — `passphraseChangerComponent`**
  *Cubre:* RF-04.1, RF-04.2, caso límite 12.  
  *Alcance:* tres campos exigidos, los cuatro veredictos con su leyenda castellana canónica, recibo con `othersDissolvedCount`, doble envío → recibo idempotente (jamás aviso mentiroso), anuncio único por región viva. Arnés `scratch/test_passphrase_changer.mjs` fases [1–5].  
  **Hecho cuando:** las 5 fases del arnés del cambiador pasan, incluido el doble envío con recibo idempotente.

- [ ] **Tarea 6.3 — `personalLedgerComponent`**
  *Cubre:* RF-06.1, RF-06.3, RF-06.4.  
  *Alcance:* lista semántica de asientos con estampas legibles (ISO → fecha castellana), paginación por cursor con botón «Ver más», leyenda de silencio ante `entries: []`. Arnés `scratch/test_personal_ledger_view.mjs` (fases: render, paginación, silencio).  
  **Hecho cuando:** el arnés de la lente aserta render, paginación sin duplicados y leyenda de silencio ante vacío.

- [ ] **Tarea 6.4 — `convalescenceCountdownComponent`**
  *Cubre:* RF-05.1–RF-05.3, RNF-03, caso límite 8.  
  *Alcance:* cuenta atrás en días con reloj inyectable, anuncios SOLO en hitos {≤7, ≤3, 1, alzamiento}, refresco sin recarga + `panel:convalescence-lifted` al llegar a cero, sin montaje si no hay convalecencia. Arnés `scratch/test_convalescence_countdown.mjs` fases [1–5].  
  **Hecho cuando:** las 5 fases del arnés del contador pasan, incluido el alzamiento sin recarga y el no-montaje sin veto.

---

## FASE 7 — Integración de Cabecera y Enrutador

- [ ] **Tarea 7.1 — Opción «Mi morada» en el distintivo**
  *Cubre:* RF-08.1.  
  *Alcance:* `userProfileBadge.js`: nueva entrada `{ action: 'openPanel', label: 'Mi morada' }` en `MENU_OPTIONS` con su callback `onOpenPanel` y su cableado en `main.js` (`navigate('panel')`), SIN tocar las tres opciones ratificadas; ampliación de `scratch/test_user_profile_badge.mjs` (la opción presente, las ratificadas intactas, `role=menuitem`).  
  **Hecho cuando:** el arnés del distintivo ampliado aserta la nueva opción, la intacta supervivencia de las tres canónicas y el guard RF-03.4 (sin cambio de linaje).

- [ ] **Tarea 7.2 — Ruta `#/morada` y retención del peregrino**
  *Cubre:* RF-01.2, RF-01.3, RNF-06.  
  *Alcance:* `HASH_TO_VIEW_MAP` con `#/morada → panel`, registro de la vista en el orquestador; `panel` NO entra en `OATH_EXEMPT_VIEWS` (el interceptor existente retiene al peregrino); ampliación de `scratch/test_lineage_retention_nav.mjs` (el peregrino que pide `#/morada` aterriza en la ceremonia).  
  **Hecho cuando:** el arnés de retención ampliado aserta el desvío del peregrino a `juramento` ante `#/morada` y el acceso libre del linajado y del Supremo.

- [ ] **Tarea 7.3 — Repinta de cabecera por eventos**
  *Cubre:* RF-03.3, RF-03.4 (efecto inmediato), caso límite 19 (pestañas: reflejo en siguiente interacción, sin sincronismo vivo).  
  *Alcance:* el distintivo escucha `panel:avatar-changed` y repinta su efigie sin recarga; la vista del panel emite tras cada alta/retiro. Asertos en `test_user_panel_view.mjs` y en el arnés del distintivo.  
  **Hecho cuando:** el arnés aserta que tras el evento el distintivo muestra la nueva efigie sin recarga de página.

---

## FASE 8 — Cierre de Calidad

- [ ] **Tarea 8.1 — Familia completa de arneses en verde**
  *Cubre:* todo RF/RNF.  
  *Alcance:* ejecución de los 8 arneses nuevos + los ampliados (`test_user_profile_badge`, `test_lineage_retention_nav`, `test_main_auth_integration`, `test_navbar`) sin regresiones; centinelas de consola limpios.  
  **Hecho cuando:** la ejecución completa de la familia termina 0 fallidos y los resúmenes narran ÉXITO.

- [ ] **Tarea 8.2 — Auditoría de literales y Dogma Vanilla**
  *Cubre:* RNF-01, RNF-02, RNF-07.  
  *Alcance:* verificación de cero hex crudos en `user-panel.css` (patrón del arnés del Códice), cero dependencias nuevas, `declare(strict_types=1)` en los 12 ficheros PHP, identificadores camelCase y comentarios en castellano.  
  **Hecho cuando:** la auditoría de literales pasa y ningún fichero nuevo carece de tipado estricto o introduce dependencias.

- [ ] **Tarea 8.3 — Checklist manual y despliegue**
  *Cubre:* RNF-03, RNF-06, §7b de la spec.  
  *Alcance:* los 8 puntos manuales del plan §6.3 (teclado, lector de pantalla, reduced-motion, doble pestaña); verificación en producción de las cuotas de avatar (InfinityFree) y de la migración idempotente; README actualizado si la migración requiere paso manual en MySQL.  
  **Hecho cuando:** la checklist manual está completada y firmada en el registro de la tarea y la migración aplicada en producción responde a una segunda aplicación sin error.

---

## Trazabilidad rápida (RF → tareas)

| Requisito | Tareas |
|---|---|
| RF-01.1 | 1.4, 4.2, 5.1 |
| RF-01.2 | 1.4, 7.2 |
| RF-01.3 | 1.4, 1.5, 5.2, 7.2 |
| RF-01.4 | 3.4, 2.2 (avatar 401) |
| RF-01.5 | 1.4 |
| RF-02.1–02.4 | 1.2, 1.3, 1.4 |
| RF-03.1 | 1.5, 2.1, 2.4 |
| RF-03.2 | 2.2 |
| RF-03.3 | 2.2, 7.3 |
| RF-03.4 | 2.4, 7.3 |
| RF-03.5 | 2.4, 2.5 |
| RF-03.6 | 2.3 |
| RF-04.1 | 3.2, 3.3 |
| RF-04.2 | 3.1, 3.4 |
| RF-04.3 | 3.1 |
| RF-05.1–05.3 | 6.4 |
| RF-06.1/06.2 | 4.1 |
| RF-06.3/06.4 | 4.2, 6.3 |
| RF-07.1–07.3 | 1.2, 1.4 |
| RF-08.1 | 7.1 |
| RF-08.2 | 5.2, 5.3 |
| RF-08.3 | 5.3 |
| RNF-01/02 | 8.2 (transversal) |
| RNF-03 | 5.4, 6.1, 6.2, 6.4 |
| RNF-04 | 2.2, 2.3 |
| RNF-05 | 2.3, 3.1 |
| RNF-06 | 1.5, 3.4 |
| RNF-07 | 5.4, 8.2 |
