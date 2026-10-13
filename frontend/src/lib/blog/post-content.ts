/**
 * Parseo del contenido HTML de una entrada: marcadores de recomendación, TOC y
 * segmentos.
 *
 * Una entrada puede incrustar una recomendación con un comentario HTML que el
 * editor no pinta: `<!-- hwe-rec type="product" slug="camiseta-basica" -->`.
 * El frontend lo sustituye por la tarjeta del producto o de la entrada.
 */

import { stripHtml } from "@/lib/format";

export type RecType = "product" | "post";

export interface RecMarker {
  type: RecType;
  slug: string;
}

export interface PostHeading {
  id: string;
  text: string;
  level: 2 | 3;
}

export type PostSegment =
  { kind: "html"; html: string } | { kind: "rec"; index: number };

const REC_TYPES = new Set<string>(["product", "post"]);
const REC_COMMENT_RE = /<!--\s*hwe-rec\s+([\s\S]*?)-->/gi;

function parseRecAttrs(attrs: string): RecMarker | null {
  const typeM = /\btype\s*=\s*["']([^"']+)["']/i.exec(attrs);
  const slugM = /\bslug\s*=\s*["']([^"']+)["']/i.exec(attrs);
  const type = typeM?.[1]?.trim().toLowerCase();
  const slug = slugM?.[1]?.trim();
  // El slug acaba en una consulta y en un atributo: solo caracteres de slug.
  if (!type || !slug || !REC_TYPES.has(type) || !/^[a-z0-9_-]{1,200}$/i.test(slug)) {
    return null;
  }
  return { type: type as RecType, slug };
}

/** Extrae los comentarios `<!-- hwe-rec … -->` y los sustituye por marcadores. */
export function extractRecs(rawHtml: string): { html: string; recs: RecMarker[] } {
  const recs: RecMarker[] = [];
  const html = rawHtml.replace(REC_COMMENT_RE, (_full, attrs: string) => {
    const marker = parseRecAttrs(attrs);
    if (!marker) return "";
    const index = recs.length;
    recs.push(marker);
    return `<div data-hwe-rec="${index}"></div>`;
  });
  return { html, recs };
}

export function slugifyHeading(text: string): string {
  return (
    text
      .normalize("NFD")
      .replace(/\p{M}/gu, "")
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-|-$/g, "")
      .slice(0, 64) || "section"
  );
}

/**
 * Texto plano de un encabezado. Decodifica entidades porque la TOC lo pinta
 * como texto de React: un contenido importado trae `&nbsp;` entre palabras, que
 * saldría literal y, sin espacios reales donde partir, desbordaría en móvil.
 */
function headingText(html: string): string {
  return stripHtml(html).replace(/\s+/g, " ").trim();
}

/**
 * El mismo `&nbsp;` en el HTML del encabezado lo vuelve una sola palabra que el
 * navegador no puede partir: el título del cuerpo desbordaría igual que la TOC.
 */
const NBSP_RE = /&nbsp;|&#160;|&#xa0;| /gi;

/** Inyecta `id` en h2/h3 y construye la TOC. */
export function preparePostHeadings(html: string): {
  html: string;
  headings: PostHeading[];
} {
  const used = new Set<string>();
  const headings: PostHeading[] = [];
  const out = html.replace(
    /<h([23])(\s[^>]*)?>([\s\S]*?)<\/h\1>/gi,
    (_full, levelStr: string, attrs = "", inner: string) => {
      const level = Number(levelStr) as 2 | 3;
      const text = headingText(inner);
      const existing = /\bid\s*=\s*["']([^"']+)["']/i.exec(attrs)?.[1];
      let id = existing || slugifyHeading(text);
      if (used.has(id)) {
        let n = 2;
        while (used.has(`${id}-${n}`)) n += 1;
        id = `${id}-${n}`;
      }
      used.add(id);
      // Reserva también el slug del texto para colisiones con encabezados siguientes.
      const fromText = slugifyHeading(text);
      if (fromText !== id) used.add(fromText);
      headings.push({ id, text, level });
      const attrsClean = (attrs || "").replace(/\s*\bid\s*=\s*["'][^"']*["']/i, "");
      const innerClean = inner.replace(NBSP_RE, " ");
      return `<h${level}${attrsClean} id="${id}">${innerClean}</h${level}>`;
    },
  );
  return { html: out, headings };
}

const PLACEHOLDER_RE = /<div\s+data-hwe-rec="(\d+)"\s*><\/div>/gi;

/** Parte el HTML en segmentos prosa / recomendación. */
export function splitPostHtml(html: string): PostSegment[] {
  const segments: PostSegment[] = [];
  let last = 0;
  let m: RegExpExecArray | null;
  const re = new RegExp(PLACEHOLDER_RE.source, "gi");
  while ((m = re.exec(html)) !== null) {
    if (m.index > last) {
      segments.push({ kind: "html", html: html.slice(last, m.index) });
    }
    segments.push({ kind: "rec", index: Number(m[1]) });
    last = m.index + m[0].length;
  }
  if (last < html.length) {
    segments.push({ kind: "html", html: html.slice(last) });
  }
  return segments.filter((s) => s.kind === "rec" || s.html.trim().length > 0);
}
