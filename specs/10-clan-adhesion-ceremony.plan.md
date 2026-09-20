# PLAN TÉCNICO — SPEC-10: Ceremonia de Adhesión a Clanes del Propio Linaje

> **Estado:** Borrador para revisión (SDD — Fase de Contratos y Mocks)
> **Especificación regida:** `specs/10-clan-adhesion-ceremony.spec.md`
> **Specs tocadas:** SPEC-07 (se sirve y se enmienda en dos contratos), SPEC-09 (frontera de retención y exención), SPEC-02 (Kit de Controles y Sello Rúnico), SPEC-03 (Bitácora y catálogo de actos), SPEC-01 (navegación)
> **Regla de oro:** nada de este plan se implementa sin aprobación previa (Artículo VI).

---

## 0. Radiografía del Terreno (lo que ya existe y se REUTILIZA)

El reconocimiento del código revela que SPEC-07 construyó casi todo el sostén del
rito de adhesión. Este plan **compone sobre lo existente, jamás lo reimplementa**:

| Mecanismo exigido por la SPEC-10 | Ya existe en producción | Veredicto |
|---|---|---|
| Rito único de admisión (abierto → inmediato; dictamen → petición) | `ClanService::applyToClan()` — un solo punto de entrada que despacha a `admitImmediately()` o `registerApplication()` según `admissionMode` | **REUTILIZAR** |
| Anulación de peticiones residuales (RF-03.7) | `admitImmediately()` y `resolveApplication('approve')` llaman a `cancelPendingApplications()` | **REUTILIZAR** (añadir inscripción en Bitácora) |
| Idempotencia de la petición (RF-03.1) | Guardia atómica en `createApplication()` + `findPendingApplicationForClan()` → `APPLICATION_ALREADY_PENDING` | **REUTILIZAR** |
| Vedados: convalecencia, membresía, plenitud, límite de 3 | `requireFreedomFromConvalescence()`, `findActiveMembership()`, `assertVacancy()`, `countPendingApplications()` con códigos canónicos `CONVALESCENCE_ACTIVE`, `ALREADY_AFFILIATED`, `CLAN_QUOTA_EXCEEDED`, `PENDING_APPLICATIONS_LIMIT` | **REUTILIZAR** (renombrar la leyenda de la vía de adhesión, §5.2) |
| Aritmética de días de convalecencia (RF-03.5) | `ClanMemberDto::convalescenceDaysRemaining` (alza al entero superior, frontera inclusiva) — ratificada y probada | **REUTILIZAR** |
| Persistencia de la clausura por casa | `clan_applications` jamás borra filas: `approved`/`rejected`/`cancelled` persisten con `resolved_at` | **AÑADIR índice único** (§1.3) |
| Sello Rúnico determinista de las casas | `runeSealComponent.js` + contrato de SPEC-02 RF-07 | **REUTILIZAR** |
| Kit de Controles Interactivos | `controls.css` (SPEC-02 RF-08) + guard de cobertura `test_css_coverage.mjs` | **CONSUMIR** |
| Retención del peregrino y exención del Supremo | `LineageOathMiddleware` + interceptor de `main.js` (SPEC-09) | **FRONTERA RESPECTADA** |

**Huecos reales que este plan cierra:** el guardia de linaje jurado NO existe
(`applyToClan` y `foundClan` jamás cotejan el linaje del adepto con el de la
casa — la promesa de SPEC-09 RF-04.2 quedó sin sustancia backend); no hay
endpoint de retirada del postulante; no hay clausura permanente por casa; no
existe la vista del Vestíbulo ni su catálogo derivado de sesión; los actos de
adhesión no figuran en el catálogo de la Bitácora; no hay aviso de veredictos
no contemplados.

---

## 1. Estructura de Módulos y Ficheros

### 1.1 Backend (PHP 8.2+, MVC ligero, PDO preparado)

```
src/
├── Controllers/
│   ├── VestibuleController.php        # NUEVO: GET /clans/vestibule (catálogo derivado
│   │                                  #     de sesión), retirada del postulante,
│   │                                  #     veredicto leído y contador de rótulo
│   │                                  #     (RF-01.1, RF-03.3, RF-03.4, RF-03.8).
│   └── ClanController.php             # MODIFICADO: apply() acepta `motivation`
│                                      #     (molde 20–500, RF-03.1); resolveApplication()
│                                      #     exige `motive` en el rechazo e inscribe el
│                                      #     asiento del dictamen (reparto por actor,
│                                      #     RF-04.4; enmienda SPEC-07).
├── Services/
│   ├── ClanVestibuleService.php       # NUEVO: estado del Vestíbulo en una sola carga —
│   │                                  #     casas del linaje jurado + mi casa legada +
│   │                                  #     aptitud derivada + peticiones propias +
│   │                                  #     días de convalecencia + veredictos sin leer
│   │                                  #     (RF-01.2, RF-01.3, RF-01.7, RF-03.8, RNF-04).
│   └── ClanService.php                # MODIFICADO: applyToClan() añade los guardias
│                                      #     CLAN_LINEAGE_MISMATCH, CLAN_LOYALTY_BOUND y
│                                      #     ADMIN_LINEAGE_REQUIRED (RF-04.1, RF-02.3,
│                                      #     RF-01.1); withdrawApplication() nuevo
│                                      #     (RF-03.3); acknowledgeVerdict() nuevo.
├── Repositories/
│   ├── ClanApplicationRepository.php  # MODIFICADO: findApplicationsByUser() (todos los
│   │                                  #     estados), hasSealedHouse() (clausura),
│   │                                  #     markVerdictSeen(), countUnreadVerdicts().
│   └── ClanMemberRepository.php       # INTACTO: findActiveMembership(), countActiveMembers(),
│                                      #     isUserInConvalescence() bastan.
├── Dto/
│   ├── VestibuleStateDto.php          # NUEVO: el sobre único de la ceremonia.
│   ├── VestibuleClanDto.php           # NUEVO: casa + estado del adepto ante ella.
│   └── ClanPetitionDto.php            # NUEVO: petición del inventario consolidado.
├── Exceptions/
│   └── ClanGovernanceException.php    # MODIFICADO: cuatro códigos canónicos nuevos
│                                      #     (§2.2) con sus leyendas solemnes.
└── Models/
    └── AuditEntry.php                 # MODIFICADO: cinco actos nuevos de adhesión en
                                      #     el catálogo CERRADO (§2.3, RF-04.4).
```

**Registro en el Front Controller (`public/index.php`):**

