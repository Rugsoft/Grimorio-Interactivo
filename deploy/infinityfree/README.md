# DESPLIEGUE GRATUITO EN INFINITYFREE — Guía de Instalación

> Objetivo: ver el Grimorio Interactivo online gratis con API funcional
> (sesiones, catálogo, tomo, elogio) y datos persistentes.
>
> Constitución: Artículo I (todo nativo, sin dependencias ni build);
> AGENTS.md 6.1 (src/, specs/, scratch/ y database/ jamás alcanzables
> por URL); SPEC-03 (HTTPS obligatorio para la cookie de sesión).

---

## 0. Qué se instala (4 ficheros desde `deploy/infinityfree/`)

| Fichero en el repo | Se copia como | Dónde (en el hosting) |
|---|---|---|
| `deploy/infinityfree/htaccess-root` | `.htaccess` | Raíz del hosting (`htdocs/`) |
| `deploy/infinityfree/htaccess-public` | `.htaccess` | `htdocs/public/` |
| `deploy/infinityfree/user-ini` | `.user.ini` | `htdocs/public/` (solo si el servidor usa PHP-CGI/FastCGI; en mod_php lo ignora sin daño) |
| `deploy/infinityfree/env.php` | `env.php` | `htdocs/public/` |

> **Canales de carga de `env.php`**: el front controller
> `public/index.php` lo invoca directamente (`require_once` con guardia
> `is_file`) — es el canal PRINCIPAL y funciona en cualquier SAPI. En el
> sandbox de InfinityFree el `auto_prepend_file` está monopolizado por el
> propio servidor (`php_admin_value` hacia su script interno
> `/var/www/errors/override.php`, no sobrescribible), por eso los
> canales .htaccess/`php_value` y `.user.ini` del paquete son inertes
> ALLÍ y el `require` del front controller es el que manda. El DSN no
> viaja por el prepend: lo materializa `env.php` vía `define` ANTES de
> que `Connection.php` lea la configuración (la vía `putenv` está muerta
> en el sandbox: `putenv` figura en `disable_functions` y la llamada
> muere en silencio — véase la sección «Migración sobre SQLite en
> producción»).

---

## 1. Crear la cuenta y el hosting

1. Regístrate en <https://www.infinityfree.com> y pulsa **Create Account**
   (hosting gratuito). Elige un subdominio tipo `grimorio.infinityfreeapp.com`.
2. En **Account Details** del panel anota dos datos:
   - La **ruta absoluta** de tu cuenta (p. ej.
     `/home/vol12_3/infi0000/tugrimorio.000webhostapp.com/htdocs`) — la
     necesitas en el paso 4.
   - Las credenciales **FTP** (host, usuario, contraseña).

## 2. Seleccionar PHP 8.3

En el panel: **Select PHP Version** → **PHP 8.3** (mínimo 8.2, Artículo I).

## 3. Subir el repositorio a `htdocs/`

Con FileZilla (o el File Manager web), sube el contenido COMPLETO del
repositorio a `htdocs/`. Debe quedar así:

```
htdocs/
├── .htaccess          ← htaccess-root renombrado (paso 5)
├── src/               ← backend privado
├── public/            ← escaparate (index.html, index.php, assets/)
│   ├── .htaccess      ← htaccess-public renombrado (paso 5)
│   ├── .user.ini      ← user-ini renombrado (paso 5)
│   └── env.php        ← copiado tal cual
├── database/          ← schema.sql y seeds.sql (el auto-bootstrap los usa)
├── specs/  scratch/  sql/  ...   (quedan blindados por el funnel)
```

> Consejo: sube primero el repositorio completo; los 4 ficheros de
> configuración renómbralos AL FINAL (paso 5) para que Apache no los
> lea a medias durante la subida.

## 4. Ajustar la ruta en `public/env.php`

Abre `htdocs/public/env.php` en el File Manager (Edit) y sustituye:

```php
$projectRoot = '/TU_RUTA_ABSOLUTA/htdocs';
```

por la ruta absoluta real anotada en el paso 1. Este fichero materializa
el DSN `sqlite:<storage>/grimorio_live.sqlite` ANTES de cada petición
(vía `auto_prepend_file`), para que datos y sesiones sean PERSISTENTES
(el fallback `sqlite::memory:` de `Connection.php` borra todo en cada
petición y solo sirve para desarrollo).

## 5. Renombrar los ficheros de configuración

En el File Manager:

1. `htdocs/htaccess-root` → renombrar a `htdocs/.htaccess`
2. `htdocs/public/htaccess-public` → renombrar a `htdocs/public/.htaccess`
3. `htdocs/public/user-ini` → renombrar a `htdocs/public/.user.ini`

(O si subiste ya renombrados, no hagas nada.)

## 6. Proteger el directorio de la base (opcional pero recomendado)

El funnel ya bloquea `/storage/` por URL. Cinturón adicional:
en el File Manager, crea si no existe `htdocs/storage/` y ponle
permisos **700**. Ahí nacerá `grimorio_live.sqlite`.

## 7. Activar HTTPS

En el panel: **Free SSL Certificates** → pide el certificado para tu
subdominio y espera la propagación (minutos-horas). El `.htaccess` raíz
ya eleva todo el tráfico HTTP a HTTPS con redirección 301; la cookie de
sesión de SPEC-03 exige HTTPS (`secure = true`), así que sin este paso
no habrá sesión que funcione.

## 7b. Seguridad de sesión y procedencia (SPEC-15)

Estado de las garantías de sesión en este hosting (verificado el
2026-09-28 con la sonda del entorno y la evidencia del panel):

- **Topología:** apache2handler (PHP 8.4), SSL automático en subdominios,
  SIN Cloudflare propio ni offload TLS hacia PHP. La señal `HTTPS=on`
  que PHP recibe es **directa del servidor**: no hay cabecera intermedia
  de la que fiarse (o desconfiar) para el esquema.
- **Cookie de sesión:** `grimorio_session` ya se emite con `Secure`,
  `HttpOnly`, `SameSite=Strict` y `Path=/` (RF-01 de SPEC-15). No requiere
  acción del custodio.
- **Procedencia del limitador (RF-03):** la única autoridad es
  `REMOTE_ADDR` validada. Las cabeceras reenviadas (`X-Forwarded-For` y
  semejantes) se **ignoran por completo**: en este hosting llegan
  idénticas a `REMOTE_ADDR` (o ausentes) y ningún cliente puede elegir su
  clave en el limitador enviando una cabecera.

