"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { Link, usePathname } from "@/i18n/navigation";
import { CartIndicator } from "@/components/cart/cart-indicator";
import { LocaleSwitcher } from "@/components/i18n/locale-switcher";
import { DarkModeToggle } from "@/components/ui/dark-mode-toggle";
import { cn } from "@/lib/utils";

interface NavChild {
  key: string;
  href: string;
}

interface NavItem {
  key: string;
  href: string;
  children?: NavChild[];
}

const NAV_ITEMS: NavItem[] = [
  { key: "home", href: "/" },
  {
    key: "store",
    href: "/products",
    children: [
      { key: "allProducts", href: "/products" },
      { key: "offers", href: "/products" },
    ],
  },
  { key: "blog", href: "/blog" },
  { key: "about", href: "/about" },
];

function ChevronDown({ open }: { open: boolean }) {
  return (
    <svg
      className={cn("h-3 w-3 transition-transform duration-200", open && "rotate-180")}
      fill="none"
      viewBox="0 0 24 24"
      stroke="currentColor"
      strokeWidth="2"
    >
      <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
    </svg>
  );
}

export interface SiteHeaderProps {
  /** URL del logo (config.brand.logo). Vacío/undefined = nombre del sitio como texto. */
  logoUrl?: string;
}

export function SiteHeader({ logoUrl }: SiteHeaderProps = {}) {
  const t = useTranslations("nav");
  const tSite = useTranslations("site");
  const pathname = usePathname();

  const [isOpen, setIsOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);
  const [openDropdown, setOpenDropdown] = useState<string | null>(null);

  useEffect(() => {
    const handleScroll = () => setScrolled(window.scrollY > 10);
    window.addEventListener("scroll", handleScroll);
    return () => window.removeEventListener("scroll", handleScroll);
  }, []);

  return (
    <header
      className={cn(
        "sticky top-0 z-50 overflow-visible border-b border-gray-200 transition-all duration-300 dark:border-gray-800",
        scrolled
          ? "bg-white/95 backdrop-blur-md dark:bg-black/80"
          : "bg-white/80 backdrop-blur dark:bg-black/50",
      )}
    >
      <div className="mx-auto max-w-7xl px-6 lg:px-10">
        <div className="flex h-16 items-center justify-between lg:h-[72px]">
          <Link
            href="/"
            className="flex items-center text-lg font-bold tracking-tight text-[--foreground]"
            aria-label={tSite("name")}
          >
            {logoUrl ? (
              // eslint-disable-next-line @next/next/no-img-element -- URL remota definida por la instancia (medioteca WP); no se conocen los dominios en build.
              <img src={logoUrl} alt={tSite("name")} className="h-8 w-auto" />
            ) : (
              tSite("name")
            )}
          </Link>

          {/* Navegación desktop */}
          <nav className="hidden items-center gap-1 lg:flex">
            {NAV_ITEMS.map((item) => {
              const isActive = pathname === item.href;

              if (item.children) {
                return (
                  <div
                    key={item.key}
                    className="relative"
                    onMouseEnter={() => setOpenDropdown(item.key)}
                    onMouseLeave={() => setOpenDropdown(null)}
                  >
                    <button
                      type="button"
                      className={cn(
                        "relative flex items-center gap-1.5 px-3 py-1 text-[13px] tracking-wide transition-colors duration-200",
                        isActive
                          ? "font-medium text-brand"
                          : "text-[--foreground]/60 hover:text-brand",
                      )}
                    >
                      {t(item.key)}
                      <ChevronDown open={openDropdown === item.key} />
                    </button>
                    <div
                      className={cn(
                        "absolute left-0 top-full z-[60] pt-2 transition-all duration-200",
                        openDropdown === item.key
                          ? "pointer-events-auto translate-y-0 opacity-100"
                          : "pointer-events-none -translate-y-2 opacity-0",
                      )}
                    >
                      <div className="min-w-[200px] rounded-xl border border-gray-200 bg-white py-2 shadow-lg dark:border-gray-800 dark:bg-gray-900">
                        {item.children.map((child) => (
                          <Link
                            key={child.key}
                            href={child.href}
                            className="block px-4 py-2.5 text-[13px] text-[--foreground]/70 transition-colors duration-150 hover:bg-surface hover:text-brand dark:hover:bg-gray-800"
                          >
                            {t(child.key)}
                          </Link>
                        ))}
                      </div>
                    </div>
                  </div>
                );
              }

              return (
                <Link
                  key={item.key}
                  href={item.href}
                  className={cn(
                    "relative px-3 py-1 text-[13px] tracking-wide transition-colors duration-200",
                    isActive
                      ? "font-medium text-brand"
                      : "text-[--foreground]/60 hover:text-brand",
                  )}
                >
                  {t(item.key)}
                </Link>
              );
            })}
          </nav>

          {/* Acciones desktop */}
          <div className="hidden items-center gap-4 text-sm font-medium text-gray-600 lg:flex dark:text-gray-300">
            <Link
              href="/contact"
              className="rounded-lg bg-brand px-5 py-2.5 font-heading text-[11px] tracking-[0.15em] text-white shadow-md transition-all duration-200 hover:bg-brand-dark hover:shadow-lg"
            >
              {t("contact")}
            </Link>
            <CartIndicator />
            <LocaleSwitcher />
            <DarkModeToggle className="text-gray-600 dark:text-gray-300" />
          </div>

          {/* Acciones móviles */}
          <div className="flex items-center gap-3 lg:hidden">
            <CartIndicator />
            <button
              type="button"
              className="flex h-10 w-10 flex-col items-center justify-center gap-1.5"
              onClick={() => setIsOpen(!isOpen)}
              aria-label={t("skipToContent")}
              aria-expanded={isOpen}
            >
              <span
                className={cn(
                  "h-0.5 w-6 origin-center rounded-full bg-[--foreground] transition-all duration-300",
                  isOpen && "translate-y-2 rotate-45",
                )}
              />
              <span
                className={cn(
                  "h-0.5 w-6 rounded-full bg-[--foreground] transition-all duration-300",
                  isOpen ? "scale-0 opacity-0" : "scale-100 opacity-100",
                )}
              />
              <span
                className={cn(
                  "h-0.5 w-6 origin-center rounded-full bg-[--foreground] transition-all duration-300",
                  isOpen && "-translate-y-2 -rotate-45",
                )}
              />
            </button>
          </div>
        </div>
      </div>

      {/* Menú móvil */}
      <div
        className={cn(
          "overflow-hidden transition-all duration-300 ease-in-out lg:hidden",
          isOpen ? "max-h-[600px] opacity-100" : "max-h-0 opacity-0",
        )}
      >
        <div className="border-t border-gray-200 px-6 pb-6 pt-2 dark:border-gray-800">
          <div className="flex flex-col gap-1">
            {NAV_ITEMS.map((item) => {
              const isActive = pathname === item.href;

              if (item.children) {
                return (
                  <div key={item.key}>
                    <button
                      type="button"
                      className={cn(
                        "flex w-full items-center justify-between rounded-lg px-4 py-3 text-left text-[14px] tracking-wide transition-all duration-200",
                        isActive
                          ? "bg-brand/10 font-medium text-brand"
                          : "text-[--foreground]/60 hover:bg-surface hover:text-[--foreground] dark:hover:bg-gray-800",
                      )}
                      onClick={() =>
                        setOpenDropdown(openDropdown === item.key ? null : item.key)
                      }
                    >
                      {t(item.key)}
                      <ChevronDown open={openDropdown === item.key} />
                    </button>
                    <div
                      className={cn(
                        "overflow-hidden transition-all duration-200",
                        openDropdown === item.key
                          ? "max-h-48 opacity-100"
                          : "max-h-0 opacity-0",
                      )}
                    >
                      <div className="py-1 pl-4">
                        {item.children.map((child) => (
                          <Link
                            key={child.key}
                            href={child.href}
                            className="block rounded-lg px-4 py-2 text-[13px] text-[--foreground]/50 transition-colors duration-150 hover:bg-surface hover:text-[--foreground] dark:hover:bg-gray-800"
                            onClick={() => setIsOpen(false)}
                          >
                            {t(child.key)}
                          </Link>
                        ))}
                      </div>
                    </div>
                  </div>
                );
              }

              return (
                <Link
                  key={item.key}
                  href={item.href}
                  className={cn(
                    "rounded-lg px-4 py-3 text-[14px] tracking-wide transition-all duration-200",
                    isActive
                      ? "bg-brand/10 font-medium text-brand"
                      : "text-[--foreground]/60 hover:bg-surface hover:text-[--foreground] dark:hover:bg-gray-800",
                  )}
                  onClick={() => setIsOpen(false)}
                >
                  {t(item.key)}
                </Link>
              );
            })}
          </div>

          <div className="mt-4 flex items-center justify-between gap-4 border-t border-gray-200 pt-4 dark:border-gray-800">
            <Link
              href="/contact"
              className="flex-1 rounded-lg bg-brand px-5 py-3 text-center font-heading text-[11px] tracking-[0.15em] text-white transition-all duration-200 hover:bg-brand-dark"
              onClick={() => setIsOpen(false)}
            >
              {t("contact")}
            </Link>
            <LocaleSwitcher />
            <DarkModeToggle className="text-gray-600 dark:text-gray-300" />
          </div>
        </div>
      </div>
    </header>
  );
}
