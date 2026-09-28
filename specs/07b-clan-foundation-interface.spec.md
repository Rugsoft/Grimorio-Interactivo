# SPEC-07b: El Umbral de la Fundación — Interfaz de Creación de Clanes

> **Constitución:** [`constitution.md`](../constitution.md) | **Directrices:** [`AGENTS.md`](../AGENTS.md)
> **Espec madre:** [`specs/07-clans-lineages.spec.md`](07-clans-lineages.spec.md) (RF-01.2, RF-02) · [`specs/10-clan-adhesion-ceremony.spec.md`](10-clan-adhesion-ceremony.spec.md) (estado vacío del Vestíbulo)
> **Estado:** RATIFICADA (2026-09-28) — las tres decisiones de la sección 10 quedan cerradas con la opción recomendada
> **Área:** Superficie de interfaz del Santuario (Dogma Vanilla, Artículo I)
> **Restricción:** Norma el QUÉ observable de la interfaz. El contrato de backend ya ratificado en SPEC-07 no se altera: esta spec lo VESTIGE, jamás lo amplía.

---

## 1. Contexto y Objetivo

La auditoría de costuras entre especificaciones (2026-09-28) reveló que el rito de fundación de hermandades — REQURIDO por SPEC-07 RF-01.2 y HEREDABLE desde el estado vacío del Vestíbulo (SPEC-10, RF-02) — carece de superficie de interfaz:

- El **backend** está servido y verificado: `POST /api/v1/clans` con sus ocho vetos en cadena, transacción atómica y asiento `CLAN_FOUNDED` en la Bitácora.
- El **cliente HTTP** existe: `clanClient.foundClan(payload)` (Endpoint 1 del `clanClient`).
- La **leyenda fundacional** está escrita pero huérfana: `CLAN_VIEW_LEGENDS.founder` («Fundar una hermandad propia») no la renderiza ningún componente.
- El resultado: un adepto apto **no puede fundar un clan desde la web** — una promesa de HU-01 de SPEC-07 sin gesto que la consuma.

**Objetivo:** forjar el **Umbral de la Fundación** — un modal solemne en el Salón de los Linajes (`#/linajes`) que recoja los cuatro sellos canónicos (nombre, lema, blasón, linaje rector) y el régimen de admisión, consuma `clanClient.foundClan`, y traduzca cada veto del canon a una leyenda temática viva. Con él, el ciclo HU-01 queda completo de punta a punta.

---

## 2. Alcance y Exclusiones

**En alcance:**
- Modal de fundación (`<dialog>` nativo) montado sobre la vista del Salón de los Linajes (`clansPreviewView`) y accesible desde la invitación del estado vacío del Vestíbulo.
- Formulario de cuatro sellos + régimen de admisión, con validación local temprana (espejo del canon, no sustituto del backend).
- Traducción temática de los códigos de veto del contrato `ClanGovernanceException`.
- Gesto de entrada visible SOLO para quien cumple los prerequisitos observables (autenticado, sin clan, sin convalecencia).
- Arnés de pruebas `.mjs` con DOM simulado (patrón de los arneses hermanos de componentes).

**Fuera de alcance (prohibido bajo esta spec):**
- Cualquier cambio en `src/` (backend): el contrato de SPEC-07 es ley cerrada.
- Moderación, admisión de adeptos (SPEC-10 ya la norma), heráldica SVG (SPEC-02 RF-07 / SPEC-07 RF-02.4 ya la forjan), o PDA.
- Flujos de edición o disolución de clanes ya fundados (gobierno del Patriarca, ya existente en `clanManagementComponent`).

---

## 3. Actores

* **El Fundador Apto:** adepto autenticado (`editor` o superior), jurado (SPEC-09), sin membresía activa y fuera de Convalecencia Arcana. Único actor que ve habilitado el gesto.
* **El Fundador Vetado:** cualquier visitante o adepto que el canon excluye (anónimo, ya militante, convaleciente, `reader`). Ve el gesto con su leyenda de veto, inhabilitado — el veto es real (`disabled` + `aria-disabled`), no un aviso decorativo.
* **El Santuario:** backend de SPEC-07, única autoridad de veredictos; la interfaz jamás decide por su cuenta.

---

## 4. Historia de Usuario

