# PLAN-12: Plan Técnico del Panel del Adepto

> **Especificación:** [`specs/12-user-panel.spec.md`](12-user-panel.spec.md) — Enmendada tras auditoría de QA (4 principios rectores, 19 casos límite, §9 sellado).  
> **Constitución:** [`constitution.md`](../constitution.md) | **Directrices:** [`AGENTS.md`](../AGENTS.md)  
> **Estado:** Borrador — Pendiente de ratificación del plan junto a la spec.  
> **Regla de oro del plan:** el panel es VITRINA de datos ya ratificados y PUERTA que conduce. Este plan no crea verdad nueva: solo reúne, viste y conduce; las dos escrituras nuevas (avatar propio, cambio de frase desde el panel) son actos de identidad personal ratificados por la spec.

---

## 1. Estructura de Módulos y Ficheros

### 1.1 Backend (PHP 8.2+, `declare(strict_types=1)` en todos los ficheros)

| Fichero | Naturaleza | Responsabilidad |
|---|---|---|
| `src/Controllers/UserPanelController.php` | NUEVO | Los 5 endpoints del panel (§3): vitrina, catálogo de avatares, alta/retiro de efigie, cambio de frase. Sin lógica de negocio: delega en servicios. |
| `src/Services/UserPanelService.php` | NUEVO | Orquestación de la vitrina: reúne identidad (sesión), linaje, clan/membresía, convalecencia, contadores del tomo, firmas del Maestro y gloria semanal. Compone los DTO existentes, jamás los duplica. |
| `src/Services/AvatarService.php` | NUEVO | Ciclo de vida del avatar (§4.2): catálogo, alta atómica de efigie propia, retiro con retorno al canónico, validación de formato/peso/lados. Único escritor de la columna `avatar`. |
| `src/Dto/UserPanelDto.php` | NUEVO | Contrato de salida de la vitrina (§2.1). Autocontenido (se forja desde filas, sin instanciar el modelo `User`, patrón de `GrimoirePageDto`). |
| `src/Dto/AvatarCatalogDto.php` | NUEVO | Contrato del catálogo (§2.2): lista de efigies canónicas + vigente. |
| `src/Dto/PassphraseChangeResultDto.php` | NUEVO | Los cuatro veredictos del cambio de frase (§2.4): `changed`, `rejected`, `identical`, `idempotentReceipt`. |
| `src/Services/AuthService.php` | AMPLIADO | Nuevo método `changePassphraseAuthenticated(userId, currentPassphrase, newPassphrase)`: comparte con `resetPassphrase()` el hasheo y la regla de solidez, pero conserva la sesión actual (§4.3). |
| `src/Repositories/UserPanelRepository.php` | NUEVO | Lecturas de vitrina (joins sobre `users`, `clan_members`, `master_signatures`, `grimoire_collections`, `dominion_awards`) y la ÚNICA escritura de `avatar` (sentencia preparada con guard `WHERE id = :userId`). |
| `src/Repositories/AuditService.php` | AMPLIADO | Alta del acto `AVATAR_SELF_MODIFIED` en el catálogo cerrado (§5.1, duda 6). |
| `public/index.php` | AMPLIADO | Registro de las 5 rutas nuevas (§3) tras las de `auth`. |
| `sql/12_user_panel.sql` | NUEVO | Migración: `ALTER TABLE users ADD COLUMN avatar TEXT NULL` + comentario constitucional (patrón de `sql/09_lineage_oath.sql`). Idempotente (guardia por PRAGMA table_info en SQLite / INFORMATION_SCHEMA en MySQL). |
| `database/schema.sql` | AMPLIADO | La columna `avatar` en el CREATE TABLE de `users` (coherencia guion↔esquema). |

### 1.2 Frontend (ES Modules nativos, sin dependencias)

| Fichero | Naturaleza | Responsabilidad |
|---|---|---|
| `public/assets/js/views/userPanelView.js` | NUEVO | La vista `panel` del enrutador: compone las secciones (vitrina, avatar, frase, convalecencia, bitácora, obras y deberes). Patrón de `grimoireCollectionView.js`. |
| `public/assets/js/components/userProfileBadge.js` | AMPLIADO | Nueva opción `{ action: 'openPanel', label: 'Mi morada' }` en `MENU_OPTIONS` (§6.1) — sin tocar las tres ratificadas. |
| `public/assets/js/components/avatarPickerComponent.js` | NUEVO | Selector: rejilla del catálogo + zona de subida propia con previsualización y marco ceremonial cuadrado. |
| `public/assets/js/components/passphraseChangerComponent.js` | NUEVO | Formulario de custodia: frase actual + doble entrada nueva, con los cuatro veredictos narrados en castellano (§6.2). |
| `public/assets/js/components/personalLedgerComponent.js` | NUEVO | Lente de bitácora personal (solo lectura, leyenda de silencio si no hay asientos). |
| `public/assets/js/components/convalescenceCountdownComponent.js` | NUEVO | Cuenta atrás en días con anuncios por hitos (§6.3) y alzamiento sin recarga. |
| `public/assets/js/api/userPanelClient.js` | NUEVO | Cliente `fetch` de los 5 endpoints (patrón de `authClient.js`). |
| `public/assets/js/main.js` | AMPLIADO | Ruta `#/morada → 'panel'` en `HASH_TO_VIEW_MAP`; entrada `panel` en el registro de vistas; el peregrino queda retenido por el interceptor existente (OATH_EXEMPT_VIEWS sin tocar). |
| `public/assets/css/components/user-panel.css` | NUEVO | Hoja del panel: solo tokens (§7, RNF-07). |

