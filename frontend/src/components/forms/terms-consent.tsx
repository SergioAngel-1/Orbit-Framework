"use client";
import { useId } from "react";
import { useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";

const LINK_CLASS = "text-brand underline underline-offset-2";

/**
 * Casilla de términos + privacidad + veracidad de los datos. Registro y checkout
 * de invitado (o de una cuenta sin aceptación registrada). Los enlaces abren en
 * otra pestaña para no perder lo ya escrito en el formulario.
 */
export function TermsConsent({
  checked,
  onChange,
  error,
}: {
  checked: boolean;
  onChange: (checked: boolean) => void;
  error?: string;
}) {
  const t = useTranslations("form");
  const id = useId();
  const errorId = useId();
  return (
    <div>
      <div className="flex items-start gap-2">
        <input
          id={id}
          type="checkbox"
          name="termsAccepted"
          required
          checked={checked}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? errorId : undefined}
          onChange={(e) => onChange(e.target.checked)}
          className="mt-0.5 rounded border-gray-300 dark:border-gray-700"
        />
        <label
          htmlFor={id}
          className="text-xs leading-relaxed text-gray-600 dark:text-gray-400"
        >
          {t.rich("termsConsent", {
            terms: (chunks) => (
              <Link href="/legal/terms" target="_blank" className={LINK_CLASS}>
                {chunks}
              </Link>
            ),
            privacy: (chunks) => (
              <Link href="/legal/privacy" target="_blank" className={LINK_CLASS}>
                {chunks}
              </Link>
            ),
          })}
          <span className="ml-0.5 text-red-600" aria-hidden>
            *
          </span>
        </label>
      </div>
      {error && (
        <p id={errorId} role="alert" className="mt-1 text-xs text-red-600">
          {error}
        </p>
      )}
    </div>
  );
}
