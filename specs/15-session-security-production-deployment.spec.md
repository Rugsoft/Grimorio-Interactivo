# SPEC-15: Seguridad de Sesión en Producción — Cookies HTTPS y Procedencia Confiable

> **Constitución:** [`constitution.md`](../constitution.md) | **Directrices:** [`AGENTS.md`](../AGENTS.md)
> **Estado:** RATIFICADA por el Arquitecto; topología de producción verificada por evidencia del custodio (2026-09-28, ver §11); verificación funcional en producción (cookies/revocación con cuenta de ensayo) pendiente de Tarea 4.3.
> **Área:** Verificación de las garantías de sesión de SPEC-03 en la topología real de producción.
> **Precedentes:** SPEC-03 (Autenticación y RBAC), SPEC-13 (paridad de despliegue) y SPEC-14 (enmienda de despliegue).
> **Restricción:** Esta especificación define resultados observables y criterios de aceptación. No prescribe una librería, proveedor, topología concreta ni modificación de código antes de su ratificación.

---

## 1. Contexto y objetivo

SPEC-03 define sesiones portadas por cookie y protección de acceso por procedencia. La revisión del despliegue identifica dos garantías cuyo resultado puede depender del entorno real:

1. La bandera `Secure` depende de que PHP reconozca la petición como HTTPS. Si TLS termina en un proxy, la conexión que recibe PHP puede ser distinta de la conexión pública.
2. El limitador de autenticación usa la IP de cliente, pero las cabeceras de proxy solo son fiables cuando las proporciona un proxy confiable y no el propio cliente.

Además, las operaciones de disolución global y renuncia deben dejar la cookie local caducada, no solo revocar la sesión en la base de datos.

**Objetivo:** verificar que las garantías de sesión acordadas en SPEC-03 se cumplen en un entorno representativo de producción, y documentar las limitaciones si el hosting no permite verificarlas.

Esta especificación **no crea una política de autenticación distinta**, no cambia los endpoints, no altera el esquema de datos y no amplía el alcance ratificado de SPEC-14. La política ratificada de bloqueo —cinco fallos acumulados en una ventana de quince minutos; un acceso exitoso no borra los fallos vigentes— permanece definida por SPEC-03.

---

## 2. Alcance y exclusiones

### Dentro del alcance

- Emisión y atributos de la cookie de sesión bajo HTTPS en producción.
- Confianza en señales de esquema e IP reenviadas por proxies.
- Identificación de procedencias para el limitador de autenticación.
- Expiración de la cookie local al disolver todos los vínculos o renunciar.
- Evidencia de pruebas del entorno real o de un entorno representativo.
- Documentación de resultados y limitaciones específicas del hosting.

### Fuera del alcance

- Cambios al flujo de recuperación de contraseña, que permanece deshabilitado hasta aprobar un canal de entrega según la enmienda propuesta a SPEC-03.
- Cambios en la política del limitador ratificada en SPEC-03.
- Cambios de roles o permisos RBAC.
- Cambios en DDL, esquema SQLite o esquema MySQL.
- Cambios de comportamiento o alcance de SPEC-14.
- Elección de proveedor de TLS, proxy o hosting.
- Incorporación de HSTS u otra política HTTP adicional, salvo que una especificación independiente la ratifique.

---

## 3. Actores

- **Adepto vinculado:** inicia o disuelve sesiones desde un navegador.
- **Custodio del despliegue:** configura el hosting y documenta las señales de proxy confiables disponibles.
- **Responsable de verificación:** comprueba las cookies y el comportamiento del limitador en staging o producción sin exponer credenciales.
- **Proxy confiable, si existe:** componente de infraestructura que termina TLS o reenvía la dirección del cliente conforme a una política documentada.

---

## 4. Historias de usuario

- **HU-01 — Cookie protegida en producción:**
  Como adepto que entra al santuario por HTTPS, quiero que mi vínculo viaje en una cookie protegida, para que no pueda transmitirse en claro por conexiones inseguras.

