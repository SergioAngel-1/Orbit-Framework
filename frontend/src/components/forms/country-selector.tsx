"use client";
import { useEffect, useMemo, useRef, useState, type ComponentType } from "react";
import { US, CA, MX, CO, AR, CL, PE, EC, VE, BO, PY, UY, ES, BR, GB } from "country-flag-icons/react/3x2";
import { Modal, Input } from "@/components/ui";
import { cn } from "@/lib/utils";
import { COUNTRIES, type Country } from "./contact-form.data";

type FlagComponent = ComponentType<{ className?: string; title?: string }>;

const FLAG_ICONS: Record<string, FlagComponent> = {
  US, CA, MX, CO, AR, CL, PE, EC, VE, BO, PY, UY, ES, BR, GB,
};

function toFlagEmoji(countryCode: string): string {
  return countryCode
    .toUpperCase()
    .split("")
    .map((c) => String.fromCodePoint(0x1f1e6 + c.charCodeAt(0) - 65))
    .join("");
}

function Flag({ country, className }: { country: string; className?: string }) {
  const Icon = FLAG_ICONS[country];
  if (Icon) return <Icon className={className} />;
  return <span className="text-lg leading-none">{toFlagEmoji(country)}</span>;
}

export interface CountrySelectorProps {
  value?: string;
  onChange?: (code: string) => void;
  className?: string;
}

export function CountrySelector({ value = "+57", onChange, className }: CountrySelectorProps) {
  const [isOpen, setIsOpen] = useState(false);
  const [search, setSearch] = useState("");
  const inputRef = useRef<HTMLInputElement>(null);

  const selected = COUNTRIES.find((c) => c.code === value) ?? COUNTRIES[0];

  useEffect(() => {
    if (isOpen && inputRef.current) {
      const id = setTimeout(() => inputRef.current?.focus(), 100);
      return () => clearTimeout(id);
    }
    setSearch("");
  }, [isOpen]);

  const filtered = useMemo(() => {
    const q = search.toLowerCase();
    return COUNTRIES.filter(
      (c) => c.name.toLowerCase().includes(q) || c.code.includes(q),
    );
  }, [search]);

  const handleSelect = (country: Country) => {
    onChange?.(country.code);
    setIsOpen(false);
  };

  return (
    <>
      <button
        type="button"
        onClick={() => setIsOpen(true)}
        className={cn(
          "flex items-center gap-2 rounded-lg border-2 border-brand-light/60 bg-surface px-3 py-3",
          "cursor-pointer transition-colors hover:border-brand/50",
          "dark:bg-gray-950",
          className,
        )}
      >
        <Flag country={selected.country} className="h-4 w-5" />
        <span className="font-sans text-[13px] text-[--foreground]">{selected.code}</span>
        <svg
          className="ml-auto h-4 w-4 text-[--foreground]/40"
          xmlns="http://www.w3.org/2000/svg"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
          aria-hidden
        >
          <path d="m6 9 6 6 6-6" />
        </svg>
      </button>

      <Modal open={isOpen} onClose={() => setIsOpen(false)} title="Seleccionar país" size="sm">
        <Input
          ref={inputRef}
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Buscar país..."
          className="mb-4"
        />

        {filtered.length === 0 ? (
          <p className="py-8 text-center font-sans text-[12px] text-[--foreground]/35">
            No se encontraron países
          </p>
        ) : (
          <div className="space-y-1">
            {filtered.map((country) => (
              <button
                key={`${country.code}-${country.country}`}
                type="button"
                onClick={() => handleSelect(country)}
                className={cn(
                  "flex w-full cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 text-left",
                  "transition-colors duration-150",
                  value === country.code
                    ? "border border-brand/20 bg-brand/8"
                    : "hover:bg-surface dark:hover:bg-gray-800",
                )}
              >
                <Flag country={country.country} className="h-4 w-6" />
                <span className="font-sans text-[13px] text-[--foreground]">{country.name}</span>
                <span className="ml-auto font-sans text-[13px] text-[--foreground]/60">{country.code}</span>
                {value === country.code && (
                  <span className="font-sans text-[10px] tracking-wide text-brand">ACTUAL</span>
                )}
              </button>
            ))}
          </div>
        )}
      </Modal>
    </>
  );
}
