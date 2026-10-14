"use client";
import { useEffect, useState } from "react";
import { Button } from "./button";

// ============================================================================
//  Toggle de modo oscuro — clase-based (añade/quita .dark en <html>).
//  Guarda la preferencia en localStorage; al cargar la página el script inline
//  DarkModeScript aplica la clase antes del primer paint (sin FOUC).
// ============================================================================

function MoonIcon() {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      width="18"
      height="18"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
    >
      <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
    </svg>
  );
}

function SunIcon() {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      width="18"
      height="18"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
    >
      <circle cx="12" cy="12" r="4" />
      <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41" />
    </svg>
  );
}

export function DarkModeToggle({ className }: { className?: string }) {
  const [isDark, setIsDark] = useState(false);

  useEffect(() => {
    setIsDark(document.documentElement.classList.contains("dark"));
  }, []);

  const toggle = () => {
    const html = document.documentElement;
    const nextDark = !html.classList.contains("dark");
    html.classList.toggle("dark", nextDark);
    try {
      localStorage.setItem("hwe-theme", nextDark ? "dark" : "light");
    } catch {
      /* ignore */
    }
    setIsDark(nextDark);
  };

  return (
    <Button
      variant="ghost"
      size="icon"
      onClick={toggle}
      aria-label={isDark ? "Activar modo claro" : "Activar modo oscuro"}
      className={className}
    >
      {isDark ? <SunIcon /> : <MoonIcon />}
    </Button>
  );
}

const THEME_KEY = "hwe-theme";

/** Aplica el tema guardado (o el del sistema) a `<html>`. Misma lógica que el script inline. */
function applyStoredTheme() {
  try {
    const stored = localStorage.getItem(THEME_KEY);
    const dark =
      stored === "dark" ||
      (stored === null && window.matchMedia("(prefers-color-scheme:dark)").matches);
    document.documentElement.classList.toggle("dark", dark);
  } catch {
    // localStorage bloqueado (modo privado, políticas): se queda el tema por defecto.
  }
}

/**
 * Aplica el tema antes del primer paint, sin parpadeo (FOUC).
 *
 * En el servidor emite un `<script>` inline que va en el HTML de la primera carga.
 * En el cliente NO pinta un `<script>` (React 19 avisa y nunca lo ejecutaría): al
 * cambiar de idioma el layout se vuelve a montar y `<html>` se recrea sin la clase
 * `dark`, así que se reaplica el tema al montar. Usar con `suppressHydrationWarning`
 * en `<html>`.
 */
export function DarkModeScript() {
  useEffect(() => {
    applyStoredTheme();
  }, []);

  // El `<script>` solo hace falta en el HTML del servidor, antes del primer paint.
  // React 19 tolera en `<head>` el nodo del servidor que el cliente no renderiza.
  if (typeof window !== "undefined") return null;
  return (
    <script
      // biome-ignore lint/security/noDangerouslySetInnerHtml: inline script necesario para evitar FOUC de modo oscuro
      dangerouslySetInnerHTML={{
        __html: `(function(){try{var t=localStorage.getItem('${THEME_KEY}');if(t==='dark'||(t===null&&window.matchMedia('(prefers-color-scheme:dark)').matches)){document.documentElement.classList.add('dark')}else{document.documentElement.classList.remove('dark')}}catch(e){}})()`,
      }}
    />
  );
}
