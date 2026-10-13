import type { Metadata } from "next";
import {
  EditorialLandingRoute,
  editorialMetadata,
  editorialStaticParams,
} from "@/components/landings/editorial/editorial-landing-route";

// Landing editorial del ÁREA (`/learn/<tramo>`). Toda la lógica vive en la ruta compartida: la
// de la raíz (`app/[locale]/[landingPath]`) la comparte. Con el módulo `landings` apagado, 404.

export const revalidate = 300;

type Props = { params: Promise<{ locale: string; slug: string }> };

export function generateStaticParams() {
  return editorialStaticParams("area", "slug");
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { locale, slug } = await params;
  return editorialMetadata(locale, "area", slug);
}

export default async function LearnLandingPage({ params }: Props) {
  const { locale, slug } = await params;
  return <EditorialLandingRoute locale={locale} base="area" path={slug} />;
}