* **HU-01b (El Umbral de la Fundación):**
  *Como* mago consagrado, jurado sobre un linaje y sin lealtad vigente,
  *Quiero* alzar mi propia hermandad desde el Salón de los Linajes, declarando su nombre canónico, su lema, su blasón y su régimen de admisión bajo mi linaje rector,
  *Para* consumar el gesto fundacional que SPEC-07 me promete sin abandonar la lengua del santuario ni abrir una sola consola.

---

## 5. Requisitos Funcionales (Notación EARS en Español)

### RF-10: El Umbral de la Fundación (superficie de SPEC-07 RF-01.2)

* **RF-10.1 [Dirigido por Eventos — El Gesto]:**
  EN el Salón de los Linajes (`#/linajes`), el sistema DEBERÁ exhibir el gesto «Fundar una hermandad propia» para todo visitante autenticado; AL activarlo, DEBERÁ abrir un `<dialog>` solemne de fundación. El gesto DEBERÁ estar visible pero INHABILITADO (`disabled` + `aria-disabled="true"` y leyenda explicativa) para quien el canon veta: visitante anónimo, adepto ya militante, convaleciente o de rango `reader`.

* **RF-10.2 [Ubicuo — Los Cuatro Sellos y el Régimen]:**
  El modal DEBERÁ recoger, con rotulación en noble castellano, **tres sellos y el régimen** (decisión ratificada: el blasón NO figura en el modal — el sello determinista nace neutro y el Patriarca lo personaliza desde su gobierno ya existente):
  1. **Nombre Canónico** (obligatorio, 4–50 caracteres; contador visible).
  2. **Lema Heráldico** (obligatorio; el corazón de la casa). *Nota de contraste verificada: SPEC-07 RF-01.2 lo exige como sello fundacional, aunque la persistencia tolera su ausencia (`DEFAULT ''`); la interfaz exige el canon de la spec madre, nunca la laxitud del almacenamiento.*
  3. **Linaje Rector:** la selección DEBERÁ presentar los 8 Linajes Canónicos del Salón, pero SOLO el linaje jurado del fundador DEBERÁ ser seleccionable; los otros siete DEBERÁN mostrarse inhabilitados con la leyenda «Tu sangre arcana jura otro linaje: el estandarte brota de tu propia casa» (SPEC-10 RF-04.1, veto `CLAN_LINEAGE_MISMATCH` hecho visible ANTES del envío).
  4. **Régimen de Admisión:** elección entre «Abierto» (`open`) y «Bajo Petición» (`byApplication`), con la leyenda de cada régimen (la de admisión abierta DEBE nombrar la plenitud de treinta).

  El `coatOfArms` del payload DEBERÁ viajar vacío (`''`): el sello determinista del canon (SPEC-07 RF-02.4) es el cumplimiento del sello del blasón, y su edición posterior ya vive en el panel del Patriarca.

* **RF-10.3 [Dirigido por Eventos — La Consumación]:**
  CUANDO el fundador confirme, el sistema DEBERÁ remitir el rito a `clanClient.foundClan` con el payload canónico (`name`, `motto`, `coatOfArms`, `lineageType`, `admissionMode`) y presentar un estado de esperanza solemne que impida el doble envío (el botón se inhabilita mientras el veredicto viaja).

* **RF-10.4 [Límite y Capacidad — La Antesala del Éxito]:**
  ANTES de la consumación, SI el veredicto del santuario es favorable, el modal DEBERÁ cerrarse y el Salón DEBERÁ reflejar la casa nacida (catálogo refrescado) y conducir al fundador a la ficha de su estandarte, donde lo espera el panel de gobierno del Patriarca ya existente.

* **RF-10.8 [Ratificada — El blasón no se pregunta]:**
  El modal de fundación NO DEBERÁ ofrecer campo de edición del blasón: la heráldica nace del sello determinista neutro (SPEC-07 RF-02.4: las muescas codifican el identificador, jamás se eligen a mano) y su personalización pertenece al gobierno del Patriarca (`clanManagementComponent`), que ya la ofrece tras la consumación.

