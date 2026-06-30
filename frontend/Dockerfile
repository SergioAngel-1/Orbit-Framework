# ============================================================================
#  Dockerfile multi-stage — Frontend Next.js
#
#  Targets:
#    - dev  : entorno de desarrollo con hot-reload (lo usa docker-compose).
#    - prod : imagen de producción mínima (Next standalone, usuario no-root).
#
#  Compose construye `--target dev`. Para producción:
#    docker build --target prod -t hwe-frontend:prod ./frontend
# ============================================================================

# ---------- Base común ----------
FROM node:24-alpine AS base
WORKDIR /app
# libc6-compat: algunas dependencias nativas lo requieren en Alpine.
RUN apk add --no-cache libc6-compat

# ---------- Dependencias (cacheables) ----------
FROM base AS deps
COPY package.json package-lock.json* ./
RUN npm ci

# ---------- Desarrollo (hot-reload) ----------
FROM base AS dev
ENV NODE_ENV=development
ENV HOSTNAME=0.0.0.0
COPY --from=deps /app/node_modules ./node_modules
COPY . .
EXPOSE 3000
CMD ["npm", "run", "dev"]

# ---------- Build de producción ----------
FROM base AS builder
ENV NEXT_TELEMETRY_DISABLED=1
COPY --from=deps /app/node_modules ./node_modules
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

USER nextjs
EXPOSE 3000

# Healthcheck nativo de la imagen (independiente del de compose).
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD node -e "fetch('http://localhost:3000/api/health').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

CMD ["node", "server.js"]
