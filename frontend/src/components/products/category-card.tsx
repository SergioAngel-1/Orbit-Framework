import Image from "next/image";
import { Link } from "@/i18n/navigation";
import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";

type CategoryTheme = "brand" | "secondary" | "accent" | "surface";

export interface CategoryCardProps {
  href: string;
  label: string;
  image?: string | null;
  /** Tinte temático del fondo de la tarjeta. */
  theme?: CategoryTheme;
  loading?: boolean;
  className?: string;
}

const themeClasses: Record<CategoryTheme, string> = {
  brand: "bg-brand/10 border-brand/20",
  secondary: "bg-secondary/10 border-secondary/20",
  accent: "bg-accent/15 border-accent/25",
  surface: "bg-surface border-gray-200 dark:border-gray-700",
};

export function CategoryCard({
  href,
  label,
  image,
  theme = "surface",
  loading = false,
  className,
}: CategoryCardProps) {
  if (loading) {
    return (
      <div className={cn("overflow-hidden rounded-2xl", className)}>
        <Skeleton variant="block" className="h-[220px] w-full rounded-2xl" />
      </div>
    );
  }

  return (
    <Link
      href={href}
      className={cn(
        "group block overflow-hidden rounded-2xl border shadow-md transition-all duration-300 hover:scale-[1.02] hover:shadow-xl",
        themeClasses[theme],
        className,
      )}
    >
      <div className="p-3 pb-0">
        <div className="relative h-[170px] overflow-hidden rounded-xl bg-gray-100 dark:bg-gray-800">
          {image ? (
            <Image
              src={image}
              alt={label}
              fill
              sizes="(max-width: 768px) 50vw, 25vw"
              className="object-cover transition-transform duration-300 group-hover:scale-105"
            />
          ) : (
            <div className="flex h-full items-center justify-center text-3xl font-bold text-gray-300">
              {label.charAt(0)}
            </div>
          )}
        </div>
      </div>
      <div className="px-5 py-4 text-center">
        <span className="font-heading text-[15px] text-[--foreground]">{label}</span>
      </div>
    </Link>
  );
}