```
GET  /api/v1/clans/vestibule                              → VestibuleController::show
POST /api/v1/clans/{id}/applications                      → EXISTENTE (rito de ambos regímenes)
POST /api/v1/clans/{id}/applications/{appId}/withdraw     → VestibuleController::withdraw (NUEVO)
POST /api/v1/clans/applications/{appId}/verdict-acknowledge → VestibuleController::acknowledgeVerdict (NUEVO)
GET  /api/v1/clans/verdicts/unread-count                  → VestibuleController::unreadCount (NUEVO)
POST /api/v1/clans/{id}/applications/{appId}/resolve      → EXISTENTE (lado deliberante, enmendado)
```

Las rutas nuevas se registran **detrás de la cadena de middleware vigente**
(`AuthMiddleware → RbacMiddleware → LineageOathMiddleware`): el peregrino
sin linaje jamás llega al Vestíbulo — la retención de SPEC-09 precede
(RF-04.3 de SPEC-10, caso límite 10).

### 1.2 Frontend (Vanilla ES Modules, sin dependencias)

```
public/assets/js/
├── main.js                                  # MODIFICADO: ruta '#/vestibulo' → 'vestibule'
│                                            #     en el mapa de rutas; rótulo de
│                                            #     dictámenes pendientes al hidratar (RF-01.1).
├── views/
│   └── vestibuleView.js                     # NUEVO: la ceremonia — catálogo de casas,
│                                            #     inventario consolidado, modales de los
│                                            #     dos ritos, aviso solemne de veredicto
│                                            #     (RF-01, RF-02, RF-03).
├── components/
│   ├── vestibuleClanCardComponent.js        # NUEVO: tarjeta de hermandad con los estados
│                                            #     derivados de RF-01.7 (RF-01.3, RF-03.5).
│   ├── admissionModalComponent.js           # NUEVO: modal solemne del ingreso inmediato —
│                                            #     lealtad indivisible + advertencia de
│                                            #     convalecencia futura, foco atrapado (RF-02.1).
│   ├── petitionComposerComponent.js         # NUEVO: molde 20–500 con contador vivo y
│                                            #     leyenda al exceder (RF-03.1).
│   ├── petitionInventoryComponent.js        # NUEVO: «Tus peticiones pendientes: N de 3»
│                                            #     con retirada directa (RF-03.8).
│   ├── runeSealComponent.js                 # REUTILIZADO: Sello Rúnico determinista de la casa.
│   ├── convalescenceBannerComponent.js      # REUTILIZADO: aritmética de días (RF-03.5).
│   └── navbarComponent.js                   # MODIFICADO: rótulo «Hermandades» + distintivo
│                                            #     «Tienes dictámenes a la espera» (RF-01.1).
├── api/
│   └── vestibuleClient.js                   # NUEVO: cliente fetch de los 4 endpoints.
└── views/lineageHallView.js                 # MODIFICADO: llamamiento al Vestíbulo en el
                                             #     Salón de los Linajes (doble vía, RF-01.1).
```

```
public/assets/css/components/
└── vestibule.css                            # NUEVO: Velo Arcano del Vestíbulo (RNF-01).
                                             #     Solo tokens de tokens.css (RNF-01 de
                                             #     SPEC-10); controles del kit de SPEC-02
                                             #     RF-08; cobertura por test_css_coverage.mjs.
```

### 1.3 Base de datos y migración

```
database/migrations/10_clan_vestibule.sql    # Migración idempotente (estilo 07_*, 09_*).
database/schema.sql                          # MODIFICADO: índice único + columna.
```

**Migración `10_clan_vestibule.sql` (idempotente):**

1. **Deduplicación de legado** (preflight del índice): para cada `(user_id,
   clan_id)` con más de una fila histórica, se conserva la más antigua y las
   restantes pasan a `status = 'cancelled'`… no basta: el índice único las
   bloquearía. Resolución canónica: se **archivan fuera de la tabla** — se
   mueven a `clan_applications_archive` (tabla espejo creada por la misma
   migración) conservando todas sus columnas. Los duplicados legados no son
   clausura: son eco de escrituras previas a la clausura.
2. `CREATE UNIQUE INDEX IF NOT EXISTS uq_clan_application_house
   ON clan_applications (user_id, clan_id)` — **la clausura por casa es un
   invariante físico**: cualquier fila histórica de esa casa para esa cuenta
   (pendiente, aprobada, rechazada o cancelada) bloquea una nueva petición
   (RF-03.1). El INSERT recibe la violación y el servicio discierne
   `APPLICATION_HOUSE_CLOSED` de `APPLICATION_ALREADY_PENDING`.
3. `ALTER TABLE clan_applications ADD COLUMN verdict_seen_at TEXT NULL` —
   instante en que el postulante contempló el veredicto; `NULL` con estado
   terminal = veredicto sin leer (rótulo del acceso, RF-01.1/RF-03.4).
4. `database/seeds.sql`: sin cambios (el canon de clanes y linajes no muta).

---

## 2. Modelo de Datos y Contratos de API REST

### 2.1 Modelo de datos

**Tabla `clan_applications` (adiciones):**

| Columna / Índice | Tipo | Semántica |
|---|---|---|
| `verdict_seen_at` | TEXT NULL (ISO 8601 UTC) | Instante en que el postulante leyó el veredicto. `NULL` + estado terminal = sin leer. Jamás se escribe sobre peticiones `pending`. |
| `uq_clan_application_house` | UNIQUE (user_id, clan_id) | **Clausura perpetua por casa y cuenta** (RF-03.1): una sola petición por casa en la vida de la cuenta, con el estado terminal que sea. |

Nada cambia en `users`, `clans` ni `clan_members`: la membresía sigue siendo
autoridad de `clan_members` (SPEC-07) y la lectura de la casa legada divergente
viene de ahí (excepción de RF-01.2), no de datos nuevos.

**Derivaciones en servidor (jamás columnas):** la aptitud (RF-01.7), los días
restantes de convalecencia (alza al entero superior) y el estado de tarjeta se
**calculan en el instante** de servir el catálogo — cero flags persistentes
que alguien deba apagar (RF-03.5, caso límite del vencimiento).

### 2.2 Contratos de API REST

**Cuatro códigos canónicos nuevos** (`ClanGovernanceException`, con leyenda
solemne en castellano y `recoveryAction` para el frontend):

