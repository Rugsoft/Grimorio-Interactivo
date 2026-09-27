# TASKS-14: Tareas de la Efigie en Producción

> **Especificación:** [`specs/14-avatar-production-deployment.spec.md`](14-avatar-production-deployment.spec.md) — RATIFICADA | **Plan:** [`specs/14-avatar-production-deployment.plan.md`](14-avatar-production-deployment.plan.md)
> **Convención:** tareas de 20–30 min, ordenadas por dependencia, con «Hecho cuando» verificable. Las tareas de backend llevan arnés `scratch/test_*.php` (TDD del santuario).

---

## FASE 1 — Chemin declarable (RF-01)

- [x] **Tarea 1.1 — El `define` del chemin en `env.php`**
  *Cubre:* RF-01.1, RF-06.2. *Alcance:* bloque comentado y DESACTIVADO por defecto en `deploy/infinityfree/env.php` que materializa `GRIMORIO_AVATARS_ROOT` (hermano de los `GRIMORIO_DB_*`), con la nota de «descomentar solo ante topología no canónica». **Hecho cuando:** el fichero parsea (`php -l`) y el bloque desactivado no altera el comportamiento de ningún despliegue vigente.

- [x] **Tarea 1.2 — Lectura del `define` en el front controller**
  *Cubre:* RF-01.1, RF-01.2, RF-06.2. *Alcance:* `public/index.php` resuelve la raíz de efigies como `GRIMORIO_AVATARS_ROOT` (si definida y no vacía) o la derivación automática `dirname(__DIR__) . '/storage/avatars'` (valor por defecto, comportamiento vigente intacto — cero regresión). Arnés `scratch/test_spec14_local.php` fase [1]. **Hecho cuando:** el arnés aserta ambas resoluciones y `php -l` pasa.

---

## FASE 2 — La sonda de diagnóstico (RF-03)

- [x] **Tarea 2.1 — `deploy/infinityfree/probe-avatar.php`**
  *Cubre:* RF-03.1, RF-03.2, RNF-02. *Alcance:* sonda hermana de `probe-env.php`/`probe-mysql.php` con las 4 comprobaciones (a chemin, b GD real 512×512, c topes de transporte, d funnel por doble vía), veredicto JSON accionable en castellano, sin credenciales ni rutas absolutas completas, y purga íntegra de ficheros de prueba en bloque `finally`. Arnés `scratch/test_spec14_local.php` fases [3,4]. **Hecho cuando:** el arnés aserta veredicto EXITO ×4 con restos purgados y FALLO honesto de la comprobación (d) ante funnel ausente.

---

## FASE 3 — Documentación de despliegue (RF-04, RNF-04)

- [x] **Tarea 3.1 — Sección «La efigie en producción» del README**
  *Cubre:* RF-04.1, RF-04.2, RNF-04, criterio 7. *Alcance:* sección nueva en `deploy/infinityfree/README.md` con topología canónica, instalación de la sonda y tabla de veredictos/remedios, el `define` como válvula, el ciclo E2E con cuenta de prueba, la nota de honestidad ante `post_max_size` y el recordatorio de borrado de la sonda. **Hecho cuando:** la guía cubre los 7 puntos del plan §1.3 y el diff no toca secciones ratificadas.

---

## FASE 4 — Verificación del plan (§3)

- [x] **Tarea 4.1 — Arnés `scratch/test_spec14_local.php` y verificación local**
  *Cubre:* §3.1 del plan (fases 1–5). *Alcance:* arnés local que ejercita resolución del chemin (con/sin `define`), ciclo de efigie íntegro sobre MariaDB local vía `AvatarService`, sonda en modo local (EXITO ×4 + purga + FALLO honesto sin funnel) y `php -l` de los ficheros tocados. **Hecho cuando:** las 5 fases del arnés pasan y la verificación en producción queda como acto del Arquitecto documentado en la guía.
