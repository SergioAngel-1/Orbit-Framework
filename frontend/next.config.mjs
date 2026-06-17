/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,

  // Permite servir imágenes alojadas en WordPress (medios de wp-content/uploads).
  images: {
    remotePatterns: [
      {
        protocol: "http",
        hostname: "localhost",
        port: "8080",
        pathname: "/wp-content/uploads/**",
      },
      {
        protocol: "http",
        hostname: "wordpress",
        port: "80",
        pathname: "/wp-content/uploads/**",
      },
      // Añade aquí tu dominio de producción cuando despliegues:
      // { protocol: "https", hostname: "cms.tudominio.com", pathname: "/wp-content/uploads/**" },
    ],
  },
};

export default nextConfig;
