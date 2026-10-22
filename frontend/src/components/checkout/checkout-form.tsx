"use client";
import { HUBSPOT_DO_NOT_COLLECT } from "@/lib/analytics/hubspot";
import { useEffect, useRef, useState } from "react";
import { useLocale, useTranslations } from "next-intl";
import { csrfFetch } from "@/lib/client/csrf";
import { useCart } from "@/components/cart/cart-context";
import { Button } from "@/components/ui/button";
import { useFormatStoreAmount } from "@/components/ui/currency-context";
import {
  paymentMethodFor,
  shouldStartGatewayPayment,
  type PaymentChoice,
} from "@/lib/payments/checkout-options";
import { checkoutReturnUrl } from "@/lib/checkout/return-url";
import { canSubmitCheckout } from "@/lib/validation/checkout-guard";
import { beginCheckoutParams } from "@/lib/analytics/ecommerce";
import { hasTrackingConsent, trackEvent } from "@/components/analytics/track-event";
import {
  checkoutSeedFromCustomer,
  type CheckoutCustomerSeed,
} from "@/lib/checkout/customer-seed";
import { CityField, DivisionField } from "@/components/forms/geo-fields";
import { DocumentField } from "@/components/forms/document-field";
import { TermsConsent } from "@/components/forms/terms-consent";
import { hasDocument, type CustomerIdentity } from "@/lib/customer/identity";
import { EMPTY_IDENTITY } from "@/lib/customer/identity";
import type { GeoDocumentType } from "@/lib/geo/types";
import { GENERIC_PACK } from "@/lib/geo/generic";
import { checkAddress } from "@/lib/geo/address";
import { FreeShippingNotice } from "@/components/cart/free-shipping-notice";
import { cartHasDestination } from "@/lib/shipping/destination";
import type { ShippingMethod } from "@/lib/shipping/types";
import type { GeoPack } from "@/lib/geo/types";
import type { WooCustomer } from "@/types/woocommerce";

interface CheckoutResult {
  order_id: number;
  status: string;
}

/** Campos del formulario, en el orden en que se pintan. */
const FIELD_NAMES = [
  "first_name",
  "last_name",
  "address_1",
  "city",
  "state",
  "postcode",
  "country",
  "email",
  "phone",
] as const;
type FieldName = (typeof FIELD_NAMES)[number];
type FieldValues = Record<FieldName, string>;

const EMPTY_VALUES: FieldValues = {
  first_name: "",
  last_name: "",
  address_1: "",
  city: "",
  state: "",
  postcode: "",
  country: "",
  email: "",
  phone: "",
};

/**
 * Valores iniciales: el perfil del cliente y su dirección predeterminada (o la
 * primera de la libreta). Sin cliente (invitado), vacío con el país de la tienda.
 */
function initialValues(
  seed: CheckoutCustomerSeed | null | undefined,
  defaultCountry: string,
): FieldValues {
  if (!seed) return { ...EMPTY_VALUES, country: defaultCountry };
  const address = seed.addresses.find((a) => a.is_default) ?? seed.addresses[0];
  return {
    first_name: address?.first_name || seed.first_name,
    last_name: address?.last_name || seed.last_name,
    address_1: address?.address_1 ?? "",
    city: address?.city || seed.city,
    state: address ? address.state : seed.state,
    postcode: address?.postcode ?? "",
    country: address?.country || seed.country || defaultCountry,
    email: seed.email,
    phone: address?.phone || seed.phone,
  };
}

/** Claves de `checkout.*` (es/en) de cada medio de pago. */
const CHOICE_COPY: Record<
  PaymentChoice,
  { label: string; hint: string; submit: string }
> = {
  online: {
    label: "paymentOnline",
    hint: "paymentOnlineHint",
    submit: "submitOnline",
  },
  whatsapp: {
    label: "paymentWhatsapp",
    hint: "paymentWhatsappHint",
    submit: "submitWhatsapp",
  },
  cash: {
    label: "paymentCash",
    hint: "paymentCashHint",
    submit: "submit",
  },
};

