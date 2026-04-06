# Starter Home Sections - Sistema Escalable

## 🎯 Descripción

Plugin WordPress escalable para gestionar secciones de productos en la página de inicio. Permite crear **ilimitadas secciones** con diferentes layouts y configuraciones.

## ✨ Características

### **Tipos de Layout Disponibles**

1. **Horizontal** (8 productos en fila)
   - Grid: 2 columnas móvil, 4 tablet, 8 desktop
   - Ideal para mostrar variedad de productos

2. **Estándar** (6 productos en grilla)
   - Grid: 2 columnas móvil, 3 tablet, 6 desktop
   - Balance entre variedad y detalle

3. **Compacta** (4 productos en 2x2) ⚠️ **Requiere Par**
   - Grid: 2x2 en todas las pantallas
   - **IMPORTANTE**: Siempre se muestran en pares (2 secciones lado a lado)
   - Perfecto para secciones bottom con categorías específicas
   - Si creas una sección compacta sola, se mostrará a ancho completo hasta que agregues su par

### **Zonas de Ubicación**

- **Top**: Secciones superiores (después del hero)
- **Middle**: Secciones intermedias (después del banner medio)
- **Bottom**: Secciones inferiores (antes de beneficios)

## 🚀 Uso

### **Crear una Nueva Sección**

1. Ve a **WordPress Admin → Secciones de Inicio**
2. Completa el formulario:
   - **Tipo de Layout**: Elige horizontal, estándar o compacta
   - **Zona**: Top, Middle o Bottom
   - **Categoría**: Selecciona la categoría de productos
   - **Título**: (Opcional) Personaliza el título
   - **Subtítulo**: (Opcional) Agrega un subtítulo
   - **Orden**: Define el orden de visualización (0 = primero)
   - **Productos Aleatorios**: Marca para mostrar productos random
   - **Sección Activa**: Desmarca para desactivar temporalmente

3. Click en **Crear Sección**

### **Editar/Eliminar Secciones**

- Usa los botones **Editar** o **Eliminar** en la tabla de secciones
- Las secciones se ordenan automáticamente por zona y orden

### **Migración desde Formato Antiguo**

Si tienes secciones en el formato antiguo (section_top_1, etc.):

1. Verás un aviso amarillo en la página de administración
2. Click en **Migrar al Nuevo Formato**
3. Tus secciones se convertirán automáticamente
4. Se crea un backup por seguridad

## 📡 API REST

### **Endpoints Disponibles**

#### GET `/wp-json/starter/v1/home-sections`
Obtiene todas las secciones activas

**Respuesta:**
```json
[
  {
    "id": "section_67abc123",
    "layout_type": "horizontal",
    "grid_type": "wide",
    "zone": "top",
    "category_id": 25,
    "category_name": "Rosas",
    "category_slug": "rosas",
    "title": "Nuestras Rosas Más Vendidas",
    "subtitle": "Frescas y hermosas",
    "limit": 8,
    "min_products": 3,
    "order": 0
  }
]
```

#### GET `/wp-json/starter/v1/home-sections/{section_id}`
Obtiene productos de una sección específica

**Respuesta:**
```json
{
  "id": "section_67abc123",
  "layout_type": "horizontal",
  "grid_type": "wide",
  "zone": "top",
  "category_id": 25,
  "category_name": "Rosas",
  "category_slug": "rosas",
  "title": "Nuestras Rosas Más Vendidas",
  "subtitle": "Frescas y hermosas",
  "products": [...],
  "limit": 8,
  "min_products": 3
}
```

#### POST `/wp-json/starter/v1/home-sections` (Admin)
Crea una nueva sección

**Body:**
```json
{
  "layout_type": "horizontal",
  "zone": "top",
  "category_id": 25,
  "title": "Título personalizado",
  "subtitle": "Subtítulo",
  "random": true,
  "order": 0,
  "enabled": true
}
```

#### PUT `/wp-json/starter/v1/home-sections/{section_id}` (Admin)
Actualiza una sección existente

#### DELETE `/wp-json/starter/v1/home-sections/{section_id}` (Admin)
Elimina una sección

## 🔧 Estructura de Datos

### **Base de Datos**

Las secciones se almacenan en `wp_options`:
- **Clave**: `starter_home_sections_list`
- **Formato**: Array asociativo de secciones

