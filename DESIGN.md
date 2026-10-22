---
name: HWE Orbit
description: Identidad programática del framework de e-commerce headless HWE Orbit — un núcleo estable y las instancias que giran a su alrededor.
---

<!-- SEED: established with the user before implementation; re-run $impeccable document once there's code to capture the actual tokens and components. -->

# DESIGN.md — HWE Orbit

> Alcance: es la identidad de **HWE Orbit, el producto** (logo, docs, web del framework,
> material de venta). **No** es el diseño de las tiendas que se construyen con él: el starter de
> `frontend/` sigue siendo neutro y cada instancia trae su propia marca.

## Overview

**North star: «Programa Orbit».** La marca no es un dibujo, es un **programa** en el sentido de
Karl Gerstner (*Designing Programmes*, 1964): un conjunto pequeño de reglas fijas que genera
variantes coherentes. Es la misma idea que el producto: un **núcleo** que no se mueve y
**instancias** libres que ocupan posiciones permitidas a su alrededor, nunca dentro.

Carácter: diseño suizo de sistema, exacto, seco y seguro de sí mismo; afirma por construcción,
sin decorar. Nada de brillo, glow, degradados ni espacio exterior ilustrado: Orbit es una
órbita **geométrica**, no astronómica.

### Programa de marca (la retícula constructiva)

Todo símbolo, diagrama o ilustración de HWE Orbit sale de esta construcción:

1. **Unidad `u`** = grosor del trazo de la órbita. Toda medida del símbolo es múltiplo entero
   de `u`. Los masters en `brand/` usan `u = 6`, y el símbolo ocupa una caja de `33u` (198).
2. **Núcleo**: disco macizo de radio `8u`. Siempre sólido, siempre centrado, siempre presente.
3. **Órbita**: anillo concéntrico con el eje a radio `16u` (el doble del núcleo) y trazo de `1u`.
   La marca primaria usa **una** órbita; los diagramas pueden añadir órbitas cada `8u`.
4. **Satélite**: disco macizo de radio `3u` con el centro **sobre** la órbita, en una de las 8
   posiciones de la rosa (múltiplos de 45°). La posición canónica de la marca es **45° (NE)**.
   Por debajo de 48 px se usa la variante óptica (`brand/favicon.svg`): trazo y satélite más
   gruesos con la misma relación de núcleo a órbita.
5. **Regla de estado** (heredada del patrón de costura): la instancia elegida/activa va **sólida**;
   las posiciones posibles se dibujan solo como **contorno** de `1u` o no se dibujan.
6. **Regla programática**: la posición del satélite puede codificar algo verdadero (p. ej. la
   versión mayor del núcleo o un módulo), pero la marca primaria fija siempre 45°. Variantes
   generadas = mismo núcleo, misma órbita, otro rumbo. Nunca se deforma el núcleo.

Movimiento: un satélite avanza a saltos discretos de 45° (un paso, ligero rebote, reposo); nunca
gira continuo. Respeta `prefers-reduced-motion` (sin movimiento).

## Colors

Estrategia **Committed**: el amarillo cadmio es campo, no acento; en superficies de persuasión
ocupa regiones enteras (30–60 %). Escena física: un desarrollador de agencia evaluando el
framework de día, en su escritorio, leyendo documentación larga → el **claro** es el modo base;
el oscuro es una inversión del mismo programa, no otra paleta.

| Rol | Nombre | Valor | Uso |
|---|---|---|---|
| Campo | Amarillo cadmio | `#F2C200` | Campos de marca, fondo de la marca en su versión de color. Nunca como texto sobre claro. |
| Tinta | Negro tinta | `#111111` | Núcleo, satélite, órbitas, texto. Sobre cadmio ≈ 12:1. |
| Fondo | Gris papel offset | `#E9E9E6` | Fondo neutro frío (no crema). |
| Blanco | Blanco | `#FFFFFF` | Superficies de lectura densas (docs, código). |
| Interacción | Azul ultramar | `#2A3FD6` | Solo enlaces, foco y estado interactivo. Nunca en el logotipo. |

Combinaciones permitidas de la marca: tinta sobre cadmio (primaria), tinta sobre papel/blanco,
cadmio sobre tinta (inversa), y monocromo puro (negro o blanco). Sin degradados, sin
transparencias, sin un cuarto color.

## Typography

- **Host Grotesk** (Google Fonts) — display y texto. Grotesca de oficio y sobria, de la tradición
  suiza; en display va apretada (tracking ligeramente negativo) y en peso medio-alto.
- **JetBrains Mono** — código, rutas, nombres de hooks y comandos.
- **Wordmark** (masters en `brand/`, ya convertidos a trazados): «orbit» en minúsculas con Host
  Grotesk 800, altura x de ≈ 0,52 del diámetro del símbolo y tracking −0,028 em. «HWE» va encima,
  alineado a la izquierda con la «o», en Host Grotesk 600 y mayúsculas espaciadas (+0,32 em), con
  una altura de mayúscula de ≈ 0,16 del símbolo: es la marca paraguas, no la protagonista. Entre
  el símbolo y la «o» quedan `≈ 5u` de aire.
- Jerarquía por **tamaño y peso**, no por color. Un único tamaño de display por vista.

## Layout

- Retícula modular de columnas derivada de `u`; márgenes y gutters en múltiplos de `u`.
- Composición asimétrica suiza: el núcleo/figura se ancla a un eje fuerte; el texto alinea a
  la izquierda en bandera, nunca centrado en bloques largos.
- Diagramas de arquitectura (núcleo ↔ instancia, BFF, módulos) se dibujan con el mismo programa:
  núcleo = disco, módulos = satélites en órbitas, instancia = posición sólida.
- En móvil se elimina primero la órbita exterior; el núcleo nunca baja de tamaño reconocible.

## Elevation & Depth

Plano absoluto. Sin sombras, sin glow, sin vidrio, sin 3D. La profundidad se expresa con
**capas de color sólido** (cadmio sobre papel, tinta sobre cadmio) y con el orden centro → órbita.

## Shapes

- Lenguaje de **círculo y línea recta**; las formas curvas solo existen como discos y anillos
  concéntricos del programa.
- Contenedores rectangulares de esquina viva o radio mínimo (≤ `0.25u`); el círculo queda
  reservado a la marca y a los diagramas, para que conserve su significado.
- Trazo único de `1u` en todo el sistema; sin trazos discontinuos decorativos (el contorno solo
  significa «posición posible», regla 5).

## Do's and Don'ts

**Sí**
- Construir siempre sobre la retícula `u`; escalar por enteros.
- Mantener el núcleo macizo, centrado y presente en toda variante.
- Usar el cadmio como campo comprometido, la tinta como única voz gráfica.
- Dejar que la posición del satélite diga algo verdadero o fijarla en 45°.

**No**
- Planeta con anillo inclinado (estilo Saturno), estrellas, galaxias, estelas o espacio exterior.
- Elipses en perspectiva, órbitas inclinadas, trazos de movimiento o swooshes.
- Degradados, neón sobre negro, glow, sombras o biseles.
- Azul ultramar o cualquier otro color dentro del logotipo.
- Deformar, contornear o vaciar el núcleo; poner algo **dentro** del núcleo (nada de la
  instancia entra al centro).
- Usar la identidad de HWE Orbit en el starter de las tiendas.
