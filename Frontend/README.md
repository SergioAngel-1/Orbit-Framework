# 🌸 Flores INC - Frontend E-Commerce

Frontend moderno y escalable para la tienda en línea de Flores INC, desarrollado con **React 18**, **TypeScript**, **Tailwind CSS** y arquitectura headless, integrado con WordPress/WooCommerce mediante APIs REST personalizadas.

## 🎯 Características Principales

### 🎨 **Interfaz y Experiencia de Usuario**
- ✅ Diseño moderno y completamente responsive (Mobile-First)
- ✅ Animaciones fluidas con GSAP y CSS Transitions
- ✅ Sistema de notificaciones con Alertify.js
- ✅ Lazy loading de imágenes y componentes
- ✅ Skeleton loaders para mejor UX
- ✅ Modo oscuro preparado (variables CSS)

### 🛒 **E-Commerce Avanzado**
- ✅ Carrito híbrido con persistencia localStorage + servidor
- ✅ Sincronización automática entre dispositivos
- ✅ Productos variables con selector de atributos
- ✅ Sistema de stock en tiempo real
- ✅ Checkout optimizado con validación en tiempo real
- ✅ Gestión de cupones y descuentos

### 🔐 **Autenticación y Seguridad**
- ✅ Sistema JWT integrado con WordPress
- ✅ Gestión de sesiones con refresh tokens
- ✅ Rate limiting en endpoints críticos
- ✅ Validación de formularios con sanitización
- ✅ Protección CSRF y XSS

### 👥 **Sistema de Referidos y Gamificación**
- ✅ Códigos de referido únicos por usuario
- ✅ Tracking de referidos con cookies (30 días)
- ✅ Billetera virtual (Flores Coins)
- ✅ Transferencias P2P entre usuarios
- ✅ Sistema de recompensas por compras

### 📊 **Optimización y Performance**
- ✅ Code splitting automático con Vite
- ✅ Prefetching de rutas críticas
- ✅ Caché de API con estrategias personalizadas
- ✅ Compresión de imágenes con proxy
- ✅ Bundle size optimizado (< 500KB inicial)

## Requisitos Previos

- Node.js (v14 o superior)
- npm o yarn
- WordPress con WooCommerce instalado y configurado
- Plugin FloresInc Referrals & Points instalado en WordPress

## Instalación

1. Clonar el repositorio:
```bash
git clone <url-del-repositorio>
cd floresinc-project
```

2. Instalar dependencias:
```bash
npm install
# o
yarn
```

3. Configurar variables de entorno:
Crea un archivo `.env` en la raíz del proyecto con las siguientes variables:
```
VITE_WP_API_URL=http://tu-wordpress.local/wp-json
VITE_WC_CONSUMER_KEY=tu_consumer_key
VITE_WC_CONSUMER_SECRET=tu_consumer_secret
```

4. Iniciar el servidor de desarrollo:
```bash
npm run dev
# o
yarn dev
```

## 🏗️ Arquitectura del Proyecto

### **Stack Tecnológico**

```
Frontend Framework:     React 18.3.1
Language:              TypeScript 5.5.3
Build Tool:            Vite 5.4.1
Styling:               Tailwind CSS 3.4.1
Routing:               React Router DOM 6.26.2
State Management:      React Context API + Custom Hooks
HTTP Client:           Axios 1.7.7
Animations:            GSAP 3.12.5
Notifications:         Alertify.js 1.14.0
OAuth:                 OAuth-1.0a 2.2.6
```

### **Estructura del Proyecto**

