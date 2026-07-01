import type { ReactNode } from "react";
import { getTranslations } from "next-intl/server";

interface TrustItem {
  icon: ReactNode;
  labelKey: "natural" | "freeShipping" | "returns" | "securePayment";
}

const iconClass = "h-3.5 w-3.5 shrink-0";

const TRUST_ITEMS: TrustItem[] = [
  {
    labelKey: "natural",
    icon: (
      <svg className={iconClass} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M17 8C8 10 5.9 16.17 3.82 21.34L5.71 22l1-2.3A4.49 4.49 0 0 0 8 20c4 0 8.5-3 11-8 0 0-1-1-2-1s-2 1-2 1z" />
        <path d="M6 15s2-2 6-3" />
      </svg>
    ),
  },
  {
    labelKey: "freeShipping",
    icon: (
      <svg className={iconClass} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M5 12h14" />
        <path d="M12 5l7 7-7 7" />
      </svg>
    ),
  },
  {
    labelKey: "returns",
    icon: (
      <svg className={iconClass} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <polyline points="1 4 1 10 7 10" />
        <path d="M3.51 15a9 9 0 1 0 .49-3.85" />
      </svg>
    ),
  },
  {
    labelKey: "securePayment",
    icon: (
      <svg className={iconClass} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
        <path d="M7 11V7a5 5 0 0 1 10 0v4" />
      </svg>
    ),
  },
];

export async function TrustBar() {
  const t = await getTranslations("trustBar");

  return (
    <div className="border-b border-white/5 bg-gray-900">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-10">
        <div
          className="flex items-center justify-between gap-4 overflow-x-auto py-2.5"
          style={{ scrollbarWidth: "none" }}
        >
          {TRUST_ITEMS.map((item) => (
            <div
              key={item.labelKey}
              className="flex shrink-0 items-center gap-1.5 text-white/55 transition-colors duration-200 hover:text-white/80"
            >
              {item.icon}
              <span className="whitespace-nowrap text-[10px] tracking-wide md:text-[11px]">
                {t(item.labelKey)}
              </span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
