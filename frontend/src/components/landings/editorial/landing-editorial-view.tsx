import type { CSSProperties, ReactNode } from "react";
import { getTranslations } from "next-intl/server";
import { CmsImage } from "@/components/ui/cms-image";
import { Link } from "@/i18n/navigation";
import { productHref } from "@/lib/catalog/product-href";
import { formatPrice } from "@/lib/format";
import {
  assignContentTones,
  type BandTone,
  type FixedTone,
} from "@/lib/landings/band-tones";
import { buildBaseFlow, faqAsideFor } from "@/lib/landings/base-flow";
import { classifyCmsHref } from "@/lib/landings/cms-href";
import type { ResolvedCmsLink } from "@/lib/landings/editorial-href";
import { splitFirstBlock } from "@/lib/landings/paragraph-split";
import { splitByH3 } from "@/lib/landings/prose-blocks";
import type { BaseSectionId, EditorialRegistryEntry } from "@/lib/landings/registry";
import { resolveSections } from "@/lib/landings/resolve-sections";
import type { EditorialLanding, LandingContentSection } from "@/lib/landings/types";

// ============================================================================
//  Vista genérica de una landing editorial (starter neutro).
//
//  Es responsabilidad de la instancia (AGENTS.md §1.1): reescríbela, extiéndela o
//  bórrala. Lo que NO cambia es el contrato de datos (`EditorialLanding`) ni el
//  motor de `lib/landings/` que decide el FLUJO:
//
//    buildBaseFlow(landing) → resolveSections(base, entry) → assignContentTones
//
//  Las secciones únicas de una landing con diseño propio las aporta la instancia
//  como `uniqueSections`: un mapa `clave → nodo` ya renderizado. Es la
//  instancia —no el núcleo— quien mapea cada clave a un componente. Una única
//  sin nodo se omite (auto-oculta, como cuando su slot está apagado).
//
//  El molde no tiene `<main>` propio: lo aporta `app/[locale]/layout.tsx`.
// ============================================================================

/** Color FIJO de cada banda que lo trae en su propio markup. Las únicas son «other». */
const FIXED_TONES: Record<string, FixedTone> = {
  hero: "background",
  lead: "surface",
  quote: "other",
  faq: "background",
  products: "background",
  closing: "other",
};

const TONE_CLASS: Record<BandTone, string> = {
  surface: "bg-surface dark:bg-gray-900",
  background: "",
};

const BAND = "px-6 py-12";
const INNER = "mx-auto max-w-5xl";
const H2 = "text-2xl font-bold tracking-tight";
const BUTTON =
  "inline-flex items-center justify-center rounded-lg bg-[var(--landing-accent,var(--color-brand))] px-5 py-3 text-sm font-semibold text-white transition-opacity hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2";
const BUTTON_OUTLINE =
  "inline-flex items-center justify-center rounded-lg border border-[var(--landing-accent,var(--color-brand))] px-5 py-3 text-sm font-semibold text-[var(--landing-accent,var(--color-brand))] transition-opacity hover:opacity-80 focus-visible:outline-2 focus-visible:outline-offset-2";

export interface LandingEditorialViewProps {
  landing: EditorialLanding;
  entry: EditorialRegistryEntry;
  /** Título del hero: el del banner, el SEO o la etiqueta de la entrada. */
  heroTitle: string;
  /** Idioma de la página (para marcar `lang` y formatear precios). */
  locale: string;
  /** Moneda y decimales de la tienda, para los precios de la banda de productos. */
  currency: string;
  decimals: number | null;
  /** Los dos botones del cierre, ya validados y con `editorial:<id>` resuelto. */
  closingLinks: { primary: ResolvedCmsLink | null; secondary: ResolvedCmsLink | null };
  /** Secciones únicas de la instancia: clave → nodo ya renderizado. */
  uniqueSections?: Record<string, ReactNode>;
}

function LinkOrAnchor({
  link,
  className,
  children,
}: {
  link: ResolvedCmsLink;
  className: string;
  children: ReactNode;
}) {
  if (link.kind === "internal") {
    return (
      <Link href={link.href} className={className}>
        {children}
      </Link>
    );
  }
  return (
    <a
      href={link.href}
      className={className}
      {...(link.external ? { target: "_blank", rel: "noopener noreferrer" } : {})}
    >
      {children}
    </a>
  );
}

function Prose({ html }: { html: string }) {
  return (
    <div
      className="prose max-w-none dark:prose-invert"
      dangerouslySetInnerHTML={{ __html: html }}
    />
  );
}

