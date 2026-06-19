import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";
import { requireSession } from "@/lib/auth/session";
import { wcFetch } from "@/lib/woocommerce/client";
import { WishlistPageClient } from "./wishlist-page-client";
import type { WooCustomer } from "@/types/woocommerce";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("wishlist");
  return { title: t("title") };
}

export const dynamic = "force-dynamic";

export default async function WishlistPage() {
  const t = await getTranslations("wishlist");

  let productIds: number[] = [];
  try {
    const session = await requireSession();
    const customer = await wcFetch<WooCustomer>(`/customers/${Number(session.userId)}`);
    const meta = customer.meta_data?.find((m) => m.key === "hwe_wishlist");
    if (meta?.value) {
      const arr = typeof meta.value === "string" ? JSON.parse(meta.value) : meta.value;
      productIds = Array.isArray(arr) ? arr.map(Number).filter((n) => !isNaN(n)) : [];
    }
  } catch {
    /* not authenticated or error */
  }

  return (
    <div>
      <h2 className="mb-6 text-xl font-bold">{t("title")}</h2>
      <WishlistPageClient initialIds={productIds} />
    </div>
  );
}
