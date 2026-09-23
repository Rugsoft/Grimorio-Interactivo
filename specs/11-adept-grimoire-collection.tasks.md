# TASKS — SPEC-11: Colección del Adepto (Grimorio Personal y Elogio Popular)

> **Fuente:** `specs/11-adept-grimoire-collection.spec.md` + `specs/11-adept-grimoire-collection.plan.md`
> **Reglas:** tareas de 20–30 minutos, en orden de dependencia estricta; ninguna tarea empieza antes de que su predecesora esté en verde. Cada tarea declara los RF que cubre y una condición «Hecho cuando» verificable por comando o comprobación reproducible (Artículo VI: sin spec aprobada y sin tarea cerrada, no hay código).
> **Convención:** «Cubre» cita los RF-x/RNF-x y casos límite de la SPEC-11 (incluidos los 27 hallazgos ratificados en la Sección 9); los arneses viven en `scratch/` siguiendo el estilo de las familias 07/08/09/10.

---

## Fase 1 — Persistencia y Migración

- [x] **Tarea 1.1 — Migración `11_grimoire_collections.sql`**
  * **Qué:** migración idempotente con `CREATE TABLE IF NOT EXISTS grimoire_collections` (UNIQUE `(user_id, spell_id)`, cascadas hacia `users` y `spells` según plan §1.3) y `CREATE INDEX IF NOT EXISTS idx_grimoire_collections_user_added` sobre `(user_id, added_at DESC)`; actualización de `database/schema.sql`.
  * **Cubre:** `RF-05.4` (tabla nueva separada de `favorites`), `RF-05.3` (purga de cuenta), `RNF-01` (índice de latencia), `RNF-02` (PDO/SQL nativo).
  * **Hecho cuando:** re-ejecutar la migración sobre una base ya migrada no falla ni duplica, un segundo INSERT de `(user_id, spell_id)` existente recibe la violación del índice único, y borrar la fila de `users` arrastra su tomo por cascada.

- [x] **Tarea 1.2 — `GrimoireCollectionRepository`**
  * **Qué:** `add()` (INSERT con captura idempotente), `remove()`, `pageForUser()` (página + filtro de afinidad, orden `added_at DESC`), `countForUser()`, `existsForUser()`, `spellIdsForUser()` — PDO exclusivamente preparado, `camelCase`, comentarios en castellano.
  * **Cubre:** `RF-05.4`, `RF-01.3` (idempotencia física), `RNF-01`, `RNF-02`.
  * **Hecho cuando:** las seis consultas responden contra una base sembrada; `add()` dos veces devuelve una sola fila; `pageForUser()` respeta filtro, orden y paginación de 50.

## Fase 2 — Ritos Backend del Tomo

- [x] **Tarea 2.1 — Mapa único de estados a marcas del tomo**
  * **Qué:** método estático `tomeMarkForStatus()` en `GrimoireCollectionService`: `validated → living`, `draft|experimental → gestation`, `rejected|archived → withdrawn` (plan §3.1, hallazgos 12 y 21); cero lógica duplicada en el frontend.
  * **Cubre:** `RF-03.2` (marcas solemnes), caso límite 7.
  * **Hecho cuando:** los cinco estados del ciclo de vida producen exactamente las tres marcas canónicas y el método lanza ante un estado fuera del catálogo.

- [x] **Tarea 2.2 — Rito del sellado con guardias ordenadas**
  * **Qué:** `GrimoireCollectionService::collectSpell()`: sesión → linaje jurado (403 `LINEAGE_OATH_REQUIRED`, el linaje manda no el rol — hallazgo 16) → existencia (404) → idempotencia (200 «Ya está en tu tomo», RF-01.3, jamás gloria — hallazgos 2-3) → estado (leyenda UNIFORME ante cualquier no validado, RF-01.2 — hallazgo 4) → INSERT + asiento `TOME_SEAL`.
  * **Cubre:** `RF-01.1`, `RF-01.2`, `RF-01.3`, `RF-06.1`, caso límite 5 (doble pestaña).
  * **Hecho cuando:** cada guardia responde en su orden exacto, el sellado doble devuelve 200 sin segunda fila ni segundo asiento de Bitácora, y la leyenda de vedado es idéntica para `draft`, `experimental` y `rejected`.

