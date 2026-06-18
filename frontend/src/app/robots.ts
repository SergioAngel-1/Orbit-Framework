import type { MetadataRoute } from "next";
import { siteConfig } from "@/config/site";

export default function robots(): MetadataRoute.Robots {
  const isDev = process.env.NODE_ENV === "development";

  return {
    rules: {
      userAgent: "*",
      ...(isDev
        ? { disallow: "/" }
        : {
            allow: "/",
            // Rutas privadas / sin valor SEO (incluye sus variantes /en/...).
            disallow: [
              "/api/",
              "/account",
              "/cart",
              "/checkout",
              "/login",
              "/register",
              "/en/account",
              "/en/cart",
              "/en/checkout",
              "/en/login",
              "/en/register",
            ],
          }),
    },
    sitemap: isDev ? undefined : `${siteConfig.url}/sitemap.xml`,
  };
}
