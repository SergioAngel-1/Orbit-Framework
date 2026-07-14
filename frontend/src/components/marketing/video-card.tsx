import Image from "next/image";
import { Card } from "@/components/ui";
import { cn } from "@/lib/utils";

export interface VideoCardProps {
  thumbnailUrl: string;
  title: string;
  channel: string;
  /** Avatar del canal. Si falta, se muestran las iniciales. */
  channelAvatarUrl?: string;
  /** Vistas ya formateadas (p. ej. "1.2K"). */
  views: string;
  /** Fecha de publicación ya formateada (p. ej. "hace 2 semanas"). */
  publishedAt: string;
  /** Duración ya formateada (p. ej. "12:04"). */
  duration?: string;
  /** URL de reproducción. Si falta, la tarjeta no es un enlace. */
  videoUrl?: string;
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

export function VideoCard({
  thumbnailUrl,
  title,
  channel,
  channelAvatarUrl,
  views,
  publishedAt,
  duration,
  videoUrl,
  className,
}: VideoCardProps) {
  const inner = (
    <>
      <div className="relative h-[140px] overflow-hidden">
        <Image
          src={thumbnailUrl}
          alt={title}
          fill
          className="object-cover transition-transform duration-500 group-hover:scale-105"
        />
        <div className="absolute inset-0 bg-[--foreground]/0 transition-colors duration-300 group-hover:bg-[--foreground]/10" />
        {duration && (
          <span className="absolute bottom-2 right-2 rounded bg-[--foreground]/90 px-1.5 py-0.5 font-sans text-[10px] text-white">
            {duration}
          </span>
        )}
        <div className="absolute inset-0 flex items-center justify-center opacity-0 transition-opacity duration-300 group-hover:opacity-100">
          <div className="flex h-12 w-12 items-center justify-center rounded-full bg-accent/90 shadow-lg">
            <svg
              className="ml-0.5 h-5 w-5 text-[--foreground]"
              viewBox="0 0 24 24"
              fill="currentColor"
              aria-hidden
            >
              <path d="M8 5v14l11-7z" />
            </svg>
          </div>
        </div>
      </div>

      <div className="p-4">
        <div className="flex gap-3">
          {channelAvatarUrl ? (
            <Image
              src={channelAvatarUrl}
              alt={channel}
              width={36}
              height={36}
              className="h-9 w-9 shrink-0 rounded-full object-cover"
            />
          ) : (
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-secondary/20 font-heading text-[10px] text-[--foreground]">
              {initials(channel)}
            </div>
          )}
          <div className="min-w-0 flex-1">
            <h4 className="mb-1 line-clamp-2 font-heading text-sm leading-tight text-[--foreground] transition-colors group-hover:text-brand">
              {title}
            </h4>
            <p className="font-sans text-[11px] text-[--foreground]/50">{channel}</p>
            <div className="flex items-center gap-1.5 font-sans text-[10px] text-[--foreground]/40">
              <span>{views} vistas</span>
              <span>·</span>
              <span>{publishedAt}</span>
            </div>
          </div>
        </div>
      </div>
    </>
  );

  const cardClass = cn("group overflow-hidden border-brand-light/30 p-0", className);

  if (videoUrl) {
    return (
      <Card
        as="article"
        hoverable
        padding="none"
        className={cn(cardClass, "transition-colors hover:border-brand/40")}
      >
        <a href={videoUrl} target="_blank" rel="noopener noreferrer" className="block">
          {inner}
        </a>
      </Card>
    );
  }

  return (
    <Card as="article" padding="none" className={cardClass}>
      {inner}
    </Card>
  );
}