- [x] **Tarea 2.3 — Retirada del tomo y paginación viva**
  * **Qué:** `discardSpell()` (fila propia, 409 `SPELL_NOT_IN_TOME` si no existe, jamás toca `favorites` — hallazgo 13) y `reanudarPagina()` según plan §3.5 (última página viva tras retirada, filtro conservado — hallazgo 17).
  * **Cubre:** `RF-02.4`, casos límite 9 y 10.
  * **Hecho cuando:** retirar una entrada existente devuelve el `total` actualizado; retirar una ausente responde 409; retirar la última de una página intermedia recalcula la página destino; `favorites` queda byte a byte intacta.

## Fase 3 — Puerta del Elogio y Bitácora

- [x] **Tarea 3.1 — La puerta REST del Elogio Popular**
  * **Qué:** `GrimoireCollectionController::praiseSpell()`: guardias propios (sesión → linaje → existencia → 409 `PRAISE_SPELL_NOT_VALIDATED` sobre no validado, RF-04.5) y orquestación de `WeeklyDominionService::awardCommunityFavorite()` SIN modificarla (plan §3.3): traducción del recibo vivo — `AWARDED`, `DUPLICATE_FAVORITE → ALREADY_PRAISED`, `OWN_CLAN_FAVORITE` como estado 200 con leyenda, jamás error HTTP (hallazgos 10-11).
  * **Cubre:** `RF-04.1`, `RF-04.2`, `RF-04.3`, `RF-04.4`, `RF-04.5`, caso límite 8.
  * **Hecho cuando:** gloria nueva devuelve 5 PDA + sinergia; el segundo elogio devuelve `ALREADY_PRAISED` sin segunda gloria; el militante recibe 200 con `OWN_CLAN_FAVORITE` y `favorites` sin fila nueva; el no validado forzado responde 409; el cierre de ciclo asigna la gloria al ciclo correcto.

- [x] **Tarea 3.2 — Asientos de Bitácora `TOME_SEAL` y `TOME_PRAISE`**
  * **Qué:** dos actos canónicos nuevos en `AuditEntry::CANONICAL_ACTION_TYPES` con justificaciones del plan §2.3; `TOME_PRAISE` SOLO cuando hay gloria acreditada (`reason: AWARDED`); el eco idempotente y el recibo denegado jamás se asientan.
  * **Cubre:** `RF-06.1`, `RF-06.2` (hallazgo 20, Artículo III.3).
  * **Hecho cuando:** un sellado produce un `TOME_SEAL` con estampa temporal; un elogio con gloria produce un `TOME_PRAISE` que nombra clan y adepto; el segundo elogio y el recibo denegado no añaden filas a la Bitácora.

- [x] **Tarea 3.3 — Enriquecimiento embebido del catálogo y el tomo**
  * **Qué:** `adeptState` (camelCase, `collected`/`praised`) embebido en los listados de `GrimoireQueryService` SOLO con sesión autenticada (RF-04.0, hallazgo 5); tercera vía `mode=collection` en `GrimoireController::listSpells()` con `CollectionPageDto` paginado y `tomeMark` por entrada (RF-02.1).
  * **Cubre:** `RF-04.0`, `RF-02.1`, `RF-02.3`, `RNF-01`.
  * **Hecho cuando:** el listado canónico del adepto porta `collected`/`praised` reales sin peticiones extra; anónimo recibe el listado sin `adeptState`; `mode=collection` entrega solo su tomo ordenado por adición con `total`, `page` y `totalPages`.

## Fase 4 — Controlador REST y Cliente Frontend

