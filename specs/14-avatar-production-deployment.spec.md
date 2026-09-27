# SPEC-14: La Efigie en Producción — Paridad de Despliegue del Avatar Propio en InfinityFree

> **Constitución:** [`constitution.md`](constitution.md) | **Directrices:** [`AGENTS.md`](AGENTS.md)
> **Estado:** RATIFICADA por el Arquitecto (con la revisión de coherencia previa a la implementación: contrato 200 del alta, funnel raíz como garantía principal, chemin por defecto + define opcional, honestidad documental ante `post_max_size`).
> **Área:** Despliegue-paridad del ciclo de vida de la efigie propia (SPEC-12, RF-03.2–RF-03.6) en el alojamiento de producción (InfinityFree)
> **Restricción:** Definición estricta del QUÉ y el POR QUÉ. Los mecanismos de implementación (nombres de ficheros, constantes) se fijan solo cuando SON el contrato de despliegue; el resto pertenece al plan técnico.
> **Precedente:** SPEC-13 (paridad de dialecto MySQL) estableció el género de «enmienda de despliegue»: la función existe y está ratificada; la spec garantiza que el entorno de producción pueda ejercerla sin mengua de sus garantías.

---

## 1. Contexto y Objetivo

El ciclo de vida de la efigie propia está **implementado y verificado en local** como Tarea 2.2 de SPEC-12 (`POST /api/v1/panel/avatar` modo `own` con multipart; validación de formato `png|jpg|webp`, peso ≤ 2 MiB, lados ≤ 1024 px; encuadre ceremonial GD 512×512; alta atómica fichero→UPDATE→asiento `AVATAR_SELF_MODIFIED`; servicio privado por `GET /api/v1/panel/avatar/image` con guardia de sesión). La verificación de producción de la SPEC-13 confirmó que la identidad expone `avatarKind`/`avatarReference`/`identity.avatar` y que el endpoint de imagen responde el 404 controlado `AVATAR_IMAGE_UNAVAILABLE` — es decir, la maquinaria de lectura ya vive en producción.

**El hueco está en la ESCRITURA y en su VERIFICACIÓN sobre el alojamiento real.** La topología de InfinityFree difiere del entorno local en cuatro aspectos que hoy comprometen o sin verificar dejan el alta de la efigie:

1. **El chemin del almacenamiento:** el servicio recibe `dirname(__DIR__) . '/storage/avatars'` (hermano de `public/`). Con el repositorio COMPLETO subido a `htdocs/` — la topología canónica de la guía — esa derivación coincide con `htdocs/storage/avatars` y es correcta; el riesgo es de FRAGILIDAD, no de rotura: ante cualquier topología distinta (subida parcial, symlink, rearranque del árbol) el chemin se resuelve en silencio hacia un lugar equivocado y el alta muere con 500 `AVATAR_STORE_FAILED`. Además, hoy no existe canal de configuración de despliegue para la raíz de efigies: `env.php` materializa el DSN pero ningún `define` para el chemin.
2. **La privacidad del fichero:** RF-03.3 de SPEC-12 declara la efigie propia **privada del propio adepto**. El funnel canónico ya la ampara — `htaccess-root` contiene `RewriteRule ^storage/ - [F,L]`, que niega por URL todo el árbol `storage/`, y `Options -Indexes` ciega el listado — PERO esa garantía nunca se ha verificado en producción ni está cubierta por sonda: si el `.htaccess` raíz faltare, se corrompiere durante una subida FTP o el hosting ignorare la directiva, cualquier URL directa expondría la imagen SIN guardia de sesión.
3. **Las capacidades gráficas del sandbox:** el encuadre ceremonial exige las funciones GD del canon (`imagecreatefrompng`, `imagecreatefromjpeg`, `imagecreatefromwebp`, `imagecreatetruecolor`, `imagecopyresampled`, `imagepng`). El hosting gratuito no las garantiza; si faltare alguna, el alta fracasa en tiempo de ejecución con un 500 opaco en lugar de un aviso noble.
4. **Los límites de transporte del hosting:** el canon de SPEC-12 (2 MiB de fichero, 4 MiB de petición → 413) se diseñó «para dejar margen a las cuotas de ficheros de storage/» (duda 4 sellada de SPEC-12), pero los topes reales de `upload_max_filesize`/`post_max_size` del sandbox nunca se midieron. Si el hosting impone menos, el adepto recibiría un rechazo que no nombra el motivo verdadero.

El objetivo de esta especificación es cerrar esos cuatro flancos con garantías verificables, de modo que el acto de vestir la efigie propia —prometido en SPEC-12 RF-03.2— quede **plenamente ejercitable en producción**, sin funcionalidad nueva, sin cambios de DDL y sin tocar el canon ya ratificado.

---

## 2. Actores y Usuarios