**Configuración (deploy/infinityfree/env.php):**

- `GRIMORIO_TRUSTED_PROXY_IPS` — lista de proxies confiables. Permanece
  **VACÍA por política ratificada** (no existe evidencia de proxies
  intermedios). Solo se poblará si el soporte del hosting acredita IPs
  estables de sus proxies y el saneamiento de sus cabeceras, registrando
  esa evidencia en TASKS-15. Valores válidos: strings IPv4/IPv6 exactas
  (sin CIDR, sin hostnames).
- `GRIMORIO_COOKIE_SECURE` — refuerzo opcional para forzar `Secure`.
  Innecesario en la topología actual (la señal directa ya es fiable);
  reservado para un futuro offload TLS que ocultara `HTTPS=on`. Jamás
  establecerlo a `false` en producción.

**Límite conocido (no verificable sin staging):** al agrupar el limitador
por `REMOTE_ADDR`, muchos visitantes detrás de una misma IP compartida
(NAT corporativo, CGNAT móvil) comparten la misma ventana de bloqueo de
fuerza bruta. Es la contrapartida aceptada de no confiar en cabeceras
falsificables (RNF-01); se reevaluará solo con evidencia de proxy.

## 8. Primera visita

Abre `https://TU_SUBDOMINIO.infinityfreeapp.com/`:

1. Apache canaliza `/` → `public/` → sirve `index.html` (el shell de la SPA).
2. La SPA llama a `/api/v1/...` → `public/.htaccess` las envía a
   `index.php` → el Router despacha (REQUEST_URI conserva la ruta original,
   el funnel no la mutila).
3. La primera petición a la API dispara el **auto-bootstrap** de
   `Connection.php`: crea el esquema (`database/schema.sql`) y siembra los
   datos (`database/seeds.sql`) en `storage/grimorio_live.sqlite`.
4. Entra con el admin sembrado en `seeds.sql` y verifica el catálogo, el
   juramento de linaje y tu Tomo Personal.

## 9. Verificación post-despliegue

- `https://TU_SUBDOMINIO/api/v1/spells` → JSON del catálogo (`success: true`).
- `https://TU_SUBDOMINIO/src/Core/Router.php` → **404** (blindaje del funnel).
- Registro + juramento + sellado en el tomo → los datos sobreviven a una
  recarga (SQLite persistente activo).
- **Sonda del entorno** (recomendada tras la primera instalación): sube
  `deploy/infinityfree/probe-env.php` como `htdocs/public/probe-env.php`,
  ábrela en el navegador y comprueba que responde
  `"dsnMaterializado": true` y `"ficheroSqliteBytes"` mayor que cero.
  Si da `false`, el DSN no viaja: revisa los pasos 4-5. **Bórrala del
  servidor tras el diagnóstico.**

## 9b. Verificación de sesión y revocación en producción (SPEC-15, Tarea 4.3)

Procedimiento de BAJO IMPACTO para comprobar las garantías de sesión con
una cuenta de ensayo. Requiere autorización expresa del custodio y JAMÁS
muta datos de usuarios reales. La verificación local (`scratch/test_spec15_local.php`,
67/67 EXITO) ya cubre la lógica; esta comprobación valida la topología real.

**Preparación:**

1. Consagra una cuenta de ensayo con datos ficticios (p. ej.
   `Ensayo<fecha>`, correo desechable del dominio de prueba). No uses
   cuentas reales ni del admin sembrado.
2. Abre DevTools → pestaña *Application* (o *Storage*) → Cookies. Las
   capturas deben mostrar SOLO atributos: jamás copies ni compartas el
   VALOR de la cookie (es la credencial de sesión).

**Comprobaciones permitidas (en orden):**

1. **Cookie del vínculo (RF-01):** entra con la cuenta de ensayo sobre
   `https://` y verifica en la cookie `grimorio_session`: `Secure`,
   `HttpOnly`, `SameSite=Strict` (Lax en la columna de DevTools significa
   `Strict` sin URL: confírmalo con el detalle), `Path=/`, `Max-Age=1209600`.
2. **Señal falsificada (RF-01.3):** desde la consola del navegador NO es
   posible falsificar cabeceras de petición al navegar; omite esta
   comprobación manual — ya está asertada por el arnés local (§8 caso 2)
   y la política del código no lee cabeceras. No intentes proxy/man-in-the-middle:
   fuera del alcance permitido.
3. **Disolución global (RF-04.1):** con la cuenta de ensayo, activa
   «Disolver todos los vínculos». DevTools debe mostrar que la respuesta
   porta `Set-Cookie: grimorio_session=...; Max-Age=0` (o `Expires` en el
   pasado) con el mismo `Path=/` y `HttpOnly`; la cookie desaparece del
   almacén del navegador. Refresca: la sesión NO se restablece.
4. **Renuncia (RF-04.2, RF-09):** re-entra con la cuenta de ensayo y
   renuncia. La respuesta porta el mismo `Set-Cookie` expiratorio; el
   navegador queda anónimo. Verifica después que el alias de la cuenta ya
   no es el original (la autoría legada se exhibe como «Erudito Ancestral
   (Legado Anónimo)», RF-09.3) y que sus borradores ya no existen (RF-09.4).
5. **Cookie copiada tras revocar (RF-04.4):** ANTES de revocar, copia el
   valor de la cookie (solo para este ensayo). Tras la disolución, envía
   una petición a `/api/v1/auth/session` con esa cookie (curl con
   `-H "Cookie: grimorio_session=<valor>"`). La respuesta debe ser
   `authenticated: false`. **Borra el valor copiado al terminar** (portapapeles
   e historial del terminal).
6. **No filtración (§9 criterio 9):** las respuestas de la API no incluyen
   la IP de cliente ni cabeceras de procedencia: basta leer los cuerpos
   JSON de las comprobaciones anteriores.

**Estado de las garantías tras la verificación (plantilla honesta):**

- Emisión de cookie con banderas completas en HTTPS: VERIFICADO LOCALMENTE
  (arnés 67/67) y PENDIENTE de confirmar en producción (comprobación 1).
- Expiración de cookie en disolución global y renuncia: VERIFICADO
  LOCALMENTE y PENDIENTE en producción (comprobaciones 3-4).
- Rechazo del token revocado: VERIFICADO LOCALMENTE y PENDIENTE en
  producción (comprobación 5).
