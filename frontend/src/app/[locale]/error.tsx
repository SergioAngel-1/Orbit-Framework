"use client";
import { useEffect } from "react";
import { Button } from "@/components/ui/button";
import { Alert } from "@/components/ui/alert";

// Error boundary para la capa del locale.
// Next.js lo renderiza cuando un Server/Client Component lanza una excepción.
export default function LocaleError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    // Aquí conectarías Sentry u otro error tracker
    console.error("[locale error]", error);
  }, [error]);

  const goHome = () => {
    // Recarga completa a propósito: el árbol de React que falló puede quedar
    // inconsistente, así que no se reutiliza el router (que podría arrastrar el
    // estado roto). `assign` con URL absoluta —construida sobre el origen actual,
    // sin asumir el pathname de la página del error— es la navegación de salida.
    window.location.assign(new URL("/", window.location.origin).href);
  };

  return (
    <div className="flex flex-col items-center gap-6 py-24 text-center">
      <Alert variant="error" title="Algo salió mal" className="max-w-lg text-left">
        {error.message || "Se produjo un error inesperado. Inténtalo de nuevo."}
        {error.digest && (
          <p className="mt-1 font-mono text-xs opacity-60">ref: {error.digest}</p>
        )}
      </Alert>

      <div className="flex gap-3">
        <Button onClick={reset}>Reintentar</Button>
        <Button variant="outline" onClick={goHome}>
          Ir al inicio
        </Button>
      </div>
    </div>
  );
}
