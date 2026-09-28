# PLAN-15: Plan Técnico de Seguridad de Sesión en Producción

> **Especificación:** [`specs/15-session-security-production-deployment.spec.md`](15-session-security-production-deployment.spec.md) — RATIFICADA; conformidad del hosting pendiente.
> **Constitución:** [`constitution.md`](../constitution.md) | **Directrices:** [`AGENTS.md`](../AGENTS.md)
> **Estado:** APROBADO por el Arquitecto; Fase de descubrimiento del hosting **completada** (ver §2, resolución de la puerta, 2026-09-28). Implementación pendiente de iniciar por Fases 1–4; la verificación en producción sigue sujeta a autorización del custodio y cuenta de ensayo.
> **Regla de oro:** primero se verifica la topología real del hosting; ninguna cabecera reenviada se vuelve confiable por conjetura. Un entorno sin evidencia suficiente permanece «no verificado» y no se fuerza una configuración de proxy insegura.

---

## 1. Objetivo técnico

Materializar los requisitos ratificados en SPEC-15 con cambios mínimos y revisables en cuatro áreas:

1. Identificación segura de la procedencia usada por el limitador de autenticación.
2. Emisión y expiración coherente de la cookie de sesión.
3. Pruebas locales que separen conexión directa, proxy confiable y cabeceras falsificadas.
4. Documentación de cómo verificar estas propiedades en staging/producción sin exponer secretos ni dejar una sonda pública.

No se modifican endpoints, DDL, plazos de sesión, regla de bloqueo ni el alcance de SPEC-14. La decisión de mantener la recuperación fuera de servicio está ratificada por producto, pero su formalización en SPEC-03 sigue pendiente y no se implementa ni resuelve en este plan.

## 2. Fase previa obligatoria: descubrimiento del hosting

Antes de codificar confianza en proxies o declarar conformidad, el custodio del despliegue recopilará, sin publicar datos sensibles:

- SAPI del hosting y valor observado de `REMOTE_ADDR` en una petición de ensayo.
- Si hay terminación TLS intermedia y qué variable/señal de servidor refleja el HTTPS público.
- Si el origen es accesible directamente además de a través del proxy.
- Qué cabeceras de procedencia añade, reemplaza o conserva el proxy.
- Si el proveedor publica direcciones estables y verificables de los proxies.

**Puerta de decisión:**

- Si no se puede probar una lista de proxies confiable y el saneamiento de sus cabeceras, el sistema usará `REMOTE_ADDR` y no confiará en `X-Forwarded-For`.
- Si el proveedor garantiza HTTPS público pero no expone una señal fiable a PHP, la cookie se marcará `Secure` mediante configuración de despliegue explícita, no mediante una cabecera arbitraria del cliente.
- Si el hosting no permite confirmar la topología, la documentación señalará las propiedades afectadas como no verificadas. No se inventará un rango ni una cabecera para obtener un resultado verde.

Esta fase no requiere cambios de código. El Arquitecto aporta la evidencia del panel/hosting o autoriza una comprobación de bajo impacto con una cuenta de ensayo.

**Resolución de la puerta (2026-09-28, evidencia del custodio):**

- SAPI: PHP 8.4.25 / apache2handler; dominio con SSL automático; **sin Cloudflare propio**.
- Señales observadas: `HTTPS=on`, `REQUEST_SCHEME=https`, `SERVER_PORT=443`; `REMOTE_ADDR` = IP pública real del cliente; `X-Forwarded-For` **idéntica** a `REMOTE_ADDR`; cabeceras de Cloudflare ausentes.
- **Topología concluida:** conexión directa cliente→Apache (sin offload TLS aparente hacia PHP). Señal de servidor directa `$_SERVER['HTTPS']` fiable para `Secure`.
- **Política de proxies: lista de confianza VACÍA** (regla 1 de la puerta). `X-Forwarded-For` se ignora para la procedencia; autoridad única: `REMOTE_ADDR` validada.
- *No verificado:* respuesta del soporte sobre infraestructura intermedia/acceso directo al origen (consulta sin respuesta) y entorno de staging. Si apareciera evidencia contraria, se reabrirá esta puerta antes de poblar la lista.

