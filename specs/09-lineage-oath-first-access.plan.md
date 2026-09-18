# PLAN TÉCNICO — SPEC-09: Juramento de Linaje en el Primer Acceso

> **Estado:** Borrador para revisión (SDD — Fase de Contratos y Mocks)
> **Especificación regida:** `specs/09-lineage-oath-first-access.spec.md`
> **Specs tocadas:** SPEC-03 (enmendada), SPEC-07 (frontera), SPEC-01 (navegación), SPEC-02 (diseño)
> **Regla de oro:** nada de este plan se implementa sin aprobación previa (Artículo VI).

---

## 1. Estructura de Módulos y Ficheros

### 1.1 Backend (PHP 8.2+, MVC ligero, PDO preparado)

```
src/
├── Middleware/
│   └── LineageOathMiddleware.php      # NUEVO: retención de sustancia (RF-01.3, RF-05.1).
│                                       #     Deniega con LINEAGE_OATH_REQUIRED toda
│                                       #     operación no permitida a cuentas sin linaje;
│                                       #     retiene la ruta solicitada en la sesión.
├── Controllers/
│   ├── LineageOathController.php      # NUEVO: GET canon ceremonial, POST juramento,
│   │                                  #     DELETE ruta retenida (RF-02.1, RF-03.1).
│   └── AuthController.php             # MODIFICADO: consecrate() sin clanId; respuesta
│                                       #     con lineage: null (RF-01.1, RF-01.2 de SPEC-09).
├── Services/
│   ├── LineageOathService.php         # NUEVO: lógica del juramento — idempotencia,
│   │                                  #     serialización, validación de canon,
│   │                                  #     asiento en la Bitácora (RF-03.1–03.4).
│   ├── LineageCatalogService.php      # NUEVO: canon inmutable de los 8 linajes con
│   │                                  #     doctrinas condensada e íntegra (RF-02.1/02.2).
│   └── AuthService.php                # MODIFICADO: consecrate() deja de vincular clan;
│                                       #     bind() expone lineage para la sesión.
├── Repositories/
│   └── LineageOathRepository.php      # NUEVO: UPDATE guardado (WHERE lineage IS NULL),
│                                       #     lectura del canon y de la cuenta.
├── Dto/
│   ├── LineageOathResult.php          # NUEVO DTO de veredicto.
│   └── LineageProfileDto.php          # NUEVO DTO de ficha heráldica del canon.
└── Models/
    └── User.php                       # MODIFICADO: expone lineage (junto al espejo clan_id).
```

**Registro en el Front Controller (`public/index.php`):**
- `GET  /api/v1/lineages` — se mantiene, servido ahora por `LineageCatalogService` con doctrinas.
- `GET  /api/v1/lineage/oath-catalog` — canon ceremonial completo para la ceremonia.
- `POST /api/v1/lineage/oath` — sellar el juramento (idempotente, serializado).
- `POST /api/v1/lineage/retained-route` — registra la intención de ruta retenida (uso del interceptor).
- Cadena de middleware del portal: `AuthMiddleware → RbacMiddleware → LineageOathMiddleware`
  aplicada a TODAS las rutas de gestión existentes (hechizos, clanes, moderación, dominio).

### 1.2 Frontend (Vanilla ES Modules, sin dependencias)

```
public/assets/js/
├── main.js                            # MODIFICADO: guarda de retención en navigate()
│                                      #     (interceptor de navegación, RF-01.3).
├── views/
│   └── lineageOathView.js             # NUEVO: la ceremonia — rejilla de 8 tarjetas,
│                                      #     expansión, modal y veredicto (RF-02, RF-03).
├── components/
│   ├── oathModalComponent.js          # NUEVO: modal solemne de doble confirmación
│                                      #     con foco atrapado (RF-02.3, RNF-05).
│   ├── lineageCardComponent.js        # NUEVO: tarjeta heráldica contraída/expandida.
│   ├── accessModalComponent.js        # MODIFICADO: retira el selector de clan del
│                                      #     registro (enmienda SPEC-03).
│   └── userProfileBadge.js            # MODIFICADO: rótulo «Peregrino sin Linaje» y
│                                      #     heráldica del linaje jurado (RF-04.3).
├── api/
│   └── lineageOathClient.js           # NUEVO: cliente fetch de los 3 endpoints.
└── store.js                           # MODIFICADO: estado de sesión expone lineage.
```

