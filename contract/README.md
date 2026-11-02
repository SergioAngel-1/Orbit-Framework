# `contract/` — el contrato del framework en formatos que lee una máquina

Lo que `docs/FRONTEND_CONNECT.md` explica en prosa, aquí en ficheros que un agente o un
generador pueden leer sin interpretar texto. **Todo se genera desde el código y no se edita a
mano**; un test falla si el código cambia y el fichero no.

| Fichero | Qué describe | Se genera con | Lo fija |
|---|---|---|---|
| `config.schema.json` | JSON Schema (2020-12) de `GET /wp-json/hwe/v1/config`: cada campo público con tipo, `default`, `description` y `x-hwe-type` (tipo del Control Center). Incluye los grupos que pueblan `loyalty`, `memberships` y `shipping`. | `wp hwe contract config-schema`, o en local `HWE_UPDATE_CONTRACT=1 composer test -- --filter ContractTest` (desde `backend/`) | `backend/tests/Unit/ControlCenter/ContractTest.php` y, del lado TS, `frontend/tests/unit/contract-config.test.ts` (todo campo publicado tiene default en `CONFIG_DEFAULTS`) |
| `bff.openapi.json` | OpenAPI 3.1 del BFF (`app/api/**`, sin las rutas de la instancia): rutas, métodos y guardas de cada método (`x-hwe-module`, `x-hwe-flag`, `x-hwe-csrf`, `x-hwe-session`, `x-hwe-auth`), con `x-hwe-source` apuntando al `route.ts`. Los cuerpos viven en el schema zod de cada ruta. | `node tools/contract/bff-openapi.mjs`, o `HWE_UPDATE_CONTRACT=1 npx vitest run tests/unit/contract-bff.test.ts` (desde `frontend/`) | `frontend/tests/unit/contract-bff.test.ts` |

## Lo que se genera en cada pila (no se versiona aquí)

- **Esquema GraphQL:** depende de las versiones de WPGraphQL/WooGraphQL y de los plugins activos.
  `node tools/contract/graphql-introspection.mjs --wordpress http://localhost:8080 > schema.json`.
- **Config de una instancia:** `wp hwe contract config-schema` sobre su pila incluye su grupo
  `instance`.
- **Tipos de `SiteConfig.instance`:** `wp hwe contract instance-types` escribe la *module
  augmentation* de `InstanceConfig` con los campos públicos del grupo `instance`. En una instancia:

  ```bash
  docker compose run --rm -T --entrypoint wp wpcli hwe contract instance-types \
    > frontend/src/instance/config.generated.d.ts
  ```

  Sustituye a tipar esos campos a mano en `config.d.ts` (no declares el mismo campo en los dos:
  TypeScript exige tipos idénticos al fusionar interfaces). Los `number` salen como
  `string | number`: el Control Center los guarda como texto y un campo nunca guardado sale con su
  `default`.
- **Rutas BFF de la instancia:** `node tools/contract/bff-openapi.mjs --include-instance`.
