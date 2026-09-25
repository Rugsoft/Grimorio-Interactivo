# SPEC-12: El Panel del Adepto — Morada Privada de la Identidad

> **Constitución:** [`constitution.md`](constitution.md) | **Directrices:** [`AGENTS.md`](AGENTS.md)  
> **Estado:** Borrador enmendado tras auditoría de QA — Pendiente de ratificación del Arquitecto  
> **Área:** Identidad del Adepto, Gobierno Personal y Contemplación del Propio Vínculo  
> **Restricción:** Definición estricta del QUÉ y el POR QUÉ. Cero detalles de implementación técnica, arquitectura o nombres de archivos: eso vive en el plan técnico.

---

## 1. Contexto y Objetivo

El santuario ya forjó la identidad del adepto (SPEC-03), el juramento perpetuo de linaje (SPEC-09), las hermandades (SPEC-07), el tomo personal (SPEC-11) y la moderación solemne (SPEC-08). Pero la información del adepto vive **dispersa**: el distintivo de cabecera declara alias y clan, el banner de convalecencia anuncia el veto, el Vestíbulo custodia los veredictos, el tomo guarda la colección. No existe morada única donde el adepto **contemple su identidad íntegra** ni donde **gobierne lo que le pertenece** (su efigie, su frase de paso).

El objetivo de esta especificación es definir el **Panel del Adepto**: la cámara privada a la que todo vinculado entra tras autenticarse para ver su información completa en una sola mirada y ejercer los únicos dos actos de gobierno que le pertenecen en primera persona — vestirse un avatar y custodiar su frase de paso. El panel es una **vitrina de datos ya ratificados** (jamás una fuente nueva de verdad) y una **puerta que conduce** a las cámaras canónicas de cada gestión (Vestíbulo, Taller, Torre, Tomo), jamás un duplicado de ellas.

Dos principios rectores, ratificados por el Arquitecto durante la deliberación de esta spec:

1. **El panel es estrictamente privado.** Nadie contempla el panel ajeno; no existen fichas públicas de adeptos, buscador de personas ni mensajería. La única vía de descubrimiento entre adeptos sigue siendo el catálogo de clanes (SPEC-07).
2. **El panel no crea verdad nueva.** Todo dato que expone ya vive en el santuario (sesión, bitácora, tomo, dominio); toda acción que ofrece ya tiene cámara canónica o es propia de la identidad personal (avatar, frase de paso).
3. **El avatar entra con el juramento.** El avatar es un acto de identidad plena del linajado: el peregrino contempla su identidad sin efigie y la sección de avatar le conduce a la ceremonia (SPEC-09); el catálogo cerrado de operaciones del peregrino no se toca.
4. **La retirada por abuso no existe aquí.** Los avatares propios jamás proyectan ante terceros, de modo que no hay daño público que gobernar: toda gestión del avatar es voluntaria y pertenece al propio adepto. La potestad disciplinaria del Admin Supremo queda confinada a sus cámaras ratificadas (contenidos del catálogo: obras), jamás extendida a la imagen privada de nadie.

---

## 2. Actores y Usuarios

* **El Adepto (vinculado con rol de estudio):**  
  Entra al panel para contemplar su identidad íntegra, vestir su avatar, custodiar su frase de paso y consultar su colección, su clan y su convalecencia si la hubiere.
* **El Maestro del Códice (vinculado con oficio de moderación):**  
  Además de lo anterior, contempla sus firmas prestadas y su estado, para llevar la cuenta de sus deberes sin recorrer la Torre.
* **El Admin Supremo:**  
  Usa el panel como cualquier vinculado (identidad íntegra, avatar, frase de paso). El panel **no le añade** gobierno adicional: sus cámaras de gobernanza siguen siendo las propias de su oficio.
* **El Peregrino sin Linaje (cuenta sin juramento):**  
  Puede entrar al panel en lo que toca a sus credenciales (consulta y frase de paso), conforme al catálogo de operaciones permitidas de SPEC-09; las secciones sujetas al juramento le declaran su estado y le conducen a la ceremonia.
* **El Usuario con Necesidades de Accesibilidad:**  
  Requiere que la cuenta atrás de convalecencia, los veredictos y los cambios de estado se anuncien por región viva sin espamear, que todo el panel sea operable por teclado y que el contraste cumpla WCAG 2.1 AA.

---

## 3. Historias de Usuario

