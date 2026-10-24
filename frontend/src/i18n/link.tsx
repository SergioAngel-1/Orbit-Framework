"use client";

import { forwardRef, type ComponentProps } from "react";
import { useLocale } from "next-intl";
import { createNavigation } from "next-intl/navigation";
import { routing } from "./routing";
import { toPublicHref, type NavigationHref } from "./href";

// ============================================================================
//  `Link` con rutas públicas.
//
//  next-intl solo localiza un `href` que sea EXACTAMENTE una clave de `pathnames`; una
//  ruta concreta (`/products/vela-roja`, `/account/orders/12`) no lo es y saldría con el
//  tramo interno. Este componente traduce el href interno al público del idioma actual
//  (`toPublicHref`) y deja que next-intl anteponga el prefijo de idioma, sin declarar
//  `pathnames` (que además duplicaría los redirects del proxy).
//
//  Es un Client Component porque necesita el idioma actual; el `Link` de next-intl ya lo
//  era, así que un Server Component puede seguir renderizándolo igual.
// ============================================================================

const BaseLink = createNavigation(routing).Link;

type BaseLinkProps = ComponentProps<typeof BaseLink>;

export type LinkProps = Omit<BaseLinkProps, "href"> & { href: NavigationHref };

export const Link = forwardRef<HTMLAnchorElement, LinkProps>(function Link(
  { href, locale, ...rest },
  ref,
) {
  const currentLocale = useLocale();
  return (
    <BaseLink
      ref={ref}
      href={toPublicHref(href, locale ?? currentLocale) as BaseLinkProps["href"]}
      locale={locale}
      {...rest}
    />
  );
});
