# Consumidor mínimo del contrato HWE

Script Node (≥ 20, sin dependencias) que demuestra las tres superficies estables del
framework descritas en `docs/FRONTEND_CONNECT.md`: config pública, catálogo GraphQL y BFF
(health + CSRF). Sirve como *smoke test* del contrato y como punto de partida para integrar
cualquier frontend que no herede el de la plantilla.

## Uso

Con la pila levantada (`docker compose up -d` + frontend, o modo híbrido):

```bash
WP_URL=http://localhost:8080 BFF_URL=http://localhost:3000 node index.mjs
```

Salida esperada: la marca configurada, hasta 3 productos y el estado del BFF con un token
CSRF emitido. Cualquier `✘` indica qué superficie del contrato no responde.
