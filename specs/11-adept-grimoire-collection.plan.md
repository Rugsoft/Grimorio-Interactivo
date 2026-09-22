# PLAN TÉCNICO — SPEC-11: Colección del Adepto (Grimorio Personal y Elogio Popular)

> **Estado:** Borrador para revisión (SDD) — pendiente de aprobación antes de cualquier código.
> **Fuente canónica:** [`specs/11-adept-grimoire-collection.spec.md`](11-adept-grimoire-collection.spec.md) (doble QA ratificada, Sección 9).
> **Fronteras juradas:** SPEC-07 intocada en su maquinaria de Dominio; SPEC-08 (retirada = `archived`, jamás borrado); SPEC-09 (intents y retención reutilizados con una enmienda menor declarada); SPEC-05 (lienzo de convocatoria sin variantes).

---

## 0. Radiografía del Terreno (lo que ya existe y se REUTILIZA)

| Artefacto vivo | Ubicación | Uso en esta spec |
|---|---|---|
| `favorites` (tabla con `UNIQUE(user_id, spell_id)`) | `database/schema.sql` | Mesa de votos del Elogio — **intocada** (RF-05.4). |
| `WeeklyDominionService::awardCommunityFavorite()` | `src/Services/WeeklyDominionService.php` | La gloria del elogio: se le construye SOLO la puerta REST (RF-04.2). Guardias vivas: militancia (`OWN_CLAN_FAVORITE`, deniega ANTES de insertar fila) y duplicado (`DUPLICATE_FAVORITE`). |
| `DominionAwardDto` (recibo de gloria) | `src/Dto/DominionAwardDto.php` | `ACTION_COMMUNITY_FAVORITE`, `REASON_OWN_CLAN_FAVORITE`, `REASON_DUPLICATE_FAVORITE` — el contrato de respuesta del elogio ya existe. |
| `GrimoireQueryService` + `GrimoireController` (`/api/v1/grimoire/spells?mode=…`) | `src/Services/GrimoireQueryService.php`, `src/Controllers/GrimoireController.php` | El listado canónico/ensayos se amplía con la tercera vía `mode=collection` y el estado embebido del adepto (RF-04.0). |
| `spellCardComponent` (tarjeta compartida) | `public/assets/js/components/spellCardComponent.js` | El gesto «Añadir al tomo» / «Elogiar» nace aquí, una sola vez (RF-04.0). |
| Intents de SPEC-09 (`pendingIntent`, `handleReservedAction`, `addToGrimoire` ya catalogado) | `public/assets/js/state/store.js`, `public/assets/js/main.js` | Retención del peregrino reutilizada tal cual; `givePraise` se añade al catálogo (enmienda menor a SPEC-09, RF-01.4). |
| `AuditService::recordAction` + `AuditEntry::CANONICAL_ACTION_TYPES` | `src/Services/AuditService.php`, `src/Models/AuditEntry.php` | Asiento de los dos actos (RF-06) con dos discriminadores nuevos. |
| `LineageOathMiddleware` (retención backend del peregrino) | `src/Middleware/LineageOathMiddleware.php` | El guard de linaje (403) para gestos de colección. |
| Ciclo de vida real de un hechizo | `spell_reviews.status`: `draft / experimental / validated / rejected / archived` | Mapa único de marcas del tomo (RF-03.2). |

---

## 1. Estructura de Módulos y Ficheros

### 1.1 Backend (PHP 8.2+, MVC ligero, PDO preparado)

```
src/
├── Controllers/
│   └── GrimoireCollectionController.php     [NUEVO] Endpoints del tomo y la puerta del elogio:
│                                            listCollection, collectSpell, discardSpell, praiseSpell.
├── Repositories/
│   └── GrimoireCollectionRepository.php     [NUEVO] Único canal PDO de `grimoire_collections`:
│                                            add (INSERT OR IGNORE idempotente), remove,
│                                            pageForUser, countForUser, existsForUser, spellIdsForUser.
├── Services/
│   ├── GrimoireCollectionService.php        [NUEVO] Rito del tomo: guardias de estado/linaje,
│                                            idempotencia, ensamblado de página con estados embebidos.
│   └── GrimoireQueryService.php             [AMPLIADO] tercera vía mode=collection + mapa de
                                            estados del adepto (collected, praised) embebido en DTO.
├── Dto/
│   ├── CollectionEntryDto.php               [NUEVO] Fila del tomo enriquecida: hechizo + marca solemne
│                                            + praiseStatus.
│   └── CollectionPageDto.php                [NUEVO] Página paginada: entries, total, page, limit, totalPages.
└── Models/
    └── AuditEntry.php                        [AMPLIADO] Dos actos canónicos nuevos (RF-06):
                                            'TOME_SEAL', 'TOME_PRAISE'.
```