```
src/
├── components/                    # Componentes reutilizables (139 archivos)
│   ├── auth/                      # Autenticación (Login, Register, Modals)
│   ├── cart/                      # Carrito de compras
│   ├── common/                    # Componentes comunes (ErrorBoundary, ScrollToTop)
│   ├── home/                      # Componentes de página de inicio
│   ├── layout/                    # Layout principal (Header, Footer, Sidebar)
│   ├── modals/                    # Sistema de modales
│   ├── products/                  # Productos y catálogo
│   │   ├── variations/            # Sistema de variaciones de productos
│   │   ├── ProductCard.tsx        # Tarjeta de producto
│   │   ├── ProductDetailContent.tsx
│   │   ├── VariationSelector.tsx  # Selector de atributos
│   │   └── RelatedProducts.tsx
│   ├── profile/                   # Perfil de usuario
│   │   ├── sections/              # Secciones del perfil
│   │   ├── AddressForm.tsx        # Formulario de direcciones
│   │   └── OrderHistory.tsx       # Historial de pedidos
│   └── ui/                        # Componentes UI base
│       ├── Button.tsx
│       ├── Input.tsx
│       ├── Modal.tsx
│       ├── Loader.tsx
│       └── VerMasButton.tsx
│
├── contexts/                      # Context API de React
│   ├── AuthContext.tsx            # Estado de autenticación
│   ├── CartContext.tsx            # Estado del carrito (híbrido)
│   ├── ModalContext.tsx           # Gestión de modales
│   ├── types/                     # Tipos de contextos
│   └── utils/                     # Utilidades de contextos
│       ├── auth.utils.ts          # Helpers de autenticación
│       ├── cart.utils.ts          # Helpers del carrito
│       └── address.utils.ts       # Helpers de direcciones
│
├── hooks/                         # Custom Hooks (12 archivos)
│   ├── useAuth.ts                 # Hook de autenticación
│   ├── useCart.ts                 # Hook del carrito
│   ├── useCartSync.ts             # Sincronización de carrito
│   ├── useWooCommerce.ts          # Hook de WooCommerce
│   ├── useProductSection.ts       # Secciones de productos
│   ├── useDebounce.ts             # Debouncing
│   └── useIntersectionObserver.ts # Lazy loading
│
├── pages/                         # Páginas de la aplicación (17 archivos)
│   ├── HomePage.tsx               # Página principal
│   ├── ShopPage.tsx               # Catálogo de productos
│   ├── ProductDetailPage.tsx      # Detalle de producto
│   ├── CartPage.tsx               # Carrito de compras
│   ├── CheckoutPage.tsx           # Proceso de pago
│   ├── LandingPage.tsx            # Landing de login/registro
│   ├── ReferidosPage.tsx          # Sistema de referidos
│   ├── CatalogPage.tsx            # Catálogos PDF
│   └── NotFoundPage.tsx           # Página 404
│
├── services/                      # Servicios de API (44 archivos)
│   ├── apiConfig.ts               # Configuración de Axios
│   ├── apiServices.ts             # Exportaciones centralizadas
│   ├── auth/                      # Servicios de autenticación
│   ├── cart/                      # Servicios de carrito
│   │   ├── userCartApiService.ts  # API de carrito en servidor
│   │   └── hybridCartService.ts   # Servicio híbrido
│   ├── products/                  # Servicios de productos
│   ├── orders/                    # Servicios de pedidos
│   ├── points/                    # Sistema de puntos
│   └── query/                     # Query services
│
├── types/                         # Definiciones TypeScript
│   ├── woocommerce.ts             # Tipos de WooCommerce
│   ├── auth.types.ts              # Tipos de autenticación
│   └── cart.types.ts              # Tipos del carrito
│
├── utils/                         # Utilidades
│   ├── formatters.ts              # Formateadores (moneda, fechas)
│   ├── validators.ts              # Validadores de formularios
│   ├── logger.ts                  # Sistema de logging
│   └── constants.ts               # Constantes de la app
│
├── styles/                        # Estilos globales
│   ├── animations.css             # Animaciones CSS
│   ├── components.css             # Estilos de componentes
│   └── utilities.css              # Utilidades de Tailwind
│
├── App.tsx                        # Componente raíz
├── main.tsx                       # Punto de entrada
└── index.css                      # Estilos base + Tailwind
```

## 🔧 Funcionalidades Técnicas Detalladas

### **1. Sistema de Carrito Híbrido** 🛒

