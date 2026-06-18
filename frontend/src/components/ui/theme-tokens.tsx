import "server-only";
import { getSiteConfig } from "@/lib/config";
import { buildCssVars } from "@/lib/config/tokens";

/**
 * Server Component que inyecta los design tokens del panel de configuración
 * como CSS custom properties en `:root`.
 *
 * React 19 (App Router) sube automáticamente los <style> de Server Components
 * al <head>, asegurando que se apliquen antes del primer paint.
 *
 * Las declaraciones sobreescriben los valores por defecto de globals.css
 * (@theme en Tailwind v4) porque el <style> inyectado se sitúa después del
 * <link rel="stylesheet"> en el DOM, ganando en la cascada CSS (misma
 * especificidad, última declaración gana).
 */
export async function ThemeTokens() {
  const config = await getSiteConfig();
  const vars = buildCssVars(config);

  if (!vars) return null;

  return (
    <style
      // biome-ignore lint/security/noDangerouslySetInnerHtml: valores de config de confianza (WP admin)
      dangerouslySetInnerHTML={{ __html: `:root {\n  ${vars};\n}` }}
    />
  );
}
