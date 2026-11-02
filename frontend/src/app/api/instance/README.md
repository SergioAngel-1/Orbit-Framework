# `app/api/instance/` — rutas BFF de la instancia

Carpeta **de la instancia** (`tools/boundaries.json`): el framework solo versiona este README y
nunca toca lo que pongas aquí. Cuelga de `/api`, así que cada ruta recibe lo mismo que las del
núcleo: barrera de Origin en escrituras (proxy), refresco del JWT, sin prefijo de idioma y
`Disallow: /api/` en `robots.txt`.

- **Rutas normales** (`/api/instance/<lo-que-sea>`): exporta cada método con
  `instanceRoute(options, handler)` de `@/lib/api/instance-route`. Aplica módulo/flag (404),
  `guardMutation` en escrituras (Origin → CSRF → rate-limit), sesión (401), `no-store` y el
  mapeo de errores del núcleo. Desde aquí sí puedes importar `@/instance/**` y `@/components/**`.
- **Webhooks** (`/api/instance/webhooks/<nombre>`, un único segmento): el proxy los exime de la
  barrera de Origin porque los llama otro servidor. Deben verificar su firma antes de actuar
  (`verifyHmacSignature` de `@/lib/security/webhook`); `tests/unit/signed-routes.test.ts` lo
  comprueba sobre tus `route.ts`.

Ejemplo vivo: `examples/instance-minimal/frontend/src/app/api/instance/example/route.ts`.
Contrato: `docs/FRONTEND_CONNECT.md` §A.2, «Rutas BFF de la instancia».