**Frontera de responsabilidad (Dogma de capa):** `GrimoireCollectionService` NUNCA llama a `WeeklyDominionService` directamente; la puerta del elogio vive en `GrimoireCollectionController`, que inyecta ambos servicios y orquesta: guardias de colección → `awardCommunityFavorite` (SPEC-07, inyectada, sin modificación) → asiento de Bitácora. Así la maquinaria de Dominio queda físicamente sellada.

### 1.2 Frontend (Vanilla ES Modules, sin dependencias)

```
public/assets/js/
├── views/
│   └── grimoireCollectionView.js            [NUEVO] «Mi Grimorio»: carga del tomo paginado,
│                                            filtro por afinidad, estado vacío, paginación viva.
├── api/
│   └── grimoireCollectionClient.js          [NUEVO] Cliente fetch nativo de los 4 endpoints,
│                                            con mapping de códigos HTTP a veredictos solemnes.
├── components/
│   ├── spellCardComponent.js                [AMPLIADO] Gestos «Añadir al tomo» / «Elogiar» +
│                                            estados «Ya está en tu tomo» / «Ya rendiste homenaje» +
│                                            leyendas solemnes de vedado; estados embebidos del DTO.
│   ├── navbarComponent.js                   [AMPLIADO] Rótulo soberano «Mi Grimorio» (RF-02.1).
│   └── discardTomeEntryModalComponent.js    [NUEVO] Modal solemne de retirada (RF-02.4) con patrón
│                                            de foco de SPEC-02.
├── main.js                                   [AMPLIADO] Ruta '#/grimorio' → 'collection';
│                                            intent 'givePraise' en el catálogo de retención;
│                                            reanudación del acto concreto tras el juramento.
└── state/store.js                            [AMPLIADO] pendingIntent soporta targetSpellId para
                                            reanudar el acto (RF-01.4).
```

**CSS** (patrón de fichero por vista de SPEC-10):

```
public/assets/css/components/grimoire-collection.css   [NUEVO]
```

### 1.3 Base de datos y migración

```
database/migrations/11_grimoire_collections.sql   [NUEVO]
```

DDL (idempotente, patrón de las migraciones 09/10):

```sql
CREATE TABLE IF NOT EXISTS grimoire_collections (
    id         TEXT PRIMARY KEY,                          -- UUID v4
    user_id    TEXT NOT NULL REFERENCES users (id) ON DELETE CASCADE,  -- Purga de cuenta (RF-05.3)
    spell_id   TEXT NOT NULL REFERENCES spells (id) ON DELETE CASCADE,
    added_at   TEXT NOT NULL,                             -- Instante del sellado (ISO 8601 UTC)
    UNIQUE (user_id, spell_id)                            -- Un solo sellado por casa y adepto (RF-01.3)
);
CREATE INDEX IF NOT EXISTS idx_grimoire_collections_user_added
    ON grimoire_collections (user_id, added_at DESC);     -- RNF-01: latencia < 100 ms
```

La cascada hacia `users` (purga de cuenta, RF-05.3) y hacia `spells` (defensa de profundidad: la retirada del autor es `archived`, jamás DELETE, RF-05.5, así que jamás dispara en operación regular).

---

## 2. Modelo de Datos y Contratos de API REST

### 2.1 Modelo de datos

**Tabla `grimoire_collections`** (RF-05.4): la colección y el elogio son ritos distintos y cada invariante vive en su mesa. `favorites` queda como mesa de votos del Dominio, intocada. La fila del tomo no guarda gloria ni estado de homenaje: eso se DERIVA en lectura (hallazgo 5: el estado viaja embebido en los listados, no persiste duplicado).

**Enriquecimiento embebido (RF-04.0):** `GrimoireQueryService` amplía `GrimoirePageDto` con el objeto `adeptState` (camelCase, Artículo V), presente solo para sesión autenticada:

```json
{
  "id": "…", "slug": "…", "name": "…",
  "elementalAffinity": "fire", "circle": 3, "manaCost": 42,
  "status": "validated",
  "adeptState": { "collected": true, "praised": false }
}
```

### 2.2 Contratos de API REST (camelCase, códigos HTTP del santuario)

**Errores solemnes comunes** (cuerpo `{ "success": false, "error": { "code", "message" } }`):

| HTTP | Código | Cuándo |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Sin sesión (el flujo del umbral la retoma). |
| 403 | `LINEAGE_OATH_REQUIRED` | Gesto de colección/elogio sin linaje jurado (retención backend de SPEC-09). |
| 404 | `SPELL_NOT_FOUND` | Hechizo inexistente. |
| 409 | `SPELL_NOT_IN_TOME` | Retirada de un hechizo que no está en el tomo. |
| 409 | `PRAISE_SPELL_NOT_VALIDATED` | Elogio forzado sobre no validado (RF-04.5). |
| 200 (recibo denegado) | — | Militancia propia: el servicio de SPEC-07 responde con `reason: OWN_CLAN_FAVORITE` sin insertar fila (RF-04.4); la puerta lo traduce a leyenda, jamás a error. |

