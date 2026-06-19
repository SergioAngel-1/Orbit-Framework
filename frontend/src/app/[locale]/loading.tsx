import { ProductCardSkeleton } from "@/components/ui/skeleton";

// Esqueleto de carga para la capa del locale (rutas de productos, home, etc.).
export default function LocaleLoading() {
  return (
    <div className="space-y-8">
      {/* Encabezado de sección */}
      <div className="flex flex-col gap-2">
        <div className="h-8 w-48 animate-pulse rounded-lg bg-gray-200 dark:bg-gray-700" />
        <div className="h-4 w-72 animate-pulse rounded-md bg-gray-100 dark:bg-gray-800" />
      </div>
      {/* Grid de productos */}
      <div className="grid grid-cols-2 gap-6 lg:grid-cols-4">
        {Array.from({ length: 8 }).map((_, i) => (
          <ProductCardSkeleton key={i} />
        ))}
      </div>
    </div>
  );
}