- Cabeceras falsificadas: VERIFICADO LOCALMENTE; no verificable manualmente
  en producción sin infraestructura de intermediación (no permitida).
- Cabeceras de procedencia del hosting (XFF ≡ REMOTE_ADDR, sin Cloudflare):
  VERIFICADO con la sonda del entorno (sección 7b).

Una garantía sin confirmación en producción queda **no verificada** (RF-05.4):
no se declara cumplimiento pleno por inferencia. Registra fecha, resultados
mínimos (atributos, veredictos true/false) y limitaciones en esta misma
sección tras ejecutarla. **No generes asedios de login** (el limitador
congelaría la IP compartida del hosting ni afectar solo a tu ensayo).

**Ejecución registrada (2026-09-28, arnés `scratch/verify_spec15_production.php --autorizado`):**

- Veredicto: **13 asertos superados, 4 fallidos**. Nota de método: el
  hosting interponía el challenge anti-bot (`__test`, AES-256-CBC estático
  con capa JS); el arnés lo resuelve automáticamente.
- **VERIFICADO en producción:** cookie del vínculo con `Secure`+`HttpOnly`+
  `SameSite=Strict`+`Path=/`+`Max-Age=1209600` sobre HTTPS real (RF-01.1);
  control positivo de sesión (RF-02); **rechazo del token revocado tras
  disolución global** (RF-04.4); consagración 201 idempotente; no filtración
  de IP/cabeceras en los cuerpos (§9.9).
- **NO VERIFICADO con causa de despliegue:** Set-Cookie expiratorio tras
  disolución global (RF-04.1/04.3) y tras renuncia (RF-04.2/04.3), y
  renuncia exitosa con legado (RF-09.3). **Causa:** el build desplegado
  aún no contiene el código de las Fases 2–3 de SPEC-15 (expiración de
  cookie en el controlador, alias único RF-09.3). Localmente el arnés
  verifica 67/67 con ese código; la conformidad en producción exigirá
  **subir a `htdocs/` los ficheros tocados por las Fases 2–3**
  (`src/Core/SessionManager.php`, `src/Core/TrustedProxyResolver.php`,
  `src/Core/Request.php`, `src/Controllers/AuthController.php`,
  `src/Services/AuthService.php`, `src/Services/SpellAliasService.php`,
  `src/Dto/GrimoirePageDto.php`, `src/Services/SpellDiscoveryService.php`)
  y re-ejecutar el arnés. Estado honesto: esos 4 criterios quedan
  «no verificado — pendiente de desplegar Fases 2–3».

**Veredictos intermedios de la misma jornada (progresión del despliegue):**

- 2.ª ejecución (tras subir `LineageOathMiddleware`): **15 OK / 2 FALLA**
  — la renuncia pasa de 403 a 200 con éxito y token muerto (el juramento ya
  no bloquea el derecho al olvido); persisten las ausencias de
  Set-Cookie expiratorio por el resto del build antiguo.
- **EJECUCIÓN FINAL (2026-09-28, tras subir los 8 ficheros de las Fases
  2–3): 21 asertos superados, 0 fallidos — exit 0.** Conformidad COMPLETA
  de SPEC-15 en producción:
  - RF-01.1: cookie del vínculo con `Secure`, `HttpOnly`, `SameSite=Strict`,
    `Path=/`, `Max-Age=1209600` sobre HTTPS real.
  - RF-02: control positivo de sesión.
  - RF-04.1/04.3: la disolución global porta Set-Cookie expiratorio
    (`Max-Age=0`, `Expires` 1970) con el MISMO alcance que la emisión
    (`path=/`, `secure`, `HttpOnly`, `SameSite=Strict` — el forjador único
    verificado de punta a punta).
  - RF-04.2/04.3: la renuncia porta el mismo Set-Cookie expiratorio con
    alcance idéntico.
  - RF-04.4: el token revocado (disolución) y el token de la cuenta
    renunciada NO autentican peticiones posteriores.
  - RF-09.3: la renuncia responde 200 sin explosión UNIQUE (alias técnico
    único) — el derecho al olvido opera completo en producción.
  - §9.9: sin filtración de IP/cabeceras en los cuerpos.
  La cuenta de ensayo queda como legado anónimo (RF-09.3), conforme al
  procedimiento. Sin captura del valor de cookie en ningún registro (RNF-02).

- **RE-VERIFICACIÓN POST-RF-09.5 (2026-09-28, tras subir los 2 ficheros de
  la enmienda de atomicidad — `src/Services/AuthService.php` y
  `src/Controllers/AuthController.php`): 21 asertos superados, 0 fallidos
  — exit 0.** El flujo de renuncia ATÓMICO (transacción renuncia+asiento,
  enmienda RF-09.5 de SPEC-03) opera en producción sin degradar ningún
  contrato de SPEC-15: consagración 201, cookie íntegra, disolución con
  Set-Cookie expiratorio de alcance idéntico, renuncia 200 con el mismo
  cierre visible, tokens muertos tras ambas revocaciones y sin filtración.
  Con esta ejecución, producción queda ALINEADA con el árbol actual del
  repositorio (`a598f2b`) en todo lo relativo a sesión y renuncia.

## 9c. Despliegue del Umbral de la Fundación (SPEC-07b)

> Interfaz de creación de clanes (enmienda de superficie de SPEC-07
> RF-01.2). **Superficie pura de frontend**: 0 ficheros en `src/` y
> 0 en `database/` (verificado por diff dirigido en el cierre
> `5278990`). No hay migración de esquema: la tabla `clans` ya vive en
> producción desde las semillas. Basta subir los ficheros públicos.

### 1. Ficheros a subir a `htdocs/` (7, todos bajo `public/`)

| Fichero en el repo | Destino en el hosting | Estado |
|---|---|---|
| `public/index.html` | `htdocs/public/index.html` | Sobrescribir (añade `<dialog id="foundationModal">` y el `<link>` al CSS nuevo) |
| `public/assets/css/components/clan-foundation.css` | `htdocs/public/assets/css/components/clan-foundation.css` | **NUEVO** (crear) |
| `public/assets/js/components/clanFoundationModalComponent.js` | `htdocs/public/assets/js/components/clanFoundationModalComponent.js` | **NUEVO** (crear) |
| `public/assets/js/components/lineageHallComponent.js` | `htdocs/public/assets/js/components/lineageHallComponent.js` | Sobrescribir (gesto de fundación en cabecera del Salón) |
| `public/assets/js/views/lineageHallView.js` | `htdocs/public/assets/js/views/lineageHallView.js` | Sobrescribir (veredicto de sesión + despacho `foundClan`) |
| `public/assets/js/views/vestibuleView.js` | `htdocs/public/assets/js/views/vestibuleView.js` | Sobrescribir (segunda puerta RF-10.6 en el estado vacío) |
| `public/assets/js/main.js` | `htdocs/public/assets/js/main.js` | Sobrescribir (inyección `foundationDialog`) |

