import type { Metadata } from "next";
import {
  EditorialLandingRoute,
  editorialMetadata,
  editorialStaticParams,
} from "@/components/landings/editorial/editorial-landing-route";

// Landing editorial de la RAÍZ (`/<tramo>`), sin página padre. Next da precedencia a las rutas
// estáticas: una landing sobre un tramo reservado vuelve al área (ver `lib/landings/registry`).
// Cualquier tramo que no sea una landing publicada es 404.

export const revalidate = 300;

type Props = { params: Promise<{ locale: string; landingPath: string }> };

export function generateStaticParams() {
  return editorialStaticParams("root", "landingPath");
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { locale, landingPath } = await params;
  return editorialMetadata(locale, "root", landingPath);
}

export default async function RootLandingPage({ params }: Props) {
  const { locale, landingPath } = await params;
  return <EditorialLandingRoute locale={locale} base="root" path={landingPath} />;
}