* **El Adepto (vinculado con linaje jurado):** beneficiario final; podrá subir, reemplazar y retirar su efigie desde el panel en producción, con los mismos avisos nobles que en local.
* **El Arquitecto Fundador:** ejecuta los actos de despliegue (crear el directorio del almacenamiento, instalar la protección por URL, subir la sonda, borrarla tras uso) conforme a la guía de `deploy/infinityfree/`.
* **La Sonda de Diagnóstico (herramienta transitoria):** scripts efímeros bajo `deploy/infinityfree/` que verifican chemin, permisos, GD y topes de transporte en el hosting real; jamás imprimen credenciales ni trazas internas y se eliminan del servidor tras su uso (convención ya establecida con `probe-env.php` y `probe-mysql.php`).

---

## 3. Historias de Usuario

* **HU-01 (La efigie que cruza el umbral):**
  *Como* adepto linajado en producción,
  *Quiero* subir una imagen desde mi panel y verla vestida en mi identidad,
  *Para* que la promesa de SPEC-12 RF-03.2 no viva solo en el taller local.

* **HU-02 (El retrato que nadie roba):**
  *Como* adepto,
  *Quiero* que mi efigie solo se sirva a través de mi sesión,
  *Para* que ninguna URL directa ni adivinanza de nombre de fichero la exponga ante terceros (RF-03.3 de SPEC-12).

* **HU-03 (El aviso que nombra la causa verdadera):**
  *Como* adepto cuya imagen excede un tope del hosting (no del canon),
  *Quiero* un aviso solemne que nombre el motivo real,
  *Para* no culpar a mi imagen de un fracaso que es del transporte.

* **HU-04 (El diagnostico antes del despliegue):**
  *Como* Arquitecto Fundador,
  *Quiero* una sonda que verifique chemin, permisos, GD y topes del sandbox con un veredicto accionable,
  *Para* no descubrir los flancos rotos a través de los avisos de los adeptos.

---

## 4. Requisitos Funcionales (Notación EARS en Español)

### RF-01: Resolución del Almacenamiento de Efigies en Producción

* **RF-01.1 [Ubicuo]:**
  CUANDO el backend resuelva la raíz física de las efigies propias en producción, DEBERÁ obtener un directorio EXISTENTE y ESCRIBIBLE por el proceso PHP del hosting. La derivación automática vigente (`dirname(__DIR__)` del front controller) DEBERÁ consolidarse como valor POR DEFECTO, precedido por un canal de configuración del despliegue (`define` en `env.php`, hermano de los `GRIMORIO_DB_*` ya ratificados): quien despliegue con topología no canónica podrá declarar la raíz sin tocar código.
* **RF-01.2 [Dirigido por Eventos]:**
  SI la raíz declarada no existiere o no fuere escribible en el momento del alta, ENTONCES el sistema DEBERÁ responder con el aviso controlado vigente (`AVATAR_STORE_FAILED`, 500) sin mutar la efigie vigente ni dejar fichero huérfano (atomicidad ya ratificada, caso límite 16 de SPEC-12) — y la sonda de RF-03 DEBERÁ haberlo detectado ANTES del despliegue, con veredicto accionable.
* **RF-01.3 [Ubicuo]:**
  El chemin resuelto en producción DEBERÁ vivir fuera del alcance directo de URL del escaparate. Con la topología canónica de la guía, el directorio canónico es `htdocs/storage/avatars` — bajo el árbol servible — y su negación por URL queda amparada por el funnel existente de `htaccess-root` (`^storage/` → 403), verificado por la sonda de RF-03. El directorio de efigies JAMÁS compartira ficheros con la base legada de SQLite (`storage/grimorio_live.sqlite` ya no existe en el despliegue MySQL de producción; el directorio queda libre para uso exclusivo de efigies).

### RF-02: Privacidad Absoluta del Fichero de Efigie

* **RF-02.1 [Ubicuo]:**
  La ÚNICA vía de lectura de una efigie propia DEBERÁ seguir siendo `GET /api/v1/panel/avatar/image` con guardia de sesión previa a la lectura de disco (implementación vigente, commit `23f4f24`). Ninguna URL estática, índice de directorio ni regla de reescritura podrá servir el fichero directamente.
* **RF-02.2 [Estado]:**
  MIENTRAS el directorio de efigies viva bajo el árbol servible del hosting (`htdocs/`), su negación por URL DEBERÁ quedar garantizada por el funnel canónico de `htaccess-root` (`RewriteRule ^storage/ - [F,L]` y `Options -Indexes`, ya instalados). La instalación de un `.htaccess` de denegación ADICIONAL dentro del propio directorio de efigies es cinturón de profundidad opcional, jamás sustituto: la garantía principal no puede depender de un fichero que una re-subida FTP podría omitir.