> Consejo de orden: sube primero el CSS y los JS nuevos/modificados y
> `index.html` AL FINAL — el shell referencia el CSS nuevo, y así
> ningún visitante intermedio ve un shell que pide un asset que aún
> no existe. Al terminar, recarga con caché forzada (Ctrl+F5): los
> assets no llevan versión en la URL.

### 2. Verificación con cuenta de ensayo (bajo impacto)

Requiere autorización del custodio; JAMÁS muta datos de cuentas reales.
La lógica ya está verificada localmente (arnés
`scratch/test_clan_foundation_modal.mjs`, 70/70, y regresiones hermanas
en verde); esto valida el despliegue real.

**Preparación:** consagra una cuenta de ensayo (patrón del §9b) y
consume el juramento de linaje (SPEC-09): el gesto de fundación exige
adepto JURADO — `lector`, `militante` y `convaleciente` lo tienen vedado
con `disabled` real (RNF-04).

**Comprobaciones (en orden):**

1. **Regresión del catálogo:** `https://TU_SUBDOMINIO/api/v1/spells`
   sigue respondiendo `success: true` (el shell y `main.js` nuevos no
   rompen la SPA).
2. **Assets servidos:** abre en el navegador
   `/assets/js/components/clanFoundationModalComponent.js` y
   `/assets/css/components/clan-foundation.css` → ambos deben servir el
   contenido (no 404; el funnel solo blinda `src/`, `database/` y
   `storage/`, jamás `public/assets/`).
3. **Primera puerta (Salón, RF-10.1/10.2):** con la cuenta de ensayo
   jurada, entra en `#/linajes`. La opción de fundación debe aparecer
   HABILITADA en la cabecera; al abrirla, el modal despliega los tres
   sellos (blasón oculto por decisión §10.2 — el payload viaja con
   `coatOfArms: ''` y el blasón lo viste después el Patriarca) y el
   régimen.
4. **Fundación real (RF-10.3):** nombra el clan de ensayo con un rótulo
   inequívoco de prueba (p. ej. `Ensayo Umbral <fecha>`), lema y régimen,
   y consagra. Debe responder **201**, el Salón se refresca mostrando el
   clan fundado y el gesto queda VEDADO por lealtad (un adepto solo
   funda una vez).
5. **Segunda puerta + veto real (RF-10.6/10.5):** consagra una SEGUNDA
   cuenta de ensayo jurada del mismo linaje y entra por el Vestíbulo:
   el estado vacío debe invitar a fundar (mismo modal). Intenta fundar
   con el nombre EXACTO del clan del paso 4 → la API responde el veto
   `NAME_ALREADY_RESERVED` y **el borrador (nombre/lema) permanece
   intacto** en el modal.
6. **Sello anti-doble-envío (RNF):** durante el paso 4, el botón de
   consagración debe quedar deshabilitado tras el primer envío (no deben
   nacer dos clanes gemelos).

**Nota de honestidad sobre los datos de ensayo:** la fundación crea
DATOS REALES en producción. El clan de ensayo y su cuenta persistirán
como legado; si el custodio desea limpiarlo, debe hacerlo como
`admin_supremo` desde el panel de moderación. No intentes borrar por
base: el hosting no ofrece CLI sobre SQLite/MySQL de la demo.

**Plantilla de veredicto (a registrar tras ejecutar):**

- Assets servidos (CSS + componente): PENDIENTE de confirmar.
- Fundación 201 con Salón refrescado y gesto vedado: PENDIENTE.
- Segunda puerta con veto `NAME_ALREADY_RESERVED` y borrador intacto:
  PENDIENTE.
- Sello anti-doble-envío: PENDIENTE.

Mientras quede algún PENDIENTE, SPEC-07b queda en producción como
«desplegada, no verificada» (misma doctrina de honestidad de §9b).
Registra aquí fecha, veredictos y limitaciones tras la ejecución.

## 9d. Despliegue de la cabecera agrupada y la portada recompuesta (SPEC-16)

> Enmienda de composición de SPEC-01 (`RF-02.1` y `RF-01.1`). **Superficie
> pura de frontend**: los dos commits de SPEC-16 tocan **0** ficheros en
> `src/` y **0** en `database/` (verificado por diff dirigido en el cierre,
> `3535356..HEAD`: 20 ficheros, +3153/−130). No hay migración de esquema ni
> cambio de contrato de API. Basta subir los ficheros públicos.

### 0. Antes de nada: el orden NO es opcional

SPEC-16 se despliega sobre un árbol que ya tiene una corrección pendiente. El
orden obligatorio es:

| # | Commit | Por qué va antes |
|---|---|---|
| 1 | `195d708` | **Bloqueante.** Corrige el Umbral de la Fundación: sin él, el modal se sirve con texto invisible y los ocho linajes en clave técnica inglesa. |
| 2 | `6be98d3` | Fase 1 de SPEC-16: cabecera agrupada, distintivo corto, `matchMedia` en `main.js`. |
| 3 | `d872efa` | Fase 2 de SPEC-16: sello de validación, portada recompuesta, cinta del Regente. |

> **Si subes SPEC-16 sin `195d708`, montas una cabecera nueva y una portada
> nueva sobre un Umbral roto.** No es un defecto cosmético: es texto que no se
> ve. Los 2 ficheros de `195d708` son `clan-foundation.css` y
> `clanFoundationModalComponent.js`.

> `3535356` (auditoría de tokens fantasma) es **independiente** y recomendable:
> arregla 13 variables CSS inexistentes en otras hojas. No es un requisito de
> SPEC-16 —los 9 ficheros de este apartado ya llevan sus propias correcciones
> acumuladas—, pero sin él el Vestíbulo, el Creador y el Códice siguen con
> texto ilegible.

### 1. Ficheros a subir a `htdocs/` (9, todos bajo `public/`)

Los dos commits de SPEC-16 tocan 5 ficheros públicos cada uno, con `clans.css`
compartido: **9 ficheros únicos, no 7**.