#### `GET /api/v1/grimoire/collection?element={afinidad}&page={n}` → 200

Tomo personal paginado (50/página, caso límite 4). Respuesta:

```json
{
  "success": true,
  "data": {
    "entries": [
      { "spell": { "…GrimoirePageDto…": "…" },
        "addedAt": "2026-09-22T10:00:00Z",
        "tomeMark": "living",          // 'living' | 'gestation' | 'withdrawn'
        "praiseStatus": { "praised": true, "allowed": false } }
    ],
    "total": 137, "page": 1, "limit": 50, "totalPages": 3
  }
}
```

- `tomeMark`: marca solemne derivada del mapa de estados (§3.1); `living` para `validated`.
- `praiseStatus.allowed = false` cuando la militancia veda el gesto (RF-04.4) o el estado no es `validated` (RF-04.5).

#### `POST /api/v1/grimoire/collection` → 201 | 200 | 403 | 404

Cuerpo `{ "spellId": "…" }`. Guardias en orden (§3.2): sesión → linaje → existencia → estado.

- 201: sellado con asiento de Bitácora `TOME_SEAL`.
- 200: idempotente — ya estaba (RF-01.3), sin segunda fila ni segundo asiento.
- 403 `LINEAGE_OATH_REQUIRED`: el peregrino (el front retiene `addToGrimoire` y conduce al juramento).

#### `DELETE /api/v1/grimoire/collection/{spellId}` → 200 | 409 | 403 | 404

Retirada del tomo. 200 con `{ "total": n }` actualizado. **Jamás toca `favorites`** (caso límite 9: elogio perpetuo).

#### `POST /api/v1/grimoire/praise` → 200 | 409 | 403 | 404

Cuerpo `{ "spellId": "…" }`. La puerta REST del Elogio Popular:

1. Guardias propios: sesión → linaje → existencia → `validated` (409 si no, RF-04.5).
2. Invocación de `WeeklyDominionService::awardCommunityFavorite($user, $spellId)` (SPEC-07, sin tocar).
3. Traducción del recibo al contrato de la interfaz:

```json
// Gloria acreditada o ya rendida (ambas 200, el voto vive en favorites):
{ "success": true, "data": { "praised": true,
    "awarded": { "points": 5, "hasSynergy": true },   // solo si gloria nueva
    "reason": "AWARDED" | "ALREADY_PRAISED" } }
// Militancia (200, recibo denegado vivo de SPEC-07 — RF-04.4):
{ "success": true, "data": { "praised": false, "reason": "OWN_CLAN_FAVORITE" } }
```

- 409 `PRAISE_SPELL_NOT_VALIDATED`: elogio forzado sobre no validado (único 409 nuevo; duplicado y militancia NO son errores — son estados).

### 2.3 Catálogo de la Bitácora (RF-06)

Dos actos canónicos nuevos en `AuditEntry::CANONICAL_ACTION_TYPES`, patrón de SPEC-10:

| actionType | targetEntityType | Actor | Justificación canónica |
|---|---|---|---|
| `TOME_SEAL` | `spell` | El adepto que sella | «{alias} selló {hechizo} en su tomo personal». |
| `TOME_PRAISE` | `spell` | El adepto que elogia | «{alias} rindió homenaje a {hechizo}, granjeando gloria a {clan}». |

`TOME_PRAISE` SOLO se asienta cuando hay gloria acreditada (`reason: AWARDED`): un acto que mueve PDA exige constancia de quién movió la gloria (RF-06.2); el eco idempotente y el recibo denegado jamás se asientan.

---

## 3. Algoritmos Clave y Máquinas de Estado

### 3.1 Mapa único de estados a marcas del tomo (RF-03.2)

```
función tomeMark(status):
    según status:
        'validated'              → 'living'       // convocatoria y lectura plenas
        'draft', 'experimental'  → 'gestation'    // «obra en gestación»
        'rejected', 'archived'   → 'withdrawn'    // «obra retirada del canon»
    fin
```

Garantía: el mapa vive UNA sola vez (método estático en `GrimoireCollectionService`); el frontend jamás duplica la lógica — recibe la marca en el DTO. Estados vedados para convocatoria: todo lo que no sea `living`. La entrada jamás se elimina de la colección por detrás (hallazgo 12/21).

### 3.2 El rito del sellado con sus guardias ordenadas (RF-01)

```
función collectSpell(adepto, spellId):
    1. guardia sesión          → 401 UNAUTHENTICATED
    2. guardia linaje jurado   → 403 LINEAGE_OATH_REQUIRED   // el linaje manda, no el rol (hallazgo 16)
    3. guardia existencia      → 404 SPELL_NOT_FOUND
    4. guardia idempotencia:
         SI existsForUser(adepto, spellId):
             devolver 200 ALREADY_IN_TOME          // sin segunda fila ni segundo asiento (RF-01.3)
    5. guardia estado:
         SI status(spell) ≠ 'validated':
             devolver leyenda UNIFORME «solo lo validado entra al tomo» (RF-01.2, sin nombrar estado)
    6. INSERT idempotente (INSERT OR IGNORE; la UNIQUE física resuelve la carrera
       de doble pestaña — caso límite 5) + asiento TOME_SEAL
    7. devolver 201 { total }
```

