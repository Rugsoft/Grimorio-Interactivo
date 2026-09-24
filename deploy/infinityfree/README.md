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
> viaja por el prepend: lo materializa `env.php` vía `putenv` ANTES de
> que `Connection.php` lea la variable.

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
compatible con ambos motores (cabecera de `database/schema.sql`:
«SQLite 3.35+ y MySQL 8 / MariaDB 10.4+»), y `Connection.php` ya lee las
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
   1. el contenido íntegro de `database/schema.sql`
   2. el contenido íntegro de `database/seeds.sql`

   (Verifica al final que `spells`, `users` y `clans` existen y que las
   semillas se asentaron.)
3. **Edita `public/env.php`**: sustituye el bloque del DSN SQLite por el
   trío MySQL (el resto del fichero, el guard de 403 y `$projectRoot`, no
   hace falta tocarlo):

   ```php
   // DSN MySQL del hosting (variante alternativa a SQLite).
   putenv('GRIMORIO_DB_DSN=mysql:host=sqlXXX.infinityfree.com;dbname=infi0000_grimorio;charset=utf8mb4');
   putenv('GRIMORIO_DB_USER=infi0000_grimorio');
   putenv('GRIMORIO_DB_PASS=tu_contraseña_de_la_base');
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
| Migraciones nuevas | Las guiones de ascensión posteriores (`sql/*.sql`) son idempotentes en SQLite; en MySQL ejecútalos también en phpMyAdmin cuando el santuario crezca. |
| `PRAGMA foreign_keys` | No aplica; MySQL con InnoDB aplica las claves foráneas por defecto (ya lo documenta `schema.sql`). |
| Contraseña en el fichero | `env.php` vive en `public/` pero el funnel la protege; el guard de 403 impide servirla como URL. La contraseña de la base es la del plan gratuito — regénérala desde el panel si acaso. |
