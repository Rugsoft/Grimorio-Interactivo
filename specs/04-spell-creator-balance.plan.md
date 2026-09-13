# PLAN-04: Plan Técnico de Implementación — Creador de Hechizos y Algoritmo de Balanceo de Maná

> **Especificación Asociada:** [`specs/04-spell-creator-balance.spec.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/specs/04-spell-creator-balance.spec.md)  
> **Constitución:** [`constitution.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/constitution.md) | **Directrices:** [`AGENTS.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/AGENTS.md)  
> **Estado:** Listo para Implementación  
> **Restricción Suprema:** Dogma Vanilla (Cero dependencias externas, cero librerías de cálculo) y Dualidad Lingüística (Código, identificadores técnicos en inglés `camelCase`, interfaz, motivos y comentarios en noble castellano).

---

## 1. Estructura de Módulos y Ficheros

La arquitectura se compone de servicios backend desacoplados en **PHP 8.2+ estricto** (`declare(strict_types=1);`) y componentes reactivos en **Modern Vanilla JS** (ES Modules nativos):

```
grimorio-interactivo/
├── src/                                         # Código fuente Backend privado
│   ├── Dto/
│   │   ├── SpellCalculationInputDto.php         # Parámetros numéricos de entrada para la fórmula [RF-01, RF-02]
│   │   ├── SpellCalculationResultDto.php        # Desglose matemático, coste final y círculo arcano [RF-02, RF-03]
│   │   └── SpellCreateDto.php                   # Carga útil completa para guardado de borradores y publicación [RF-01, RF-05]
│   ├── Services/
│   │   ├── SpellBalanceService.php              # Algoritmo matemático puro con constantes universales [RF-02, RF-03, Art. II]
│   │   └── SpellManagementService.php           # Ciclo de vida, cuota de 10 borradores y reseteo selectivo [RF-05, RF-06]
│   └── Controllers/
│       └── SpellCreatorController.php           # Endpoints de cálculo en vivo, borradores y publicación [RF-01 a RF-06]
└── public/
    └── assets/
        └── js/
            ├── api/
            │   └── spellCreatorClient.js        # Cliente HTTP fetch para cálculo, drafts y publicación [RF-04, RF-05]
            ├── components/
            │   ├── manaBreakdownComponent.js    # Componente visual del desglose pedagógico en tiempo real [RF-04.1, RNF-04]
            │   └── spellFormControls.js         # Selectores táctiles de efectos, geometrías y componentes [RF-01]
            ├── utils/
            │   └── spellBalanceSimulator.js     # Motor matemático en cliente para simulación reactiva < 50ms [RF-04.1, RNF-02]
            └── views/
                └── spellCreatorView.js          # Vista principal del taller de creación y gestión de borradores [RF-01 a RF-06]
```

---

## 2. Modelo de Datos y Contratos de la API REST

### 2.1 Esquema DDL en SQL (Ampliación de la Tabla `spells`)

Todos los identificadores de columnas, tipos de efectos y modificadores se definen en **inglés y `camelCase`/`snake_case`** en cumplimiento del **Artículo V de la Constitución**:

```sql
-- Estructura de la tabla de conjuros con parámetros cuantitativos [RF-01, RF-05, RF-06]
CREATE TABLE IF NOT EXISTS spells (
    id VARCHAR(36) PRIMARY KEY,                         -- Identificador único uuid (ej. 'spl_9f8b2c1a')
    slug VARCHAR(80) NOT NULL UNIQUE,                  -- Slug alfanumérico único para la URL rúnica
    name VARCHAR(60) NOT NULL UNIQUE,                  -- Nombre canónico único en todo el santuario
    author_id VARCHAR(36) NOT NULL,                    -- Creador del conjuro (clave foránea a users)
    clan_id VARCHAR(36) NOT NULL,                      -- Clan al que pertenecía el autor al concebirlo
    status VARCHAR(20) NOT NULL DEFAULT 'draft',       -- 'draft', 'experimental', 'validated'
    
    -- Metadatos arcanos
    magic_school VARCHAR(30) NOT NULL,                 -- 'evocation', 'abjuration', 'necromancy', etc.
    elemental_affinity VARCHAR(30) NOT NULL,           -- 'fire', 'water', 'lightning', 'earth', etc.
    casting_time VARCHAR(20) NOT NULL,                 -- 'action', 'reaction', 'ritual'
    description TEXT NOT NULL,                         -- Descripción narrativa en castellano noble
    
    -- Magnitudes numéricas cuantitativas (Efectos base)
    damage INT NOT NULL DEFAULT 0,                     -- Puntos de daño directo o continuo
    healing INT NOT NULL DEFAULT 0,                    -- Puntos de curación directa
    barrier INT NOT NULL DEFAULT 0,                    -- Puntos de absorción o protección
    crowd_control_type VARCHAR(20) NOT NULL DEFAULT 'none', -- 'none', 'slow', 'root', 'stun'
    
    -- Modificadores geométricos y temporales
    range_type VARCHAR(20) NOT NULL DEFAULT 'touch',   -- 'touch', 'short', 'medium', 'long'
    area_type VARCHAR(20) NOT NULL DEFAULT 'singleTarget', -- 'singleTarget', 'cone', 'line', 'sphere'
    duration_type VARCHAR(20) NOT NULL DEFAULT 'instant',  -- 'instant', 'concentration', 'sustained'
    
    -- Componentes atenuadores
    has_verbal BOOLEAN NOT NULL DEFAULT 0,             -- Componente verbal (-10%)
    has_somatic BOOLEAN NOT NULL DEFAULT 0,            -- Componente somático (-10%)
    has_material BOOLEAN NOT NULL DEFAULT 0,           -- Componente material (-10%)
    
    -- Resultados deterministas calculados por SpellBalanceService
    mana_cost INT NOT NULL,                            -- Coste final de maná (mínimo 5, máximo 200)
    circle INT NOT NULL,                               -- Círculo arcano asignado (1 a 5)
    math_fingerprint VARCHAR(64) NOT NULL,             -- Hash SHA-256 de parámetros matemáticos para antifraude
    
    -- Estado de moderación
    signatures_count INT NOT NULL DEFAULT 0,           -- Firmas de Maestros acumuladas (0 a 3)
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    validated_at DATETIME NULL,
    
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (clan_id) REFERENCES clans(id) ON UPDATE CASCADE,
    INDEX idx_spell_author_status (author_id, status),
    INDEX idx_spell_clan_validated (clan_id, status)
);
```

---

### 2.2 Contratos de la API REST

Base URL: `/api/v1`  
Cabecera Global: `Content-Type: application/json; charset=utf-8`

#### Endpoint 1: Simulación y Desglose Pedagógico de Maná
* **`POST /api/v1/spells/calculate`**
* **Payload Entrada:**
  ```json
  {
    "damage": 30,
    "healing": 0,
    "barrier": 0,
    "crowdControlType": "none",
    "rangeType": "medium",
    "areaType": "sphere",
    "durationType": "instant",
    "hasVerbal": true,
    "hasSomatic": true,
    "hasMaterial": false
  }
  ```
* **Respuesta HTTP `200 OK`:**
  ```json
  {
    "success": true,
    "data": {
      "baseEffectPoints": 30.0,
      "multipliers": {
        "range": 1.25,
        "area": 1.6,
        "duration": 1.0,
        "combined": 2.0
      },
      "grossMana": 60.0,
      "discounts": {
        "verbal": 0.10,
        "somatic": 0.10,
        "material": 0.0,
        "totalPercent": 0.20,
        "amountDeducted": 12.0
      },
      "netMana": 48.0,
      "finalManaCost": 48,
      "circle": 3,
      "circleLabel": "Círculo III (Magister)",
      "isOverloaded": false
    }
  }
  ```
* **Respuesta HTTP `400 Bad Request` (Sobrecarga Arcana):**
  ```json
  {
    "success": false,
    "error": {
      "code": "ARCANE_OVERLOAD",
      "message": "La concentración de poder supera la capacidad de contención del plano mortal (máx. 200 maná).",
      "calculatedMana": 240
    }
  }
  ```

#### Endpoint 2: Guardar Borrador Privado (`draft`)
* **`POST /api/v1/spells/drafts`**
* **Payload Entrada:**
  ```json
  {
    "name": "Esfera Ígnea de Frieren",
    "elementalAffinity": "fire",
    "magicSchool": "evocation",
    "castingTime": "action",
    "description": "Concentra calor blanco en un núcleo denso que estalla al alcanzar la distancia media.",
    "damage": 25,
    "healing": 0,
    "barrier": 0,
    "crowdControlType": "none",
    "rangeType": "medium",
    "areaType": "sphere",
    "durationType": "instant",
    "hasVerbal": true,
    "hasSomatic": true,
    "hasMaterial": false
  }
  ```
* **Respuestas HTTP:**
  * `201 Created`: Borrador guardado exitosamente. Retorna el ID y el slug generado.
  * `400 Bad Request`: Error de validación o nombre duplicado.
  * `403 Forbidden` (`DRAFT_QUOTA_EXCEEDED`): El autor ya posee 10 borradores activos.

#### Endpoint 3: Listar Borradores del Autor Activo
* **`GET /api/v1/spells/drafts`**
* **Respuesta HTTP `200 OK`:** Arreglo con la lista de conjuros en estado `draft` del usuario activo (con sus costes, círculos y fechas).

#### Endpoint 4: Publicación a Estado Experimental
* **`POST /api/v1/spells/publish/{id}`**
* **Respuestas HTTP:**
  * `200 OK`: Transición a estado `experimental` completada (firmas inicializadas a 0/3).
  * `400 Bad Request`: El conjuro no cumple requisitos mínimos (carece de efectos base o descripción).
  * `404 Not Found`: Borrador inexistente o ajeno al autor activo.

#### Endpoint 5: Edición de Hechizo Experimental y Antifraude de Firmas
* **`PUT /api/v1/spells/experimental/{id}`**
* **Respuestas HTTP:**
  * `200 OK`: Conjuro actualizado. Si los parámetros matemáticos cambiaron, el objeto de respuesta incluye `"signaturesReset": true` con firmas restablecidas a 0/3.
  * `403 Forbidden`: Intento de edición sobre un conjuro que ya ha alcanzado el estado `validated`.

---

## 3. Algoritmos Clave en Pseudocódigo y Constantes Matemáticas

### 3.1 Constantes Universales y Algoritmo de la Fórmula de Maná (`SpellBalanceService.php`)
```text
CLASS SpellBalanceService:
    // Ponderaciones de Efectos Base
    CONST WEIGHT_DAMAGE = 1.0
    CONST WEIGHT_HEALING = 1.5
    CONST WEIGHT_BARRIER = 1.2
    
    CONST CC_WEIGHTS = {
        'none': 0.0,
        'slow': 8.0,
        'root': 15.0,
        'stun': 25.0
    }
    
    // Multiplicadores Geométricos y Temporales
    CONST RANGE_FACTORS = { 'touch': 1.0, 'short': 1.1, 'medium': 1.25, 'long': 1.5 }
    CONST AREA_FACTORS = { 'singleTarget': 1.0, 'cone': 1.3, 'line': 1.4, 'sphere': 1.6 }
    CONST DURATION_FACTORS = { 'instant': 1.0, 'concentration': 1.25, 'sustained': 1.5 }
    
    // Descuentos de Componentes
    CONST COMPONENT_DISCOUNTS = { 'verbal': 0.10, 'somatic': 0.10, 'material': 0.10 }
    CONST MAX_COMPONENT_DISCOUNT = 0.30
    CONST MANA_FLOOR = 5
    CONST MANA_OVERLOAD_CEILING = 200

    FUNCTION calculate(input: SpellCalculationInputDto) -> SpellCalculationResultDto:
        // 1. Suma de puntos de efectos base
        LET basePoints = (input.damage * WEIGHT_DAMAGE) +
                         (input.healing * WEIGHT_HEALING) +
                         (input.barrier * WEIGHT_BARRIER) +
                         CC_WEIGHTS[input.crowdControlType]
                         
        IF basePoints <= 0 THEN
            THROW ValidationException("Un conjuro sin efectos carece de forma arcana.")

        // 2. Multiplicadores combinados
        LET rangeMul = RANGE_FACTORS[input.rangeType]
        LET areaMul = AREA_FACTORS[input.areaType]
        LET durationMul = DURATION_FACTORS[input.durationType]
        LET combinedMul = rangeMul * areaMul * durationMul
        
        LET grossMana = basePoints * combinedMul
        
        // 3. Descuento acotado por componentes
        LET discountPercent = 0.0
        IF input.hasVerbal THEN discountPercent += COMPONENT_DISCOUNTS['verbal']
        IF input.hasSomatic THEN discountPercent += COMPONENT_DISCOUNTS['somatic']
        IF input.hasMaterial THEN discountPercent += COMPONENT_DISCOUNTS['material']
        
        discountPercent = MIN(discountPercent, MAX_COMPONENT_DISCOUNT)
        
        // 4. Redondeo ceil y suelo de 5
        LET netMana = grossMana * (1.0 - discountPercent)
        LET finalMana = MAX(MANA_FLOOR, CEIL(netMana))
        
        // 5. Evaluación de Sobrecarga Arcana
        IF finalMana > MANA_OVERLOAD_CEILING THEN
            THROW OverloadException("Sobrecarga Arcana: el maná resultante (" + finalMana + ") excede el límite mortal de 200.")
            
        // 6. Asignación automática del Círculo Arcano
        LET circle = determineCircle(finalMana)
        
        RETURN SpellCalculationResultDto(finalMana, circle, basePoints, combinedMul, discountPercent)

    FUNCTION determineCircle(mana: Integer) -> Integer:
        IF mana <= 20 THEN RETURN 1
        IF mana <= 45 THEN RETURN 2
        IF mana <= 80 THEN RETURN 3
        IF mana <= 130 THEN RETURN 4
        RETURN 5
```

### 3.2 Huella Digital Matemática y Reseteo Selectivo de Firmas
```text
FUNCTION computeMathFingerprint(spellData: SpellInput) -> String:
    // Concatenar estrictamente los parámetros matemáticos
    LET mathPayload = spellData.damage + ":" +
                      spellData.healing + ":" +
                      spellData.barrier + ":" +
                      spellData.crowdControlType + ":" +
                      spellData.rangeType + ":" +
                      spellData.areaType + ":" +
                      spellData.durationType + ":" +
                      (spellData.hasVerbal ? "1" : "0") + ":" +
                      (spellData.hasSomatic ? "1" : "0") + ":" +
                      (spellData.hasMaterial ? "1" : "0")
                      
    RETURN SHA256(mathPayload)

FUNCTION updateExperimentalSpell(existingSpell: Spell, newPayload: SpellInput):
    IF existingSpell.status == 'validated' THEN
        THROW ForbiddenException("Un conjuro validado es patrimonio inmutable y no puede alterarse.")

    LET oldFingerprint = existingSpell.mathFingerprint
    LET newFingerprint = computeMathFingerprint(newPayload)
    
    IF oldFingerprint != newFingerprint THEN
        // Alteración matemática detectada -> Reseteo estricto de firmas a 0/3
        existingSpell.signaturesCount = 0
        existingSpell.mathFingerprint = newFingerprint
        existingSpell.manaCost = SpellBalanceService.calculate(newPayload).finalManaCost
        existingSpell.circle = SpellBalanceService.calculate(newPayload).circle
        
        AuditService.record(
            action: 'RESET_SIGNATURES_MATH_CHANGE',
            entityId: existingSpell.id,
            justification: 'Alteración de parámetros cuantitativos en moderación. Firmas restablecidas a 0/3.'
        )
    ELSE
        // Solo cambió descripción narrativa o metadato cosmético -> Firmas intactas
        AuditService.record(
            action: 'UPDATE_DESCRIPTION_INTACT_SIGNATURES',
            entityId: existingSpell.id,
            justification: 'Actualización ortográfica/narrativa de conjuro experimental. Firmas conservadas.'
        )

    existingSpell.description = newPayload.description
    existingSpell.save()
```

---

## 4. Arquitectura de Eventos y Componentes Frontend Vanilla

### 4.1 Simulador Reactivo en Cliente (`spellBalanceSimulator.js`)
* **Espejo exacto del backend:** Implementa la misma fórmula matemática en JS nativo para ofrecer retroalimentación en menos de **50 ms** sin esperar llamadas de red en cada pulsación.
* **Manejo de eventos:** Los inputs numéricos y selectores emiten eventos `input` y `change`. El componente captura el evento, invoca al simulador y actualiza el árbol DOM del componente de desglose.

### 4.2 Componente de Desglose Pedagógico (`manaBreakdownComponent.js`)
* Muestra de forma estructurada y con diseño arcano:
  * Barra de Círculo Arcano (Círculos I al V con indicadores de color).
  * Panel de desglose: Puntos base $\times$ Multiplicadores de área/alcance $-$ Deducción de componentes $=$ Total de Maná.
  * Advertencia visual en rojo ígneo si se aproxima o supera el umbral de Sobrecarga Arcana (> 200).

### 4.3 Persistencia Volátil ante Pérdida de Conexión
* Cada cambio en el formulario se serializa automáticamente en `sessionStorage.setItem('grimoire_current_draft', JSON.stringify(formData))`.
* Si la conexión cae o se refresca la ventana accidentalmente, el formulario recupera el estado previo sin pérdida de trabajo creativo.

---

## 5. Decisiones Técnicas Justificadas

### Decisión 1: Simetría de Cálculo (Simulador Frontend + Backend Autoritativo)
* **Elección:** Implementar el cálculo en cliente para la respuesta inmediata (< 50 ms) y re-ejecutarlo de forma obligatoria y ciega en el backend durante el guardado.
* **Alternativa Descartada:** Depender exclusivamente de llamadas AJAX continuas al servidor o confiar ciegamente en el coste enviado por el cliente.
* **Justificación Constitucional:** Cumple el **Artículo II (Determinismo Ciego)**. El cliente no puede hacer trampa enviando un coste de maná menor, ya que el backend ignora cualquier valor enviado por el navegador y computa el maná mediante `SpellBalanceService`.

### Decisión 2: Huella Digital Matemática (`math_fingerprint`) para Antifraude
* **Elección:** Generar un hash SHA-256 de los parámetros numéricos y compararlo en cada actualización.
* **Alternativa Descartada:** Resetear firmas ante cualquier edición (incluso corregir una tilde) o requerir confirmación manual de un administrador.
* **Justificación:** Protege la integridad de la moderación frente a manipulaciones sutiles (ej. cambiar un multiplicador de alcance de Contacto a Largo tras obtener 2 firmas) sin castigar injustamente correcciones ortográficas o de redacción.

### Decisión 3: Cuota de 10 Borradores por Usuario
* **Elección:** Límite duro de 10 registros `draft` por usuario verificado en la capa de servicio.
* **Alternativa Descartada:** Borradores ilimitados o forzar publicación directa sin fase de borrador.
* **Justificación:** Previene la acumulación de datos zombis y spam en la base de datos relacional sin restringir la flexibilidad creativa del autor.

---

## 6. Estrategia de Pruebas y Verificación

### 6.1 Script Automatizado de Verificación Integral (`scratch/test_spell_balance.php`)
Se creará un script CLI autónomo que validará secuencialmente:

1. **Prueba de Suelo Mínimo:** Conjuro con efectos mínimos (ej. 1 de daño con 3 componentes) $\rightarrow$ Verifica que el maná resultante sea exactamente `5`.
2. **Prueba de Redondeo Ceil:** Caso que genere un valor bruto de 14.1 $\rightarrow$ Verifica que el maná resultante sea exactamente `15`.
3. **Prueba de Límite de Descuento:** Conjuro con componentes activados $\rightarrow$ Verifica que el descuento nunca exceda el `30%`.
4. **Prueba de Sobrecarga Arcana:** Conjuro con 150 de daño en área esférica a larga distancia $\rightarrow$ Verifica que lance excepción `OverloadException` (maná > 200).
5. **Prueba de Cuota de Borradores:** Intento de creación del 11.º borrador para un mismo autor $\rightarrow$ Verifica rechazo con código `403 Forbidden` (`DRAFT_QUOTA_EXCEEDED`).
6. **Prueba Antifraude de Firmas:**
   * Modificar descripción de hechizo experimental con 2 firmas $\rightarrow$ Verifica que conserva las 2 firmas.
   * Modificar daño de 20 a 25 $\rightarrow$ Verifica que las firmas se restablecen a `0/3` y se registra en `audit_log`.

---

## 7. Matriz de Trazabilidad de Requisitos

| Requisito | Descripción | Clase / Módulo Técnico | Método / Endpoint | Verificación |
| :--- | :--- | :--- | :--- | :--- |
| **RF-01.1 a 01.5** | Modelo de datos, efectos y modificadores | `SpellCreationDto.php`, `spells` (SQL) | `POST /api/v1/spells/drafts` | Validación de esquema y tipos |
| **RF-02.1 a 02.5** | Fórmula de maná, suelo de 5 y redondeo ceil | `SpellBalanceService.php` | `calculate()` | Tests 1, 2 y 3 en `test_spell_balance.php` |
| **RF-03.1 / 03.2** | 5 Círculos y Sobrecarga Arcana (> 200) | `SpellBalanceService.php` | `determineCircle()` | Test 4 en `test_spell_balance.php` |
| **RF-04.1 / 04.2** | Simulación cliente < 50ms y recálculo backend | `spellBalanceSimulator.js`, `SpellCreatorController.php` | `POST /api/v1/spells/calculate` | Medición de latencia en navegador |
| **RF-05.1** | Cuota de 10 borradores privados | `SpellManagementService.php` | `POST /api/v1/spells/drafts` | Test 5 en `test_spell_balance.php` |
| **RF-05.2** | Publicación a experimental (0/3 firmas) | `SpellManagementService.php` | `POST /api/v1/spells/publish/{id}` | Comprobación de estado y firmas = 0 |
| **RF-05.3 / 05.4** | Reseteo selectivo antifraude | `SpellManagementService.php` | `PUT /api/v1/spells/experimental/{id}` | Test 6 en `test_spell_balance.php` |
| **RF-06.1 / 06.2** | Inmutabilidad de hechizos validados | `SpellManagementService.php` | `PUT /api/v1/spells/experimental/{id}` | Rechazo 403 sobre hechizo validado |
| **RF-06.3** | Clonación como Variante independiente | `SpellManagementService.php` | `POST /api/v1/spells/variant/{id}` | Creación de nuevo draft con sufijo |
| **RNF-01 a 05** | Determinismo, latencia y bilingüismo | `SpellBalanceService.php`, UI | N/A | Auditoría de código e inspección de BD |

---

## 8. Cumplimiento Constitucional y de Gobernanza

1. **Artículo I (El Dogma Vanilla):** Cero librerías externas para matemáticas o formularios; cálculo aritmético nativo en PHP 8.2+ y JavaScript ES6 estándar.
2. **Artículo II (La Ley Universal del Maná: Determinismo & Anti-Power-Creep):**
   * El cálculo es 100% determinista y ciego en el backend, sin atajos manuales.
   * El techo absoluto de 200 de maná previene el desequilibrio de poder en el santuario.
3. **Artículo III (Ética de la Moderación):** La salvaguarda de huella digital matemática neutraliza el fraude por sustitución de conjuros tras obtener firmas de Maestros.
4. **Artículo V (Dualidad Lingüística):**
   * Identificadores de columnas, variables y claves técnicas en inglés `camelCase` (`manaCost`, `crowdControlType`, `rangeType`, `areaType`, `mathFingerprint`).
   * Desglose pedagógico, descripciones arcanas, nombres de Círculos y mensajes de Sobrecarga Arcana expresados con riqueza solemne en **lengua castellana**.
