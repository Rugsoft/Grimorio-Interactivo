# SPEC-18: El Sobre que Olvidaba la Casa — `clanId` ausente en la respuesta de vínculo

> **Constitución:** [`constitution.md`](../constitution.md) | **Directrices:** [`AGENTS.md`](../AGENTS.md)
> **Espec madre:** [`specs/03-auth-rbac.spec.md`](03-auth-rbac.spec.md) (Endpoint 2 «Renovar Vínculo», plan §2.2) · [`specs/09-lineage-oath-first-access.spec.md`](09-lineage-oath-first-access.spec.md) (peregrino) · [`specs/07-clans-lineages.spec.md`](07-clans-lineages.spec.md) (pertenencia) · [`specs/12-user-panel.spec.md`](12-user-panel.spec.md) (efigie, RF-03.3)
> **Espec que la descubre:** [`specs/17-hero-contextual-call-to-action.spec.md`](17-hero-contextual-call-to-action.spec.md), §8.1, hallazgo nº2
> **Estado:** EJECUTADA Y CERRADA (2026-09-29) — ratificada, **3/3 tareas**, **11/11 criterios de §8** con evidencia (uno añadido por la Tarea 0). Arnés **23/0**, prueba de mutación **5/5**, **264** asertos PHP en ocho de los nueve arneses de `AuthController`, **112/112** arneses `.mjs`. Superficie: **un solo fichero de producción**. Divergencia declarada en §8, criterio 11. Hallazgo de la Tarea 0: la prueba de mutación descubrió un requisito que esta spec no declaraba, y se añadió a `RF-18.1`.
> **Tareas:** [`specs/18-bind-envelope-clan-identity.tasks.md`](18-bind-envelope-clan-identity.tasks.md)
> **Área:** Contrato de sesión del backend (PHP 8.2, PDO nativo, MVC ligero)
> **Naturaleza:** CORRECCIÓN. No añade superficie, no cambia rutas, no altera códigos HTTP ni formas de error. Repara un campo que el contrato ya prometía.

---

## 1. Contexto y Objetivo

`POST /api/v1/auth/bind` —«Renovar Vínculo», el login— devuelve un sobre `data.user` con **`clanId: null` y `clanName: ""` para todos los usuarios, tengan hermandad o no**. `GET /api/v1/auth/session` devuelve el mismo usuario con los dos campos correctos. Los dos endpoints se contradicen, y el frontend se queda con el que se le entrega primero.

### 1.1 La causa, medida y no opinada

`forgeUserPayload()` recibe la fila de `users` y extrae cada campo con un cierre que busca la clave **por el nombre que tiene en el JSON**:

```php
$fieldValue = static function (string $key) use ($userRow, $activeUser): mixed {
    if (is_array($userRow) && array_key_exists($key, $userRow)) {
        return $userRow[$key];          // ← busca 'clanId'
    }
    return match ($key) {
        'id'     => $activeUser?->getId(),
        'alias'  => $activeUser?->getAlias(),
        'role'   => $activeUser?->getRole(),
        'clanId' => $activeUser?->getClanId(),   // ← cae aquí siempre
        default  => $activeUser?->getLineage(),
    };
};
```

La fila trae las columnas **`clan_id`**, en `snake_case` (Art. V, y el dialecto de base de datos). `array_key_exists('clanId', $userRow)` es por tanto **siempre `false`**, y el cierre cae al respaldo.

Cinco campos pasan por el cierre: `id`, `alias`, `role`, `clanId` y `lineage`. Cuatro tienen el mismo nombre que su columna y por eso se resuelven solos; **`clanId` es el único cuyo nombre de contrato no coincide con el de su columna**, y el único roto. El octavo campo del sobre, `avatar`, ni siquiera pasa por el cierre: se lee aparte y por eso nunca ha fallado. Esa es la medida: un campo de cinco, y el que se nota.

### 1.2 Por qué `session` sí funciona y `bind` no

