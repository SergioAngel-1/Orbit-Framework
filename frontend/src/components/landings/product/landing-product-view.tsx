import type { CSSProperties } from "react";
import { getTranslations } from "next-intl/server";
import { CmsImage } from "@/components/ui/cms-image";
import { Link } from "@/i18n/navigation";
import { splitAdvice } from "@/lib/landings/advice";
import { LANDING_MAX_COMPONENTS, LANDING_MAX_QUALITIES } from "@/lib/landings/limits";
import type { RelatedProductLanding } from "@/lib/landings/related-product-landings";
import { resolveLandingHref } from "@/lib/landings/safe-href";
import type { ProductLanding } from "@/lib/landings/types";
import { extractYoutubeId } from "@/lib/youtube";
import { QualityIcon } from "./quality-icons";

// ============================================================================
//  Vista genérica de la landing de producto (starter neutro).
//
//  Es responsabilidad de la instancia (AGENTS.md §1.1): reescríbela o bórrala.
//  Lo que NO cambia es el contrato de datos (`ProductLanding`): la vista solo
//  pinta lo que el editor rellenó y respeta los interruptores de cada sección.
//
//  Decisiones del starter:
//  - el video es un enlace a YouTube, no un iframe: la CSP del framework fija
//    `frame-src 'none'`, y relajarla es una decisión de la instancia;
//  - sin carrito propio: el CTA lleva a la ficha, que ya lo tiene;
//  - los colores de acento de la landing llegan como propiedades CSS ya validadas
//    (`#rrggbb`), con la marca como respaldo.
// ============================================================================

interface LandingProductViewProps {
  product: {
    name: string;
    image: { sourceUrl: string; altText: string } | null;
  };
  landing: ProductLanding;
  /** Ruta interna de la ficha del producto. */
  productHref: string;
  related: RelatedProductLanding[];
  /** Idioma de la página (para marcar `lang` si el contenido es de otro). */
  locale: string;
}

const SECTION = "mx-auto mt-14 max-w-5xl";
const H2 = "text-2xl font-bold tracking-tight";
const BUTTON =
  "inline-flex items-center justify-center rounded-lg bg-[var(--landing-secondary,var(--color-brand))] px-5 py-3 text-sm font-semibold text-white transition-opacity hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2";

