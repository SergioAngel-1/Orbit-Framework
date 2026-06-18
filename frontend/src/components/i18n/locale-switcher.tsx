"use client";
import { useLocale } from "next-intl";
import { usePathname, useRouter } from "@/i18n/navigation";
import { routing } from "@/i18n/routing";

export function LocaleSwitcher() {
  const locale = useLocale();
  const pathname = usePathname();
  const router = useRouter();

  return (
    <div className="flex items-center gap-1">
      {routing.locales.map((l) => (
        <button
          key={l}
          type="button"
          onClick={() => router.replace(pathname, { locale: l })}
          disabled={l === locale}
          aria-current={l === locale ? "true" : undefined}
          className={`rounded px-1.5 py-0.5 text-xs font-bold uppercase transition-colors ${
            l === locale
              ? "bg-brand text-white"
              : "text-gray-400 hover:text-brand"
          }`}
        >
          {l}
        </button>
      ))}
    </div>
  );
}
