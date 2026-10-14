/**
 * Extrae el ID de un video de YouTube desde URLs habituales.
 * Acepta watch, youtu.be, Shorts, embed y live.
 */
export function extractYoutubeId(raw: string): string | null {
  const input = raw.trim();
  if (!input) return null;

  try {
    const url = new URL(input.startsWith("http") ? input : `https://${input}`);
    const host = url.hostname.replace(/^www\./, "");

    if (host === "youtu.be") {
      const id = url.pathname.split("/").filter(Boolean)[0] ?? "";
      return isYoutubeId(id) ? id : null;
    }

    if (
      host === "youtube.com" ||
      host === "m.youtube.com" ||
      host === "youtube-nocookie.com"
    ) {
      const v = url.searchParams.get("v");
      if (v && isYoutubeId(v)) return v;

      const parts = url.pathname.split("/").filter(Boolean);
      const kind = parts[0];
      const id = parts[1];
      if (
        id &&
        isYoutubeId(id) &&
        (kind === "embed" || kind === "shorts" || kind === "live" || kind === "v")
      ) {
        return id;
      }
    }
  } catch {
    return null;
  }

  return null;
}

function isYoutubeId(id: string): boolean {
  return /^[\w-]{11}$/.test(id);
}