## 3. Diseño de la solución

### 3.1 Procedencia efectiva y proxies confiables (SPEC-15 RF-02, RF-03)

La decisión de procedencia se centralizará en un resolvedor pequeño, puro y comprobable, invocado por `Request::getClientIp()` o por la frontera HTTP equivalente. Su contrato conceptual será:

```text
resolveClientIp(remoteAddress, forwardedChain, trustedProxyAddresses) -> validatedIp
```

Reglas:

1. Validar la dirección del par conectado (`REMOTE_ADDR`) como IPv4 o IPv6.
2. Si el par conectado no coincide con una dirección configurada como proxy confiable, ignorar por completo cualquier cabecera reenviada y usar el par conectado validado.
3. Si el par es confiable, interpretar la cabecera solo conforme al comportamiento documentado del proxy. Para una cadena de proxies, descartar los saltos confiables desde el extremo conectado y seleccionar el primer salto no confiable validado; rechazar/ignorar una cadena malformada según el contrato de error cerrado.
4. No aceptar nombres de host, cadenas arbitrarias, puertos ni direcciones no válidas como IP de cliente.
5. Si no se dispone de una dirección válida, usar un valor de procedencia controlado y estable, no un valor arbitrario del cliente. La elección final del valor debe preservar el tipo/tamaño aceptado por `login_attempts.ip_address` y documentarse en las pruebas.

La configuración de confianza vivirá en el canal de despliegue ya cargado por `public/index.php` (`deploy/infinityfree/env.php`), como lista explícita de direcciones de pares confiables. El valor predeterminado será lista vacía: **ningún proxy es confiable hasta que se declare**. No se añadirá soporte CIDR/rangos dinámicos salvo que el proveedor lo requiera y dicho requisito se ratifique en una revisión del plan.

**Importante:** solo confiar en que `REMOTE_ADDR` pertenece al proxy no basta si ese proxy conserva una cabecera controlable sin sanear. La estrategia de extracción concreta se seleccionará después de la Fase 2 y deberá coincidir con la evidencia del proveedor; si no puede demostrarse, se ignorará la cabecera.

### 3.2 Política de `Secure` y expiración (SPEC-15 RF-01, RF-02, RF-04)

Centralizar la decisión de seguridad de cookie y compartirla entre emisión y expiración:

- En producción, `Secure` se determina a partir de una señal de servidor verificada o de un ajuste explícito de despliegue cuyo valor sea `true` para el sitio HTTPS.
- En desarrollo local, el comportamiento actual podrá conservarse: `Secure` según HTTPS observado, evitando bloquear HTTP local.
- Ninguna cabecera de cliente no confiable puede forzar `Secure=false` en producción.
- La misma política se aplica al emitir y borrar: nombre `grimorio_session`, `Path=/`, `HttpOnly`, `SameSite=Strict`, y un `Domain` idéntico si llegara a configurarse.
- La expiración se ejecuta solo tras revocación exitosa en servidor. Una revocación fallida no debe dar una respuesta de éxito ni caducar una sesión distinta.

Para cerrar la cookie tras disolución global y renuncia, se expondrá una operación acotada del `SessionManager` que expire la cookie de la petición actual, o se encapsulará la revocación y expiración en el propio servicio. La elección deberá evitar duplicar la definición de atributos en `AuthController` y `AuthService`. No se almacenará el token crudo ni se añadirá otro mecanismo de sesión.

### 3.3 Manejo de `X-Forwarded-For`

No se confiará en el primer elemento de `X-Forwarded-For` por defecto. Antes de habilitarlo:

- se identificará si el proxy reemplaza o concatena el valor recibido;
- se verificará cómo representa una cadena con más de un salto;
- se rechazará cualquier parsing que permita al cliente anteponer una IP arbitraria como identidad efectiva;
- la lista de proxies confiables no se expondrá en respuestas públicas.

