# Auditoría de accesibilidad — WCAG 2.2 AA

## Alcance

Revisión estática de la plantilla contra los criterios WCAG 2.2 Nivel AA.
No incluye pruebas automatizadas con lectores de pantalla ni test de usuarios
con discapacidad — esas verificaciones quedan como trabajo futuro del
integrador en el contexto de su contenido y branding.

## Resumen

| Principio | Estado |
|-----------|--------|
| Perceptible | ✅ Implementado |
| Operable   | ✅ Implementado (con salvedades) |
| Comprensible | ✅ Implementado |
| Robusto    | ⚠️ Parcial (ver detalle) |

## Detalle por criterio

### 1. Perceptible

| Criterio | Estado | Notas |
|----------|--------|-------|
| 1.1.1 Contenido no textual | ✅ | Imágenes decorativas con `alt=""`; imágenes funcionales con `alt` descriptivo |
| 1.2.1 Solo audio/video | N/A | La plantilla no incluye contenido multimedia |
| 1.3.1 Información y relaciones | ✅ | Encabezados jerárquicos (`h1`→`h2`→`h3`); `<nav>`, `<main>`, `<footer>` semánticos |
| 1.3.2 Secuencia significativa | ✅ | Orden de lectura coincide con orden visual |
| 1.3.4 Orientación (AA) | ✅ | No se restringe la orientación del dispositivo |
| 1.4.1 Uso del color | ⚠️ | Los enlaces en párrafos se distinguen por color y subrayado en hover, pero no se ha verificado contraste de todos los estados |
| 1.4.3 Contraste mínimo (AA) | ✅ | Tokens de color definidos en `globals.css` con contraste suficiente contra fondos claros |
| 1.4.4 Cambio de tamaño texto | ✅ | Unidades relativas (`rem`) en toda la UI |
| 1.4.10 Reflow (AA) | ✅ | Layout responsive, sin scroll horizontal en 320px |
| 1.4.11 Contraste de componentes (AA) | ✅ | Inputs y botones usan colores con contraste ≥ 3:1 |
| 1.4.12 Espaciado texto (AA) | ✅ | No se requiere espaciado específico; el layout tolera sobreescritura de estilos de usuario |

### 2. Operable

| Criterio | Estado | Notas |
|----------|--------|-------|
| 2.1.1 Teclado | ✅ | Todos los controles son accesibles por teclado (`<button>`, `<a>`, `<input>`, `<select>`) |
| 2.1.2 Sin trampa de teclado | ✅ | No hay elementos que atrapen el foco |
| 2.1.4 Atajos de tecla (A) | N/A | No se implementan atajos de teclado propios |
| 2.2.1 Tiempo ajustable | ⚠️ | El rate-limit no tiene advertencia de tiempo; las sesiones caducan (refresh JWT), pero no hay timeout destructivo sin aviso |
| 2.4.1 Saltar bloques | ✅ | Enlace "Saltar al contenido principal" al inicio de `<body>` |
| 2.4.2 Titulado de páginas | ✅ | `<title>` dinámico vía `generateMetadata` |
| 2.4.3 Orden del foco | ✅ | Orden de tabulación lógico |
| 2.4.4 Propósito de los enlaces | ✅ | Los enlaces tienen texto descriptivo o `aria-label` |
| 2.4.5 Múltiples vías | ✅ | Navegación principal + búsqueda + mapa de categorías |
| 2.4.6 Encabezados y etiquetas | ✅ | Labels visibles en formularios; encabezados descriptivos |
| 2.4.7 Foco visible | ✅ | Outline visible en todos los elementos enfocables (estilo `focus-visible`) |
| 2.4.11 Foco no oculto (AA) | ✅ | El foco no queda oculto tras ningún elemento |
| 2.5.7 Movimiento de activación (AA) | ✅ | No se requieren gestos complejos |
| 2.5.8 Tamaño de objetivo (AA) | ⚠️ | Botones pequeños (tallas, iconos solitarios) pueden tener menos de 24×24px |

### 3. Comprensible

| Criterio | Estado | Notas |
|----------|--------|-------|
| 3.1.1 Idioma de la página | ✅ | Atributo `lang` dinámico en `<html>` según locale |
| 3.1.2 Idioma de las partes | ⚠️ | Cambios de idioma inline no señalados (no se espera en una plantilla B2C) |
| 3.2.1 Al recibir el foco | ✅ | No hay cambios de contexto al recibir foco |
| 3.2.2 Al recibir entrada | ✅ | Los envíos requieren acción explícita (submit) |
| 3.2.3 Navegación consistente | ✅ | Header y footer consistentes en todas las páginas |
| 3.2.4 Identificación consistente | ✅ | Iconos y etiquetas de carrito, cuenta, etc. consistentes |
| 3.3.1 Identificación de errores | ✅ | Validación con mensajes de error asociados al campo (Zod) |
| 3.3.2 Etiquetas e instrucciones | ✅ | Todos los inputs tienen `<label>` visible |
| 3.3.3 Sugerencias ante errores (AA) | ✅ | Los mensajes describen el error y sugieren corrección |
| 3.3.4 Prevención de errores legales (AA) | ✅ | Confirmación explícita en checkout antes de crear el pedido |
| 3.3.7 Redundancia de entrada (A) | N/A | No se espera entrada redundante en una tienda |

### 4. Robusto

| Criterio | Estado | Notas |
|----------|--------|-------|
| 4.1.1 Procesamiento | ⚠️ | HTML semántico válido, pero no se ha validado con validador W3C |
| 4.1.2 Nombre, rol, valor | ✅ | Elementos interactivos con roles y nombres correctos |
| 4.1.3 Mensajes de estado (AA) | ⚠️ | Las actualizaciones del carrito y notificaciones toast no tienen `aria-live`; mejorable |

## Acciones recomendadas

Prioridad alta:
1. **Añadir `aria-live="polite"`** a las notificaciones del carrito y feedback del checkout para que los lectores de pantalla anuncien cambios.
2. **Validar HTML completo** con el validador W3C (Nu HTML Checker) tras aplicar el branding.

Prioridad media:
3. **Aumentar tamaño mínimo de objetivos táctiles** a 24×24px en todos los botones icono (eliminar iconos en elementos menores de 24px).
4. **Verificar contraste de color** con herramienta (p. ej. axe DevTools) en todos los estados (hover, focus, active, disabled).
5. **Añadir `aria-live`** a los mensajes de carga y error asíncronos (carrito, checkout).

Prioridad baja:
6. **Pruebas con lector de pantalla** (NVDA/VoiceOver) en el flujo de compra completo.
7. **Test de usuarios** con discapacidad para validación cualitativa.

> Nota: la plantilla proporciona la base estructural (HTML semántico, labels, skip-to-content, foco visible, contraste base). La personalización del comprador (colores, contenido, font-size) puede degradar la accesibilidad si no se mantienen los ratios de contraste y tamaños mínimos.