| # | Fichero en el repo | Destino en el hosting | Estado | Qué trae |
|---|---|---|---|---|
| 1 | `public/assets/css/layout.css` | `htdocs/public/assets/css/layout.css` | Sobrescribir | Una fila de cabecera (157 → 63 px), grupos, submenús, modo plano `<1024 px` |
| 2 | `public/assets/css/components/clans.css` | `htdocs/public/assets/css/components/clans.css` | Sobrescribir | Distintivo corto, efigie con respaldo, **cinta compacta del Regente** |
| 3 | `public/assets/css/components/library.css` | `htdocs/public/assets/css/components/library.css` | Sobrescribir | Sello de validación, título del héroe, portada recompuesta |
| 4 | `public/assets/js/main.js` | `htdocs/public/assets/js/main.js` | Sobrescribir | `setLayout()` cableado con `matchMedia` — **sin este, el móvil queda roto** |
| 5 | `public/assets/js/components/navbarComponent.js` | `htdocs/public/assets/js/components/navbarComponent.js` | Sobrescribir | `NAV_GROUPS` y el render de los grupos |
| 6 | `public/assets/js/components/userProfileBadge.js` | `htdocs/public/assets/js/components/userProfileBadge.js` | Sobrescribir | Alias sin linaje, identidad en el desplegable, ouroboros por defecto |
| 7 | `public/assets/js/components/landingSigilComponent.js` | `htdocs/public/assets/js/components/landingSigilComponent.js` | **NUEVO** (crear) | El sello de validación de la portada |
| 8 | `public/assets/js/components/clanBannerComponent.js` | `htdocs/public/assets/js/components/clanBannerComponent.js` | Sobrescribir | Variante `compact` del blasón |
| 9 | `public/assets/js/views/landingView.js` | `htdocs/public/assets/js/views/landingView.js` | Sobrescribir | Orden de bloques y montaje del sello |

**Ningún fichero de este apartado es opcional.** En particular, `main.js`
(fichero 4) lo es menos de lo que parece: sin él la cabecera sale **agrupada
también en el móvil**, donde los enlaces cuelgan de submenús y solo «Inicio»
resulta alcanzable. Se comprobó medido: los otros nueve destinos con tamaño
0×0.

> **Orden de subida dentro de la lista:** sube primero los CSS, después los
> JS y `landingView.js` al final. Ningún shell referencia un asset nuevo por
> URL (el sello va montado por `landingView.js`, no por `index.html`), así que
> el orden es de comodidad, no de seguridad. Aun así, recarga con **Ctrl+F5**
> al terminar: los assets no llevan versión en la URL, así que el navegador
> los sirve desde caché con facilidad.

### 2. Verificación en navegador (sin cuenta de ensayo)

Es superficie pura de presentación: **no crea ni muta datos**, así que puede
verificarse con cualquier cuenta, incluida una de lector. Requiere
autorización del custodio. La lógica ya está verificada localmente (arnés
`scratch/test_portal_composition.mjs` 98/98 y **regresión completa de los
111 arneses `.mjs` en verde**); esto valida el despliegue real.

Los valores esperados están **medidos**, no estimados. Compáralos con lo que
ves; una diferencia relevante es un despliegue incompleto.

**A. Escritorio, 1440×900**

1. **Cabecera de una sola fila.** Alto ≤ 96 px (medido: **63 px**). Debe
   leerse `Inicio · BIBLIOTECA ▾ · LINAJES ▾ · SALA DE TRABAJO ▾`.
2. **Los diez destinos son alcanzables.** Pasa el ratón por cada rótulo: el
   submenú abre bajo el grupo y lista sus destinos. `Escape` lo recoge y
   **devuelve el foco al rótulo**.
3. **Distintivo corto.** La cabecera muestra **solo el alias**, sin el linaje
   (medido: 216 px, antes 493). Al abrirlo, el desplegable declara el linaje
   arriba, en oro, y la efigie muestra un sello rúnico — **nunca un cuadro
   vacío**.
4. **Portada recompuesta.** De arriba abajo: sello «Tomo validado», héroe con
   el título en `MedievalArcaneTitle` a 36 px en oro, cinta del Regente,
   Pergaminos Destacados. **El blasón NO abre la página por delante del
   título.**
5. **El CTA entra sin desplazamiento.** «Consagrar Linaje» debe verse
   completo en el pliegue (medido: cierra en y=372 sobre 900 px, con el
   blasón ya montado).
6. **La cinta es una línea.** El Regente se lee en una sola fila con blasón,
   corona, nombre y lema (medido: 40 px de alto, sin marco). Antes eran 435 px
   con dos cajas concéntricas.

**B. Móvil, 375×812 y 320 px**

7. **Modo plano.** Por debajo de 1024 px la cabecera vuelve a la lista
   simple tras el botón `☰`: **sin grupos, sin submenús**, con los diez
   destinos y zona táctil de 44 px.
8. **Cero desbordamiento horizontal.** La barra no desborda ni el botón `☰`
   se sale de la pantalla. Este defecto estaba presente antes de SPEC-16 y es
   el que se cierra aquí.

**C. Regresión rápida (30 segundos)**

9. Recorre las cinco vistas desde la barra. Un enlace roto en cualquiera de
   ellas significa que se subió un fichero sin el resto.
10. Abre el Umbral de la Fundación desde el Salón o el Vestíbulo: el texto
    debe ser **legible** y los ocho linajes deben verse en castellano
    (`Linaje de la Llama Primordial`, `de las Mareas Celestiales`, …). Si
    ves `primordialFlame` o texto invisible, falta `195d708`.

> **Divergencia conocida, no es un defecto:** a 390 px o menos la cinta del
> Regente envuelve a **dos** filas (67 px) en lugar de una. Se prefirió
> `RNF-16.2` (responsive) sobre la lectura literal de «una sola línea» de
> `RF-18.7`: forzarla exigía recortar el lema o hacerlo ilegible. Está
> declarado en TASKS-16 y el custodio puede revocarlo.

**Plantilla de veredicto (a registrar tras ejecutar):**