```
public/assets/css/components/
└── lineage-oath.css                   # NUEVO: Velo Arcano de la ceremonia (RNF-01).
```

### 1.3 Base de datos y migración

```
sql/09_lineage_oath.sql               # Migración idempotente (estilo 07_*, 08_*).
database/schema.sql                   # MODIFICADO: columna lineage en users.
```

**Migración `09_lineage_oath.sql` (idempotente):**
1. `ALTER TABLE users ADD COLUMN lineage TEXT NULL` con `CHECK` del canon de 8
   (en SQLite se recrea la tabla si el CHECK no puede añadirse por ALTER; la
   migración detecta la columna existente antes de actuar).
2. **Respaldo de legado:** `UPDATE users SET lineage = (SELECT c.lineage_type
   FROM clans c WHERE c.id = users.clan_id) WHERE lineage IS NULL AND clan_id
   IS NOT NULL` — el adepto con membresía histórica hereda el linaje de su clan
   y queda exento para siempre (SPEC-09, caso límite 7).
3. Los que quedan con `lineage IS NULL` (sin clan histórico) devienen
   **peregrinos**: la ceremonia los espera en su próximo inicio de sesión.

---

## 2. Modelo de Datos y Contratos de API REST

### 2.1 Modelo de datos

**Tabla `users` (adición):**

| Columna | Tipo | Nulidad | Semántica |
|---|---|---|---|
| `lineage` | TEXT CHECK en los 8 canónicos | **NULL permitido** | NULL = peregrino sin linaje (retención activa). Escritura única: el juramento. Jamás UPDATE con `lineage` previo distinto (RF-03.3/03.4). |

El espejo `clan_id` NO se toca: la membresía de clan sigue siendo autoridad de
`clan_members` (SPEC-07). El linaje jurado y el clan son vínculos independientes
(RF-04.1); la migración los alinea una sola vez en el respaldo de legado.

**Sesión del servidor (`$_SESSION`):**

| Clave | Semántica |
|---|---|
| `retainedRoute` | Ruta interna solicitada antes de la retención (RF-05.3). Saneada: solo rutas del portal (lista blanca de vistas de `main.js`), jamás URLs externas. Caduca con la sesión. |

**Catálogo de linajes** (código + BD semilla, canon inmutable — exclusión 5):

| Campo | Origen |
|---|---|
| `id` | Los 8 identificadores canónicos de SPEC-07 RF-02.1 (`primordialFlame`…`aetherWeavers`). |
| `name`, `glyph`, `bannerColor`, `rulingElement` | Ya existentes en la heráldica de SPEC-07. |
| `doctrineCondensed` | 1–2 frases canónicas (tarjeta contraída). |
| `doctrineFull` | 2–4 frases canónicas (expansión y modal). |
| `hasActiveClans` | Derivado de `clans` (solo para la nota «Sin hermandades activas», RF-02.1). |

Los 8 textos de doctrina se redactan como ANEXO A de este plan (borrador para
revisión del Arquitecto) y se inscriben en `database/seeds.sql` / servicio
canónico. Nada de métricas demográficas en la ceremonia (duda 3 resuelta).

### 2.2 Contratos de API REST

**Respuesta de error canónica de la retención (RF-05.1) — aplica a TODAS las
rutas de gestión interceptadas:**

```json
HTTP/1.1 403 Forbidden
Content-Type: application/json; charset=utf-8

{
  "success": false,
  "error": {
    "code": "LINEAGE_OATH_REQUIRED",
    "message": "El santuario aguarda tu juramento: nadie pisa sus salas sin linaje jurado.",
    "details": { "oathView": "#/juramento" }
  }
}
```

La petición denegada **retiene su ruta** en la sesión del servidor antes de
responder (cuando la petición porta la cabecera `X-Requested-Route` que el
cliente añade al navegar; las llamadas API puras retienen la ruta de la vista
que las originó, informada por el mismo mecanismo).