Si no se obtiene evidencia suficiente, `X-Forwarded-For` se ignora en todos los entornos de producción para la selección de IP. La conexión directa seguirá identificándose con `REMOTE_ADDR`.

### 3.4 Revocación global y renuncia

- `dissolveAll`: resolver y revocar las sesiones del titular como hoy; solo tras éxito, emitir `Set-Cookie` expirado para la cookie presente.
- `renounceAccount`: tras completar la operación de renuncia y la auditoría exigida por SPEC-03, emitir la expiración de la cookie portadora.
- No cambiar el estado HTTP ni el contenido JSON salvo que las pruebas de integración demuestren una incompatibilidad con el contrato ratificado; cualquier cambio de contrato requerirá enmienda SDD.
- Verificar en HTTP real que el navegador recibe la cookie expiratoria con los mismos atributos de alcance. `headers_list()` en CLI no se tomará como evidencia.

La atomicidad completa de la renuncia y su auditoría no se amplía en este plan; si su análisis revela que el orden impide satisfacer SPEC-15, detener y elevar una enmienda antes de extender el alcance.

## 4. Configuración propuesta

La configuración se cargará desde `env.php`, ya incluido por el Front Controller antes de instanciar los componentes. Nombres tentativos, sujetos a aprobación del plan:

```php
GRIMORIO_COOKIE_SECURE // bool opcional: forzar Secure en el sitio de producción HTTPS
GRIMORIO_TRUSTED_PROXY_IPS // array opcional: direcciones exactas de pares proxy confiables
```

Reglas de configuración:

- ausencia de `GRIMORIO_TRUSTED_PROXY_IPS` equivale a lista vacía;
- tipos inesperados, valores vacíos o direcciones inválidas no agregan confianza;
- `GRIMORIO_COOKIE_SECURE` no puede establecerse a `false` en la configuración de producción aprobada;
- los ejemplos de configuración usarán valores ficticios/no operativos, sin credenciales ni direcciones reales del hosting;
- si la evidencia revela rangos o rotación de IPs, se revisará este plan antes de implementar; no se construirá parser CIDR de manera implícita.

Si se concluye que la configuración propuesta es innecesaria para la topología verificada, se mantendrá el código más simple y se registrará esa decisión en la guía.

## 5. Archivos previstos

| Archivo | Naturaleza propuesta | Responsabilidad |
|---|---|---|
| `src/Core/Request.php` | MODIFICAR | Obtener la IP efectiva mediante una política confiable, no por lectura ciega del primer `X-Forwarded-For`. |
| `src/Core/TrustedProxyResolver.php` | NUEVO, si la revisión mantiene la separación | Resolver y validar IP directa/proxy en lógica pura, tipada y testeable. Comentarios en castellano. |
| `src/Core/SessionManager.php` | MODIFICAR | Unificar los atributos de emisión/expiración y la política de `Secure`; ofrecer la operación de expiración necesaria a las operaciones autorizadas. |
| `src/Services/AuthService.php` | MODIFICAR, solo si centraliza expiración tras revocación | Expirar cookie después de una disolución global o renuncia exitosa, sin cambios de almacenamiento. |
| `src/Controllers/AuthController.php` | MODIFICAR, solo si la decisión de diseño coloca allí la expiración | Integrar el resultado de expiración sin cambiar las respuestas JSON ratificadas. |
| `deploy/infinityfree/env.php` | MODIFICAR, condicional | Añadir configuración explícita para cookie/proxies solo con valores que el custodio haya verificado. No confirmar proxies sin evidencia. |
| `deploy/infinityfree/README.md` | MODIFICAR | Documentar requisitos, configuración verificada, límites y procedimiento de prueba/rollback. |
| `scratch/test_spec15_local.php` | NUEVO | Pruebas locales puras/integradas de procedencia y revocación, con recursos temporales autocontenidos. |
| `scratch/test_session_manager.php` | REUTILIZAR | Regresión de expiración/atributos de cookie; revisar su uso de servidor HTTP temporal antes de ejecutarlo. |
| `scratch/test_auth_controller.php` | REUTILIZAR | Regresión de endpoints de autenticación; añadir casos solo si no se modifica su contrato. |
| `specs/15-session-security-production-deployment.tasks.md` | NUEVO, después de aprobar este plan | Desglosar tareas verificables de implementación y despliegue. |