| Código | HTTP | Cuando (según SPEC-10) |
|---|---|---|
| `CLAN_LINEAGE_MISMATCH` | 403 | Gesto (ingreso o postulación) hacia casa de linaje distinto del jurado, incluso por API directa (RF-01.2, RF-04.1). Jamás por lectura de catálogo. |
| `CLAN_LOYALTY_BOUND` | 403 | El adepto ya milita y apunta a otra casa: «Tu lealtad ya está empeñada en [casa]» (RF-02.3, hallazgo 4). **Enmienda al contrato de SPEC-07:** en la vía de adhesión (`applyToClan`) este código SUSTITUYE a `ALREADY_AFFILIATED`, que permanece canónico en la vía de fundación (`foundClan`). Los arneses de SPEC-07 que aserten `ALREADY_AFFILIATED` sobre `applyToClan` se realinean en su tarea. |
| `ADMIN_LINEAGE_REQUIRED` | 403 | Admin Supremo sin linaje en el catálogo o los gestos del Vestíbulo (RF-01.1, hallazgos 13/19). Ni `LINEAGE_OATH_REQUIRED` (está exento de la retención) ni `CLAN_LINEAGE_MISMATCH` (no hay linaje contra el que casar). |
| `APPLICATION_HOUSE_CLOSED` | 403 | Re-postulación sobre casa clausurada para esa cuenta (rechazo o retirada previos, RF-03.1/hallazgo 16). No consume cupo de pendientes. |

---

**Endpoint 1 — Estado del Vestíbulo (una sola carga, RNF-04):**

```
GET /api/v1/clans/vestibule
Autorización: sesión válida; el linaje se DERIVA de la sesión — el endpoint
NO admite parámetro de filtro alguno (RF-01.2).
```

| Código | `error.code` | Cuando |
|---|---|---|
| 200 | — | Estado servido (catálogo + aptitud + peticiones + veredictos). |
| 401 | `SESSION_EXPIRED` | Sin sesión válida. |
| 403 | `ADMIN_LINEAGE_REQUIRED` | Admin Supremo sin linaje (aviso solemne, RF-01.1). |

```json
{
  "success": true,
  "data": {
    "adeptState": {
      "lineage": "celestialTides",
      "membership": { "clanId": "cln_mareas", "clanName": "Mareas de Aether" },
      "aptitude": {
        "isApt": true,
        "vedado": null,
        "convalescenceDaysRemaining": 0,
        "pendingPetitionsCount": 1,
        "pendingPetitionsLimit": 3
      },
      "unreadVerdictsCount": 2
    },
    "myHouse": null,   // o: { "clanId": "…", "clanName": "…", "isLegacyDivergent": true,
                        //     "state": "active"|"archived" } — solo para el legado divergente
    "clans": [
      {
        "clanId": "cln_mareas",
        "name": "Mareas de Aether",
        "motto": "…",
        "coatOfArms": "cln_mareas",
        "lineageType": "celestialTides",
        "memberCount": 12,
        "memberLimit": 30,
        "admissionMode": "open",
        "admissionModeLabel": "Admisión abierta",
        "isRegent": false,
        "adeptRelation": "none",
        "gesture": "join",
        "vedadoLegend": null
      },
      {
        "clanId": "cln_tempestad",
        "name": "Tempestad Eterna",
        "admissionMode": "byApplication",
        "adeptRelation": "pending",
        "gesture": "withdraw",
        "petitionId": "app_9f2…"
      }
    ],
    "petitions": [
      {
        "applicationId": "app_9f2…",
        "clanId": "cln_tempestad",
        "clanName": "Tempestad Eterna",
        "status": "pending",
        "motivation": "…",
        "verdictMotive": null,
        "verdictSeen": true,
        "createdAt": "2026-09-20T10:15:00Z"
      }
    ]
  }
}
```

Notas de contrato: `coatOfArms` es el identificador que el Sello Rúnico
**codifica, jamás imprime** (SPEC-02 RF-07.3 — el DTO lo porta; el DOM jamás lo
deleta). `myHouse` aparece solo para el adepto legado divergente (excepción de
RF-01.2) como entrada única especial, con su estado heráldico por metal y
forma (bronce roto si `archived`). `gesture` enumera el gesto disponible
(`join` | `petition` | `withdraw` | `null` = vedado) y `vedadoLegend` porta la
leyenda solemne cuando procede (RF-03.5) — la interfaz pinta lo que el
santuario declara, jamás decide por su cuenta.

---

**Endpoint 2 — El rito de ambos regímenes (EXISTENTE, enmendado):**

```
POST /api/v1/clans/{id}/applications
Content-Type: application/json
CSRF: token de sesión.

{ "motivation": "…20–500 caracteres…", "receivedAt": "2026-09-20T10:15:00.123Z" }
```

`motivation` es obligatoria y se valida (20–500) SOLO cuando la casa es
`byApplication`; para casas `open` se ignora si llega (el rito de ingreso no
redacta). `receivedAt` es la estampa de llegada del gesto, opcional y acotada
a una ventana de tolerancia (±30 s) — alimenta el desempate de vacante (§3.2);
el servidor fija la suya si no llega o desconfía.

| Código | `error.code` | Cuando |
|---|---|---|
| 200 | — | Ingreso inmediato consumado (casa `open` con vacante) o petición registrada (`byApplication`). El payload distingue con `admittedNow: true/false`. |
| 400 | `INVALID_MOTIVATION` | Molde 20–500 excedido o incumplido en `byApplication` (RF-03.1). |
| 400 | `INSUFFICIENT_RANK` | Rol sin rango de adhesión (legado de SPEC-07). |
| 403 | `ADMIN_LINEAGE_REQUIRED` | Admin Supremo sin linaje (RF-01.1). |
| 403 | `CLAN_LINEAGE_MISMATCH` | Casa de otro linaje (RF-04.1, sustancia backend). |
| 403 | `CLAN_LOYALTY_BOUND` | Ya milita y apunta a otra casa (RF-02.3). |
| 403 | `APPLICATION_HOUSE_CLOSED` | Casa clausurada para la cuenta (RF-03.1). |
| 403 | `CONVALESCENCE_ACTIVE` | En convalecencia (leyenda de RF-03.5; la interfaz ya la mostraba vedada). |
| 404 | `CLAN_NOT_FOUND` / 410 `CLAN_ARCHIVED` | La casa mutó en vuelo (caso límite 2). |
| 409 | `CLAN_QUOTA_EXCEEDED` | Plenitud: carrera perdida (RF-02.2, caso límite 1). |
| 409 | `APPLICATION_ALREADY_PENDING` | Doble envío: idempotencia de petición (RF-03.1, caso límite 6). |
| 409 | `ALREADY_AFFILIATED` | Residuo teórico: carrera de doble ingreso ganada en otra transacción (el estado real mandó sobre la foto del catálogo). |

Respuesta 200 (ingreso inmediato, con residuales anuladas):

```json
{
  "success": true,
  "data": {
    "admittedNow": true,
    "membership": { "clanId": "cln_mareas", "joinedAt": "…" },
    "annulledResiduals": [
      { "applicationId": "app_…", "clanId": "cln_tempestad", "act": "CLAN_APPLICATION_RESIDUALS_ANNULLED" }
    ]
  }
}
```