* **RF-10.5 [No Deseado — La Voz de los Vetos]:**
  SI el santuario deniega el rito, ENTONCES el modal DEBERÁ permanecer abierto, conservar íntegro lo escrito, y exhibir la leyenda temática del veto en región viva (`aria-live="assertive"`), mapeando cada código canónico del contrato:
  | Código | Leyenda del veto |
  |---|---|
  | `INSUFFICIENT_RANK` | «Los neófitos sin pluma no alzan estandartes: tu rango aún no alcanza la fundación.» |
  | `INVALID_NAME` | «El nombre canónico no viste la túnica del canon (4 a 50 caracteres).» |
  | `NAME_ALREADY_RESERVED` | «Ese nombre ya resuena bajo otro estandarte: elige otro estandarte para tu casa.» |
  | `UNKNOWN_LINEAGE` | «El linaje invocado no habita el canon de los Ocho.» |
  | `CLAN_LINEAGE_MISMATCH` | «El estandarte brota de tu propia sangre arcana: no puedes fundar bajo linaje ajeno.» |
  | `CONVALESCENCE_ACTIVE` | «Tu esencia aún sana en Convalecencia Arcana: espera a que remita su hechizo.» |
  | `ALREADY_AFFILIATED` | «La lealtad mágica es indivisible: ya militas bajo otro estandarte.» |
  | `ADMIN_LINEAGE_REQUIRED` | «La Corona Suprema debe declarar su linaje rector antes de alzar la casa.» |
  Cualquier código no catalogado DEBERÁ degradar a una leyenda genérica de fallo arcano, jamás a un texto técnico crudo (Artículo IV).

* **RF-10.6 [Ubicuo — La Invitación del Vestíbulo]:**
  La invitación discreta a fundar del estado vacío del Vestíbulo (SPEC-10, RF-02 — «Ninguna hermandad ruega aún tu linaje») DEBERÁ conducir al mismo Umbral: un solo rito de fundación en todo el santuario, nunca dos.

* **RF-10.7 [Ubicuo — Sanidad Ceremonial]:**
  El descarte del modal (botón, × o Escape) DEBERÁ conservar lo escrito para la reapertura en la misma visita y no consumar acción alguna ante el santuario; el foco DEBERÁ quedar atrapado mientras el modal esté abierto y regresar al gesto originador al cerrar (mismo canon de RNF-03 de SPEC-10).

---

## 6. Contrato de API (consumido, no modificado)

El flujo consume el contrato ya ratificado en SPEC-07 (Tarea 5.1):

- **Petición:** `POST /api/v1/clans` con cookie de sesión; cuerpo JSON `{ name, motto, coatOfArms, lineageType, admissionMode }`.
- **Éxito:** `201` con el DTO del clan nacido (la fundación inscribe al Patriarca).
- **Vetos:** `401` sin sesión; `403` rango insuficiente; `409` nombre reservado / ya afiliado; `422` datos no canónicos — cuerpo estándar `{ success: false, error: { code, message, recoveryAction } }` (AGENTS.md 6.1). La UI traduce por `code` (RF-10.5) y jamás muestra el `message` crudo del servidor en primera instancia.

---

## 7. Requisitos No Funcionales (RNF)

* **RNF-01 (Dogma Vanilla — Artículo I):** `<dialog>` nativo, DOM exclusivamente por `createElement` con `textContent` puro; `innerHTML` PROHIBIDO; cero dependencias, CDNs o frameworks.
* **RNF-02 (Accesibilidad Solemne):** etiquetas de formulario vinculadas, foco visible, trampa de foco en el modal, región viva para veredictos y vetos, navegación completa por teclado (Enter confirma, Escape descarta).
* **RNF-03 (Dualidad Lingüística — Artículo V):** identificadores en inglés `camelCase`; toda leyenda, rótulo y veto en noble castellano; cero referencias técnicas (RF-xx, códigos) visibles al adepto.
* **RNF-04 (El Veto es Real):** ningún control inhabilitado por el canon despacha peticiones: la inhabilitación es de interfaz Y el backend es la última muralla; la UI jamás simula un veredicto.
* **RNF-05 (Honestidad del Estado):** el token de sesión viaja solo por la cookie del cliente; ningún dato del rito se persiste en `localStorage`.

---

## 8. Criterios de Aceptación

