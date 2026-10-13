"use client";
import { useEffect } from "react";
import { useTranslations } from "next-intl";
import { divisionOptionsForCity, findDivision, packCountry } from "@/lib/geo/address";
import type { GeoPack } from "@/lib/geo/types";

// ============================================================================
//  Campos de dirección que dependen del paquete de país (`getGeoPack()`):
//  ciudad con sugerencias y división (departamento, provincia…) como lista
//  cerrada cuando el paquete la define, o texto libre cuando no.
//
//  Reciben el paquete por props: no leen nada por su cuenta.
// ============================================================================

const INPUT_CLASS =
  "w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900";

interface CommonProps {
  id: string;
  name: string;
  label: string;
  pack: GeoPack;
  required?: boolean;
}

/** Ciudad: texto libre con las ciudades del paquete como sugerencia. */
export function CityField({
  id,
  name,
  label,
  pack,
  required,
  value,
  onChange,
}: CommonProps & { value: string; onChange: (value: string) => void }) {
  const listId = `${id}-options`;
  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-sm font-medium">
        {label}
      </label>
      <input
        id={id}
        name={name}
        required={required}
        value={value}
        autoComplete="address-level2"
        list={pack.cities.length > 0 ? listId : undefined}
        onChange={(e) => onChange(e.target.value)}
        className={INPUT_CLASS}
      />
      {pack.cities.length > 0 && (
        <datalist id={listId}>
          {pack.cities.map((c) => (
            <option key={c.name} value={c.name} />
          ))}
        </datalist>
      )}
    </div>
  );
}

/**
 * División administrativa. Con lista cerrada (`required`/`optional` y divisiones
 * en el paquete) es un `<select>` que, para una ciudad conocida, solo ofrece la
 * suya; si no, texto libre. Con `none` no se pinta.
 *
 * `onChange(value, auto)`: `auto` es `true` cuando el campo se ajusta solo (la
 * ciudad fija la división, o un valor antiguo en texto pasa a su código).
 */
export function DivisionField({
  id,
  name,
  label,
  pack,
  required,
  city,
  country,
  value,
  onChange,
}: CommonProps & {
  city: string;
  /** País elegido en el formulario; fuera del país del paquete, texto libre. */
  country: string;
  value: string;
  onChange: (value: string, auto?: boolean) => void;
}) {
  const tForm = useTranslations("form");
  const own = packCountry(pack);
  const closed =
    pack.divisions.length > 0 &&
    (pack.address.division === "required" || pack.address.division === "optional") &&
    (!country || country.toUpperCase() === own);
  const options = closed ? divisionOptionsForCity(pack, city) : [];
  const onlyOption = options.length === 1 ? options[0].code : "";
  const known = options.some((d) => d.code === value);
  // Un valor escrito a mano (libreta antigua) que equivale a una división pasa a su código.
  const normalized =
    closed && value && !known ? (findDivision(pack, value)?.code ?? "") : "";

  useEffect(() => {
    if (!closed) return;
    if (onlyOption && value !== onlyOption) onChange(onlyOption, true);
    else if (normalized && normalized !== value) onChange(normalized, true);
    else if (!onlyOption && value && !known && !normalized) onChange("", true);
  }, [closed, onlyOption, normalized, known, value, onChange]);

  if (pack.address.division === "none") return null;

  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-sm font-medium">
        {label}
      </label>
      {closed ? (
        <select
          id={id}
          name={name}
          required={required ?? pack.address.division === "required"}
          value={known ? value : onlyOption}
          autoComplete="address-level1"
          onChange={(e) => onChange(e.target.value)}
          className={INPUT_CLASS}
        >
          {options.length !== 1 && <option value="">{tForm("select")}</option>}
          {options.map((d) => (
            <option key={d.code} value={d.code}>
              {d.name}
            </option>
          ))}
        </select>
      ) : (
        <input
          id={id}
          name={name}
          value={value}
          autoComplete="address-level1"
          onChange={(e) => onChange(e.target.value)}
          className={INPUT_CLASS}
        />
      )}
    </div>
  );
}