### 3.3 La puerta del elogio (RF-04.2/04.4/04.5)

```
función praiseSpell(adepto, spellId):
    1-3. guardias de sesión, linaje y existencia (como el sellado)
    4. SI status(spell) ≠ 'validated' → 409 PRAISE_SPELL_NOT_VALIDATED (RF-04.5)
    5. recibo = WeeklyDominionService::awardCommunityFavorite(adepto, spellId)
         // SPEC-07 aplica sus guardias VIVAS, sin modificación:
         //   militancia → OWN_CLAN_FAVORITE, deniega ANTES de insertar fila (RF-04.4)
         //   duplicado  → DUPLICATE_FAVORITE (RF-04.3)
    6. SI recibo.reason = AWARDED: asiento TOME_PRAISE en la Bitácora (RF-06.2)
    7. devolver 200 con praised/reason traducidos (§2.2)
```

Invariantes: el voto jamás se inserta desde esta spec (solo el servicio vivo escribe en `favorites`); la retirada del tomo jamás consulta ni toca `favorites` (hallazgo 13); la gloria es asiento histórico — cero revisión retroactiva por cambio de militancia (hallazgo 18, fuera de alcance).

### 3.4 Reanudación del acto tras el juramento (RF-01.4, caso límite 1)

```
Máquina de estados del intent (ampliación de SPEC-09):
    activar(adepto sin linaje, gesto G sobre hechizo H):
        pendingIntent = { action: 'addToGrimoire' | 'givePraise',
                          targetSlug: <vista origen>,
                          targetSpellId: H }        // campo NUEVO
        navegar a '#/juramento'
    veredicto 'oath:sealed':
        SI pendingIntent.action = 'addToGrimoire':
            POST /grimoire/collection {spellId: targetSpellId}   // el acto se COMPLETA solo
        SI pendingIntent.action = 'givePraise':
            POST /grimoire/praise {spellId: targetSpellId}
        navegar a targetSlug con el eco solemne del acto reanudado
```

`addToGrimoire` ya existe en el catálogo de intents (reutilizado tal cual); `givePraise` es enmienda menor y explícita a SPEC-09 (hallazgo 9). El campo `targetSpellId` amplía la forma de `pendingIntent` sin romper los intents existentes (compatibles: campos extra se ignoran).

### 3.5 Paginación viva del tomo (casos límite 4 y 10)

```
función reanudarPagina(páginaCorriente, tras retirar):
    total ← countForUser(adepto, filtro)
    totalPages ← máx(1, techo(total / 50))
    SI páginaCorriente > totalPages: navegar a totalPages   // última página viva, filtro conservado
    SI NO: refrescar la página corriente
```

La consulta usa el índice `(user_id, added_at DESC)` del RNF-01; el conteo total viaja SIEMPRE en la respuesta (`data.total`) para el rótulo visible.

### 3.6 Degradación tras 401 (RF-05.2)

La vista del tomo intercepta el 401 del cliente: muestra el aviso solemne del umbral, pone `disabled` + `aria-disabled` en sellado/retirada/elogio, y CONSERVA el DOM ya cargado y el filtro activo. Nada se vacía (hallazgo 7). La sesión muerta no degrada a «lectura pública»: es la sala íntima a oscuras, con lo leído aún a la vista.

---

## 4. Arquitectura de Eventos y Componentes Frontend

### 4.1 Ciclo de la vista «Mi Grimorio» (`grimoireCollectionView`)

```
montaje → GET /grimoire/collection?page&element
  ├─ 200 → pintar entradas (spellCardComponent + marca solemne + praiseStatus)
  │         rótulo «N entradas · página X de Y» + filtro por afinidad
  ├─ lista vacía → estado de invitación «Tu tomo aguarda su primera obra»
  │                + gesto directo hacia #/biblioteca (RF-02.2, sin lenguaje de error)
  └─ 401 → degradación solemne (§3.6)
```

Eventos en el bus del shell (patrón del Vestíbulo):

| Evento | Emite | Escucha |
|---|---|---|
| `collection:changed` | la vista tras sellar/retirar (actualiza `total`) | la propia vista, el badge de la navbar si existiera |
| `tome:seal` / `tome:praise` | `spellCardComponent` al activar el gesto | `libraryView` y `grimoireSimulatorView` (delegan al cliente y muestran el eco) |
| `oath:sealed` | la ceremonia (SPEC-09) | `main.js` → reanudación del acto (§3.4) |

### 4.2 El gesto compartido en `spellCardComponent` (RF-04.0)