### 1.3 Arneses (`scratch/`, tradición del proyecto)

`test_user_panel_vitrina.php`, `test_avatar_service.php`, `test_passphrase_change.php`, `test_personal_ledger.php`, `test_user_panel_view.mjs`, `test_avatar_picker.mjs`, `test_passphrase_changer.mjs`, `test_convalescence_countdown.mjs` (contratos en §8).

---

## 2. Modelo de Datos y Contratos de API REST

### 2.1 Persistencia (lo único nuevo)

```sql
-- users: una sola columna nueva. NULL = avatar canónico por defecto.
ALTER TABLE users ADD COLUMN avatar TEXT NULL;
-- Valores: NULL → canónico; 'catalog:<id>' → efigie del catálogo;
-- 'own:<fileId>' → efigie propia (fileId = nombre físico en storage/avatars/).
```

Sin tablas nuevas: el resto de la vitrina lee de `users`, `clan_members` (autoría de membresía, SPEC-07), `master_signatures` (firmas, SPEC-08), `grimoire_collections` (tomo, SPEC-11), `favorites`/`dominion_awards` (elogios y gloria) y `audit_log` (lente, RF-06). La fecha del juramento (RF-02.1) se lee del asiento `LINEAGE_OATH_SWORN` del propio `user_id` en `audit_log` (fuente ya ratificada; sin columna nueva).

### 2.2 GET `/api/v1/panel` — La vitrina (RF-01.1, RF-02)

| | |
|---|---|
| **Autenticación** | Cookie de sesión (SPEC-03). Anónimo → 401. |
| **200 (linajado)** | `{"success":true,"data":{"panel":{ "identity": { "alias","email","roleLabel","avatar":{"kind":"catalog"\|"own"\|"default","reference"\|"url"\|null,"isOwn":bool} }, "lineage": {"key"\|null,"label","swornAt"\|null,"heraldryKey"\|null,"isUnknownLegacy":bool}, "clan": {"id","name","state":"active"\|"archived","heraldryKey","joinedAt"} \| null, "session": {"deviceLabel","createdAt","expiresAt"}, "convalescence": null \| {"expiresAt","daysRemaining","causeLegend","previousClanName","retainedLegend"}, "collection": {"sealedCount","praiseCount"} , "masterDuties": null \| {"pendingCount","retractedCount","annulledCount"}, "weeklyGlory": null \| {"weekLabel","points"} }}}` |
| **200 (peregrino)** | Igual estructura con `lineage: {key:null, label:"Peregrino sin Linaje", swornAt:null}`, `clan:null`, `collection:{sealedCount:0,praiseCount:0}` y `avatarRestricted:true` (el frontend pinta la conducción a la ceremonia; el backend además refrende con 403 en las escrituras de avatar). `roleLabel` y `masterDuties` según rol. |
| **401** | Sobre canónico `{"success":false,"error":{"code":"UNAUTHENTICATED","message":"..."}}`. |
| **500** | Sobre canónico sin trazas: `{"code":"PANEL_UNAVAILABLE"}`. |

`roleLabel` nace de un mapa espejo canónico (Adepto / Maestro del Códice / Admin Supremo / Lector — mismo vocabulario del `roleLegend` del distintivo, commit 6c21b99). Jamás se imprime el rol técnico ni identificadores crudos (RF-02.1, Art. V).

### 2.3 GET `/api/v1/panel/avatars` — Catálogo (RF-03.1)

- **200:** `{"success":true,"data":{"catalog":[{"id":"seal_primordialFlame","kind":"heraldry","label":"Llama Primordial",...},...],"current":{"kind","reference"},"ownAvatar":{"url","uploadedAt"}|null,"restricted":bool}}`.
- `restricted:true` para el peregrino (hallazgo 12 del QA: lectura permitida como parte de la lectura pública `reader`, pero toda escritura devolverá 403 `LINEAGE_OATH_REQUIRED` — coherencia con el catálogo cerrado de SPEC-09).
- **500** fallo de carga: `{"code":"AVATAR_CATALOG_UNAVAILABLE"}` (caso límite 14: el frontend pinta el aviso solemne con reintento, hermano de «El canon no responde»).

### 2.4 POST `/api/v1/panel/avatar` — Alta/elección (RF-03.2/03.3)

