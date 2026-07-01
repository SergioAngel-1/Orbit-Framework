import { cn } from "@/lib/utils";
import { Breadcrumb, type BreadcrumbItem } from "./breadcrumb";

export interface PageHeroProps {
  title: string;
  eyebrow?: string;
  subtitle?: string;
  /** URL de imagen de fondo. Solo se usa cuando `overlay` es `true`. */
  image?: string;
  breadcrumbs?: BreadcrumbItem[];
  centered?: boolean;
  /** Fondo oscuro sólido. */
  dark?: boolean;
  /** Imagen de fondo con velo oscuro encima (requiere `image`). */
  overlay?: boolean;
  className?: string;
}

export function PageHero({
  title,
  eyebrow,
  subtitle,
  image,
  breadcrumbs,
  centered = false,
  dark = false,
  overlay = false,
  className,
}: PageHeroProps) {
  const isDark = dark || overlay;

  if (overlay && image) {
    return (
      <div className={cn("relative overflow-hidden", className)}>
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={image} alt="" className="absolute inset-0 h-full w-full object-cover" />
        <div className="absolute inset-0 bg-gray-900/65" />
        <div className="relative px-4 py-14 sm:px-6 md:py-20 lg:px-10">
          <div className={cn("mx-auto max-w-7xl", centered && "text-center")}>
            {breadcrumbs && (
              <div className="mb-5">
                <Breadcrumb items={breadcrumbs} light />
              </div>
            )}
            {eyebrow && (
              <p className="mb-2 text-[10px] tracking-[0.2em] text-white/50 md:text-[11px]">
                {eyebrow}
              </p>
            )}
            <h1 className="mb-3 font-heading text-[32px] leading-tight text-white md:text-[42px] lg:text-[52px]">
              {title}
            </h1>
            {subtitle && (
              <p className="max-w-xl font-sans text-[14px] font-light leading-relaxed text-white/65 md:text-[16px]">
                {subtitle}
              </p>
            )}
          </div>
        </div>
      </div>
    );
  }

  return (
    <div
      className={cn(
        "border-b px-4 pb-10 pt-8 sm:px-6 md:pb-12 lg:px-10",
        dark ? "border-white/10 bg-gray-900" : "border-[--foreground]/10 bg-surface",
        className,
      )}
    >
      <div className={cn("mx-auto max-w-7xl", centered && "text-center")}>
        {breadcrumbs && (
          <div className="mb-4">
            <Breadcrumb items={breadcrumbs} light={dark} />
          </div>
        )}
        {eyebrow && (
          <p
            className={cn(
              "mb-1 text-[10px] tracking-[0.2em] md:text-[11px]",
              isDark ? "text-white/40" : "text-[--foreground]/40",
            )}
          >
            {eyebrow}
          </p>
        )}
        <h1
          className={cn(
            "font-heading text-[28px] leading-tight md:text-[38px] lg:text-[44px]",
            isDark ? "text-white" : "text-[--foreground]",
            subtitle ? "mb-3" : "mb-0",
          )}
        >
          {title}
        </h1>
        {subtitle && (
          <p
            className={cn(
              "max-w-2xl font-sans text-[13px] font-light leading-relaxed md:text-[15px]",
              isDark ? "text-white/60" : "text-[--foreground]/50",
              centered && "mx-auto",
            )}
          >
            {subtitle}
          </p>
        )}
      </div>
    </div>
  );
}
