/**
 * Lógica PURA del archivo del blog (`/blog/archivo`): agrupar por año. Sin
 * `server-only` porque no lee nada del CMS (eso es `getPostsIndex`, en
 * `posts.ts`) y así se prueba sin simular el servidor.
 */

export interface ArchivePost {
  slug: string;
  title: string;
  /** Fecha de WordPress en la hora del sitio, sin zona (`2026-09-01T15:46:17`). */
  date: string;
}

export interface ArchiveYear<T extends ArchivePost = ArchivePost> {
  year: string;
  posts: T[];
}

/**
 * Agrupa por año, del más reciente al más antiguo; dentro de cada año respeta
 * el orden recibido (el CMS las entrega ya de más nueva a más vieja). El año se
 * lee del texto de la fecha y NO de un `Date`: la fecha es la hora local del
 * sitio, y pasarla por UTC mandaría el 31 de diciembre a las 23:59 al año
 * siguiente. Una fecha ilegible no pierde la entrada: va a un grupo de año
 * vacío, al final.
 */
export function groupPostsByYear<T extends ArchivePost>(posts: T[]): ArchiveYear<T>[] {
  const byYear = new Map<string, T[]>();
  for (const post of posts) {
    const match = /^(\d{4})-/.exec(post.date);
    const year = match ? match[1] : "";
    const list = byYear.get(year);
    if (list) list.push(post);
    else byYear.set(year, [post]);
  }
  return [...byYear.entries()]
    .sort(([a], [b]) => (a === "" ? 1 : b === "" ? -1 : b.localeCompare(a)))
    .map(([year, list]) => ({ year, posts: list }));
}
