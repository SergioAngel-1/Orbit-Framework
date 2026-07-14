"use client";
import { useState, type ReactNode, type SVGProps } from "react";
import { Input, Select, Textarea, Checkbox, Button } from "@/components/ui";
import { csrfFetch } from "@/lib/client/csrf";
import { cn } from "@/lib/utils";
import { CountrySelector } from "./country-selector";
import { REQUEST_TYPES, type Branch, type RequestTypeValue } from "./contact-form.data";

export type { Branch };

// ─── Iconos inline (evita dependencia de iconos externos) ─────────────────────

function PhoneIcon(props: SVGProps<SVGSVGElement>) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
      {...props}
    >
      <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z" />
    </svg>
  );
}

function MailIcon(props: SVGProps<SVGSVGElement>) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
      {...props}
    >
      <rect x="2" y="4" width="20" height="16" rx="2" />
      <path d="m22 7-10 5L2 7" />
    </svg>
  );
}

function MapPinIcon(props: SVGProps<SVGSVGElement>) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
      {...props}
    >
      <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z" />
      <circle cx="12" cy="10" r="3" />
    </svg>
  );
}

function ClockIcon(props: SVGProps<SVGSVGElement>) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
      {...props}
    >
      <circle cx="12" cy="12" r="10" />
      <path d="M12 6v6l4 2" />
    </svg>
  );
}

function SendIcon(props: SVGProps<SVGSVGElement>) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
      {...props}
    >
      <path d="M22 2 11 13" />
      <path d="M22 2 15 22l-4-9-9-4 20-7z" />
    </svg>
  );
}

function NavigationIcon(props: SVGProps<SVGSVGElement>) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
      {...props}
    >
      <polygon points="3 11 22 2 13 21 11 13 3 11" />
    </svg>
  );
}

// ─── Redes sociales soportadas ─────────────────────────────────────────────
// Mapea 1:1 a los campos de `config.social` del HWE Control Center
// (frontend/src/lib/config/types.ts) para que un caller pueda pasar
// `socials={{ facebook: config.social.facebook, ... }}` directamente.

export interface ContactSocialLinks {
  facebook?: string;
  instagram?: string;
  youtube?: string;
}

type SocialPlatform = keyof ContactSocialLinks;

const SOCIAL_ICONS: Record<SocialPlatform, (p: SVGProps<SVGSVGElement>) => ReactNode> =
  {
    facebook: (p) => (
      <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden {...p}>
        <path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z" />
      </svg>
    ),
    instagram: (p) => (
      <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
        aria-hidden
        {...p}
      >
        <rect x="2" y="2" width="20" height="20" rx="5" />
        <circle cx="12" cy="12" r="5" />
        <circle cx="17.5" cy="6.5" r="1.5" fill="currentColor" stroke="none" />
      </svg>
    ),
    youtube: (p) => (
      <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden {...p}>
        <path d="M22.54 6.42a2.78 2.78 0 00-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 00-1.94 2A29 29 0 001 11.75a29 29 0 00.46 5.33A2.78 2.78 0 003.4 19.1c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 001.94-2 29 29 0 00.46-5.25 29 29 0 00-.46-5.43z" />
        <polygon points="9.75,15.02 15.5,11.75 9.75,8.48" fill="var(--surface, #fff)" />
      </svg>
    ),
  };

const SOCIAL_LABELS: Record<SocialPlatform, string> = {
  facebook: "Facebook",
  instagram: "Instagram",
  youtube: "YouTube",
};