* **HU-01 (La vitrina de la identidad):**  
  *Como* adepto linajado,  
  *Quiero* entrar a mi panel y contemplar en una sola mirada mi avatar, mi alias, mi oficio, mi linaje jurado con su heráldica, mi clan con su blasón y la antigüedad de mi vínculo,  
  *Para* reconocer mi lugar en el santuario sin recorrer cámaras dispersas.

* **HU-02 (Vestir la efigie):**  
  *Como* adepto,  
  *Quiero* elegir un avatar del catálogo del santuario o subir una imagen propia que respete el marco ceremonial,  
  *Para* que mi identidad me represente ante mí mismo en cada visita.

* **HU-03 (Custodia activa de la frase de paso):**  
  *Como* adepto,  
  *Quiero* cambiar mi frase de paso desde el panel presentando la actual y confirmando dos veces la nueva,  
  *Para* custodiar mi cuenta sin depender de perder el acceso ni del pergamino de recuperación.

* **HU-04 (La penitencia con fecha):**  
  *Como* adepto en convalecencia de clan,  
  *Quiero* ver la cuenta atrás viva de mi veto, su causa noble y qué queda retenido mientras dure,  
  *Para* saber con certeza cuándo vuelvo al servicio de las hermandades.

* **HU-05 (Rendir cuentas de mis actos):**  
  *Como* adepto,  
  *Quiero* hojear los últimos asientos de la Bitácora que me conciernen (juramento, adhesión, firmas sobre mis obras, vetos),  
  *Para* detectar cualquier acto ajeno sobre mi identidad y rendir memoria de los míos.

* **HU-06 (Los deberes del Maestro):**  
  *Como* Maestro del Códice,  
  *Quiero* contemplar en mi panel mis firmas prestadas y su estado (en deliberación, retiradas, anuladas),  
  *Para* equilibrar mi deber de moderación sin abrir la Torre para consultarlo.

* **HU-07 (La puerta que conduce):**  
  *Como* adepto,  
  *Quiero* que cada sección del panel que necesita gestión (clan, tomo, taller, torre) me conduzca a su cámara canónica con un solo gesto,  
  *Para* que el panel sea mi vestíbulo personal hacia el santuario, jamás un segundo lugar donde repetir las gestiones.

---

## 4. Requisitos Funcionales (Notación EARS en Español)

### RF-01: Acceso, Privacidad y Estados de Cuenta

* **RF-01.1 [Ubicuo]:**  
  CUANDO un usuario autenticado acceda al panel, el sistema DEBERÁ exponer únicamente su propia información: jamás revelará datos, avatares, bitácoras ni colecciones de otros adeptos.
* **RF-01.2 [Dirigido por Eventos]:**  
  SI un visitante anónimo solicita el panel, ENTONCES el sistema DEBERÁ retenerlo en el umbral mediante la interceptación canónica del santuario (SPEC-03), conservando la intención de retorno.
* **RF-01.3 [Estado]:**  
  MIENTRAS el linaje del vinculado sea nulo (peregrino iniciático), el panel DEBERÁ declarar su estado solemne («Peregrino sin Linaje») y las secciones sujetas al juramento (linaje, clan, obras y avatar) DEBERÁN exhibirse como pendientes del juramento con conducción directa a la ceremonia; la sección de credenciales (frase de paso) DEBERÁ permanecer plenamente operativa, conforme al catálogo de operaciones permitidas por SPEC-09 (RF-01.4).
* **RF-01.4 [Dirigido por Eventos]:**  
  SI la sesión caduca durante una edición del panel (cambio de frase de paso o avatar), ENTONCES el sistema DEBERÁ responder con aviso solemne controlado, conducir al umbral para reautenticar y conservar el panel intacto al retorno, sin exponer trazas internas.
* **RF-01.5 [Ubicuo]:**  
  CUANDO el Admin Supremo — con o sin linaje legado — acceda al panel, el sistema DEBERÁ declarar su estado fundacional conforme a SPEC-09 (RF-01.6): sin secciones de linaje fantasma y sin retención de ceremonia.

### RF-02: La Vitrina de la Identidad Íntegra

