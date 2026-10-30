import { lastResortCopy, lastResortStyles as styles } from "./last-resort";

// ============================================================================
//  Not-found GLOBAL (fuera de [locale]). Next genera internamente `/_not-found`
//  sin contexto de locale; aquí renderizamos un HTML mínimo y autónomo para
//  evitar el error "Couldn't find next-intl config" durante la build.
//  Los 404 dentro de un idioma los cubre app/[locale]/not-found.tsx.
// ============================================================================

export default function GlobalNotFound() {
  const copy = lastResortCopy();
  return (
    <html lang={copy.lang}>
      <head>
        <title>{copy.notFoundTitle}</title>
      </head>
      <body style={styles.body}>
        <main style={styles.panel}>
          <h1 style={styles.title}>{copy.notFoundTitle}</h1>
          <p style={styles.text}>{copy.notFoundBody}</p>
          {/* eslint-disable-next-line @next/next/no-html-link-for-pages */}
          <a href="/" style={styles.action}>
            {copy.home}
          </a>
        </main>
      </body>
    </html>
  );
}
