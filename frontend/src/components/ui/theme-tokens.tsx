import "server-only";
import { getSiteConfig } from "@/lib/config";
import { resolveColorScheme } from "@/lib/config/color-scheme";
import { resolveDesignTokens, themeTokensCss } from "@/lib/config/tokens";
import { logger } from "@/lib/observability/logger";

/** Tokens ya notificados: el mismo valor inválido se registra una vez por proceso. */
const reported = new Set<string>();
const MAX_REPORTED = 200;

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
 * especificidad, última declaración gana). El fondo, el texto y la superficie son
 * la excepción: ver `resolveDesignTokens`, para no tapar a `.dark`.
 *
 * Los valores se validan por tipo (ver `lib/config/tokens.ts`) porque se interpolan
 * tal cual en el `<style>`: uno inválido cae al valor por defecto y se registra.
 */
export async function ThemeTokens() {
  const config = await getSiteConfig();
  const resolved = resolveDesignTokens(config, resolveColorScheme(config));
  const { rejected } = resolved;

  for (const token of rejected) {
    const key = `${token.cssVar}|${token.value}`;
    if (reported.has(key) || reported.size >= MAX_REPORTED) continue;
    reported.add(key);
    logger.warn(
      { event: "design.token_invalid", ...token },
      "Token de diseño inválido; se usa el valor por defecto",
    );
  }

  const css = themeTokensCss(resolved);
  if (!css) return null;

  return (
    <style
      // biome-ignore lint/security/noDangerouslySetInnerHtml: valores de config de confianza (WP admin)
      dangerouslySetInnerHTML={{ __html: css }}
    />
  );
}