La tarjeta recibe en su DTO el `adeptState` (o `praiseStatus`/`tomeMark` en el tomo) y decide el gesto UNA sola vez, para Biblioteca, Simulador y Tomo:

- `collected: false` y estado `validated` → botón «Añadir al tomo».
- `collected: true` → estado «Ya está en tu tomo» (no botón; conmutador informativo, `aria-pressed`).
- `praised: true` → estado «Ya rendiste homenaje».
- militancia en la casa del hechizo → gesto «Elogiar» ausente + leyenda sobria canónica (RF-04.4).
- estado ≠ `validated` → ni «Añadir» ni «Elogiar»; en el tomo, la marca solemne del mapa (§3.1).

Accesibilidad (RNF-04): activables por teclado, foco visible, `aria-pressed` en los conmutadores de estado; el modal de retirada atrapa y devuelve el foco (patrón SPEC-02). RNF-05: sellado y eco de elogio degradan a opacidad bajo `prefers-reduced-motion`.

### 4.3 Rótulos y leyendas canónicas (RNF-03, Velo Arcano)

| Rótulo / leyenda | Dónde |
|---|---|
| «Mi Grimorio» | navbar (rótulo soberano, RF-02.1). |
| «Tu tomo aguarda su primera obra» + «Recorrer la Biblioteca» | estado vacío (RF-02.2). |
| «Ya está en tu tomo» | conmutador de colección (RF-01.3). |
| «Ya rendiste homenaje» | conmutador de elogio (RF-04.3). |
| «Solo lo que el Tribunal ha sellado entra al tomo» | leyenda UNIFORME del vedado de sellado (RF-01.2). |
| «Un adepto de la casa no granjea gloria para su propio estandarte» | leyenda de militancia (RF-04.4). |
| «Obra en gestación» / «Obra apartada del canon» | marcas solemnes del tomo (RF-03.2). |
| «Esta obra dejará tu tomo para siempre: medítalo antes de firmar.» | modal de retirada (RF-02.4). |
| «Tu vínculo con el santuario ha expirado: renuévalo y tus gestos aguardarán donde los dejaste.» | aviso 401 (RF-05.2). |
| «{hechizo} queda sellado en tu tomo.» | eco del sellado (RF-01.1). |
| «Tu homenaje a {hechizo} ya resuena en su casa.» | eco del homenaje (RF-04.1). |
| «La gloria solo nace de obra sellada por el Tribunal.» | mapeo del cliente ante el 409 forzado (RF-04.5). |

Cero referencias técnicas visibles (RF-xx, códigos HTTP): el guard de soberanía lingüística de SPEC-03 extiende su auditoría a los módulos nuevos.

---

## 5. Decisiones Técnicas Justificadas y Alternativas Descartadas

| Decisión | Alternativa descartada | Justificación |
|---|---|---|
| **Tabla nueva `grimoire_collections`** (RF-05.4, hallazgo 1) | Reutilizar `favorites` con discriminador | Colección y elogio son ritos distintos: la mesa de votos del Dominio lleva las guardias y la gloria; la del tomo, la memoria íntima. Mezclarlas acopla la purga de cuenta al Dominio y complica toda cascada futura. |
| **Estado del adepto derivado en lectura** (RF-04.0) | Persistir `praised` en la fila del tomo | El voto ya vive en `favorites` con su UNIQUE; duplicarlo crea dos verdades que divergen. Derivar en la consulta del listado (un LEFT JOIN por userId de sesión) mantiene una sola fuente. |
| **Ritos separados: sellado sin gloria** (hallazgos 2-3) | Sellado acredita elogio automático | Acoplar la gloria al gesto íntimo obligaría a deshacer PDA en cada retirada del tomo — toque prohibido a SPEC-07. Cada rito vive su vida (caso límite 9). |
| **El linaje manda, no el rol** (hallazgo 16) | Gatear por rol (`editor`+) | El juramento es la identidad arcana (SPEC-09); un `lector` linajado es coleccionista de pleno derecho. Una sola regla, un solo guard. |
| **`givePraise` como enmienda menor a SPEC-09** (hallazgo 9) | Renombrar `addToGrimoire` | El catálogo vivo de intents ya despacha `addToGrimoire`; renombrar es migración sin beneficio. La enmienda se declara explícita y solo AÑADE. |
| **Guardia de militancia respetada con recibo denegado** (hallazgos 10-11) | Insertar voto y denegar solo la gloria | El servicio vivo de SPEC-07 deniega ANTES de insertar fila; alterarlo rompe un contrato ratificado. La puerta REST traduce el recibo a leyenda, jamás a error HTTP. |
| **Retirada del autor = `archived`, nunca DELETE** (RF-05.5, hallazgo 14) | Aceptar el borrado físico y su cascada | La promesa «jamás se disuelve por detrás» exige que la retirada no dispare las cascadas de `favorites` ni del tomo. Frontera declarada con SPEC-08. |
| **Elogio perpetuo tras retirar del tomo** (hallazgo 13) | Anular el homenaje con la retirada | El elogio es gloria para el clan del autor, no una pata de la colección; anularlo exigiría un rito de reversión que SPEC-07 jamás contempló. |
| **Militancia como estado, no error** (§2.2) | 403 para `OWN_CLAN_FAVORITE` | El adepto no hizo nada malo: la casa no hincha su propio estandarte. Error HTTP estigmatizaría; el recibo denegado con leyenda sobria honra el Velo Arcano. |
| **`tomeMark` decidido en backend** (§3.1) | Mapear estados en el frontend | Un solo mapa, una sola verdad: el front jamás duplica la lógica de ciclo de vida (que ya mentiría dos veces si SPEC-08 cambia). |
| **Revisión retroactiva de gloria: fuera de alcance** (hallazgo 18) | Recalcular al cambiar de clan | Es patrimonio exclusivo del ciclo de SPEC-07; esta spec solo construye su puerta. Frontera declarada en la Sección 7 de la spec. |

