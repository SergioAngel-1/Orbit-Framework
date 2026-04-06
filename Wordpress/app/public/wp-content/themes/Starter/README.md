# Starter - WordPress Theme (Headless Backend)

Tema personalizado de WordPress diseñado como **backend headless** para una tienda en línea e-commerce. Proporciona una API REST robusta y escalable que alimenta el frontend React con datos de WooCommerce, gestión de usuarios, sistema de referidos y funcionalidades personalizadas.

## 🎯 Características Principales

### 🔌 **API REST Extendida**
- ✅ Endpoints personalizados para funcionalidades específicas
- ✅ Integración completa con WooCommerce API
- ✅ Autenticación JWT para frontend headless
- ✅ CORS configurado para peticiones cross-origin
- ✅ Rate limiting para prevenir abuso
- ✅ Optimización de consultas SQL

### 👥 **Gestión Avanzada de Usuarios**
- ✅ Sistema de aprobación manual de usuarios
- ✅ Roles personalizados (Cliente, Gestor de Tienda)
- ✅ Gestión de múltiples direcciones por usuario
- ✅ Perfil extendido con campos personalizados
- ✅ Historial completo de pedidos

### 🎁 **Sistema de Referidos y Puntos**
- ✅ Códigos de referido únicos automáticos
- ✅ Tracking de referidos con relaciones en BD
- ✅ Moneda virtual (Virtual Coins)
- ✅ Transferencias P2P entre usuarios
- ✅ Historial de transacciones

### 📧 **Sistema de Emails Personalizado**
- ✅ Templates HTML responsivos
- ✅ Emails transaccionales (pedidos, aprobaciones)
- ✅ Notificaciones de sistema
- ✅ Branding personalizado

### 🛡️ **Seguridad y Performance**
- ✅ Sanitización de inputs
- ✅ Prepared statements en SQL
- ✅ Rate limiting por IP
- ✅ Validación de permisos en endpoints
- ✅ Logs de seguridad

## 🏗️ Estructura del Tema

### **Stack Tecnológico Backend**

```
CMS:                   WordPress 6.4+
E-Commerce:            WooCommerce 8.0+
Language:              PHP 8.0+
Database:              MySQL 8.0+
Authentication:        JWT (JSON Web Tokens)
Email:                 WordPress Mail + SMTP
Caching:               Object Cache (Redis compatible)
```

### **Estructura de Archivos**