export interface CheckoutFormProps {
  /**
   * Perfil y libreta del cliente con sesión, ya leídos por la página en el
   * servidor: el formulario nace relleno. `null` (WordPress no respondió) = lo
   * pide el propio formulario al BFF.
   */
  customer?: CheckoutCustomerSeed | null;
  /** Compra sin sesión (`ecommerce.checkout_login_required` apagado). */
  guest?: boolean;
  /** País de la tienda (`config.ecommerce.country`) para el campo vacío. */
  defaultCountry?: string;
  /**
   * Paquete de país (`getGeoPack()`): ciudades, divisiones y política de la
   * dirección. Omitido, la dirección es texto libre.
   */
  geoPack?: GeoPack;
  /**
   * Módulo `customer-identity` activo: pide la aceptación de términos y, si el país
   * tiene tipos de documento, el documento. Omitido, el checkout no pide ninguno.
   */
  identity?: { documentTypes: GeoDocumentType[] };
  /**
   * Módulo `shipping` activo: ofrece los métodos extra (aditivos, se cobran como
   * cargo del pedido), avisa del envío gratis y comunica el destino al carrito para
   * que WooCommerce calcule la tarifa por ciudad. Omitido, nada de eso.
   */
  shipping?: { methods: ShippingMethod[] };
  /**
   * Medios activos en el Control Center, en orden (`enabledPaymentChoices`):
   * pago en línea, link por WhatsApp y efectivo. Con uno solo no se pinta el
   * selector. Omitido, el formulario ofrece solo el pago en línea.
   */
  paymentChoices?: PaymentChoice[];
  /**
   * La pasarela activa cobra con credenciales de pruebas
   * (`isActiveGatewaySandbox`). Solo pinta un aviso: el cobro va igual.
   */
  gatewaySandbox?: boolean;
  /** `config.ecommerce.coupons_enabled` — oculta la sección de cupón si es `false`. */
  couponsEnabled?: boolean;
}