```
SPEC-16 — Verificación en producción
Fecha:              PENDIENTE
Ejecutado por:      PENDIENTE
Rama/commit:        PENDIENTE (6be98d3 + d872efa)

A. Escritorio 1440×900
- [ ] Cabecera de una sola fila, alto ≤ 96 px (medido en producción: ___ px)
- [ ] Los diez destinos alcanzables; Escape cierra y devuelve el foco
- [ ] Distintivo solo con el alias; desplegable con el linaje en oro
- [ ] Efigie con sello rúnico, sin cuadro vacío
- [ ] Orden de portada: sello → héroe → cinta → destacados
- [ ] Título del héroe en MedievalArcaneTitle 36 px oro
- [ ] CTA «Consagrar Linaje» visible sin desplazamiento (cierra en y=___)
- [ ] Cinta del Regente en una sola línea, sin marco (alto: ___ px)

B. Móvil 375×812 y 320 px
- [ ] Modo plano: 0 grupos, 10 destinos, zona táctil de 44 px
- [ ] Cero desbordamiento horizontal; botón ☰ en pantalla

C. Regresión
- [ ] Las cinco vistas se recorren sin enlaces rotos
- [ ] Umbral de la Fundación legible y en castellano (requiere 195d708)

Limitaciones o incidencias encontradas:
PENDIENTE
```

Mientras quede alguna casilla sin marcar, SPEC-16 queda en producción como
**«desplegada, no verificada»** (misma doctrina de honestidad de §9b y §9c).
Registra aquí fecha, veredictos y limitaciones tras la ejecución.

### 3. Reversión

Todo SPEC-16 es frontend, así que la reversión es sustituir por los ficheros
del commit `3535356` (el estado previo a SPEC-16). No hay estado de base de
datos que deshacer ni migración que revertir. Si solo falla la Fase 2,
revierte los ficheros 2, 3, 7, 8 y 9; si falla la Fase 1, revierte los 9.

## Solución de problemas

| Síntoma | Causa probable | Remedio |
|---|---|---|
| Sesión que no perdura: «El canon no responde: los Ocho Linajes guardan silencio» tras consagrar/login | DSN sin materializar (`env.php` no se ejecuta) → base `sqlite::memory:` borrada en cada petición | La sonda `probe-env.php` da `"dsnMaterializado": false`. Verifica que subiste los `.htaccess` CORREGIDOS (con los bloques `php_value auto_prepend_file`) y que `env.php` está en `htdocs/public/` con la ruta absoluta correcta |
| 500 al abrir la web | `.user.ini` rechazado o `env.php` no encontrado | Revisa el paso 5; prueba a usar la ruta absoluta en `auto_prepend_file` |
| La API responde pero los datos desaparecen a cada clic | DSN sin materializar → `sqlite::memory:` | `env.php` mal instalado o `$projectRoot` mal escrita |
| Página de error «your connection is not private» | SSL aún no propagado | Espera a que el certificado esté ACTIVO en el panel |
| `Fatal: uncaught RuntimeException: La conexión al plano arcano...` | Ruta de `storage/` inaccesible | Verifica permisos 700/755 y que `$projectRoot` sea la absoluta correcta |
| Los assets (CSS/JS) no cargan y da 404 | El funnel no está en la raíz o falta `RewriteBase` | Confirma `htdocs/.htaccess`; prueba a añadir `RewriteBase /` tras `RewriteEngine On` |
| Al abrir la raíz responde JSON `ROUTE_NOT_FOUND` («Ningún sendero arcano…») | El servidor eligió `index.php` (la API) como índice de directorio en vez del shell `index.html` | Los `.htaccess` incluyen ya la regla explícita `RewriteRule ^$ …index.html`; confirma que instalaste las versiones corregidas y sube de nuevo ambos ficheros |

## Limitaciones conocidas del plan gratuito

- Sin SSH ni cron: el endpoint de expiración de moderación
  (`POST /api/v1/moderation/cron-check-expiry`) e el cierre del ciclo de
  Dominio (`POST /api/v1/dominion/cron-cycle-close`) deben invocarse a
  mano (navegador/curl con el sello de admin) o con un cron externo
  gratuito (p. ej. cron-job.org) apuntando a esas URLs.
- Límites diarios de ancho de banda y «hits» (holgados para una demo).
- La base SQLite es monofichero: perfecta para este volumen. Si el
  santuario creciera, la **variante MySQL** del propio hosting está a un
  DSN de distancia (sección siguiente).

---

## Variante MySQL del propio hosting (opcional)

El plan gratuito incluye bases MySQL/MariaDB. El esquema canónico ya es
compatible con ambos motores (el guion `database/schema-mysql.sql` es el
gemelo dialectal de `schema.sql`, SPEC-13) y `Connection.php` ya lee las
tres variables del entorno — solo cambia el DSN en `env.php`.

### Cuándo conviene

- Si prevés crecimiento real del catálogo o escritura concurrente
  intensiva (SQLite bloquea el fichero entero por escritura).
- Si quieres administrar la base con phpMyAdmin del panel (cómodo para
  respaldos y consultas manuales).

Con la demo o un uso moderado, la SQLite persistente es perfecta y más
simple: nada de credenciales ni de importar el esquema a mano.

### Pasos

1. **Crea la base** en el panel: **MySQL Databases** → anota el nombre
   completo de la base (p. ej. `infi0000_grimorio`), el usuario y la
   contraseña que el propio panel te asigna/genera.
2. **Importa el esquema y las semillas** (en MySQL el auto-bootstrap de
   `Connection.php` NO aplica — es exclusivo de SQLite): abre
   **phpMyAdmin** desde el panel, entra en la base nueva y ejecuta en
   orden, en la pestaña SQL:
   1. el contenido íntegro de `database/schema-mysql.sql` (el DDL en
      dialecto MySQL; el `schema.sql` canónico es dialecto SQLite y
      phpMyAdmin lo rechazaría con `#1170` — SPEC-13)
   2. el contenido íntegro de `database/seeds.sql` (portable: idéntico
      en ambos motores)

   (Verifica al final que `spells`, `users` y `clans` existen y que las
   semillas se asentaron. El `schema-mysql.sql` vigente ya viste
   SPEC-12: `users.avatar` viene incluida — bases MySQL NACEN con el
   Panel del Adepto, sin migraciones previas.)

   **Bases MySQL LEGADAS** (creadas antes de SPEC-13 con el DDL antiguo
   de esta guía): si tu base fue creada con un `schema.sql` anterior a
   la columna `users.avatar`, ejecuta en phpMyAdmin la guardia y, si
   falta, el ALTER de `sql/12_user_panel.sql`:

   ```sql
   -- Guardia previa (MySQL 8): ¿ya porta la columna?
   SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'users'
     AND COLUMN_NAME  = 'avatar';
   -- Conteo 0 → aplicar: ALTER TABLE users ADD COLUMN avatar TEXT NULL;
   -- Conteo 1 → la base ya viste SPEC-12: no ejecutar nada.
   ```

   En MariaDB 10.4+ basta la vía directa:
   `ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar TEXT NULL;`
   (aplicarla DOS veces no muta fila alguna ni produce error).
