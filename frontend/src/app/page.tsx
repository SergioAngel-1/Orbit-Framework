import Image from "next/image";
import { fetchGraphQL } from "@/lib/graphql-client";
import { LATEST_POSTS_QUERY } from "@/lib/queries";
import type { PostsQueryResponse, WPPost } from "@/types/wordpress";

// ----------------------------------------------------------------------------
//  INCREMENTAL STATIC REGENERATION (ISR)
//  La página se genera estáticamente y se regenera, como máximo, cada 60s.
//  Esto se combina con `next: { revalidate }` del cliente GraphQL.
// ----------------------------------------------------------------------------
export const revalidate = 60;

/** Convierte una fecha ISO de WordPress en formato legible en español. */
function formatDate(iso: string): string {
  try {
    return new Intl.DateTimeFormat("es-ES", {
      day: "numeric",
      month: "long",
      year: "numeric",
    }).format(new Date(iso));
  } catch {
    return iso;
  }
}

/** Elimina las etiquetas HTML del excerpt que devuelve WordPress. */
function stripHtml(html: string): string {
  return html
    .replace(/<[^>]*>/g, "")
    .replace(/&hellip;/g, "…")
    .replace(/&nbsp;/g, " ")
    .trim();
}

async function getLatestPosts(): Promise<WPPost[]> {
  const data = await fetchGraphQL<PostsQueryResponse>(LATEST_POSTS_QUERY, {
    variables: { first: 5 },
    revalidate: 60,
    tags: ["posts"],
  });
  return data.posts.nodes;
}

function PostCard({ post }: { post: WPPost }) {
  const image = post.featuredImage?.node;

  return (
    <article className="group flex flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm transition-shadow hover:shadow-md dark:border-gray-800 dark:bg-gray-900">
      {image?.sourceUrl ? (
        <div className="relative aspect-[16/9] w-full overflow-hidden bg-gray-100 dark:bg-gray-800">
          <Image
            src={image.sourceUrl}
            alt={image.altText || post.title}
            fill
            sizes="(max-width: 768px) 100vw, 50vw"
            className="object-cover transition-transform duration-300 group-hover:scale-105"
          />
        </div>
      ) : (
        <div className="flex aspect-[16/9] w-full items-center justify-center bg-gradient-to-br from-brand-light to-brand-dark text-2xl font-bold text-white">
          {post.title.charAt(0).toUpperCase()}
        </div>
      )}

      <div className="flex flex-1 flex-col p-6">
        <div className="mb-2 flex items-center gap-2 text-xs font-medium text-gray-500 dark:text-gray-400">
          <time dateTime={post.date}>{formatDate(post.date)}</time>
          {post.author?.node?.name && (
            <>
              <span aria-hidden>·</span>
              <span>{post.author.node.name}</span>
            </>
          )}
        </div>

        <h2 className="mb-2 text-xl font-semibold leading-snug tracking-tight transition-colors group-hover:text-brand">
          {post.title}
        </h2>

        {post.excerpt && (
          <p className="line-clamp-3 flex-1 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
            {stripHtml(post.excerpt)}
          </p>
        )}

        <span className="mt-4 inline-flex items-center text-sm font-medium text-brand">
          Leer más →
        </span>
      </div>
    </article>
  );
}

export default async function HomePage() {
  let posts: WPPost[] = [];
  let errorMessage: string | null = null;

  try {
    posts = await getLatestPosts();
  } catch (error) {
    errorMessage =
      error instanceof Error ? error.message : "Error desconocido al cargar los posts.";
  }

  return (
    <div>
      <section className="mb-12">
        <h1 className="text-4xl font-extrabold tracking-tight sm:text-5xl">
          Últimas publicaciones
        </h1>
        <p className="mt-3 max-w-2xl text-lg text-gray-600 dark:text-gray-300">
          Contenido servido desde WordPress a través de WPGraphQL y renderizado
          de forma estática con regeneración incremental (ISR cada 60s).
        </p>
      </section>

      {errorMessage ? (
        <div className="rounded-xl border border-red-200 bg-red-50 p-6 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
          <p className="font-semibold">No se pudieron cargar los posts.</p>
          <p className="mt-1 text-sm opacity-80">{errorMessage}</p>
          <p className="mt-3 text-sm">
            Comprueba que WordPress está levantado y que WPGraphQL responde en{" "}
            <code className="rounded bg-red-100 px-1 dark:bg-red-900">
              /graphql
            </code>
            .
          </p>
        </div>
      ) : posts.length === 0 ? (
        <div className="rounded-xl border border-gray-200 bg-gray-50 p-6 text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
          Todavía no hay publicaciones. Crea contenido en el panel de WordPress.
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-8 sm:grid-cols-2">
          {posts.map((post) => (
            <PostCard key={post.id} post={post} />
          ))}
        </div>
      )}
    </div>
  );
}