---

**Endpoint 1 — Canon ceremonial:**

```
GET /api/v1/lineage/oath-catalog
Autorización: sesión válida (cualquier rol; el adepto sin linaje la necesita).
```

| Código | Cuando |
|---|---|
| 200 | Canon servido. |
| 401 | Sin sesión válida. |

```json
{
  "success": true,
  "data": {
    "accountState": "pilgrim",
    "lineages": [
      {
        "id": "primordialFlame",
        "name": "Linaje de la Llama Primordial",
        "glyph": "rune-flame",
        "bannerColor": "#c0563b",
        "rulingElement": "fire",
        "doctrineCondensed": "…1–2 frases…",
        "doctrineFull": "…2–4 frases…",
        "hasActiveClans": true
      }
    ]
  }
}
```

---

**Endpoint 2 — Sellar el juramento (idempotente y serializado, RF-03.3):**

```
POST /api/v1/lineage/oath
Content-Type: application/json
CSRF: token de sesión (regla general de mutaciones).

{ "lineageId": "primordialFlame" }
```

| Código | `error.code` | Cuando |
|---|---|---|
| 200 | — | Juramento sellado ahora, **o** idempotencia: la cuenta ya porta ESE mismo linaje (éxito sin mutación). |
| 400 | `INVALID_LINEAGE` | `lineageId` ausente, no cadena o ajeno al canon de 8 (canon inmutable: única validación, caso límite 5). |
| 401 | `SESSION_EXPIRED` | Sesión caducada (RF-03.2; el cliente reautentica y la ceremonia reaparece intacta). |
| 403 | `LINEAGE_OATH_CONFLICT` | La cuenta ya porta un linaje **distinto** (RF-03.3: rechazo solemne sin mutación). Aplica también al Admin Supremo que reedita (RF-01.6). |
| 403 | `OATH_FORBIDDEN_ROLE` | El actor está exento por privilegio y no puede jurar (Admin Supremo, RF-01.6). |
| 429 | — | Defensa anti-fuerza bruta heredada de SPEC-03. |

Respuesta 200 (ambos caminos — sellado ahora o idempotencia):

```json
{
  "success": true,
  "data": {
    "lineage": "primordialFlame",
    "sealedNow": true,
    "retainedRoute": "#/simulador"
  }
}
```

`retainedRoute` viaja si existía y es ruta interna saneada; `null` en caso
contrario (el cliente aterriza en el portal de inicio, RF-03.1).

**Pseudocódigo del servicio (serialización por guardia atómica):**

```
function sealOath(userId, lineageId):
    BEGIN TRANSACTION
    row ← SELECT id, lineage FROM users WHERE id = :userId  -- PDO preparado
    IF row.lineage ≠ NULL:
        IF row.lineage = lineageId: COMMIT; return IdempotentSuccess()
        ROLLBACK; throw OathConflict(row.lineage)          -- 403, sin mutación
    updated ← UPDATE users SET lineage = :lineageId, updated_at = :now
              WHERE id = :userId AND lineage IS NULL       -- guardia atómica
    IF updated.rowCount = 0:                               -- carrera: otro juramento ganó
        ROLLBACK; return sealOath(userId, lineageId)       -- re-evalúa → idempotencia o 403
    INSERT INTO audit_log (actor, action_type = 'LINEAGE_OATH_SWORN',
                           target_entity_type = 'user', target_entity_id = userId,
                           justification = 'Juramento del linaje «…» sellado en la ceremonia',
                           created_at = :now)
    COMMIT
    return SealedNow(retainedRoute: takeRetainedRouteFromSession())
```

La guardia `AND lineage IS NULL` dentro del UPDATE es el punto de serialización:
bajo concurrencia, solo la primera escritura vence; la segunda re-evalúa y cae
en idempotencia (200) o conflicto solemne (403) — nunca doble escritura.

---

**Endpoint 3 — Registro de ruta retenida (uso del interceptor):**

```
POST /api/v1/lineage/retained-route
Content-Type: application/json

{ "route": "#/simulador" }
```

