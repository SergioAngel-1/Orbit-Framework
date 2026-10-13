"use client";
import { useEffect, useMemo, useState } from "react";
import { useLocale, useTranslations } from "next-intl";
import { summarizeCities } from "@/lib/shipping/free-shipping-scope";
import { cn } from "@/lib/utils";
import { loadFreeShippingScope } from "./free-shipping-scope-help";

/**
 * Las ciudades en las que aplica el envío gratis, por su nombre. Dos usos:
 *   - `hint`: la pista bajo la barra cuando aún no se sabe la ciudad del
 *     pedido. Hasta que llega la lista (o si falla) dice «ciudades
 *     seleccionadas», como antes.
 *   - `other`: la única línea del aviso cuando la ciudad del pedido NO lo
 *     admite. Sin lista no hay nada que decir y no pinta nada.
 *
 * La lista sale del mismo alcance que el «?» (una petición por idioma para
 * toda la página). Con muchas ciudades nombra las primeras y deja el resto
 * tras «Ver todas».
 */
export function FreeShippingCities({
  variant,
  className,
}: {
  variant: "hint" | "other";
  className?: string;
}) {
  const t = useTranslations("cart.freeShipping");
  const locale = useLocale();
  const [cities, setCities] = useState<string[] | null>(null);
  const [expanded, setExpanded] = useState(false);

  useEffect(() => {
    let cancelled = false;
    loadFreeShippingScope(locale).then(
      (scope) => {
        if (!cancelled) setCities(scope.cities ?? []);
      },
      () => {
        if (!cancelled) setCities([]);
      },
    );
    return () => {
      cancelled = true;
    };
  }, [locale]);

  const { list, hidden } = useMemo(() => {
    const summary = summarizeCities(cities ?? [], expanded);
    const parts =
      summary.hidden > 0
        ? [...summary.shown, t("citiesMore", { count: summary.hidden })]
        : summary.shown;
    return {
      list: new Intl.ListFormat(locale, { type: "conjunction" }).format(parts),
      hidden: summary.hidden,
    };
  }, [cities, expanded, locale, t]);

  const known = cities !== null && cities.length > 0;
  if (!known) {
    return variant === "hint" ? (
      <span className={className}>{t("hintCities")}</span>
    ) : null;
  }

  const showAll = hidden > 0 && (
    <>
      {" "}
      <button
        type="button"
        onClick={() => setExpanded(true)}
        className="font-bold underline underline-offset-2"
      >
        {t("citiesShowAll")}
      </button>
    </>
  );

  if (variant === "hint") {
    return (
      <span className={className}>
        {t("hintCityList", { cities: list })}
        {showAll}
      </span>
    );
  }
  return (
    <p role="status" className={cn("text-xs text-gray-500", className)}>
      {t("otherCities", { cities: list })}
      {showAll}
    </p>
  );
}
