"use client";

import { lastResortCopy, lastResortStyles as styles } from "./last-resort";

/**
 * Último recurso: atrapa lo que `[locale]/error.tsx` no puede, un fallo del propio layout de
 * `[locale]`. Sin `globals.css` ni contexto: ver `last-resort.ts`. El mensaje del error nunca se
 * enseña (puede llevar detalles internos); `digest` es la referencia para cruzar con el log.
 */
export default function GlobalError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  const copy = lastResortCopy();
  return (
    <html lang={copy.lang}>
      <head>
        <title>{copy.errorTitle}</title>
      </head>
      <body style={styles.body}>
        <main style={styles.panel}>
          <h1 style={styles.title}>{copy.errorTitle}</h1>
          <p style={styles.text}>{copy.errorBody}</p>
          <button type="button" onClick={reset} style={styles.action}>
            {copy.retry}
          </button>
          {error.digest && (
            <p style={styles.reference}>
              {copy.reference}: {error.digest}
            </p>
          )}
        </main>
      </body>
    </html>
  );
}