| Código | Cuando |
|---|---|
| 204 | Retenida (saneada contra la lista blanca; si no es interna, se descarta en silencio). |
| 401 | Sin sesión. |

El middleware de retención escribe también esta clave al denegar llamadas API
que porten `X-Requested-Route`; el endpoint existe para que el interceptor
frontend retenga rutas de vista puras (navegación sin llamada API posterior).

---

**Endpoint modificado — Consagración (enmienda SPEC-03):**

```
POST /api/v1/auth/consecrate

{ "alias": "…", "email": "…", "passphrase": "…" }
```

`clanId` **deja de aceptarse** (si llega, se ignora — sin error, para no romper
clientes en caché). Respuesta 201:

```json
{
  "success": true,
  "data": {
    "user": { "id": "usr_…", "alias": "…", "role": "editor", "lineage": null },
    "session": { "…": "…" }
  }
}
```

`GET /api/v1/auth/me` (existente) añade `lineage` a su payload: el store
frontend lo consume al hidratar y el interceptor decide sin round-trip extra.

---

## 3. Algoritmos Clave y Máquinas de Estado

### 3.1 Ciclo de vida de la cuenta (RF-01, RF-03)

```
                    ┌────────────────────────────────────────────┐
                    v                                            |
[Consagración] ──► [ PEREGRINO ] ──sellOath (guardia)──► [ LINAJADO ] ── (terminal)
  lineage: null      lineage: null        lineage: los 8      lineage: fijo
                    |     ^
                    |     | reingreso (sesión cerrada a mitad de ceremonia)
                    +-----+  (RF-01.7: persistente, no es evento de un solo uso)

Exento: [ ADMIN SUPREMO ] — privilegio fundacional; navega con o sin linaje (RF-01.6).
Muerte: [ PURGADA ] — el vínculo muere con la cuenta; una re-creación nace peregrina (RF-03.4).
```

### 3.2 Interceptor de retención en `main.js` (frontend, RF-01.3)

```
function navigate(viewName, options):
    user ← store.getState().sessionUser
    IF user existe AND user.lineage = null AND user.role ≠ 'supremeAdmin'
       AND viewName ∉ { 'juramento', 'perfil', 'landing' }:
        POST /api/v1/lineage/retained-route { route: hashActual }   // retención (RF-05.3)
        viewName ← 'juramento'
    … navegación normal …
```

El store se hidrata con `GET /api/v1/auth/me` al arrancar: la decisión de
retención es local e instantánea (RNF-04). La retención de **sustancia** es
del backend: aunque el cliente estuviera comprometido, la API deniega.

### 3.3 Ceremonia y doble confirmación (RF-02, RF-03)

```
[Cargando canon] ──200──► [ Tarjetas ] ──selección──► [ Expandida ]
       |                        ▲                          | pulsar «Jurar»
       | fallo (RF-02.1)        | descartar                v
       v                        +──────────────── [ Modal de sello (foco atrapado) ]
[ Canon no responde ]                                    | «Sellar el juramento»
[ + Reintentar ]                                         v
                                                  [ Sellando… ] ──200──► [ Sellado ]
                                                  | 401/5xx                  | retorno a
                                                  v                          | ruta retenida
                                              [ Aviso solemne ] ◄────────────+ (o inicio)
                                              (ceremonia operativa, RF-03.2)
```

El modal exige segunda pulsación explícita; el descarte no consume nada
(RF-02.3). Tras sellar, la vista conduce a `retainedRoute` del veredicto.

### 3.4 Idempotencia y concurrencia (RF-03.3)

Descrito en §2.2 (guardia atómica `WHERE lineage IS NULL` + re-evaluación).
Criterio determinista: **un solo ganador**; el resto cae en éxito-sin-mutación
(mismo linaje) o conflicto solemne 403 (distinto).

---

## 4. Arquitectura de Eventos y Componentes Frontend

**Sin framework de eventos externo (Artículo I):** la ceremonia se orquesta con
`CustomEvent` sobre el propio contenedor de la vista, al estilo del bus de
combos del simulador.