* **RF-02.1 [Ubicuo]:**  
  CUANDO el panel se muestre a un vinculado, DEBERÁ exponer como mínimo: el avatar vigente, el alias, el correo personal (es el dato del propio dueño), el oficio en la lengua del santuario (Adepto, Maestro del Códice, Admin Supremo, Lector), el linaje jurado con su heráldica (la MISMA representación que SPEC-07/SPEC-09 forjan), el clan con su blasón si perteneciere, la **fecha del sellado del juramento** como única estampa del vínculo con linaje (la antigüedad narrada nace de ella) y el estado del vínculo de sesión actual (qué dispositivo/morada es la presente y cuándo expira). El panel JAMÁS imprimirá identificadores técnicos crudos (Artículo V: se codifica, no se imprime) y el correo, al ser dato personal, queda sujeto a la retención de la renuncia (SPEC-03 RF-09) igual que el avatar propio.
* **RF-02.2 [Estado]:**  
  SI el adepto careciere de clan, ENTONCES el panel mostrará su identidad de linaje sin hermandad con la leyenda canónica, sin clan fantasma y sin vacantes consumidas (coherencia estricta con SPEC-09 RF-04.1).
* **RF-02.3 [Dirigido por Eventos]:**  
  SI el linaje del adepto no figurare en el catálogo local (cuenta legada o degradación), ENTONCES el panel DEBERÁ vestir la leyenda neutra canónica («Linaje jurado»), idéntica a la del distintivo de cabecera, sin inventar heráldica.
* **RF-02.4 [Ubicuo]:**  
  CUANDO el panel exponga el oficio de moderación de un Maestro o del Admin Supremo, DEBERÁ anunciarlo también en el nombre accesible de la identidad, con la misma solemnidad que el distintivo de cabecera (coherencia RF-08.2 de SPEC-03).

### RF-03: El Avatar — Catálogo del Santuario y Efigie Propia

* **RF-03.1 [Ubicuo]:**  
  El sistema DEBERÁ ofrecer a todo vinculado **con linaje jurado** un **catálogo de avatares del santuario** (efigies y heráldicas ya existentes en el canon visual), cuya selección surta efecto de inmediato y sin ceremonia de moderación alguna. El peregrino sin linaje queda excluido por la retención de sustancia (SPEC-09 RF-05.1): su panel declara el estado pendiente y conduce a la ceremonia, sin ofrecerle escritura alguna de identidad (principio rector 3).
* **RF-03.2 [Dirigido por Eventos]:**  
  CUANDO el adepto suba una imagen propia, el sistema DEBERÁ validarla por formato y dimensiones (peso máximo, lados máximos, formatos admitidos) y encajarla en el marco ceremonial cuadrado antes de aceptarla; SI la imagen fallare la validación, ENTONCES el sistema DEBERÁ responder con aviso solemne que nombre el motivo en lengua noble, sin mutar el avatar vigente ni exponer trazas.
* **RF-03.3 [Ubicuo]:**  
  MIENTRAS un avatar propio estuviere vigente, el sistema DEBERÁ exhibirlo en el panel y en la identidad de cabecera del **propio adepto**; el santuario JAMÁS lo expondrá en superficies compartidas ni catálogos públicos (ver §7 y dudas abiertas).
* **RF-03.4 [Dirigido por Eventos]:**  
  CUANDO el adepto eligiere otro avatar del catálogo o retirare el propio, el cambio DEBERÁ surtir efecto de inmediato y el avatar anterior dejará de referenciarse.
* **RF-03.5 [Estado]:**  
  SI el adepto retirare su avatar propio sin elegir otro, ENTONCES el sistema DEBERÁ vestir el avatar canónico por defecto del santuario: la identidad jamás quedará sin efigie.
* **RF-03.6 [Ubicuo]:**  
  CUANDO un cambio de avatar se consumare con efecto real (elección distinta de la vigente o alta/retiro de efigie propia), el acto DEBERÁ quedar registrado en la Bitácora de Auditoría conforme al catálogo cerrado de SPEC-03 (RF-08.1); SI el acto fuere inocuo (re-subida de la imagen idéntica a la vigente), ENTONCES el sistema DEBERÁ rechazarlo con aviso noble específico («la imagen ya viste tu identidad») sin asiento alguno: un acto sin efecto real no se inscribe.

### RF-04: La Custodia de la Frase de Paso

