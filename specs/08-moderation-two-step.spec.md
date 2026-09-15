# SPEC-08: Sistema de Moderación Solemne en Dos Pasos y Consecución de Firmas

> **Constitución:** [`constitution.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/constitution.md) | **Directrices:** [`AGENTS.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/AGENTS.md)  
> **Estado:** Especificación Ratificada y Blindada tras Auditoría de Calidad (QA)  
> **Área:** Calidad Litúrgica, Integridad Canónica y Gobierno del Santuario  
> **Restricción:** Definición estricta del QUÉ y el POR QUÉ. Cero detalles de implementación técnica, arquitectura o nombres de archivos.

---

## 1. Contexto y Objetivo

En los grandes cónclaves arcanos inmortalizados en la literatura fantástica (*Frieren*, la Torre de los Magos de *Tolkien*, los colegios de magia de *D&D*), el descubrimiento o invención de un nuevo encantamiento jamás se inscribe a la ligera en los anales del saber. Todo nuevo conjuro nace como una fórmula inestable y potencialmente herética; solo tras ser sometido al escrutinio minucioso de magos consagrados puede considerarse una verdad canónica apta para ser transmitida a las futuras generaciones.

El objetivo de esta especificación es instituir el **Sistema de Moderación Solemne en Dos Pasos y Consecución de Firmas**: un marco de gobernanza colegiada y meritocrática donde ningún conjuro pueda ingresar al Gran Tomo ni otorgar gloria a su clan sin superar previamente un riguroso cónclave deliberativo. Se estructura en dos fases indisolubles: el **Paso 1 (Envío a Pruebas y Exposición Comunitaria)**, donde la obra se abre a la experimentación práctica en el simulador sin alterar el cómputo de dominio; y el **Paso 2 (Colegiatura de Tres Firmas de Maestros)**, donde tres evaluadores independientes de hermandades distintas deben otorgar su bendición solemne bajo el estricto mandato ético del **Artículo III de la Constitución** (Veto de Clanes y Conflicto de Intereses), tutelados por la potestad suprema y auditable del Administrador Supremo (`supremeAdmin`) en armonía con la Ley Universal del Maná (**Artículo II**).

---

## 2. Actores y Usuarios

* **El Autor Aspirante (Editor / Mago Creador):**  
  Concibe el conjuro en su libreta de borradores privados (`draft`), lo somete a la Torre de Moderación cuando considera que está equilibrado, sigue su progreso en la cola de revisión, atiende las objeciones en caso de ser rechazado y puede reabrirlo a borrador para subsanarlo.
* **El Maestro de la Torre (Revisor / Moderador Colegiado):**  
  Mago de rango superior (`master`) encargado de examinar la coherencia temática, el lore y la dignidad de las obras postuladas. Puede estampar su Firma de Consagración con glosa litúrgica, retractarse antes del cierre o emitir un Dictamen de Objeción fundamentado, siempre sujeto a la prohibición constitucional de juzgar conjuros de su clan actual, de los últimos 30 días o de su propia autoría.
* **El Administrador Supremo (`supremeAdmin`):**  
  Máxima autoridad del santuario y custodio del equilibrio universal. Posee la facultad de validar de oficio mediante Firma Soberana Instantánea sobre obras en revisión, rescatar conjuros rechazados o desterrar obras corruptas, quedando cada uno de sus decretos inmutablemente grabado en la Bitácora de Auditoría pública y sujeto a la prohibición de auto-aprobarse o beneficiar unilateralmente a su propia hermandad.
* **El Lector o Adepto Visitante (`reader` / Comunidad general):**  
  Recorre el Atrio de Pruebas Experimentales, somete a prueba los conjuros en deliberación dentro del simulador para apreciar sus efectos visuales y orales, pero sin que sus interacciones alteren la contienda de puntos de dominio de los clanes.

---

## 3. Historias de Usuario

* **HU-01 (Postulación a Pruebas y Deliberación):**  
  *Como* editor arcano que ha balanceado una nueva creación,  
  *Quiero* enviar mi conjuro a la Torre de Moderación para que pase a estado experimental y entre en la sala de deliberación,  
  *Para* que los Maestros examinen mi obra y los lectores puedan probarla en la Cámara de Conjuración.