---

**Endpoint 3 — Retirada del postulante (NUEVO, RF-03.3):**

```
POST /api/v1/clans/{id}/applications/{appId}/withdraw
CSRF: token de sesión.
```

| Código | Cuando |
|---|---|
| 200 | Retirada consumada: `status = 'cancelled'`, `resolved_at` fijado, cupo liberado, casa clausurada (la fila persiste — el índice único la vela), asiento en Bitácora. |
| 403 | `APPLICATION_NOT_FOUND` / la petición no es del actuante. |
| 409 | `APPLICATION_ALREADY_RESOLVED` — carrera con el dictamen (caso límite 5): la serialización por transacción deja un solo desenlace determinista. |

---

**Endpoint 4 — Veredicto contemplado (NUEVO, RF-03.4):**

```
POST /api/v1/clans/applications/{appId}/verdict-acknowledge
```

| Código | Cuando |
|---|---|
| 200 | `verdict_seen_at` fijado (solo si el estado es terminal y la petición es del actuante). Idempotente: reenvío → 200 sin mutación. |
| 404 | Petición ajena o inexistente. |

---

**Endpoint 5 — Contador del rótulo de navegación (NUEVO, RF-01.1):**

```
GET /api/v1/clans/verdicts/unread-count
```

| Código | Cuando |
|---|---|
| 200 | `{ "unreadVerdictsCount": 2 }` — consulta ligera para el distintivo del acceso al Vestíbulo. |
| 401 | Sin sesión. |

---

**Endpoint 6 — Dictamen del Patriarca (EXISTENTE, enmienda de reparto por actor):**

```
POST /api/v1/clans/{id}/applications/{appId}/resolve

{ "decision": "approve" | "reject", "motive": "…" }
```

**Enmienda a SPEC-07:** el rechazo EXIGE `motive` (20–500 caracteres) — el
Artículo III.3 exige motivo en todo asiento de veredicto, y el hallazgo 23 lo
ratificó. La aprobación no lo exige (el ingreso ES su motivo). El asiento
`CLAN_APPLICATION_VERDICT` (§2.3) lo inscribe aquí — lado deliberante — con
identidad, estampa temporal y motivo; el lado del postulante jamás lo duplica
(RF-04.4).

### 2.3 Catálogo de la Bitácora (RF-04.4, reparto por actor)

Cinco actos nuevos en el catálogo **cerrado** de `AuditEntry`
(`CANONICAL_ACTION_TYPES`), con su rótulo castellano en el mismo acto (el
aserto de rotulación de SPEC-03 vela el catálogo):

| Acto | Actor que lo inscribe | Momento |
|---|---|---|
| `CLAN_MEMBER_JOINED` | Lado del postulante (ingreso inmediato o aprobación) | La membresía nace. |
| `CLAN_APPLICATION_SUBMITTED` | Lado del postulante | Petición formal remitida. |
| `CLAN_APPLICATION_WITHDRAWN` | Lado del postulante | Retirada voluntaria (RF-03.3). |
| `CLAN_APPLICATION_RESIDUALS_ANNULLED` | Lado del postulante | Cada anulación de oficio por nacimiento de membresía (RF-03.7): un asiento por petición anulada, causa «la lealtad indivisible absuelve las peticiones huérfanas». |
| `CLAN_APPLICATION_VERDICT` | **Lado deliberante** (SPEC-07, Endpoint 6) | Dictamen con su `motive` (Artículo III.3). |

`target_entity_type = 'clan'` en todos; `target_entity_id` = la casa; el
justification nombra al postulante.

---

## 3. Algoritmos Clave y Máquinas de Estado

### 3.1 Aptitud conjuntiva derivada del instante (RF-01.7)

```
function computeAptitude(userId, now):
    lineage     ← SELECT lineage FROM users WHERE id = :userId
    membership  ← findActiveMembership(userId)
    convalescing ← isUserInConvalescence(userId, now)      // instante, jamás flag
    pending     ← countPendingApplications(userId)

    IF membership ≠ null:
        return { isApt: false, vedado: 'loyalty', house: membership.clanId }
    IF convalescing:
        return { isApt: false, vedado: 'convalescence',
                 daysRemaining: ceilDays(convalescenceExpiresAt, now) }   // alza al entero
    // apto = linajado + sin membresía + sin convalecencia (+ casa con vacante, por casa)
    return { isApt: lineage ≠ null, vedado: null, pending, limit: 3 }
```

`ceilDays` replica EXACTAMENTE `ClanMemberDto::convalescenceDaysRemaining`
(alza al día entero superior, frontera inclusiva): cliente y backend jamás
disienten sobre «restan X». El vencimiento es estado derivado del instante:
nadie apaga nada (RF-03.5, criterio del último día).

### 3.2 El rito unificado con sus tres guardias nuevas (RF-04.1, RF-02.3, RF-01.1)

`applyToClan()` conserva su orden de guardias y estrena tres, ANTES de tocar
persistencia:

```
function applyToClan(applicant, clanId, receivedAt, now):
    IF applicant.role = 'supremeAdmin' AND applicant.lineage = null:
        throw ADMIN_LINEAGE_REQUIRED                      // 403 (privilegio fundacional)
    clan ← requireClan(clanId); requireActiveClan(...)
    requireEligibleRank(applicant)
    requireFreedomFromConvalescence(applicant, now)
    IF findActiveMembership(applicant) ≠ null:
        throw CLAN_LOYALTY_BOUND(membership.clanName)     // 403 «Tu lealtad ya está empeñada…»
    IF clan.lineageType ≠ applicant.lineage:
        throw CLAN_LINEAGE_MISMATCH                       // 403 (RF-04.1, sustancia backend)
    assertVacancy(clanId)                                 // plenitud (RF-02.2)
    IF clan.admissionMode = 'byApplication':
        validateMotivation(payload.motivation)            // 20–500 (RF-03.1)
        return registerApplication(...)                   // guardia atómica existente
    return admitImmediately(...)                          // residuales anuladas (§0)
```

**El mismo `foundClan()` estrena el guardia de linaje** (`CLAN_LINEAGE_MISMATCH`
antes de reservar nombre): SPEC-09 RF-04.2 ata fundación y postulación al mismo
filtro. `ADMIN_LINEAGE_REQUIRED` le antecede igualmente.

### 3.3 Carrera de plenitud y desempate por llegada (RF-02.2, caso límite 8)