* **RF-04.1 [Dirigido por Eventos]:**  
  CUANDO el adepto solicitare el cambio de frase de paso desde el panel, el sistema DEBERÁ exigir la frase actual y la doble entrada de la nueva, con la misma regla de solidez vigente del registro (SPEC-03); SI la frase actual no coincidiere, las nuevas difirieren o la solidez fuere insuficiente, ENTONCES el sistema DEBERÁ responder con un único aviso solemne que JAMÁS revele cuál de las tres causas falló. Dos salvedades honestas: SI la nueva frase coincidiere con la vigente, ENTONCES el sistema DEBERÁ rechazar el acto con aviso noble específico («la nueva frase coincide con la vigente») sin asiento ni mutación; y SI el envío fuere reenvío legítimo (la frase presentada como actual ya es la nueva y coincide con la doble entrada), ENTONCES el sistema DEBERÁ responder con el recibo idempotente del acto ya consumado — el aviso ciego jamás mentirá al dueño legítimo; el ciego protege solo ante terceros que ignoran la frase vigente.
* **RF-04.2 [Dirigido por Eventos]:**  
  CUANDO el cambio se consumare, la sesión actual DEBERÁ persistir sin expulsión y las demás sesiones activas del adepto DEBERÁN disolverse, anunciándolo en el recibo del acto (la custodia de la frase es acto de desconfianza sanitaria: las otras moradas se vacían por seguridad). Distinción deliberada y ratificada respecto al flujo de recuperación de SPEC-03 (RF-06.3): el pergamino de recuperación revoca TODAS las sesiones incluida la actual porque nace de la pérdida de acceso; el cambio consciente desde el panel la conserva porque el dueño está presente y autenticado. La disolución de las demás sesiones es la ÚNICA gestión de sesiones que el panel ejecuta (no enlaza): es efecto inseparable del acto de custodia, no una gestión independiente.
* **RF-04.3 [Ubicuo]:**  
  El acto DEBERÁ inscribirse en la Bitácora de Auditoría conforme al catálogo cerrado de SPEC-03 (RF-08.1), con su estampa temporal y sin mutar el catálogo con actos nuevos si el existente lo ampara.

### RF-05: La Convalecencia con Cuenta Atrás

* **RF-05.1 [Estado]:**  
  SI el adepto estuviere en convalecencia de clan, el panel DEBERÁ mostrar: la cuenta atrás viva hasta el alzamiento del veto (en días naturales, la unidad que SPEC-07 ratifica), la causa noble (la partida voluntaria o expulsión que la originó, con el nombre del clan anterior) y el conjunto exacto de retenciones que SPEC-07 RF-01.6 ratifica — ingresar a otro clan o fundar una nueva hermandad —, sin ampliarlo ni menguarlo.
* **RF-05.2 [Dirigido por Eventos]:**  
  CUANDO la cuenta atrás alcanzare el cero con el panel abierto, el estado DEBERÁ refrescarse sin recarga y el alzamiento DEBERÁ anunciarse por la región viva, sin recargar la cámara ni perder el estado del panel.
* **RF-05.3 [Ubicuo]:**  
  SI el adepto no estuviere en convalecencia, ENTONCES el panel no mencionará penitencia alguna: el silencio es el estado saludable y ninguna sección fantasma de veto ha de asustar al vinculado en paz.

### RF-06: La Bitácora Personal (Lente de Lectura)

* **RF-06.1 [Ubicuo]:**  
  El sistema DEBERÁ exponer en el panel los últimos asientos de la Bitácora de Auditoría (SPEC-03 RF-08) **cuyo sujeto o destinatario afectado es el propio adepto** — su juramento, sus adhesiones y retiradas, los veredictos de sus postulaciones, los vetos que le alcanzan, los actos sobre sus obras y sus firmas —, en orden inverso al cronológico, con estampa temporal y nombre del acto en la lengua del santuario. Los actos colectivos del clan sin el adepto como sujeto (coronaciones ajenas, deducciones a la casa, crónicas de Dominio) no entran en la lente personal: esa visión colectiva vive en las cámaras del clan.
* **RF-06.2 [Ubicuo]:**  
  La bitácora del panel JAMÁS escribirá, duplicará ni resumirá asientos por cuenta propia: es pura lente de lectura sobre el registro inmutable ya ratificado; la atribución de «lo que le concierne» la declara el plan técnico sobre los actos existentes.
* **RF-06.3 [Dirigido por Eventos]:**  
  SI no existiere asiento alguno que concierna al adepto, ENTONCES el panel DEBERÁ mostrar una leyenda solemne de silencio, jamás una página vacía cruda.