El carrito implementa una arquitectura híbrida que combina **localStorage** (offline-first) con **persistencia en servidor** (user meta de WordPress).

#### **Arquitectura del Carrito**

```
Usuario → CartContext → hybridCartService → {
  ├─ localStorage (caché local, respuesta inmediata)
  └─ userCartApiService → WordPress User Meta (/floresinc/v1/cart)
}
```

#### **Flujo de Operaciones**

**Añadir Producto:**
```typescript
1. Usuario hace clic en "Añadir al carrito"
2. CartContext.addItem() → hybridCartService.addItem()
3. Actualiza localStorage inmediatamente (optimistic update)
4. Muestra alerta de éxito
5. Si está autenticado: sincroniza con servidor en background
6. Usuario ve el producto en el carrito sin delay
```

**Inicio de Sesión:**
```typescript
1. Usuario inicia sesión exitosamente
2. useCartSync detecta cambio de isAuthenticated
3. Llama a hybridCartService.recoverCartOnLogin()
4. Obtiene carrito local y carrito del servidor
5. Merge inteligente:
   - Local + Servidor → Combinar (sumar cantidades)
   - Solo Local → Subir al servidor
   - Solo Servidor → Descargar a local
6. Guarda resultado en ambos lados
7. Muestra notificación de sincronización
```

#### **Servicios del Carrito**

**`userCartApiService.ts`** - API de persistencia en servidor
```typescript
- getUserCart(): Obtiene carrito desde user meta
- saveUserCart(items): Guarda carrito en user meta
- clearUserCart(): Limpia carrito del servidor
```

**`hybridCartService.ts`** - Lógica híbrida
```typescript
- getLocalItems(): Lee de localStorage
- saveLocalItems(): Guarda en localStorage
- fetchServerCart(): Obtiene y sincroniza desde servidor
- syncToServer(): Sube cambios locales al servidor
- recoverCartOnLogin(): Merge al iniciar sesión
- mergeCartItems(): Combina dos carritos
```

**`CartContext.tsx`** - Estado global
```typescript
- items: CartItem[]
- addItem(): async - Añade producto
- updateItemQuantity(): async - Actualiza cantidad
- removeItem(): async - Elimina producto
- clearCart(): async - Vacía carrito
- recoverCart(): async - Recupera al login
- isSyncing: boolean - Estado de sincronización
```

#### **Ventajas del Sistema Híbrido**

✅ **Offline-First**: Funciona sin conexión  
✅ **Sincronización Automática**: Entre dispositivos  
✅ **Optimistic Updates**: Respuesta inmediata  
✅ **Recuperación de Carrito**: Al cambiar de dispositivo  
✅ **Merge Inteligente**: Sin pérdida de datos  

---

### **2. Sistema de Autenticación JWT** 🔐

#### **Flujo de Autenticación**

```typescript
1. Login → POST /jwt-auth/v1/token
2. Recibe: { token, user_email, user_nicename, user_display_name }
3. Guarda token en localStorage
4. Configura header: Authorization: Bearer {token}
5. Todas las peticiones incluyen el token automáticamente
```

#### **AuthContext - Gestión de Estado**

**Estados:**
```typescript
- isAuthenticated: boolean
- user: User | null
- loading: boolean
- error: string | null
- isPending: boolean (cuenta pendiente de aprobación)
- showRejectedModal: boolean
```

**Métodos:**
```typescript
- login(email, password): Promise<LoginResult>
- logout(): void
- register(userData): Promise<void>
- updateProfile(data): Promise<void>
- refreshUser(): Promise<void>
```

#### **Manejo de Estados Especiales**

**Cuenta Pendiente:**
```typescript
- Usuario registrado pero no aprobado
- Muestra modal de "Cuenta Pendiente"
- Permite ver información pero no comprar
```

**Cuenta Rechazada:**
```typescript
- Usuario rechazado por administrador
- Muestra modal explicativo
- Opción de contactar soporte
```

---

### **3. Sistema de Productos Variables** 📦

