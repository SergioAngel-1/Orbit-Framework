import type { SiteConfig } from "./types";

/** Redes con icono en UI (no wikipedia/wikidata: solo sameAs). */
export type SocialPlatform =
  "facebook" | "instagram" | "youtube" | "twitter" | "linkedin" | "whatsapp";

export interface SocialLinkEntry {
  platform: SocialPlatform;
  href: string;
  label: string;
  /**
   * Solo para un enlace de WhatsApp: el nombre de la línea que abre, tal como lo
   * define la instancia (p. ej. para el evento `Contact` de Meta). El núcleo no
   * conoce líneas: el enrutado por línea vive en la instancia.
   */
  contactLine?: string;
}

const UI_PLATFORMS: Exclude<SocialPlatform, "whatsapp">[] = [
  "facebook",
  "instagram",
  "youtube",
  "twitter",
  "linkedin",
];

type SocialKind = SocialPlatform | "wikipedia" | "wikidata";

/** Parte un campo del panel: `user1 | user2 | user3`. */
export function splitSocialValues(raw: string): string[] {
  return (raw || "")
    .split("|")
    .map((s) => s.trim())
    .filter((s) => s.length > 0);
}

/**
 * Extrae el “usuario” desde un valor de panel.
 * Acepta handle puro; si pegan una URL por error, toma el último segmento útil.
 */
function normalizeUser(raw: string, kind: SocialKind): string | null {
  let v = (raw || "").trim();
  if (!v) return null;

  if (/^https?:\/\//i.test(v)) {
    try {
      const path = new URL(v).pathname.replace(/\/+$/, "");
      const parts = path.split("/").filter(Boolean);
      if (kind === "linkedin") {
        const companyIdx = parts.indexOf("company");
        if (companyIdx >= 0 && parts[companyIdx + 1]) return parts[companyIdx + 1];
        const inIdx = parts.indexOf("in");
        if (inIdx >= 0 && parts[inIdx + 1]) return parts[inIdx + 1];
      }
      if (kind === "youtube") {
        const at = parts.find((p) => p.startsWith("@"));
        if (at) return at.replace(/^@/, "");
        const cIdx = parts.indexOf("c");
        if (cIdx >= 0 && parts[cIdx + 1]) return parts[cIdx + 1];
        const channelIdx = parts.indexOf("channel");
        if (channelIdx >= 0 && parts[channelIdx + 1]) return parts[channelIdx + 1];
      }
      if (kind === "wikipedia" || kind === "wikidata") {
        const wikiIdx = parts.indexOf("wiki");
        if (wikiIdx >= 0 && parts[wikiIdx + 1]) {
          return decodeURIComponent(parts[wikiIdx + 1]);
        }
      }
      const last = parts[parts.length - 1];
      if (last) return last.replace(/^@/, "");
    } catch {
      /* valor raro: seguir con strip básico */
    }
  }

  v = v.replace(/^@/, "").trim();
  return v || null;
}

function buildUrl(user: string, kind: Exclude<SocialKind, "whatsapp">): string | null {
  switch (kind) {
    case "twitter":
      return `https://twitter.com/${user}`;
    case "instagram":
      return `https://instagram.com/${user}`;
    case "facebook":
      return `https://facebook.com/${user}`;
    case "youtube":
      return `https://youtube.com/@${user}`;
    case "linkedin":
      return `https://www.linkedin.com/company/${user}`;
    case "wikipedia": {
      const title = user.replace(/ /g, "_");
      return `https://es.wikipedia.org/wiki/${encodeURIComponent(title).replace(/%2F/gi, "/")}`;
    }
    case "wikidata": {
      const id = /^Q\d+$/i.test(user)
        ? user.toUpperCase()
        : `Q${user.replace(/^Q/i, "")}`;
      if (!/^Q\d+$/i.test(id)) return null;
      return `https://www.wikidata.org/wiki/${id}`;
    }
    default:
      return null;
  }
}

export interface ResolvedSocialLink {
  user: string;
  href: string;
}

/**
 * Resuelve todas las cuentas de un campo (`a | b`).
 * Vacío → [].
 */
export function resolveSocialUrls(
  value: string,
  kind: SocialKind,
): ResolvedSocialLink[] {
  const parts = splitSocialValues(value);
  const out: ResolvedSocialLink[] = [];

  for (const part of parts) {
    if (kind === "whatsapp") {
      const href = whatsappHref(part);
      if (!href) continue;
      const user = part.replace(/\D/g, "");
      out.push({ user, href });
      continue;
    }
    const user = normalizeUser(part, kind);
    if (!user) continue;
    const href = buildUrl(user, kind);
    if (!href) continue;
    out.push({ user, href });
  }

  return out;
}

/** Número internacional → wa.me. Acepta espacios/+; vacía → null. */
export function whatsappHref(raw: string, message?: string): string | null {
  // Si viene "a | b", usa solo el primero (el float es un único botón).
  const first = splitSocialValues(raw)[0] ?? raw;
  const digits = (dialableNumber(first) ?? "").replace(/\D/g, "");
  if (!digits) return null;
  // `text` precarga el mensaje en el chat (wa.me/<número>?text=…).
  const text = message?.trim();
  return text
    ? `https://wa.me/${digits}?text=${encodeURIComponent(text)}`
    : `https://wa.me/${digits}`;
}

export type SocialLabelFn = (platform: SocialPlatform) => string;

/**
 * Lista de redes configuradas para footer/contacto.
 * Un icono por cuenta; si hay varias en el mismo campo, la etiqueta incluye el usuario.
 */
export function getConfiguredSocialLinks(
  social: SiteConfig["social"],
  labelFor: SocialLabelFn,
  extras: SocialLinkEntry[] = [],
): SocialLinkEntry[] {
  const items: SocialLinkEntry[] = [];
  for (const platform of UI_PLATFORMS) {
    const resolved = resolveSocialUrls(social[platform], platform);
    const base = labelFor(platform);
    for (const { user, href } of resolved) {
      const label = resolved.length > 1 ? `${base} · ${user}` : base;
      items.push({ platform, href, label });
    }
  }
  for (const extra of extras) {
    const href = (extra.href || "").trim();
    if (!href || href === "#") continue;
    items.push({ ...extra, href });
  }
  return items;
}

/** sameAs para JSON-LD (incluye wikipedia/wikidata/whatsapp; todas las cuentas). */
export function collectSocialSameAs(social: SiteConfig["social"]): string[] {
  const kinds: SocialKind[] = [
    "twitter",
    "instagram",
    "facebook",
    "youtube",
    "linkedin",
    "whatsapp",
    "wikipedia",
    "wikidata",
  ];
  return kinds.flatMap((kind) =>
    resolveSocialUrls(social[kind as keyof SiteConfig["social"]] ?? "", kind).map(
      (r) => r.href,
    ),
  );
}

/**
 * Número marcable desde el texto de display, o `null` si no hay dígitos.
 *
 * Versión genérica: conserva los dígitos (y un `+` inicial si lo trae). La
 * normalización por país (p. ej. indicativos antiguos que ya no marcan) llega
 * con el paquete de país en la tarea 52 (hwe-geo): aquí no se supone ningún país.
 */
export function dialableNumber(display: string): string | null {
  const raw = (display || "").trim();
  if (!raw) return null;
  const digits = raw.replace(/[^\d+]/g, "");
  if (!digits) return null;
  return digits;
}