| Evento (nombre) | Emisor | Detalle | Consumidor |
|---|---|---|---|
| `oath:catalog-loaded` | `lineageOathView` | `{ lineages: LineageProfileDto[] }` | Registro/arneys |
| `oath:lineage-expanded` | `lineageCardComponent` | `{ lineageId }` | Vista (render del texto íntegro) |
| `oath:confirmation-opened` | `oathModalComponent` | `{ lineageId }` | Vista, región viva ARIA |
| `oath:confirmation-dismissed` | `oathModalComponent` | `{}` | Vista (sin mutación, RF-02.3) |
| `oath:sealed` | `lineageOathView` | `{ lineage, retainedRoute }` | `main.js` (actualiza store, navega al retorno) |
| `oath:failed` | `lineageOathView` | `{ code, message }` | Vista (aviso solemne), región viva |

**Componentes:**

- `lineageCardComponent` — tarjeta heráldica: contraída (condensada) / expandida
  (íntegra + botón «Jurar»). Enfocable, Enter/espaciadora operan (RNF-05).
- `oathModalComponent` — `<dialog>` nativo: texto del juramento en primera
  persona, advertencia de perpetuidad, doble confirmación, foco atrapado y
  devuelto al cerrar, cierre con Escape (descarte seguro).
- `lineageOathView` — orquestador: carga del canon, rejilla solemne, aviso de
  reintento ante fallo, emisión de eventos y conducción al retorno.
- `main.js` (interceptor) — guarda descrita en §3.2; añade la vista
  `juramento` a la lista blanca del enrutador.
- `userProfileBadge` / `navbarComponent` — rótulo «Peregrino sin Linaje»
  (sin heráldica) o blasón del linaje jurado (RF-04.3), con la representación
  heráldica de SPEC-07.

---

## 5. Decisiones Técnicas Justificadas y Alternativas Descartadas

1. **Retención en servidor (sesión nativa PHP) y no solo en cliente.** La spec
   exige retención de sustancia (RF-05.1). *Descartada:* guardar la ruta solo
   en el cliente (burlable) o en la URL (visible, manipulable — decisión de la
   duda «retención de ruta»).
2. **Guardia atómica en el UPDATE (`WHERE lineage IS NULL`)** como punto de
   serialización. *Descartada:* locks explícitos `SELECT … FOR UPDATE` (acopla
   a MySQL y SQLite no lo soporta igual) o reintentos de aplicación sin guardia
   (carrera real de doble escritura). El guardia es portable PDO y determinista.
3. **Canon de linajes como servicio inmutable (`LineageCatalogService`), no
   tabla administrable.** La spec excluye altas/bajas/suspensiones (exclusión 5,
   caso límite 5). *Descartada:* tabla `lineages` editable por el Admin Supremo
   (crearía la potestad administrativa que el QA desterró).
4. **`lineage` como columna propia de `users`, independiente del espejo
   `clan_id`.** El linaje es identidad perpetua; el clan es membresía mutable
   con convalecencia (RF-04.1). *Descartada:* derivar el linaje del clan activo
   en caliente (el abandono de clan anularía la identidad — contrario a la
   irrevocabilidad). La migración alinea una sola vez el legado y a partir de
   ahí los vínculos viven separados.
5. **Exención del Admin Supremo resuelta por rol, no por linaje.** El
   middleware y el interceptor comprueban `role ≠ 'supremeAdmin'` (RF-01.6).
   *Descartada:* asignarle un linaje sintético (fabricaría identidad sin
   juramento).
6. **Designación de Maestro condicionada a linaje (RF-05.2)** implementada como
   guardia en `SovereignAdminService::promoteMaster` (rechazo solemne si
   `lineage IS NULL`), no como filtro de la ceremonia. *Descartada:* permitir
   el ascenso y confiar en el conflicto de intereses posterior (crearía un
   Maestro sin sujeto ético determinado — hueco del Artículo III).
7. **Convalecencia sin interacción.** Ninguna consulta de `clan_members` ni de
   convalecencias en el flujo del juramento (RF-04.4): vínculos distintos.
   *Descartada:* bloquear el juramento a convalecientes (penaría injustamente
   a legados sin linaje).
