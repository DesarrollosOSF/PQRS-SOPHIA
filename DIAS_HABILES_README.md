# 📅 Sistema de Cálculo de Días Hábiles para PQRS - Colombia

## 🎯 Objetivo de la Mejora

El sistema Sophía ha sido mejorado para calcular los vencimientos de PQRS utilizando **días hábiles** en lugar de días calendario, considerando:

- ✅ **Lunes a Viernes** como días hábiles
- ❌ **Sábados y Domingos** NO se cuentan
- ❌ **Festivos de Colombia** NO se cuentan (según Ley 51 de 1983 - Ley Emiliani)

---

## 📊 ¿Qué cambió?

### **ANTES (Días Calendario):**
```
Fecha de creación: 01/11/2024 (viernes)
Plazo: 15 días calendario
Fecha límite: 16/11/2024 (sábado)
```

### **AHORA (Días Hábiles):**
```
Fecha de creación: 01/11/2024 (viernes)
Plazo: 15 días hábiles
Fecha límite: 22/11/2024 (viernes)
Explicación: Se excluyen 4 fines de semana (8 días) y 1 festivo (11/11 - Independencia de Cartagena)
```

---

## 🏗️ Arquitectura de la Solución

### **1. Archivos Nuevos Creados**

#### **config/festivos_colombia.php**
- Contiene todas las funciones para calcular festivos de Colombia
- Implementa la Ley Emiliani (festivos trasladados al lunes)
- Funciones principales:
  - `obtenerFestivosColombia($anio)` - Obtiene todos los festivos del año
  - `esDiaHabil($fecha)` - Verifica si una fecha es día hábil
  - `calcularFechaLimiteHabiles($fecha_inicio, $dias_habiles)` - Calcula fecha límite
  - `calcularDiasHabilesEntre($fecha_inicio, $fecha_fin)` - Cuenta días hábiles

#### **config/pqrs_helper.php**
- Funciones auxiliares para procesamiento de PQRS
- Integra el cálculo de días hábiles con los datos de la BD
- Funciones principales:
  - `procesarPQRSConDiasHabiles($pqrs_data)` - Agrega campos de días hábiles
  - `ordenarPQRSPorUrgencia($pqrs_data)` - Ordena por días hábiles restantes
  - `calcularEstadisticasPQRS($pqrs_data)` - Calcula KPIs con días hábiles
  - `generarBadgeDiasHabiles($dias_habiles, $estado)` - Genera HTML para badges

#### **dashboard/festivos.php**
- Página nueva en el sistema
- Muestra todos los festivos de Colombia por año
- Incluye calculadora de días hábiles
- Ejemplos prácticos de cálculo de vencimientos

---

## 🔧 Archivos Modificados

### **config/app.php**
- Agregadas funciones de compatibilidad:
  - `calcularDiasHabilesRestantes()` - Nueva función principal
  - `obtenerFechaLimiteHabiles()` - Obtiene fecha límite
  - `calcularDiasRestantes()` - Mantiene función antigua (comentada como obsoleta)

### **dashboard/index.php** (Dashboard Principal)
- ✅ Incluye `pqrs_helper.php`
- ✅ Procesa PQRS con días hábiles
- ✅ Ordena por urgencia usando días hábiles
- ✅ KPIs actualizados: "Días Hábiles Promedio Restantes"
- ✅ Tabla muestra "Días Hábiles Restantes" con fecha límite

### **dashboard/pqrs.php** (Gestión de PQRS)
- ✅ Incluye `pqrs_helper.php`
- ✅ Procesa PQRS con días hábiles
- ✅ Filtros funcionan con días hábiles
- ✅ Tarjetas muestran "Borrador Vencidas (días hábiles)"
- ✅ Columnas actualizadas: "Fecha Máxima (Hábiles)" y "Días Hábiles Restantes"

### **dashboard/reportes.php** (Reportes y Análisis)
- ✅ Incluye `pqrs_helper.php`
- ✅ Procesa PQRS con días hábiles
- ✅ PQRS en riesgo se identifican por días hábiles (≤3 días)
- ✅ Tabla de riesgo muestra días hábiles restantes

### **includes/header.php** (Navegación)
- ✅ Agregado enlace a "Festivos Colombia" en el menú lateral

---

## 📅 Festivos de Colombia Implementados

### **Festivos Fijos (no se trasladan):**
1. **01 de Enero** - Año Nuevo
2. **01 de Mayo** - Día del Trabajo
3. **20 de Julio** - Día de la Independencia
4. **07 de Agosto** - Batalla de Boyacá
5. **08 de Diciembre** - Inmaculada Concepción
6. **25 de Diciembre** - Navidad

### **Festivos Variables (Semana Santa):**
- **Jueves Santo** (3 días antes del Domingo de Resurrección)
- **Viernes Santo** (2 días antes del Domingo de Resurrección)

### **Festivos Trasladables al Lunes (Ley Emiliani):**
1. **06 de Enero** - Reyes Magos
2. **19 de Marzo** - San José
3. **29 de Junio** - San Pedro y San Pablo
4. **15 de Agosto** - Asunción de la Virgen
5. **12 de Octubre** - Día de la Raza
6. **01 de Noviembre** - Todos los Santos
7. **11 de Noviembre** - Independencia de Cartagena

### **Festivos Basados en Semana Santa (trasladados al lunes):**
- **Ascensión del Señor** (39 días después del Domingo de Resurrección)
- **Corpus Christi** (60 días después del Domingo de Resurrección)
- **Sagrado Corazón** (68 días después del Domingo de Resurrección)

---

