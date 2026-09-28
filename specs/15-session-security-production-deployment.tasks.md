# TASKS-15: Tareas de Seguridad de Sesión en Producción

> **Especificación:** [`specs/15-session-security-production-deployment.spec.md`](15-session-security-production-deployment.spec.md) — RATIFICADA; verificación del hosting pendiente.
> **Plan técnico:** [`specs/15-session-security-production-deployment.plan.md`](15-session-security-production-deployment.plan.md) — APROBADO por el Arquitecto; verificación del hosting pendiente.
> **Estado de estas tareas:** BORRADOR operativo; la aprobación levanta el bloqueo de revisión del plan, pero las tareas dependientes de evidencia del hosting y cualquier acción en producción siguen sujetas a sus puertas explícitas.
> **Convención:** tareas pequeñas, ordenadas por dependencia, con «Hecho cuando» verificable. Casillas sin marcar indican trabajo no realizado.
> **Regla de seguridad:** no confiar en cabeceras reenviadas ni modificar producción hasta verificar y documentar la topología real del hosting. Ninguna prueba podrá mutar datos de usuarios reales.

---

## FASE 0 — Puertas SDD y evidencia del hosting

- [x] **Tarea 0.1 — Aprobación del plan técnico**
  *Cubre:* SPEC-15 §11; PLAN-15 §10. *Alcance:* registrar la aprobación explícita de PLAN-15 por el Arquitecto. La aprobación valida el enfoque y las puertas de seguridad, no la evidencia del hosting ni la conformidad en producción. **Hecho cuando:** PLAN-15 deja de figurar como pendiente de aprobación y queda identificado como aprobado. Aprobación registrada; no implica autorización de despliegue ni ejecución automática de las tareas siguientes.

- [x] **Tarea 0.2 — Documentar la topología de producción**
  *Cubre:* SPEC-15 RF-02/RF-05; PLAN-15 §2. *Alcance:* obtener del custodio del hosting la SAPI, el valor observado de `REMOTE_ADDR`, la ruta pública HTTPS, la presencia de terminación TLS, el acceso directo al origen y el comportamiento de cabeceras reenviadas. No publicar valores sensibles. **Hecho cuando:** existe una ficha de evidencia revisada que identifica qué señales son confiables; cualquier dato no comprobable aparece explícitamente como pendiente.
  *Evidencia registrada (2026-09-28, aportada por el custodio):*
  - Dominio: `grimoriointeractivo.freedev.app`; subdominios con SSL automático; **sin Cloudflare propio** del custodio.
  - PHP **8.4.25** sobre **apache2handler**.
  - Señales de servidor en petición de ensayo: `HTTPS=on`, `REQUEST_SCHEME=https`, `SERVER_PORT=443` → **señal directa de servidor** (sin offload TLS aparente hacia PHP).
  - `REMOTE_ADDR=83.42.40.69` (IP pública real del cliente en la sonda); `X-Forwarded-For=83.42.40.69` **idéntica** a `REMOTE_ADDR`; `X-Forwarded-Proto=https`; `CF-Visitor` y `CF-Connecting-IP` ausentes (confirma ausencia de Cloudflare).
  - Cookie de login observada: `Secure` sí, `HttpOnly` sí, `SameSite=Strict`, `Path=/`, `Max-Age=1209600` → emisión actual ya conforme con RF-01 en producción.
  - *Pendiente explícito:* consulta al soporte de InfinityFree sobre infraestructura intermedia y acceso directo al origen **sin respuesta**; entorno de staging **no disponible** («no se»). Ambos quedan registrados como no verificados conforme a RF-05.4.