function Hero({
  landing,
  title,
  kicker,
}: {
  landing: EditorialLanding;
  title: string;
  kicker: string;
}) {
  const slide = landing.hero;
  const cta = classifyCmsHref(slide?.ctaHref);
  const subtitle = slide?.subtitle ?? null;

  return (
    <header className={`${BAND} relative isolate overflow-hidden`}>
      {slide?.image && (
        <>
          <CmsImage src={slide.image} alt="" fill sizes="100vw" priority />
          <span className="absolute inset-0 bg-black/45" aria-hidden />
        </>
      )}
      <div className={`${INNER} relative py-10 ${slide?.image ? "text-white" : ""}`}>
        <p
          className={`text-sm font-semibold uppercase tracking-wide ${slide?.image ? "" : "text-[var(--landing-accent,var(--color-brand))]"}`}
        >
          {kicker}
        </p>
        <h1 className="mt-2 text-4xl font-extrabold tracking-tight sm:text-5xl">
          {title}
        </h1>
        {subtitle && (
          <p
            className={`mt-4 max-w-2xl text-lg ${slide?.image ? "" : "text-gray-600 dark:text-gray-400"}`}
          >
            {subtitle}
          </p>
        )}
        {slide?.cta && cta && cta.kind !== "editorial" && (
          <LinkOrAnchor
            link={cta.kind === "internal" ? cta : { ...cta }}
            className={`${BUTTON} mt-6`}
          >
            {slide.cta}
          </LinkOrAnchor>
        )}
      </div>
    </header>
  );
}

function ContentSection({
  section,
  tone,
  faqAside,
  faq,
}: {
  section: LandingContentSection;
  tone: BandTone;
  faqAside: boolean;
  faq: EditorialLanding["faq"];
}) {
  const { layout } = section;
  const image =
    section.image && layout !== "text-only" && layout !== "prose-blocks" ? (
      <div
        className={`relative overflow-hidden rounded-2xl bg-gray-100 dark:bg-gray-800 ${
          layout === "full" ? "aspect-[21/9]" : "aspect-[4/3]"
        }`}
      >
        <CmsImage
          src={section.image}
          alt={section.imageAlt ?? ""}
          fill
          sizes={layout === "full" ? "100vw" : "(max-width: 768px) 100vw, 50vw"}
        />
      </div>
    ) : null;

  const heading = (
    <>
      {section.kicker && (
        <p className="text-sm font-semibold uppercase tracking-wide text-[var(--landing-accent,var(--color-brand))]">
          {section.kicker}
        </p>
      )}
      {section.title && <h2 className={`${H2} mt-1`}>{section.title}</h2>}
    </>
  );

  let content: ReactNode;
  if (layout === "divider") {
    // Franja de anuncio: antetítulo entre filetes, título opcional y descripción corta.
    content = (
      <div className="text-center">
        {section.kicker && (
          <p className="flex items-center gap-4 text-sm font-semibold uppercase tracking-widest text-[var(--landing-accent,var(--color-brand))]">
            <span className="h-px flex-1 bg-current opacity-30" aria-hidden />
            {section.kicker}
            <span className="h-px flex-1 bg-current opacity-30" aria-hidden />
          </p>
        )}
        {section.title && <h2 className={`${H2} mt-3`}>{section.title}</h2>}
        {section.body && (
          <div className="mx-auto mt-3 max-w-2xl">
            <Prose html={section.body} />
          </div>
        )}
      </div>
    );
  } else if (layout === "prose-blocks") {
    // Intro y un bloque por cada <h3>; sin <h3> degrada a prosa corrida.
    const { intro, blocks } = splitByH3(section.body);
    content = (
      <>
        {heading}
        {intro && (
          <div className="mt-4">
            <Prose html={intro} />
          </div>
        )}
        {blocks.length > 0 && (
          <div className="mt-8 grid gap-8 md:grid-cols-3">
            {blocks.map((block, index) => (
              <div key={`${block.heading}-${index}`}>
                <h3 className="text-lg font-semibold">{block.heading}</h3>
                {block.html && (
                  <div className="mt-2">
                    <Prose html={block.html} />
                  </div>
                )}
                {block.ctas.map((cta) => {
                  const link = classifyCmsHref(cta.href);
                  return link && link.kind !== "editorial" ? (
                    <LinkOrAnchor
                      key={cta.href}
                      link={link}
                      className={`${BUTTON_OUTLINE} mt-3`}
                    >
                      {cta.label}
                    </LinkOrAnchor>
                  ) : null;
                })}
              </div>
            ))}
          </div>
        )}
      </>
    );
  } else if (layout === "mirror-pair") {
    // Dos filas enfrentadas: imagen y primer bloque, y segundo bloque con la segunda imagen.
    const { first, rest } = splitFirstBlock(section.body);
    const second = section.image2 ? (
      <div className="relative aspect-[4/3] overflow-hidden rounded-2xl bg-gray-100 dark:bg-gray-800">
        <CmsImage
          src={section.image2}
          alt={section.image2Alt ?? ""}
          fill
          sizes="(max-width: 768px) 100vw, 50vw"
        />
      </div>
    ) : null;
    content = (
      <div className="space-y-10">
        <div className="grid items-center gap-8 md:grid-cols-2">
          {image}
          <div>
            {heading}
            {first && (
              <div className="mt-4">
                <Prose html={first} />
              </div>
            )}
          </div>
        </div>
        {(rest || second) && (
          <div className="grid items-center gap-8 md:grid-cols-2">
            <div>{rest && <Prose html={rest} />}</div>
            {second}
          </div>
        )}
      </div>
    );
  } else if (layout === "full") {
    content = (
      <div className="space-y-6">
        {image}
        <div>
          {heading}
          {section.body && (
            <div className="mt-4">
              <Prose html={section.body} />
            </div>
          )}
        </div>
      </div>
    );
  } else if (layout === "text-only" || !image) {
    content = (
      <div className="max-w-3xl">
        {heading}
        {section.body && (
          <div className="mt-4">
            <Prose html={section.body} />
          </div>
        )}
      </div>
    );
  } else {
    // image-left / image-right
    content = (
      <div className="grid items-center gap-8 md:grid-cols-2">
        <div className={layout === "image-left" ? "md:order-2" : ""}>
          {heading}
          {section.body && (
            <div className="mt-4">
              <Prose html={section.body} />
            </div>
          )}
        </div>
        <div className={layout === "image-left" ? "md:order-1" : ""}>{image}</div>
      </div>
    );
  }

  return (
    <section
      className={`${BAND} ${TONE_CLASS[tone]}`}
      aria-labelledby={`landing-${section.id}`}
    >
      <div className={INNER}>
        {/* El título ya está en `content`; este id solo da nombre a la región. */}
        <span id={`landing-${section.id}`} className="sr-only">
          {section.title ?? section.kicker ?? ""}
        </span>
        {content}
        {faqAside && faq && <FaqList faq={faq} className="mt-10" />}
      </div>
    </section>
  );
}

