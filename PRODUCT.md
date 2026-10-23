# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Primario: **agencias y freelancers de desarrollo** que construyen tiendas online para sus
clientes con la licencia de agencia. Su trabajo es entregar e-commerce en producción rápido,
seguro y mantenible, repitiendo la misma base en muchos proyectos sin que cada cliente
contamine la base. Secundario (no prioritario): equipos técnicos in-house con licencia single.

Son lectores técnicos: evalúan el producto leyendo README, docs y código, no folletos.

## Product Purpose

HWE Orbit es un framework comercial de e-commerce headless: WordPress + WooCommerce como
backend sin frontend nativo (WPGraphQL para leer, WC REST/Store API para escribir) y Next.js
como web pública **y** BFF, donde vive toda operación sensible. Existe para que una agencia
arranque cada tienda sobre un núcleo probado (auth, seguridad, pagos, i18n, módulos) y dedique
su tiempo a la capa propia de cada cliente. Éxito: una instancia nueva en producción sin tocar
el núcleo, y actualizaciones del núcleo que no rompen instancias.

## Positioning

La separación **núcleo ↔ instancia** como contrato verificado: el framework posee backend +
BFF + lógica, la instancia vive en rutas reservadas que el framework nunca toca, conectada por
hooks de nombre estable y un `registry` de solo datos; CI (puerta anti-instancia, job
«contrato») impide que nada de un cliente concreto entre al núcleo. Cada tienda gira alrededor
de un centro estable; eso es lo que un tema o starter de WooCommerce no puede afirmar.

## Operating Context

- Se evalúa y usa desde el repositorio: README, `docs/`, `AGENTS.md`, `CHANGELOG.md`,
  `examples/instance-minimal/`.
- Gestión diaria en `/wp-admin` con el mu-plugin **HWE Control Center** como fuente de la
  configuración de negocio.
- Despliegue con Docker Compose (Caddy TLS) o modo híbrido en desarrollo.
- Versionado SemVer; v2.0.0 publicada el 2026-10-14.

## Capabilities and Constraints

- 10 módulos opcionales por instancia (`banners`, `shipping`, `product-landing`, `landings`,
  `consent-log`, `site-reviews`, `coupon-rules`, `customer-identity`, `external-integration`,
  `loyalty`).
- Contenido a N idiomas, paquetes por país, pagos agnósticos (Wompi y Aval Pay de punta a punta).
- Seguridad que falla cerrada: WordPress solo atiende al BFF, CSRF, rate-limit, secretos solo en
  servidor, el pago se prueba con webhook verificado.
- El frontend que se hereda al clonar es un **starter neutro**: la identidad visual de HWE Orbit
  es la del producto/framework, nunca la de las tiendas que se construyen con él.
- Restricción de nombre: el repo no puede contener nombres de instancias concretas (puerta
  anti-instancia de CI).

## Brand Commitments

- Nombre: **Orbit**, bajo la sigla que se conserva: **HWE Orbit** («HWE – Orbit Framework»).
  HWE = Headless Web Ecosystem. La forma exacta de la firma está abierta.
- Mercado internacional desde el inicio: la marca debe funcionar en inglés; la documentación
  sigue en español.
- Autor/titular: Sergio Jauregui. Sin vínculo de marca obligatorio con sergioja.com.
- Libertad total de identidad visual; no existen activos previos (no hay logo).

## Evidence on Hand

- Código y docs reales del framework; CHANGELOG con v2.0.0.
- No hay clientes, testimonios, métricas, precios publicados ni casos de estudio en el repo:
  no se deben inventar.

## Product Principles

1. El núcleo es estable; la instancia es libre. Nada del cliente orbita hacia el centro.
2. Seguridad por defecto, fallo cerrado.
3. El navegador nunca ve secretos; todo lo sensible pasa por el BFF.
4. Contratos explícitos y verificados por CI antes que convenciones implícitas.
5. Honestidad técnica: lo no verificado se dice.