Los nombres de configuración y el nuevo resolvedor son decisiones de diseño propuestas; podrán ajustarse al confirmar la topología antes de escribir código.

## 6. Estrategia de pruebas

### 6.1 Pruebas unitarias locales

- IP directa IPv4 e IPv6 válidas.
- `X-Forwarded-For` arbitraria desde cliente no confiable: ignorada.
- Par remoto dentro/fuera de lista confiable.
- Cadena de saltos confiables y no confiables de acuerdo al modelo real del proxy.
- Valores vacíos, duplicados, malformados, con hostnames, puerto o espacios impropios.
- Configuración ausente/incorrecta: no aumenta la confianza.
- Política de cookie: HTTP local, HTTPS directo, HTTPS reportado por proxy confiable y señal falsificada.
- El mismo conjunto de atributos de cookie se usa para emisión y expiración.

Los tests de IP comprobarán tanto la resolución como la IP finalmente recibida por `RateLimiter::isBlocked()` y persistida en `login_attempts`; no basta un test aislado del helper.

### 6.2 Integración HTTP local

En un servidor de prueba local y con puerto libre verificado:

1. Login emite cookie con las banderas del modo de prueba.
2. Disolución global devuelve expiración de cookie y revoca todos los tokens del titular.
3. Renuncia devuelve expiración de cookie y el token anterior ya no autentica.
4. Una petición con cabecera reenviada falsificada desde conexión local directa queda asociada a `REMOTE_ADDR`, no al valor falsificado.
5. Pruebas y proceso de servidor se limpian incluso si falla una aserción.

No se ejecutarán scripts de prueba cuyo proceso de autolimpieza no se haya inspeccionado o que maten procesos por puerto sin verificar el PID propio.

### 6.3 Regresión

- `php scratch/test_auth_service.php`
- `php scratch/test_rate_limiter.php`
- `php scratch/test_session_manager.php` (solo tras verificar su proceso/puerto y limpieza)
- `php scratch/test_auth_controller.php` (solo tras verificar sandbox temporal)
- `php scratch/test_csrf_cookie_shield.php` si el puerto dedicado está libre y su cleanup ha sido revisado
- `node scratch/test_auth_client.mjs`
- `node scratch/test_main_auth_integration.mjs`

Las pruebas existentes pueden codificar decisiones previas sobre cabeceras o revocación; actualizarlas se hará solo en la implementación aprobada y según los nuevos criterios de SPEC-15, nunca para silenciar un fallo sin resolver su causa.

### 6.4 Verificación de producción/staging

Esta fase requiere acción autorizada del custodio; no se automatizará contra producción desde el agente.

- Usar cuenta de prueba dedicada, autorizada y no vinculada a datos reales.
- Capturar solo nombres/atributos de `Set-Cookie`; no conservar el valor de la cookie.
- Verificar el host/flujo público HTTPS y la señal observada por PHP sin publicar información privada.
- Simular cabeceras falsificadas mediante una ruta de ensayo autorizada; no generar un asedio de login contra el servicio real.
- Comprobar cierre global y renuncia solo con la cuenta de ensayo, confirmando que no quedan sesiones de esa cuenta.
- Eliminar datos/cuentas temporales conforme al procedimiento aprobado; no borrar registros fuera del sandbox de prueba.
- Registrar topología, fecha, resultado y limitaciones en `deploy/infinityfree/README.md`, sin secretos.

Si staging o producción no permiten la prueba sin riesgo o no ofrecen observabilidad suficiente, dejar el criterio en estado **no verificado**; no ejecutar una mutación destructiva solo para obtener evidencia.

## 7. Orden de trabajo propuesto