- **HU-02 — Procedencia no falsificable:**
  Como custodio, quiero que el limitador use una IP determinada por la conexión o por un proxy confiable, para que un cliente no pueda eludir el límite escogiendo una cabecera de IP.

- **HU-03 — Revocación visible en el navegador:**
  Como adepto que disuelve sus vínculos o renuncia, quiero que el navegador elimine la cookie portadora, para que la interfaz y el servidor reflejen el estado anónimo.

- **HU-04 — Despliegue verificable:**
  Como custodio, quiero poder demostrar las garantías anteriores en una topología representativa, para conocer si el hosting satisface los requisitos antes de declarar el despliegue completo.

---

## 5. Requisitos funcionales

### RF-01 — Emisión segura de la cookie

1. En producción, cuando la aplicación se sirva por HTTPS, la cookie de sesión `grimorio_session` DEBERÁ incluir `Secure`, `HttpOnly`, `SameSite=Strict` y `Path=/`.
2. La duración y demás atributos de la cookie se mantendrán conforme a SPEC-03; esta especificación no redefine el plazo de sesión.
3. Ningún parámetro controlable por el cliente —incluidas cabeceras reenviadas no confiables— podrá activar o desactivar por sí solo `Secure`.
4. El entorno local HTTP podrá conservar su comportamiento de desarrollo, siempre que la excepción no afecte a la configuración de producción.

### RF-02 — Reconocimiento de HTTPS tras un proxy

1. Si TLS termina en un proxy, el backend solo podrá considerar una señal reenviada de HTTPS cuando la petición proceda de un proxy declarado confiable por el despliegue.
2. El despliegue DEBERÁ documentar la señal confiable y la topología que la produce.
3. Si no se puede distinguir de forma fiable el tráfico del proxy del tráfico directo de clientes, el despliegue se considerará **no verificado** respecto a esta garantía.
4. La ausencia de una señal confiable no se resolverá confiando automáticamente en cabeceras que cualquier cliente pueda enviar.

### RF-03 — Procedencia usada por el limitador

1. Para peticiones directas, el limitador DEBERÁ usar la dirección de conexión que proporciona el servidor web.
2. Para peticiones detrás de un proxy confiable, podrá usar la dirección de cliente reenviada conforme a la política documentada de ese proxy.
3. El backend no deberá aceptar como identidad de procedencia una cabecera de IP suministrada directamente por un cliente no confiable.
4. Las señales de IP malformadas o no atribuibles a un proxy confiable no podrán permitir que el cliente elija libremente la clave del limitador.
5. Las peticiones de login seguirán sujetas a la política de cinco fallos acumulados en quince minutos establecida en SPEC-03. La limitación se aplica a la procedencia y no bloquea directamente la cuenta.
6. La IP y las cabeceras utilizadas para evaluar el límite no se devolverán en las respuestas públicas de la API.

### RF-04 — Expiración de la cookie en operaciones de revocación

1. Al disolver todos los vínculos de una cuenta, la respuesta DEBERÁ expirar en el navegador la cookie portadora del vínculo actual.
2. Al renunciar a la cuenta, la respuesta DEBERÁ expirar la cookie portadora después de revocar las sesiones en el servidor.
3. La expiración usará atributos compatibles con la cookie emitida, en particular el mismo nombre y `Path=/`; si se declarase un atributo `Domain`, también deberá coincidir.
4. La revocación en base de datos continuará siendo la autoridad: una cookie que permanezca en el navegador por un fallo del cliente no deberá permitir autenticación.

### RF-05 — Verificación y documentación de despliegue

1. Antes de declarar conformidad, se deberá verificar la emisión de cookie por HTTPS en un entorno representativo de producción.
2. Se deberá demostrar que cabeceras reenviadas falsificadas desde un cliente no confiable no alteran la IP efectiva del limitador.
3. El resultado de las pruebas y las señales de proxy confiables se documentarán en la guía de despliegue correspondiente.
4. Si el hosting no ofrece medios para confirmar alguna garantía, esta se marcará como **no verificada**; no se declarará cumplimiento pleno por inferencia.

---

## 6. Contrato de API

