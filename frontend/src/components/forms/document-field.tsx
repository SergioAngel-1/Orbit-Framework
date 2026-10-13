"use client";
import { useId } from "react";
import { useTranslations } from "next-intl";
import type { GeoDocumentType } from "@/lib/geo/types";

const INPUT_CLASS =
  "w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900";

/**
 * Documento de identidad: tipo + número. Lo usan el registro, la cuenta y el checkout.
 *
 * Los tipos salen del paquete de país (`documentTypes`); con una lista vacía no se
 * pinta nada (el módulo no pide documento en ese país). `locked` lo pinta de solo
 * lectura: la cuenta ya tiene uno y no se cambia desde el sitio. En los dos estados
 * lleva, como ayuda discreta, para qué se pide. La validación es la del BFF
 * (`lib/validation/identity.ts`); aquí solo se pinta el error que llegue.
 */
export function DocumentField({
  documentTypes,
  documentType,
  documentNumber,
  onTypeChange,
  onNumberChange,
  locked = false,
  error,
  required = true,
}: {
  documentTypes: readonly GeoDocumentType[];
  documentType: string;
  documentNumber: string;
  onTypeChange?: (type: string) => void;
  onNumberChange?: (number: string) => void;
  locked?: boolean;
  error?: string;
  required?: boolean;
}) {
  const t = useTranslations("form");
  const numberId = useId();
  const typeId = useId();
  const errorId = useId();
  const helpId = useId();

  if (documentTypes.length === 0) return null;

  if (locked) {
    const label =
      documentTypes.find((d) => d.code === documentType)?.label ?? documentType;
    return (
      <div>
        <label htmlFor={numberId} className="mb-1 block text-sm font-medium">
          {t("documentNumber")}
        </label>
        <input
          id={numberId}
          value={`${label} · ${documentNumber}`}
          disabled
          readOnly
          aria-describedby={helpId}
          className={`${INPUT_CLASS} bg-gray-50 text-gray-500 dark:bg-gray-800`}
        />
        <p id={helpId} className="mt-1 text-xs text-gray-500">
          {t("documentBillingHint")} {t("documentLockedHint")}
        </p>
      </div>
    );
  }

  return (
    <div>
      <label htmlFor={numberId} className="mb-1 block text-sm font-medium">
        {t("document")}
        {required && (
          <span className="ml-0.5 text-red-600" aria-hidden>
            *
          </span>
        )}
      </label>
      <div className="flex items-start gap-2">
        <select
          id={typeId}
          name="documentType"
          aria-label={t("documentType")}
          value={documentType}
          onChange={(e) => onTypeChange?.(e.target.value)}
          className="w-28 shrink-0 rounded-lg border border-gray-300 px-2 py-2 dark:border-gray-700 dark:bg-gray-900"
        >
          {documentTypes.map((d) => (
            <option key={d.code} value={d.code}>
              {d.code}
            </option>
          ))}
        </select>
        <div className="min-w-0 flex-1">
          <input
            id={numberId}
            name="documentNumber"
            required={required}
            autoComplete="off"
            inputMode="text"
            placeholder={t("documentNumberPlaceholder")}
            value={documentNumber}
            aria-invalid={error ? true : undefined}
            aria-describedby={error ? errorId : helpId}
            onChange={(e) => onNumberChange?.(e.target.value)}
            className={INPUT_CLASS}
          />
        </div>
      </div>
      {error ? (
        <p id={errorId} role="alert" className="mt-1 text-xs text-red-600">
          {error}
        </p>
      ) : (
        <p id={helpId} className="mt-1 text-xs text-gray-500">
          {t("documentBillingHint")}
        </p>
      )}
    </div>
  );
}
