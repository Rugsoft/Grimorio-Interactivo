# PLAN-14: Plan Técnico de la Efigie en Producción

> **Especificación:** [`specs/14-avatar-production-deployment.spec.md`](14-avatar-production-deployment.spec.md) — RATIFICADA por el Arquitecto.
> **Constitución:** [`constitution.md`](../constitution.md) | **Directrices:** [`AGENTS.md`](../AGENTS.md)
> **Estado:** Borrador → Ratificado junto a la spec.
> **Regla de oro del plan:** esta enmienda NO rehace la subida (Tarea 2.2 de SPEC-12, ya implementada y probada en local): consolida su despliegue. Tres actos — chemin declarable, sonda de diagnóstico, documentación — y cero cambios de contrato.

---

## 1. Diseño de la solución

### 1.1 El chemin de efigies: canal de configuración + valor por defecto

La resolución vigente en `public/index.php` es `dirname(__DIR__) . '/storage/avatars'`. Con la topología canónica de la guía (repositorio completo bajo `htdocs/`) es correcta; el defecto es de fragilidad ante topologías no canónicas y de falta de canal de configuración.

**Solución (RF-01.1):** preceder la derivación automática con una constante opcional:

```php
// public/index.php — construcción del AvatarService
$avatarsRoot = defined('GRIMORIO_AVATARS_ROOT') && is_string(GRIMORIO_AVATARS_ROOT) && GRIMORIO_AVATARS_ROOT !== ''
    ? GRIMORIO_AVATARS_ROOT
    : dirname(__DIR__) . '/storage/avatars';
$avatarService = new AvatarService(..., $avatarsRoot);
```

Y en `deploy/infinityfree/env.php` (el fichero que el arquitecto instala en `htdocs/public/env.php`), bloque nuevo documentado y DESACTIVADO por defecto:

```php
// Chemin de las efigies propias (SPEC-14). DESCOMENTAR SOLO si tu
// topología difiere de la canónica (repositorio completo en htdocs/):
// la derivación automática ya resuelve htdocs/storage/avatars.
// if (!defined('GRIMORIO_AVATARS_ROOT')) {
//     define('GRIMORIO_AVATARS_ROOT', $projectRoot . '/storage/avatars');
// }
```

**Justificación de la decisión:**
- La derivación automática queda como valor por defecto → los despliegues existentes NO cambian de comportamiento (cero regresión).
- El `define` es la válvula para topologías ajenas → se declara sin tocar código.
- Nomenclatura `GRIMORIO_AVATARS_ROOT`: misma familia que los `GRIMORIO_DB_*` ratificados por SPEC-13.

### 1.2 La sonda `probe-avatar.php` (RF-03)

Hermana de `probe-env.php` y `probe-mysql.php`: fichero en `deploy/infinityfree/`, instalado en producción como `htdocs/public/probe-avatar.php`, abierto en navegador, leído su veredicto y BORRADO del servidor.

Comprobaciones (una visita, veredicto accionable, JSON):

| # | Comprobación | Cómo (sin secretos) | Causa noble si falla |
|---|---|---|---|
| a | Chemin resuelto, existente y escribible | Aplica la MISMA lógica que el front controller (`GRIMORIO_AVATARS_ROOT` si definida, si no `dirname(__DIR__, 2) . '/storage/avatars'`); `is_dir` + `mkdir` de prueba + `is_writable` con fichero de prueba que se purga | «El altar de efigies no está disponible o no es escribible» |
| b | GD del canon vivo | `extension_loaded('gd')` + verificación individual de las seis funciones del canon + **decodificación y codificación real**: PNG sintético 1024×1024 → encuadre 512×512 con `imagecreatetruecolor`+`imagecopyresampled`+`imagepng` a un temporal que se purga | «La fragua gráfica (GD) carece de alguna herramienta del canon» |
| c | Topes de transporte | `ini_get('upload_max_filesize')` y `ini_get('post_max_size')` normalizados a bytes con el sufijo K/M/G; comparación contra el canon (2 MiB fichero / 4 MiB petición) | «El transporte del hospedaje impone un tope menor que el canon» |
| d | Funnel raíz vivo (doble vía) | (d1) lee `../.htaccess` por sistema de ficheros y busca `^storage/` con flag F; (d2) petición HTTP de prueba vía `file_get_contents` con `ignore_errors` a `../storage/avatars/sonda-probe.txt` (fichero de prueba creado en a, purgado al final) y comprueba que la respuesta NO es el contenido — 403/404 limpios | «El funnel raíz no está negando el almacenamiento por URL» |

**Seguridad:** jamás imprime credenciales ni rutas absolutas completas (muestra solo la cola del chemin recortada); purga todo fichero de prueba en un bloque `finally`; guard de 403 si se ejecuta sin ser fichero antepuesto no aplica (es endpoint deliberado), pero sí autodestrucción documentada (RF-03.3: borrar tras uso).

