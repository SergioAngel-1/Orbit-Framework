"use client";
import { useTranslations } from "next-intl";

export function ConsentBanner({
  onAccept,
  onDecline,
}: {
  onAccept: () => void;
  onDecline: () => void;
}) {
  const t = useTranslations("consent");

  return (
    <div className="fixed bottom-0 left-0 right-0 z-50 border-t border-gray-200 bg-white p-4 shadow-lg dark:border-gray-800 dark:bg-gray-950">
      <div className="mx-auto flex max-w-5xl flex-col items-center justify-between gap-3 sm:flex-row">
        <p className="text-sm text-gray-600 dark:text-gray-300">{t("message")}</p>
        <div className="flex shrink-0 gap-2">
          <button
            type="button"
            onClick={onDecline}
            className="rounded-lg border border-gray-300 px-4 py-1.5 text-sm font-medium transition-colors hover:bg-gray-100 dark:border-gray-700 dark:hover:bg-gray-800"
          >
            {t("necessaryOnly")}
          </button>
          <button
            type="button"
            onClick={onAccept}
            className="rounded-lg bg-brand px-4 py-1.5 text-sm font-medium text-white transition-colors hover:bg-brand-dark"
          >
            {t("acceptAll")}
          </button>
        </div>
      </div>
    </div>
  );
}