* **RF-06.4 [Ubicuo]:**  
  CUANDO un asiento concierna a un acto colectivo con terceros (p. ej. una firma ajena sobre la obra del adepto), el panel DEBERÁ narrar el acto sin exponer datos personales de los terceros más allá de lo que la bitácora pública ya publica (coherencia con SPEC-03 RF-08.2).

### RF-07: La Vitrina de Obras y Deberes

* **RF-07.1 [Ubicuo]:**  
  El sistema DEBERÁ exponer los contadores de la colección personal del adepto conforme a SPEC-11 (obras selladas en el tomo; homenajes rendidos, cuya existencia como dato agregado del adepto ya ratifica SPEC-11 — el voto único por `UNIQUE(user_id, spell_id)` —), con conducción directa al tomo; los contadores provienen de los datos existentes, jamás de cómputos nuevos.
* **RF-07.2 [Dirigido por Eventos]:**  
  SI el adepto ostentare el oficio de Maestro, ENTONCES el panel DEBERÁ exponer sus firmas prestadas y su estado (en deliberación, retiradas, anuladas) conforme a SPEC-08, con conducción a la Torre del Maestro.
* **RF-07.3 [Estado]:**  
  SI el clan del adepto publicare gloria semanal conforme a SPEC-07, ENTONCES el panel DEBERÁ exponerla con su semana; SI no existiere cómputo vigente, ENTONCES el panel mostrará la leyenda canónica sin cifras fantasma. *(Ratificar alcance en la duda abierta 3.)*

### RF-08: La Puerta que Conduce (Coherencia de Cámaras)

* **RF-08.1 [Ubicuo]:**  
  El panel DEBERÁ ser accesible desde la identidad de cabecera del vinculado mediante una **nueva opción del menú arcano del distintivo** (la morada personal), añadida sin tocar las opciones ya ratificadas (libro personal y disoluciones de sesión), con un solo gesto y sin nueva ceremonia ni llaves adicionales.
* **RF-08.2 [Ubicuo]:**  
  CUANDO una sección del panel requiriere gestión que exceda la contemplación (postular a clan, editar obra, moderar, fundar hermandad), el sistema DEBERÁ conducir al adepto a su cámara canónica (Vestíbulo de SPEC-10, Tomo de SPEC-11, Taller y Torre de SPEC-08/04), jamás duplicar la gestión dentro del panel. El enlace a la renuncia de cuenta (exclusión 2) conduce a la superficie canónica de SPEC-03 RF-09 con su confirmación solemne propia: el panel solo muestra la puerta, jamás la palanca.
* **RF-08.3 [Ubicuo]:**  
  El panel JAMÁS ofrecerá cambio ni revocación del linaje jurado (SPEC-09, RF-03.4: perpetuo e irrevocable), ni cambio de alias o correo, ni baja de cuenta; los actos que ya tienen cámara canónica se enlazan, no se repiten.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Velo Arcano y Soberanía Lingüística):**  
  Rótulos, avisos, recibos y leyendas en noble castellano con la solemnidad del santuario (Artículos IV y V); identificadores técnicos en inglés `camelCase`; jamás jerga técnica moderna ni identificadores crudos impresos.
* **RNF-02 (Dogma Vanilla):**  
  El panel se implementa íntegramente con tecnologías web estándar nativas, sin dependencias externas (Artículo I); sin JavaScript el panel es inoperante como el resto del portal y no introduce requisitos de degradación adicionales.
* **RNF-03 (Accesibilidad WCAG 2.1 AA):**  
  Contraste ≥ 4.5:1, panel plenamente operable por teclado, foco visible y devuelto a su origen tras cerrar cualquier diálogo, y anuncios por región viva de convalecencia, veredictos y cambios de estado **con moderación** (la cuenta atrás anuncia hitos, no cada segundo).
* **RNF-04 (Privacidad del Dato Personal):**  
  El panel es la cámara más íntima del santuario: jamás expone datos de terceros más allá de lo ya público, jamás indexa adeptos, y la imagen propia del avatar es dato personal del adepto sujeto a la retención de la renuncia (SPEC-03 RF-09: purgada con la cuenta).
* **RNF-05 (Trazabilidad):**  
  Todo acto de gobierno personal (cambio de avatar, cambio de frase de paso) queda inscrito en la Bitácora inmutable; el panel no crea excepciones a la trazabilidad ratificada.
