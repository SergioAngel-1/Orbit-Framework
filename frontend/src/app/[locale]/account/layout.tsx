import { Link, redirect } from "@/i18n/navigation";
import { getTranslations } from "next-intl/server";
import { getSession } from "@/lib/auth/session";
import { getSiteConfig } from "@/lib/config";
import type { Metadata } from "next";
import { privateMetadata } from "@/lib/seo/page-metadata";

export const dynamic = "force-dynamic";

// Toda la cuenta va `noindex, nofollow`, declarado AQUÍ y no página a página:
// una vista nueva de la cuenta nace privada sin que nadie tenga que acordarse.
export const metadata: Metadata = privateMetadata();

export default async function AccountLayout({
  children,
  params,
}: {
  children: React.ReactNode;
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  const [t, config] = await Promise.all([getTranslations("account"), getSiteConfig()]);
  const session = await getSession();
  if (!session) {
    redirect({ href: "/login", locale });
  }

  return (
    <div className="grid grid-cols-1 gap-8 md:grid-cols-[200px_1fr]">
      <aside>
        <h2 className="mb-4 text-sm font-bold uppercase tracking-wide text-gray-500">
          {t("myAccount")}
        </h2>
        <nav className="flex flex-col gap-2 text-sm">
          <Link href="/account" className="hover:text-brand">
            {t("profile")}
          </Link>
          <Link href="/account/orders" className="hover:text-brand">
            {t("orders")}
          </Link>
          <Link href="/account/addresses" className="hover:text-brand">
            {t("addresses")}
          </Link>
          {config.ecommerce.wishlist_enabled && (
            <Link href="/account/wishlist" className="hover:text-brand">
              {t("wishlist")}
            </Link>
          )}
          <Link href="/account/password" className="hover:text-brand">
            {t("changePassword")}
          </Link>
        </nav>
      </aside>
      <section>{children}</section>
    </div>
  );
}
