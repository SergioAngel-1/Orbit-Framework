# Marca HWE Orbit

Masters vectoriales de la identidad del **framework** (no de las tiendas que se construyen con él;
ver `DESIGN.md`). El símbolo es geometría exacta y el wordmark está convertido a trazados, así
que no dependen de ninguna fuente instalada.

| Archivo | Uso |
|---|---|
| `orbit-logo.svg` / `-white.svg` | Logo horizontal (principal), en tinta o en blanco, fondo transparente |
| `orbit-logo-on-cadmium.svg` / `-on-ink.svg` | Logo horizontal con campo de color y área de respeto incluidos |
| `orbit-logo-stacked*.svg` | Versión apilada, para espacios cuadrados o verticales |
| `orbit-wordmark*.svg` | Solo «HWE orbit», cuando el símbolo ya aparece cerca |
| `orbit-symbol*.svg` | Solo el símbolo (tinta, blanco o cadmio), fondo transparente |
| `orbit-app-icon.svg` / `-ink.svg` | Icono cuadrado con campo (avatares, redes, app) |
| `favicon.svg`, `favicon.ico` | Variante óptica para tamaños ≤ 48 px (trazo y satélite engrosados) |
| `png/` | Exportaciones raster: icono de 512 px, `apple-touch-icon` de 180, favicon de 32 y logos de 1200 px |

## Reglas

- **Colores**: solo tinta `#111111`, cadmio `#F2C200` y blanco. Sin degradados, sombras ni
  un tercer color.
- **Área de respeto**: alrededor de la marca, como mínimo, el radio del núcleo (≈ un cuarto
  de la altura del símbolo).
- **Tamaño mínimo**: el logo horizontal a 96 px de ancho; por debajo usa el símbolo, y por debajo de
  48 px usa `favicon.svg`.
- **No** rotes el símbolo ni muevas el satélite fuera de los múltiplos de 45°, y nunca deformes ni
  vacíes el núcleo. El resto de prohibiciones está en `DESIGN.md` → *Do's and Don'ts*.