* **HU-02 (Evaluación Colegiada y Firma Solemne):**  
  *Como* Maestro de la Torre sin conflicto de intereses con el clan del autor ni autoría propia,  
  *Quiero* revisar las propiedades, descripción lírica y efectos de un conjuro experimental para estampar mi firma independiente con una glosa ceremonial de aprobación,  
  *Para* sumar mi aval al proceso que consagrará la obra en el Gran Tomo Canónico.

* **HU-03 (Objeción Fundamentada y Calidad Litúrgica):**  
  *Como* Maestro celoso de la dignidad del grimorio,  
  *Quiero* emitir un Dictamen de Objeción razonado en castellano cuando un conjuro atente contra el equilibrio o el lore del santuario,  
  *Para* devolverlo a la libreta del autor con indicaciones claras de corrección e impedir la proliferación de obras corruptas.

* **HU-04 (Consagración Definitiva del Conjuro):**  
  *Como* autor o líder de clan,  
  *Quiero* que al alcanzarse la tercera firma válida de Maestros independientes mi conjuro sea consagrado de inmediato en el Gran Tomo y se acrediten los Puntos de Dominio Arcano al clan bajo cuyo blasón fue concebido,  
  *Para* ver inmortalizado nuestro esfuerzo colectivo en el patrimonio del grimorio.

* **HU-05 (Intervención Soberana y Desempate Constitucional):**  
  *Como* Administrador Supremo,  
  *Quiero* poder elevar de inmediato a estado validado una obra experimental excepcional o revocar un conjuro fraudulento mediante decreto solemne en la bitácora pública (sin favorecer a mi propio clan),  
  *Para* velar por la justicia superior y resolver situaciones de estancamiento o controversia extrema.

---

## 4. Requisitos Funcionales (Notación EARS en Español)

### RF-01: El Ciclo de Estados del Conjuro y Flujo de Dos Pasos
* **RF-01.1 [Ubicuo]:**  
  El sistema DEBERÁ gobernar la vida de todo conjuro a través de los siguientes estados canónicos mutuamente excluyentes:
  * **Borrador Privado (`draft`):** Obra en gestación en la libreta del autor, editable libremente e invisible para el resto del santuario.
  * **Paso 1 — En Deliberación / Experimental (`experimental`):** Obra con balance matemático y huella criptográfica (`math_fingerprint`) sellados, sometida a la Torre de Moderación y expuesta públicamente en el Atrio de Pruebas.
  * **Paso 2 — Consagrado y Sellado (`validated`):** Obra ratificada colegiadamente por 3 firmas independientes o decreto soberano, incorporada de forma inmutable al Gran Tomo Canónico y acreedora de puntos de dominio.
  * **Rechazado con Observaciones (`rejected`):** Obra vetada por objeción fundamentada de un Maestro, retirada de inmediato de la vista pública y devuelta a la libreta del autor con registro inmutable del dictamen.
  * **Archivado / Desterrado (`archived`):** Obra retirada del canon por intervención administrativa o disolución de hermandad («Herencia Ancestral»).
* **RF-01.2 [Dirigido por Eventos y Cuota Anti-Spam]:**  
  CUANDO un usuario consagrado (`editor` o superior) solicite enviar su conjuro a moderación desde `draft`, el sistema DEBERÁ verificar que el usuario **no supere el cupo máximo de tres (3) conjuros en estado `experimental` simultáneamente**; SI no supera dicho límite, transicionará el conjuro a `experimental` iniciando el contador de firmas en cero ($0/3$).
* **RF-01.3 [Estado e Inmutabilidad en Revisión]:**  
  MIENTRAS un conjuro permanezca en estado `experimental`:
  * El sistema DEBERÁ **bloquear terminantemente cualquier edición de sus parámetros arcanos** (nombre, círculo, afinidad, componentes, área, rango, fórmulas o descripción) para preservar la integridad de lo evaluado.
  * SI el autor desea realizar modificaciones, DEBERÁ retirar formalmente la obra de la revisión, lo cual **anulará irrevocablemente todas las firmas de Maestros acumuladas** y devolverá el conjuro al estado `draft`.