#### **VariationSelector Component**

Maneja productos con múltiples atributos (talla, color, etc.)

**Características:**
- ✅ Selector dinámico de atributos
- ✅ Validación de combinaciones disponibles
- ✅ Actualización de precio en tiempo real
- ✅ Gestión de stock por variación
- ✅ Imágenes específicas por variación

**Flujo de Selección:**
```typescript
1. Usuario selecciona atributo (ej: Talla → M)
2. Sistema filtra variaciones compatibles
3. Actualiza opciones disponibles de otros atributos
4. Cuando todos los atributos están seleccionados:
   - Encuentra variación exacta
   - Actualiza precio
   - Actualiza stock disponible
   - Habilita botón "Añadir al carrito"
```

**Estructura de Datos:**
```typescript
interface ProductVariation {
  id: number;
  attributes: { name: string; option: string }[];
  price: string;
  regular_price: string;
  stock_quantity: number;
  stock_status: 'instock' | 'outofstock';
  image: Image;
}
```

---

### **4. Sistema de Búsqueda Inteligente** 🔍

#### **Algoritmo de Búsqueda**

**Puntuación por Relevancia:**
```typescript
1. Coincidencia exacta en nombre: +10,000 puntos
2. Nombre empieza con término: +5,000 puntos
3. Nombre contiene término: +1,000 puntos
4. Descripción contiene término: +500 puntos
5. Categoría contiene término: +300 puntos
6. SKU contiene término: +200 puntos
7. Producto en stock: +100,000 puntos base
```

**Características:**
- ✅ Búsqueda en tiempo real con debounce (300ms)
- ✅ Búsqueda en múltiples campos
- ✅ Priorización de productos en stock
- ✅ Normalización de texto (acentos, mayúsculas)
- ✅ Filtro de productos agotados (opcional)

---

### **5. Optimización de Imágenes** 🖼️

#### **Image Proxy Service**

Proxy personalizado para optimizar imágenes de WooCommerce:

```typescript
imageProxyService.getProxiedImageUrl(originalUrl, options)
```

**Opciones:**
- `width`: Ancho deseado
- `height`: Alto deseado
- `quality`: Calidad (1-100)
- `format`: 'webp' | 'jpg' | 'png'

**Ventajas:**
- ✅ Conversión automática a WebP
- ✅ Redimensionamiento on-the-fly
- ✅ Compresión optimizada
- ✅ Caché de imágenes procesadas
- ✅ Lazy loading automático

---

### **6. Gestión de Estado Global** 🌐

#### **Contextos Principales**

**AuthContext:**
- Estado de autenticación
- Información del usuario
- Gestión de sesión

**CartContext:**
- Items del carrito
- Totales y subtotales
- Cupones aplicados
- Sincronización con servidor

**ModalContext:**
- Gestión centralizada de modales
- Prevención de múltiples modales
- Animaciones de entrada/salida

---

### **7. Sistema de Logging** 📝

#### **Logger Utility**

Sistema de logging categorizado para debugging:

```typescript
logger.info('Component', 'Mensaje', data);
logger.warn('Component', 'Advertencia', data);
logger.error('Component', 'Error', error);
logger.debug('Component', 'Debug', data);
```

**Características:**
- ✅ Categorización por componente
- ✅ Niveles de log (info, warn, error, debug)
- ✅ Timestamps automáticos
- ✅ Formato consistente
- ✅ Desactivable en producción

---

## Principales Componentes

### Autenticación y Perfil

- `AuthContext`: Gestiona el estado de autenticación del usuario
- `ProfileModal`: Modal para gestionar el perfil del usuario
- `ProfileSection`: Sección para editar información personal
- `AddressesSection`: Gestión de direcciones del usuario
- `OrdersSection`: Historial de pedidos del usuario

### Sistema de Referidos y Billetera

- `WalletModal`: Modal para gestionar la billetera virtual
- `ReferralSection`: Sección para compartir y gestionar referidos
- `ReferralLink`: Componente para generar enlaces de referido
- `TransferCoins`: Formulario para transferir Flores Coins