export async function LandingProductView({
  product,
  landing,
  productHref,
  related,
  locale,
}: LandingProductViewProps) {
  const t = await getTranslations("productLanding");

  const accents = {
    "--landing-primary": landing.accents.primary ?? undefined,
    "--landing-secondary": landing.accents.secondary ?? undefined,
  } as CSSProperties;

  const heroImage = landing.heroImage ?? product.image?.sourceUrl ?? null;
  const { tips, questions } = splitAdvice(landing.advice.items);
  const components = landing.about.components.slice(0, LANDING_MAX_COMPONENTS);
  const qualities = landing.qualities.items.slice(0, LANDING_MAX_QUALITIES);
  const videoId =
    landing.video.enabled && landing.video.url
      ? extractYoutubeId(landing.video.url)
      : null;

  const showEdu =
    landing.edu.enabled &&
    Boolean(landing.edu.title || landing.edu.body || landing.edu.kicker);
  const showAbout = Boolean(
    landing.about.title || landing.about.body || components.length > 0,
  );
  const showQualities = landing.qualities.enabled && qualities.length > 0;
  const showAdvice =
    landing.advice.enabled &&
    Boolean(tips.length > 0 || questions.length > 0 || landing.advice.intro);

  const closingLink = resolveLandingHref(landing.closing.ctaHref);
  const closingLabel = landing.closing.ctaLabel ?? t("closingBack");
  const showClosing = Boolean(landing.closing.title || landing.closing.text);

  return (
    <article
      style={accents}
      lang={landing.contentLang !== locale ? landing.contentLang : undefined}
      className="landing-product"
    >
      {/* Hero */}
      <header className="grid items-center gap-8 md:grid-cols-2">
        <div>
          <p className="text-sm font-semibold uppercase tracking-wide text-[var(--landing-primary,var(--color-brand))]">
            {t("kicker")}
          </p>
          <h1 className="mt-2 text-4xl font-extrabold tracking-tight">
            {product.name}
          </h1>
          {landing.heroSubtitle && (
            <p className="mt-3 text-lg text-gray-600 dark:text-gray-400">
              {landing.heroSubtitle}
            </p>
          )}
          <Link href={productHref} className={`${BUTTON} mt-6`}>
            {t("viewProduct")}
          </Link>
        </div>
        {heroImage && (
          <div className="relative aspect-[4/5] overflow-hidden rounded-2xl bg-gray-100 dark:bg-gray-800">
            <CmsImage
              src={heroImage}
              alt={
                landing.heroImage
                  ? product.name
                  : product.image?.altText || product.name
              }
              fill
              sizes="(max-width: 768px) 100vw, 50vw"
              priority
            />
          </div>
        )}
      </header>

      {/* Educativa */}
      {showEdu && (
        <section className={SECTION} aria-labelledby="landing-edu">
          <div
            className={landing.edu.image ? "grid items-start gap-8 md:grid-cols-2" : ""}
          >
            <div>
              {landing.edu.kicker && (
                <p className="text-sm font-semibold uppercase tracking-wide text-[var(--landing-primary,var(--color-brand))]">
                  {landing.edu.kicker}
                </p>
              )}
              {landing.edu.title && (
                <h2 id="landing-edu" className={`${H2} mt-1`}>
                  {landing.edu.title}
                </h2>
              )}
              {landing.edu.body && (
                <div
                  className="prose mt-4 max-w-none dark:prose-invert"
                  dangerouslySetInnerHTML={{ __html: landing.edu.body }}
                />
              )}
            </div>
            {landing.edu.image && (
              <div className="relative aspect-[4/5] overflow-hidden rounded-2xl bg-gray-100 dark:bg-gray-800">
                <CmsImage
                  src={landing.edu.image}
                  alt=""
                  fill
                  sizes="(max-width: 768px) 100vw, 50vw"
                />
              </div>
            )}
          </div>
        </section>
      )}

      {/* Sobre el producto + componentes */}
      {showAbout && (
        <section className={SECTION} aria-labelledby="landing-about">
          <h2 id="landing-about" className={H2}>
            {landing.about.title ?? t("aboutTitle", { name: product.name })}
          </h2>
          {landing.about.body && (
            <div
              className="prose mt-4 max-w-none dark:prose-invert"
              dangerouslySetInnerHTML={{ __html: landing.about.body }}
            />
          )}
          {components.length > 0 && (
            <>
              <h3 className="mt-8 text-lg font-semibold">{t("componentsTitle")}</h3>
              <ul className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                {components.map((component, index) => (
                  <li
                    key={`${component.name}-${index}`}
                    className="flex flex-col items-center rounded-xl border border-gray-200 p-4 text-center dark:border-gray-800"
                  >
                    <span className="relative flex h-20 w-20 items-center justify-center overflow-hidden rounded-full bg-gray-100 text-[var(--landing-primary,var(--color-brand))] dark:bg-gray-800">
                      {component.image ? (
                        <CmsImage src={component.image} alt="" fill sizes="80px" />
                      ) : (
                        <QualityIcon icon={component.icon} className="h-8 w-8" />
                      )}
                    </span>
                    <span className="mt-3 font-medium">{component.name}</span>
                    {component.note && (
                      <span className="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {component.note}
                      </span>
                    )}
                  </li>
                ))}
              </ul>
            </>
          )}
        </section>
      )}

      {/* Cualidades */}
      {showQualities && (
        <section className={SECTION} aria-labelledby="landing-qualities">
          <h2 id="landing-qualities" className={H2}>
            {landing.qualities.title ?? t("qualitiesTitle")}
          </h2>
          <ul className="mt-6 grid gap-4 sm:grid-cols-2">
            {qualities.map((quality, index) => (
              <li
                key={`${quality.title}-${index}`}
                className="flex gap-4 rounded-xl border border-gray-200 p-4 dark:border-gray-800"
              >
                <span className="relative flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-gray-100 text-[var(--landing-primary,var(--color-brand))] dark:bg-gray-800">
                  {quality.image ? (
                    <CmsImage src={quality.image} alt="" fill sizes="40px" />
                  ) : (
                    <QualityIcon icon={quality.icon} className="h-5 w-5" />
                  )}
                </span>
                <span>
                  <span className="block font-medium">{quality.title}</span>
                  {quality.text && (
                    <span className="mt-1 block text-sm text-gray-600 dark:text-gray-400">
                      {quality.text}
                    </span>
                  )}
                </span>
              </li>
            ))}
          </ul>
        </section>
      )}

      {/* Consejos y preguntas */}
      {showAdvice && (
        <section className={SECTION} aria-labelledby="landing-advice">
          <h2 id="landing-advice" className={H2}>
            {landing.advice.title ?? t("adviceTitle")}
          </h2>
          {landing.advice.intro && (
            <div
              className="prose mt-4 max-w-none dark:prose-invert"
              dangerouslySetInnerHTML={{ __html: landing.advice.intro }}
            />
          )}
          {tips.length > 0 && (
            <ol className="mt-6 list-decimal space-y-2 pl-6">
              {tips.map((tip, index) => (
                <li key={index} className="whitespace-pre-line">
                  {tip.body}
                </li>
              ))}
            </ol>
          )}
          {questions.length > 0 && (
            <div className="mt-8">
              <h3 className="text-lg font-semibold">{t("questionsTitle")}</h3>
              <div className="mt-3 divide-y divide-gray-200 rounded-xl border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                {questions.map((question, index) => (
                  <details key={index} className="group p-4" open={index === 0}>
                    <summary className="cursor-pointer list-none font-medium marker:hidden">
                      {question.heading}
                    </summary>
                    <p className="mt-2 whitespace-pre-line text-gray-600 dark:text-gray-400">
                      {question.body}
                    </p>
                  </details>
                ))}
              </div>
            </div>
          )}
        </section>
      )}

      {/* CTA de compra */}
      <section
        className={`${SECTION} rounded-2xl bg-gray-50 p-8 text-center dark:bg-gray-900`}
        aria-labelledby="landing-cta"
      >
        <h2 id="landing-cta" className={H2}>
          {landing.cta.title ?? t("ctaTitle", { name: product.name })}
        </h2>
        {landing.cta.text && (
          <p className="mx-auto mt-2 max-w-xl text-gray-600 dark:text-gray-400">
            {landing.cta.text}
          </p>
        )}
        <Link href={productHref} className={`${BUTTON} mt-5`}>
          {t("ctaButton")}
        </Link>
      </section>

      {/* Video */}
      {videoId && (
        <section className={SECTION} aria-labelledby="landing-video">
          <h2 id="landing-video" className={H2}>
            {t("videoTitle")}
          </h2>
          <a
            href={`https://www.youtube.com/watch?v=${videoId}`}
            target="_blank"
            rel="noopener noreferrer"
            className="group mt-4 block"
          >
            {landing.video.poster && (
              <span className="relative block aspect-video overflow-hidden rounded-xl bg-gray-100 dark:bg-gray-800">
                <CmsImage
                  src={landing.video.poster}
                  alt=""
                  fill
                  sizes="(max-width: 1024px) 100vw, 64rem"
                />
              </span>
            )}
            <span className="mt-2 inline-block font-medium text-[var(--landing-primary,var(--color-brand))] underline-offset-4 group-hover:underline">
              {landing.video.caption ?? t("videoWatch")}
            </span>
          </a>
        </section>
      )}

      {/* Cierre + «Sigue aprendiendo» */}
      {(showClosing || related.length > 0) && (
        <section className={SECTION} aria-labelledby="landing-closing">
          {showClosing && (
            <div>
              {landing.closing.title && (
                <h2 id="landing-closing" className={H2}>
                  {landing.closing.title}
                </h2>
              )}
              {landing.closing.text && (
                <div
                  className="prose mt-4 max-w-none dark:prose-invert"
                  dangerouslySetInnerHTML={{ __html: landing.closing.text }}
                />
              )}
              {closingLink?.kind === "external" ? (
                <a
                  href={closingLink.href}
                  target="_blank"
                  rel="noopener noreferrer"
                  className={`${BUTTON} mt-5`}
                >
                  {closingLabel}
                </a>
              ) : (
                <Link
                  href={closingLink?.href ?? productHref}
                  className={`${BUTTON} mt-5`}
                >
                  {closingLabel}
                </Link>
              )}
            </div>
          )}
          {related.length > 0 && (
            <div className={showClosing ? "mt-12" : ""}>
              <h2 className={H2}>{t("relatedTitle")}</h2>
              <ul className="mt-6 grid gap-4 sm:grid-cols-3">
                {related.map((card) => (
                  <li
                    key={card.href}
                    className="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-800"
                  >
                    <Link href={card.href} className="block h-full">
                      {card.image && (
                        <span className="relative block aspect-square bg-gray-100 dark:bg-gray-800">
                          <CmsImage
                            src={card.image}
                            alt=""
                            fill
                            sizes="(max-width: 640px) 100vw, 33vw"
                          />
                        </span>
                      )}
                      <span className="block p-4">
                        <span className="block font-medium">{card.name}</span>
                        {card.excerpt && (
                          <span className="mt-1 block text-sm text-gray-600 dark:text-gray-400">
                            {card.excerpt}
                          </span>
                        )}
                        <span className="mt-2 block text-sm font-semibold text-[var(--landing-primary,var(--color-brand))]">
                          {t("relatedCta")}
                        </span>
                      </span>
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </section>
      )}
    </article>
  );
}