* **RF-01.4 [Dirigido por Eventos y Re-apertura]:**  
  CUANDO un conjuro se encuentre en estado `rejected`, el sistema DEBERÁ mantenerlo inmutable en la libreta del autor; CUANDO el autor accione la opción **«Reabrir como Borrador»** (`reopenAsDraft`), el sistema DEBERÁ transicionar el conjuro de `rejected` a `draft`, habilitando la edición completa de parámetros y conservando visibles las notas del dictamen anterior para su subsanación.
* **RF-01.5 [Dirigido por Eventos y Liberación de Cupo]:**  
  En el instante en que un conjuro transicione al estado `rejected` o `validated`, el sistema DEBERÁ **liberar de forma inmediata el cupo de revisión concurrente del autor**, permitiéndole enviar una nueva creación a la Torre sin bloqueos artificiales.
* **RF-01.6 [Temporalidad y Caducidad por Abandono]:**  
  SI un conjuro en estado `experimental` acumula **noventa (90) días naturales consecutivos sin recibir ninguna firma ni interacción de los Maestros**, ENTONCES el sistema DEBERÁ transicionarlo automáticamente al estado `rejected` bajo el motivo ceremonial de *«Letargo Arcano por Falta de Resonancia Colegiada»*, liberando la cola de moderación y permitiendo al autor reabrirlo a `draft`.

---

### RF-02: Mecánica de Firmas, Criterio de Objeción y Consagración
* **RF-02.1 [Ubicuo y Pluralidad]:**  
  Para que un conjuro alcance de forma ordinaria el estado `validated`, el sistema DEBERÁ exigir **tres (3) Firmas de Consagración independientes emitidas por tres Maestros distintos (`master`)**, bajo la regla unívoca de pluralidad: **«No puede haber dos firmantes que pertenezcan a una misma hermandad»**. Se admite la concurrencia de dos o tres Maestros ermitaños sin clan, siempre que sean cuentas de usuario diferentes y sin conflicto ético.
* **RF-02.2 [Dirigido por Eventos y Glosa Litúrgica]:**  
  CUANDO un Maestro de la Torre examine un conjuro experimental y decida avalarlo, el sistema DEBERÁ permitirle estampar su **Firma de Consagración**, con opción de adjuntar una glosa ceremonial de aprobación en noble castellano acotada a un **máximo de doscientos cincuenta (250) caracteres**.
* **RF-02.3 [Dirigido por Eventos y Consagración Atómica]:**  
  CUANDO un conjuro en estado `experimental` acumule su tercera ($3ª$) firma válida procesada dentro de una transacción atómica segura, el sistema DEBERÁ **transicionar de forma automática e inmediata el conjuro al estado `validated`**, acreditar los Puntos de Dominio Arcano (PDA) al clan de origen según SPEC-07 e inscribir la consagración en el Libro de Oro del santuario.
* **RF-02.4 [Dirigido por Eventos y Retractación]:**  
  CUANDO un Maestro decida retractarse de una firma estampada sobre un conjuro que aún posea menos de 3 firmas ($< 3$), el sistema DEBERÁ retirar su firma, reducir el contador en uno ($N-1$) y registrar la retractación en la bitácora; SI el conjuro ya alcanzó el estado `validated`, la consagración será irrevocable para los Maestros. La resolución concurrente entre una retractación y una 3ª firma se dirimirá atómicamente en servidor mediante bloqueo transaccional.
* **RF-02.5 [Dirigido por Eventos y Veto de Calidad]:**  
  CUANDO un Maestro detecte que un conjuro incumple los principios del grimorio, atenta contra la coherencia temática o evidencia trampas en su balance matemático, el sistema DEBERÁ facultarlo para emitir un **Dictamen de Objeción Fundamentada (Veto de Calidad)**; para ser admisible, el Maestro **deberá redactar obligatoriamente una justificación solemne en castellano de al menos veinte (20) caracteres**.
* **RF-02.6 [Dirigido por Eventos y Retorno al Autor]:**  
  En el instante en que se emita un Dictamen de Objeción válido, el sistema DEBERÁ **transicionar de inmediato el conjuro al estado `rejected`**, registrar el dictamen en el historial, retirarlo del Atrio de Pruebas y retornarlo a la libreta privada del autor, cancelando cualquier otra firma previa que hubiese alcanzado.

---