* **RF-02.3 [Dirigido por Eventos]:**
  SI una petición directa intentara servir una efigie por URL estática, ENTONCES el servidor DEBERÁ responder con negación limpia (403 o 404, según la capa que la rechace) sin revelar existencia, tamaño ni contenido de fichero alguno.
* **RF-02.4 [Ubicuo]:**
  El nombre de fichero de cada efigie DEBERÁ seguir siendo aleatorio y NO derivado del alias (RNF-04 de SPEC-12, ya implementado): la defensa por nombre es cinturón de profundidad, nunca la garantía principal.

### RF-03: La Sonda de Diagnóstico de Efigie (verificación previa al despliegue)

* **RF-03.1 [Ubicuo]:**
  El sistema DEBERÁ disponer de una sonda de diagnóstico bajo `deploy/infinityfree/` —hermana de `probe-env.php` y `probe-mysql.php`, e instalada en producción como `public/probe-avatar.php` según la misma convención— que verifique en el hosting real, en una sola visita y con veredicto accionable: (a) resolución del chemin de efigies y su existencia/escritura; (b) presencia y funcionamiento de las funciones GD del canon con una codificación y decodificación real 512×512; (c) los topes efectivos de `upload_max_filesize` y `post_max_size` del sandbox; (d) que el funnel raíz sigue vivo y negando `storage/` (lectura del `.htaccess` por sistema de ficheros, como ya hace `probe-env.php`, y petición de prueba por URL con veredicto de negación).
* **RF-03.2 [Ubicuo]:**
  La sonda JAMÁS imprimirá credenciales, trazas internas ni contenido de ficheros; su veredicto será una lista de comprobaciones EXITO/FALLO con causa noble y acción remedial en castellano.
* **RF-03.3 [Dirigido por Eventos]:**
  CUANDO la sonda cumpliera su oficio, DEBERÁ ser borrada del servidor (convención de las sondas del proyecto); su permanencia en producción se considera incidencia de despliegue.

### RF-04: Los Topes de Transporte del Hosting

* **RF-04.1 [Ubicuo]:**
  El canon de validación vigente (fichero ≤ 2 MiB, lados ≤ 1024 px, petición ≤ 4 MiB → 413) DEBERÁ conservarse íntegro: esta enmienda JAMÁS lo amplía ni lo reduce.
* **RF-04.2 [Dirigido por Eventos]:**
  SI el hosting impusiere topes de transporte INFERIORES al canon, ENTONCES el despliegue DEBERÁ documentar los valores reales en la guía de InfinityFree. La narración del aviso distingue DOS subcasos de honestidad: (i) SI el fichero excede `upload_max_filesize` sin exceder `post_max_size`, PHP lo entrega con `UPLOAD_ERR_INI_SIZE` y el sistema DEBERÁ responder con aviso que nombre el tope del transporte en lugar del 400/`AVATAR_TOO_LARGE` del canon; (ii) SI el envío entero excede `post_max_size`, PHP vacía `$_FILES` y `$_POST` ANTES de que la aplicación ejecutara — el aviso noble específico es entonces TÉCNICAMENTE IMPOSIBLE y la honestidad se garantiza por la vía documental: la sonda (RF-03.1c) medirá el tope real y la guía lo publicará. El adepto jamás recibirá un aviso que mienta sobre la causa allí donde la aplicación tiene voz.
* **RF-04.3 [Ubicuo]:**
  La denegación por exceso SHALL seguir distinguiendo petición (413) de fichero (400) conforme al reparto ya implementado en el controlador; esta enmienda no reordena los códigos.

### RF-05: Verificación de Extremo a Extremo en Producción

* **RF-05.1 [Ubicuo]:**
  El despliegue no se considerará completo sin el ciclo REAL en producción con una cuenta de prueba: alta de efigie válida (**200** con asiento — el contrato vigente de `POST /api/v1/panel/avatar` responde 200, no 201), lectura de la imagen solo con sesión, reemplazo (el fichero anterior deja de existir, RF-03.4 de SPEC-12), re-subida idéntica rechazada (`AVATAR_IDENTICAL` sin asiento) y retiro (canónico + fichero borrado).
* **RF-05.2 [Dirigido por Eventos]:**
  SI cualquier eslabón del ciclo fracasare en producción, ENTONCES el despliegue quedará declarado PARCIAL y la guía documentará el estado real y el flanco roto; la identidad del adepto conservará en todo momento una efigie (canónica o propia) — jamás quedará sin retrato.

### RF-06: Intangibilidad Constitucional

* **RF-06.1 [Ubicuo]:**
  Esta enmienda NO introduce funcionalidad nueva, NO modifica DDL alguno (la Regla de los Gemelos de SPEC-13 §8 queda intacta: ni `schema.sql` ni `schema-mysql.sql` cambian), NO altera el catálogo cerrado de actos de la Bitácora (el alta/retiro sigue inscribiendo `AVATAR_SELF_MODIFIED`), NO añade dependencia externa alguna (Artículo I: GD nativa y Apache nativo) y NO altera el contrato HTTP del alta (200 con `data.avatar`, sin cambio de códigos).
