import { useMemo } from "react";
import { useLocale } from "next-intl";
import { createNavigation } from "next-intl/navigation";
import { routing } from "./routing";
import { toPublicHref, type NavigationHref } from "./href";

// ============================================================================
//  Navegación consciente del locale y de las rutas públicas de la instancia.
//
//  Usa SIEMPRE estos `Link`, `redirect`, `useRouter`, `usePathname` en lugar de los de
//  `next/*` para que se conserve el prefijo de idioma (/en/...) al navegar y para que un
//  href INTERNO (`/products`, `/account/orders`) se sirva con el tramo PÚBLICO que declare
//  `InstanceRegistry.publicPaths` (`/productos`, `/cuenta/orders`). Sin mapa, todo es la
//  identidad: el framework navega exactamente como antes.
//
//  No se declaran `pathnames` de next-intl: solo localizan una ruta si el `href` es una
//  clave exacta de ese mapa, así que una ficha concreta saldría sin traducir. El proxy
//  hace el rewrite público → interno y el 308 interno → público.
// ============================================================================

const base = createNavigation(routing);

export { Link } from "./link";
export type { LinkProps } from "./link";

export const usePathname = base.usePathname;

/** Argumentos de `getPathname`/`redirect` con un href interno o público. */
export interface NavigationArgs {
  locale: string;
  href: NavigationHref;
  forcePrefix?: boolean;
}

type BaseGetPathnameArgs = Parameters<typeof base.getPathname>[0];

/** Ruta localizada (con prefijo de idioma) de un href interno o público. */
export function getPathname(args: NavigationArgs): string {
  return base.getPathname({
    ...args,
    href: toPublicHref(args.href, args.locale),
  } as BaseGetPathnameArgs);
}

type BaseRedirect = (args: BaseGetPathnameArgs, ...rest: unknown[]) => never;

export function redirect(args: NavigationArgs, ...rest: unknown[]): never {
  return (base.redirect as unknown as BaseRedirect)(
    { ...args, href: toPublicHref(args.href, args.locale) } as BaseGetPathnameArgs,
    ...rest,
  );
}

export function permanentRedirect(args: NavigationArgs, ...rest: unknown[]): never {
  return (base.permanentRedirect as unknown as BaseRedirect)(
    { ...args, href: toPublicHref(args.href, args.locale) } as BaseGetPathnameArgs,
    ...rest,
  );
}

/** Router del cliente: `push`/`replace`/`prefetch` aceptan hrefs internos. */
export function useRouter() {
  const router = base.useRouter();
  const locale = useLocale();
  return useMemo(() => {
    const localize = (href: NavigationHref) => toPublicHref(href, locale);
    type PushArgs = Parameters<typeof router.push>;
    return {
      ...router,
      push: (href: NavigationHref, options?: PushArgs[1]) =>
        router.push(localize(href) as unknown as PushArgs[0], options),
      replace: (href: NavigationHref, options?: Parameters<typeof router.replace>[1]) =>
        router.replace(localize(href) as unknown as PushArgs[0], options),
      prefetch: (href: NavigationHref) =>
        router.prefetch(localize(href) as unknown as PushArgs[0]),
    };
  }, [router, locale]);
}
