# ============================================================================
#  Dockerfile — Frontend Next.js (entorno de DESARROLLO con hot-reload)
#  Para producción, ver la fase comentada al final (build standalone).
# ============================================================================
FROM node:20-alpine AS dev

WORKDIR /app

# Instala dependencias primero para aprovechar la caché de capas de Docker.
COPY package.json package-lock.json* ./
RUN npm install

# El código se monta como volumen en docker-compose (hot-reload),
# pero lo copiamos también para que la imagen sea autocontenida.
COPY . .

EXPOSE 3000

ENV NODE_ENV=development
# Necesario para que Next escuche en todas las interfaces dentro del contenedor.
ENV HOSTNAME=0.0.0.0

CMD ["npm", "run", "dev"]

# ----------------------------------------------------------------------------
#  PRODUCCIÓN (descomentar y construir con --target=prod):
#
#  FROM node:20-alpine AS builder
#  WORKDIR /app
#  COPY package.json package-lock.json* ./
#  RUN npm ci
#  COPY . .
#  RUN npm run build
#
#  FROM node:20-alpine AS prod
#  WORKDIR /app
#  ENV NODE_ENV=production
#  COPY --from=builder /app/public ./public
#  COPY --from=builder /app/.next/standalone ./
#  COPY --from=builder /app/.next/static ./.next/static
#  EXPOSE 3000
#  CMD ["node", "server.js"]
#  (requiere `output: "standalone"` en next.config.mjs)
# ----------------------------------------------------------------------------