```
Starter/
├── inc/                                    # Módulos funcionales (40+ archivos)
│   │
│   ├── 🔐 AUTENTICACIÓN Y USUARIOS
│   ├── custom-auth-endpoint.php           # Endpoint JWT personalizado
│   ├── user-management/                   # Gestión de usuarios
│   │   ├── user-approval.php              # Sistema de aprobación
│   │   ├── user-rejection.php             # Manejo de rechazos
│   │   ├── user-queries.php               # Consultas optimizadas
│   │   └── user-validation.php            # Validación de datos
│   ├── user-management-endpoint.php       # Endpoint principal de usuarios
│   ├── role-manager-functions.php         # Gestión de roles
│   ├── password-reset/                    # Reset de contraseña
│   │   ├── password-reset-handler.php     # Lógica de reset
│   │   ├── password-reset-validation.php  # Validación
│   │   └── cors.php                       # CORS específico
│   └── password-reset-endpoint.php        # Endpoint de reset
│   │
│   ├── 👤 PERFIL Y DIRECCIONES
│   ├── user-profile/                      # Perfil de usuario
│   │   ├── profile-update.php             # Actualización de perfil
│   │   ├── profile-validation.php         # Validación de datos
│   │   └── profile-fields.php             # Campos personalizados
│   ├── user-profile-endpoint.php          # Endpoint de perfil
│   ├── profile-functions.php              # Funciones auxiliares
│   ├── user-addresses-functions.php       # Gestión de direcciones
│   │
│   ├── 🛒 CARRITO Y PEDIDOS
│   ├── cart-endpoint.php                  # Persistencia de carrito (user meta)
│   ├── woocommerce-functions.php          # Personalización WooCommerce
│   ├── woocommerce-admin-customizations.php # Admin de WooCommerce
│   ├── woocommerce-order-details.php      # Detalles de pedidos
│   ├── woocommerce-orders-customization.php # Personalización pedidos
│   ├── order-email-endpoint.php           # Emails de pedidos
│   │
│   ├── 🎁 SISTEMA DE REFERIDOS (Plugin Starter Referrals & Points)
│   │   # El sistema de referidos está en un plugin separado
│   │   # Ubicación: wp-content/plugins/starter-referrals-points/
│   │
│   ├── 🏠 CONTENIDO Y CATÁLOGO
│   ├── banners/                           # Sistema de banners
│   │   ├── banner-api.php                 # API de banners
│   │   ├── banner-admin.php               # Interfaz admin
│   │   ├── banner-cpt.php                 # Custom Post Type
│   │   └── banner-queries.php             # Consultas
│   ├── banner-functions.php               # Funciones de banners
│   ├── featured-categories-functions.php  # Categorías destacadas
│   ├── promotional-grid/                  # Grilla promocional
│   │   ├── grid-api.php                   # API de grilla
│   │   ├── grid-admin.php                 # Admin
│   │   └── grid-queries.php               # Consultas
│   ├── promotional-grid-endpoint.php      # Endpoint de grilla
│   ├── 📄 PÁGINAS Y CONTENIDO
│   ├── legal-functions.php                # Páginas legales
│   ├── menu/                              # Sistema de menús
│   │   ├── menu-api.php                   # API de menús
│   │   └── menu-queries.php               # Consultas
│   ├── menu-functions.php                 # Funciones de menús
│   ├── contact-endpoint.php               # Formulario de contacto
│   │
│   ├── 🔧 CONFIGURACIÓN Y ADMIN
│   ├── admin-functions.php                # Funciones de admin
│   ├── shop-manager-settings.php          # Configuración gestor tienda
│   ├── init.php                           # Inicialización del tema
│   │
│   ├── 🛡️ SEGURIDAD Y CORS
│   ├── cors-functions.php                 # Configuración CORS global
│   ├── rate-limiting.php                  # Rate limiting por IP
│   │
│   ├── ⚡ OPTIMIZACIÓN
│   ├── api-optimization/                  # Optimización de API
│   │   ├── query-optimization.php         # Optimización de queries
│   │   ├── cache-manager.php              # Gestión de caché
│   │   └── response-compression.php       # Compresión de respuestas
│   └── api-optimization.php               # Loader de optimización
│   │
│   └── 📧 EMAILS
│       └── email-customization/           # Personalización de emails
│           ├── email-templates.php        # Templates HTML
│           ├── email-sender.php           # Envío de emails
│           └── index.php                  # Loader
│
├── functions.php                          # Archivo principal (carga todos los módulos)
├── style.css                              # Estilos del tema
├── index.php                              # Template principal
└── README.md                              # Esta documentación
```

## 🔧 Funcionalidades Técnicas Detalladas

### **1. Sistema de Persistencia de Carrito** 🛒

#### **Endpoint: `/starter/v1/cart`**

Permite guardar y recuperar el carrito del usuario autenticado usando **user meta** de WordPress.

**GET** - Obtener carrito del usuario
```php
// Request
GET /wp-json/starter/v1/cart
Headers: Authorization: Bearer {jwt_token}

// Response
{
  "success": true,
  "items": [
    {
      "id": 123,
      "product": {...},
      "quantity": 2,
      "variation_id": 456
    }
  ],
  "count": 2
}
```

**POST** - Guardar carrito del usuario
```php
// Request
POST /wp-json/starter/v1/cart
Headers: Authorization: Bearer {jwt_token}
Body: {
  "items": [...]
}

// Response
{
  "success": true,
  "message": "Carrito guardado exitosamente",
  "count": 2
}
```

**DELETE** - Limpiar carrito del usuario
```php
// Request
DELETE /wp-json/starter/v1/cart
Headers: Authorization: Bearer {jwt_token}

// Response
{
  "success": true,
  "message": "Carrito limpiado exitosamente"
}
```

