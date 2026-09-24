# SPEC-11 · Evidencia del Recorrido Manual (Tarea 9.2)

> **Fecha:** 2026-09-23 · **Alcance:** plan §6.3 (los diez pasos) contra el servidor
> de demostración (`php -S 127.0.0.1:8109 -t public public/index.php`, base
> `scratch/demo_tome.sqlite` sembrada con `demo_local_seed.php` + `demo_tome_seed.php`).
> **Veredicto:** recorrido completado; **siete incidencias corregidas en el acto** y
> **cinco hallazgos registrados** (tres de ellos tocan contratos de specs ya cerradas
> y exigen enmienda ratificada).

---

## 0. Nota metodológica (honestidad de la evidencia)

Este entorno de agente **no puede capturar PNG**: el webview de previsualización no
compone fotogramas (`preview_screenshot` responde «it produced no frames»). La
evidencia viaja, por tanto, como **árbol de accesibilidad, sondas de DOM, respuestas
HTTP reales y estado de la base de datos** — todas ellas reproducibles con las
instrucciones anexas. No se declara ninguna captura que no exista.

---

## 1. Los diez pasos del plan §6.3

| # | Paso | Veredicto | Evidencia |
|---|---|---|---|
| 1 | Sellar desde la Biblioteca (ficha) y asiento `TOME_SEAL` | ✅ | Clic en «Añadir a mi Grimorio» → **sin modal de acceso** (`accessModal.open === false`) y eco «El sellado queda consumado: el tomo lo recuerda.»; BD: fila `(usr_custodio_primordial, spl_mares_02)` + asiento `TOME_SEAL` «Selló el conjuro en su tomo personal.» |
| 1b | Sellar desde el **Simulador** | ⚠️ hallazgo 8 | El Simulador no monta la tarjeta compartida: no ofrece gesto de tomo. El gesto vive en la ficha (que el Simulador no abre) y en el Tomo. |
| 2 | Doble vía: navbar y rótulo del umbral | ⚠️ parcial | **Navbar** → `#/grimorio`, `collection-view`, «Mi Grimorio» + «1 entrada · página 1 de 1» + conmutador «Ya está en tu tomo» (aria-pressed="true") + gesto «Elogiar». **Rótulo del umbral** → monta `grimoire-simulator` (hallazgo 10). |
| 3 | Convocar desde el tomo | ✅ | Clic en la tarjeta del tomo → `grimoire-simulator` con «Brisa de Sal» iluminada, **un solo `canvas`** (`arcane-canvas`, 381×380) — el motor de SPEC-05 se convoca, jamás se duplica. |
| 4 | Elogiar obra ajena | ✅ | Clic en «Elogiar» → eco «Tu homenaje…»/conmutador «Ya rendiste homenaje» (aria-pressed="true"); BD: `favorites(usr_custodio_primordial, spl_mares_02)`, `dominion_awards` = 5 PDA para `cln_mares`, asiento `TOME_PRAISE` «El Custodio Primordial rindió homenaje a «Brisa de Sal», granjeando gloria a «Mareas de Aether».» |
| 5 | Militancia en la casa del hechizo | ✅ | Tras sellar «Chispa de Ignición» (casa propia `cln_primordial`): leyenda «Un adepto de la casa no granjea gloria para su propio estandarte» y **gesto «Elogiar» ausente**; recarga: estados estables (todo el estado viaja embebido en el DTO). |
| 6 | Hechizo coleccionado que cae de estado | ✅ | `spl_mares_02 → experimental` → marca «Obra en gestación», convocatoria vetada («La obra madura: la convocatoria aguarda a que el Tribunal la selle.»), sin «Elogiar». `→ archived` → «Obra apartada del canon», entrada íntegra. Restaurado a `validated`. |
| 7 | Peregrino: gesto → juramento → acto reanudado | ✅ acto · ⚠️ retorno | Sellar como `usr_peregrino_demo`: **sin modal de acceso**, la ficha se retira, la ceremonia monta (`lineage-oath`); tras jurar: eco «Tu sellado aguardado queda consumado: el tomo lo recuerda.» y fila real en el tomo del peregrino + `TOME_SEAL`. El **retorno aterrizó en el portal** (hallazgo 7). |
| 8 | Retirada con modal y paginación viva | ✅ | 52 entradas → «página 1 de 2» (50 tarjetas); página 2 = 2 tarjetas; primera retirada → «51 entradas · página 2 de 2» (1 tarjeta); segunda → **«50 entradas · página 1 de 1»** con las 50 de la página viva — jamás una hoja fantasma (plan §3.5). Modal solemne «Firmar la retirada» / «Conservar la obra». |
| 9 | Sesión expirada con el tomo abierto | ✅ | Borrada la sesión en `user_sessions` y recargado el filtro: aviso **visible** «Tu vínculo con el santuario ha expirado: renuévalo y tus gestos aguardarán donde los dejaste.», botones con `disabled` + `aria-disabled="true"`, **50 tarjetas y el conteo intactos**, filtro presente (apagado, «Viento») — exactamente lo ratificado por RF-05.2. |
| 10 | Teclado, foco, reduced-motion, contraste, CSS | ✅ por arnés · ⚠️ matiz | `test_accessibility_flows`, `test_collection_responsive_css` (21/21), `test_css_coverage` (2/2), `test_spell_card_tome_gestures` (31/31) y `test_discard_tome_modal` (27/27) en verde; 103 reglas `:focus-visible` en el CSS. La comprobación **en vivo** de `:focus-visible` no pudo ejercerse: la ventana del navegador automatizado no tiene foco de sistema, así que Chromium no activa la pseudo-clase. |

---

## 2. Incidencias corregidas en el acto