- **Petición:** `multipart/form-data` con `mode=catalog` + `avatarId`, o `mode=own` + `image` (fichero).
- **200:** `{"success":true,"data":{"avatar":{"kind","reference","url"},"auditRecorded":true}}`.
- **400** `INVALID_AVATAR_FORMAT` / `AVATAR_TOO_LARGE` / `AVATAR_DIMENSIONS_EXCEEDED` — aviso que SÍ nombra el motivo (RF-03.2, asimetría razonada: la imagen no es secreto, la frase sí).
- **400** `AVATAR_IDENTICAL` — re-subida idéntica a la vigente (caso límite 18, RF-03.6: sin asiento).
- **403** `LINEAGE_OATH_REQUIRED` — peregrino (retención de sustancia).
- **401** sesión caducada a mitad de edición (RF-01.4).
- **413** exceso de tamaño de petición.
- **500** `AVATAR_STORE_FAILED` — sin mutación del vigente (aceptación atómica, caso límite 16).

### 2.5 DELETE `/api/v1/panel/avatar` — Retiro (RF-03.4/03.5)

- **200:** vuelve el canónico `{"data":{"avatar":{"kind":"default"}}}`. **403** peregrino. **401** caducada.

### 2.6 POST `/api/v1/panel/passphrase` — La custodia (RF-04, cuatro salidas)

- **Petición:** `{"currentPassphrase","newPassphrase","newPassphraseRepeat"}` (JSON, cuerpo no registrado jamás en bitácora ni logs).
- **200 (éxito):** `{"data":{"verdict":"changed","othersDissolvedCount":N,"currentSessionPreserved":true}}` — RF-04.2: disolución de las demás sesiones conservando la actual, anunciada en el recibo. Asiento `PASSPHRASE_SELF_CHANGED` (§5.1).
- **200 (idempotente):** `{"data":{"verdict":"idempotentReceipt","changedAt":<estampa del cambio previo>}}` — reenvío legítimo: `currentPassphrase` ya coincide con el hash vigente Y las nuevas coinciden entre sí y con él (hallazgo 16 del QA: el dueño jamás recibe un aviso que mienta). Sin asiento nuevo.
- **400 (fallo ciego):** `{"error":{"code":"PASSPHRASE_CHANGE_FAILED"}}` — UN SOLO veredicto para: frase actual errónea, nuevas que difieren, solidez insuficiente (RF-04.1: sin pistas). Excluye expresamente el caso «nueva idéntica a la vigente», que tiene salida propia.
- **400 (idéntica):** `{"error":{"code":"PASSPHRASE_IDENTICAL"}}` — caso límite 17, aviso noble específico sin asiento.
- **401** sesión caducada → RF-01.4 (aviso solemne + umbral; sin mutación parcial).

### 2.7 GET `/api/v1/panel/ledger?cursor={cursor}` — Lente de bitácora (RF-06)

- **200:** `{"data":{"entries":[{"actionLabel":"Juramento de Linaje sellado","actionType":"LINEAGE_OATH_SWORN","createdAt","narrative","targetKind"}],"nextCursor":string|null}}` — máximo **20 asientos** por página, cursor opaco (la cardinalidad «últimos» del QA, hallazgo 1, queda sellada: 20 por página, sin límite histórico, paginación por cursor).
- **Filtro de pertenencia (RF-06.1/06.2):** SQL con parámetros vinculados sobre `audit_log`: `(actor_user_id = :userId) OR (target_entity_type = 'user' AND target_entity_id = :userId) OR (target_entity_type = 'spell' AND target_entity_id IN (SELECT id FROM spells WHERE author_id = :userId))`. Los actos colectivos del clan sin el adepto como sujeto quedan fuera (decisión QA: «actos dirigidos al adepto»).
- **200 con vacío:** `entries: []` — el frontend pinta la leyenda de silencio (RF-06.3).
- **403** si el solicitante no es el dueño de la sesión (RF-01.1: privacidad estricta; sin parámetro `userId` ajeno — el endpoint jamás acepta identidades ajenas).

### 2.8 Errores transversales

Todos los errores usan el sobre canónico de AGENTS.md §6.1 (`success:false`, `error{code,message}`), mensajes en castellano solemne, jamás trazas ni excepciones PDO. Códigos usados: 200, 400, 401, 403, 404, 413, 500.

---

## 3. Algoritmos Clave (pseudocódigo) y Máquinas de Estados

### 3.1 Guardia de retención del peregrino sobre el panel (RF-01.3, RNF-06)

```text
función resolverAccesoPanel(sesión):
    si no hay sesión válida                    → UMBRAL (interceptación canónica, RF-01.2)
    si sesión.usuario.linaje ≠ null
       o sesión.usuario.rol = 'supremeAdmin'   → PANEL COMPLETO
    en otro caso (peregrino):                  → PANEL PARCIAL
        secciones habilitadas: identidad (sin efigie), credenciales (frase)
        secciones vestidas como PENDIENTES: linaje, clan, obras, avatar
        cualquier POST de avatar → backend responde 403 LINEAGE_OATH_REQUIRED
                                   (muralla de sustancia, jamás solo fachada)
```

