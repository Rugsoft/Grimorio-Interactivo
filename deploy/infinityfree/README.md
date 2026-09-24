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
| `deploy/infinityfree/user-ini` | `.user.ini` | `htdocs/public/` |
| `deploy/infinityfree/env.php` | `env.php` | `htdocs/public/` |

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

## Solución de problemas

| Síntoma | Causa probable | Remedio |
|---|---|---|
| 500 al abrir la web | `.user.ini` rechazado o `env.php` no encontrado | Revisa el paso 5; prueba a usar la ruta absoluta en `auto_prepend_file` |
| La API responde pero los datos desaparecen a cada clic | DSN sin materializar → `sqlite::memory:` | `env.php` mal instalado o `$projectRoot` mal escrita |
| Página de error «your connection is not private» | SSL aún no propagado | Espera a que el certificado esté ACTIVO en el panel |
| `Fatal: uncaught RuntimeException: La conexión al plano arcano...` | Ruta de `storage/` inaccesible | Verifica permisos 700/755 y que `$projectRoot` sea la absoluta correcta |
| Los assets (CSS/JS) no cargan y da 404 | El funnel no está en la raíz o falta `RewriteBase` | Confirma `htdocs/.htaccess`; prueba a añadir `RewriteBase /` tras `RewriteEngine On` |

## Limitaciones conocidas del plan gratuito

- Sin SSH ni cron: el endpoint de expiración de moderación
  (`POST /api/v1/moderation/cron-check-expiry`) e el cierre del ciclo de
  Dominio (`POST /api/v1/dominion/cron-cycle-close`) deben invocarse a
  mano (navegador/curl con el sello de admin) o con un cron externo
  gratuito (p. ej. cron-job.org) apuntando a esas URLs.
- Límites diarios de ancho de banda y «hits» (holgados para una demo).
- La base SQLite es monofichero: perfecta para este volumen; si el
  santuario creciera, el siguiente paso sería MySQL del propio hosting
  cambiando solo el DSN en `env.php`.