async function FaqList({
  faq,
  className = "",
}: {
  faq: NonNullable<EditorialLanding["faq"]>;
  className?: string;
}) {
  const t = await getTranslations("landings");
  return (
    <div className={className}>
      <h2 className={H2}>{faq.title ?? t("faqTitle")}</h2>
      <div className="mt-4 divide-y divide-gray-200 rounded-xl border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
        {faq.items.map((item, index) => (
          <details key={index} className="group p-4" open={index === 0}>
            <summary className="cursor-pointer list-none font-medium marker:hidden">
              {item.question}
            </summary>
            <div className="mt-2 text-gray-600 dark:text-gray-400">
              <Prose html={item.answer} />
            </div>
          </details>
        ))}
      </div>
    </div>
  );
}

export async function LandingEditorialView({
  landing,
  entry,
  heroTitle,
  locale,
  currency,
  decimals,
  closingLinks,
  uniqueSections = {},
}: LandingEditorialViewProps) {
  const t = await getTranslations("landings");

  const ordered = resolveSections(buildBaseFlow(landing, entry), entry);
  const fixed: Record<string, FixedTone> = { ...FIXED_TONES };
  for (const node of ordered) {
    // Las únicas traen su propio color: no participan de la alternancia ni la cortan.
    if (node.id.startsWith("unique:")) fixed[node.id] = "other";
  }
  const tones = assignContentTones(ordered, fixed);
  const aside = faqAsideFor(landing, entry);

  const accent = {
    "--landing-accent": landing.accent ?? undefined,
  } as CSSProperties;

  const render = (id: BaseSectionId | `unique:${string}`, key?: string): ReactNode => {
    if (id.startsWith("unique:")) return uniqueSections[key ?? id.slice(7)] ?? null;
    switch (id as BaseSectionId) {
      case "hero":
        return <Hero landing={landing} title={heroTitle} kicker={t("kicker")} />;
      case "lead":
        return landing.lead ? (
          <section className={`${BAND} bg-surface dark:bg-gray-900`}>
            <div className={`${INNER} max-w-3xl text-lg`}>
              <Prose html={landing.lead} />
            </div>
          </section>
        ) : null;
      case "s1":
      case "s2":
      case "s3":
      case "s4":
      case "s5": {
        const section = landing.sections.find((s) => s.id === id);
        return section ? (
          <ContentSection
            section={section}
            tone={tones.get(id) ?? "background"}
            faqAside={aside === id}
            faq={landing.faq}
          />
        ) : null;
      }
      case "quote":
        return landing.quote ? (
          <section
            className={`${BAND} relative isolate overflow-hidden bg-[var(--landing-accent,var(--color-accent))]`}
          >
            {landing.quote.backgroundImage && (
              <>
                <CmsImage
                  src={landing.quote.backgroundImage}
                  alt=""
                  fill
                  sizes="100vw"
                />
                <span className="absolute inset-0 bg-black/50" aria-hidden />
              </>
            )}
            <figure className={`${INNER} relative max-w-3xl text-center text-white`}>
              <blockquote className="text-2xl font-semibold leading-snug whitespace-pre-line">
                {landing.quote.text}
              </blockquote>
              {(landing.quote.author || landing.quote.photo) && (
                <figcaption className="mt-5 flex items-center justify-center gap-3 text-sm">
                  {landing.quote.photo && (
                    <span className="relative h-14 w-14 overflow-hidden rounded-full bg-white/20">
                      <CmsImage src={landing.quote.photo} alt="" fill sizes="56px" />
                    </span>
                  )}
                  {landing.quote.author}
                </figcaption>
              )}
              {landing.quote.logo && (
                <span className="relative mx-auto mt-6 block h-10 w-44">
                  <CmsImage
                    src={landing.quote.logo}
                    alt={landing.quote.logoAlt ?? ""}
                    fill
                    sizes="176px"
                    objectFit="contain"
                  />
                </span>
              )}
            </figure>
          </section>
        ) : null;
      case "faq":
        return landing.faq ? (
          <section className={BAND}>
            <div className={`${INNER} max-w-3xl`}>
              <FaqList faq={landing.faq} />
            </div>
          </section>
        ) : null;
      case "products":
        return landing.products && landing.products.items.length > 0 ? (
          <section className={BAND} aria-labelledby="landing-products">
            <div className={INNER}>
              <h2 id="landing-products" className={H2}>
                {landing.products.title ?? t("productsTitle")}
              </h2>
              <ul className="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                {landing.products.items.map((product) => (
                  <li
                    key={product.databaseId}
                    className="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-800"
                  >
                    <Link href={productHref(product)} className="block h-full">
                      {product.image && (
                        <span className="relative block aspect-square bg-gray-100 dark:bg-gray-800">
                          <CmsImage
                            src={product.image.sourceUrl}
                            alt={product.image.altText || product.name}
                            fill
                            sizes="(max-width: 640px) 50vw, 25vw"
                          />
                        </span>
                      )}
                      <span className="block p-3">
                        <span className="block font-medium">{product.name}</span>
                        {product.price && (
                          <span className="mt-1 block text-sm text-gray-600 dark:text-gray-400">
                            {formatPrice(product.price, currency, locale, decimals)}
                          </span>
                        )}
                      </span>
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
          </section>
        ) : null;
      case "closing": {
        const closing = landing.closing;
        if (!closing) return null;
        return (
          <section
            className={`${BAND} relative isolate overflow-hidden bg-brand-light/20 dark:bg-accent/20`}
            aria-labelledby="landing-closing"
          >
            {closing.backgroundImage && (
              <>
                <CmsImage src={closing.backgroundImage} alt="" fill sizes="100vw" />
                <span
                  className="absolute inset-0 bg-white/70 dark:bg-black/60"
                  aria-hidden
                />
              </>
            )}
            <div className={`${INNER} relative max-w-3xl text-center`}>
              {closing.title && (
                <h2 id="landing-closing" className={H2}>
                  {closing.title}
                </h2>
              )}
              {closing.text && (
                <div className="mt-4 text-left">
                  <Prose html={closing.text} />
                </div>
              )}
              <div className="mt-6 flex flex-wrap justify-center gap-3">
                {closingLinks.primary && closing.ctaLabel && (
                  <LinkOrAnchor link={closingLinks.primary} className={BUTTON}>
                    {closing.ctaLabel}
                  </LinkOrAnchor>
                )}
                {closingLinks.secondary && closing.cta2Label && (
                  <LinkOrAnchor
                    link={closingLinks.secondary}
                    className={BUTTON_OUTLINE}
                  >
                    {closing.cta2Label}
                  </LinkOrAnchor>
                )}
              </div>
            </div>
          </section>
        );
      }
      default:
        return null;
    }
  };

  return (
    <article
      style={accent}
      lang={landing.contentLang !== locale ? landing.contentLang : undefined}
      className="landing-editorial -mx-6"
    >
      {ordered.map((node) => (
        <div key={node.id}>{render(node.id, "key" in node ? node.key : undefined)}</div>
      ))}
    </article>
  );
}