8. **El registro ignora `clanId` en silencio** en lugar de rechazarlo. Clientes
   en caché con el formulario viejo no rompen; la UI nueva ya no lo envía.
   *Descartada:* 400 ante `clanId` (rompería transitoriamente el registro real
   de usuarios con pestañas abiertas).
9. **Vista `juramento` como ruta real del enrutador** (deep-linkable, lista
   blanca de `main.js`), no capa modal global. *Descartada:* overlay bloqueante
   sobre cada vista (interferiría con la hidratación de cada vista y con el
   botón atrás sin ganar nada).

---

## 6. Estrategia de Pruebas

### 6.1 Arneses backend (PHP, estilo `scratch/test_*.php`)

| Arnés | Cubre |
|---|---|
| `scratch/test_lineage_oath_service.php` | Juramento feliz; idempotencia (mismo linaje → 200 sin mutación); conflicto (distinto → 403); canon inválido → 400; serialización concurrente simulada (dos `sealOath` entrelazados → un ganador); guardia `WHERE lineage IS NULL`; asiento `LINEAGE_OATH_SWORN` en la Bitácora; exención del Admin Supremo; designación de Maestro a peregrino rechazada. |
| `scratch/test_lineage_oath_middleware.php` | Retención: peregrino → 403 `LINEAGE_OATH_REQUIRED` en rutas de gestión; rutas permitidas (canon, juramento, perfil, logout, lectura pública) → paso; linajado → paso siempre; ruta retenida saneada (interna sí, externa descartada). |
| `scratch/test_lineage_consecration.php` | Enmienda SPEC-03: `consecrate` sin `clanId` crea cuenta con `lineage: null`; `clanId` recibido se ignora; contrato 201 sin `clanName`. |
| `scratch/test_lineage_migration.php` | Migración 09: columna añadida (idempotente al re-ejecutar); respaldo de legado (usuario con `clan_id` hereda `lineage_type`); peregrinos restantes. |

### 6.2 Arneses frontend (Node ES Modules, estilo `scratch/test_*.mjs`)

| Arnés | Cubre |
|---|---|
| `scratch/test_lineage_oath_view.mjs` | Render de las 8 tarjetas (nombre, blasón, estandarte, elemento, condensada); expansión (íntegra + texto del juramento); nota «Sin hermandades activas»; fallo de canon → aviso + reintento (retención no liberada). |
| `scratch/test_lineage_oath_modal.mjs` | Modal doble confirmación; descarte sin mutación; foco atrapado y devuelto; Escape; anuncios ARIA; contraste y movimiento reducido (media query falsa). |
| `scratch/test_lineage_retention_nav.mjs` | Interceptor de `navigate()`: peregrino retenido con retención enviada; lista blanca (perfil, landing, juramento); linajado jamás interrumpido; Admin Supremo exento; retorno a la ruta del veredicto; rótulo «Peregrino sin Linaje» en el badge; heráldica tras jurar. |
| `scratch/test_lineage_oath_client.mjs` | Cliente `fetch`: payloads, códigos 200/400/401/403 mapeados a veredictos de la vista. |

### 6.3 Verificación manual (navegador, contra el servidor de demo)

1. Registrar un adepto nuevo: sin selector de linaje; aterriza en la ceremonia.
2. Intentar por URL directa `#/creador` con pestaña nueva: retención + retorno
   post-juramento a `#/creador`.
3. Recorrer el modal: descartar, sellar, comprobar Bitácora de Auditoría
   pública (`#/bitacora`) y rótulo de identidad.
4. Cerrar sesión a mitad de ceremonia y reingresar: ceremonia intacta.
5. Doble pestaña: jurar en una, navegar en la otra, recargar → exento.
6. Simular fallo del canon (servidor pausado) → aviso solemne + reintento.
7. Teclado completo de la ceremonia (foco visible, Enter, Escape) y
   `prefers-reduced-motion` activo.

---

## 7. Trazabilidad RF-x / RNF-x ↔ Plan