1. **Descubrimiento:** recopilar evidencia del hosting y resolver la puerta de decisión de §2.
2. **Plan aprobado:** fijar nombres/configuración y modelo de extracción de IP conforme a evidencia.
3. **Pruebas primero:** escribir pruebas de la IP confiable y de atributos cookie, incluyendo falsificación.
4. **Resolver procedencia:** implementar el resolvedor/configuración mínima; confirmar que `RateLimiter` recibe la IP validada.
5. **Unificar cookie:** centralizar `Secure` y atributos comunes de emisión/expiración.
6. **Cerrar revocaciones:** expirar cookie solo después de `dissolveAll` o renuncia exitosa.
7. **Documentar y probar:** correr regresiones seguras, revisar las cabeceras HTTP en entorno autorizado y documentar resultados.
8. **Cierre:** revisar diff, confirmar que no hay cambios de DDL/API fuera de alcance y actualizar el checklist de SPEC-15 con evidencia real.

## 8. Fuera de alcance técnico

- Añadir endpoints públicos de diagnóstico.
- Habilitar o implementar recuperación de contraseña.
- Cambiar la semántica de rate limiting ratificada.
- Cambiar la política de borrado de intentos o retención de registros.
- Persistir la configuración de proxies en la base de datos.
- Aceptar `Forwarded`, `X-Real-IP` u otras cabeceras por conveniencia sin especificar y verificar el proxy emisor.
- Cambiar `public/.htaccess`, `deploy/infinityfree/htaccess-root` o `user-ini` antes de demostrar que la topología lo requiere; cualquier ajuste al funnel de producción deberá incorporarse al plan tras validación del hosting.
- Cambiar SPEC-14 o las rutas de almacenamiento de avatares.

## 9. Riesgos y decisiones técnicas pendientes

1. **Proxy compartido o direcciones rotatorias:** si el proveedor no documenta sus IPs, no se confiará en la cabecera reenviada; el rate limit podrá agrupar usuarios detrás de una IP común. Registrar esa limitación y solicitar evidencia al hosting.
2. **Cadena de cabeceras:** el algoritmo depende de si el proxy reemplaza o añade saltos. No codificar la extracción hasta conocer ese comportamiento.
3. **HTTPS detrás de TLS offload:** puede requerir cookie `Secure` forzada por configuración en vez de detección por `$_SERVER['HTTPS']`.
4. **Cambio de cookie al renunciar:** renuncia y auditoría no son actualmente una transacción única. Si un fallo de auditoría ocurre después de anonimizar, este plan no debe improvisar una política de rollback: pausar y solicitar enmienda de SPEC-03.
5. **Atributo Domain:** hoy no se fija `Domain`. Mantenerlo ausente salvo requisito ratificado; si se introduce, emisión y expiración deben concordar.
6. **Pruebas de cabeceras en CLI:** `headers_list()` no valida de forma fiable `Set-Cookie` bajo CLI; usar integración HTTP local o entorno de staging autorizado.

## 10. Criterios de salida del plan

Antes de iniciar la implementación (la aprobación de este plan ya fue concedida):

- [x] Plan técnico aprobado por el Arquitecto.
- [x] Evidencia del hosting revisada; los proxies se configuran solo si son identificables y confiables. *(Resuelto: no identificables → lista vacía; ver §2.)*
- [x] Política de cookie para producción definida sin depender de cabeceras de cliente. *(Resuelto: `$_SERVER['HTTPS']` como señal directa de servidor; refuerzo opcional `GRIMORIO_COOKIE_SECURE=true`; ver §2.)*
- [ ] Contratos y criterios de SPEC-15 trazados a pruebas propuestas.
- [ ] Pruebas HTTP y limpieza de recursos revisadas por seguridad.
- [ ] Confirmado que SPEC-14, DDL, endpoints y recuperación quedan fuera del alcance.

**Puerta de implementación:** la aprobación del plan no equivale a autorización para desplegar ni a evidencia de conformidad. Las tareas de implementación que dependan de la topología real permanecen bloqueadas hasta completar y documentar las comprobaciones anteriores; cualquier prueba de producción requiere autorización del custodio y cuenta de ensayo.