3. **Edita `public/env.php`**: sustituye el bloque del DSN SQLite por el
   trío MySQL (el resto del fichero, el guard de 403 y `$projectRoot`, no
   hace falta tocarlo). **VÍA `define`, jamás `putenv`**: el sandbox de
   InfinityFree tiene `putenv` en `disable_functions` y la llamada muere
   en silencio (hallazgo de SPEC-12, verificado con la sonda
   `probe-env.php`); `Connection.php` resuelve PRIMERO las constantes y
   solo recurre al entorno como fallback:

   ```php
   // DSN MySQL del hosting (variante alternativa a SQLite).
   // VÍA define (no putenv): putenv está en disable_functions del sandbox.
   if (!defined('GRIMORIO_DB_DSN')) {
       define('GRIMORIO_DB_DSN', 'mysql:host=sqlXXX.infinityfree.com;dbname=infi0000_grimorio;charset=utf8mb4');
   }
   if (!defined('GRIMORIO_DB_USER')) {
       define('GRIMORIO_DB_USER', 'infi0000_grimorio');
   }
   if (!defined('GRIMORIO_DB_PASS')) {
       define('GRIMORIO_DB_PASS', 'tu_contraseña_de_la_base');
   }
   ```

   - El **host** exacto (`sqlXXX.infinityfree.com`) lo muestra el panel
     en **MySQL Databases** junto a cada base.
   - `charset=utf8mb4` es obligatorio: los rótulos castellanos y las
     leyendas solemnes viajan con acentos y «comillas angulares».

4. **Verifica**: recarga la web y prueba el catálogo, el registro y el
   juramento. Si el plano respondiera con «La conexión al plano arcano
   no pudo establecerse», revisa host/usuario/contraseña contra el
   panel — y que la base ya tenga las tablas importadas del paso 2.

### Particularidades de la variante MySQL

| Aspecto | Detalle |
|---|---|
| Sesiones | Siguen siendo ficheros PHP nativos del hosting — no cambian con el motor de la base. |
| Migraciones nuevas | Las guiones de ascensión posteriores (`sql/*.sql`) son idempotentes en SQLite; en MySQL ejecútalos también en phpMyAdmin cuando el santuario crezca. SPEC-12 añadió `users.avatar` (Panel del Adepto): sobre bases legadas aplícala con la guardia de `INFORMATION_SCHEMA` del paso 2; sobre bases nuevas de `schema.sql` vigente no hace falta. (Sobre la vía SQLite sin phpMyAdmin, véase la sección «Migración sobre SQLite en producción».) Los avatares propios viven en `storage/avatars/` con nombre aleatorio NO derivado del alias; vigila la cuota de ficheros del plan gratuito (limitación declarada en SPEC-12 §7b). |
| `PRAGMA foreign_keys` | No aplica; MySQL con InnoDB aplica las claves foráneas por defecto (ya lo documenta `schema.sql`). |
| Contraseña en el fichero | `env.php` vive en `public/` pero el funnel la protege; el guard de 403 impide servirla como URL. La contraseña de la base es la del plan gratuito — regénérala desde el panel si acaso. |

---

## Migración sobre SQLite en producción (sonda `migrate-spec12.php`)

> Contexto: la vía MySQL de arriba tiene phpMyAdmin; la vía SQLite del
> hosting NO ofrece CLI ni administración sobre el fichero
> `storage/grimorio_live.sqlite`. Cuando una ascensión de esquema
> (p. ej. SPEC-12: `users.avatar`) llegue a una base legada ya en
> producción, la herramienta oficial es la **sonda de migración**
> `deploy/infinityfree/migrate-spec12.php` — misma doctrina que la
> sonda del entorno `probe-env.php` del paso 9: subir, ejecutar en el
> navegador, leer el informe y **borrar del servidor**.

### Cuándo procede

- Tu base SQLite fue creada por el auto-bootstrap con un `schema.sql`
  ANTERIOR a la migración (base legada), y los adeptos ya registran
  datos que no quieres perder.
- Sobre bases NUEVAS no hace falta: el auto-bootstrap levanta el
  `schema.sql` vigente con la columna ya incluida.

### Pasos

1. **Sube la sonda por FTP** como `htdocs/public/migrate-spec12.php`
   (junto a `index.php`).
2. **Ábrela en el navegador**:
   `https://TU_SUBDOMINIO.infinityfreeapp.com/migrate-spec12.php`.
   Responde un JSON de informe, por ejemplo en la ascensión SPEC-12
   (la primera corrida real de esta sonda):

   ```json
   {
       "dsn": "sqlite (persistente)",
       "columnaAntes": 0,
       "migracion": "ALTER aplicado: columna avatar añadida a users",
       "columnaDespues": 1,
       "usuarios": 3,
       "avataresVestidos": 0,
       "success": true
   }
   ```

3. **Certifica la idempotencia**: re-ejecuta la misma URL. La segunda
   respuesta debe decir `"La base ya porta la columna: nada que hacer
   (idempotente)"` con `"success": true` — la guardia por
   `PRAGMA table_info(users)` evita el ALTER si la columna ya vive, en
   fiel cumplimiento del contrato idempotente de `sql/12_user_panel.sql`
   («duplicate column» es señal, jamás error).
4. **BORRA la sonda del servidor** (`htdocs/public/migrate-spec12.php`):
   es llave de esquema y la guía la prohíbe en producción una vez usada
   (misma regla que `probe-env.php`).

### Guardias de la sonda (por qué es fail-safe)

| Guardia | Comportamiento |
|---|---|
| DSN ausente o `:memory:` | HTTP 500 con informe JSON y **ninguna escritura**: si el entorno no materializa el DSN persistente, tocar la base sería borrar todo en cada petición. Verificado en la primera corrida real: la primera versión de la sonda (solo `getenv`) falló con «DSN no materializado» y la base quedó intacta. |
| Idempotencia | `PRAGMA table_info(users)` decide si el ALTER procede; re-ejecutar nunca falla ni muta fila. |
| Errores controlados | `catch (Throwable)` con JSON mínimo, sin trazas PDO ni rutas internas (AGENTS.md 6.1). |

