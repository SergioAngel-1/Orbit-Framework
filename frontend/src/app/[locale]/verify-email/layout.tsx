import type { Metadata } from "next";
import { privateMetadata } from "@/lib/seo/page-metadata";

// Existe solo para declarar la metadata: la página es un Client Component (la
// verificación ocurre en un efecto contra el BFF) y un componente de cliente no
// puede exportar `metadata`. Sin esto, esta vista privada dependía solo del
// `Disallow` de robots.txt para no indexarse.
export const metadata: Metadata = privateMetadata();

export default function VerifyEmailLayout({ children }: { children: React.ReactNode }) {
  return children;
}
