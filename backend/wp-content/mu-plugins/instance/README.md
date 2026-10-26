# `mu-plugins/instance/` — el código de TU instancia

Esta carpeta es **tuya**. El framework nunca la toca: puedes actualizar el núcleo (`git merge vX.Y.Z`)
sin conflictos en nada de lo que pongas aquí. Lo único que el framework versiona es este README.

## Cómo funciona

`hwe-instance-loader.php` (un mu-plugin del núcleo) hace `require` de los `.php` de **primer nivel**
de esta carpeta, en **orden alfabético**. WordPress no desciende a subcarpetas de `mu-plugins/`; si
quieres organizar tu código en subcarpetas, cárgalas tú desde uno de los archivos de primer nivel.

- Usa prefijos numéricos si el orden importa: `10-campos.php`, `20-tipos-de-campo.php`.
- Con la carpeta vacía el cargador no hace nada.
- Un archivo que lanza una excepción o tiene un error de sintaxis **no tumba el sitio**: se registra
  en el log de PHP (`[hwe] instance-loader: …`) y se sigue con el siguiente. Un `exit` o un error
  fatal sí lo tumba (PHP no permite capturarlos).
- `HWE_CORE_CONTRACT_VERSION` (`2.2.0`) es la versión del contrato núcleo ↔ instancia. Compárala si tu
  código depende de un hook que apareció en una versión concreta.

## El contrato: qué puedes hacer desde aquí

Todo se hace con **hooks**. Los nombres son estables (cambiarlos es un cambio incompatible del
framework y se anuncia en `docs/UPGRADE.md`).

| Hook | Tipo | Para qué |
|---|---|---|
| `hwe_control_center_schema` | filtro | Añadir campos al Control Center. Solo dentro del grupo `instance`. |
| `hwe_control_center_saved` | acción | Reaccionar a «Guardar configuración» (datos saneados y POST crudo). |
| `hwe_revalidation_tags` | filtro | Declarar tags ISR propios para poder revalidarlos desde WordPress. |
| `hwe_geo_packs` | filtro | Añadir o sustituir paquetes por país del mu-plugin `hwe-geo`. |
| `hwe_modules_catalog` | filtro | Registrar un plugin propio para que `wp hwe setup` pueda activarlo (receta más abajo). |

(F3 añade `hwe_control_center_field_types`; los módulos y F5 añaden más. Todos se documentan en
`docs/PLUGIN_DEVELOPMENT.md`.)

### `hwe_control_center_schema` — añadir ajustes propios

El núcleo declara un grupo raíz `instance`, vacío, que el panel pinta como la pestaña **«Instancia»**
en cuanto tiene campos. Añade hijos a ese grupo:

```php
add_filter('hwe_control_center_schema', static function (array $schema): array {
    $schema['instance']['children']['example'] = [
        'type'     => 'group',
        'label'    => 'Ejemplo',
        'children' => [
            'greeting' => ['type' => 'text', 'label' => 'Saludo', 'default' => 'Hola', 'public' => true],
        ],
    ];
    return $schema;
});
```

Reglas:

- Los campos `public => true` salen por `/wp-json/hwe/v1/config` en `instance.<grupo>.<campo>` y el
  frontend los lee con `getSiteConfig()` (tipados por *module augmentation*, ver
  `frontend/src/instance/config.d.ts`). Los `secret` se cifran y nunca salen.
- **Solo se admiten claves raíz del núcleo y `instance`.** Una clave raíz nueva se descarta y se registra
  en el log (`[hwe] control-center: …`). Tus ajustes van dentro de `instance`.
- **El núcleo no se puede quitar desde un filtro.** Si un filtro elimina un grupo del núcleo, se restaura
  y se registra. Sí puedes añadir campos a un grupo del núcleo si de verdad lo necesitas, pero lo
  recomendable es no hacerlo: así una actualización del núcleo no choca con tu schema.
- Si `instance` deja de ser un grupo (o el filtro no devuelve un array), se descarta lo que devolvió y se
  registra.

### `hwe_control_center_saved` — reaccionar al guardado

```php
add_action('hwe_control_center_saved', static function (array $publicData, array $rawPost): void {
    // $publicData: la config pública YA saneada y guardada (la misma forma que el schema).
    // $rawPost:    el árbol `hwe_config` de la petición tal como llegó, SIN sanear.
}, 10, 2);
```

Se dispara tras guardar y **antes** de revalidar el frontend, solo con nonce y capacidad
`manage_options` ya comprobados. Es el lugar para persistir campos que gestiona tu propio código (post
meta, tablas propias…). **Sanea siempre `$rawPost`** antes de usarlo. Sustituye a las acciones con
nombre de instancia de versiones anteriores (`hwe_control_center_<tu_cosa>_save`): el panel ya no lee
ninguna clave del POST que no sea del schema.

### `hwe_revalidation_tags` — tags ISR propios

```php
add_filter('hwe_revalidation_tags', static fn (array $tags): array => [...$tags, 'example']);
```

WordPress solo envía al frontend los tags registrados aquí (formato `[a-z0-9._-]`, máximo 64
caracteres). Declara el mismo tag en el frontend, en `src/instance/registry.ts`
(`revalidationTags`), o `/api/revalidate` lo rechazará.

## Plugins grandes de instancia

Si tu instancia necesita un plugin entero (CPT, tablas, UI propia), créalo como plugin regular
`hwe-instance-<slug>` en `wp-content/plugins/`. El `.gitignore` del framework trae el patrón
preparado (comentado) para versionarlos; descoméntalo al clonar.

Para que `wp hwe setup` lo active, regístralo en el catálogo de módulos y añade su id a
`modules` en `instance.config.json`:

```php
// backend/wp-content/mu-plugins/instance/20-modulos.php
add_filter('hwe_modules_catalog', static function (array $catalog): array {
    $catalog['example'] = 'hwe-instance-example'; // id del módulo → carpeta del plugin
    return $catalog;
});
```

```json
{ "modules": ["example", "banners"] }
```

**Con `modules` presente, el setup activa los listados y desactiva el resto del catálogo**, así
que enumera también los módulos del framework que quieras conservar. Receta completa y reglas
del filtro: `docs/PLUGIN_DEVELOPMENT.md` §0.

## Ejemplo completo

`examples/instance-minimal/` replica esta carpeta y la del frontend con un campo, un tag de
revalidación y una vista. Copia de ahí para empezar.
