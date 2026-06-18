import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";
import { getCustomer } from "@/lib/account/data";
import { ProfileForm } from "@/components/account/profile-form";
import { LogoutButton } from "@/components/account/logout-button";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("account");
  return { title: t("profile") };
}

export const dynamic = "force-dynamic";

export default async function AccountPage() {
  const t = await getTranslations("account");
  let customer: Awaited<ReturnType<typeof getCustomer>> | null = null;
  let error: string | null = null;

  try {
    customer = await getCustomer();
  } catch (e) {
    error =
      e instanceof Error ? e.message : t("dataError");
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold">{t("profile")}</h1>
        <LogoutButton />
      </div>

      {error ? (
        <div className="rounded-xl border border-amber-200 bg-amber-50 p-6 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
          <p className="font-semibold">{t("dataError")}</p>
          <p className="mt-1 text-sm opacity-80">{error}</p>
          <p className="mt-2 text-sm">
            {t("dataErrorHint")}
          </p>
        </div>
      ) : (
        customer && (
          <div className="space-y-6">
            <p className="text-sm text-gray-500">
              {t("sessionAs")}{" "}
              <span className="font-medium text-gray-700 dark:text-gray-200">
                {customer.email}
              </span>
            </p>
            <ProfileForm
              initial={{
                first_name: customer.first_name ?? "",
                last_name: customer.last_name ?? "",
              }}
            />
          </div>
        )
      )}
    </div>
  );
}
