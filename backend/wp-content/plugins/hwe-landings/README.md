# HWE Landings

Módulo `landings` del framework: **landings editoriales** administrables desde el HWE Control
Center. Un registro de landings y, por cada una, un molde de **slots fijos** con URL propia, SEO y
traducción a los idiomas del sitio. Es un plugin regular (activable) y no contiene ninguna landing
de ninguna instancia. Guía para editores: [`MANUAL.md`](MANUAL.md).

El plugin solo es la mitad de WordPress. El motor del frontend (registro combinado, resolución de
secciones, enlaces y rutas) vive en `frontend/src/lib/landings/`; ver `docs/FRONTEND_CONNECT.md`.

## Qué hace

- Reserva la pestaña **Landings** del Control Center (grupo raíz `landings`, vacío en el núcleo) y
  la puebla con `hwe_control_center_schema`.
- **Registro** (`landings.registry`): una landing por fila (`identificador · nombre · tipo`), editado
  con el tipo de campo `landing_registry`. Tipo = `article` o `person` (datos estructurados).
- **Contenido** (`landings.editorial.<id>`), con slots fijos:

  | Slot | Campos |
  |---|---|
  | `published` | publicar |
  | `url` | `base` (`area` o `root`), `path` y un `path_<idioma>` por idioma secundario |
  | hero | banner de tipo «Landing» en la locación `landing-<id>` (plugin `hwe-banners`) |
  | `accent` | color de apoyo propio de la landing |
  | `product` | producto asociado (la ficha enlaza a la landing) |
  | `seo` | título, descripción, imagen, fechas, firma y página de la persona que firma |
  | `lead_*` | entradilla |
  | `sections.s1`…`s5` | habilitada, orden, disposición, antetítulo, título, cuerpo, imagen(es) |
  | `quote` | cita, autor, foto, fondo y logotipo |
  | `faq` | título y `q1`…`q6` (pregunta y respuesta) |
  | `products` | título, posición, fuente (categoría o manual) |
  | `closing` | título, texto, dos botones y fondo |

- **Identidad por slot:** una sección es `s1`…`s5`, no una posición; `order` solo cambia dónde se
  pinta, así que reordenar no huérfana nada.
- **N idiomas:** los textos llevan `translatable`; el núcleo del Control Center les añade un
  `<campo>_<idioma>` por cada idioma de `i18n.locales`. Vacío = se usa el del idioma por defecto.
- **Identificador inmutable:** es la clave bajo la que cuelga el contenido y la locación del banner.
  La URL pública vive en `url.*` y se cambia sin huérfanar nada.
- Registra el tag ISR `landings` y lo revalida al guardar el grupo `landings`.

## Estructura

```
hwe-landings/
  hwe-landings.php            — entrada, constantes y autoloader PSR-4 (HWE\Landings\…)
  includes/
    Plugin.php                — cableado: schema, tipos de campo, banners, revalidación, assets
    Registry.php              — landings estáticas (instancia) + del panel; parse y serialize
    ContentSchema.php         — esquema del grupo `landings`: registro y molde por landing
    Options.php               — opciones de producto y categoría (consultas ligeras, con caché)
    BannerLocations.php       — locación `landing-<id>` por landing
    Revalidation.php          — tag `landings`
    FieldTypes/
      LandingRegistryType.php — `landing_registry`: el editor del registro
      RegistryTypeType.php    — `registry_type`: el tipo, editado en el bloque de la landing
  assets/                     — admin.js (añadir y quitar filas) y admin.css
  README.md  MANUAL.md
```

## Datos

Todo vive en `hwe_config` bajo `landings` y sale por `GET /wp-json/hwe/v1/config` (los campos
`public`):

```json
{
  "landings": {
    "registry": "guia | Guía de uso | article",
    "editorial": {
      "guia": {
        "published": true,
        "url": { "base": "area", "path": "guia-de-uso", "path_en": "usage-guide" },
        "seo": { "title": "…", "title_en": "…" },
        "sections": { "s1": { "enabled": true, "layout": "image-right", "title": "…", "body": "<p>…</p>" } }
      }
    }
  }
}
```

El registro es un **escalar** (texto de una línea por landing): `Storage::merge` lo reemplaza entero y
quitar una fila no deja entradas huérfanas. Quitar una landing del registro conserva su contenido
(si se vuelve a registrar el mismo identificador, reaparece).

## Extensibilidad

| Hook | Tipo | Uso |
|---|---|---|
| `hwe_landings_static_entries` | filtro | Landings con **diseño propio** (secciones de código) de la instancia: `id => ['label' => …, 'jsonld' => 'article'\|'person', 'base' => 'area'\|'root']`. Su contenido se edita en el mismo panel. Deben coincidir con `instanceRegistry.landings.staticEntries` del frontend. Una entrada del panel con el mismo id se ignora. |
| `hwe_control_center_schema` | filtro | Lo usa el propio plugin (prioridad 20) para poblar el grupo `landings`. |
| `hwe_control_center_field_types` | filtro | Lo usa el propio plugin para `landing_registry` y `registry_type`. |
| `hwe_banners_locations` | filtro | Lo usa el propio plugin para añadir `landing-<id>`. |
| `hwe_revalidation_tags` | filtro | Lo usa el propio plugin para registrar `landings`. |

## Tests

`backend/tests/Unit/Landings/`. Desde `backend/`: `composer test`.