export function CheckoutForm({
  customer,
  guest = false,
  defaultCountry = "",
  geoPack = GENERIC_PACK,
  identity,
  shipping,
  paymentChoices = ["online"],
  gatewaySandbox = false,
  couponsEnabled = false,
}: CheckoutFormProps) {
  const {
    cart,
    loading,
    refresh,
    applyCoupon,
    removeCoupon,
    selectShippingRate,
    setShippingDestination,
  } = useCart();
  const t = useTranslations();
  const formatStoreAmount = useFormatStoreAmount();
  const tCheckout = t.raw("checkout") as unknown as Record<string, string>;
  const tCart = t.raw("cart") as unknown as Record<string, string>;
  const tForm = t.raw("form") as unknown as Record<string, string>;
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const locale = useLocale();
  const [values, setValues] = useState<FieldValues>(() =>
    initialValues(customer, defaultCountry),
  );
  // Lo que el cliente ya escribió no se pisa con el respaldo asíncrono.
  const touchedRef = useRef(false);
  // Un envío en vuelo bloquea otro (doble clic, Enter repetido) y la clave de
  // idempotencia es estable durante todo el intento: un reintento tras un fallo
  // de red no crea un segundo pedido. Se renueva solo tras crear el pedido.
  const submittingRef = useRef(false);
  const idempotencyKeyRef = useRef<string | null>(null);
  const [choice, setChoice] = useState<PaymentChoice>(paymentChoices[0] ?? "online");
  // Si la lista de medios cambia (config revalidada) y el elegido ya no está,
  // se cae al primero: nunca se envía un medio que el servidor rechazaría.
  const effectiveChoice: PaymentChoice = paymentChoices.includes(choice)
    ? choice
    : (paymentChoices[0] ?? "online");
  const copy = CHOICE_COPY[effectiveChoice];
  const [couponCode, setCouponCode] = useState("");

  // Extras de envío (módulo `shipping`): se suman al pedido como cargo DESPUÉS de crearlo
  // (el total de la Store API no los conoce), así que aquí se suman para que lo que se
  // muestra coincida con lo que se cobra.
  const shippingMethods = shipping?.methods ?? [];
  const [selectedMethodIds, setSelectedMethodIds] = useState<number[]>([]);
  const selectedMethods = shippingMethods.filter((m) =>
    selectedMethodIds.includes(m.id),
  );
  const extrasMajor = selectedMethods.reduce((sum, m) => sum + m.addedValue, 0);
  function toggleShippingMethod(id: number) {
    setSelectedMethodIds((ids) =>
      ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id],
    );
  }

  // Documento y términos (módulo `customer-identity`). Lo que la cuenta ya tenga
  // manda: el documento queda bloqueado y la aceptación no se vuelve a pedir.
  const [storedIdentity, setStoredIdentity] = useState<CustomerIdentity>(
    customer?.identity ?? EMPTY_IDENTITY,
  );
  const documentTypes = identity?.documentTypes ?? [];
  const [documentType, setDocumentType] = useState(documentTypes[0]?.code ?? "");
  const [documentNumber, setDocumentNumber] = useState("");
  const [termsAccepted, setTermsAccepted] = useState(false);
  const [identityError, setIdentityError] = useState<string | null>(null);
  const [termsError, setTermsError] = useState<string | null>(null);
  const documentLocked = hasDocument(storedIdentity);
  const askDocument = Boolean(identity) && documentTypes.length > 0;
  const askTerms = Boolean(identity) && !storedIdentity.termsAcceptedAt;

  const FIELDS: {
    name: FieldName;
    label: string;
    type?: string;
    required?: boolean;
  }[] = [
    { name: "first_name", label: tForm.firstName, required: true },
    { name: "last_name", label: tForm.lastName, required: true },
    { name: "address_1", label: tForm.address, required: true },
    { name: "city", label: tForm.city, required: true },
    { name: "state", label: tForm.state },
    ...(geoPack.address.postcode === "none"
      ? []
      : [
          {
            name: "postcode" as const,
            label: tForm.postcode,
            required: geoPack.address.postcode === "required",
          },
        ]),
    { name: "country", label: tForm.country, required: true },
    { name: "email", label: tForm.email, type: "email", required: true },
    { name: "phone", label: tForm.phone },
  ];

  // `begin_checkout` una sola vez por visita, en cuanto hay un carrito con algo.
  const beginTracked = useRef(false);
  useEffect(() => {
    if (beginTracked.current || !cart) return;
    const params = beginCheckoutParams(cart);
    if (!params) return;
    beginTracked.current = true;
    trackEvent("begin_checkout", params);
  }, [cart]);

  // La tarifa de envío es por CIUDAD y la calcula WooCommerce con el destino del carrito:
  // cada vez que cambia la ciudad (o el país/la división) se le comunica y el carrito vuelve
  // con `shipping_rates` recalculadas. Con el debounce, escribir la ciudad letra a letra no
  // dispara una petición por tecla; el ref evita reenviar el mismo destino (al volver `cart`).
  const destinationCity = values.city.trim();
  const destinationCountry = (values.country || defaultCountry).trim().toUpperCase();
  const destinationState = values.state;
  const destinationPostcode = values.postcode.trim();
  const needsShipping = cart?.needs_shipping ?? false;
  const lastDestinationRef = useRef<string | null>(null);
  const checkedDestination = checkAddress(geoPack, {
    city: destinationCity,
    state: destinationState,
    country: destinationCountry,
  });
  // El BFF ya fija el destino del cliente con sesión al abrirla: si el carrito llega con el
  // de la dirección elegida, no hace falta otra ida a WordPress.
  const destinationInSync = cartHasDestination(cart, {
    city: destinationCity,
    country: destinationCountry,
    state: checkedDestination.state,
    postcode: destinationPostcode,
  });
  const destinationValid = checkedDestination.issues.length === 0;
  const shippingEnabled = shipping !== undefined;
  useEffect(() => {
    if (!shippingEnabled || !needsShipping || !destinationCity || !destinationValid)
      return;
    if (destinationCountry.length !== 2) return;
    const key = [destinationCountry, destinationState, destinationCity].join("|");
    if (lastDestinationRef.current === key) return;
    if (destinationInSync) {
      lastDestinationRef.current = key;
      return;
    }
    const timer = setTimeout(() => {
      lastDestinationRef.current = key;
      void setShippingDestination({
        city: destinationCity,
        country: destinationCountry,
        state: destinationState || undefined,
        postcode: destinationPostcode || undefined,
      });
    }, 400);
    return () => clearTimeout(timer);
  }, [
    shippingEnabled,
    needsShipping,
    destinationCity,
    destinationCountry,
    destinationState,
    destinationPostcode,
    destinationInSync,
    destinationValid,
    setShippingDestination,
  ]);

  // Respaldo: el servidor no pudo leer al cliente (`customer === null`), así
  // que el formulario lo pide al BFF. Un invitado no tiene nada que pedir.
  useEffect(() => {
    if (customer !== null || guest) return;
    let cancelled = false;
    void (async () => {
      try {
        const res = await fetch("/api/store/customer", { credentials: "same-origin" });
        if (!res.ok || cancelled || touchedRef.current) return;
        const seed = checkoutSeedFromCustomer(
          (await res.json()) as WooCustomer,
          defaultCountry,
        );
        if (!cancelled && !touchedRef.current) {
          setValues(initialValues(seed, defaultCountry));
          setStoredIdentity(seed.identity);
        }
      } catch {
        /* sin precarga: el cliente escribe sus datos */
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [customer, guest, defaultCountry]);

  /**
   * Inicia el cobro del pedido con la pasarela activa (capa agnóstica).
   * @returns true si redirige al checkout alojado (la página se abandona).
   */
  async function startPayment(orderId: number, returnUrl: string): Promise<boolean> {
    try {
      const res = await csrfFetch("/api/payments/create", {
        method: "POST",
        body: { reference: orderId, returnUrl },
      });
      if (!res.ok) return false; // sin sesión / pasarela no aplicable → fallback
      const data = (await res.json().catch(() => ({}))) as {
        mode?: string;
        redirectUrl?: string;
      };
      if (data.mode === "redirect" && data.redirectUrl) {
        window.location.href = data.redirectUrl;
        return true;
      }
      return false;
    } catch {
      return false;
    }
  }

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (submittingRef.current) return;
    submittingRef.current = true;
    setPending(true);
    setError(null);
    setIdentityError(null);
    setTermsError(null);

    const billing_address = Object.fromEntries(
      FIELD_NAMES.map((name) => [name, values[name].trim()]),
    );
    idempotencyKeyRef.current ??= crypto.randomUUID();

    try {
      const res = await csrfFetch("/api/store/checkout", {
        method: "POST",
        headers: { "Idempotency-Key": idempotencyKeyRef.current },
        body: {
          billing_address,
          payment_method: paymentMethodFor(effectiveChoice),
          customer_note: "",
          ...(selectedMethodIds.length > 0
            ? { shipping_method_ids: selectedMethodIds }
            : {}),
          ...(askDocument && !documentLocked
            ? { identity: { documentType, documentNumber } }
            : {}),
          ...(askTerms ? { terms_accepted: askTerms && termsAccepted } : {}),
          // Contexto de medición del pedido: el consentimiento que rige y el
          // idioma de la página (el servidor lo guarda solo si hay «Aceptar»).
          tracking: { consent: hasTrackingConsent(), locale },
        },
      });
      const data = (await res.json().catch(() => ({}))) as
        CheckoutResult | { error?: string };
      if (!res.ok) {
        const details = (data as { details?: Record<string, string[]> }).details;
        setIdentityError(details?.identity?.[0] ?? null);
        setTermsError(details?.terms_accepted ? tForm.termsRequired : null);
        throw new Error(("error" in data && data.error) || tCheckout.genericError);
      }

      const order = data as CheckoutResult;
      // Pedido creado: el siguiente intento es una compra nueva.
      idempotencyKeyRef.current = null;
      await refresh();

      // La pantalla de «gracias» está en el idioma de la página; el cobro en
      // línea también vuelve a ella.
      const returnUrl = checkoutReturnUrl(
        window.location.origin,
        locale as Parameters<typeof checkoutReturnUrl>[1],
        order.order_id,
      );

      // Efectivo o link por WhatsApp: no hay cobro en línea; «gracias» dice
      // cómo sigue (pago al recibir, o el link que enviará la tienda).
      if (!shouldStartGatewayPayment(effectiveChoice)) {
        window.location.href = returnUrl;
        return;
      }

      // Capa de pagos agnóstica: inicia el cobro con la pasarela activa. Si
      // devuelve un checkout alojado, redirige. Si no, el pedido ya existe y el
      // carrito se vació: «gracias» lo dice y ofrece reintentar el pago.
      const redirected = await startPayment(order.order_id, returnUrl);
      if (!redirected) window.location.href = `${returnUrl}&retry=1`;
    } catch (err) {
      setError(err instanceof Error ? err.message : tCheckout.genericError);
    } finally {
      submittingRef.current = false;
      setPending(false);
    }
  }

  const empty = !cart || cart.items.length === 0;
  // Carrito que necesita envío y sin ninguna tarifa (zona sin cubrir): no se
  // cierra el pedido con envío 0. El servidor aplica la misma regla.
  const noShippingRate = !empty && !canSubmitCheckout(cart);

  return (
    <form {...HUBSPOT_DO_NOT_COLLECT} onSubmit={onSubmit} className="space-y-6">
      {error && (
        <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
          {error}
        </p>
      )}
      {noShippingRate && (
        <p
          role="alert"
          className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
        >
          {tCheckout.noShipping}
        </p>
      )}
      {empty && (
        <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
          {tCheckout.emptyCart}
        </p>
      )}

      {/* Cupón */}
      {couponsEnabled && (
        <section className="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
          <h3 className="mb-2 text-sm font-semibold">{tCart.couponCode}</h3>
          <div className="flex gap-2">
            <input
              type="text"
              value={couponCode}
              onChange={(e) => setCouponCode(e.target.value)}
              placeholder={tCart.couponCode}
              aria-label={tCart.couponCode}
              className="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"
            />
            <Button
              variant="outline"
              size="sm"
              disabled={loading || !couponCode.trim()}
              onClick={() => {
                applyCoupon(couponCode.trim());
                setCouponCode("");
              }}
            >
              {tCart.applyCoupon}
            </Button>
          </div>
          {cart?.coupons.map((c) => (
            <div
              key={c.code}
              className="mt-2 flex items-center justify-between text-sm"
            >
              <span className="text-green-600 dark:text-green-400">
                {c.code}
                {c.description && (
                  <span className="ml-2 text-xs text-gray-500">{c.description}</span>
                )}
              </span>
              <button
                type="button"
                disabled={loading}
                onClick={() => removeCoupon(c.code)}
                className="text-xs text-gray-400 hover:text-red-600"
              >
                {tCart.removeCoupon}
              </button>
            </div>
          ))}
        </section>
      )}

      {/* Selección de envío */}
      {cart && cart.needs_shipping && cart.shipping_rates.length > 0 && (
        <section className="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
          <h3 className="mb-2 text-sm font-semibold">{tCheckout.shippingMethod}</h3>
          {cart.shipping_rates.map((pkg) => (
            <div key={pkg.package_id}>
              <p className="mb-1 text-xs text-gray-500">{pkg.name}</p>
              {pkg.shipping_rates.map((rate) => (
                <label
                  key={rate.rate_id}
                  className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-900"
                >
                  <input
                    type="radio"
                    name={`shipping_${pkg.package_id}`}
                    defaultChecked={rate.selected}
                    onChange={() => selectShippingRate(pkg.package_id, rate.rate_id)}
                    className="text-brand dark:text-accent"
                  />
                  <span className="text-sm">{rate.name}</span>
                  <span className="ml-auto text-sm font-medium">
                    {rate.price === "0"
                      ? "Gratis"
                      : formatStoreAmount(
                          rate.price,
                          rate.currency_minor_unit,
                          rate.currency_code,
                        )}
                  </span>
                </label>
              ))}
            </div>
          ))}
        </section>
      )}

      {/* Dirección */}
      <section className="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
        <h3 className="mb-3 text-sm font-semibold">{tCheckout.shippingAddress}</h3>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          {FIELDS.map((f) => {
            const setField = (value: string, auto?: boolean) => {
              if (!auto) touchedRef.current = true;
              setValues((v) => (v[f.name] === value ? v : { ...v, [f.name]: value }));
            };
            const id = `checkout-${f.name}`;
            if (f.name === "city") {
              return (
                <CityField
                  key={f.name}
                  id={id}
                  name={f.name}
                  label={f.label}
                  pack={geoPack}
                  required={f.required}
                  value={values.city}
                  onChange={setField}
                />
              );
            }
            if (f.name === "state") {
              return (
                <DivisionField
                  key={f.name}
                  id={id}
                  name={f.name}
                  label={geoPack.fallback ? f.label : geoPack.divisionLabel}
                  pack={geoPack}
                  city={values.city}
                  country={values.country}
                  value={values.state}
                  onChange={setField}
                />
              );
            }
            return (
              <div key={f.name}>
                <label
                  htmlFor={`checkout-${f.name}`}
                  className="mb-1 block text-sm font-medium"
                >
                  {f.label}
                </label>
                <input
                  id={`checkout-${f.name}`}
                  name={f.name}
                  type={f.type ?? "text"}
                  required={f.required}
                  value={values[f.name]}
                  onChange={(e) => setField(e.target.value)}
                  className="w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900"
                />
              </div>
            );
          })}
        </div>
        {(askDocument || askTerms) && (
          <div className="mt-4 space-y-4">
            {askDocument && (
              <DocumentField
                documentTypes={documentTypes}
                documentType={
                  documentLocked ? (storedIdentity.documentType ?? "") : documentType
                }
                documentNumber={
                  documentLocked
                    ? (storedIdentity.documentNumber ?? "")
                    : documentNumber
                }
                locked={documentLocked}
                onTypeChange={(v) => {
                  touchedRef.current = true;
                  setDocumentType(v);
                  setIdentityError(null);
                }}
                onNumberChange={(v) => {
                  touchedRef.current = true;
                  setDocumentNumber(v);
                  setIdentityError(null);
                }}
                error={identityError ?? undefined}
              />
            )}
            {askTerms && (
              <TermsConsent
                checked={termsAccepted}
                onChange={(v) => {
                  setTermsAccepted(v);
                  setTermsError(null);
                }}
                error={termsError ?? undefined}
              />
            )}
          </div>
        )}
      </section>

      {/* Extras de envío (módulo `shipping`) */}
      {shippingMethods.length > 0 && (
        <fieldset className="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
          <legend className="px-1 text-sm font-semibold">
            {tCheckout.shippingExtras}
          </legend>
          {shippingMethods.map((method) => (
            <label
              key={method.id}
              className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-900"
            >
              <input
                type="checkbox"
                checked={selectedMethodIds.includes(method.id)}
                onChange={() => toggleShippingMethod(method.id)}
              />
              <span className="flex-1 text-sm">{method.name}</span>
              {method.addedValue > 0 && cart && (
                <span className="text-sm font-medium">
                  +{" "}
                  {formatStoreAmount(
                    String(
                      Math.round(
                        method.addedValue * 10 ** cart.totals.currency_minor_unit,
                      ),
                    ),
                    cart.totals.currency_minor_unit,
                    cart.totals.currency_code,
                  )}
                </span>
              )}
            </label>
          ))}
        </fieldset>
      )}

      {/* Medio de pago */}
      {paymentChoices.length > 1 && (
        <fieldset className="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
          <legend className="px-1 text-sm font-semibold">
            {tCheckout.paymentMethod}
          </legend>
          {paymentChoices.map((c) => (
            <div
              key={c}
              className="flex items-start gap-2 rounded-lg px-2 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-900"
            >
              <input
                id={`payment-choice-${c}`}
                type="radio"
                name="payment_choice"
                value={c}
                checked={effectiveChoice === c}
                onChange={() => setChoice(c)}
                className="mt-1 text-brand dark:text-accent"
              />
              <label htmlFor={`payment-choice-${c}`} className="cursor-pointer">
                <span className="block text-sm font-medium">
                  {tCheckout[CHOICE_COPY[c].label]}
                </span>
                <span className="block text-xs text-gray-500">
                  {tCheckout[CHOICE_COPY[c].hint]}
                </span>
              </label>
            </div>
          ))}
        </fieldset>
      )}
      {gatewaySandbox && effectiveChoice === "online" && (
        <p
          role="status"
          className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
        >
          {tCheckout.sandboxNotice}
        </p>
      )}

      {/* Resumen */}
      <section className="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
        <h3 className="mb-3 text-sm font-semibold">{tCart.total}</h3>
        {shippingEnabled && <FreeShippingNotice cart={cart} className="mb-3" />}
        <div className="space-y-1 text-sm">
          <div className="flex justify-between">
            <span className="text-gray-500">{tCart.subtotal}</span>
            <span>
              {cart &&
                formatStoreAmount(
                  cart.totals.total_items,
                  cart.totals.currency_minor_unit,
                  cart.totals.currency_code,
                )}
            </span>
          </div>
          {cart && cart.totals.total_discount !== "0" && (
            <div className="flex justify-between">
              <span className="text-gray-500">{tCart.discount}</span>
              <span className="text-green-600">
                -
                {formatStoreAmount(
                  cart.totals.total_discount,
                  cart.totals.currency_minor_unit,
                  cart.totals.currency_code,
                )}
              </span>
            </div>
          )}
          {cart && cart.totals.total_shipping !== "0" && (
            <div className="flex justify-between">
              <span className="text-gray-500">{tCart.shipping}</span>
              <span>
                {formatStoreAmount(
                  cart.totals.total_shipping,
                  cart.totals.currency_minor_unit,
                  cart.totals.currency_code,
                )}
              </span>
            </div>
          )}
          {cart && extrasMajor > 0 && (
            <div className="flex justify-between">
              <span className="text-gray-500">{tCheckout.shippingExtras}</span>
              <span>
                {formatStoreAmount(
                  String(
                    Math.round(extrasMajor * 10 ** cart.totals.currency_minor_unit),
                  ),
                  cart.totals.currency_minor_unit,
                  cart.totals.currency_code,
                )}
              </span>
            </div>
          )}
          <div className="flex justify-between border-t border-gray-200 pt-2 font-semibold dark:border-gray-700">
            <span>{tCart.total}</span>
            <span>
              {cart &&
                formatStoreAmount(
                  String(
                    Number(cart.totals.total_price) +
                      Math.round(extrasMajor * 10 ** cart.totals.currency_minor_unit),
                  ),
                  cart.totals.currency_minor_unit,
                  cart.totals.currency_code,
                )}
            </span>
          </div>
        </div>
      </section>

      <button
        type="submit"
        disabled={pending || empty || noShippingRate}
        className="w-full rounded-lg bg-brand py-3 font-semibold text-white transition-colors hover:bg-brand-dark disabled:opacity-50 dark:bg-accent dark:text-gray-950 dark:hover:bg-accent/85"
      >
        {pending ? tCheckout.processing : tCheckout[copy.submit]}
      </button>
    </form>
  );
}