---

## 6. Estrategia de Pruebas

### 6.1 Arneses backend (PHP, estilo `scratch/test_*.php`)

| Arnés | Cubre |
|---|---|
| `scratch/test_grimoire_collection_repository.php` | DDL idempotente; UNIQUE `(user_id, spell_id)` física; índice `(user_id, added_at)`; cascada por purga de cuenta (RF-05.3); orden `added_at DESC`. |
| `scratch/test_grimoire_collection_service.php` | Rito del sellado con guardias ordenadas (401→403→404→200 idempotente→201); leyenda UNIFORME ante cualquier no validado; mapa de marcas completo (los 5 estados); idempotencia por carrera de doble pestaña (caso límite 5). |
| `scratch/test_grimoire_collection_controller.php` | REST íntegro: 201/200/403/404/409; camelCase en claves (guard Artículo V); `LINEAGE_OATH_REQUIRED` del peregrino; retirada con `total` actualizado y 409 `SPELL_NOT_IN_TOME`; la retirada jamás toca `favorites` (caso límite 9). |
| `scratch/test_praise_gateway.php` | La puerta del elogio: gloria acreditada (`AWARDED`, 5 PDA + sinergia); duplicado → `ALREADY_PRAISED` sin segunda gloria (RF-04.3); militancia → recibo denegado `OWN_CLAN_FAVORITE` sin fila en `favorites` y sin error HTTP (RF-04.4, hallazgo 10); no validado → 409 (RF-04.5); cierre de ciclo semanal → la gloria cae en el ciclo correcto (caso límite 8). |
| `scratch/test_tome_audit.php` | Asientos `TOME_SEAL` y `TOME_PRAISE` (uno por acto); el eco idempotente y el recibo denegado jamás se asientan; catálogo cerrado y rótulos castellanos (regresión del guard de SPEC-03). |
| `scratch/test_collection_query_embedding.php` | `mode=collection` en `GrimoireQueryService`; `adeptState` embebido solo con sesión; latencia de la página < 100 ms (RNF-01, arnés de presupuesto del RNF-02 de SPEC-06). |

### 6.2 Arneses frontend (Node ES Modules, estilo `scratch/test_*.mjs`)

| Arnés | Cubre |
|---|---|
| `scratch/test_grimoire_collection_view.mjs` | Carga única del tomo paginado; filtro por afinidad con conteo; estado vacío con invitación a la Biblioteca; paginación viva (última página tras retirada, caso límite 10); 401 → aviso solemne + gestos apagados + lectura y filtro conservados (RF-05.2). |
| `scratch/test_spell_card_tome_gestures.mjs` | El gesto compartido: «Añadir al tomo» / «Ya está en tu tomo» / «Ya rendiste homenaje»; gesto ausente + leyenda por militancia; gestos ausentes sobre no validados; `aria-pressed`, teclado, foco visible (RNF-04); `prefers-reduced-motion` (RNF-05). |
| `scratch/test_discard_tome_modal.mjs` | Modal solemne de retirada: confirmación, foco atrapado y devuelto, Escape, sin mutación al descartar (RF-02.4). |
| `scratch/test_grimoire_collection_client.mjs` | Cliente fetch: mapeo de 200/201/403/404/409 y del recibo denegado a veredictos de la vista; errores solemnes con leyenda canónica. |
| `scratch/test_intent_give_praise.mjs` | Retención de ambos gestos en el peregrino; `targetSpellId` en el store; reanudación del acto concreto tras `oath:sealed` (RF-01.4, caso límite 1); regresión de los intents previos de SPEC-09 (compatibilidad de forma). |
| Regresión cruzada | Batería de la familia grimoire/dominion/clanes + guard de soberanía lingüística extendido a los módulos nuevos (RNF-03). |

### 6.3 Verificación manual (navegador, contra el servidor de demo)