`admitImmediately()` ya corre dentro de una transacción atómica
(`runAtomically` → `countActiveMembers` + `addMember` guardado). La
**estampa de llegada** (`receivedAt` del Endpoint 2, fijada por el servidor
con tolerancia de ±30 s) se persiste en el asiento `CLAN_MEMBER_JOINED` y en
la respuesta; bajo el candado de escritura, los gestos se atienden por orden
de llegada y el perdedor recibe `CLAN_QUOTA_EXCEEDED` con la leyenda de
plenitud de SPEC-07. Desempate determinista y auditable: gana la estampa
temporal de llegada UTC más antigua — el mismo criterio del segundo
desempate de SPEC-07 RF-04.5. En SQLite (un escritor) la serialización es
inherente; en MySQL/MariaDB la transacción con lectura de censo en el mismo
candado la garantiza igual.

### 3.4 Máquina de estados de la petición (RF-03.1, RF-03.3, RF-03.4)

```
                     ┌─────────────── (única fila por casa y cuenta —
                     │               índice uq_clan_application_house)
                     v
[remisión] ──► [ pending ] ──resolve('approve')──► [ approved ] ─► membresía + residuales anuladas
                    |                                     (CLAN_MEMBER_JOINED + RESIDUALS_ANNULLED ×n)
                    |──resolve('reject', motive)──► [ rejected ]  (CLAN_APPLICATION_VERDICT, lado deliberante)
                    |──withdraw()────────────────► [ cancelled ] (CLAN_APPLICATION_WITHDRAWN, lado postulante)
                    |──casa disuelta en vuelo────► [ rejected ] (causa solemne «La hermandad se ha disuelto», caso límite 3)

TODO estado terminal + verdict_seen_at NULL ──► rótulo «Tienes dictámenes a la espera» (RF-01.1)
                                              └─acknowledge()─► verdict_seen_at fijado (idempotente)
CUALQUIER fila existente (user, clan) ──► casa CLAUSURADA: APPLICATION_HOUSE_CLOSED (RF-03.1)
```

La aprobación y el ingreso inmediato comparten un único desenlace: la
membresía nace y las residuales se anulan de oficio (el código de
`resolveApplication('approve')` y de `admitImmediately()` ya lo hace — este
plan solo añade los asientos por petición anulada).

### 3.5 Rótulo «Tienes dictámenes a la espera» (RF-01.1, RF-03.4)

```
HIDRATACIÓN (main.js):
    user ← store.getState().sessionUser
    IF user.lineage ≠ null:
        unread ← GET /api/v1/clans/verdicts/unread-count     // consulta ligera (RNF-04)
        navbar pinta el distintivo sii unread > 0
AL CONTEMPLAR en el Vestíbulo (veredicto visible en tarjeta o inventario):
    POST .../verdict-acknowledge por cada veredicto mostrado → el rótulo se apaga
REGLA: el rótulo vive en los DOS accesos (navegación y Salón); jamás bloquea
navegación alguna (ceremonia voluntaria, RF-01.6).
```

---

## 4. Arquitectura de Eventos y Componentes Frontend

**Sin framework externo (Artículo I):** `CustomEvent` sobre el contenedor de
la vista, al estilo del bus del simulador y de la ceremonia de SPEC-09.

| Evento | Emisor | Detalle | Consumidor |
|---|---|---|---|
| `vestibule:catalog-loaded` | `vestibuleView` | `{ state: VestibuleStateDto }` | Arnés, región viva |
| `vestibule:admission-opened` | `admissionModalComponent` | `{ clanId, mode }` | Vista, foco atrapado |
| `vestibule:admission-dismissed` | `admissionModalComponent` | `{}` | Vista (sin mutación, RF-02.1) |
| `vestibule:membership-created` | `vestibuleView` | `{ clanId, annulledResiduals }` | `main.js` (store, rótulos del navbar) |
| `vestibule:petition-composed` | `petitionComposerComponent` | `{ clanId, length }` | Vista (validación de molde) |
| `vestibule:petition-submitted` | `vestibuleView` | `{ clanId }` | Inventario, región viva |
| `vestibule:petition-withdrawn` | `vestibuleView` | `{ applicationId }` | Inventario, tarjeta |
| `vestibule:verdict-announced` | `vestibuleView` | `{ applicationId, decision }` | Región viva ARIA, rótulo |
| `vestibule:gesture-denied` | `vestibuleView` | `{ code, legend }` | Región viva (leyendas de RF-03.5) |
| `vestibule:catalog-failed` | `vestibuleView` | `{}` | Aviso «Las hermandades no responden» + reintento (RF-01.3) |
| `vestibule:unread-verdicts` | `main.js` | `{ count }` | `navbarComponent`, llamamiento del Salón |

**Componentes:**

- `vestibuleClanCardComponent` — tarjeta solemne: lema, Sello Rúnico
  (metal y forma por estado — SPEC-02 RF-07.4), «X de 30», régimen rotulado
  en castellano, corona y heráldica dorada del Regente (sin estilos ad hoc:
  los del kit de SPEC-07), estado del adepto y UN gesto o su leyenda vedada.
  Enfocable; Enter/espaciadora operan (RNF-03).
- `admissionModalComponent` — `<dialog>` nativo: nombre de la casa, lealtad
  indivisible y advertencia de convalecencia futura EN EL CUERPO (jamás letra
  menuda — RF-02.1), confirmación explícita, foco atrapado y devuelto,
  Escape = descarte seguro.
- `petitionComposerComponent` — `textarea` del kit de controles (SPEC-02
  RF-08) con contador vivo 0/500, leyenda solemne al exceder el molde y
  mínimo de 20 para remitir (RF-03.1).
- `petitionInventoryComponent` — apéndice «Tus peticiones pendientes: N de 3»
  con estado de cada solicitud, retirada directa y veredictos a la espera de
  lectura (RF-03.8).
- `vestibuleView` — orquestador: una carga, estados derivados pintados desde
  el DTO (la interfaz jamás decide), aviso de reintento ante fallo de catálogo,
  conducción de la intención de entrada al inventario.
- `main.js` / `navbarComponent` / `lineageHallView` — ruta nueva, rótulo
  «Hermandades», distintivo de dictámenes, llamamiento desde el Salón.

---

## 5. Decisiones Técnicas Justificadas y Alternativas Descartadas

1. **Un solo rito backend (`applyToClan`) para ambos regímenes.** El
   despacho `open → admitImmediately` / `byApplication → registerApplication`
   ya existe, probado y con guardias atómicas. *Descartada:* dos endpoints
   nuevos (`join` y `petition`) — duplicarían el canon de guardias para
   divergir en cuanto una regla cambiara; el Vestíbulo distingue los ritos
   por su MODAL, no por su plomería.