#### **Implementación Técnica**

```php
// Guardar en user meta
update_user_meta($user_id, '_starter_cart', $items);

// Recuperar de user meta
$cart_items = get_user_meta($user_id, '_starter_cart', true);
```

**Ventajas:**
- ✅ Persistencia entre dispositivos
- ✅ Recuperación automática al login
- ✅ No depende de sesiones de WordPress
- ✅ Compatible con JWT
- ✅ Sincronización con frontend React

---

### **2. Sistema de Gestión de Usuarios** 👥

#### **Flujo de Registro y Aprobación**

```
1. Usuario se registra → Estado: "pending"
2. Admin recibe notificación por email
3. Admin aprueba/rechaza desde panel de WordPress
4. Usuario recibe email de aprobación/rechazo
5. Si aprobado: Estado → "active", puede comprar
6. Si rechazado: Estado → "rejected", no puede acceder
```

#### **Endpoints de Gestión**

**Aprobar Usuario:**
```php
POST /wp-json/starter/v1/users/approve/{user_id}
Permission: Administrator only

// Acciones automáticas:
- Cambia rol a 'customer'
- Envía email de bienvenida
- Activa cuenta para compras
- Si tiene referidor: asigna Virtual Coins
```

**Rechazar Usuario:**
```php
POST /wp-json/starter/v1/users/reject/{user_id}
Body: {
  "reason": "Motivo del rechazo"
}

// Acciones automáticas:
- Marca cuenta como rechazada
- Envía email explicativo
- Bloquea acceso a la tienda
```

#### **Campos Personalizados de Usuario**

```php
// Meta fields almacenados
- phone: Teléfono
- gender: Género
- birth_date: Fecha de nacimiento
- newsletter: Suscripción a newsletter
- referral_code: Código de referido único
- referred_by: ID del referidor
- approval_status: pending|approved|rejected
- rejection_reason: Motivo de rechazo
```

---

### **3. Gestión de Direcciones** 📍

#### **Estructura de Datos**

```php
// Almacenado en user meta: _starter_addresses
[
  {
    "id": "addr_1234567890",
    "type": "shipping", // o "billing"
    "first_name": "Juan",
    "last_name": "Pérez",
    "address_1": "Calle Principal 123",
    "address_2": "Apto 4B",
    "city": "Bogotá",
    "state": "Cundinamarca",
    "postcode": "110111",
    "country": "CO",
    "phone": "+57 300 1234567",
    "is_default": true
  }
]
```

#### **Endpoints**

```php
GET    /starter/v1/user/addresses        // Listar direcciones
POST   /starter/v1/user/addresses        // Crear dirección
PUT    /starter/v1/user/addresses/{id}   // Actualizar dirección
DELETE /starter/v1/user/addresses/{id}   // Eliminar dirección
POST   /starter/v1/user/addresses/{id}/set-default // Marcar como predeterminada
```

---

### **4. Sistema de CORS** 🌐

#### **Configuración Multi-Origen**

```php
// Orígenes permitidos
$allowed_origins = [
    'http://localhost:5173',           // Desarrollo local
    'http://admin.starter.local',      // Desarrollo Docker
    'https://example.com',            // Producción
    'https://www.example.com',        // Producción con www
    'https://admin.example.com'       // Admin
];

// Headers configurados
Access-Control-Allow-Origin: {origin}
Access-Control-Allow-Credentials: true
Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS
Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With
Access-Control-Max-Age: 3600
```

#### **Manejo de Preflight**

```php
// Respuesta automática a OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    status_header(200);
    exit;
}
```

---

### **5. Rate Limiting** 🛡️

#### **Configuración por Endpoint**

```php
// Límites configurados
$rate_limits = [
    'login'          => ['limit' => 5,  'window' => 300],   // 5 intentos / 5 min
    'register'       => ['limit' => 3,  'window' => 3600],  // 3 intentos / 1 hora
    'password_reset' => ['limit' => 3,  'window' => 3600],  // 3 intentos / 1 hora
    'default'        => ['limit' => 60, 'window' => 60]     // 60 req / 1 min
];
```