- [x] **Tarea 4.1 — Endpoints REST completos del tomo**
  * **Qué:** `GrimoireCollectionController` con `listCollection` (GET), `collectSpell` (POST → 201/200), `discardSpell` (DELETE → 200/409), registro de las cuatro rutas en `public/index.php` y patrones de error del santuario (401/403/404/409) con cuerpo `{ success, error: { code, message } }` — claves JSON en camelCase (hallazgos 8/19, Artículo V).
  * **Cubre:** `RF-05.1`, `RF-05.3`.
  * **Hecho cuando:** las cuatro rutas responden en el arnés con los códigos exactos del plan §2.2, el peregrino recibe 403 `LINEAGE_OATH_REQUIRED` y ninguna clave JSON del contrato va en snake_case.

- [x] **Tarea 4.2 — `grimoireCollectionClient.js`**
  * **Qué:** cliente fetch nativo de los cuatro endpoints (`credentials: 'same-origin'`), con mapeo de códigos y razones a veredictos de la vista (`AWARDED`, `ALREADY_PRAISED`, `OWN_CLAN_FAVORITE`, `LINEAGE_OATH_REQUIRED`…) — sin librerías, comentarios en castellano.
  * **Cubre:** `RF-05.1`, `RNF-02`.
  * **Hecho cuando:** el doble de fetch del arnés recibe cada código/razón y el cliente lo traduce al veredicto canónico sin lanzar excepciones en los estados solemnes.

## Fase 5 — Componentes y Vista del Tomo

- [ ] **Tarea 5.1 — Gesto compartido en `spellCardComponent`**
  * **Qué:** ampliación de la tarjeta con los estados del DTO: «Añadir al tomo» / «Ya está en tu tomo» / «Ya rendiste homenaje» (conmutadores con `aria-pressed`), gesto «Elogiar» ausente + leyenda de militancia («Un adepto de la casa no granjea gloria para su propio estandarte»), gestos ausentes sobre no validados — una sola lógica para Biblioteca, Simulador y Tomo (RF-04.0); eventos `tome:seal` / `tome:praise` en el bus. Textos LITERALES del Anexo A del plan.
  * **Cubre:** `RF-04.0`, `RF-01.1`, `RF-04.3`, `RF-04.4`, `RF-04.5`, `RNF-04`.
  * **Hecho cuando:** la misma tarjeta pinta los seis estados posibles solo desde su DTO, los conmutadores llevan `aria-pressed`, la activación por teclado funciona y los gestos vedados jamás llegan al bus.

- [ ] **Tarea 5.2 — Modal solemne de retirada**
  * **Qué:** `discardTomeEntryModalComponent.js` con la leyenda canónica del Anexo A, foco atrapado y devuelto, Escape, descarte sin mutación — patrón de foco de SPEC-02.
  * **Cubre:** `RF-02.4`, `RNF-04`, caso límite 10 (reanudación tras confirmar).
  * **Hecho cuando:** confirmar retira la entrada y actualiza el conteo sin recargar la página; Escape y descarte no mutan; el foco vuelve al gesto de origen.

- [ ] **Tarea 5.3 — Vista «Mi Grimorio» con ruta propia**
  * **Qué:** `grimoireCollectionView.js` (`#/grimorio`), rótulo soberano en la navbar, carga única paginada, filtro por afinidad con conteo, estado vacío con invitación a la Biblioteca («Tu tomo aguarda su primera obra» + «Recorrer la Biblioteca»), paginación viva (plan §3.5) y degradación tras 401 con la leyenda del Anexo A (aviso solemne + gestos apagados + lectura y filtro conservados — hallazgo 7).
  * **Cubre:** `RF-02.1`, `RF-02.2`, `RF-02.3`, `RF-03.1`, `RF-05.2`, casos límite 4, 6 y 10.
  * **Hecho cuando:** la vista monta con una sola carga del sobre paginado, el filtro filtra con conteo, el tomo vacío invita a la Biblioteca sin lenguaje de error y el 401 apaga los gestos sin vaciar lo leído.

- [ ] **Tarea 5.4 — Marcas solemnes y convocatoria desde el tomo**
  * **Qué:** pintado de `tomeMark` («Obra en gestación» / «Obra apartada del canon») en las entradas del tomo con convocatoria vedada, y enrutado de la convocatoria de una entrada viva hacia el Simulador con el modal de casta ya desplegado (SPEC-05 sin variantes nuevas). Textos LITERALES del Anexo A del plan.
  * **Cubre:** `RF-03.1`, `RF-03.2`, casos límite 2 y 7.
  * **Hecho cuando:** cada entrada no viva muestra su marca exacta sin gesto de convocatoria, y la convocatoria de una entrada viva abre el Simulador con el modal desplegado usando el motor de partículas intacto.