**Decisión sellada:** la sonda NO crea `storage/avatars` definitivo si falta — crea el árbol solo para medir y lo elimina si estaba vacío y ella lo creó, para no dejar estado en el servidor más allá del diagnóstico (el alta real del adepto ya sabe crear el árbol con `mkdir` recursivo; verificado en `AvatarService`).

### 1.3 El README (RF-04.2, RNF-04, criterio 7)

Sección nueva «La efigie en producción» tras la «Migración sobre SQLite en producción»:

1. Qué es (paridad de la efigie propia de SPEC-12 en producción).
2. Topología canónica: `htdocs/storage/avatars`, negada por URL por el funnel raíz (`RewriteRule ^storage/ - [F,L]`).
3. Instalación de la sonda `probe-avatar.php` + tabla de veredictos y remedios.
4. El `define` de `GRIMORIO_AVATARS_ROOT` como válvula para topologías no canónicas (desactivado por defecto).
5. Ciclo E2E de verificación con cuenta de prueba (alta → lectura con sesión → reemplazo → idéntica rechazada → retiro) y los códigos que nombran el motivo.
6. Nota de honestidad ante `post_max_size` (RF-04.2.ii): si el tope del hosting fuera menor que 4 MiB, los envíos grandes morirán ANTES de la aplicación y no habrá aviso específico — el remedio es documental.
7. Recordatorio de borrado de la sonda (RF-03.3).

---

## 2. Ficheros del plan

| Fichero | Naturaleza | Responsabilidad |
|---|---|---|
| `deploy/infinityfree/env.php` | AMPLIADO | Bloque comentado del `define('GRIMORIO_AVATARS_ROOT', ...)` (desactivado por defecto). |
| `public/index.php` | AMPLIADO | Lectura del `define` con fallback a la derivación automática (comportamiento vigente intacto). |
| `deploy/infinityfree/probe-avatar.php` | NUEVO | Sonda de diagnóstico (4 comprobaciones a/b/c/d, veredicto JSON accionable, purga de restos). |
| `deploy/infinityfree/README.md` | AMPLIADO | Sección «La efigie en producción» (7 puntos, §1.3). |
| `specs/14-avatar-production-deployment.plan.md` | NUEVO | Este plan. |
| `specs/14-avatar-production-deployment.tasks.md` | NUEVO | Tareas del ciclo (hermana de TASKS-12). |

**Fuera de alcance (RF-06.2):** ningún cambio en `src/` salvo el front controller; DDL, catálogo de Bitácora, contrato HTTP y canon de límites quedan intactos.

---

## 3. Verificación del plan

### 3.1 Arnés local `scratch/test_spec14_local.php` (TDD, convención del proyecto)

Fases:
1. **[1] Resolución del chemin:** con `GRIMORIO_AVATARS_ROOT` definida → la usa; sin ella → la derivación automática produce `public/../storage/avatars` (misma ruta que hoy).
2. **[2] Comportamiento íntegro post-cambio:** sobre MariaDB local (env `GRIMORIO_DB_*`), ciclo de efigie completo vía `AvatarService` directamente (alta → juez de hash → retiro) para probar que el cambio del front controller no altera el servicio.
3. **[3] Sonda en modo local:** ejecutar `probe-avatar.php` desde CLI con un `document root` simulado y comprobar veredicto EXITO en las 4 comprobaciones y purga de restos (cero ficheros huérfanos tras la ejecución).
4. **[4] Sonda ante funnel ausente:** sin `.htaccess` raíz simulado → la comprobación (d) debe declarar FALLO con causa noble (no debe mentir EXITO).
5. **[5] `php -l` en los tres ficheros PHP tocados/creados.**

### 3.2 Verificación en producción (acto del Arquitecto, fuera del agente)

La guía documenta el ciclo: subir la sonda → veredicto EXITO ×4 → ciclo E2E con cuenta de prueba → borrar la sonda.

---

## 4. Dudas selladas del plan

1. **¿La sonda crea `storage/avatars` si falta?** Solo para medir la escribibilidad; lo elimina si estaba vacío y ella lo creó. El alta real ya crea el árbol por sí sola (verificado en `AvatarService::uploadOwn`).
2. **¿Cómo prueba la sonda la negación por URL sin saber el dominio?** Con ruta relativa (`../storage/avatars/...`): PHP la resuelve contra el host que sirve la propia sonda; si el transporte HTTP no está disponible para `file_get_contents` (`allow_url_fopen` desactivado), la comprobación (d) declara «no verificable» en lugar de EXITO — honestidad antes que confort.
3. **¿Tope `post_max_size` menor al canon?** No hay remedio en aplicación (PHP vacía el envío antes de ejecutarla): honestidad documental (RF-04.2.ii) + el veredicto de la sonda recomienda contactar al hosting o ajustar el canon anunciado al adepto en la guía.
