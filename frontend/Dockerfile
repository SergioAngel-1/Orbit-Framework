# ============================================================================
#  Dockerfile multi-stage — Frontend Next.js
#
#  Targets:
#    - dev  : entorno de desarrollo con hot-reload (lo usa docker-compose).
#    - prod : imagen de producción mínima (Next standalone, usuario no-root).
#
#  Compose construye `--target dev`. Para producción:
#    docker build --target prod \
#      --build-arg WORDPRESS_PUBLIC_URL=https://cms.example.com \
#      -t hwe-frontend:prod ./frontend
#  (el workflow de CD y `docker compose -f docker-compose.prod.yml build
#  frontend` ya pasan los argumentos; ver «Variables que se CONGELAN»).
# ============================================================================

# ---------- Base común ----------
FROM node:24-alpine AS base
WORKDIR /app
# libc6-compat: algunas dependencias nativas lo requieren en Alpine.
RUN apk add --no-cache libc6-compat

# ---------- Dependencias (cacheables) ----------
FROM base AS deps
COPY package.json package-lock.json* ./
# `scripts/` entra AQUÍ, antes del `npm ci`, porque el `postinstall` parchea el
# `next` instalado (scripts/patch-next-isr-headers.mjs). El `next build` corre en
# el stage siguiente con ESTE `node_modules`, así que el parche llega al
# artefacto de producción. Sin esta copia el `npm ci` fallaría por script
# inexistente, y silenciarlo sería peor: producción se quedaría sin el parche
# que desarrollo sí tiene.
#
# El coste es que editar cualquier script invalida la capa de dependencias. Se
# acepta: cambian poco, y una capa de más pesa menos que un bug solo de prod.
COPY scripts ./scripts
RUN npm ci

# ---------- Build de producción ----------
FROM base AS builder
ENV NEXT_TELEMETRY_DISABLED=1
COPY --from=deps /app/node_modules ./node_modules

# ----------------------------------------------------------------------------
#  Variables que se CONGELAN en el build. Inyectarlas en el contenedor NO
#  sirve: Next las resuelve una sola vez aquí y las hornea en el artefacto.
#
#   - WORDPRESS_PUBLIC_URL: `next.config.mjs` la lee para construir la CSP
#     (`img-src`) y los `remotePatterns` de next/image, y esos acaban en
#     `routes-manifest.json`. Sin ella la imagen servía una CSP que autorizaba
#     `http://localhost:8080` en lugar del dominio real del CMS.
#   - WORDPRESS_INTERNAL_API_URL: también autoriza el origen interno en los
#     `remotePatterns` (el optimizador de imágenes llega a WordPress por ahí) y
#     es el endpoint contra el que el prerender hace fetch en servidor. Si el
#     build no alcanza WordPress (el runner de CI), déjalo vacío: las páginas
#     horneadas usan la config por defecto y ISR las regenera al primer acceso
#     tras el despliegue.
#   - NEXT_PUBLIC_SITE_URL: fallback de `brand.url` si el CMS no responde.
#   - CSP_ANALYTICS_PROVIDERS: estrecha los hosts de analítica autorizados en la
#     CSP. La CSP la evalúa `next.config.mjs` al construir.
#   - SENTRY_DSN: activa el wrap de `withSentryConfig` (túnel `/monitoring`).
#   - Ritmo y paciencia del prerender (solo afectan al BUILD, no al artefacto):
#     sin valor, Next y el cliente GraphQL usan sus defaults.
#
#  ORDEN: este bloque va ANTES de `COPY . .` a propósito. El builder clásico
#  (obligado si el build necesita `build.network`, ver docker-compose.prod.yml)
#  commitea una capa por instrucción, y con el bloque DETRÁS del COPY su capa
#  padre sería nueva en CADA despliegue: ninguna podría cachearse. Delante,
#  mientras los valores no cambien —y no cambian: son dominios y ritmos— son
#  cache hit. No toca la caché de dependencias: `node_modules` entra por
#  `COPY --from=deps`, de otra etapa.
# ----------------------------------------------------------------------------
ARG WORDPRESS_PUBLIC_URL
ARG WORDPRESS_INTERNAL_API_URL
ARG NEXT_PUBLIC_SITE_URL
ARG CSP_ANALYTICS_PROVIDERS
ARG SENTRY_DSN
ARG NEXT_STATIC_GENERATION_MAX_CONCURRENCY
ARG NEXT_STATIC_GENERATION_MIN_PAGES_PER_WORKER
ARG NEXT_STATIC_PAGE_GENERATION_TIMEOUT
ARG GRAPHQL_TIMEOUT_MS
ARG CONFIG_TIMEOUT_MS
ARG PRERENDER_LONG_TAIL
ENV WORDPRESS_PUBLIC_URL=$WORDPRESS_PUBLIC_URL \
    WORDPRESS_INTERNAL_API_URL=$WORDPRESS_INTERNAL_API_URL \
    NEXT_PUBLIC_SITE_URL=$NEXT_PUBLIC_SITE_URL \
    CSP_ANALYTICS_PROVIDERS=$CSP_ANALYTICS_PROVIDERS \
    SENTRY_DSN=$SENTRY_DSN \
    NEXT_STATIC_GENERATION_MAX_CONCURRENCY=$NEXT_STATIC_GENERATION_MAX_CONCURRENCY \
    NEXT_STATIC_GENERATION_MIN_PAGES_PER_WORKER=$NEXT_STATIC_GENERATION_MIN_PAGES_PER_WORKER \
    NEXT_STATIC_PAGE_GENERATION_TIMEOUT=$NEXT_STATIC_PAGE_GENERATION_TIMEOUT \
    GRAPHQL_TIMEOUT_MS=$GRAPHQL_TIMEOUT_MS \
    CONFIG_TIMEOUT_MS=$CONFIG_TIMEOUT_MS \
    PRERENDER_LONG_TAIL=$PRERENDER_LONG_TAIL