1. Adepto linajado: sellar desde la Biblioteca y desde el Simulador; verificar «Ya está en tu tomo» y el asiento `TOME_SEAL` en `#/bitacora`.
2. Abrir «Mi Grimorio» por navbar y por el rótulo «Ver mi libro personal» del umbral: ambas vías aterrizan en la vista propia (hallazgo 5 de la primera ronda).
3. Convocar desde el tomo: modal de casta ya desplegado, partículas y conjuro intactos (RF-03.1, SPEC-05 sin variantes).
4. Elogiar un hechizo ajeno: eco solemne, «Ya rendiste homenaje», asiento `TOME_PRAISE`, gloria visible en el Dominio.
5. Militante de la casa: gesto ausente con leyenda; doble pestaña y recarga: estados estables (embebidos).
6. Hechizo coleccionado que cae de estado: marcas solemnes correctas, convocatoria y elogio vedados, entrada íntegra.
7. Peregrino (cuenta nueva): gesto de sellado y de elogio → juramento → el acto se completa solo tras sellarlo.
8. Retirada con modal; última página intermedia vaciada → reanudación en la página viva; elogio previo intacto.
9. Sesión expirada con el tomo abierto: aviso solemne, gestos apagados, lectura y filtro conservados.
10. Teclado completo, foco visible, `prefers-reduced-motion`, contraste; cobertura CSS sin huérfanos.

---

## 7. Trazabilidad RF-x / RNF-x ↔ Plan

| Requisito | Cobertura en el plan |
|---|---|
| RF-01.1 | §3.2 (rito del sellado), §2.2 (`POST /collection` → 201), §4.2 (gesto en tarjeta), arnés 6.1-fila 2. |
| RF-01.2 | §3.2 guardia 5 (leyenda UNIFORME), §4.3 (leyenda canónica), arneses 6.1-fila 2 y 6.2-fila 2. |
| RF-01.3 | §3.2 guardia 4, §2.2 (200 idempotente), UNIQUE física (§1.3), caso límite 5, arnés 6.1-fila 2. |
| RF-01.4 | §3.4 (máquina de intents + `targetSpellId`), §4.3, arnés 6.2-fila 5. |
| RF-02.1 | §4.1 (vista + rótulo navbar), §2.2 (`GET /collection` con estados embebidos), arnés 6.2-fila 1. |
| RF-02.2 | §4.1 estado vacío con invitación, §4.3, arnés 6.2-fila 1. |
| RF-02.3 | §2.2 (`?element=`), §3.5 (filtro conservado), arnés 6.2-fila 1. |
| RF-02.4 | §4.2 modal de retirada, §2.2 (`DELETE`), §3.5 paginación viva, arneses 6.2-filas 1 y 3. |
| RF-03.1 | §4.1 convocatoria directa al Simulador (SPEC-05 sin variantes), verificación manual 6.3-3. |
| RF-03.2 | §3.1 mapa único de marcas, §2.2 (`tomeMark` en DTO), arneses 6.1-fila 2 y 6.2-fila 2. |
| RF-04.0 | §2.1 `adeptState` embebido, §4.2 gesto compartido único, arnés 6.1-fila 6. |
| RF-04.1 | §2.2 `POST /praise` (200), §3.3, arnés 6.1-fila 4. |
| RF-04.2 | §1.1 frontera de responsabilidad (puerta REST), §3.3 paso 5 (servicio vivo), arnés 6.1-fila 4. |
| RF-04.3 | §3.3 (`DUPLICATE_FAVORITE` → `ALREADY_PRAISED`), §4.3, arnés 6.1-fila 4. |
| RF-04.4 | §3.3 (recibo denegado sin fila), §2.2 (militancia como estado), §4.2 (gesto ausente + leyenda), arnés 6.1-fila 4. |
| RF-04.5 | §3.3 guardia 4 (409), §4.2 (gesto ausente sobre no validado), arnés 6.1-fila 4. |
| RF-05.1 | §2.2 completo (camelCase + códigos HTTP), guard del Artículo V en arnés 6.1-fila 3. |
| RF-05.2 | §3.6 degradación solemne, arnés 6.2-fila 1, manual 6.3-9. |
| RF-05.3 | §1.3 cascada de purga, arnés 6.1-fila 1. |
| RF-05.4 | §1.3 tabla nueva, decisión §5 fila 1, arnés 6.1-fila 1. |
| RF-05.5 | Decisión §5 fila 7 (archived, nunca DELETE), frontera con SPEC-08, arnés 6.1-fila 3. |
| RF-06.1 | §2.3 (`TOME_SEAL`), arnés 6.1-fila 5. |
| RF-06.2 | §2.3 (`TOME_PRAISE` solo con gloria), arnés 6.1-fila 5. |
| RNF-01 | §1.3 índice dedicado, §3.5, arnés de latencia 6.1-fila 6. |
| RNF-02 | §1.1/1.2 (PDO preparado, ES Modules), §0 (partículas de SPEC-05 jamás duplicadas). |
| RNF-03 | §4.3 (leyendas canónicas), guard de soberanía extendido (6.1-fila 3, 6.2-regresión). |
| RNF-04 | §4.2 accesibilidad, arnés 6.2-fila 2, manual 6.3-10. |
| RNF-05 | §4.2 reduced-motion, arnés 6.2-fila 2. |