- [x] **Tarea 0.3 — Resolver la puerta de confianza del proxy**
  *Cubre:* SPEC-15 RF-02.1–RF-02.4 y RF-03.1–RF-03.4. *Dependencia:* 0.2. *Alcance:* decidir, a partir de evidencia, si se configurará uno o más proxies confiables. Sin evidencia verificable, la política será lista vacía y se ignorarán las cabeceras reenviadas para determinar la procedencia. **Hecho cuando:** el plan aprobado refleja una política concreta o declara que el proxy no puede ser confiado y el criterio de producción correspondiente permanece no verificado.
  *Resolución (2026-09-28):*
  - **Política ratificada: lista de proxies confiables VACÍA.** No existe evidencia verificable de proxies intermedios (sin Cloudflare propio; `XFF ≡ REMOTE_ADDR` en la sonda; sin lista de IPs del proveedor), luego **se ignorarán `X-Forwarded-For` y toda cabecera reenviada** para determinar la procedencia; la única autoridad será `REMOTE_ADDR` validada.
  - **Política `Secure`:** con apache2handler y `HTTPS=on` como señal directa de servidor, la detección nativa `$_SERVER['HTTPS']` es fiable en producción; se unificará emisión/expiración sobre esa señal (o `GRIMORIO_COOKIE_SECURE=true` explícito como refuerzo opcional de despliegue). Ninguna cabecera de cliente participará en la decisión.
  - Si el soporte de InfinityFree confirmara en el futuro infraestructura intermedia con acceso directo al origen, la lista podría poblarse **solo** mediante enmienda de esta tarea con evidencia nueva; hasta entonces queda vacía.

---

## FASE 1 — Contratos de prueba locales

- [ ] **Tarea 1.1 — Crear arnés local aislado de SPEC-15**
  *Cubre:* SPEC-15 RNF-02/RNF-04, §10. *Alcance:* crear `scratch/test_spec15_local.php` con pruebas de unidades/integración aisladas, sin llamadas a producción, sin credenciales reales y con limpieza garantizada de DB/archivos/proceso propio. Si usa servidor HTTP, seleccionar un puerto libre y parar únicamente el PID que el arnés haya iniciado. **Hecho cuando:** las pruebas fallan inicialmente ante las conductas inseguras actuales, no dependen de servicios externos y limpian todos sus recursos incluso ante fallo.

- [ ] **Tarea 1.2 — Especificar pruebas de resolución de procedencia**
  *Cubre:* SPEC-15 RF-03, §8 casos 2–4 y §10 pruebas 3–6. *Dependencia:* 0.3, 1.1. *Alcance:* incluir IPv4/IPv6 directas, cabeceras falsificadas sin proxy confiable, proxy confiable con cadena acorde al comportamiento verificado, cabeceras vacías/malformadas y configuración ausente/inválida. Asertar también la IP que llega al RateLimiter y se registra en el sandbox. **Hecho cuando:** el arnés expresa resultados inequívocos para cada caso y nunca usa la cabecera del cliente como autoridad en conexión directa.

- [ ] **Tarea 1.3 — Especificar pruebas de cookies y revocación**
  *Cubre:* SPEC-15 RF-01/RF-04, §9 criterios 1, 6–8. *Dependencia:* 1.1. *Alcance:* probar atributos de emisión y expiración con la misma política, modos local HTTP y HTTPS simulado, disolución global, renuncia y rechazo posterior del token revocado. Usar integración HTTP para observar `Set-Cookie`; no usar `headers_list()` bajo CLI como única evidencia. **Hecho cuando:** el arnés comprueba nombre, `Path`, `HttpOnly`, `SameSite`, `Secure` esperado y caducidad, sin imprimir ni persistir el token crudo.

---

## FASE 2 — Resolución de procedencia confiable

- [ ] **Tarea 2.1 — Implementar resolución segura de IP directa**
  *Cubre:* SPEC-15 RF-03.1/RF-03.3/RF-03.4. *Dependencia:* 1.2. *Alcance:* validar `REMOTE_ADDR` IPv4/IPv6 y usarla como procedencia cuando el par conectado no es un proxy declarado confiable. Ignorar cabeceras reenviadas en ese camino. Definir y probar un resultado controlado si `REMOTE_ADDR` es inválida, compatible con el contrato de almacenamiento existente. **Hecho cuando:** pruebas negativas y positivas pasan y la cabecera falsificada no afecta ni el veredicto ni el registro del limitador.

