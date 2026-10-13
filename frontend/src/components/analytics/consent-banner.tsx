"use client";
import { useEffect, useRef } from "react";
import { useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";

/**
 * Banner de consentimiento. Dice qué se pide y a quién: los terceros que la
 * instancia configuró en Control Center → Integraciones (`consentMessageArgs`),
 * porque un consentimiento tiene que ser informado.
 */
export function ConsentBanner({
  message,
  onAccept,
  onDecline,
  onClose,
}: {
  message: { kind: "analytics" | "ads" | "both"; providers: string };
  onAccept: () => void;
  onDecline: () => void;
  /**
   * Solo al reabrirlo desde «Preferencias de cookies» con una decisión ya
   * tomada: cerrarlo la deja como estaba. La primera vez no hay cierre —no
   * decidir no es una decisión— y el banner sigue hasta que se responde.
   */
  onClose?: () => void;
}) {
  const t = useTranslations("consent");
  const ref = useRef<HTMLElement>(null);

  // La franja va fija abajo: sin reservar su alto, taparía el final de cada
  // página —el enlace a la política de cookies del pie incluido— justo mientras
  // se pide decidir. Se reserva en el `<body>` y se sigue su alto.
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    const body = document.body;
    const sync = () => {
      body.style.paddingBottom = `${el.offsetHeight}px`;
    };
    sync();
    const observer =
      typeof ResizeObserver === "undefined" ? null : new ResizeObserver(sync);
    observer?.observe(el);
    return () => {
      observer?.disconnect();
      body.style.paddingBottom = "";
    };
  }, []);

  return (
    // Landmark propio: suelto en el `<body>` quedaría fuera de toda región
    // (axe: `region`) y no se alcanzaría navegando por regiones.
    <section
      ref={ref}
      aria-label={t("regionLabel")}
      className="fixed bottom-0 left-0 right-0 z-50 border-t border-gray-200 bg-white p-4 shadow-lg dark:border-gray-800 dark:bg-gray-950"
    >
      <div className="mx-auto flex max-w-5xl flex-col items-center justify-between gap-3 sm:flex-row">
        <p className="text-sm text-gray-600 dark:text-gray-300">
          {t.rich("message", {
            ...message,
            link: (chunks) => (
              <Link
                href="/legal/cookies"
                className="font-medium underline underline-offset-2 hover:text-brand"
              >
                {chunks}
              </Link>
            ),
          })}
        </p>
        <div className="flex shrink-0 gap-2">
          {onClose && (
            <button
              type="button"
              onClick={onClose}
              className="rounded-lg px-4 py-1.5 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
            >
              {t("close")}
            </button>
          )}
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
    </section>
  );
}
