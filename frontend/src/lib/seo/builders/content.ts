import type { SiteConfig } from "@/lib/config/types";
import type { FaqItem } from "../faq";
import { cleanBase } from "./shared";

// ============================================================================
//  JSON-LD de contenido: `FAQPage`, `Article`/`BlogPosting`, `VideoObject` y
//  `BreadcrumbList`. Funciones puras.
// ============================================================================

/**
 * JSON-LD `FAQPage`. Google retiró los resultados enriquecidos de FAQ para todos
 * los sitios (7-5-2026): ya no dan fragmento en la SERP. Se mantiene porque
 * describe bien un bloque de preguntas visible en la página, no por una ventaja
 * en buscadores ni en motores generativos, que no está demostrada.
 */
export function buildFaqJsonLd(items: FaqItem[]) {
  return {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    mainEntity: items.map((item) => ({
      "@type": "Question",
      name: item.question,
      acceptedAnswer: { "@type": "Answer", text: item.answer },
    })),
  };
}

/**
 * JSON-LD `Article` para landings educativas y posts de blog.
 *
 * `author` y `publisher` son requeridos por Google para que un `Article` sea
 * elegible: sin `author` explícito se atribuye a la `Organization` del sitio
 * (referencia por `@id` al nodo del `@graph` global). `datePublished` /
 * `dateModified` se emiten solo si el llamante los tiene.
 */
export function buildArticleJsonLd(input: {
  headline: string;
  description: string;
  config: SiteConfig;
  image?: string;
  url: string;
  datePublished?: string;
  dateModified?: string;
  /** `id`: el `@id` de una entidad del grafo (p. ej. el del fundador). */
  author?: { name: string; url?: string; id?: string; description?: string };
  about?: string;
  /** `es` / `en` — el idioma REAL del cuerpo servido, no el de la URL. */
  inLanguage?: string;
  /** `BlogPosting` para las entradas del blog; `Article` por defecto. */
  type?: "Article" | "BlogPosting";
  /** Selectores CSS de los bloques óptimos para asistentes de voz/IA. */
  speakable?: string[];
}): Record<string, unknown> {
  const base = cleanBase(input.config.brand.url);
  const orgRef = base ? { "@id": `${base}/#organization` } : undefined;

  const node: Record<string, unknown> = {
    "@context": "https://schema.org",
    "@type": input.type ?? "Article",
    headline: input.headline,
    description: input.description,
    mainEntityOfPage: input.url,
    url: input.url,
  };
  if (input.image) node.image = [input.image];
  if (input.inLanguage) node.inLanguage = input.inLanguage;
  if (input.datePublished) node.datePublished = input.datePublished;
  if (input.dateModified) node.dateModified = input.dateModified;

  node.author = input.author
    ? {
        "@type": "Person",
        ...(input.author.id ? { "@id": input.author.id } : {}),
        name: input.author.name,
        ...(input.author.url ? { url: input.author.url } : {}),
        ...(input.author.description ? { description: input.author.description } : {}),
      }
    : (orgRef ?? { "@type": "Organization", name: input.config.brand.name });
  if (orgRef) node.publisher = orgRef;

  if (input.about) node.about = { "@id": input.about };
  if (input.speakable && input.speakable.length > 0) {
    node.speakable = {
      "@type": "SpeakableSpecification",
      cssSelector: input.speakable,
    };
  }
  return node;
}

/**
 * `uploadDate` como Google lo acepta para un vídeo: fecha y hora CON zona
 * horaria. El CMS guarda solo el día (`AAAA-MM-DD`, ver `isoDateOrMonth`) y un
 * día a secas hacía que Search Console avisara «falta la zona horaria» y, en
 * algunos casos, «valor de fecha y hora no válido».
 *
 * La hora real no se sabe: se emite mediodía UTC, que cae en el mismo día en
 * cualquier zona de -12 a +11 — la fecha que el cliente escribió no se desplaza. Una fecha con hora y zona se respeta tal cual. Un mes
 * a secas (`AAAA-MM`) o cualquier otra cosa devuelve `undefined`: Google lo da
 * por inválido, y mejor ningún dato que uno que invalide el nodo.
 */
export function videoUploadDateTime(value: string | undefined): string | undefined {
  const raw = (value ?? "").trim();
  if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return `${raw}T12:00:00Z`;
  if (
    /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/.test(raw) &&
    !Number.isNaN(Date.parse(raw))
  ) {
    return raw;
  }
  return undefined;
}

/**
 * JSON-LD `VideoObject` para un embed de YouTube. `uploadDate` es opcional
 * aquí, pero Google la EXIGE: sin ella Search Console marca el vídeo («Falta el
 * campo uploadDate») y no hay resultado enriquecido — el nodo sigue siendo
 * señal útil para motores generativos. `thumbnailUrl` se deriva del ID de
 * YouTube.
 */
export function buildVideoObjectJsonLd(input: {
  name: string;
  description: string;
  youtubeId: string;
  uploadDate?: string;
}): Record<string, unknown> {
  const node: Record<string, unknown> = {
    "@context": "https://schema.org",
    "@type": "VideoObject",
    name: input.name,
    description: input.description,
    thumbnailUrl: [
      `https://i.ytimg.com/vi/${input.youtubeId}/hqdefault.jpg`,
      `https://i.ytimg.com/vi/${input.youtubeId}/maxresdefault.jpg`,
    ],
    embedUrl: `https://www.youtube-nocookie.com/embed/${input.youtubeId}`,
    contentUrl: `https://www.youtube.com/watch?v=${input.youtubeId}`,
  };
  const uploadDate = videoUploadDateTime(input.uploadDate);
  if (uploadDate) node.uploadDate = uploadDate;
  return node;
}

export interface BreadcrumbItem {
  name: string;
  url: string;
}

/** JSON-LD `BreadcrumbList` a partir de una lista ordenada de migas. */
export function buildBreadcrumbJsonLd(items: BreadcrumbItem[]) {
  return {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: items.map((item, i) => ({
      "@type": "ListItem",
      position: i + 1,
      name: item.name,
      item: item.url,
    })),
  };
}