La vista frontend recibe `avatarRestricted`/`lineage.key:null` y viste las secciones pendientes; el backend REFRENDA con 403 las escrituras (coherencia SPEC-09: el bloqueo es de sustancia).

### 3.2 Máquina de estados del avatar (RF-03, casos límite 15 y 16)

```text
ESTADOS: CANONICO → CATALOG(id) → PROPIO(fileId) → (retiro) → CANONICO

transición a PROPIO(file, hashImagen):
    1. validar formato ∈ {png, jpg, webp} ∧ peso ≤ 2 MiB ∧ lados ≤ 1024px   [cifras §5.2]
    2. recorte/encuadre al marco ceremonial cuadrado (512×512 efectivos)
    3. hash del resultado; si hash = hash del vigente propio → RECHAZO AVATAR_IDENTICAL (sin asiento)
    4. escribir fichero en storage/avatars/{fileId} (nombre aleatorio, no derivado del alias)
    5. UPDATE users SET avatar='own:{fileId}' WHERE id=:userId  (única escritura, transacción)
    6. INSERT bitácora AVATAR_SELF_MODIFIED                    [tras el COMMIT]
    si falla 4 o 5 → borrar fichero huérfano si existe → estado VIGENTE intacto (atomicidad, caso límite 16)
    si falla 6 (bitácora) → ROLLBACK de 5 (el acto sin trazabilidad no existe: RNF-05)

degradación (carga de vista):
    si avatar = PROPIO(f) ∧ fichero f inaccesible → servir CANONICO + leyenda discreta
    (la identidad JAMÁS queda sin efigie, caso límite 15; el vigente NO se muta)
```

### 3.3 Máquina de estados del cambio de frase (RF-04, cuatro salidas)

```text
entrada: (actual, nueva, nuevaRepetida)

h = hash(actual) сравña con password_hash vigente vía password_verify
 ┌─ si password_verify(actual) ∧ nueva = nuevaRepetida ∧ sólida(nueva):
 │     si password_verify(nueva, vigente):            → IDEMPOTENTE (recibo con estampa previa, sin asiento)
 │     en otro caso:
 │         tx: UPDATE users SET password_hash=hash(nueva)
 │              DELETE FROM user_sessions WHERE user_id=u AND id≠sesiónActual
 │         → ÉXITO (changed, othersDissolvedCount, asiento PASSPHRASE_SELF_CHANGED)
 ├─ si ¬password_verify(actual) ∧ nueva = nuevaRepetida ∧ sólida ∧ ¬password_verify(nueva):
 │     → FALLO CIEGO (PASSPHRASE_CHANGE_FAILED, sin pistas)
 └─ si password_verify(nueva, vigente):               → IDENTICA (PASSPHRASE_IDENTICAL, aviso noble específico)

 orden de evaluación: la idéntica se comprueba ANTES del fallo ciego
 (la coincidencia de la nueva con la vigente es observable por el dueño
 sin revelar nada a un tercero que no conozca la frase vigente)
```

> Nota de la distinción con el pergamino (§5.3): `resetPassphrase()` (recuperación) revoca TODAS las sesiones incluida la actual porque nace de la pérdida de acceso; `changePassphraseAuthenticated()` conserva la actual porque el dueño está presente y autenticado. Son dos puntos de entrada a la misma columna, jamás el mismo flujo.

### 3.4 Cuenta atrás de convalecencia (RF-05, RNF-03)

```text
servidor: daysRemaining = ceil((convalescenceExpiresAt - ahora) / 1 día)   [espejo de ClanVestibuleService]
cliente:  reloj diario (no por segundo): al cruzar cada frontera de día,
          el contador decrementa y anuncia SOLO los hitos por región viva:
          {≤7 días, ≤3 días, 1 día, alzamiento}   [hitos de RNF-03, jamás cada segundo]
alzamiento (cuenta = 0 con panel abierto):
    refresco sin recarga de la vitrina → convalescence desaparece (RF-05.3: silencio)
    + anuncio «Tu penitencia ha concluido» por región viva (RF-05.2)
```

---

## 4. Arquitectura de Eventos y Componentes Frontend

### 4.1 Flujo de navegación (RF-08.1)

`userProfileBadge` → opción **«Mi morada»** (`openPanel`) → callback `onOpenPanel` del orquestador → `navigate('panel')` → `#/morada` deep-linkable. El peregrino queda retenido por el interceptor existente (`OATH_EXEMPT_VIEWS` no incluye `panel`), igual que el tomo y el Vestíbulo.

### 4.2 Contrato de eventos del bus (patrón del santuario)

| Evento | Emite | Consumen |
|---|---|---|
| `panel:avatar-changed` `{kind, reference}` | vista del panel | distintivo de cabecera (re-pinta efigie sin recarga), vista |
| `panel:passphrase-changed` `{othersDissolvedCount}` | vista del panel | vista (recibo solemne), orquestador (no toca la cookie: la sesión actual persiste) |
| `panel:convalescence-lifted` | contador | vista (refresco de vitrina sin recarga), región viva |
| `panel:restricted-section-activated` `{section}` | vista | orquestador → `navigate('juramento')` (conducción del peregrino) |