- [ ] Un fundador apto ve el gesto «Fundar una hermandad propia» habilitado en `#/linajes` y, al activarlo, el modal presenta los cuatro sellos y el régimen con rotulación castellana (RF-10.1, RF-10.2).
- [ ] Solo el linaje jurado del fundador es seleccionable; los otros siete se ven inhabilitados con su leyenda de sangre propia (RF-10.2.4).
- [ ] La confirmación despacha `foundClan` una sola vez (sin doble envío) y, con 201, el Salón refresca y conduce a la ficha de la casa nacida (RF-10.3, RF-10.4).
- [ ] Cada código de veto del contrato se exhibe como su leyenda temática, el modal conserva lo escrito y el envío no se pierde (RF-10.5).
- [ ] El visitante anónimo, el militante, el convaleciente y el `reader` ven el gesto inhabilitado con su leyenda, y ningún veto de interfaz despacha petición (RF-10.1, RNF-04).
- [ ] La invitación del estado vacío del Vestíbulo conduce al mismo modal (RF-10.6).
- [ ] Escape/botón/× descartan sin efecto en el santuario, conservan el borrador y devuelven el foco al gesto originador (RF-10.7).
- [ ] El arnés `scratch/test_clan_foundation_modal.mjs` verifica el ciclo completo (apertura, campos, selección de linaje, envío único, vetos, descarte) en verde.
- [ ] Cero ficheros de `src/` alterados (diff verificado en el cierre de la tarea).

---

## 9. Casos de Prueba Requeridos (arnés `.mjs`, DOM simulado)

1. **Gesto por actor:** apto → habilitado; anónimo/militante/convaleciente/`reader` → inhabilitado con leyenda.
2. **Apertura solemne:** el `<dialog>` abre, el foco entra al primer campo y el anuncio vivo lo declara.
3. **Linaje rector:** los 8 se listan; solo el jurado es seleccionable; los demás portan su leyenda.
4. **Validación temprana:** nombre corto/largo y lema vacío bloquean la confirmación con leyenda local.
5. **Envío único:** doble clic rápido despacha exactamente una petición (el botón se sella mientras viaja).
6. **Éxito:** 201 → cierre del modal, catálogo refrescado y navegación a la ficha de la casa.
7. **Vetos:** inyección de cada código del contrato (RF-10.5) → leyenda temática exacta, modal abierto, texto conservado, `aria-live` anunciando.
8. **Descarte:** Escape, botón y × cierran sin petición, conservan el borrador y restauran el foco.
9. **Lengua:** cero literales de interfaz en inglés y cero códigos técnicos visibles (patrón `test_auth_language_sovereignty`).

---

## 10. Decisiones ratificadas (2026-09-28)

1. **Ubicación del gesto — HÍBRIDO Salón + Vestíbulo (ratificada):** el gesto primario vive en el Salón de los Linajes (`clansPreviewView`); la invitación del estado vacío del Vestíbulo (SPEC-10, RF-02) abre el MISMO modal (RF-10.6). Un solo rito de fundación, dos puertas.
2. **El blasón — OCULTO del modal (ratificada):** el sello determinista neutro cumple el sello fundacional (ver RF-10.8); el payload viaja con `coatOfArms: ''` y la personalización pertenece al panel del Patriarca. El modal presenta TRES sellos + régimen.
3. **Tramitación SDD — MINI-TRÍADA (ratificada):** esta spec + `specs/07b-clan-foundation-interface.tasks.md` (tareas de implementación, arnés y cierre) — sin `plan.md` separado, pues las secciones 5–9 de este documento ya fijan los ficheros y el contrato. El plan técnico vive aquí.

**Ficheros previstos por la implementación (mini-plan):**

| Fichero | Operación | Papel |
|---|---|---|
| `public/assets/js/components/clanFoundationModalComponent.js` | NUEVO | El Umbral: modal `<dialog>` con 3 sellos + régimen, leyes de veto, foco y borrador |
| `public/assets/js/views/clansPreviewView.js` | MODIFICAR | El gesto en la cabecera del catálogo y el cableado del modal |
| `public/assets/js/views/vestibuleView.js` / `vestibuleClanCardComponent.js` | MODIFICAR | La invitación del estado vacío abre el mismo modal (RF-10.6) |
| `public/assets/css/components/` (anexo en `components.css` o fichero propio) | NUEVO/ANEXO | Estilos solemnes del modal (tokens de SPEC-02) |
| `public/index.html` | MODIFICAR | El `<dialog>` anfitrión del modal, junto a los ya existentes |
| `scratch/test_clan_foundation_modal.mjs` | NUEVO | Arnés de los 9 casos de prueba (§9) |