Los dos endpoints llaman al mismo cierre. La diferencia está en el **segundo argumento**:

| Endpoint | `$userRow` | `$activeUser` | `clanId` resultante |
|---|---|---|---|
| `POST /auth/bind` (línea 224) | `fetchUserRow($userId)` | **`null`** (no se pasa) | `null` — **siempre** |
| `GET /auth/session` (línea 318) | `fetchUserRow($activeUser->getId())` | `AuthMiddleware` lo inyecta | correcto |

`AuthMiddleware` materializa la entidad `User` con `User::fromDatabaseRow()`, y ahí **sí** se traduce `clan_id → clanId` correctamente (`src/Models/User.php:193`). Así que `session` no depende de la fila: depende de la entidad, que es correcta. `bind` no tiene entidad —no hay middleware que la haya construido para una petición que aún no está vinculada—, y se queda solo con la fila, donde la traducción no existe.

**El respaldo no es un respaldo: es un agujero.** Para `bind` la ruta de la fila es la única ruta, y está desconectada.

### 1.3 Los tres síntomas medidos en navegador real

Medido el 2026-09-29 sobre `php -S` con una base sandbox y un usuario `Hermano18` con `clan_id = cln_mares` («Mareas de Aether») y `lineage = celestialTides`, iniciando sesión por el formulario del Umbral:

| # | Síntoma | Medición |
|---|---------|----------|
| **S1** | **El distintivo de cabecera degrada su etiqueta.** Dice el linaje donde debería decir la hermandad | `aria-label` = *«Hermano18 — Linaje de las Mareas Celestiales — Adepto»*. Tras recargar: *«Hermano18 — Mareas de Aether — Adepto»* |
| **S2** | **El CTA del héroe ofrece un acto ya cumplido.** Es la promesa de SPEC-17 rota por el contrato, no por su lógica | `.landing-hero__cta` = «Vincularse a una Hermandad», 1 nodo. Tras recargar: 0 nodos |
| **S3** | `clanName` vacío y `clanId` nulo en el sobre | `{"clanId":null,"clanName":""}` en `bind`; `{"clanId":"cln_mares","clanName":"Mareas de Aether"}` en `session` |

`userProfileBadge.setUser()` decide su rótulo con `user.clanId !== '' && user.clanName !== ''`; en falsehood cae al nombre del linaje. Por eso **S1 y S2 son el mismo defecto leído por dos superficies distintas**, y por eso el arreglo es único.

### 1.4 Lo que NO está roto (y delimita el arreglo)

- El **Vestíbulo** muestra «Ya habitas esta hermandad» correctamente: consulta su propio endpoint en vez de leer la sesión.
- La **afiliación en base de datos** es correcta: `users.clan_id` y `clan_members` coinciden.
- Los **códigos HTTP y las formas de error** de `bind` son correctos: 200, 401, 429.
- **`consecrate`** (línea 135) emite su propio sobre sin `forgeUserPayload`, y **no se toca** (§2.2).

### 1.5 Objetivo

Que `bind` y `session` devolvan **el mismo usuario**, y que tras el clic en «Renovar Vínculo» la interfaz diga la verdad sobre la hermandad sin esperar a una recarga.

---

## 2. Alcance y Exclusiones

### 2.1 Alcance

- El mapeo de `forgeUserPayload()`: que la fila de `users` alimente **todos** los campos del sobre.
- La **eliminación del brazo `default`** del `match`, que hoy devuelve el linaje ante cualquier clave desconocida.
- La verificación de que `bind` y `session` emiten usuarios **idénticos** para el mismo titular.
- Un arnés PHP que falle **antes** del arreglo y pase después.

### 2.2 Exclusiones (y por qué)