#### **Almacenamiento**

```php
// Usa transients de WordPress
set_transient("rate_limit_{$ip}_{$endpoint}", $attempts, $window);
```

#### **Respuesta de Límite Excedido**

```json
{
  "code": "rate_limit_exceeded",
  "message": "Demasiados intentos. Intenta de nuevo en 5 minutos.",
  "data": {
    "status": 429,
    "retry_after": 300
  }
}
```

---

### **6. Optimización de Consultas SQL** ⚡

#### **Estrategias Implementadas**

**1. Caché de Productos:**
```php
// Caché de 1 hora para listados de productos
$cache_key = 'products_' . md5(serialize($args));
$products = get_transient($cache_key);

if (false === $products) {
    $products = wc_get_products($args);
    set_transient($cache_key, $products, HOUR_IN_SECONDS);
}
```

**2. Eager Loading de Relaciones:**
```php
// Precarga de categorías y variaciones
$args = [
    'include' => $product_ids,
    'return' => 'objects',
    'limit' => -1
];
```

**3. Índices de Base de Datos:**
```sql
-- Índices personalizados
CREATE INDEX idx_user_approval ON wp_usermeta(meta_key, meta_value)
WHERE meta_key = 'approval_status';

CREATE INDEX idx_referral_code ON wp_usermeta(meta_key, meta_value)
WHERE meta_key = 'referral_code';
```

---

### **7. Sistema de Emails Personalizado** 📧

#### **Templates Disponibles**

```php
// Ubicación: inc/email-customization/templates/

- user-approved.php          // Usuario aprobado
- user-rejected.php          // Usuario rechazado
- new-user-pending.php       // Notificación a admin
- order-confirmation.php     // Confirmación de pedido
- referral-reward.php        // Recompensa por referido
```

#### **Envío de Emails**

```php
// Función helper
starter_send_email([
    'to' => $user_email,
    'subject' => 'Cuenta Aprobada',
    'template' => 'user-approved',
    'data' => [
        'user_name' => $user->display_name,
        'login_url' => home_url('/login')
    ]
]);
```

#### **Características**

- ✅ Templates HTML responsivos
- ✅ Variables dinámicas
- ✅ Branding personalizado
- ✅ Soporte para imágenes
- ✅ Fallback a texto plano

---

### **8. Personalización de WooCommerce** 🛍️

#### **Campos Personalizados en Productos**

```php
// Meta fields adicionales
- _featured_badge: Badge destacado
- _promo_text: Texto promocional
- _related_products_custom: Productos relacionados manuales
```

#### **Estados de Pedido Personalizados**

```php
// Estados adicionales
- wc-pending-approval: Pendiente de aprobación
- wc-processing-payment: Procesando pago
- wc-ready-to-ship: Listo para envío
```

#### **Hooks Personalizados**

```php
// Modificar respuesta de API
add_filter('woocommerce_rest_prepare_product_object', 
    'customize_product_api_response', 10, 3);

// Validar checkout
add_action('woocommerce_after_checkout_validation', 
    'validate_custom_checkout_fields', 10, 2);
```

---

## 📊 Endpoints API Completos

### **Autenticación**

```
POST   /jwt-auth/v1/token                    # Login (obtener JWT)
POST   /jwt-auth/v1/token/refresh            # Refresh token
POST   /jwt-auth/v1/token/validate           # Validar token
```

### **Usuarios**

```
POST   /wp/v2/users                          # Registro
GET    /wp/v2/users/me                       # Usuario actual
POST   /starter/v1/users/approve/{id}      # Aprobar usuario
POST   /starter/v1/users/reject/{id}       # Rechazar usuario
GET    /starter/v1/users/pending           # Usuarios pendientes
```

### **Perfil**

```
GET    /starter/v1/user/profile            # Obtener perfil
POST   /starter/v1/user/profile            # Actualizar perfil
```

### **Direcciones**