Ningún componente del panel llama a la API por su cuenta salvo su cliente propio (`userPanelClient.js`); la identidad de cabecera se repinta por evento, jamás por sondeo.

### 4.3 Accesibilidad estructural (RNF-03)

- Región viva única del panel (`aria-live="polite"`) para: hitos de convalecencia, veredicto del cambio de frase, recibos de avatar. Jamás el tictac diario.
- Foco devuelto al origen tras cerrar cualquier diálogo de confirmación (patrón del modal del juramento).
- El picker de avatar es rejilla de botones reales con `aria-pressed` en la vigente; la zona de subida es `input[type=file]` real con etiqueta (operable sin ratón).
- Tabla de bitácora como lista semántica con estampas temporales legibles (ISO → «el 12 de septiembre de 2026»).

---

## 5. Decisiones Técnicas Justificadas y Alternativas Descartadas

### 5.1 Duda 6 sellada — Asiento de bitácora del cambio de avatar

Verificado sobre `src/Models/AuditEntry.php`: el catálogo cerrado vigente (36 actos: `LINEAGE_OATH_SWORN`, `TOME_SEAL`, `CLAN_MEMBER_JOINED`, `AVATAR…` no existe) **no ampara** el cambio consciente de avatar. Decisión: **ampliación mínima del catálogo con UN acto nuevo**, `AVATAR_SELF_MODIFIED` (actor = sujeto = el propio adepto; `target_entity_type='user'`; `justification` = leyenda fija generada por el servicio, no editable), porque RNF-05 exige trazabilidad del acto y la alternativa de no inscribirlo rompería la inmutabilidad ratificada. Rechazadas: reutilizar `CLAN_MODIFY` (entidad y semántica ajenas), crear `PROFILE_MODIFIED` genérico (abre la puerta a actos difusos futuros — la spec prohibe inventar actos).

### 5.2 Duda 4 sellada — Cifras de la subida (§7b, cuotas InfinityFree)

Formatos `png|jpg|webp`; peso ≤ **2 MiB**; lados ≤ **1024 px**; encuadre ceremonial efectivo **512×512**. Justificación: 2 MiB es el tope cómodo del sandbox gratuito por petición y deja margen a las cuotas de ficheros de `storage/`; se sirve el avatar por fichero estático bajo `storage/avatars/` (fuera del escaparate de fuentes, dentro del alojamiento). Limpieza de huérfanos: el retiro del avatar propio borra el fichero físico en la misma transacción lógica; los huérfanos por fallo se purgan con el reattempt. Limitación declarada en §7b de la spec: sin CDN ni redimensionado bajo demanda (Dogma Vanilla; el encuadre se hace una sola vez en el alta).

### 5.3 Disolución de sesiones: panel vs pergamino (coherencia SPEC-03 RF-06.3)

| | Recuperación (pergamino) | Panel (cambio consciente) |
|---|---|---|
| Dispara | enlace con token de un solo uso | formulario con frase actual |
| Sesión actual | revocada (el dueño no está presente) | **conservada** (el dueño está autenticado) |
| Otras sesiones | revocadas | revocadas |
| Asiento | acto del catálogo existente | `PASSPHRASE_SELF_CHANGED` (nuevo, misma ampliación mínima de §5.1) |

Ambos comparten `password_hash` y regla de solidez; el plan NO unifica los flujos porque su semántica de confianza difiere (ratificado en RF-04.2 y §9.2 de la spec).

### 5.4 Otras decisiones

- **Fecha del juramento desde `audit_log`** (`LINEAGE_OATH_SWORN` del propio user_id): sin columna nueva; la bitácora es la fuente de verdad inmutable del acto (coherencia con el hallazgo 3 del QA resuelto).
- **`roleLabel` por mapa espejo** en el DTO (no instancia de `User`): mismo patrón autocontenido de `GrimoirePageDto` (hallazgo 13 de SPEC-11), cargable sin autoload por los arneses.
- **Lente personal en SQL parametrizado con subconsulta de obras del autor** (§2.7): sin tabla puente nueva; el índice existente `idx_audit_actor` sostiene el camino principal. Alternativa descartada: denormalizar un índice de pertenencia (verdad duplicada, prohíbe el principio rector 2).
- **El peregrino lee el catálogo pero no escribe** (403 en POST): la lectura forma parte de la lectura pública `reader` de SPEC-09 RF-05.1; la escritura queda fuera de su catálogo cerrado. Resuelve el hallazgo 12 sin enmendar SPEC-09.
- **Descartado: subida con recorte en cliente por canvas** para «mejorar» el encuadre: duplicaría la validación y el único recorte canónico debe ser determinista y del lado del santuario.

---

## 6. Estrategia de Pruebas (tradición de arneses del proyecto)