* **RNF-06 (Rendimiento y Coherencia de Retención):**  
  El panel se contempla en una sola carga, con latencia comparable a cualquier control de sesión existente; las operaciones permitidas al peregrino respetan la retención de sustancia de SPEC-09 (RF-05.1) y ninguna vista del panel libera al peregrino de la ceremonia.
* **RNF-07 (Sistema de Diseño):**  
  El panel viste los tokens del sistema (SPEC-02): pergamino, obsidiana y oro arcano; heráldica por sello rúnico determinista; sin literales de color ni tipografías fuera del canon.

---

## 6. Casos Límite y Situaciones Excepcionales

1. **Peregrino sin linaje en el panel:** las credenciales operan con normalidad; las secciones de linaje, clan y obras declaran el estado pendiente del juramento y conducen a la ceremonia; ninguna operación sujeta a retención se ofrece (SPEC-09 RF-01.4, RF-05.1).
2. **Sesión caducada a mitad del cambio de frase:** aviso solemne controlado, reautenticación por el umbral y panel intacto al retorno; sin mutación parcial (RF-01.4).
3. **Tres causas de fallo en la frase de paso:** frase actual errónea, nuevas que no coinciden o solidez insuficiente responden con un único aviso noble sin pistas del motivo concreto (RF-04.1).
4. **Avatar propio con formato o peso no válidos:** rechazo solemne nombrando el motivo; el avatar vigente no se muta y el catálogo permanece operativo (RF-03.2).
5. **Avatar propio retirado sin sustituto:** la identidad viste el avatar canónico por defecto; jamás un hueco vacío (RF-03.5).
6. **Linaje ajeno al catálogo local (cuenta legada):** degradación con la leyenda neutra canónica, sin heráldica inventada (RF-02.3).
7. **Admin Supremo sin linaje:** el panel declara su estado fundacional, sin secciones de linaje fantasma ni retención (RF-01.5).
8. **La cuenta atrás de convalecencia llega a cero con el panel abierto:** alzamiento anunciado sin recarga, con el resto del panel intacto (RF-05.2).
9. **Asientos de la bitácora que conciernen al adepto pero fueran firmados por terceros:** se narran los actos conforme a lo ya público, sin exponer datos personales de terceros (RF-06.4).
10. **Clan del adepto en estado archivado o disuelto:** la vitrina lo declara con la solemnidad canónica del estado (bronce, anillo roto — SPEC-07), jamás como clan vivo fantasma.
11. **Pérdida de conexión durante la carga de los datos vitrinales:** el panel conserva el último estado conocido y ofrece reintento solemne; jamás muestra ceros ni errores crudos.
12. **Reenvío legítimo del cambio de frase (doble clic o reintento tras consumarse):** el backend reconoce el reenvío del dueño (frase presentada como actual ya vigente y coincidente con la doble entrada) y responde con el recibo idempotente del acto ya consumado; el aviso ciego queda reservado a quien ignora la frase vigente (RF-04.1).
13. **Peregrino en convalecencia simultánea (sin linaje jurado + penitencia de clan activa):** la vitrina declara AMBOS estados por separado y sin mezclarlos — «Peregrino sin Linaje» pendiente del juramento y penitencia de clan con su cuenta atrás — conforme a SPEC-09 RF-04.4 (la convalecencia solo ata a clanes, jamás al juramento).
14. **Fallo de carga del catálogo de avatares:** aviso solemne controlado con reintento (hermano del «El canon no responde» de SPEC-09), sin mutar el avatar vigente ni dejar la identidad sin efigie.
15. **Avatar propio cuyo fichero resulta inaccesible o corrupto en el almacenamiento:** el panel y la cabecera degradan al avatar canónico por defecto con leyenda discreta de indisponibilidad; la identidad jamás queda sin efigie ni la cámara rota.
16. **Subida de avatar interrumpida a mitad (corte de conexión):** el avatar vigente no se muta (la aceptación es atómica: o la imagen completa validada entra, o nada cambia) y el panel ofrece reintento solemne.
17. **Nueva frase idéntica a la vigente:** rechazo con aviso noble específico, sin asiento ni mutación (RF-04.1).
18. **Re-subida de la imagen idéntica a la vigente:** rechazo con aviso noble específico, sin asiento (RF-03.6).
19. **Pestañas múltiples tras un cambio de avatar o de frase:** las demás pestañas del mismo adepto reflejan el nuevo estado en su siguiente interacción o recarga (sin sincronismo en vivo entre pestañas); ninguna pestaña conserva capacidad de actuación con estado caducado que el backend no refrende.