| Excluido | Motivo |
|---|---|
| **`consecrate` (línea 135)** | Emite un sobre propio, sin `forgeUserPayload`, y `test_auth_controller.php:137` **afirma que `clanId` no debe estar presente** en él (`!isset($userData['clanId'])`). Es una decisión de SPEC-09: la cuenta nace peregrina. Unificar los tres sobres «por simetría» rompería un aserto que el tiempo ha ratified. |
| **Que el frontend vuelva a pedir la sesión tras `bind`** | Es el arreglo que parecería obvious y es el peor: tapa el defecto de contrato en vez de corregirlo, cuesta una ida y vuelta, y deja el `bind` mintiendo para el próximo cliente que no sea este navegador. |
| **Tocar `models/User.php` o `AuthMiddleware`** | Ya son correctos. El defecto está en el puente entre la fila y el sobre. |
| **`database/schema.sql` y `schema-mysql.sql`** | El esquema no participa: la fila ya trae `clan_id` bien escrito. |
| **Los otros 152 arneses PHP** | Se ejecutan como puerta de entrada (§9), no se modifican. |
| **Las tres `store.subscribe` de `main.js`** | Defecto distinto, de ciclo de vida, anotado en SPEC-17 §8.1. Mezclar una corrección de contrato con una de desmontaje produce una spec difícil de revisar. |

---

## 3. Actores

| Actor | Relación con esta spec |
|---|---|
| **Adepto con hermandad que entra por el Umbral** | El afectado directo. Es a quien el sistema le dice que no tiene casa. |
| **Adepto sin hermandad** | No nota el cambio: `null` es la respuesta correcta para él. El arreglo **no debe alterar su sobre**. |
| **Peregrino** | Sin hermandad por definición (SPEC-09). Su caso es el de arriba, correcto por casualidad. |
| **El siguiente cliente del API** | Quien lea `clanId` como contrato. Hoy recibe un `null` que contradice la especificación de SPEC-03. |

---

## 4. Requisitos Funcionales (EARS)

### RF-18.1 — La fila alimenta el sobre entero

> CUANDO se forje el sobre `data.user` a partir de una fila de `users`, el sistema **DEBERÁ** traducir cada columna a su clave de contrato, incluida `clan_id → clanId`, y **DEBERÁ** normalizar la cadena vacía a `null` antes de emitirla. Una fila de la que se puede leer `clanId` es un error de mapeo, no una ausencia de dato; y una cadena vacía en el espejo es una ausencia de dato, no un nombre de hermandad.
>
> **La normalización la encontró una prueba, no una revisión.** La Tarea 0 mut� el c�rre de cinco maneras distintas y una de ellas —el «arreglo correcto» seg��n quien lo escribi�— devolvi� `""` donde el contrato pide `null`. El arnés lo rojiz� y qued� escrito como criterio. La raz�n de fondo: `store.setSession()` normaliza con `typeof clanId === 'string' && clanId !== ''`, as� que el frontend **sí sabe** distinguir la cadena vacía de la ausencia, pero solo si el backend se lo dice bien. Un contrato con dos representaciones para un mismo estado es un contrato que algún tendrá que adivinar.

### RF-18.2 — Sin huecos en el mapeo

> EL SISTEMA **NO DEBERÁ** tener un brazo `default` que devuelva un campo ante una clave no reconocida. Un campo devuelto por defecto es un campo que nadie verificó: es exactamente el mecanismo que escondió este defecto durante tanto tiempo. Una clave desconocida **DEBERÍA** ser un fallo ruidoso durante el desarrollo, no un linaje silencioso en producción.

### RF-18.3 — `bind` y `session` dicen lo mismo

> CUANDO se consulte el mismo titular por `POST /auth/bind` y por `GET /auth/session`, los dos sobres **DEBERÁN** ser idénticos campo a campo, con la única diferencia admisible siendo `authenticated`, que solo existe en `session`.

### RF-18.4 — La hermandad correcta, con su nombre

> CUANDO el titular pertenezca a una hermandad, `bind` **DEBERÁ** devolver `clanId` con el identificador de la hermandad y `clanName` con su nombre resuelto. Cuando no pertenezca a ninguna, **DEBERÁ** devolver `clanId: null` y `clanName: ""` — que es lo que ya hace y **no se toca**.