### Carrito y Checkout

- `CartModal`: Modal del carrito de compras
- `AddToCartButton`: Botón para añadir productos al carrito
- `CheckoutPage`: Página de proceso de pago
- `CartService`: Servicio para gestionar el carrito en localStorage

### Productos y Catálogo

- `ProductCard`: Tarjeta de producto para listados
- `ProductDetailPage`: Página de detalle de producto
- `CategoryPage`: Página de categoría de productos
- `RelatedProducts`: Componente de productos relacionados

## Rutas Disponibles

- `/` - Página de inicio
- `/tienda` - Catálogo de productos
- `/categoria/:slug` - Productos por categoría
- `/producto/:slug` - Detalle de producto
- `/carrito` - Carrito de compras
- `/checkout` - Proceso de pago
- `/contacto` - Página de contacto
- `/blog` - Blog de la tienda
- `/legal/:page` - Páginas legales (términos, privacidad, etc.)
- `/login` - Página de inicio de sesión (con soporte para referidos)
- `/register` - Página de registro (con soporte para referidos)

## Sistema de Referidos

El sistema de referidos funciona de la siguiente manera:

1. Cada usuario tiene un código único de referido generado automáticamente
2. Los usuarios pueden compartir un enlace con su código (ej: `http://dominio?ref=CODIGO`)
3. Cuando un nuevo usuario accede a través de ese enlace:
   - Es dirigido al formulario de registro con el código ya completado
   - Se muestra el nombre del referidor para confirmar quién los invitó
4. Al registrarse un nuevo usuario con código de referido:
   - Se establece la relación entre referidor y referido
   - Se generan Flores Coins para el referidor cuando el referido es aprobado

## Sistema de Referidos y Moneda Virtual (Flores Coins)

### Sistema de Referidos

El sistema de referidos es una funcionalidad clave que permite a los usuarios invitar a otros y obtener recompensas. Su implementación incluye:

#### Componentes Frontend
- **Generación de Enlaces**: Los enlaces de referido se generan automáticamente en el perfil del usuario
- **Persistencia de Cookies**: Cuando un usuario accede a través de un enlace de referido, el código se almacena en cookies
- **Pre-llenado de Formulario**: El registro detecta el código de referido en cookies y lo autocompleta
- **Validación Visual**: El sistema muestra el nombre del referidor durante el registro para confirmar la relación
- **Interfaz de Usuarios Referidos**: Cada usuario puede ver una lista de las personas que ha referido

#### Flujo Completo del Sistema de Referidos
1. **Generación de Código**: Al registrarse, cada usuario recibe un código alfanumérico único
2. **Compartir**: El usuario comparte su enlace personalizado (URL + parámetro `ref=CODIGO`)
3. **Aterrizaje**: Al hacer clic en el enlace, el visitante es redirigido a la página principal
   - Si el visitante no está autenticado, se le dirige al formulario de registro
   - El código de referido se almacena en una cookie por 30 días
4. **Registro**: Al registrarse, el sistema:
   - Detecta el código almacenado en la cookie
   - Valida que el código corresponda a un usuario existente
   - Vincula al nuevo usuario con su referidor en la base de datos
   - Coloca al usuario en estado "pendiente de aprobación"
5. **Aprobación**: Cuando un administrador aprueba al usuario:
   - Se verifica nuevamente la relación de referido
   - Se asignan Flores Coins al referidor como recompensa
   - Ambos usuarios reciben notificaciones del proceso

### Moneda Virtual (Flores Coins)

La moneda virtual Flores Coins es un sistema de puntos de fidelidad que permite múltiples interacciones económicas dentro de la plataforma:

#### Características Técnicas
- **Persistencia**: El balance se almacena en la base de datos y se sincroniza con el estado de React
- **Precision**: Todos los valores se manejan como enteros para evitar problemas de precisión
- **Transacciones Atómicas**: Se utilizan transacciones SQL para garantizar la integridad
- **Historial Completo**: Cada operación genera un registro de transacción con timestamp