## Fase 6 — Intents, Retención y Vestimenta CSS

- [ ] **Tarea 6.1 — Retención y reanudación del acto (`addToGrimoire` / `givePraise`)**
  * **Qué:** `givePraise` añadida al catálogo de intents (enmienda menor y explícita a SPEC-09 — hallazgo 9), `targetSpellId` en `pendingIntent` (forma compatible), reanudación del acto concreto tras `oath:sealed` según plan §3.4 (el retorno COMPLETA el sellado o el elogio sin repetir el gesto — hallazgo 6).
  * **Cubre:** `RF-01.4`, caso límite 1.
  * **Hecho cuando:** el peregrino que intenta sellar o elogiar aterriza en el juramento y, tras sellarlo, el acto se completa solo sobre el hechizo retenido; los tres intents previos de SPEC-09 siguen despachando igual.

- [ ] **Tarea 6.2 — `grimoire-collection.css`**
  * **Qué:** vestimenta de la vista y los gestos con los tokens de SPEC-02 (cero literales de color fuera de `tokens.css`, cobertura del guard de huérfanos CSS), `prefers-reduced-motion` para sellado y eco de elogio (RNF-05), responsive hasta móvil.
  * **Cubre:** `RNF-03` (rotulación castellana), `RNF-04`, `RNF-05`.
  * **Hecho cuando:** el arnés de cobertura CSS no registra huérfanos, el guard de soberanía lingüística no encuentra literales técnicos en los módulos nuevos y la vista no desborda a 360 px de ancho.

## Fase 7 — Arneses Backend

- [ ] **Tarea 7.1 — Arnés del repositorio y la migración**
  * **Qué:** `scratch/test_grimoire_collection_repository.php`: DDL idempotente, UNIQUE física, índice de latencia, cascada de purga, orden y paginación — según plan §6.1 fila 1.
  * **Cubre:** `RF-05.3`, `RF-05.4`, `RNF-01`, `RNF-02`.
  * **Hecho cuando:** el arnés concluye en verde con asertos sobre cada invariante físico de la tabla.

- [ ] **Tarea 7.2 — Arnés del rito del tomo y del controlador**
  * **Qué:** `scratch/test_grimoire_collection_service.php` + `scratch/test_grimoire_collection_controller.php`: guardias ordenadas, idempotencia por carrera, leyenda UNIFORME, mapa de marcas completo, REST íntegro (201/200/403/404/409), camelCase, retirada sin tocar `favorites` — según plan §6.1 filas 2-3.
  * **Cubre:** `RF-01.1`–`RF-01.3`, `RF-02.4`, `RF-03.2`, `RF-05.1`, `RF-05.5`, casos límite 2, 5 y 9.
  * **Hecho cuando:** ambos arneses concluyen en verde reproduciendo cada caso del plan, incluida la carrera de doble INSERT resuelta por la UNIQUE física.

- [ ] **Tarea 7.3 — Arnés de la puerta del elogio y la Bitácora**
  * **Qué:** `scratch/test_praise_gateway.php` + `scratch/test_tome_audit.php`: las tres denegaciones/eos del recibo vivo de SPEC-07 sin modificarla, 409 del no validado, asientos `TOME_SEAL`/`TOME_PRAISE` solo donde procede, catálogo cerrado y rótulos castellanos — según plan §6.1 filas 4-5.
  * **Cubre:** `RF-04.1`–`RF-04.5`, `RF-06.1`, `RF-06.2`, casos límite 3, 8 y 11.
  * **Hecho cuando:** cada uno de los cinco comportamientos del elogio responde exactamente como el plan §3.3 dicta y la Bitácora registra solo los dos actos canónicos con su estampa.

## Fase 8 — Arneses Frontend