### RF-18.5 — La interfaz dice la verdad desde el primer instante

> CUANDO una persona entre por el Umbral con una hermandad, el distintivo de cabecera **DEBERÁ** nombrar esa hermandad y el CTA del héroe **NO DEBERÁ** ofrecer vincularse a una Hermandad, **sin recarga de página**.

### RF-18.6 — La autoridad sigue siendo `clan_members`

> La reparación **NO DEBERÁ** alterar de dónde procede la afiliación. `users.clan_id` es el espejo denormalizado de `clan_members` (RF-01.1, Art. VII), y así debe seguir siendo. Esta spec repara la traducción, no la fuente.

---

## 5. Requisitos No Funcionales

* **RNF-18.1 (Sin dependencias):** PDO nativo y PHP 8.2. Ni una línea de SQL cambia; el arreglo es de mapeo en memoria.
* **RNF-18.2 (Soberanía lingüística):** el arreglo no introduce texto visible. Los nombres de campo viajan en inglés `camelCase` porque son claves de contrato (Art. V).
* **RNF-18.3 (Prepared statements):** inalterado. `fetchUserRow()` ya usa `$stmt->prepare()` con parámetro vinculado y así se queda.
* **RNF-18.4 (Superficie mínima):** **un solo fichero de producción**, `src/Controllers/AuthController.php`, y dentro de él **un solo cierre privado**. Nada de `public/`, nada de CSS, nada de `database/`.
* **RNF-18.5 (Arnés sin servidor web):** la verificación se hace invocando el controlador directamente, como los nueve arneses PHP que ya lo hacen.
* **RNF-18.6 (Paridad MySQL/SQLite):** la reparación no toca DDL ni consultas, así que la Regla de los Gemelos (SPEC-13 §8) no se activa. Se comprueba igualmente que `git diff` no liste ningún fichero bajo `database/`.

---

## 6. Casos Límite

| # | Caso | Regla |
|---|---|---|
| 1 | Titular **sin** hermandad que entra por el Umbral | Su sobre no cambia: `clanId: null`, `clanName: ""`. El arreglo no debe «rellenar» nada. Es el caso que un arreglo descuidado rompe. |
| 2 | `users.clan_id` apunta a una hermandad **inexistente** | **Medido antes de escribirlo:** `users.clan_id` es `TEXT REFERENCES clans (id)` **sin** `ON DELETE CASCADE`, y `Connection` activa `PRAGMA foreign_keys = ON`, así que un dangling es prácticamente inalcanzable con la FK activa. Aun así `resolveClanName()` devuelve `""` si el `SELECT` no trae fila, y el arreglo **no** debe inventar una hermandad fantasma ni normalizar a `null` a escondidas. Si algún día un `clan_id` colgante llegara, el sobre será `clanId: "cln_huerfano"`, `clanName: ""` — que es exactamente lo que dice la base de datos, y ocultarlo sería peor que mostrarlo. |
| 3 | `bind` con credenciales erróneas (401) o procedencia congelada (429) | Sin cambio. Ninguna de esas ramas llega a `forgeUserPayload`. |
| 4 | `session` anónimo | Sin cambio: `authenticated: false` y `user: null`, sin llegar al cierre. |
| 5 | ¿Debería `bind` reusar la entidad `User` en vez de la fila? | **Descartado, y el motivo se escribe:** `bind` no tiene entidad porque la petición aún no está vinculada; construirla exigiría una consulta extra o un `User::fromDatabaseRow()` sintético, y `User` **no modela `avatar`**, que el sobre sí necesita (SPEC-12, RF-03.3). La fila es la fuente correcta aquí; lo que falta es traducirla. |
| 6 | ¿Y si `clan_members` dijera una casa distinta de `users.clan_id`? | Fuera de alcance. `users.clan_id` es el espejo declarado y su desincronización es un defecto de SPEC-07, no de este cierre. Se anota, no se arregla. |