* **RF-06.2 [Ubicuo]:**
  Todo cambio de código que esta enmienda autorice se limita a: el `define` opcional del chemin en `env.php` y su lectura en el front controller (la derivación automática queda como valor por defecto), la sonda de diagnóstico y la documentación de `deploy/infinityfree/README.md`. Cualquier otra mutación exige enmienda propia.

---

## 5. Requisitos No Funcionales

* **RNF-01 (Dogma Vanilla):** protección del directorio con Apache nativo (`.htaccess`), sin paneles, sin CDN, sin librerías.
* **RNF-02 (El Velo Arcano):** las causas de fallo del almacenamiento se narran en avisos controlados en castellano; jamás trazas de PHP, rutas absolutas del hosting ni nombres de funciones.
* **RNF-03 (Seguridad en profundidad):** tres capas independientes protegen la efigie: (1) guardia de sesión en el endpoint de servicio, (2) negación Apache del directorio, (3) nombre aleatorio no derivado del alias. El fallo de una sola capa no expone nada.
* **RNF-04 (Portabilidad del despliegue):** la guía de `deploy/infinityfree/README.md` incorporará una sección «La efigie en producción» replicable por cualquier adepto que despliegue su propia copia.

---

## 6. Casos Límite Cobrados

1. **Chemin inexistente en producción:** alta → 500 `AVATAR_STORE_FAILED` sin huérfano; la sonda lo detecta antes del despliegue.
2. **Directorio servible sin funnel raíz (`.htaccess` raíz ausente o corrupto tras una subida FTP):** la URL estática directa expondría la imagen; la sonda lo detecta por doble vía (comprobación RF-03.1d: lectura del `.htaccess` + petición de prueba por URL con veredicto de negación).
3. **GD sin `webp` en el sandbox:** el formato webp fracasaría al decodificar → 500; la sonda lo detecta y la guía recomienda restringir el canon anunciado o resolverlo con el hosting.
4. **Tope de transporte menor al canon:** subida de fichero entre el tope del hosting y 2 MiB → aviso que nombra el tope real (RF-04.2), jamás el 413 canónico.
5. **Huérfanos por fracaso del alta:** el purgado de huérfanos ya implementado (caso límite 16 de SPEC-12) DEBERÁ seguir vigente tras la paridad; la sonda no altera el flujo.
6. **Sonda olvidada en producción:** convención de borrado obligatorio (RF-03.3); el checklist de aceptación la cobra.

---

## 7. Dudas Abiertas (para ratificación del Arquitecto)

1. **¿Bajo `htdocs/` o fuera de él?** La opción canónica de la guía es `htdocs/storage/avatars` — el funnel raíz ya lo niega por URL y solo `htdocs/` es escribible en el sandbox —; la opción paranoica (chemin fuera de `htdocs/`) no es viable en InfinityFree. **Propuesta sellada:** directorio canónico `htdocs/storage/avatars`, negación por funnel existente, `.htaccess` interno opcional y verificación por sonda; el `define` de `env.php` queda como válvula para topologías ajenas.
2. **Permisos finos del directorio:** la guía existente recomienda 700 para `storage/`; ¿se ratifica 700 también para el directorio de efigies (el proceso PHP del sandbox escribe como el dueño FTP, por lo que 700 suele bastar)? **Propuesta:** 700, verificado por sonda.
3. **Valores reales de `upload_max_filesize`/`post_max_size`:** se MEDIRÁN con la sonda y se fijarán en la guía; esta spec no los presupone.

---

## 8. Criterios de Aceptación

* [ ] La sonda de diagnóstico existe en `deploy/infinityfree/`, pasa en producción con todas sus comprobaciones EXITO, y ha sido borrada del servidor.
* [ ] El chemin de efigies resuelto en producción es existente y escribible, y su resolución no depende de rutas automáticas frágiles.
* [ ] Una petición directa por URL a una efigie conocida responde negación limpia (403/404), jamás la imagen.
* [ ] El ciclo completo RF-05.1 (alta → lectura con sesión → reemplazo → idéntica rechazada → retiro) se ha ejecutado con éxito en producción con cuenta de prueba.
* [ ] Los avisos de rechazo nombran siempre el motivo verdadero (canon o transporte).
* [ ] `schema.sql`, `schema-mysql.sql` y el catálogo de actos de la Bitácora quedan sin cambios (verificable por diff).
* [ ] `deploy/infinityfree/README.md` documenta la sección «La efigie en producción» con los pasos de directorio, protección y verificación.