# Falla PRONTO y con un mensaje claro en vez de hornear una imagen con la CSP y
# los remotePatterns apuntando a localhost — el fallo silencioso que esto viene a
# evitar. Solo afecta al target `prod`; `dev` no pasa por aquí.
RUN test -n "$WORDPRESS_PUBLIC_URL" || ( \
      echo "ERROR: falta --build-arg WORDPRESS_PUBLIC_URL." >&2; \
      echo "       Se hornea en la CSP y en los remotePatterns de next/image;" >&2; \
      echo "       pasarla solo por 'environment' del contenedor NO tiene efecto." >&2; \
      exit 1 )

COPY . .

RUN npm run build

# ---------- Runtime de producción (standalone) ----------
FROM node:24-alpine AS prod
WORKDIR /app
ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1
ENV HOSTNAME=0.0.0.0
ENV PORT=3000

# Usuario sin privilegios.
RUN addgroup --system --gid 1001 nodejs \
  && adduser --system --uid 1001 nextjs

# Solo lo necesario para arrancar: server.js + estáticos + public.
COPY --from=builder /app/public ./public
COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static ./.next/static

# Destino de la caché de imágenes optimizadas (`<distDir>/cache/images`).
#
# Se crea AQUÍ, ya con el dueño correcto, porque `docker-compose.prod.yml` monta
# un volumen con nombre en esta ruta: Docker copia el contenido y los permisos
# del directorio de la imagen al inicializar el volumen, así que si no existiera
# el volumen nacería propiedad de root y el proceso `nextjs` (uid 1001) no podría
# escribir — cada petición de imagen reoptimizaría sin poder guardar nada.
RUN mkdir -p .next/cache/images && chown -R nextjs:nodejs .next/cache

USER nextjs
EXPOSE 3000

# Healthcheck nativo de la imagen (independiente del de compose). Liveness: no
# toca Redis ni WordPress (la readiness la decide el despliegue con ?ready=1).
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD node -e "fetch('http://localhost:3000/api/health/live').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

CMD ["node", "server.js"]

# ---------- Desarrollo (hot-reload) ----------
#
# Va AL FINAL, después de `prod`, y el orden es funcional, no estético. El
# builder clásico (no BuildKit) NO salta los stages que el target no necesita:
# recorre el Dockerfile ENTERO hasta el target, en orden de fichero. Con este
# stage entre `deps` y `builder`, cada build de producción con ese builder
# ejecutaría sus dos COPY —`node_modules` completo y todo el contexto— para
# tirarlos a la basura. Detrás de `prod` no se construye nunca en producción, y
# `--target dev` lo sigue construyendo igual.
FROM base AS dev
ENV NODE_ENV=development
ENV HOSTNAME=0.0.0.0
COPY --from=deps /app/node_modules ./node_modules
COPY . .
EXPOSE 3000
CMD ["npm", "run", "dev"]
