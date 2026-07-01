import Image from "next/image";
import { Card } from "@/components/ui";
import { cn } from "@/lib/utils";

export interface AllyCardProps {
  /** URL del logotipo del aliado. Si falta, se muestran las iniciales. */
  logoUrl?: string;
  name: string;
  description: string;
  /** URL del sitio web del aliado. Si falta, se oculta el enlace. */
  websiteUrl?: string;
  className?: string;
}

function initials(name: string): string {
  return name
    .split(" ")
    .map((word) => word[0] ?? "")
    .join("")
    .slice(0, 2)
    .toUpperCase();
}

export function AllyCard({ logoUrl, name, description, websiteUrl, className }: AllyCardProps) {
  const content = (
    <>
      <div className="flex items-start gap-4">
        <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl border border-brand-light/30 bg-surface transition-colors group-hover:border-brand/40">
          {logoUrl ? (
            <Image
              src={logoUrl}
              alt={name}
              width={40}
              height={40}
              className="h-10 w-10 object-contain"
            />
          ) : (
            <span className="font-heading text-lg text-brand">{initials(name)}</span>
          )}
        </div>
        <div className="min-w-0 flex-1">
          <h4 className="mb-1 font-heading text-[15px] text-[--foreground] transition-colors group-hover:text-brand">
            {name}
          </h4>
          <p className="line-clamp-2 font-sans text-xs leading-relaxed text-[--foreground]/50">
            {description}
          </p>
        </div>
      </div>
      {websiteUrl && (
        <div className="mt-4 border-t border-brand-light/30 pt-3">
          <span className="flex items-center gap-1.5 font-sans text-[10px] text-brand transition-all group-hover:gap-2">
            <svg className="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden>
              <circle cx="12" cy="12" r="10" />
              <line x1="2" y1="12" x2="22" y2="12" />
              <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
            </svg>
            Visitar sitio web
            <svg className="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden>
              <path d="M5 12h14M12 5l7 7-7 7" />
            </svg>
          </span>
        </div>
      )}
    </>
  );

  if (websiteUrl) {
    return (
      <Card
        as="article"
        hoverable
        className={cn("group border-brand-light/30 transition-colors hover:border-brand/40", className)}
      >
        <a href={websiteUrl} target="_blank" rel="noopener noreferrer" className="block">
          {content}
        </a>
      </Card>
    );
  }

  return (
    <Card as="article" className={cn("group border-brand-light/30", className)}>
      {content}
    </Card>
  );
}