---

## 7. Fuera de Alcance (Exclusiones Explícitas)

1. **Fichas públicas de adeptos, buscador de personas y mensajería:** el panel es estrictamente privado (decisión ratificada); el descubrimiento entre adeptos sigue viviendo en el catálogo de clanes (SPEC-07).
2. **Cambio de alias, correo o baja de cuenta:** la renuncia y el ciclo de vida de cuentas tienen cámara canónica en SPEC-03 (RF-09); el panel, a lo sumo, enlaza.
3. **Cambio o revocación del linaje jurado:** perpetuo e irrevocable (SPEC-09 RF-03.4); ninguna sección del panel lo ofrece jamás.
4. **Gestión de clanes** (adhesión, postulación, fundación, retiro, veredictos): Vestíbulo de SPEC-10 y cámaras de SPEC-07; el panel solo muestra estado y conduce.
5. **Proyección del avatar propio ante terceros** (fichas de clan, crónicas de Dominio, firmas): fuera de alcance en esta spec; el avatar propio vive solo en el panel y en la cabecera del propio adepto. *(Duda abierta 1.)*
6. **Retirada disciplinaria de avatares propios:** NO EXISTE. Los avatares propios jamás proyectan ante terceros (exclusión 5), de modo que no hay superficie pública ni daño a terceros que moderar; toda gestión del avatar es voluntaria y pertenece al propio adepto. El Admin Supremo no retira avatares ajenos: su potestad disciplinaria queda confinada a las superficies ya ratificadas (contenidos del catálogo: obras), y esta spec no crea potestad ni acto de auditoría alguno para intervenir la imagen privada de nadie (principio rector 4).
7. **Edición de obras y hechizos desde el panel:** el Taller y el Tomo son las cámaras canónicas; el panel enlaza, no gestiona.
8. **Notificaciones push, correo o resúmenes periódicos:** la consulta del panel es activa (SPEC-10 RF-05, exclusión 5, mismo espíritu).
9. **Métricas nuevas más allá de los datos existentes** (rachas, logros, días desde el juramento como indicador gamificado): decisión ratificada — el panel es vitrina de datos ya sembrados, no nueva aritmética.
10. **Gestión de sesiones y dispositivos** más allá de las disoluciones ya ratificadas en el distintivo (individual y global) y de la disolución inseparable de las demás sesiones que el cambio de frase de paso ejecuta (RF-04.2): el panel enlaza las disoluciones voluntarias del distintivo y no duplica superficie alguna de gestión; la única excepción ejecutada es efecto del propio acto de custodia, no una gestión independiente.

---

## 7b. Limitaciones Conocidas del Entorno de Despliegue

1. **Almacenamiento de avatares propios en el plan gratuito (InfinityFree):** el despliegue gratuito del santuario impone cuotas de espacio y de número de ficheros; los avatares propios multiplican ficheros por adepto y quedan sujetos a esos techos operativos. La spec declara la limitación como restricción de despliegue conocida; las cifras de cuota, retención y limpieza de avatares huérfanos las fija el plan técnico en coherencia con la guía de despliegue (post-despliegue y limitaciones del plan gratuito).

---

## 8. Criterios de Finalización y Aceptación