### RF-03: Incompatibilidad Ética Constitucional y Veto de Clanes (Artículo III)
* **RF-03.1 [Ubicuo y Veto de Hermandad]:**  
  En cumplimiento estricto del **Artículo III de la Constitución**, el sistema DEBERÁ **inhabilitar terminantemente a todo Maestro para emitir firmas de consagración o dictámenes de objeción sobre conjuros forjados por adeptos de su propio clan**, así como de aquellos clanes a los que el Maestro haya pertenecido en los **últimos treinta (30) días naturales**.
* **RF-03.2 [Ubicuo y Bloqueo de Auto-Firma]:**  
  El sistema DEBERÁ **bloquear terminantemente que cualquier usuario firme, avale, objete o delibere sobre un conjuro de su propia autoría**, independientemente de que ostente el rango de `master` o `supremeAdmin`.
* **RF-03.3 [No Deseado / Excepción]:**  
  SI un Maestro intenta deliberar sobre una obra sujeta a veto ético de clan o personal, el sistema DEBERÁ rechazar la acción arrojando un solemne bloqueo ceremonial: *«Conflicto de intereses: No es lícito a un Maestro juzgar las obras nacidas bajo su propio estandarte, linajes recientes o propia pluma»*.
* **RF-03.4 [Dirigido por Eventos y Conflicto Sobrevenido]:**  
  SI un Maestro estampó una firma válida y, con posterioridad, sobreviene un conflicto de intereses antes de alcanzarse la 3ª firma (por ejemplo, el autor se afilia al clan del Maestro o el Maestro al clan del autor), el sistema DEBERÁ **anular automáticamente y de oficio dicha firma por nulidad constitucional sobrevenida**, regresando el contador a $N-1$ firmas y notificando al autor.
* **RF-03.5 [Dirigido por Eventos y Pérdida de Rango en Tránsito]:**  
  SI un Maestro que estampó una firma es degradado de rango (`master` $\rightarrow$ `editor`/`reader`) o suspendido administrativamente antes de que el conjuro alcance la 3ª firma, el sistema DEBERÁ **revocar de oficio dicha firma**, reduciendo el contador en uno ($N-1$) y requiriendo un nuevo aval de un Maestro activo.
* **RF-03.6 [Ubicuo y Convalecencia Arcana]:**  
  Un Maestro que se encuentre en el Periodo de Convalecencia Arcana de 14 días (SPEC-07) **mantendrá su potestad judicial para deliberar y firmar conjuros** actuando como Maestro independiente ermitaño, permaneciendo en pleno vigor el veto de 30 días respecto a su hermandad previa (Artículo III).
* **RF-03.7 [Ubicuo y Disolución de Clan]:**  
  SI el clan del autor se disuelve (`archived`) durante la revisión, el conjuro mantendrá su vinculación histórica al clan disuelto; al consagrarse, se inscribirá perpetuamente como **«Herencia Ancestral»** del clan histórico sumando a sus puntos históricos (sin puntos semanales), y los antiguos miembros mantendrán su veto ético de 30 días desde la fecha de su partida.

---

### RF-04: Potestades Soberanas del Administrador Supremo (`supremeAdmin`)
* **RF-04.1 [Dirigido por Eventos y Firma Soberana]:**  
  El Administrador Supremo poseerá la potestad de emitir una **Firma Soberana Instantánea exclusivamente sobre conjuros en estado `experimental`** (cuyo balance de maná ya ha sido certificado deterministamente bajo el Artículo II), elevando la obra de forma unilateral e inmediata a `validated` sin requerir firmas colegiadas adicionales de Maestros. Los borradores privados en `draft` son inviolables y están excluidos de esta facultad.
* **RF-04.2 [Ubicuo y Veto Ético del Administrador Supremo]:**  
  SI el Administrador Supremo pertenece a una hermandad mágica activa, **el sistema DEBERÁ prohibirle terminantemente emitir Firmas Soberanas sobre conjuros forjados por miembros de su propio clan** (Artículo III.2); tales obras deberán someterse obligatoriamente al juicio imparcial de 3 Maestros independientes de clanes ajenos.
