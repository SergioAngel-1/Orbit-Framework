import { getTranslations } from "next-intl/server";
import { Link } from "@/i18n/navigation";

export default async function LocaleNotFound() {
  const t = await getTranslations("notFound");

  return (
    <div className="py-20 text-center">
      <h1 className="text-4xl font-extrabold">404</h1>
      <p className="mt-3 text-gray-600 dark:text-gray-300">{t("message")}</p>
      <Link
        href="/"
        className="mt-6 inline-block font-medium text-brand hover:underline"
      >
        {t("backHome")}
      </Link>
    </div>
  );
}