function BranchCard({ branch }: { branch: Branch }) {
  return (
    <div className="flex items-start gap-3 border-b border-brand-light/20 py-3 last:border-0">
      <div className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand/8">
        <MapPinIcon className="h-4 w-4 text-brand" />
      </div>
      <div className="min-w-0 flex-1">
        <div className="flex items-center justify-between">
          <p className="font-heading text-[13px] text-[--foreground]">{branch.name}</p>
          <a
            href={branch.mapUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="flex items-center gap-1 font-sans text-[10px] text-brand transition-colors hover:text-brand-dark"
          >
            <NavigationIcon className="h-3 w-3" />
            Ir
          </a>
        </div>
        <p className="mt-0.5 font-sans text-[11px] text-[--foreground]/45">
          {branch.address}
        </p>
        <div className="mt-1 flex items-center gap-3">
          <span className="flex items-center gap-1 font-sans text-[10px] text-[--foreground]/35">
            <PhoneIcon className="h-3 w-3" /> {branch.phone}
          </span>
          <span className="flex items-center gap-1 font-sans text-[10px] text-[--foreground]/35">
            <ClockIcon className="h-3 w-3" /> {branch.hours}
          </span>
        </div>
      </div>
    </div>
  );
}

export interface ContactFormData {
  name: string;
  email: string;
  countryCode: string;
  phone: string;
  requestType: RequestTypeValue;
  description: string;
  terms: boolean;
}

const INITIAL: ContactFormData = {
  name: "",
  email: "",
  countryCode: "+57",
  phone: "",
  requestType: "peticion",
  description: "",
  terms: false,
};

type Status = "idle" | "submitting" | "success" | "error";

export interface ContactFormProps {
  className?: string;
  /** Email de contacto a mostrar (recomendado: `config.legal.email`). Vacío = canal oculto. */
  email?: string;
  /** Teléfono de contacto a mostrar, ya formateado. Vacío = canal oculto. */
  phone?: string;
  /** Enlaces a redes sociales (recomendado: derivar de `config.social`). Vacío = sección oculta. */
  socials?: ContactSocialLinks;
  /** Sedes físicas a listar. Vacío (por defecto) = sección "Sedes" oculta. */
  branches?: readonly Branch[];
}

export function ContactForm({
  className,
  email,
  phone,
  socials,
  branches = [],
}: ContactFormProps) {
  const [formData, setFormData] = useState<ContactFormData>(INITIAL);
  const [status, setStatus] = useState<Status>("idle");
  const [error, setError] = useState<string | null>(null);

  const setField = <K extends keyof ContactFormData>(
    field: K,
    value: ContactFormData[K],
  ) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
  };

  const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    setStatus("submitting");
    setError(null);
    try {
      const res = await csrfFetch("/api/contact", {
        method: "POST",
        body: {
          name: formData.name,
          email: formData.email,
          phone: `${formData.countryCode} ${formData.phone}`.trim(),
          requestType: formData.requestType,
          description: formData.description,
          acceptedTerms: formData.terms,
        },
      });
      if (!res.ok) {
        const json = (await res.json().catch(() => null)) as { error?: string } | null;
        throw new Error(json?.error ?? "No se pudo enviar la solicitud.");
      }
      setStatus("success");
      setFormData(INITIAL);
    } catch (err) {
      setStatus("error");
      setError(err instanceof Error ? err.message : "Error inesperado.");
    }
  };

  return (
    <div
      className={cn(
        "overflow-hidden rounded-2xl border border-brand-light/40 bg-surface dark:bg-gray-900",
        className,
      )}
    >
      <div className="flex flex-col lg:flex-row">
        {/* Izquierda: formulario */}
        <div className="flex flex-col border-brand-light/20 p-6 lg:w-1/2 lg:border-r lg:p-8">
          <div className="mb-6">
            <h3 className="font-heading text-[24px] leading-tight text-[--foreground]">
              Escríbenos
            </h3>
            <p className="mt-1 font-sans text-[13px] text-[--foreground]/45">
              Completa el formulario y te responderemos pronto.
            </p>
          </div>

          {status === "success" ? (
            <div className="flex flex-1 flex-col items-center justify-center gap-2 py-12 text-center">
              <div className="flex h-12 w-12 items-center justify-center rounded-full bg-secondary/15 text-secondary">
                <SendIcon className="h-5 w-5" />
              </div>
              <p className="font-heading text-[18px] text-[--foreground]">
                ¡Solicitud enviada!
              </p>
              <p className="font-sans text-[13px] text-[--foreground]/45">
                Gracias por escribirnos. Te responderemos pronto.
              </p>
              <Button
                variant="outline"
                size="sm"
                className="mt-2"
                onClick={() => setStatus("idle")}
              >
                Enviar otra
              </Button>
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="flex flex-1 flex-col gap-4">
              <Input
                label="Nombre completo"
                placeholder="Tu nombre"
                value={formData.name}
                onChange={(e) => setField("name", e.target.value)}
                required
              />

              <Input
                label="Correo electrónico"
                type="email"
                placeholder="correo@ejemplo.com"
                value={formData.email}
                onChange={(e) => setField("email", e.target.value)}
                required
              />

              <div className="flex flex-col gap-1.5">
                <label className="font-sans text-sm font-medium text-[--foreground]">
                  Teléfono de contacto
                </label>
                <div className="flex gap-2">
                  <CountrySelector
                    value={formData.countryCode}
                    onChange={(code) => setField("countryCode", code)}
                  />
                  <div className="flex-1">
                    <Input
                      placeholder="300 123 4567"
                      value={formData.phone}
                      onChange={(e) => setField("phone", e.target.value)}
                    />
                  </div>
                </div>
              </div>

              <Select
                label="Tipo de requerimiento"
                options={[...REQUEST_TYPES]}
                value={formData.requestType}
                onChange={(e) =>
                  setField("requestType", e.target.value as RequestTypeValue)
                }
              />

              <Textarea
                label="Descripción del requerimiento (PQR)"
                placeholder="Describe detalladamente tu solicitud..."
                value={formData.description}
                onChange={(e) => setField("description", e.target.value)}
                rows={4}
                required
              />

              <Checkbox
                label={
                  <span className="text-[12px] text-[--foreground]/60">
                    Acepto la{" "}
                    <a href="#" className="text-brand underline">
                      política de Privacidad
                    </a>
                  </span>
                }
                checked={formData.terms}
                onChange={(e) => setField("terms", e.target.checked)}
                required
              />

              {status === "error" && error && (
                <p
                  role="alert"
                  className="font-sans text-[12px] text-red-600 dark:text-red-400"
                >
                  {error}
                </p>
              )}

              <div className="mt-auto pt-2">
                <Button
                  type="submit"
                  variant="solid"
                  fullWidth
                  loading={status === "submitting"}
                  leadingIcon={<SendIcon className="h-4 w-4" />}
                >
                  Enviar solicitud
                </Button>
              </div>
            </form>
          )}
        </div>

        {/* Derecha: información de contacto */}
        <div className="flex flex-col p-6 lg:w-1/2 lg:p-8">
          <div className="mb-6">
            <h3 className="font-heading text-[24px] leading-tight text-[--foreground]">
              Encuéntranos
            </h3>
            <p className="mt-1 font-sans text-[13px] text-[--foreground]/45">
              Nuestras sedes y canales de atención.
            </p>
          </div>

          {(phone || email) && (
            <div className="mb-5">
              <p className="mb-3 font-sans text-[10px] tracking-[0.15em] text-[--foreground]/35">
                CANALES DE CONTACTO
              </p>
              <div className="space-y-2.5">
                {phone && (
                  <a
                    href={`tel:${phone.replace(/[^+\d]/g, "")}`}
                    className="group flex items-center gap-3"
                  >
                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand/8">
                      <PhoneIcon className="h-4 w-4 text-brand" />
                    </div>
                    <span className="font-sans text-[13px] text-[--foreground]/70 transition-colors group-hover:text-brand">
                      {phone}
                    </span>
                  </a>
                )}
                {email && (
                  <a href={`mailto:${email}`} className="group flex items-center gap-3">
                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand/8">
                      <MailIcon className="h-4 w-4 text-brand" />
                    </div>
                    <span className="break-all font-sans text-[13px] text-[--foreground]/70 transition-colors group-hover:text-brand">
                      {email}
                    </span>
                  </a>
                )}
              </div>
            </div>
          )}

          {socials && Object.values(socials).some(Boolean) && (
            <div className="mb-5">
              <p className="mb-3 font-sans text-[10px] tracking-[0.15em] text-[--foreground]/35">
                REDES SOCIALES
              </p>
              <div className="flex gap-2">
                {(Object.entries(socials) as [SocialPlatform, string | undefined][])
                  .filter((entry): entry is [SocialPlatform, string] =>
                    Boolean(entry[1]),
                  )
                  .map(([platform, href]) => {
                    const Icon = SOCIAL_ICONS[platform];
                    return (
                      <a
                        key={platform}
                        href={href}
                        title={SOCIAL_LABELS[platform]}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="flex h-9 w-9 items-center justify-center rounded-lg border border-brand-light/30 bg-[--background] transition-colors hover:border-brand/30 hover:bg-brand/8"
                      >
                        <Icon className="h-4 w-4 text-[--foreground]/50" />
                      </a>
                    );
                  })}
              </div>
            </div>
          )}

          {branches.length > 0 && (
            <div className="flex-1">
              <p className="mb-2 font-sans text-[10px] tracking-[0.15em] text-[--foreground]/35">
                SEDES
              </p>
              <div>
                {branches.map((branch) => (
                  <BranchCard key={branch.name} branch={branch} />
                ))}
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