### 6.1 Arneses PHP (backend, `scratch/test_*.php`)

| Arnés | Fases y cobertura |
|---|---|
| `test_user_panel_vitrina.php` | [1] 200 linajado con todos los campos y SIN identificadores crudos; [2] 200 peregrino con `avatarRestricted:true` y secciones pendientes; [3] 401 anónimo; [4] privacidad estricta: la respuesta jamás contiene datos de otro user_id sembrado (RF-01.1); [5] `roleLabel` por mapa espejo para los 4 roles; [6] linaje legado desconocido → leyenda neutra (caso límite 6); [7] clan archivado → estado bronce (caso límite 10); [8] Admin Supremo sin linaje → sin retención (RF-01.5). |
| `test_avatar_service.php` | [1] alta de catálogo con efecto inmediato + asiento `AVATAR_SELF_MODIFIED`; [2] alta propia válida (encuadre 512×512) + asiento; [3] rechazos por formato/peso/lados con código que nombra el motivo y vigente intacto; [4] `AVATAR_IDENTICAL` sin asiento (caso límite 18); [5] retiro → canónico + fichero borrado (RF-03.4/05); [6] peregrino → 403 `LINEAGE_OATH_REQUIRED` (hallazgo 12 del QA); [7] fallo de almacenamiento → sin mutación ni fichero huérfano (atomicidad, caso límite 16); [8] idempotencia del catálogo ya vigente. |
| `test_passphrase_change.php` | [1] éxito: hash cambiado, demás sesiones disueltas, actual conservada, asiento nuevo; [2] fallo ciego único para las tres causas (frase errónea, difieren, solidez) — el cuerpo de respuesta es BYTE a BYTE idéntico en los tres casos (RF-04.1); [3] `PASSPHRASE_IDENTICAL` sin asiento (caso límite 17); [4] reenvío idempotente → `idempotentReceipt` con estampa, sin asiento (hallazgo 16); [5] 401 caducada sin mutación parcial (RF-01.4); [6] la frase jamás aparece en bitácora ni en el cuerpo de ningún asiento. |
| `test_personal_ledger.php` | [1] juramento/adhesión/veredictos/vetos propios presentes en orden inverso; [2] actos ajenos (otro user_id) ausentes; [3] actos colectivos del clan sin el adepto como sujeto ausentes (decisión QA); [4] firma ajena sobre obra propia presente SIN datos personales del firmante más allá de lo público (RF-06.4); [5] vacío → `entries: []` (leyenda de silencio, RF-06.3); [6] paginación por cursor estable y sin duplicados; [7] parámetro `userId` ajeno → 403 (privacidad). |

### 6.2 Arneses mjs (frontend, DOM simulado — patrón de `test_user_profile_badge.mjs`)

| Arnés | Fases y cobertura |
|---|---|
| `test_user_panel_view.mjs` | [1] la ruta `#/morada` resuelve la vista `panel`; [2] vitrina completa renderizada con `textContent` (innerHTML prohibido, centinela del arnés); [3] secciones del peregrino vestidas como pendientes con conducción a `juramento` (evento `panel:restricted-section-activated`); [4] ningún rótulo del panel ofrece «Cambiar de linaje», cambio de alias/correo ni baja (guardia RF-03.4 de SPEC-09 — patrón de FASE 2 del arnés del distintivo); [5] accesibilidad: jerarquía de encabezados, región viva única, foco devuelto tras diálogos. |
| `test_avatar_picker.mjs` | [1] rejilla del catálogo con `aria-pressed` en la vigente; [2] selección → evento `panel:avatar-changed` y repinta del distintivo; [3] subida → previsualización y marco cuadrado; [4] aviso solemne por código de error nombrando el motivo; [5] fallo de carga del catálogo → aviso con reintento (caso límite 14); [6] fichero corrupto → degradación al canónico con leyenda discreta (caso límite 15). |
| `test_passphrase_changer.mjs` | [1] los tres campos exigidos; [2] los cuatro veredictos narrados con su leyenda castellana canónica (éxito, ciego, idéntica, idempotente); [3] recibo de éxito anuncia `othersDissolvedCount`; [4] doble envío → segundo intento con recibo idempotente, jamás aviso mentiroso; [5] región viva anuncia el veredicto una única vez. |
| `test_convalescence_countdown.mjs` | [1] cuenta atrás en días desde `convalescenceExpiresAt`; [2] hitos anunciados SOLO en {≤7, ≤3, 1, alzamiento} — jamás por segundo (RNF-03); [3] alzamiento → refresco sin recarga + evento `panel:convalescence-lifted` + silencio posterior (RF-05.3); [4] sin convalecencia → el componente no monta sección fantasma; [5] reloj inyectable (determinismo, RNF-01 del panel). |

### 6.3 Pruebas manuales (checklist de verificación en navegador)