```
GET    /starter/v1/user/addresses          # Listar
POST   /starter/v1/user/addresses          # Crear
PUT    /starter/v1/user/addresses/{id}     # Actualizar
DELETE /starter/v1/user/addresses/{id}     # Eliminar
POST   /starter/v1/user/addresses/{id}/set-default # Predeterminada
```

### **Carrito**

```
GET    /starter/v1/cart                    # Obtener carrito
POST   /starter/v1/cart                    # Guardar carrito
DELETE /starter/v1/cart                    # Limpiar carrito
```

### **Contenido**

```
GET    /starter/v1/banners                 # Banners activos
GET    /starter/v1/featured-categories     # Categorías destacadas
GET    /starter/v1/promotional-grid        # Grilla promocional
GET    /starter/v1/catalogs                # Catálogos PDF
GET    /starter/v1/legal/{page}            # Páginas legales
GET    /starter/v1/menu/{location}         # Menús
```

### **Contacto**

```
POST   /starter/v1/contact                 # Enviar mensaje
```

### **WooCommerce (Nativo)**

```
GET    /wc/v3/products                       # Listar productos
GET    /wc/v3/products/{id}                  # Detalle producto
GET    /wc/v3/products/categories            # Categorías
GET    /wc/v3/orders                         # Pedidos
POST   /wc/v3/orders                         # Crear pedido
```

---

## 🔍 Módulos Técnicos Detallados

### **1. Sistema de Banners Dinámicos** 🎨

#### **Custom Post Type: `banner`**

```php
// Campos personalizados
- banner_image: URL de la imagen
- banner_link: URL de destino
- banner_order: Orden de visualización
- banner_active: Estado activo/inactivo
- banner_location: home|shop|category
- banner_start_date: Fecha de inicio
- banner_end_date: Fecha de fin
```

#### **API Endpoint**

```php
GET /starter/v1/banners?location=home

// Response
{
  "banners": [
    {
      "id": 123,
      "title": "Promoción Verano",
      "image": "https://...",
      "link": "/catalogo/verano",
      "order": 1
    }
  ]
}
```

#### **Características**

- ✅ Programación de banners por fecha
- ✅ Ubicación específica (home, shop, categoría)
- ✅ Orden personalizable
- ✅ Activación/desactivación individual
- ✅ Interfaz admin intuitiva

---

### **2. Categorías Destacadas** ⭐

#### **Endpoint: `/starter/v1/featured-categories`**

```php
GET /starter/v1/featured-categories

// Response
{
  "categories": [
    {
      "id": 45,
      "name": "Rosas",
      "slug": "rosas",
      "image": "https://...",
      "count": 150,
      "featured": true,
      "order": 1
    }
  ]
}
```

#### **Funcionalidades**

- ✅ Selección manual de categorías destacadas
- ✅ Orden personalizable
- ✅ Contador de productos
- ✅ Imágenes optimizadas
- ✅ Caché de 1 hora

---

### **3. Grilla Promocional** 📱

#### **Custom Post Type: `promo_grid_item`**

Sistema flexible para crear grillas promocionales en la home.

```php
// Campos
- grid_image: Imagen del item
- grid_link: URL de destino
- grid_title: Título
- grid_subtitle: Subtítulo
- grid_position: Posición en la grilla (1-6)
- grid_size: small|medium|large
```

#### **Layouts Soportados**

- Grid 2x2
- Grid 3x2
- Grid 2x3
- Layout personalizado

---

### **4. Catálogos PDF** 📚

#### **Custom Post Type: `catalog`**

```php
// Campos
- catalog_pdf: URL del PDF
- catalog_cover: Imagen de portada
- catalog_description: Descripción
- catalog_year: Año del catálogo
- catalog_season: Temporada
- catalog_downloads: Contador de descargas
```

#### **API**

```php
GET /starter/v1/catalogs

// Response
{
  "catalogs": [
    {
      "id": 78,
      "title": "Catálogo Primavera 2024",
      "cover": "https://...",
      "pdf_url": "https://...",
      "downloads": 1250
    }
  ]
}
```

