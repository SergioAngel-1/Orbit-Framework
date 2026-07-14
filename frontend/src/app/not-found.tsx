import { routing } from "@/i18n/routing";

// ============================================================================
//  Not-found GLOBAL (fuera de [locale]). Next genera internamente `/_not-found`
//  sin contexto de locale; aquí renderizamos un HTML mínimo y autónomo para
//  evitar el error "Couldn't find next-intl config" durante la build.
//  Los 404 dentro de un idioma los cubre app/[locale]/not-found.tsx.
// ============================================================================

export default function GlobalNotFound() {
  return (
    <html lang={routing.defaultLocale}>
      <head>
        <title>404 — Page not found</title>
      </head>
      <body
        style={{
          fontFamily: "system-ui, sans-serif",
          display: "flex",
          minHeight: "100vh",
          alignItems: "center",
          justifyContent: "center",
          margin: 0,
        }}
      >
        <div style={{ textAlign: "center" }}>
          <h1 style={{ fontSize: "2.5rem", margin: 0 }}>404</h1>
          <p style={{ color: "#666" }}>Page not found.</p>
          {/* eslint-disable-next-line @next/next/no-html-link-for-pages */}
          <a href="/" style={{ color: "#2563eb", fontWeight: 600 }}>
            ← Home
          </a>
        </div>
      </body>
    </html>
  );
}