**Casos límite 1–12:** 1→§3.4 · 2→RF-05.5/§5-7 · 3→RF-04.4/§3.3 · 4→§3.5 · 5→§3.2-6 · 6→§3.6 · 7→§3.1+RF-04.5 · 8→§3.3 (instante del servicio) · 9→§3.3 invariante + arnés 6.1-fila 3 · 10→§3.5 · 11→§5 fila 11 (fuera de alcance) · 12→guard de linaje §3.2-2.
**Hallazgos 1–21 (Sección 9 de la spec):** ratificados en las decisiones §5 (filas 1–11), el mapa de marcas §3.1, la máquina de intents §3.4 y el catálogo de Bitácora §2.3.
**Fronteras:** SPEC-07 intocada (§1.1, §3.3) · SPEC-08 retirada=archived (§5 fila 7) · SPEC-09 intents (§3.4, enmienda `givePraise` declarada) · SPEC-05 lienzo sin variantes (§4.1, RF-03.1).

---

## 8. Garantía de Dogma Vanilla y Dualidad Lingüística

- **Artículo I:** cero dependencias npm/composer/CDN en los módulos nuevos; PDO exclusivamente preparado en el repositorio nuevo; ES Modules nativos; el motor de partículas del Simulador se convoca, jamás se duplica ni se toca.
- **Artículo III:** el asiento de Bitácora de los dos actos (RF-06) con el servicio INSERT-puro y triggers anti-mutación ya vigentes.
- **Artículo IV:** las marcas y leyendas canónicas (§4.3) en noble castellano, sin anacronismos; la militancia se comunica como estado solemne, jamás como error.
- **Artículo V:** identificadores en inglés `camelCase`/`PascalCase`/`UPPER_SNAKE_CASE`; comentarios PHPDoc/JSDoc, rótulos, leyendas y marcas solemnes en castellano; **claves JSON camelCase** (hallazgos 8/19).
- **Artículo VI:** este plan precede a todo código; la tríada (spec→plan→tasks) queda completa solo con el `tasks.md` posterior a la aprobación de este documento.

---

## Anexo A — Leyendas Solemnes Canónicas (RATIFICADAS)

> Textos de los nuevos rótulos, leyendas y avisos de la superficie de
> colección; voz solemne, sin anacronismos (Artículo IV). Cada leyenda
> acompaña a su criterio. **Estado:** ratificado tras revisión tonal del
> Arquitecto — alineado con la voz canónica en producción
> (`vestibuleClient.js`, `lineageOathClient.js`, `clanClient.js`,
> `grimoireSimulatorView.js`, `lineageOathView.js`); las tareas que las
> inscriban (5.1, 5.3 y 4.2) deben copiar el texto LITERAL de este anexo.

1. **Rótulo soberano (RF-02.1)** — «Mi Grimorio».
2. **Estado vacío (RF-02.2)** — «Tu tomo aguarda su primera obra.» (invitación discreta: «Recorrer la Biblioteca».)
3. **Conmutador de colección (RF-01.3)** — «Ya está en tu tomo».
4. **Conmutador de elogio (RF-04.3)** — «Ya rendiste homenaje».
5. **Vedado de sellado, UNIFORME ante cualquier no validado (RF-01.2)** — «Solo lo que el Tribunal ha sellado entra al tomo.»
6. **Leyenda de militancia (RF-04.4)** — «Un adepto de la casa no granjea gloria para su propio estandarte.»
7. **Marcas solemnes del tomo (RF-03.2)** — «Obra en gestación» (draft/experimental) · «Obra apartada del canon» (rejected/archived). "Apartada" y no "retirada": el verbo queda reservado al acto del adepto (RF-02.4) y al del autor (RF-05.5).
8. **Modal de retirada (RF-02.4)** — «Esta obra dejará tu tomo para siempre: medítalo antes de firmar.»
9. **Aviso 401 (RF-05.2)** — «Tu vínculo con el santuario ha expirado: renuévalo y tus gestos aguardarán donde los dejaste.»
10. **Eco del sellado (RF-01.1)** — «{hechizo} queda sellado en tu tomo.»
11. **Eco del homenaje (RF-04.1)** — «Tu homenaje a {hechizo} ya resuena en su casa.»
12. **Vedado de gloria, mapeo del cliente ante el 409 forzado (RF-04.5)** — «La gloria solo nace de obra sellada por el Tribunal.» (jamás visible por la ocultación del gesto; contrato de cliente completo).