---

### **5. Sistema de Menús Dinámicos** 🧭

#### **Endpoint: `/starter/v1/menu/{location}`**

```php
GET /starter/v1/menu/primary

// Response
{
  "menu": [
    {
      "id": 12,
      "title": "Tienda",
      "url": "/tienda",
      "children": [
        {
          "id": 13,
          "title": "Rosas",
          "url": "/catalogo/rosas"
        }
      ]
    }
  ]
}
```

#### **Ubicaciones de Menú**

- `primary`: Menú principal
- `footer`: Menú del footer
- `mobile`: Menú móvil
- `account`: Menú de cuenta

---

### **6. Páginas Legales** ⚖️

#### **Endpoint: `/starter/v1/legal/{page}`**

```php
GET /starter/v1/legal/privacy-policy

// Response
{
  "title": "Política de Privacidad",
  "content": "<html>...</html>",
  "last_updated": "2024-01-15"
}
```

#### **Páginas Disponibles**

- `privacy-policy`: Política de privacidad
- `terms-conditions`: Términos y condiciones
- `return-policy`: Política de devoluciones
- `shipping-info`: Información de envíos
- `referral-policy`: Política de referidos

---

### **7. Formulario de Contacto** 📬

#### **Endpoint: `/starter/v1/contact`**

```php
POST /starter/v1/contact
Body: {
  "name": "Juan Pérez",
  "email": "juan@example.com",
  "phone": "+57 300 1234567",
  "subject": "Consulta sobre pedido",
  "message": "Hola, tengo una pregunta..."
}

// Response
{
  "success": true,
  "message": "Mensaje enviado exitosamente"
}
```

#### **Características**

- ✅ Validación de campos
- ✅ Sanitización de inputs
- ✅ Envío de email al admin
- ✅ Copia al usuario
- ✅ Rate limiting (3 mensajes/hora)
- ✅ Protección anti-spam

---

### **8. Roles y Permisos Personalizados** 🔑

#### **Roles Definidos**

**Cliente (customer):**
```php
Capabilities:
- read
- edit_posts: false
- delete_posts: false
- upload_files: false
```

**Gestor de Tienda (shop_manager):**
```php
Capabilities:
- manage_woocommerce
- view_woocommerce_reports
- edit_shop_orders
- read_shop_orders
- delete_shop_orders
- publish_shop_orders
- edit_product
- read_product
- delete_product
- edit_products
- publish_products
```

#### **Verificación de Permisos**

```php
// Helper function
function starter_user_can_manage_shop() {
    return current_user_can('manage_woocommerce') || 
           current_user_can('administrator');
}
```

---

## 📋 Requisitos del Sistema

### **Mínimos**
```
WordPress:      6.4+
WooCommerce:    8.0+
PHP:            8.0+
MySQL:          8.0+
Memory Limit:   256M
Max Exec Time:  300s
```

### **Recomendados**
```
WordPress:      6.5+
WooCommerce:    8.5+
PHP:            8.2+
MySQL:          8.0+
Memory Limit:   512M
Max Exec Time:  600s
Redis/Memcached: Para object cache
```

### **Plugins Requeridos**

1. **WooCommerce** (8.0+)
2. **JWT Authentication for WP REST API**
3. **Starter Referrals & Points** (Custom Plugin)

### **Plugins Recomendados**

- **Redis Object Cache**: Para caché de objetos
- **WP Mail SMTP**: Para envío confiable de emails
- **Query Monitor**: Para debugging (solo desarrollo)

---

## 🚀 Instalación

### **1. Instalación Base**

```bash
# 1. Instalar WordPress
# 2. Instalar WooCommerce desde el panel de admin

# 3. Clonar el tema
cd wp-content/themes/
git clone <repo-url> Starter

# 4. Activar el tema desde WordPress Admin
# Apariencia → Temas → Activar Starter
```

### **2. Configuración de JWT**

```bash
# Instalar plugin JWT
cd wp-content/plugins/
git clone https://github.com/Tmeister/wp-api-jwt-auth.git jwt-auth

# Activar desde WordPress Admin
```