### El hallazgo del doble canal del DSN (define vs putenv)

La sonda respeta la **misma precedencia** que `Connection.php`, y ahí
vive el hallazgo que motivó su corrección:

1. La documentación inicial de `env.php` atribuía el DSN a la vía
   `putenv('GRIMORIO_DB_DSN', ...)`.
2. En el sandbox real de InfinityFree, **`putenv` está en
   `disable_functions`**: la llamada muere en silencio (verificado con
   `probe-env.php`: `dsnTrasRequire: null`), así que el DSN jamás
   llegaría por ahí.
3. El `env.php` desplegado materializa el DSN vía
   `define('GRIMORIO_DB_DSN', 'sqlite:' . $storageDir . '/grimorio_live.sqlite')`,
   y `Connection.php` resuelve PRIMERO la constante
   `GRIMORIO_DB_DSN`, recurriendo a `getenv` solo como fallback.
4. Por eso la sonda lee el doble canal, en este orden:

   ```php
   $dsn = defined('GRIMORIO_DB_DSN')
       ? constant('GRIMORIO_DB_DSN')
       : getenv('GRIMORIO_DB_DSN');
   ```

   La primera versión (solo `getenv`) devolvía `null` en producción y
   la guardia cortó la ejecución ANTES de abrir la base — el fail-safe
   funcionó tal cual fue diseñado. Corregida la precedencia, la sonda
   fue ensayada en AMBOS canales (define: ALTER aplicado; getenv:
   fallback operativo) antes de su corrida real exitosa.

Cualquier sonda futura de migración debe copiar este patrón:
`require __DIR__ . '/env.php'`, doble canal del DSN, guardia anti
memoria, guardia idempotente del esquema, informe JSON y borrado del
servidor tras el uso.

---

## La efigie en producción (SPEC-14)

> La subida de efigie propia de SPEC-12 está implementada y probada en
> local; esta sección cubre su PARIDAD DE DESPLIEGUE en InfinityFree:
> chemin del almacenamiento, privacidad por funnel, fragua gráfica (GD)
> y topes de transporte. Fuente normativa: `specs/14-avatar-production-deployment.spec.md`.

### 1. Topología canónica

- **Directorio de efigies:** `htdocs/storage/avatars` (el mismo
  `storage/` que usó la base SQLite; hoy queda libre para uso exclusivo
  de las efigies, pues la base de producción es MySQL remota).
- **Privacidad (RF-03.3 de SPEC-12):** la efigie propia es PRIVADA del
  adepto; la única vía de lectura es `GET /api/v1/panel/avatar/image`
  con guardia de sesión. El funnel raíz (`htdocs/.htaccess` con
  `RewriteRule ^storage/ - [F,L]` y `Options -Indexes`) niega por URL
  todo el árbol: una URL directa responde 403, jamás la imagen.
- **Chemins no canónicos:** si tu instalación difiere, descomenta en
  `htdocs/public/env.php` el bloque `GRIMORIO_AVATARS_ROOT` (hermano de
  los `GRIMORIO_DB_*`); la derivación automática del front controller
  sigue siendo el valor por defecto y los despliegues canónicos no
  cambian nada.

### 2. Verificación con la sonda `probe-avatar.php`

1. Sube `deploy/infinityfree/probe-avatar.php` por FTP como
   `htdocs/public/probe-avatar.php`.
2. Ábrelo en el navegador:
   `https://TU_SUBDOMINIO/probe-avatar.php`. Devuelve un JSON con cuatro
   comprobaciones y remedios accionables:

   | Comprobación | Qué mide | Si falla |
   |---|---|---|
   | `chemin` | El directorio de efigies existe y acepta escritura real | Crea `htdocs/storage/avatars` desde el File Manager con permisos 700, o declara `GRIMORIO_AVATARS_ROOT` en `env.php` |
   | `gd` | Extensión GD con las 6 funciones del canon y encuadre REAL 512×512 | Contacta al hospedaje o restringe el canon de formatos anunciado |
   | `transport` | `upload_max_filesize` / `post_max_size` efectivos contra el canon (2 MiB / 4 MiB) | Véase la nota de honestidad del punto 4 |
   | `funnel` | El `.htaccess` raíz niega `storage/` por URL (doble vía: fichero + petición HTTP de prueba) | Reinstala `htaccess-root` y repite la sonda — sin funnel, la efigie podría exponerse |

3. El veredicto global `EXITO` requiere las cuatro comprobaciones sanas;
   `REVISAR` lista los remedios.
4. **Nota de honestidad ante `post_max_size`:** si un envío excede el
   `upload_max_filesize` del hospedaje, PHP lo entrega con error y la
   API responde con aviso que nombra el motivo. Pero si excede el
   `post_max_size`, PHP VACÍA el envío antes de ejecutar la aplicación:
   allí ningún aviso específico es posible (la honestidad es documental,
   esta misma nota). La sonda mide los topes reales; compáralos con el
   canon y, si fueran menores, ajústalo en la guía del adepto.
5. **BORRA la sonda del servidor** tras el diagnóstico (misma regla que
   `probe-env.php` y `probe-mysql.php`): su permanencia es incidencia
   de despliegue (RF-03.3).

### 3. Ciclo de verificación de extremo a extremo (cuenta de prueba)

Con la sonda en EXITO, ejerce el ciclo completo desde el panel con una
cuenta de prueba linajada:

1. **Alta:** `POST /api/v1/panel/avatar` modo `own` con multipart
   (`image`) → **200** con `data.avatar` (`kind: "own"`) y asiento
   `AVATAR_SELF_MODIFIED` en la Bitácora.
2. **Lectura privada:** `GET /api/v1/panel/avatar/image` con sesión →
   el PNG; sin sesión → 401; URL directa al fichero → 403 del funnel.
3. **Reemplazo:** sube otra imagen → el fichero anterior deja de existir
   (RF-03.4 de SPEC-12).
4. **Idéntica rechazada:** re-subir la misma imagen → 400
   `AVATAR_IDENTICAL` sin asiento (el juez del hash).
5. **Retiro:** `DELETE /api/v1/panel/avatar` → vuelve el canónico y el
   fichero propio se borra del disco.

Con los cinco pasos verdes, la SPEC-14 queda desplegada y la efigie
propia plenamente ejercitable en producción.