---

## 7. Contrato de Superficie (nombres exactos)

| Símbolo | Fichero | Cambio |
|---|---|---|
| `forgeUserPayload()` | `src/Controllers/AuthController.php` | El cierre `$fieldValue` deja de buscar por nombre de contrato y busca por **nombre de columna**; el brazo `default` desaparece |
| `fetchUserRow()` | mismo | **Sin cambios.** Ya trae `clan_id` |
| `resolveClanName()` | mismo | **Sin cambios.** Recibe el `clanId` correcto y ya funciona |
| `User::fromDatabaseRow()` | `src/Models/User.php` | **Sin cambios.** Ya traduce bien |

**Firma inalterada:** `private function forgeUserPayload(?array $userRow, ?\Grimorio\Models\User $activeUser = null): array`. El segundo argumento **sigue siendo opcional** porque `session` lo usa y `bind` no; el arreglo no obliga a `bind` a construir una entidad.

**Forma del sobre, sin cambios:** `{ id, alias, role, clanId, clanName, lineage, avatarKind, avatarReference, avatarUrl? }`. Esta spec **no añade ni quita ninguna clave**.

---

## 8. Criterios de Aceptación (verificables)

> **Verificación de la Tarea 2 (2026-09-29).** Los once criterios están medidos y con evidencia. Cinco los mide el arnés de la Tarea 0 (23/0), dos el navegador real sobre una base sandbox propia, y cuatro se resuelven por el diff. El criterio 11 lleva una **divergencia declarada**: el noveno de los nueve arneses PHP está en rojo y lo estaba antes de que esta spec tocara una línea.
- [x] El arnés rojo de la Tarea 0 **falla** nombrando `clanId` antes del arreglo, y **no reventa**: imprime marcas rojas y sale con código 1.
      *Evidencia:* `php scratch/test_bind_envelope_clan.php` → **19 asertos superados, 4 fallidos, exit 1**. La paridad se lee en el propio texto del fallo: `clanId (bind=NULL, session='cln_sobre'); clanName (bind='', session='Mareas de Aether')`. Prueba de mutación **5/5** (§8.2).
- [x] `bind` devuelve `clanId: 'cln_mares'` y `clanName: 'Mareas de Aether'` para un titular con hermandad.
      *Evidencia:* arnés, fase [1]. Por HTTP con la misma base sandbox: `{"clanId":"cln_mares","clanName":"Mareas de Aether"}`. Antes del arreglo: `{"clanId":null,"clanName":""}`.
- [x] `bind` sigue devolviendo `clanId: null` y `clanName: ''` para un titular **sin** hermandad (el caso límite 1, que un arreglo descuidado rompe).
      *Evidencia:* arnés, fase [2], **cinco** aserciones. Las dos últimas existen solo para esto: que ambas claves **sigan estando presentes** y que el mismo lector que las lee distinga bien una clave con valor. Por HTTP: `clanId=NULL clanName=''` con `array_key_exists` cierto en ambas. En navegador, el titular sin casa muestra «Jurado18 — Linaje de las Sombras Abisales» y el CTA sí le ofrece «Vincularse a una Hermandad»: **no se le regaló una hermandad que no tiene**.
- [x] Los ocho campos del sobre de `bind` y de `session` son **idénticos campo a campo** para el mismo titular (RF-18.3).
      *Evidencia:* arnés, fase [3], con la comparación de los ocho campos y el texto del veredicto en el propio fallo (`clanId (bind=NULL, session='cln_sobre')` antes del arreglo; `sin divergencias` ahora). **Y por HTTP, byte a byte:** los dos cuerpos de `bind` y de `session` son el mismo fichero, sin una diferencia de un espacio. Ese es el criterio que dio nombre a la spec.