2. **Clausura por casa como índice físico UNIQUE, no como búsqueda.** Las
   filas ya persisten con estado terminal; el índice convierte la regla en
   invariante que ninguna vía (API directa incluida) burla. *Descartadas:*
   consulta previa `hasSealedHouse` (ventana de carrera) o columna booleana
   de clausura (una segunda verdad que divergiría de las filas).
3. **`CLAN_LOYALTY_BOUND` en la vía de adhesión; `ALREADY_AFFILIATED`
   permanece en la vía de fundación.** La SPEC-10 ratificó una leyenda propia
   para el gesto del militante (hallazgo 4) con su código; renombrar el
   código existente rompería el contrato REST vivo de SPEC-07 en todos sus
   consumidores. *Descartadas:* reutilizar `ALREADY_AFFILIATED` con otra
   leyenda (dos significados, un código — diagnóstico imposible) o unificar
   ambos códigos (enmienda mayor de SPEC-07 fuera del alcance de esta fase).
   **Enmienda declarada:** los arneses que aserten `ALREADY_AFFILIATED` sobre
   `applyToClan` se realinean en su tarea de implementación.
4. **Reparto por actor en la Bitácora.** El asiento del dictamen con su
   `motive` nace donde vive la deliberación (Endpoint 6 de SPEC-07); esta
   spec inscribe los actos del postulante. *Descartada:* inscripción
   centralizada en el Vestíbulo (invertiría la frontera de la exclusión 2 de
   la SPEC-10 y duplicaría asientos si el postulante jamás reabre la vista).
5. **Una sola petición por casa y cuenta (sin cooldown ni re-postulación).**
   Ratificada contra las alternativas de re-postulación libre y calma de 7
   días: el índice único no necesita contador nuevo ni job de expiración, y
   el peso solemne del gesto (cada casa, una vez) es el veredicto del hallazgo
   16. *Descartadas:* re-postulación libre (spam al Patriarca) y cooldown de
   7 días (columna + job que el índice físico hace innecesarios).
6. **El Patriarca como única guardia del texto.** Cero filtros de estilo en
   backend (Dogma Vanilla: juicio mecánico de anacronismos jamás sería
   exhaustivo); la validación se limita al MOLDE (20–500), que es
   determinista. *Descartada:* lista de patrones anacrónicos (imposible de
   completar y fácil de burlar).
7. **La casa legada divergente se lee de `clan_members`, no de datos nuevos.**
   La excepción de RF-01.2 es una PROYECCIÓN de la membresía activa existente;
   ni migración ni columna. `myHouse` viaja en el DTO con `isLegacyDivergent`
   para que la vista la trate como entrada única especial. *Descartada:*
   reasignar linajes legados (tocaría identidad perpetua de SPEC-09) u ocultar
   la casa (el adepto no contemplaría su propia casa).
8. **Día parcial con alza al entero superior, replicando la aritmética
   ratificada de `ClanMemberDto`.** El instante exacto gobierna en servidor
   para el veredicto; el alza es solo la voz de la leyenda. *Descartadas:*
   suelo («restan 1» con 25 horas — la puerta parece abierta un día antes) o
   aritmética nueva en el servicio (dos fuentes del mismo número).
9. **`receivedAt` opcional con tolerancia de ±30 s para el desempate.** El
   servidor fija la suya si el cliente no la aporta o desconfía: el criterio
   es la LLEGADA al santuario, no el reloj del cliente. *Descartadas:*
   confiar ciegamente en el reloj del cliente (manipulable) o ignorar la
   llegada y dejar el desenlace al planificador (indeterminista, hallazgo 6).
10. **Vista `vestibule` como ruta real (`#/vestibulo`), no capa modal global.**
    Deep-linkable, lista blanca del enrutador, historial del navegador
    respetado — el mismo patrón que la ceremonia de SPEC-09. *Descartada:*
    overlay sobre el Salón (rompería el botón atrás y la doble vía).
11. **El contador del rótulo como endpoint ligero propio, no sobrecarga del
    `auth/me`.** El distintivo de dictámenes es una preocupación de SPEC-10;
    meterlo en la sesión de SPEC-03 acoplaría dos contratos que evolucionan
    por separado. *Descartada:* añadir `unreadVerdictsCount` a `auth/me`
    (enmienda cruzada innecesaria) o sondeo con `setInterval` (prohibido por
    el patrón del plan de SPEC-07 — polling continuo).

---

## 6. Estrategia de Pruebas

### 6.1 Arneses backend (PHP, estilo `scratch/test_*.php`)

| Arnés | Cubre |
|---|---|
| `scratch/test_vestibule_service.php` | Catálogo derivado de sesión (solo linaje jurado, solo `active`); `myHouse` legada divergente; aptitud conjuntiva por instante; días con alza al entero superior; `unreadVerdictsCount`; peregrino → jamás servido (retención previa); Admin sin linaje → `ADMIN_LINEAGE_REQUIRED` (403). |
| `scratch/test_clan_admission_guards.php` | Los tres guardias nuevos de `applyToClan` y `foundClan`: `CLAN_LINEAGE_MISMATCH` (gesto y API directa, jamás en lectura); `CLAN_LOYALTY_BOUND` con la leyenda que nombra la casa; `CLAN_LOYALTY_BOUND` veda también la postulación del militante (hallazgo 18); `ALREADY_AFFILIATED` intacto en fundación (enmienda declarada); convalecencia con su leyenda. |
| `scratch/test_clan_application_closure.php` | Clausura por casa: rechazo y retirada clausuran sin consumir cupo; re-postulación → `APPLICATION_HOUSE_CLOSED` (403); molde 20–500 con `INVALID_MOTIVATION` en los tres bordes (19, 20, 501); retirada libera el cupo de 3. |
| `scratch/test_clan_admission_race.php` | Última vacante disputada: dos ingresos concurrentes → un solo adeptos, ganador por estampa de llegada más antigua, perdedor con leyenda de plenitud; casa que muta de régimen en vuelo → rechazo solemne, jamás conversión; doble envío idempotente. |
| `scratch/test_clan_vestibule_audit.php` | Reparto por actor: `CLAN_MEMBER_JOINED`, `CLAN_APPLICATION_SUBMITTED`, `CLAN_APPLICATION_WITHDRAWN`, `CLAN_APPLICATION_RESIDUALS_ANNULLED` (uno por petición anulada) inscritos por el postulante; `CLAN_APPLICATION_VERDICT` con `motive` por el lado deliberante; rechazo sin `motive` → 400; catálogo cerrado con rótulos castellanos (regresión del aserto de SPEC-03). |
| `scratch/test_vestibule_migration.php` | Migración 10 idempotente al re-ejecutar: deduplicación a archivo espejo; índice único creado; `verdict_seen_at` presente; acknowledge idempotente; rótulo se apaga al leer. |

