import { getLocale, getTranslations } from "next-intl/server";
import { Link } from "@/i18n/navigation";
import { getSiteConfig } from "@/lib/config";
import { getMenu } from "@/lib/navigation/menu";
import type { MenuLink } from "@/lib/navigation/types";

export interface FooterLink {
  /** Texto ya traducido (no una key de i18n: el caller decide el idioma). */
  label: string;
  href: string;
}

export interface FooterColumn {
  heading: string;
  links: FooterLink[];
}

/**
 * Icono (path SVG) por red social. Genérico — no depende del negocio.
 * El HREF sí depende de la instancia: se resuelve desde `config.social.*`
 * (HWE Control Center), no está hardcodeado aquí.
 */
const SOCIAL_ICON_PATHS: Record<"instagram" | "facebook" | "youtube", string> = {
  instagram:
    "M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z",
  facebook:
    "M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z",
  youtube:
    "M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z",
};

export interface SiteFooterProps {
  /**
   * Columnas de enlaces. Por defecto usa el menú `footer` de WordPress
   * (Apariencia → Menús) si está asignado; si no, reutiliza las páginas/
   * traducciones que YA existen en el framework base (tienda, blog, sobre
   * nosotros, legal) — sin inventar categorías de catálogo de un negocio
   * concreto. Pásalas explícitas si la instancia necesita otra estructura.
   */
  columns?: FooterColumn[];
}

/**
 * Convierte el menú `footer` de WP en columnas: cada item raíz CON hijos es
 * una columna (heading = label del padre); los items raíz sueltos se agrupan
 * en una única columna final con el heading genérico indicado.
 */
function menuToColumns(menu: MenuLink[], looseHeading: string): FooterColumn[] {
  const columns: FooterColumn[] = [];
  const loose: FooterLink[] = [];

  for (const item of menu) {
    if (item.children && item.children.length > 0) {
      columns.push({
        heading: item.label,
        links: item.children.map((c) => ({ label: c.label, href: c.href })),
      });
    } else {
      loose.push({ label: item.label, href: item.href });
    }
  }
  if (loose.length > 0) {
    columns.push({ heading: looseHeading, links: loose });
  }
  return columns;
}

export async function SiteFooter({ columns }: SiteFooterProps = {}) {
  const [tFooter, tNav, tSite, config, locale] = await Promise.all([
    getTranslations("footer"),
    getTranslations("nav"),
    getTranslations("site"),
    getSiteConfig(),
    getLocale(),
  ]);

  const footerMenu = await getMenu("footer", locale);

  const siteName = tSite("name");
  const year = new Date().getFullYear();

  // Prioridad: props explícitas > menú `footer` de WP > defaults i18n.
  const footerColumns: FooterColumn[] = columns ??
    (footerMenu ? menuToColumns(footerMenu, tFooter("links")) : null) ?? [
      {
        heading: tNav("store"),
        links: [
          { label: tNav("allProducts"), href: "/products" },
          { label: tNav("offers"), href: "/products" },
          { label: tNav("blog"), href: "/blog" },
        ],
      },
      {
        heading: tFooter("company"),
        links: [
          { label: tNav("about"), href: "/about" },
          { label: tNav("contact"), href: "/contact" },
          { label: tNav("terms"), href: "/legal/terms" },
        ],
      },
    ];

  const socialLinks = (
    [
      ["instagram", config.social.instagram],
      ["facebook", config.social.facebook],
      ["youtube", config.social.youtube],
    ] as const
  ).filter((entry): entry is [keyof typeof SOCIAL_ICON_PATHS, string] =>
    Boolean(entry[1]),
  );

  return (
    <footer className="bg-gray-900 px-4 py-12 sm:px-6 md:py-16 lg:px-10">
      <div className="mx-auto max-w-7xl">
        <div className="mb-10 grid grid-cols-2 gap-8 md:grid-cols-4 md:gap-10">
          {/* Columna de marca */}
          <div className="col-span-2 md:col-span-1">
            <p className="mb-4 font-heading text-lg text-white">{siteName}</p>
            {config.brand.tagline && (
              <p className="mb-5 max-w-xs text-[12px] leading-relaxed text-white/60">
                {config.brand.tagline}
              </p>
            )}
            {socialLinks.length > 0 && (
              <div className="flex gap-2.5">
                {socialLinks.map(([platform, href]) => (
                  <a
                    key={platform}
                    href={href}
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label={platform}
                    className="flex h-9 w-9 items-center justify-center rounded-full border border-white/20 transition-all duration-200 hover:border-white/50 hover:bg-white/10"
                  >
                    <svg
                      className="h-4 w-4 text-white/60"
                      viewBox="0 0 24 24"
                      fill="currentColor"
                    >
                      <path d={SOCIAL_ICON_PATHS[platform]} />
                    </svg>
                  </a>
                ))}
              </div>
            )}
          </div>

          {/* Columnas de enlaces */}
          {footerColumns.map((col) => (
            <div key={col.heading}>
              <p className="mb-4 text-[10px] tracking-[0.2em] text-white/60">
                {col.heading}
              </p>
              {col.links.map((link) => (
                <Link
                  key={link.href + link.label}
                  href={link.href}
                  className="mb-2.5 block text-[13px] text-white/70 transition-colors duration-150 hover:text-white"
                >
                  {link.label}
                </Link>
              ))}
            </div>
          ))}
        </div>

        {/* Enlaces legales */}
        <nav
          aria-label={tNav("legal")}
          className="mb-6 flex flex-wrap justify-center gap-x-5 gap-y-2 border-t border-white/10 pt-6 md:justify-start"
        >
          <Link
            href="/legal/privacy"
            className="text-[12px] text-white/60 hover:text-white"
          >
            {tNav("privacy")}
          </Link>
          <Link
            href="/legal/cookies"
            className="text-[12px] text-white/60 hover:text-white"
          >
            {tNav("cookies")}
          </Link>
          <Link
            href="/legal/terms"
            className="text-[12px] text-white/60 hover:text-white"
          >
            {tNav("terms")}
          </Link>
          <Link
            href="/legal/returns"
            className="text-[12px] text-white/60 hover:text-white"
          >
            {tNav("returns")}
          </Link>
        </nav>

        <div className="flex flex-col items-center justify-between gap-3 md:flex-row">
          <p className="text-center text-[11px] text-white/60 md:text-left">
            © {year} {siteName} · {tFooter("rights")}
          </p>
        </div>
      </div>
    </footer>
  );
}