```php
array(
    'section_67abc123' => array(
        'id' => 'section_67abc123',
        'layout_type' => 'horizontal',
        'zone' => 'top',
        'category_id' => 25,
        'title' => 'Título',
        'subtitle' => 'Subtítulo',
        'random' => false,
        'order' => 0,
        'enabled' => true
    )
)
```

## 🎨 Frontend (React)

### **Componentes Actualizados**

- `ProductSections.tsx`: Filtra por `zone` en lugar de prefijos de ID
- `ProductSectionItem.tsx`: Usa `grid_type` y `min_products` del backend
- `ProductGrid.tsx`: Renderiza según `grid_type` (wide/standard/compact)

### **Hook useProductSection**

```typescript
const { section, loading, error } = useProductSection('section_67abc123');

// section contiene:
{
  id: string;
  layout_type: string;
  grid_type: 'standard' | 'wide' | 'compact';
  zone: string;
  category_id: number;
  category_name: string;
  category_slug: string;
  title: string;
  subtitle: string;
  products: Product[];
  limit: number;
  min_products: number;
  order: number;
}
```

## 📝 Ejemplos de Uso

### **Ejemplo 1: Sección Horizontal de Ofertas**
```
Tipo: Horizontal
Zona: Top
Categoría: Ofertas
Título: "Ofertas de la Semana"
Orden: 0
```

### **Ejemplo 2: Dos Secciones Compactas en Bottom (CORRECTO)**
```
Sección 1:
  Tipo: Compacta
  Zona: Bottom
  Categoría: Rosas
  Orden: 0

Sección 2:
  Tipo: Compacta
  Zona: Bottom
  Categoría: Tulipanes
  Orden: 1
```
✅ Se mostrarán lado a lado en desktop (grid 2 columnas)

### **Ejemplo 2b: Mezclar Layouts (CORRECTO)**
```
Sección 1: Compacta - Rosas - Orden 0
Sección 2: Compacta - Tulipanes - Orden 1
Sección 3: Horizontal - Ofertas - Orden 2
```
✅ Las compactas se agrupan en par, la horizontal va debajo a ancho completo

### **Ejemplo 2c: Sección Compacta Sola (ADVERTENCIA)**
```
Sección 1: Compacta - Rosas - Orden 0
```
⚠️ Se mostrará a ancho completo hasta que agregues su par

### **Ejemplo 3: Múltiples Secciones en Middle**
```
Sección 1: Horizontal - Categoría A - Orden 0
Sección 2: Estándar - Categoría B - Orden 1
Sección 3: Horizontal - Categoría C - Orden 2
```

## 🔒 Seguridad

- Todos los endpoints POST/PUT/DELETE requieren permisos de administrador
- Sanitización de inputs con `sanitize_text_field()`
- Validación de tipos de layout y zonas
- Nonces para formularios

## 🐛 Troubleshooting

### **Las secciones no aparecen en el frontend**

1. Verifica que la sección esté **activa** (checkbox marcado)
2. Verifica que la categoría tenga productos **en stock**
3. Verifica que haya suficientes productos (mínimo según layout)
4. Limpia caché de W3 Total Cache

### **Error "Sección no encontrada"**

- Verifica que el `section_id` existe en la base de datos
- Ejecuta la migración si tienes secciones antiguas

### **Productos no se muestran**

- Verifica que los productos estén publicados
- Verifica que tengan `stock_status = 'instock'`
- Revisa los logs de WordPress para errores

## 📊 Ventajas del Nuevo Sistema

✅ **Escalabilidad**: Crea ilimitadas secciones sin tocar código
✅ **Flexibilidad**: 3 tipos de layout + 3 zonas = 9 combinaciones
✅ **Control de Orden**: Define exactamente dónde aparece cada sección
✅ **Activación/Desactivación**: Oculta secciones temporalmente sin eliminarlas
✅ **API REST Completa**: CRUD completo para integraciones
✅ **Migración Segura**: Convierte secciones antiguas con un click
✅ **Type Safety**: Frontend TypeScript con interfaces tipadas

## 🔄 Changelog

### v2.0.0 (Actual)
- Sistema completamente escalable
- Tipos de layout configurables
- Zonas dinámicas
- API REST CRUD completa
- Migración automática desde v1.0
- Interfaz de administración mejorada

### v1.0.0 (Antiguo)
- 6 secciones hardcodeadas
- Límites fijos de productos
- Sin API de gestión