- [x] El cierre de mapeo **no resuelve ningún campo por reserva**: ante una fila sin `clan_id`, con `clan_id` vacío, o ausente por completo, `clanId` vale `null` y **nunca** el linaje (RF-18.1, RF-18.2).
      *Evidencia:* arnés, fase [4], por **reflexión** sobre el cierre privado con cuatro filas manipuladas. La última comprueba además que la ruta de `session` no se rompe: con entidad y sin fila, `clanId` sale de la entidad.
- [x] La cadena vacía viaja normalizada a `null`, no como `""` (RF-18.1). **Criterio añadido por la Tarea 0**, después de que la prueba de mutación rojizara un arreglo que no cumplía nada de esto.
      *Evidencia:* arnés, fase [4]: «Ante `clan_id` vacío, `clanId` vale null y no se sustituye por el linaje». Es la aserción que cazó la mutación M3.
- [x] El distintivo de cabecera, tras entrar por el Umbral **sin recargar**, nombra la hermandad y no el linaje (S1 corregido).
      *Evidencia:* navegador real. Antes: `aria-label` = «Hermano18 — **Linaje de las Mareas Celestiales** — Adepto». Ahora: «Hermano18 — **Mareas de Aether** — Adepto», y el marcador `window.__spec18Alive` siguió vivo: la página no se recargó. Comprobación explícita: `hermandadEnBadge: true`, `linajeEnBadge: false`.
- [x] El CTA del héroe, en el mismo instante y sin recargar, **no** ofrece «Vincularse a una Hermandad» a quien ya tiene hermandad: 0 nodos (S2 corregido).
      *Evidencia:* navegador real, en la misma medición y sin recargar: `querySelectorAll('.landing-hero__cta').length === 0`. Antes: 1 nodo con ese rótulo. **La promesa de SPEC-17 vuelve a ser cierta desde el primer instante, no a la segunda recarga.**
- [x] `resolveClanName()` y `User::fromDatabaseRow()` **no** han sido tocados.
      *Evidencia:* `git diff --exit-code -- src/Models/User.php` limpio; `git diff -U0 | grep -E '^[+-].*resolveClanName'` sin resultados. `resolveClanName()` sigue en la línea 651 y `fetchUserRow()` en la 534. `git diff --stat -- src/` → **un solo fichero**. La firma de `forgeUserPayload()` tampoco cambia y su segundo argumento sigue siendo opcional.
- [x] `git diff --name-only` no lista ningún fichero bajo `database/`, ni `tokens.css`, ni nada bajo `public/`.
      *Evidencia:* los tres filtros devuelven **NINGUNO**. La superficie completa son 5 ficheros: 2 specs, 2 docs y **1 de producción**, `src/Controllers/AuthController.php` (+63/−8). La Regla de los Gemelos (SPEC-13 §8) no se activa porque no hay DDL.
- [x] `php -l` limpio y los **nueve arneses PHP** que tocan `AuthController` siguen en verde.
      *Evidencia:* `php -l` sin errores; el arnés de la Tarea 0 en **23/0, exit 0**. **Ocho de los nueve** en verde, con recuento idéntico antes y después y **264 asertos** ejecutados: `test_spec15_local` 77, `test_auth_controller` 53, `test_auth_rbac` 33, `test_lineage_oath_controller` 24, `test_auth_language_sovereignty` 21, `test_csrf_cookie_shield` 21, `test_discreet_duplicate_notice` 20, `test_lineage_consecration` 15.
      **Divergencia declarada:** el noveno, `test_renounce_account.php`, está en rojo — y **estaba en rojo antes de que esta spec tocara una línea**. No es una afirmación: se comprobó con `diff` entre sus dos logs (vacío) y reconstruyendo el baseline contra `HEAD` (`git show HEAD:src/...`), donde el arnés de SPEC-18 falla igual con 19/4. Su causa es un agujero de SPEC-15, que añadió `TrustedProxyResolver` sin actualizar el `require` manual de ese arnés, y revienta con un `Fatal error` **sin imprimir un solo aserto rojo**. Ver el riesgo nº7 de TASKS-18. El criterio se marca cumplido en cuanto al **efecto del arreglo**, que es lo que mide, y la cifra literal de 9/9 se declara falsa en lugar de inflarse.
      *Y un RNF que no estaba en la lista y se verificó igual:* el diff no contiene **ni una línea** de SQL (`grep -cE '^[+-].*(prepare|execute|SELECT |INSERT |UPDATE |DELETE )'` → **0**), así que RNF-18.3 —prepared statements inalterados— se cumple por medición, no por confianza.