| Requisito | Puntos del plan | Prueba |
|---|---|---|
| RF-01.1 (registro sin linaje) | §1.1 `AuthController` mod.; §1.2 `accessModalComponent`; §2.2 Endpoint consagración | `test_lineage_consecration.php` |
| RF-01.2 (cuenta con `lineage: null`) | §1.1 `AuthService`; §2.1 columna `lineage` | `test_lineage_consecration.php` |
| RF-01.3 (retención vistas+API) | §1.1 `LineageOathMiddleware`; §3.2 interceptor | `test_lineage_oath_middleware.php`, `test_lineage_retention_nav.mjs` |
| RF-01.4 (lista de permitidos) | §2.2 (rutas excluidas del middleware); §7 middleware | `test_lineage_oath_middleware.php` |
| RF-01.5 (despliegue/legado) | §1.3 migración con respaldo | `test_lineage_migration.php` |
| RF-01.6 (exención linajado y Supremo) | §5.5; §3.2 guardia de rol | `test_lineage_oath_middleware.php`, `test_lineage_oath_service.php` |
| RF-01.7 (reingreso a ceremonia) | §3.1 máquina de estados (persistencia de `lineage: null`) | `test_lineage_retention_nav.mjs` |
| RF-02.1 (canon ceremonial, nota, fallo) | §2.1 catálogo; §4 vista | `test_lineage_oath_view.mjs` |
| RF-02.2 (expansión, íntegra) | §2.1 doctrina única; §4 tarjeta | `test_lineage_oath_view.mjs` |
| RF-02.3 (modal doble confirmación) | §3.3; §4 `oathModalComponent` | `test_lineage_oath_modal.mjs` |
| RF-03.1 (vínculo permanente + Bitácora + retorno) | §2.2 Endpoint 2; §3.4; §2.1 `retainedRoute` | `test_lineage_oath_service.php` |
| RF-03.2 (fallo solemne) | §2.2 códigos 401/5xx; §3.3 aviso | `test_lineage_oath_client.mjs` |
| RF-03.3 (idempotencia + serialización) | §2.2 pseudocódigo; §3.4 guardia atómica | `test_lineage_oath_service.php` |
| RF-03.4 (sin cambio/revocación; purga) | §2.2 (403 `LINEAGE_OATH_CONFLICT`); §3.1 | `test_lineage_oath_service.php` |
| RF-03.5 (logout sin jurar) | §1.4 rutas permitidas (logout); §3.1 | `test_lineage_retention_nav.mjs` |
| RF-04.1 (independencia de clan) | §5.4 columna propia; sin toques a `clan_members` | `test_lineage_migration.php` |
| RF-04.2 (filtro rector de clanes) | Respeto pasivo: SPEC-07 consulta `lineage` (punto de integración futuro, sin código aquí) | Manual (documentado) |
| RF-04.3 (peregrino / heráldica) | §1.2 `userProfileBadge`, `navbarComponent` | `test_lineage_retention_nav.mjs` |
| RF-04.4 (convalecencia sin interacción) | §5.7 | `test_lineage_oath_service.php` (regresión) |
| RF-05.1 (denegación backend) | §1.1 middleware; §2.2 `LINEAGE_OATH_REQUIRED` | `test_lineage_oath_middleware.php` |
| RF-05.2 (Maestro exige linaje) | §5.6 guardia en `SovereignAdminService` | `test_lineage_oath_service.php` |
| RF-05.3 (ruta en sesión, saneada) | §2.1 sesión; §2.2 Endpoint 3 | `test_lineage_oath_middleware.php` |
| RNF-01 (Velo Arcano) | §1.2 `lineage-oath.css` | Revisión visual manual |
| RNF-02 (Soberanía lingüística) | Anexo A; textos de UI en castellano; identificadores en inglés | `test_lineage_oath_view.mjs` (rótulos) |
| RNF-03 (irrevocabilidad ineludible) | §3.3 modal | `test_lineage_oath_modal.mjs` |
| RNF-04 (rendimiento) | §3.2 decisión local vía store; una sola carga del canon | Manual + inspección |
| RNF-05 (WCAG 2.1 AA) | §4 foco/ARIA/teclado; §6.3 punto 7 | `test_lineage_oath_modal.mjs` |
| RNF-06 (trazabilidad Bitácora) | §2.2 asiento `LINEAGE_OATH_SWORN` | `test_lineage_oath_service.php` |
| RNF-07 (sin degradación extra) | SPA asumida; nada añadido | — |
| Enmienda SPEC-03 (HU-01, RF-01.1/01.2) | §1.1, §2.2, §1.2 formulario | `test_lineage_consecration.php` |