**Configurar en `wp-config.php`:**
```php
// JWT Secret Key
define('JWT_AUTH_SECRET_KEY', 'tu-clave-secreta-aqui');
define('JWT_AUTH_CORS_ENABLE', true);
```

**Configurar en `.htaccess`:**
```apache
# Habilitar Authorization Header
RewriteEngine on
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule ^(.*) - [E=HTTP_AUTHORIZATION:%1]

SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

### **3. Configuración de WooCommerce**

```php
// Configurar claves de API
// WooCommerce → Ajustes → Avanzado → REST API

Consumer Key:    ck_xxxxxxxxxxxxxxxx
Consumer Secret: cs_xxxxxxxxxxxxxxxx
Permissions:     Read/Write
```

### **4. Configuración de CORS**

Los orígenes permitidos están en `inc/cors-functions.php`:

```php
$allowed_origins = [
    'http://localhost:5173',      // Desarrollo local
    'https://example.com',       // Producción
    // Agregar más según necesidad
];
```

### **5. Configuración de Emails**

```php
// Recomendado: Usar WP Mail SMTP
// Configurar SMTP en: Ajustes → WP Mail SMTP

// O configurar en wp-config.php
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'tu-email@gmail.com');
define('SMTP_PASS', 'tu-contraseña');
define('SMTP_FROM', 'noreply@example.com');
define('SMTP_NAME', 'Mi Tienda');
```

---

## 👨‍💻 Guía de Desarrollo

### **Estructura de un Endpoint Personalizado**

```php
// 1. Crear archivo en inc/
// Ejemplo: inc/mi-modulo/mi-endpoint.php

<?php
/**
 * Mi Endpoint Personalizado
 */

// Registrar endpoint
add_action('rest_api_init', function () {
    register_rest_route('starter/v1', '/mi-endpoint', array(
        'methods' => 'GET',
        'callback' => 'mi_endpoint_callback',
        'permission_callback' => 'mi_endpoint_permission',
        'args' => array(
            'param1' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function($param) {
                    return !empty($param);
                }
            )
        )
    ));
});

// Callback del endpoint
function mi_endpoint_callback($request) {
    $param1 = $request->get_param('param1');
    
    // Lógica del endpoint
    $result = array(
        'success' => true,
        'data' => $param1
    );
    
    return rest_ensure_response($result);
}

// Verificación de permisos
function mi_endpoint_permission() {
    return is_user_logged_in();
}

// 2. Incluir en functions.php
require_once __DIR__ . '/inc/mi-modulo/mi-endpoint.php';
```

### **Mejores Prácticas**

#### **1. Sanitización y Validación**

```php
// SIEMPRE sanitizar inputs
$email = sanitize_email($request->get_param('email'));
$text = sanitize_text_field($request->get_param('text'));
$html = wp_kses_post($request->get_param('html'));

// SIEMPRE validar
if (!is_email($email)) {
    return new WP_Error('invalid_email', 'Email inválido', array('status' => 400));
}
```

#### **2. Prepared Statements**

```php
// NUNCA hacer esto:
$wpdb->query("SELECT * FROM table WHERE id = $id");

// SIEMPRE usar prepared statements:
$wpdb->prepare("SELECT * FROM table WHERE id = %d", $id);
```

#### **3. Manejo de Errores**

```php
try {
    // Código que puede fallar
    $result = do_something();
    
    if (!$result) {
        throw new Exception('Error al procesar');
    }
    
    return rest_ensure_response(array(
        'success' => true,
        'data' => $result
    ));
    
} catch (Exception $e) {
    error_log('Error en mi_endpoint: ' . $e->getMessage());
    
    return new WP_Error(
        'processing_error',
        $e->getMessage(),
        array('status' => 500)
    );
}
```

#### **4. Caché de Consultas**

```php
// Usar transients para caché
$cache_key = 'my_data_' . $param;
$data = get_transient($cache_key);

