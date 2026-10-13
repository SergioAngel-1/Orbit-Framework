import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";
import { AddressManager } from "@/components/account/address-manager";
import { getGeoPack } from "@/lib/geo";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("addresses");
  return { title: t("title") };
}

export const dynamic = "force-dynamic";

export default async function AddressesPage() {
  const geoPack = await getGeoPack();
  return (
    <div>
      <h2 className="mb-6 text-xl font-bold">Direcciones</h2>
      <AddressManager geoPack={geoPack} />
    </div>
  );
}
