"use client";
import { useEffect, useSyncExternalStore } from "react";
import { Button } from "./button";

// ============================================================================
//  Toggle de modo oscuro — clase-based (añade/quita .dark en <html>).
//  Guarda la preferencia en localStorage; al cargar la página el script inline
//  DarkModeScript aplica la clase antes del primer paint (sin FOUC).
// ============================================================================

const THEME_KEY = "hwe-theme";

/**
 * El tema NO es estado de React: la fuente de verdad es la clase `dark` de
 * `<html>` (la aplica el script inline antes del primer paint y la cambia el
 * propio toggle). Se lee como store externo con `useSyncExternalStore`: el
 * servidor pinta el valor por defecto y el cliente se sincroniza al hidratar,
 * sin un `setState` síncrono dentro de un efecto de montaje.
 */
const themeListeners = new Set<() => void>();

function subscribeTheme(listener: () => void): () => void {
  themeListeners.add(listener);
  return () => {
    themeListeners.delete(listener);
  };
}

function getThemeSnapshot(): boolean {
  return document.documentElement.classList.contains("dark");
}

function getServerThemeSnapshot(): boolean {
  return false;
}

/**
 * ¿El sitio fija el esquema (`design.color_scheme`)? Lo marca `<html data-color-scheme>`. Entonces no
 * hay nada que elegir: ni se lee la preferencia guardada ni se ofrece el selector.
 */
function isColorSchemeFixed(): boolean {
  return document.documentElement.hasAttribute("data-color-scheme");
}

/** Aplica el tema y avisa a los suscriptores (el toggle). */
function applyDarkClass(dark: boolean): void {
  document.documentElement.classList.toggle("dark", dark);
  themeListeners.forEach((listener) => listener());
}

/** Persiste la preferencia (si el navegador lo permite). */
function persistTheme(dark: boolean): void {
  try {
    localStorage.setItem(THEME_KEY, dark ? "dark" : "light");
  } catch {
    /* localStorage bloqueado (modo privado, políticas): el tema dura la sesión */
  }
}

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
  const isDark = useSyncExternalStore(
    subscribeTheme,
    getThemeSnapshot,
    getServerThemeSnapshot,
  );
  // Quien conozca el esquema en el servidor no debe montar este botón (`SiteHeader`). Esto es la
  // red para una cabecera propia que lo siga montando: el botón se retira al hidratar.
  const fixed = useSyncExternalStore(
    subscribeTheme,
    isColorSchemeFixed,
    getServerThemeSnapshot,
  );

  if (fixed) return null;

  const toggle = () => {
    const nextDark = !document.documentElement.classList.contains("dark");
    applyDarkClass(nextDark);
    persistTheme(nextDark);
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

/** Aplica el tema guardado (o el del sistema) a `<html>`. Misma lógica que el script inline. */
function applyStoredTheme() {
  if (isColorSchemeFixed()) return;
  try {
    const stored = localStorage.getItem(THEME_KEY);
    const dark =
      stored === "dark" ||
      (stored === null && window.matchMedia("(prefers-color-scheme:dark)").matches);
    applyDarkClass(dark);
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
        __html: `(function(){if(document.documentElement.hasAttribute('data-color-scheme'))return;try{var t=localStorage.getItem('${THEME_KEY}');if(t==='dark'||(t===null&&window.matchMedia('(prefers-color-scheme:dark)').matches)){document.documentElement.classList.add('dark')}else{document.documentElement.classList.remove('dark')}}catch(e){}})()`,
      }}
    />
  );
}