### 6.2 Arneses frontend (Node ES Modules, estilo `scratch/test_*.mjs`)

| Arnés | Cubre |
|---|---|
| `scratch/test_vestibule_view.mjs` | Render del catálogo (lema, sello, «X de 30», régimen, corona del Regente sin estilos ad hoc); estado vacío «Ninguna hermandad ruega aún tu linaje» con invitación discreta; fallo de catálogo → «Las hermandades no responden» + reintento; `myHouse` como entrada única especial; doble vía (rótulo del navbar y llamamiento del Salón). |
| `scratch/test_vestibule_card_states.mjs` | Estados derivados de RF-01.7 pintados desde el DTO: gesto disponible, «Tu hermandad», «Pendiente de dictamen», leyendas vedadas (convalecencia con días reales, lealtad empeñada); el militante sin gesto alguno en las demás casas; la contemplación jamás se bloquea. |
| `scratch/test_admission_modal.mjs` | Modal del ingreso: advertencias de lealtad indivisible y convalecencia futura en el cuerpo (jamás letra menuda); descarte sin mutación; foco atrapado y devuelto; Escape; anuncios ARIA; `prefers-reduced-motion`. |
| `scratch/test_petition_composer.mjs` | Molde 20–500 con contador vivo; leyenda al exceder; mínimo de 20 para remitir; el texto jamás se filtra por estilo (solo molde). |
| `scratch/test_petition_inventory.mjs` | «Tus peticiones pendientes: N de 3»; retirada directa desde la lista; veredictos sin leer marcados; el rótulo del acceso se apaga al contemplarlos (acknowledge). |
| `scratch/test_vestibule_client.mjs` | Cliente `fetch`: mapeo de 200/400/403/404/409 a veredictos de la vista; errores solemnes con su leyenda canónica. |
| Regresión cruzada | Batería de clanes íntegra (`test_clan_*`, `test_dominion_*`, `test_membership_*`, `test_css_coverage.mjs`) — el realineamiento de `ALREADY_AFFILIATED` se verifica en la misma pasada. |

### 6.3 Verificación manual (navegador, contra el servidor de demo)

1. Adepto linajado ermitaño entra por el rótulo de navegación Y por el
   llamamiento del Salón: ambas vías conducen al mismo Vestíbulo.
2. Recorrer las tarjetas: sello por metal y forma, plenitud, régimen, corona
   del Regente si la casa manda.
3. Rito de ingreso en casa abierta: modal solemne, confirmar, residuales
   anuladas visibles en la Bitácora pública (`#/bitacora`).
4. Rito de petición en casa bajo dictamen: molde 20–500, remitir, retirar,
   constatar la clausura en un segundo intento.
5. Dictamen del Patriarca (segunda cuenta): aprobar; verificar asiento con
   motivo, rótulo del acceso y apagado al contemplarlo.
6. Estados vedados: adepto en convalecencia contempla todo con su leyenda;
   militante sin gesto en las demás casas.
7. Peregrino sin linaje por URL directa `#/vestibulo`: retención de SPEC-09;
   Admin Supremo sin linaje: aviso solemne y `ADMIN_LINEAGE_REQUIRED`.
8. Teclado completo, foco visible, `prefers-reduced-motion`, contraste, y
   cobertura CSS sin huérfanos.

---

## 7. Trazabilidad RF-x / RNF-x ↔ Plan

| Requisito | Puntos del plan | Prueba |
|---|---|---|
| RF-01.1 (vista voluntaria, doble vía, rótulo, peregrino, lector, Admin) | §1.1 middleware; §1.2 `main.js`, `navbarComponent`, `lineageHallView`; §2.2 Ep. 1/5; §3.5; §5.11 | `test_vestibule_service.php`, `test_vestibule_view.mjs` |
| RF-01.2 (filtro sin parámetro; excepción legada) | §2.2 Ep. 1 (derivado de sesión); §5.7 `myHouse`; §3.2 guardia en gestos | `test_vestibule_service.php`, `test_clan_admission_guards.php` |
| RF-01.3 (tarjeta completa; regente; fallo de catálogo) | §1.2 `vestibuleClanCardComponent`; §4; §6.3 | `test_vestibule_view.mjs` |
| RF-01.4 (estado vacío con invitación) | §1.2 `vestibuleView` | `test_vestibule_view.mjs` |
| RF-01.5 (contemplación abierta) | §3.1 aptitud solo veda gestos | `test_vestibule_card_states.mjs` |
| RF-01.6 (sin retención; ermitaño legítimo) | §0 (nada bloquea); §5.10 ruta real | `test_vestibule_view.mjs` (regresión de navegación) |
| RF-01.7 (aptitud conjuntiva derivada) | §3.1 pseudocódigo; §2.1 derivaciones | `test_vestibule_service.php` |
| RF-02.1 (modal de ingreso solemne) | §1.2 `admissionModalComponent`; §4 | `test_admission_modal.mjs` |
| RF-02.2 (revalidación de plenitud; carrera) | §3.3; §2.2 Ep. 2 (409) | `test_clan_admission_race.php` |
| RF-02.3 (idempotencia; lealtad empeñada) | §3.2 guardias; §2.2 `CLAN_LOYALTY_BOUND`; §5.3 | `test_clan_admission_guards.php`, `test_clan_admission_race.php` |
| RF-03.1 (petición formal; molde; una sola por casa) | §2.2 Ep. 2 (motivation); §1.3 índice único; §5.2/5.5 | `test_clan_application_closure.php` |
| RF-03.2 (límite de 3) | §0 (`PENDING_APPLICATIONS_LIMIT` existente); §2.2 | `test_clan_application_closure.php` (regresión) |
| RF-03.3 (retirada; clausura; Bitácora) | §2.2 Ep. 3; §3.4 máquina de estados | `test_clan_application_closure.php`, `test_clan_vestibule_audit.php` |
| RF-03.4 (veredicto; rótulo; reparto por actor) | §2.2 Ep. 4/6; §3.5; §2.3; §5.4 | `test_clan_vestibule_audit.php`, `test_petition_inventory.mjs` |
| RF-03.5 (leyendas vedadas; días con alza) | §3.1 `ceilDays`; §1.2 tarjeta; §2.2 DTO | `test_vestibule_card_states.mjs`, `test_vestibule_service.php` |
| RF-03.6 (sustancia blindada en servidor) | §3.2 guardias ANTES de persistencia; §5.2 índice físico | `test_clan_admission_guards.php` |
| RF-03.7 (residuales anuladas de oficio) | §0 (`cancelPendingApplications` existente) + asientos nuevos §2.3 | `test_clan_vestibule_audit.php` |
| RF-03.8 (inventario consolidado) | §1.2 `petitionInventoryComponent`; §2.2 Ep. 1 (`petitions`) | `test_petition_inventory.mjs` |
| RF-04.1 (filtro por linaje jurado en gestos y API) | §3.2 `CLAN_LINEAGE_MISMATCH` en `applyToClan` y `foundClan` | `test_clan_admission_guards.php` |
| RF-04.2 (mecánicas de SPEC-07 respetadas) | §0 reutilización sin redefinir; §5.3 enmienda declarada | Regresión: batería de clanes |
| RF-04.3 (sin interacción con el juramento) | §1.1 cadena de middleware (SPEC-09 precede); sin toques a `users.lineage` | `test_vestibule_service.php` (regresión) |
| RF-04.4 (Bitácora por reparto de actor) | §2.3 catálogo cerrado; §2.2 Ep. 6 enmendado | `test_clan_vestibule_audit.php` |
| RF-04.5 (contrato único de petición) | §2.2 Ep. 2/6 comparten `clan_applications` y su DTO | `test_clan_vestibule_audit.php` |
| RNF-01 (Velo Arcano, tokens) | §1.2 `vestibule.css` (cero literales); §5.10 | Revisión visual + `test_css_coverage.mjs` |
| RNF-02 (Soberanía lingüística) | Leyendas de §2.2 en castellano; identificadores en inglés; rótulos de §2.3 | `test_clan_vestibule_audit.php` (rótulos), `test_vestibule_view.mjs` |
| RNF-03 (WCAG 2.1 AA) | §4 foco atrapado/devuelto, regiones vivas, teclado; §6.3 punto 8 | `test_admission_modal.mjs`, `test_vestibule_card_states.mjs` |
| RNF-04 (rendimiento; una carga) | §2.2 Ep. 1 (sobre único); §3.5 contador ligero; §5.11 | Inspección + manual |
| RNF-05 (Dogma Vanilla) | §1.1 PDO preparado; §1.2 ES Modules; cero CDNs | `test_vestibule_migration.php` (regresión del guard) |
| RNF-06 (trazabilidad inmutable) | §2.3; §2.2 Ep. 3/6 | `test_clan_vestibule_audit.php` |