**Nuevo acto de la Bitácora:** `LINEAGE_OATH_SWORN` se inscribe en el catálogo
cerrado de `AuditService` con rótulo castellano («Juramento de Linaje sellado»),
respetando el catálogo cerrado y su aserto de rotulación (SPEC-03, TASK-08).

---

## 8. Garantía de Dogma Vanilla y Dualidad Lingüística

- **Artículo I:** cero dependencias; PDO con consultas preparadas en todos los
  accesos nuevos (`LineageOathRepository`); frontend en ES Modules nativos con
  `<dialog>`, `CustomEvent` y CSS de Custom Properties; nada de routers,
  validadores ni utilidades externas.
- **Artículo V:** clases/DTOs en `PascalCase` inglés (`LineageOathService`,
  `OathConflict`), métodos y variables `camelCase` inglés (`sealOath`,
  `retainedRoute`, `hasActiveClans`), constantes `UPPER_SNAKE_CASE`
  (`LINEAGE_OATH_REQUIRED`); comentarios PHPDoc/JSDoc, doctrinas, juramento,
  avisos y rótulos en noble castellano. Los eventos del bus (`oath:sealed`…)
  y los códigos de error en inglés; los mensajes que los portan, en castellano.

---

## Anexo A — Borrador de las Ocho Doctrinas Canónicas (para revisión del Arquitecto)

> Textos canónicos (íntegra, 2–4 frases); la condensada se derivará recortando
> su primera o primeras frases. Voz solemne, sin anacronismos (Artículo IV).

1. **Llama Primordial** — «Nacimos del primer fuego que ardió antes que los nombres. Forjamos en la hoguera lo que otros apenas se atreven a mirar, y nuestra palabra arde tan limpia como purifica. Quien jura con nosotros aprende que la llama no destruye: revela.»
2. **Mareas Celestiales** — «El agua recuerda cada forma que alguna vez acogió. Nuestros conjuros fluyen como la marea: ceden, envuelven y vuelven siempre. La paciencia es nuestra arma más hondo y el diezmo del río, nuestra ley.»
3. **Tempestad Eterna** — «La tormenta no pregunta a dónde caerá el rayo. Corremos donde el trueno resuena y firmamos nuestros pactos con luz partida. Nuestros juramentos son breves como el relámpago y tan imposibles de retractar.»
4. **Raíces del Mundo** — «Lo que la montaña promete, la montaña cumple. Caminamos lentos porque cargamos con lo que otros olvidan: la memoria de la piedra y la deuda con la tierra. Nuestra palabra pesa como basalto.»
5. **Vientos del Alba** — «Nadie ata al viento, y sin embargo todo lo alcanza. Cruzamos fronteras, llevamos recados y canciones, y deshacemos en un soplo lo que el orgullo edificó. La libertad que juramos es la que otorgamos.»
6. **Corona Solar** — «La luz no esconde nada: por eso reina. Iluminamos el saber, señalamos al mentiroso y sostenemos el alba cuando la noche se alarga. Nuestro yugo es brillar, y brillar fatiga más que combatir.»
7. **Sombras Abisales** — «Conocemos el nombre de todas las cosas que el sol no nombra. Guardamos lo que el mundo prefiere olvidar y caminamos donde la linterna se apaga. No somos la oscuridad: somos su discreto custodio.»
8. **Tejedores del Éter** — «Del maná puro está tejido el mundo, y nosotros conocemos el hilván. No pertenecemos a un elemento: los hilos de todos pasan por nuestras manos. Quien busca el origen de la magia, busca nuestra puerta.»

**[PENDIENTE DE RATIFICACIÓN]** — ningún texto llega a código sin tu aprobación.