if (false === $data) {
    // Consulta pesada
    $data = expensive_query();
    
    // Guardar en caché por 1 hora
    set_transient($cache_key, $data, HOUR_IN_SECONDS);
}

return $data;
```

#### **5. Logging**

```php
// Usar error_log para debugging
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('Debug info: ' . print_r($data, true));
}

// Para producción, usar sistema de logs
do_action('starter_log', 'info', 'Mensaje de log', $context);
```

### **Testing de Endpoints**

```bash
# Obtener JWT Token
curl -X POST http://localhost/wp-json/jwt-auth/v1/token \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"password"}'

# Usar token en peticiones
curl -X GET http://localhost/wp-json/starter/v1/cart \
  -H "Authorization: Bearer {token}"

# Test de endpoint POST
curl -X POST http://localhost/wp-json/starter/v1/cart \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"items":[...]}'
```

---

## 🔧 Mantenimiento

### **Limpieza de Caché**

```php
// Limpiar todos los transients del tema
function starter_clear_cache() {
    global $wpdb;
    
    $wpdb->query(
        "DELETE FROM {$wpdb->options} 
         WHERE option_name LIKE '_transient_starter_%' 
         OR option_name LIKE '_transient_timeout_starter_%'"
    );
}

// Ejecutar desde WP-CLI
wp eval 'starter_clear_cache();'
```

### **Optimización de Base de Datos**

```sql
-- Limpiar revisiones antiguas
DELETE FROM wp_posts WHERE post_type = 'revision' AND post_modified < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- Limpiar transients expirados
DELETE FROM wp_options WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP();
DELETE FROM wp_options WHERE option_name LIKE '_transient_%' AND option_name NOT LIKE '_transient_timeout_%' AND option_name NOT IN (SELECT REPLACE(option_name, '_transient_timeout_', '_transient_') FROM wp_options WHERE option_name LIKE '_transient_timeout_%');

-- Optimizar tablas
OPTIMIZE TABLE wp_posts, wp_postmeta, wp_options, wp_users, wp_usermeta;
```

### **Monitoreo**

```php
// Agregar en functions.php para monitoreo
add_action('rest_api_init', function() {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('API Request: ' . $_SERVER['REQUEST_URI']);
    }
});
```

---

## 📚 Recursos Adicionales

### **Documentación**

- [WordPress REST API](https://developer.wordpress.org/rest-api/)
- [WooCommerce REST API](https://woocommerce.github.io/woocommerce-rest-api-docs/)
- [JWT Authentication](https://github.com/Tmeister/wp-api-jwt-auth)

### **Herramientas de Desarrollo**

- **Postman**: Testing de API
- **Query Monitor**: Debugging de WordPress
- **WP-CLI**: Línea de comandos de WordPress
- **PHPStorm**: IDE recomendado

---

## 🐛 Troubleshooting

### **Error: JWT Token no funciona**

```php
// Verificar en wp-config.php
define('JWT_AUTH_SECRET_KEY', 'clave-secreta');
define('JWT_AUTH_CORS_ENABLE', true);

// Verificar .htaccess
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule ^(.*) - [E=HTTP_AUTHORIZATION:%1]
```

### **Error: CORS bloqueado**

```php
// Verificar origen en inc/cors-functions.php
// Agregar origen del frontend a $allowed_origins
```

### **Error: Rate limit excedido**

```php
// Limpiar rate limits
delete_transient('rate_limit_' . $ip . '_' . $endpoint);
```

---

## 📄 Licencia

MIT License - Ver [LICENSE](LICENSE) para más detalles.

---

## 👥 Contribución

Para contribuir al proyecto:

1. Fork el repositorio
2. Crear branch de feature (`git checkout -b feature/nueva-funcionalidad`)
3. Commit cambios (`git commit -am 'Agregar nueva funcionalidad'`)
4. Push al branch (`git push origin feature/nueva-funcionalidad`)
5. Crear Pull Request

---

## 📞 Soporte

Para soporte técnico:
- Email: soporte@example.com
- Documentación: https://docs.example.com

---

**Desarrollado con ❤️ — E-Commerce Template**