Esta especificación no añade endpoints ni cambia el contrato HTTP existente.

La verificación deberá observar las respuestas de los flujos actuales de autenticación y revocación, sin exponer credenciales, tokens de sesión, cabeceras privadas ni datos de otros usuarios. No se requiere una sonda pública permanente. Si el plan técnico necesitara una sonda transitoria, su autorización y condiciones de seguridad deberán definirse antes de implementarla.

---

## 7. Requisitos no funcionales

- **RNF-01 — No confianza implícita:** solo señales originadas por infraestructura declarada confiable podrán influir en el esquema HTTPS o en la IP de cliente.
- **RNF-02 — Privacidad de diagnóstico:** pruebas, mensajes y documentación no incluirán valores de cookies, tokens, credenciales, direcciones de otros usuarios ni trazas internas.
- **RNF-03 — Degradación honesta:** si no se pueden validar las señales del hosting, se documentará el riesgo y el estado quedará como no verificado.
- **RNF-04 — Sin nuevas dependencias:** las pruebas y garantías no podrán requerir librerías externas prohibidas por el proyecto.
- **RNF-05 — Compatibilidad:** la verificación no alterará los contratos de SPEC-03 ni la configuración de avatares ratificada por SPEC-14.

---

## 8. Casos límite

1. **HTTPS termina en proxy confiable:** la cookie emitida por una petición pública HTTPS sigue llevando `Secure`, aunque el enlace interno entre proxy y PHP no use TLS.
2. **Cliente directo falsifica cabecera HTTPS:** la aplicación no trata esa petición como HTTPS basándose únicamente en la cabecera del cliente.
3. **Cliente directo falsifica `X-Forwarded-For`:** el valor no altera la procedencia usada por el limitador.
4. **Proxy no documentado o no verificable:** el despliegue queda no verificado para esquema o procedencia, según corresponda.
5. **Revocación global:** se eliminan las sesiones en servidor y la respuesta caduca la cookie presente en el navegador.
6. **Renuncia:** la cuenta queda anonimizada según SPEC-03, sus sesiones son revocadas y el navegador recibe la expiración de la cookie.
7. **Cookie copiada tras la revocación:** aunque el navegador no la elimine, una petición posterior no autentica con ese token.
8. **Entorno local HTTP:** las pruebas locales no se usan como evidencia de que producción emita `Secure`.

---

## 9. Criterios de aceptación

- [x] En HTTPS representativo de producción, la cookie emitida lleva `Secure`, `HttpOnly`, `SameSite=Strict` y `Path=/`. *(Verificado en producción 2026-09-28: arnés 21/21.)*
- [x] Las pruebas identifican y documentan qué componente termina TLS y qué señal de esquema es confiable. *(Guía §7b: apache2handler sin offload; `$_SERVER['HTTPS']` señal canónica.)*
- [x] Una cabecera HTTPS enviada directamente por un cliente no confiable no cambia la decisión de transporte seguro. *(Arnés local 6.3; el forjador no lee cabeceras.)*
- [x] Una cabecera de IP falsificada desde un cliente no confiable no cambia la clave usada por el limitador. *(Arnés local Fases 1–2; Tarea 2.1.)*
- [x] La IP reenviada solo se usa cuando la conexión procede de un proxy documentado y confiable. *(Matriz M del arnés, 12 casos; lista de confianza vacía ratificada — Tareas 0.3/2.2.)*
- [x] La disolución global revoca las sesiones de servidor y expira la cookie del navegador. *(Arnés local 6.4 y producción: Set-Cookie expiratorio con alcance idéntico.)*
- [x] La renuncia revoca las sesiones, completa el contrato de anonimización de SPEC-03 y expira la cookie del navegador. *(Arnés local 6.5 y producción: RF-09.3/09.4 + Set-Cookie expiratorio.)*
- [x] Una cookie revocada no autentica peticiones posteriores aunque el cliente la reenvíe. *(Arnés local y producción: disolución y renuncia.)*
- [x] Las pruebas no exponen secretos ni requieren un endpoint de diagnóstico público permanente. *(RNF-02: token jamás impreso/persistido; sin sondas públicas nuevas.)*
- [x] La documentación registra versión/entorno probado, topología relevante y cualquier limitación pendiente. *(Guía §7b y §9b con los tres veredictos y el método anti-bot.)*
- [x] No se modifican endpoints, DDL, la semántica del limitador ni el alcance de SPEC-14 bajo esta spec. *(Diff 5aa8925^..HEAD verificado en la Tarea 4.4: database/, Models/, front controller, RateLimiter, AvatarService y funnel intactos.)*