1. Recorrido del linajado: cabecera → «Mi morada» → vitrina completa → avatar del catálogo → repinta de cabecera sin recarga.
2. Subida de avatar propia válida y de una rechazada (verificar aviso y vigente intacto).
3. Cambio de frase completo: verificar recibo, entrar en otra pestaña → expulsada; la actual sigue viva.
4. Reenvío del cambio tras consumarlo → recibo idempotente legible.
5. Peregrino: panel parcial, credenciales operativas, avatar conduce a la ceremonia; tras jurar, el retorno aterriza en el panel (ruta retenida).
6. Teclado completo: recorrer el panel, picker, formulario y diálogos sin ratón; foco devuelto.
7. Lector de pantalla: veredictos e hitos anunciados una sola vez; sello heráldico sin doble anuncio (patrón de FASE 7 de `test_user_profile_badge.mjs`).
8. `prefers-reduced-motion` activo: sin animaciones en transiciones del panel.

---

## 7. Trazabilidad estricta (spec ↔ plan)

| Requisito de SPEC-12 | Materialización en este plan | Arnés / verificación |
|---|---|---|
| RF-01.1 privacidad estricta | §2.7 (403 identidad ajena), §2.2 sin datos de terceros | `test_user_panel_vitrina` [4], `test_personal_ledger` [2,7] |
| RF-01.2 umbral anónimo | interceptor existente + 401 §2.2 | `test_user_panel_vitrina` [3] |
| RF-01.3 peregrino parcial | §3.1 (guardia), `avatarRestricted` §2.2/2.3 | `test_user_panel_vitrina` [2], `test_avatar_service` [6], `test_user_panel_view` [3] |
| RF-01.4 sesión caducada | 401 en §2.4/2.6 sin mutación parcial | `test_passphrase_change` [5], `test_avatar_service` [7] |
| RF-01.5 Admin Supremo | §2.2 (`lineage` fundacional) | `test_user_panel_vitrina` [8] |
| RF-02.1 vitrina mínima | §2.2 (contrato completo, `roleLabel` espejo, juramento desde bitácora) | `test_user_panel_vitrina` [1,5] |
| RF-02.2 sin clan | `clan:null` + leyenda | `test_user_panel_vitrina` [1] |
| RF-02.3 linaje ajeno | leyenda neutra canónica | `test_user_panel_vitrina` [6] |
| RF-02.4 oficio accesible | `roleLabel` + anuncio de moderación (mismo vocabulario del distintivo) | `test_user_panel_vitrina` [5] |
| RF-03.1 catálogo | §2.3 (solo linajados; peregrino 403 en escritura) | `test_avatar_service` [1,6] |
| RF-03.2 subida validada | §3.2 (pasos 1–2), §2.4 códigos | `test_avatar_service` [3] |
| RF-03.3 privacidad del avatar propio | `storage/avatars/` + solo cabecera propia | `test_avatar_service` [2], manual 2 |
| RF-03.4 cambio inmediato | §3.2 + evento `panel:avatar-changed` | `test_avatar_picker` [2] |
| RF-03.5 retiro → canónico | §3.2 (transición de retiro) | `test_avatar_service` [5] |
| RF-03.6 asiento de bitácora | §5.1 (`AVATAR_SELF_MODIFIED`), inocuo sin asiento | `test_avatar_service` [1,4] |
| RF-04.1 cuatro salidas | §3.3 (máquina), §2.6 | `test_passphrase_change` [2,3,4] |
| RF-04.2 disolución conservando actual | §3.3 (tx), §5.3 (distinción con pergamino) | `test_passphrase_change` [1] |
| RF-04.3 asiento del cambio | §5.1 (`PASSPHRASE_SELF_CHANGED`) | `test_passphrase_change` [1,6] |
| RF-05.1 convalecencia con causa | §2.2 (`convalescence`), días naturales SPEC-07 | `test_convalescence_countdown` [1] |
| RF-05.2 alzamiento sin recarga | §3.4 + `panel:convalescence-lifted` | `test_convalescence_countdown` [3] |
| RF-05.3 silencio sin veto | §3.4 (no montaje) | `test_convalescence_countdown` [4] |
| RF-06.1/06.2 lente de lectura | §2.7 (filtro SQL parametrizado, 20 por cursor) | `test_personal_ledger` [1–6] |
| RF-06.3 leyenda de silencio | `entries: []` → componente | `test_personal_ledger` [5] |
| RF-06.4 terceros sin exceso | §2.7 narrativa pública | `test_personal_ledger` [4] |
| RF-07.1 contadores del tomo | §2.2 `collection` (desde `grimoire_collections`/`favorites`) | `test_user_panel_vitrina` [1] |
| RF-07.2 firmas del Maestro | §2.2 `masterDuties` (desde `master_signatures`) | `test_user_panel_vitrina` [1] + manual 1 |
| RF-07.3 gloria semanal | §2.2 `weeklyGlory` (leyenda si no hay cómputo) | `test_user_panel_vitrina` [1] |
| RF-08.1 «Mi morada» | §1.2 (badge ampliado) + §4.1 | `test_user_panel_view` [1], manual 1 |
| RF-08.2 conducir, no duplicar | §4.2 (conducción a cámaras canónicas) | `test_user_panel_view` [3,4] |
| RF-08.3 jamás cambio de linaje/alias/baja | §1.2 (sin rótulos), guardia del arnés | `test_user_panel_view` [4] |
| RNF-01 Velo y lengua | §2.8 (mensajes solemnes), §6.2 (leyendas) | todos los arneses mjs |
| RNF-02 Dogma Vanilla | §1 (cero dependencias), PDO preparado §2.7 | centinelas de arneses |
| RNF-03 WCAG AA | §4.3 + §3.4 (hitos) | `test_user_panel_view` [5], manual 6–8 |
| RNF-04 privacidad del dato | §5.2 (ficheros no derivados del alias), retención de renuncia (purga con la cuenta) | `test_avatar_service` [2] |
| RNF-05 trazabilidad | §5.1 (ampliación mínima del catálogo cerrado) | `test_avatar_service` [1], `test_passphrase_change` [1] |
| RNF-06 rendimiento/retención | §2.2 una carga; §3.1 refrendo backend | `test_user_panel_vitrina` [2] |
| RNF-07 sistema de diseño | §1.2 `user-panel.css` solo tokens | manual 8 + auditoría de literales |
| Casos límite 1–19 | 1→§3.1; 2→§2.6 401; 3→§3.3; 4→§2.4; 5→§3.2; 6→§2.2; 7→§2.2; 8→§3.4; 9→§2.7; 10→§2.2; 11→§2.7 reintento; 12→§2.6 idempotente; 13→§3.1+§2.2; 14→§2.3; 15→§3.2 degradación; 16→§3.2 atomicidad; 17→§2.6; 18→§2.4; 19→§4.2 (evento, sin sincronismo vivo) | arneses correspondientes |