- [ ] **Tarea 2.2 — Implementar política del proxy, solo si procede**
  *Cubre:* SPEC-15 RF-02.1/RF-03.2–RF-03.4. *Dependencia:* 0.3, 1.2, 2.1. *Alcance:* habilitar lectura de procedencia reenviada únicamente para pares de proxy explícitamente verificados. Interpretar la cadena conforme al comportamiento documentado del hosting; validar cada salto y rechazar/ignorar entradas malformadas. Si no hay evidencia suficiente, no crear esta vía y documentar la lista de confianza vacía. **Hecho cuando:** los casos válidos e inválidos del arnés pasan, la IP usada coincide con la procedencia contractual y la configuración por defecto no confía en ningún proxy.

- [ ] **Tarea 2.3 — Configurar proxies solo con datos confirmados**
  *Cubre:* SPEC-15 RF-02.2/RF-05.3. *Dependencia:* 0.2, 0.3, 2.2. *Alcance:* añadir a `deploy/infinityfree/env.php` únicamente la configuración explícita aprobada, con lista vacía o sin activar confianza si el hosting no ofrece valores verificables. No incorporar IPs reales en ejemplos públicos. **Hecho cuando:** configuración ausente conserva el modo seguro directo, los valores inválidos no amplían confianza y la guía explica el origen y mantenimiento de los valores aprobados.

---

## FASE 3 — Cookie segura y revocación del navegador

- [ ] **Tarea 3.1 — Unificar atributos y política de `Secure`**
  *Cubre:* SPEC-15 RF-01/RF-02, §8 casos 1–2. *Dependencia:* 1.3 y evidencia de 0.2. *Alcance:* centralizar decisión y opciones comunes para emitir/expirar `grimorio_session`. En producción HTTPS, `Secure` deberá depender de señal de servidor verificada o configuración de despliegue explícita; no de cabeceras arbitrarias. Preservar HTTP local si lo exige el desarrollo. **Hecho cuando:** emisión y expiración usan atributos de alcance compatibles y el arnés demuestra que un cliente no puede degradar `Secure` en producción.

- [ ] **Tarea 3.2 — Caducar cookie tras disolución global**
  *Cubre:* SPEC-15 RF-04.1/RF-04.3. *Dependencia:* 1.3, 3.1. *Alcance:* tras revocar exitosamente las sesiones del titular, devolver la expiración de la cookie actual. Conservar el contrato JSON y el estado HTTP. **Hecho cuando:** prueba HTTP confirma `Set-Cookie` expirado y una petición posterior con el token anterior no autentica; una revocación fallida no devuelve éxito.

- [ ] **Tarea 3.3 — Caducar cookie tras renuncia**
  *Cubre:* SPEC-15 RF-04.2–RF-04.4. *Dependencia:* 1.3, 3.1. *Alcance:* añadir expiración de cookie solo tras completar la renuncia y el asiento de auditoría exigido por el contrato actual. No resolver aquí la atomicidad global de renuncia; si el orden actual impide una respuesta coherente, detenerse y solicitar enmienda a SPEC-03 antes de ampliar el alcance. **Hecho cuando:** prueba HTTP con cuenta sandbox verifica cookie expirada, token anterior rechazado y sin cambios fuera de la cuenta de prueba.

---

## FASE 4 — Regresión y documentación

