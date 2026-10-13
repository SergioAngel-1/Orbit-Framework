import Image, { getImageProps } from "next/image";
import { preload } from "react-dom";
import { cn } from "@/lib/utils";
import { wpMediaInternalUrl } from "@/lib/wordpress/media";

/**
 * Imagen CMS / medioteca WP vía next/image.
 * Variante móvil opcional (breakpoint lg, mismo criterio que banners).
 * Si `src` está vacío, no renderiza nada (el padre muestra placeholder).
 */
export type CmsImageProps = {
  src: string;
  mobileSrc?: string;
  alt: string;
  className?: string;
  /** fill + parent relative; ignora width/height. */
  fill?: boolean;
  width?: number;
  height?: number;
  sizes?: string;
  priority?: boolean;
  /** object-fit; default cover cuando fill. */
  objectFit?: "cover" | "contain" | "fill" | "none";
  /**
   * Calidad de compresión de `next/image` (1–100). Sin valor, el 75 por
   * defecto, que va bien con fotografía. Súbela solo donde la compresión se
   * VEA: degradados amplios e ilustraciones planas, donde aparecen bandas.
   */
  quality?: number;
};

/** Mismo corte que el `lg:` de Tailwind (64rem). */
const DESKTOP_MEDIA = "(min-width: 1024px)";
const MOBILE_MEDIA = "(max-width: 1023.98px)";

/**
 * Variante móvil + escritorio como UNA sola imagen (`<picture>`, el patrón de
 * art direction de la doc de `next/image`). Antes eran dos `<Image>` con
 * `lg:hidden` / `hidden lg:block`: el CSS ocultaba una, pero con `priority`
 * Next precargaba LAS DOS sin `media`, así que cada pantalla descargaba la
 * imagen que no iba a pintar («preloaded but not used» en consola) y, en
 * móvil, se llevaba además el banner de escritorio. Ahora el navegador elige
 * la fuente y la precarga de cada variante lleva su `media`.
 */
function ArtDirectedImage({
  src,
  mobileSrc,
  alt,
  className,
  fill,
  width,
  height,
  sizes,
  priority,
  quality,
}: {
  src: string;
  mobileSrc: string;
  alt: string;
  className: string;
  fill: boolean;
  width?: number;
  height?: number;
  sizes?: string;
  priority: boolean;
  quality?: number;
}) {
  const common = fill
    ? { alt, sizes, quality, fill: true as const }
    : { alt, sizes, quality, width, height };
  const {
    props: { srcSet: desktop },
  } = getImageProps({ ...common, src });
  const {
    props: { srcSet: mobile, ...rest },
  } = getImageProps({ ...common, src: mobileSrc });

  if (priority) {
    for (const [srcSet, media, href] of [
      [mobile, MOBILE_MEDIA, mobileSrc],
      [desktop, DESKTOP_MEDIA, src],
    ] as const) {
      preload(href, {
        as: "image",
        imageSrcSet: srcSet,
        imageSizes: sizes,
        media,
        fetchPriority: "high",
      });
    }
  }

  // `contents`: el `<picture>` no genera caja propia, así que el `<img>` sigue
  // siendo el hijo directo del flex/grid del padre, como cuando esto era un
  // `<Image>` suelto. Sin él, en un padre `flex items-center` el `<picture>`
  // se encogía a su contenido y el `w-full` de la imagen dejaba de medir el
  // ancho del padre (el hero de las landings de producto dejó de ir a sangrado).
  return (
    <picture className="contents">
      <source media={DESKTOP_MEDIA} srcSet={desktop} sizes={sizes} />
      <source media={MOBILE_MEDIA} srcSet={mobile} sizes={sizes} />
      {/* eslint-disable-next-line jsx-a11y/alt-text -- el alt llega en las props de getImageProps */}
      <img
        {...rest}
        loading={priority ? "eager" : "lazy"}
        fetchPriority={priority ? "high" : undefined}
        className={className}
      />
    </picture>
  );
}

export function CmsImage({
  src,
  mobileSrc,
  alt,
  className,
  fill = false,
  width,
  height,
  sizes,
  priority = false,
  objectFit = "cover",
  quality,
}: CmsImageProps) {
  const trimmed = src.trim();
  if (!trimmed) return null;

  // El optimizador de next/image hace el fetch server-side: usar el ORIGEN
  // INTERNO de WP (resoluble por el servidor/Docker), no la URL pública del
  // navegador. En modo híbrido interno === público → no-op.
  const resolved = wpMediaInternalUrl(trimmed);
  const resolvedMobile = mobileSrc ? wpMediaInternalUrl(mobileSrc) : undefined;

  const computedSizes = sizes ?? (fill ? "100vw" : undefined);

  const fitClass =
    objectFit === "cover"
      ? "object-cover"
      : objectFit === "contain"
        ? "object-contain"
        : objectFit === "fill"
          ? "object-fill"
          : "object-none";

  const w = width ?? 800;
  const h = height ?? 600;

  if (resolvedMobile) {
    return (
      <ArtDirectedImage
        src={resolved}
        mobileSrc={resolvedMobile}
        alt={alt}
        className={cn(fitClass, className)}
        fill={fill}
        width={w}
        height={h}
        sizes={computedSizes}
        priority={priority}
        quality={quality}
      />
    );
  }

  if (fill) {
    return (
      <Image
        src={resolved}
        alt={alt}
        sizes={computedSizes}
        priority={priority}
        fetchPriority={priority ? "high" : undefined}
        quality={quality}
        fill
        className={cn(fitClass, className)}
      />
    );
  }

  return (
    <Image
      src={resolved}
      alt={alt}
      width={w}
      height={h}
      sizes={computedSizes}
      priority={priority}
      fetchPriority={priority ? "high" : undefined}
      quality={quality}
      className={cn(fitClass, className)}
    />
  );
}