* **RF-04.3 [Dirigido por Eventos y Rescate de Obras]:**  
  El Administrador Supremo podrá intervenir de oficio sobre cualquier conjuro en estado `rejected`:
  * Podrá **rescatar la obra y restituirla al estado `experimental`**, en cuyo caso el conjuro reiniciará su deliberación con **cero firmas ($0/3$)** para una evaluación colegiada limpia e imparcial.
  * Podrá consagrarla directamente a `validated` mediante Firma Soberana razonada (siempre que el autor no pertenezca a su propio clan).
* **RF-04.4 [Dirigido por Eventos y Revocación Póstuma]:**  
  El Administrador Supremo poseerá la facultad de **degradar o archivar (`archived`) un conjuro previamente validado** si con posterioridad se identifican exploits mecánicos, abusos de fórmula o herejías temáticas graves; en caso de fraude manifiesto, el sistema deducirá retroactivamente los PDA que dicho conjuro hubiera otorgado a su clan.
* **RF-04.5 [Ubicuo y Edicto Imperial Obligatorio]:**  
  Toda intervención unilateral del Administrador Supremo (Firma Soberana, rescate o archivo forzoso) **exigirá obligatoriamente un Edicto Imperial de Justificación en castellano** ($\ge 20$ caracteres) y se inscribirá de forma automática e inmutable en la Bitácora de Auditoría pública del santuario.

---

### RF-05: El Atrio de Pruebas y Aislamiento de Puntos de Dominio
* **RF-05.1 [Ubicuo]:**  
  El sistema DEBERÁ exponer un espacio público denominado **«Atrio de los Arcanos Experimentales»**, diferenciado del Gran Tomo Canónico, donde se exhiban los conjuros en deliberación con una leyenda ceremonial de advertencia (*«En Deliberación Arcana — Obra en Fase de Prueba»*) y el indicador visual de las firmas obtenidas ($0/3$, $1/3$, $2/3$). Los conjuros en estado `rejected` o `draft` jamás aparecerán en este atrio.
* **RF-05.2 [Ubicuo]:**  
  El sistema DEBERÁ permitir que **cualquier usuario consagrado o visitante anónimo invoque conjuros experimentales en la Cámara de Conjuración (Simulador)**, proyectando sus partículas visuales en Canvas y recitando su fórmula mediante síntesis de voz Web Speech para verificar su comportamiento práctico.
* **RF-05.3 [Ubicuo y Aislamiento de PDA]:**  
  MIENTRAS un conjuro permanezca en estado `experimental`:
  * El sistema DEBERÁ **bloquear la generación de Puntos de Dominio Arcano (PDA)** para el clan del autor ante la ejecución de combos en el simulador o al ser añadido a favoritos por la comunidad;
  * Los méritos de dominio se reservarán estrictamente para el instante en que el conjuro alcance el estado `validated`.
* **RF-05.4 [Ubicuo]:**  
  El sistema DEBERÁ proporcionar a los Maestros y Administradores la vista de la **«Torre de Deliberación»**, que exhibirá la cola de espera de conjuros experimentales ordenada por antigüedad, permitiendo filtrar por elemento y escuela mágica, y alertando visualmente de las obras incompatibles por conflicto de clanes.

---

### RF-06: Memoria Histórica y Trazabilidad en la Bitácora de Auditoría
* **RF-06.1 [Ubicuo]:**  
  En cumplimiento del **Artículo III y IV de la Constitución**, el sistema DEBERÁ inscribir en la **Bitácora de Auditoría pública** cada una de las siguientes acciones de moderación:
  * Envío de conjuro a moderación experimental.
  * Retiro voluntario a borrador por el autor.
  * Firma de Consagración de Maestro (con identificador del evaluador, clan al que pertenece y glosa litúrgica).
  * Retractación de firma de Maestro con motivo indicado.
  * Revocación de firma por conflicto ético sobrevenido o degradación de rango.
  * Dictamen de Objeción Fundamentada (con texto íntegro del motivo de rechazo).
  * Re-apertura de conjuro rechazado como borrador por el autor.
  * Decretos del Administrador Supremo (Firma Soberana, rescate o archivo forzoso con su Edicto Imperial).
  * Consagración final automática por 3ª firma.
  * Caducidad automática tras 90 días de inactividad en cola.