- [ ] **Tarea 4.1 — Ejecutar regresiones locales de autenticación y sesión**
  *Cubre:* SPEC-15 §9–10; PLAN-15 §6.3. *Dependencia:* Fases 2 y 3. *Alcance:* revisar y ejecutar `scratch/test_auth_service.php`, `scratch/test_rate_limiter.php`, `scratch/test_session_manager.php`, `scratch/test_auth_controller.php`, `scratch/test_csrf_cookie_shield.php`, `scratch/test_auth_client.mjs` y `scratch/test_main_auth_integration.mjs`, solo después de inspeccionar sus efectos/puertos/limpieza. **Hecho cuando:** todos los tests seleccionados pasan; los fallos preexistentes o discrepancias se registran y no se ocultan alterando aserciones.
  *Auditoría previa de seguridad de los 7 arneses (2026-09-28, requisito de PLAN-15 §6.2/6.3):*

  | Arnés | DB | Puertos/Procesos | Archivos temporales | Veredicto |
  |---|---|---|---|---|
  | `test_auth_service.php` | SQLite efímera en `sys_get_temp_dir()` con PID en el nombre; pre-borrado y unlink final | Ninguno | Solo la sandbox | **SEGURO** |
  | `test_rate_limiter.php` | Ídem | Ninguno | Ídem | **SEGURO** |
  | `test_session_manager.php` | Ídem + sonda con `sqlite::memory:` | `php -S` fijo **8099**; Windows: PID real (PowerShell `Start-Process -PassThru`) + `register_shutdown_function` | Sonda `scratch/__cookie_probe_<pid>.php` borrada al final | **CONDICIONAL** (ver hallazgos H-1, H-2, H-3) |
  | `test_auth_controller.php` | SQLite efímera ídem | Ninguno | Solo la sandbox | **SEGURO** (con advertencias H-4, H-5) |
  | `test_csrf_cookie_shield.php` | SQLite efímera + sonda `::memory:` | `php -S` fijo **8097**; mismo patrón PID Windows + shutdown | Sonda `scratch/__csrf_probe_<pid>.php` borrada | **CONDICIONAL** (H-1, H-3, H-4) |
  | `test_auth_client.mjs` | Ninguna | Ninguno (fetch simulado y restaurado) | Ninguno | **SEGURO** |
  | `test_main_auth_integration.mjs` | Ninguna | Ninguno (DOM y clientes fingidos) | Ninguno | **SEGURO** |

  *Hallazgos registrados:*
  - **H-1 (matanza por puerto, PLAN-15 §6.2):** `test_session_manager.php` incluye una limpieza secundaria **por puerto** (8099: `fuser -k` en Unix; `netstat+taskkill` en Windows) que podría matar un proceso ajeno del propio puerto. Su limpieza primaria por PID es correcta en Windows; en **Unix** el arranque con `exec(... &)` no captura PID, por lo que el `kill` del shutdown no actúa y el servidor podría quedar huérfano. Antes de ejecutarlo en Unix (o si se toca el arnés), sustituir el arranque por captura de PID real y **eliminar** la limpieza por puerto.
  - **H-2 (puertos fijos):** 8097 y 8099 son fijos → riesgo de colisión con otros arneses/hilos de agente. Si se modifican, asignar puerto libre dinámico (PLAN-15 §6.2). Ejecutarlos de uno en uno.
  - **H-3 (asertos estructurales frágiles):** `test_csrf_cookie_shield.php` cuenta las llamadas `setcookie(` del fuente del `SessionManager` (exigidas === 2) y lee los atributos del fuente de `expireCookie()`. La Tarea 3.1 (unificación de emisión/expiración) probablemente los altere: actualizar el aserto **junto con** el refactor, nunca para silenciarlo. Su verificación de cookie vía sonda HTTP real sí es conforme al requisito de PLAN-15 §3.4.
  - **H-4 (impacto de la enmienda RF-04 suspendida):** `test_auth_service.php` (sección 5), `test_auth_controller.php` (sección 6) y `test_csrf_cookie_shield.php` (fase [3], pergamino 200 neutro) prueban los endpoints de recuperación. Tras la enmienda ratificada (RF-04 fuera de servicio), estos asertos DEBERÁN revisarse con la implementación (endpoints deshabilitados → nuevos códigos esperados), sin ocultar el cambio de contrato.
  - **H-5 (dependencia de la conducta insegura de getClientIp):** `test_auth_controller.php` simula una segunda procedencia mediante cabecera `X-Forwarded-For` apoyándose en que `getClientIp()` confía en ella. Tras la Tarea 2.1/2.2 (XFF ignorado, autoridad `REMOTE_ADDR`), ese caso seguirá pasando por la IP inyectada por constructor en el `SessionManager`; verificar que así sea y que ninguna aserción exija confiar en XFF.
  - **H-6 (efectos generales):** ninguno de los 7 arneses toca `database/grimorio.db` ni `scratch/demo_live.sqlite` (todas las conexiones usan sandbox propia), no imprime tokens crudos (solo hashes/atributos), no red por defecto salvo las sondas `127.0.0.1` loopback, y los `.mjs` son puros. Los 5 PHP requieren `php` en PATH y los 2 `.mjs` `node`.

  *Conclusión:* aptos para ejecutarse en Fase 4 una vez implementadas las Fases 2–3, con las salvedades H-1..H-5; la ejecución de `test_session_manager.php` y `test_csrf_cookie_shield.php` debe hacerse de forma aislada (puertos dedicados) y en Unix solo tras resolver H-1.