---

## 9. La batería PHP: la puerta de entrada, con el riesgo medido

SPEC-17 cerró sin ejecutar los ~170 arneses PHP, y lo dejó escrito como riesgo sin contrapartida. **Esta spec cambia esa ecuación:** es la primera desde SPEC-16 que toca `src/`, así que la batería deja de ser opcional.

La investigación de 2026-09-29 da un riesgo **mucho menor** del temido:

| Medida | Resultado |
|---|---|
| Harneses PHP totales | 161 |
| Los que tocan `AuthController` | **9** |
| De esos 9, con sandbox SQLite propio | **8 de 9** |
| El noveno (`test_auth_language_sovereignty`) | No abre base: es análisis estático de fuentes. Inofensivo |
| Los que hacen `DROP DATABASE` | **1**, y es `verify-spec13.php`, que **no se ejecuta jamás** |

**Conclusión medida: los 9 arneses de `AuthController` son seguros** — cada uno levanta su propia base temporal y la destruye. La batería completa sigue sin ejecutarse, y el motivo se mantiene escrito en lugar de dado por verde.

**Tarea de esta spec:** ejecutar los 9 antes y después del arreglo, y anotar el resultado. Es la red que SPEC-16 y SPEC-17 no tuvieron.

---

## 10. Ficheros Previstos (superficie estimada)

```
src/Controllers/AuthController.php   ← ÚNICO fichero de producción (el cierre $fieldValue)
scratch/test_bind_envelope_clan.php  ← NUEVO (arnés rojo, luego verde; sandbox SQLite propio)
specs/18-bind-envelope-clan-identity.tasks.md  ← NUEVO
```

**Fuera de alcance, verificado por `git diff --name-only`:** `src/Models/User.php`, `src/Middleware/AuthMiddleware.php`, `src/Services/AuthService.php`, `database/`, todo `public/`, `tokens.css`.

---

## 11. Nota de Precedencia (por qué esta spec existe)

El defecto no es un descuido de tecleo: es una **conversión implícita que nadie escribió**. `forgeUserPayload` traduce «lo que el contrato llama a cada cosa» a «lo que la columna se llama», y esa traducción vive repartida entre el `match`, el `User::fromDatabaseRow()` y el `avatar`. Dos rutas, dos conversiones, y solo una de las dos completa. Cuando una función tiene **dos** caminos hacia el mismo dato, el que no se prueba es el que miente; y el que no se prueba aquí es el que ejecuta el login.

La lección que se escribe porque el proyecto ya la ha pagado dos veces: **una función con dos caminos hacia el mismo campo necesita una aserción que compare ambos.** No una aserción por función — una aserción por **par**. `bind` y `session` producen el mismo Concepto y se han desviado durante meses sin que nadie lo notara, porque nadie los miró juntos.

Y la conexión con SPEC-17 merece quedar escrita, porque es la que justifica abrir una spec y no cerrar en un commit: SPEC-17 prometía que *cada rótulo del héroe nombra un acto que quien lo mira puede realizar*, y dos commits después, un adepto con hermandad ve «Vincularse a una Hermandad». La lógica de SPEC-17 es correcta; el contrato que la alimenta no lo era. **Un sistema con la lógica correcta y el contrato equivocado miente igual**, y se equivoca en la superficie más visible que existe: el primer botón de la primera pantalla.