* **RF-06.2 [Ubicuo]:**  
  El autor de un conjuro rechazado DEBERÁ tener acceso permanente dentro de su libreta privada a la lectura íntegra del motivo de rechazo formulado por el Maestro, permitiéndole entender las objeciones y subsanarlas tras reabrir la obra.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Transparencia e Inmutabilidad de la Memoria):**  
  Todos los veredictos, firmas, glosas y objeciones son eternos; queda prohibida la eliminación o purga de los registros de moderación de la base de datos (Artículos III y IV de la Constitución).
* **RNF-02 (Concurrencia Segura y Atomicidad de Consagración):**  
  La consecución de la 3ª firma y la retractación deben procesarse dentro de transacciones atómicas con bloqueo pesimista en servidor, garantizando que dos eventos concurrentes no generen dobles consagraciones, votos fantasmas ni duplicación de puntos de dominio (PDA).
* **RNF-03 (El Velo Arcano y la Dignidad del Lenguaje):**  
  Todos los estados (`experimental`, `validated`, `rejected`), avisos de advertencia, dictámenes y botones ceremoniales deberán formularse con solemnidad literaria en noble castellano (Artículos IV y V de la Constitución).
* **RNF-04 (Protección Contra la Fatiga del Cónclave):**  
  El sistema limitará de forma infranqueable a un máximo de tres (3) conjuros en revisión activa por autor, previniendo el colapso de la cola de moderación.
* **RNF-05 (Dogma Vanilla y Dualismo Lingüístico):**  
  El módulo de moderación se implementará sin frameworks ni librerías externas; los esquemas, tablas y endpoints se nombrarán en inglés `camelCase`/`snake_case` (`spellId`, `masterId`, `signaturesCount`, `reviewStatus`, `objectionReason`), preservando la interfaz en noble castellano (Artículos I y V de la Constitución).

---

## 6. Casos Límite y Situaciones Excepcionales

1. **Retiro Voluntario de Conjuro con Firmas Previas:**  
   Si un autor retira a `draft` un conjuro que ya contaba con 1 o 2 firmas para retocar una descripción o parámetro, todas las firmas previas se anulan; al reenviarlo a `experimental`, comenzará de nuevo con cero firmas ($0/3$), pues los evaluadores deben juzgar la nueva versión completa.
2. **Conflicto de Intereses Sobrevenido por Traspaso de Clan:**  
   Si el Maestro M firmó un conjuro del autor A (ambos en clanes compatibles), pero antes de la 3ª firma el autor A se une al clan de M, el sistema anula automáticamente la firma de M de pleno derecho, bajando el contador y registrando el motivo en auditoría.
3. **Pluralidad de Clanes y Maestros Ermitaños:**  
   No puede haber dos Maestros del mismo clan firmando un mismo conjuro. No obstante, dos o tres Maestros ermitaños (sin clan) sí pueden firmar la misma obra, ya que la condición de ermitaño denota neutralidad y no genera conflicto de hermandad.
4. **Pérdida de Rango de Maestro en Tránsito:**  
   Si un Maestro que firmó un conjuro es degradado o sancionado antes de la 3ª firma, su firma se revoca automáticamente ($N-1$), exigiendo que un Maestro activo complete la terna.
5. **Caducidad por Letargo (90 Días):**  
   Si un conjuro experimental no recibe firmas en 90 días, pasa automáticamente a `rejected`, liberando el cupo del autor. El autor puede reabrirlo a `draft` cuando lo desee para reactivar su tramitación.
6. **Intento de Envío Excediendo la Cuota de 3 Obras:**  
   Si un autor con 3 conjuros en estado `experimental` intenta enviar un cuarto conjuro a moderación, el sistema bloqueará la acción con el mensaje ceremonial: *«La Torre de Moderación ya custodia tres de tus obras en deliberación. Aguarda su resolución antes de elevar nuevas plegarias»*.
7. **Colisión Atómica de Retractación y Consagración:**  
   Si la retractación de un Maestro llega al servidor en la misma milésima de segundo que la 3ª firma, la transacción con bloqueo garantiza que el contador descienda a 1 antes de evaluar la firma entrante (quedando en 2/3), impidiendo la consagración inválida.

---

## 7. Fuera de Alcance (Exclusiones Explícitas)

1. **Colas Prioritarias y Moderación Acelerada Pagada:**  
   No existen mecanismos de pago con maná, oro o monedas para adelantar conjuros en la cola de revisión; la moderación se rige puramente por antigüedad y mérito.