#### Formas de Obtener Flores Coins
1. **Registro**: Bonificación inicial al registrarse
2. **Referidos**: Recompensa cuando un referido es aprobado
3. **Compras**: Porcentaje del valor de cada compra realizada
4. **Transferencias**: Recibidas de otros usuarios
5. **Eventos Especiales**: Campañas y promociones específicas

#### Uso de Flores Coins
1. **Transferencias**: Envío a otros usuarios mediante su código de referido
2. **Descuentos**: Redimir en el proceso de checkout para obtener descuentos
3. **Productos Exclusivos**: Acceso a productos que solo pueden comprarse con Flores Coins

#### Billetera Virtual (Wallet)
El componente `WalletModal` proporciona una interfaz completa para la gestión de Flores Coins:

- **Panel Superior**: Muestra el balance actual y un resumen de movimientos
- **Sección de Transferencia**:
  - Campo para ingresar el código de referido del destinatario
  - Validación en tiempo real del código (muestra el nombre del destinatario)
  - Control numérico para ingresar el monto a transferir
  - Campo opcional para añadir notas a la transferencia
  - Botón de confirmación con validaciones de saldo suficiente
- **Feedback de Operaciones**: Notificaciones visuales de éxito o error
- **Prevención de Errores**: Bloqueo de transferencias a sí mismo o a usuarios inexistentes

#### Implementación Técnica de la Billetera
- **Estado Local**: Gestión del formulario con React useState
- **Validación Asíncrona**: Los códigos de referido se validan en tiempo real contra la API
- **Actualización Optimista**: El balance se actualiza inmediatamente en la interfaz
- **Manejo de Errores**: Sistema robusto de captura y visualización de errores
- **Animaciones**: Transiciones suaves entre estados con CSS Animations

#### Integración con el Resto del Sistema
- **Header**: Botón dedicado para acceder rápidamente a la billetera
- **Perfil**: Sección que muestra el historial de transferencias y balance
- **Checkout**: Opción para usar Flores Coins como método de pago parcial
- **API Service**: Módulo dedicado para todas las operaciones relacionadas con la billetera

### Seguridad del Sistema
- **Validación en Ambos Extremos**: Tanto en frontend como en backend
- **Prevención de Transferencias Negativas**: Validación de montos positivos
- **Verificación de Saldo**: Comprobación de saldo suficiente antes de cualquier operación
- **Protección contra Ataques**: Limitación de solicitudes por tiempo
- **Autenticación Requerida**: Todas las operaciones requieren usuario autenticado
- **Logs Detallados**: Registro de todas las operaciones para auditoría

## Integración con WooCommerce y APIs Personalizadas

El proyecto utiliza:
- API REST de WooCommerce para productos, categorías, carrito y pedidos
- API personalizada para el sistema de referidos y Flores Coins
- Autenticación mediante JWT con WordPress

### Servicios API

- `productService`: Gestión de productos
- `categoryService`: Gestión de categorías
- `cartService`: Gestión del carrito
- `orderService`: Gestión de pedidos
- `authService`: Autenticación y gestión de usuarios
- `referralService`: Gestión de referidos
- `walletService`: Gestión de la billetera virtual

## Estilos y Diseño

- Uso de Tailwind CSS para estilos responsive
- Variables CSS personalizadas para colores de marca
- Animaciones con GSAP para transiciones fluidas
- Diseño adaptable a móviles, tablets y escritorio
- Notificaciones con Alertify.js

## Desarrollo

Este proyecto fue creado con Vite, React y TypeScript. Para más información sobre la configuración de Vite, consulta la [documentación oficial](https://vitejs.dev/guide/).

### Scripts Disponibles

- `npm run dev` - Inicia el servidor de desarrollo
- `npm run build` - Compila el proyecto para producción
- `npm run lint` - Ejecuta el linter
- `npm run preview` - Previsualiza la versión de producción

## Licencia

[MIT](LICENSE)