---

## 10. Casos de prueba requeridos

1. **Cookie HTTPS de login:** autenticar una cuenta de prueba por HTTPS y verificar los atributos requeridos en `Set-Cookie`.
2. **Cookie local de desarrollo:** probar HTTP local y confirmar que su excepción no cambia la configuración documentada de producción.
3. **Señal HTTPS falsificada:** enviar una cabecera de esquema desde una conexión directa y verificar que no se trata como señal confiable.
4. **IP falsificada en conexión directa:** enviar `X-Forwarded-For` arbitraria sin proxy confiable y verificar que no cambia la IP registrada para el intento.
5. **IP detrás de proxy confiable:** enviar una petición por la ruta real del proxy y confirmar que la procedencia derivada coincide con el contrato documentado.
6. **Cabecera reenviada malformada:** verificar que no genera una procedencia elegida por el cliente ni elude el límite.
7. **Disolución global:** comprobar revocación de todas las sesiones, expiración de la cookie local y rechazo de la cookie anterior.
8. **Renuncia:** comprobar anonimización conforme a SPEC-03, revocación de sesiones, expiración de la cookie y rechazo del token anterior.
9. **No filtración:** verificar que respuestas y registros de diagnóstico no incluyen cookies, secretos ni trazas internas.

Las pruebas que requieran una cuenta o infraestructura de producción deberán usar una cuenta de ensayo autorizada y no mutar datos de usuarios reales.

## 11. Decisiones ratificadas y verificaciones pendientes

**Decisiones ratificadas:**

- La comprobación de cookies HTTPS y proxies confiables pertenece a esta especificación independiente; SPEC-14 conserva su alcance sobre la efigie propia.
- La política del limitador es acumulada: cinco fallos dentro de quince minutos; un éxito no borra fallos aún vigentes.
- La recuperación de contraseña queda fuera de servicio hasta que se apruebe y configure un canal de entrega. Su reactivación no forma parte de SPEC-15.

**Pendiente de evidencia para declarar conformidad:**

- ~~Topología exacta del hosting y señal que permite reconocer HTTPS detrás del proxy, si lo hay.~~ → **Verificado (2026-09-28):** PHP 8.4.25 / apache2handler en `grimoriointeractivo.freedev.app`, SSL automático, **sin Cloudflare propio**. Señales observadas: `HTTPS=on`, `REQUEST_SCHEME=https`, `SERVER_PORT=443` (señal directa de servidor, sin offload TLS aparente); `REMOTE_ADDR` = IP pública real del cliente; `X-Forwarded-For` idéntica a `REMOTE_ADDR`; cabeceras de Cloudflare ausentes. Cookie de login ya emitida con `Secure`, `HttpOnly`, `SameSite=Strict`, `Path=/`.
- ~~Qué proxy o proxies pueden considerarse confiables y cómo el hosting permite distinguirlos de clientes directos.~~ → **Resuelto (2026-09-28):** sin evidencia de proxies intermedios, la **lista de proxies confiables queda VACÍA**: se ignoran las cabeceras reenviadas y la única autoridad de procedencia es `REMOTE_ADDR` validada. Si el soporte del hosting confirmara infraestructura intermedia, la lista solo podrá poblarse con evidencia nueva registrada en TASKS-15.
- Entorno staging o procedimiento seguro disponible para ejecutar las pruebas de aceptación. *(No verificado: el custodio no dispone de staging; las pruebas de aceptación de §9 requieren cuenta de ensayo en producción autorizada por el custodio — Tarea 4.3.)*