| # | Incidencia | Corrección | RF/RNF |
|---|---|---|---|
| C1 | El gesto del tomo con **sesión viva** abría «Cruzar el Umbral»: un adepto linajado tenía que volver a cruzar el umbral para sellar. | `handleReservedAction` despacha el acto directo (`addToGrimoire`/`givePraise`) con la sesión viva y narra el desenlace. | RF-01.1, RF-04.0 |
| C2 | El **peregrino** con sesión viva recibía el modal de acceso (contradiciendo RF-01.4) y, al conducirlo a la ceremonia, la ficha quedaba abierta tapándola. | El peregrino retiene la intención y es conducido a la ceremonia; la ficha se retira antes de navegar. Arnés de la Tarea 6.1 **realineado** (ratificaba el umbral). | RF-01.4 |
| C3 | Los gestos internos de la tarjeta **burbujeaban** hasta la activación: «Elogiar» y «Retirar del tomo» abrían ADEMÁS el Simulador con la obra. | Guardia anti-burbujeo en `handleCardActivation` + 3 asertos nuevos (31/31). | RF-04.0, RF-02.4 |
| C4 | El nombre accesible de la tarjeta anunciaba «escuela **undefined**» y la insignia de escuela nacía vacía en el Tomo (el DTO del grimorio no porta `magicSchoolLabel`). | El componente OMITE la parte ausente y no forja cáscaras vacías. | RNF-03 |
| C5 | `.lineage-card__expansion` vencía el atributo `[hidden]` (su propio `display: flex`), así que la ceremonia mostraba la doctrina íntegra y **«Jurar» en las 8 tarjetas plegadas**. | Regla `.lineage-card__expansion[hidden] { display: none }`, la misma disciplina que ya usaba `.lineage-oath__sealing[hidden]`. | RF-02.2 de SPEC-09 (ratificado) |
| C6 | El anuncio de impacto sin efecto encadenaba la muletilla: «no altera al maniquí **al maniquí de pruebas**». | El anuncio sin fragmentos narra «el maniquí de pruebas permanece intacto». | RNF-03 |
| C7 | **`#/grimorio` no era retenible** para el peregrino (ni en `RETAINABLE_VIEWS` ni en el mapa hash→vista del middleware): el tomo se descartaba en silencio. Lo delató el guard de paridad de mapas de SPEC-09 (`test_lineage_oath_middleware`, 17/18 en rojo). | `'#/grimorio' => 'collection'` añadido con el mismo precedente que `#/vestibulo`; el arnés vuelve a **18/18**. | RF-02.1 (enmienda de paridad de SPEC-09) |

## 3. Hallazgos registrados (sin corregir en esta tarea: exigen enmienda o spec nueva)

| # | Hallazgo | Evidencia | Destino |
|---|---|---|---|
| H7 | **[CRÍTICO] La retención de ruta del juramento jamás persiste.** No existe `session_start()` en todo el repositorio: `$_SESSION` es un array por petición, y tanto la guardia como el controlador escriben y leen allí. | En vivo: `POST /api/v1/lineage/retained-route` → 204 y, al sellar, `{"lineage":"primordialFlame","sealedNow":true,"retainedRoute":null}` → retorno al portal (RF-03.1 de SPEC-09 incumplido). Los arneses no lo ven porque escriben y leen `$_SESSION` **en el mismo proceso**. | Enmienda a SPEC-09: persistir la ruta retenida en la sesión real (`user_sessions` vía `SessionManager`), no en `$_SESSION`. |
| H8 | **RF-04.0 incompleto:** el gesto compartido no vive en las tarjetas de la Biblioteca ni del Simulador. `/api/v1/spells` no porta `adeptState` (verificado: la lista no incluye la clave) y `libraryView` no cablea `onTomeGesture`; el Simulador no monta la tarjeta compartida. | Sonda de DOM en `#/biblioteca`: `spell-card__tome` = null en las 7 tarjetas. | Enmienda a SPEC-11 (Tareas 5.x/6.x): embeber `adeptState` en el listado del catálogo y cablear el gesto. |
| H9 | **`elementalAffinityLabel` no viaja en ningún DTO** → la insignia elemental de TODA tarjeta rotula «Arcano Puro» aunque el hechizo sea de rayo o viento. | Biblioteca: «Fragor del Alto Cielo» (rayo) y «Brisa de Sal» (viento) muestran «Arcano Puro». Preexistente de SPEC-05/06; se ve en la vista nueva. | Enmienda cruzada (SPEC-05/06): mapa canónico de etiquetas en el DTO. |
| H10 | **El rótulo «Ver mi libro personal» del umbral sigue abriendo el Simulador** (modo ensayos), contra la decisión §5 y RF-02.1. | Clic en el badge → `grimoire-simulator` con «Boceto Prohibido»; el arnés `test_simulator_route` (SPEC-05) ratifica hoy ese contrato. | Enmienda cruzada: repuntar el intent a `collection` y realinear el arnés. |
| H12 | **Carrera de arranque del interceptor:** en un deep-link, la vista no exenta se monta antes de que `auth/session` hidrate el store; el peregrino navegó la Biblioteca (7 tarjetas) sin desvío ni retención. | Observado en dos pestañas distintas con la misma sesión de peregrino. | Enmienda a SPEC-09: puerta de arranque hasta resolver la sesión. |

---

## 4. Reproducir el recorrido

```bash
php scratch/demo_local_seed.php scratch/demo_tome.sqlite
php scratch/demo_tome_seed.php  scratch/demo_tome.sqlite
GRIMORIO_DB_DSN="sqlite:scratch/demo_tome.sqlite" php -S 127.0.0.1:8109 -t public public/index.php
# Adepto linajado : custodio@primordialis.arc  / palabra-de-paso-demo
# Peregrino       : peregrino@primordialis.arc / palabra-de-paso-demo
```