**Casos límite 1–15 de la SPEC-10 → cobertura:** 1 (§3.3), 2 (§2.2 Ep. 2
404/410 + `test_clan_admission_race`), 3 (§3.4 rama de casa disuelta), 4
(guardia de vacante en `resolveApplication`, existente), 5 (§2.2 Ep. 3 409 —
serialización por transacción), 6 (§2.2 idempotencias), 7 (§5.7 `myHouse`),
8 (§3.3 desempate), 9 (§6.3 punto 5 — reautenticación patrón SPEC-09), 10
(§1.1 retención previa), 11 (migración: filas ligadas a `user_id` con
`ON DELETE CASCADE` — nada hereda), 12 (§2.2 `ADMIN_LINEAGE_REQUIRED`), 13
(§3.4 clausura), 14 (§3.4 residuales), 15 (la petición liga a la casa —
ninguna mutación en sucesión; regresión de `PATRIARCH_INACTIVITY_SUCCESSION`).

---

## 8. Garantía de Dogma Vanilla y Dualidad Lingüística

- **Artículo I:** cero dependencias; PDO con consultas preparadas y
  *parameter binding* en todos los accesos nuevos
  (`ClanVestibuleService`, `ClanApplicationRepository` ampliado); frontend en
  ES Modules nativos con `<dialog>`, `CustomEvent` y Custom Properties;
  migración SQL idempotente sin procedimientos almacenados; nada de
  validadores, routers ni utilidades externas.
- **Artículo V:** clases y DTOs en `PascalCase` inglés (`ClanVestibuleService`,
  `VestibuleStateDto`, `AdmissionModalComponent`), métodos y variables
  `camelCase` inglés (`applyToClan`, `withdrawApplication`, `unreadVerdictsCount`,
  `isLegacyDivergent`), constantes `UPPER_SNAKE_CASE`
  (`CLAN_LINEAGE_MISMATCH`, `APPLICATION_HOUSE_CLOSED`); comentarios
  PHPDoc/JSDoc, leyendas solemnes, rótulos de la Bitácora, doctrinas de
  tarjeta y avisos («Las hermandades no responden», «Tienes dictámenes a la
  espera») en noble castellano. Los eventos del bus (`vestibule:*`) y los
  códigos de error en inglés; los mensajes que los portan, en castellano.

---

## Anexo A — Leyendas Solemnes Canónicas (RATIFICADAS)

> Textos de los nuevos veredictos y avisos; voz solemne, sin anacronismos
> (Artículo IV). Cada leyenda acompaña a su código canónico. **Estado:**
> ratificado tras revisión tonal del Arquitecto — alineado con la voz canónica
> en producción (`clanClient.js`, `clanView.js`, `clansPreviewView.js`);
> las tareas que las inscriban deben copiar el texto literal de este anexo.

1. **`CLAN_LINEAGE_MISMATCH`** — «Ese estandarte porta otro linaje: tu juramento te ata a las casas de tu propia sangre.»
2. **`CLAN_LOYALTY_BOUND`** — «Tu lealtad ya está empeñada en [casa]: solo renunciar a ella —y sobrevivir la convalecencia— abre de nuevo sus puertas.»
3. **`ADMIN_LINEAGE_REQUIRED`** — «El Privilegio Fundacional te exime del juramento; sin linaje jurado no hay hermandades que contemplar.»
4. **`APPLICATION_HOUSE_CLOSED`** — «Ya pronunciaste tu palabra ante esta casa: rechazada o retirada, quedó clausurada para ti. Otras puertas aguardan.»
5. **Casa que muta en vuelo** — «La casa ha mudado su rito: hoy exige petición formal.»
6. **Molde excedido** — «Tu petición desborda el pergamino: el Patriarca lee mejor lo breve.» (y, por defecto de mínimo: «Apenas es un susurro: dale cuerpo a tu vocación.»)
7. **Catálogo sin respuesta** — «La corriente de maná se ha interrumpido: las hermandades no responden.» (acción: «Volver a convocar».)
8. **Estado vacío** — «Ninguna hermandad de tu linaje aguarda aún en el santuario.» (invitación discreta: «Podrás ser quien funde la primera.»)
9. **Rótulo del acceso** — «Tienes dictámenes a la espera.»
10. **Inventario** — «Tus peticiones pendientes: N de 3.»