---

## 8. Garantía de Dogma Vanilla y Dualidad Lingüística

- **Cero dependencias:** ningún `composer.json` ni `package.json`; el cliente es `fetch` nativo; sin CDNs (Art. I). Los arneses lo centinelan (patrón del cierre de SPEC-11).
- **PDO exclusivo:** toda consulta del plan (§2.7 incluida) con `prepare`/`execute` y parámetros vinculados; prohibida la concatenación (AGENTS.md §6.1).
- **Tipado estricto:** `declare(strict_types=1)` en los 12 ficheros PHP nuevos/ampliados.
- **ES Modules nativos:** `type="module"`, sin empaquetador; las vistas se sirven estáticas (patrón vigente).
- **Dualidad (Art. V):** identificadores en inglés `camelCase` (`changePassphraseAuthenticated`, `avatarPicker`, `panel:avatar-changed`), constantes `UPPER_SNAKE_CASE` para los códigos (`AVATAR_IDENTICAL`, `PASSPHRASE_SELF_CHANGED`), clases PHP `PascalCase`; comentarios PHPDoc/JSDoc y TODA cadena visible en castellano solemne (rótulos «Mi morada», «Tu penitencia ha concluido», «La nueva frase coincide con la vigente», «La imagen ya viste tu identidad»).
- **Sobre de error canónico** en todos los endpoints (AGENTS.md §6.1): `success:false` + `error{code,message}` en castellano, jamás trazas.

---

## 9. Secuencia de Implementación Propuesta (tareas de alto nivel)

1. **T1 — Migración y persistencia:** `sql/12_user_panel.sql` + columna en `schema.sql` + `UserPanelRepository` (lecturas + única escritura de `avatar`). Criterio: migración idempotente en SQLite y MySQL.
2. **T2 — Vitrina:** `UserPanelService` + `UserPanelDto` + `GET /panel` + arnés de vitrina. Criterio: 8 fases del arnés en verde.
3. **T3 — Avatar:** `AvatarService` + `AvatarCatalogDto` + alta/retiro + catálogo + acto `AVATAR_SELF_MODIFIED` + arnés. Criterio: 8 fases en verde, atomicidad incluida.
4. **T4 — Frase de paso:** `changePassphraseAuthenticated` + `PassphraseChangeResultDto` + endpoint de las cuatro salidas + arnés. Criterio: 6 fases en verde, cuerpo ciego byte a byte idéntico.
5. **T5 — Lente de bitácora:** endpoint paginado + filtro de pertenencia + arnés. Criterio: 7 fases en verde.
6. **T6 — Frontend:** vista `panel`, componentes (picker, frase, lente, contador), cliente API, hoja de tokens. Criterio: 4 arneses mjs en verde.
7. **T7 — Integración de cabecera y enrutador:** opción «Mi morada», ruta `#/morada`, retención del peregrino, ampliación de `test_user_profile_badge.mjs` (nueva opción presente, las ratificadas intactas). Criterio: familia completa de arneses del badge y del panel en verde.
8. **T8 — Cierre de calidad:** checklist manual completa (§6.3), revisión de literales de color, verificación en producción de cuotas de avatar (§7b).