## 🧪 Cómo Probar la Mejora

### **1. Acceder a la página de Festivos**
```
URL: dashboard/festivos.php
```
- Selecciona un año para ver todos los festivos
- Usa la calculadora de días hábiles
- Verifica el ejemplo práctico de PQRS

### **2. Ver el Dashboard Principal**
```
URL: dashboard/index.php
```
- Observa el KPI "Días Hábiles Promedio Restantes"
- Verifica que la tabla muestra "Días Hábiles Restantes"
- Comprueba que las fechas límite excluyen fines de semana y festivos

### **3. Gestión de PQRS**
```
URL: dashboard/pqrs.php
```
- Filtra por "Solo vencidas" - ahora usa días hábiles
- Observa las tarjetas de resumen con días hábiles
- Verifica las fechas límite en la tabla principal

### **4. Reportes**
```
URL: dashboard/reportes.php
```
- Verifica la sección "PQRS en Riesgo de Vencimiento (≤3 días hábiles)"
- Comprueba que los promedios por área usan días hábiles

---

## 💡 Ejemplos de Uso

### **Ejemplo 1: PQRS creada un viernes**
```php
Fecha de creación: 01/11/2024 (viernes)
Días hábiles: 15

Cálculo manual:
- 04/11 (lunes) - día 1
- 05/11 (martes) - día 2
- ...
- 11/11 (lunes) - ❌ FESTIVO (Independencia de Cartagena trasladado)
- 12/11 (martes) - día 9
- ...
- 22/11 (viernes) - día 15

Fecha límite: 22/11/2024 (viernes)
```

### **Ejemplo 2: PQRS con Semana Santa**
```php
Fecha de creación: 15/03/2024 (viernes)
Días hábiles: 15

Se excluyen:
- Fines de semana (16-17/03, 23-24/03, 30-31/03, 06-07/04)
- Jueves Santo (28/03/2024)
- Viernes Santo (29/03/2024)

Fecha límite: Aproximadamente 08/04/2024
```

---

## 🔄 Proceso de Cálculo

### **Flujo de Procesamiento:**
```
1. BD devuelve PQRS con fecha de creación
   ↓
2. procesarPQRSConDiasHabiles($pqrs_data)
   ↓
3. Para cada PQRS:
   - Calcular fecha límite (15 días hábiles desde creación)
   - Calcular días hábiles restantes (desde hoy hasta fecha límite)
   - Agregar campos: Fecha_Maxima_Respuesta_Habiles, Dias_Habiles_Restantes
   ↓
4. ordenarPQRSPorUrgencia($pqrs_data)
   - Prioridad 1: Estado (Borrador > Validado > Cancelado)
   - Prioridad 2: Días hábiles restantes (ascendente)
   ↓
5. Mostrar en dashboard con badges y alertas
```

---

## 🎨 Cambios Visuales

### **Badges de Días Hábiles:**
- 🔴 **Rojo (danger)**: Días hábiles < 0 (vencida)
- 🟡 **Amarillo (warning)**: Días hábiles entre 0 y 3 (en riesgo)
- 🟢 **Verde (success)**: Días hábiles > 3 (normal)

### **Tabla de PQRS:**
- Nueva columna: "Días Hábiles Restantes"
- Muestra fecha límite calculada con días hábiles
- Resalta en rojo las filas vencidas
- Resalta en amarillo las filas en riesgo

---

## ⚙️ Configuración

### **Cambiar el plazo de respuesta:**
```php
// Archivo: config/app.php
define('PQRS_RESPONSE_DAYS', 15); // Cambiar aquí el número de días hábiles
```

### **Agregar festivos adicionales:**
```php
// Archivo: config/festivos_colombia.php
// Editar la función obtenerFestivosColombia($anio)
$festivos[] = "$anio-XX-XX"; // Agregar fecha en formato Y-m-d
```

---

## 📈 Ventajas de la Mejora

✅ **Cumplimiento legal**: Respeta la legislación colombiana sobre días hábiles
✅ **Mayor precisión**: Los vencimientos reflejan días laborales reales
✅ **Transparencia**: Los usuarios ven exactamente cuándo vence una PQRS
✅ **Alertas oportunas**: Las PQRS en riesgo se identifican correctamente
✅ **Facilita la gestión**: Prioriza automáticamente por urgencia real

---

## 🚀 Actualización del Sistema

Si deseas actualizar el sistema existente con esta mejora:

1. **Copiar los nuevos archivos:**
   - `config/festivos_colombia.php`
   - `config/pqrs_helper.php`
   - `dashboard/festivos.php`

2. **Reemplazar los archivos modificados:**
   - `config/app.php`
   - `dashboard/index.php`
   - `dashboard/pqrs.php`
   - `dashboard/reportes.php`
   - `includes/header.php`

3. **Limpiar caché del navegador**

4. **Verificar funcionamiento en festivos.php**

---

## 📞 Soporte

Para cualquier duda o problema con el sistema de días hábiles:
- Revisa la página `dashboard/festivos.php` para ejemplos
- Verifica que los festivos se calculan correctamente para el año actual
- Asegúrate de que la función `easter_date()` de PHP está disponible

---

## 🔮 Mejoras Futuras Posibles

- [ ] Gestión de festivos locales (municipales)
- [ ] Configuración de festivos personalizados desde el dashboard
- [ ] Notificaciones automáticas cuando una PQRS entra en riesgo
- [ ] Exportación de calendario de festivos
- [ ] API para consultar días hábiles

---

**Sophía - Sistema de Gestión PQRS**  
*"Gestión con sabiduría y cercanía"* 📖