2. **Aprobación Automática mediante Inteligencia Artificial:**  
   La consagración de obras es una potestad humana soberana del Cónclave de Maestros; ningún algoritmo automatizado reemplaza la deliberación ética y litúrgica de los magos.
3. **Votaciones Populares de Lectores Sustitutivas de Firmas:**  
   El sistema de favoritos y elogios comunitarios reconoce el aprecio popular, pero **jamás convalida firmas de consagración ni transforma un conjuro experimental en canónico**.
4. **Eliminación o Purga de Dictámenes de Moderación:**  
   Queda terminantemente excluida cualquier funcionalidad para suprimir o alterar votos pasados en la base de datos; la memoria histórica es inmutable.
5. **Inspección de Borradores Privados (`draft`):**  
   Los borradores privados son inaccesibles para los Maestros y el Administrador Supremo; ningún rol puede forzar la moderación o consagración de obras que no hayan sido publicadas formalmente por su autor.

---

## 8. Criterios de Finalización y Aceptación

* [ ] Todo conjuro postulado a moderación transiciona de `draft` a `experimental` (con balance y huella sellados), requiriendo tres (3) firmas de Maestros independientes o decreto de Administrador Supremo para alcanzar `validated`.
* [ ] Las tres firmas de consagración provienen obligatoriamente de Maestros de clanes distintos entre sí, admitiéndose múltiples Maestros ermitaños neutrales.
* [ ] Se aplica el veto constitucional del Artículo III: ningún Maestro puede evaluar conjuros de su clan actual o de los últimos 30 días naturales.
* [ ] Se prohíbe de forma absoluta la auto-firma o auto-aprobación de conjuros propios para todos los roles (`master` y `supremeAdmin`).
* [ ] El Administrador Supremo solo puede emitir Firmas Soberanas sobre obras en `experimental` y tiene prohibido validar unilateralmente conjuros de su propio clan (Artículo III.2).
* [ ] La emisión de un Dictamen de Objeción por un solo Maestro exige justificación en castellano ($\ge 20$ caracteres) y pasa el conjuro de inmediato a `rejected`, retirándolo del Atrio de Pruebas.
* [ ] El autor puede reabrir formalmente un conjuro rechazado mediante `reopenAsDraft`, transicionándolo a `draft` con la nota del dictamen visible para su corrección.
* [ ] El estado `rejected` libera de forma inmediata uno de los 3 cupos concurrentes de revisión del autor.
* [ ] Un Maestro puede retractarse voluntariamente de su firma mientras el conjuro tenga menos de 3 firmas.
* [ ] Si un Maestro es degradado o sobreviene un conflicto ético de clan antes de la 3ª firma, su firma se anula automáticamente de oficio ($N-1$).
* [ ] Al alcanzarse la 3ª firma válida en transacción atómica, el conjuro pasa automáticamente a `validated`, acredita los PDA a su clan originario y se sella en el Gran Tomo.
* [ ] Un conjuro en `experimental` sin firmas durante 90 días caduca automáticamente pasando a `rejected` por letargo colegiado.
* [ ] Los conjuros en estado `experimental` no pueden ser editados por el autor sin retirarlos previamente a `draft` (lo cual reinicia las firmas a cero).
* [ ] Los conjuros experimentales se exhiben en el «Atrio de Pruebas», pueden invocarse libremente en el simulador, pero no generan PDA en la contienda de clanes.
* [ ] Si el clan originario se disuelve durante la revisión, al validarse el conjuro se inscribe como «Herencia Ancestral» sumando únicamente a puntos históricos.
* [ ] Toda firma, glosa (máx. 250 caracteres), objeción, edicto supremo y consagración se inscribe de forma inmutable en la Bitácora de Auditoría pública.
* [ ] Cero dependencias externas y cumplimiento riguroso del Dogma Vanilla, el Velo Arcano y el Dualismo Lingüístico.

---

## 9. Dudas Abiertas

* *No existen dudas abiertas [CONSENSO PLENO Y BLINDAJE CANÓNICO RATIFICADO]. Todos los hallazgos de QA, colisiones constitucionales y casos límite fueron resueltos e incorporados a la especificación.*