- [ ] **Tarea 8.1 — Arnés de la vista del tomo y el modal**
  * **Qué:** `scratch/test_grimoire_collection_view.mjs` + `scratch/test_discard_tome_modal.mjs`: carga única, filtro con conteo, estado vacío, paginación viva, 401 solemne con gestos apagados, foco del modal — según plan §6.2 filas 1 y 3.
  * **Cubre:** `RF-02.1`–`RF-02.4`, `RF-05.2`, casos límite 6 y 10.
  * **Hecho cuando:** los dos arneses concluyen en verde sobre los dobles de DOM del patrón consolidado (Vestíbulo/Simulador).

- [ ] **Tarea 8.2 — Arnés de gestos, cliente e intents**
  * **Qué:** `scratch/test_spell_card_tome_gestures.mjs` + `scratch/test_grimoire_collection_client.mjs` + `scratch/test_intent_give_praise.mjs`: los seis estados de la tarjeta, accesibilidad (`aria-pressed`, teclado, `prefers-reduced-motion`), mapeo del cliente, retención y reanudación del acto con regresión de los intents de SPEC-09 — según plan §6.2 filas 2, 4 y 5.
  * **Cubre:** `RF-01.4`, `RF-04.0`, `RF-04.4`, `RNF-04`, `RNF-05`, caso límite 1.
  * **Hecho cuando:** los tres arneses concluyen en verde y la batería de intents de SPEC-09 permanece en verde (compatibilidad de forma verificada).

- [ ] **Tarea 8.3 — Arnés de latencia RNF-01**
  * **Qué:** medición del lapso petición → primera respuesta de `mode=collection` sobre la pila real (sonda HTTP con autolimpieza `PowerShell -PassThru` + `register_shutdown_function`, patrón consolidado), presupuesto < 100 ms de backend.
  * **Cubre:** `RNF-01`.
  * **Hecho cuando:** el arnés certifica el presupuesto en cinco ejecuciones consecutivas y no deja procesos en el puerto de la sonda.

## Fase 9 — Cierre y Verificación

- [ ] **Tarea 9.1 — Regresión cruzada y guard de soberanía lingüística**
  * **Qué:** batería completa de las familias grimoire/dominion/clanes/auth en verde; guard de soberanía lingüística extendido a los módulos nuevos de colección (RNF-03); guard del Artículo V (claves JSON camelCase) sobre los contratos nuevos; cobertura CSS sin huérfanos.
  * **Cubre:** `RNF-02`, `RNF-03`, DoD de la spec (regresión completa).
  * **Hecho cuando:** la batería completa no registra ninguna suite en rojo y los guards ampliados pasan sobre los ficheros nuevos.

- [ ] **Tarea 9.2 — Recorrido manual en navegador y evidencia**
  * **Qué:** los diez pasos del plan §6.3 contra el servidor de demo (sellado desde ambas superficies, ambas vías de entrada al tomo, convocatoria directa, elogio con gloria, militancia, marcas solemnes, peregrino retenido y reanudado, retirada con paginación viva, 401, teclado/reduced-motion), con evidencia documentada en `specs/11-adept-grimoire-collection.evidence-9.2.md`.
  * **Cubre:** DoD de la spec (recorrido manual documentado).
  * **Hecho cuando:** la evidencia de los diez pasos queda documentada con capturas y las incidencias halladas quedan corregidas o registradas como hallazgos.

- [ ] **Tarea 9.3 — Cierre formal de la SPEC-11**
  * **Qué:** arnés `scratch/test_spec11_closure.php` al estilo de los cierres 07/10: cruce spec↔plan↔tasks (trazabilidad de los 28 requisitos), Dogma Vanilla (Fases 2–4 del patrón), batería íntegra en verde y los criterios de la Sección 8 de la spec con evidencia nombrada; veredicto final `RESULTADO: EXITO — SPEC-11 queda formalmente cerrada`.
  * **Cubre:** DoD completo de la spec (Sección 8).
  * **Hecho cuando:** el arnés imprime `RESULTADO: EXITO` con cero suites en rojo y el checkbox de esta tarea queda marcado en verde.