* [ ] Todo vinculado autenticado accede a su panel desde la identidad de cabecera y contempla únicamente su propia información.
* [ ] El visitante anónimo queda retenido en el umbral con la intención conservada (interceptación canónica).
* [ ] El peregrino opera sus credenciales en el panel y las secciones sujetas al juramento le conducen a la ceremonia, sin liberar retención.
* [ ] La vitrina muestra avatar, alias, correo, oficio en castellano, linaje con heráldica canónica, clan con blasón, estampa del juramento y estado del vínculo, sin identificadores crudos impresos.
* [ ] El catálogo de avatares del santuario viste la identidad del LINAJADO con efecto inmediato y sin moderación; el peregrino no recibe escritura de identidad alguna y su sección conduce a la ceremonia.
* [ ] El fallo de carga del catálogo de avatares responde con aviso solemne y reintento, sin mutar el avatar vigente.
* [ ] La subida de avatar propio valida formato y dimensiones, encaja en el marco ceremonial y rechaza con aviso solemne sin mutar el vigente.
* [ ] El avatar propio vive solo en el panel y la cabecera del propio adepto; retirado, vuelve el avatar canónico por defecto.
* [ ] El cambio de frase de paso exige frase actual y doble confirmación, responde con aviso único sin pistas ante fallo real, disuelve las demás sesiones conservando la actual, rechaza con aviso específico la frase idéntica a la vigente y reconoce el reenvío legítimo con recibo idempotente.
* [ ] El panel muestra la convalecencia con cuenta atrás viva, causa noble y retenciones; el alzamiento se anuncia sin recarga; sin convalecencia, silencio.
* [ ] La bitácora personal expone los asientos cuyo sujeto o destinatario afectado es el propio adepto como lente de lectura, sin actos colectivos del clan sin él como sujeto, jamás escribiendo asientos propios, con leyenda de silencio si no los hay.
* [ ] Los contadores del tomo (SPEC-11) y las firmas del Maestro (SPEC-08) se exponen desde datos existentes, con conducción a sus cámaras canónicas.
* [ ] Ninguna sección del panel ofrece cambio de linaje, cambio de alias/correo, baja, gestión de clanes ni edición de obras: todo conduce a su cámara.
* [ ] Todo cambio de avatar con efecto real y de frase queda inscrito en la Bitácora de Auditoría conforme al catálogo cerrado; los actos inocuos (idénticos) no se inscriben.
* [ ] El panel cumple WCAG 2.1 AA (teclado completo, foco visible y devuelto, región viva con anuncios moderados) y viste el sistema de diseño sin literales.
* [ ] Cero dependencias externas y acatamiento del Dogma Vanilla y del Velo Arcano.

---

## 9. Registro de Decisiones (dudas abiertas resueltas)

* **[RESUELTA] Proyección del avatar propio ante terceros:** el avatar propio vive SOLO en el panel y en la cabecera del propio adepto (privacidad estricta, exclusión 5); toda proyección futura en superficies compartidas exigirá enmienda formal con reglas de contenido y vía de retirada. Consecuencia ratificada: al no existir superficie pública, la retirada disciplinaria de avatares NO EXISTE (exclusión 6) y no se crea potestad ni acto de auditoría alguno para intervenir la imagen privada de nadie (principio rector 4; coherencia con los Artículos III.3 y VI de la Constitución).
* **[RESUELTA] Destino de las demás sesiones tras el cambio de frase:** se disuelven TODAS las demás sesiones conservando la actual, con anuncio en el recibo (RF-04.2). Distinción deliberada y documentada con el flujo de recuperación de SPEC-03 (que revoca todas, incluida la actual, porque nace de la pérdida de acceso): el cambio consciente desde el panel conserva la sesión presente porque el dueño está autenticado.
* **[RESUELTA] La gloria semanal en la vitrina (RF-07.3):** SI el Dominio de SPEC-07 publicare cómputo vigente, el panel lo exhibe con su semana; SI no lo hubiere, la sección se muestra con la leyenda canónica sin cifras fantasma (sección viva pero silenciosa), y el silencio jamás simula ceros.
* **[RESUELTA] Cifras de la subida de avatar propio:** la spec fija la existencia y semántica de los límites (formato, peso, lados máximos y marco ceremonial cuadrado); las cifras exactas las propone el plan técnico para ratificación del Arquitecto, junto con las cuotas del entorno de despliegue (§7b).
* **[RESUELTA] Naturaleza del catálogo de avatares:** reutilización del arte canónico ya existente (efigies y heráldicas por sello rúnico determinista), sin encargo de arte nuevo; si el Arquitecto dispusiere efigies adicionales, su alta seguirá siendo acto fundacional ajeno al panel.
* **[RESUELTA] Asiento de bitácora para el cambio de avatar (RF-03.6):** el plan técnico verificará si algún acto del catálogo cerrado de SPEC-03 (RF-08.1) ampara el cambio consciente de avatar; SI ningún acto lo amparare, ENTONCES se ampliará el catálogo cerrado con el acto mínimo necesario (alta/retiro de efigie propia), pues RNF-05 exige la trazabilidad del acto y la alternativa de renunciar a inscribirlo rompería la inmutabilidad ratificada. La verificación y la ampliación mínima quedan comprometidas en el plan, no aquí.