- [ ] **Tarea 4.2 — Documentar procedimiento y límites del despliegue**
  *Cubre:* SPEC-15 RF-05.3/RNF-02/RNF-03, §9 criterio 10. *Dependencia:* 0.2, 0.3, 3.1. *Alcance:* actualizar `deploy/infinityfree/README.md` con las señales verificadas, configuración que el custodio debe aportar, pruebas permitidas y estado de las garantías. No incluir secretos ni inventar soporte de proxy. **Hecho cuando:** una persona puede reproducir el procedimiento sin depender de datos privados y las garantías no probadas aparecen marcadas como no verificadas.

- [ ] **Tarea 4.3 — Verificar despliegue en staging/producción autorizada**
  *Cubre:* SPEC-15 RF-05 y §9 criterios 1–10. *Dependencia:* 4.1, 4.2 y autorización explícita del custodio. *Alcance:* usar exclusivamente una cuenta de ensayo; comprobar cookie HTTPS, cabeceras falsificadas, disolución global y renuncia. Capturar solo atributos de cookie y resultados mínimos, nunca el valor de cookie ni credenciales. No invocar asedios de login ni modificar cuentas reales. **Hecho cuando:** se archiva evidencia segura de cada criterio o se marca como no verificado con causa del hosting.

- [ ] **Tarea 4.4 — Cierre SDD y revisión del diff**
  *Cubre:* SPEC-15 §9 y PLAN-15 §10. *Dependencia:* tareas previas. *Alcance:* confirmar trazabilidad RF→tareas→pruebas, revisar el diff y dejar los criterios de aceptación en su estado real. Comprobar que no se alteraron DDL, endpoints, SPEC-14, política del limitador ni recuperación fuera de alcance. **Hecho cuando:** se entrega resumen de cambios y evidencia, y cualquier pendiente de producción se conserva como no verificado.

---

## Bloqueos explícitos

- La aprobación de PLAN-15 ya está registrada. Las tareas siguientes solo pueden comenzar respetando sus dependencias: primero evidencia del hosting y política de confianza; después pruebas locales y cambios autorizados.
- La Tarea 2.2 queda omitida o bloqueada si no se puede verificar la identidad del proxy y el saneamiento de sus cabeceras; en ese caso se conserva la política de no confiar en cabeceras reenviadas.
- La Tarea 3.1 no podrá habilitar `Secure` desde una cabecera controlable por el cliente.
- Las tareas 3.2 y 3.3 no podrán declarar conformidad solo porque las sesiones se borren en la base de datos: debe comprobarse la expiración HTTP de la cookie.
- La Tarea 4.3 no se ejecuta contra producción sin autorización, cuenta de prueba y procedimiento de bajo impacto.
- La recuperación permanece fuera de servicio por decisión ratificada; su formalización normativa e implementación no pertenecen a TASKS-15.
