# Evidencia de la Tarea 9.2 — Verificación manual en navegador (plan §6.3)

> Recorrido ejecutado el 2026-09-21 contra el servidor de demo
> (`php -S 127.0.0.1:8099 -t public public/index.php` con
> `GRIMORIO_DB_DSN=sqlite:scratch/demo_vestibule.sqlite`, sembrado por
> `scratch/demo_local_seed.php`). Capturas tomadas desde la vista previa
> del entorno; persistencia verificada por CLI sobre la base de demo.

## Paso 1 — Doble vía al Vestíbulo ✅
- **Vía 1 (rótulo de navegación):** «Hermandades» en la navbar conduce a
  `#/vestibulo`. Evidencia: instantánea del DOM tras el clic.
- **Vía 2 (llamamiento del Salón):** el enlace «Entrar al Vestíbulo de las
  Hermandades» en `#/linajes` conduce al mismo hash `#/vestibulo`.
- Ambas vías renderizan la misma ceremonia (heading «Vestíbulo de las
  Hermandades» + tarjeta + inventario).

## Paso 2 — Tarjetas: sello, plenitud, régimen, corona ✅
- Sello heráldico SVG por metal y forma, censo «2 de 30 adeptos»,
  régimen «Requiere petición formal», y **corona dorada ♛** sobre la casa
  regente (captura: tarjeta de Mareas de Aether con la corona).
- Contraste verificado visualmente en ambas capturas del recorrido.

## Paso 3 — Rito de ingreso en casa abierta ✅
- Viento del Norte (linaje Tempestad) acciona «Solicitar ingreso» →
  **modal solemne**: casa nombrada, lealtad indivisible y convalecencia
  futura como `role=alert` en el cuerpo.
- Confirmar → 201, membresía nacida en `clan_members`,
  `users.clan_id` espejo coherente, tarjeta pasa a «Tu hermandad» con la
  leyenda de lealtad propia y **asiento `CLAN_MEMBER_JOINED`** en la
  Bitácora por el actor postulante.
- Sin residuales que anular (ermitaño sin peticiones previas): correcto.

## Paso 4 — Rito de petición formal: molde, remitir, retirar, clausura ✅
- Brisa en Liberdad (linaje Mareas) sobre Mareas de Aether
  (`byApplication`): compositor con contador vivo 0/500, borde 19 →
  botón de remisión deshabilitado, 20+ habilita.
- Remisión → **201** con la petición `pending` (motivación íntegra).
- Reintento → **409 `APPLICATION_ALREADY_PENDING`** con leyenda solemne
  en la región viva (sin trazas).
- Retirada desde el inventario → fila `cancelled` persistida; inventario
  «1 de 3» con la fila «Retirada — Dictamen a la espera de lectura».
- Segundo intento → **403 `APPLICATION_HOUSE_CLOSED`** con la leyenda del
  Anexo A («Ya pronunciaste tu palabra ante esta casa…»).

## Paso 5 — Dictamen del Patriarca ✅
- Alta Marea (Patriarca de Mareas) delibera `approve` → membresía nacida,
  **asiento `CLAN_APPLICATION_VERDICT` por el PATRIARCA** con la
  justificación nombrando a la postulante.
- Rótulo de la postulante: `unreadVerdictsCount` = 1 → acknowledge →
  **0** con `verdict_seen_at` sellado (idempotencia verificada en la
  Fase 7 por su arnés).

## Paso 6 — Estados vedados ✅
- **Convaleciente** (Élitro de Ámbar): contempla el catálogo con la
  leyenda «Descansa en Convalecencia Arcana: tus puertas se abren en
  14 días.», `gesture = null` (ningún botón ofrecido).
- **Militante** (Viento del Norte): su casa con «Ya habitas esta
  hermandad: tu lealtad vive en sus salas.», sin gesto en ninguna.

## Paso 7 — Peregrino y Supremo sin linaje ✅
- **Peregrino por URL directa `#/vestibulo`** (Brisa de Sal, SPEC-09):
  la vista narra la retención — «El santuario aguarda tu juramento: nadie
  pisa sus salas sin linaje jurado. Vuelve al Umbral para jurar tu
  linaje.» — **sin botón de reintento** (conduce al juramento, no a
  reintentar lo vedado). El backend responde 403
  `LINEAGE_OATH_REQUIRED`.
- **Admin Supremo sin linaje:** aviso del Privilegio Fundacional (403
  `ADMIN_LINEAGE_REQUIRED`, `recoveryAction: VIEW_PUBLIC_HALL`) narrado
  en el aviso solemne; identidad «Supremo Peregrino — Peregrino sin
  Linaje» en la navbar (SPEC-09).

## Paso 8 — Teclado, foco, reduced-motion, consola ✅
- 22 elementos enfocables en el Vestíbulo; foco visible con el anillo
  canónico (`:focus-visible` en la hoja).
- Bloque `@media (prefers-reduced-motion: reduce)` presente en
  `vestibule.css` (verificado por fetch del CSS).
- Consola sin excepciones JavaScript tras limpiar (los 401/403 previos
  son las respuestas esperadas del paso 7).

## Hallazgos corregidos durante el recorrido
1. **`children.length = 0` en `openComposer`** (bloqueante): TypeError
   sobre la HTMLCollection viva del navegador real — el rito formal
   jamás se abría. Corregido con `replaceChildren()`.
2. **Retención silenciada en carga AJAX:** la vista derivaba
   `LINEAGE_OATH_REQUIRED`/`ADMIN_LINEAGE_REQUIRED` al aviso de red
   («La corriente de maná se ha interrumpido»). Corregido: la leyenda
   del sobre manda y el reintento se oculta en la retención.
3. **Referencias técnicas en leyendas (Artículo V):** «(RF-01.6)» y
   «(Art. III.1/III.2)» visibles al usuario. Purificadas en
   `ClanVestibuleService` y en la leyenda del linaje neutro.

## Regresión final en verde
- Los 7 arneses frontend del Vestíbulo: EXITO.
- Guard de cobertura CSS: 2/0.
- Cierre formal `test_spec07_closure`: EXITO (63 auditorías, 213 suites,
  6.734 asertos).
