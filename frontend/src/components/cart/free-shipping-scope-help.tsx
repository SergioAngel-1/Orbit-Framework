"use client";
import {
  useCallback,
  useEffect,
  useId,
  useLayoutEffect,
  useRef,
  useState,
} from "react";
import { useLocale, useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";
import { useCart } from "./cart-context";
import type { FreeShippingScope } from "@/lib/shipping/free-shipping-scope";

/** Cuántas subcategorías o productos se enseñan antes de «Ver todas». */
const COLLAPSED_COUNT = 8;
/** Separación con el botón y con los bordes de la ventana, en px. */
const GAP = 8;
/** Gracia al salir con el puntero, para poder cruzar del «?» al panel. */
const HOVER_CLOSE_MS = 160;

/** Una petición por idioma para toda la sesión de página: el alcance es el mismo en las tres vistas (y lo comparte la lista de ciudades del aviso). */
const scopeRequests = new Map<string, Promise<FreeShippingScope>>();

export function loadFreeShippingScope(locale: string): Promise<FreeShippingScope> {
  let request = scopeRequests.get(locale);
  if (!request) {
    request = fetch(
      `/api/store/free-shipping/scope?locale=${encodeURIComponent(locale)}`,
    ).then((res) => {
      if (!res.ok) throw new Error(`free-shipping scope ${res.status}`);
      return res.json() as Promise<FreeShippingScope>;
    });
    // Un fallo no se queda en caché: el siguiente intento vuelve a pedirlo.
    request.catch(() => scopeRequests.delete(locale));
    scopeRequests.set(locale, request);
  }
  return request;
}

function canHover(): boolean {
  return window.matchMedia("(hover: hover) and (pointer: fine)").matches;
}

/**
 * El «?» del aviso de envío gratis: abre un panel con las categorías (y sus
 * subcategorías) y los productos que suman para el mínimo. Con ratón se abre
 * al pasar por encima; en táctil, al tocar.
 *
 * El panel usa la Popover API (`popover="manual"`): vive en la capa superior
 * del navegador, así que no lo recorta el `overflow` del cajón del carrito ni
 * queda debajo de su `<dialog>`. La posición se calcula a mano contra el botón
 * porque el posicionamiento por ancla de CSS aún no está en todos los
 * navegadores. La lista puede ser larga: el cuerpo tiene alto máximo con
 * scroll propio y cada grupo se recorta a `COLLAPSED_COUNT` con «Ver todas».
 */
export function FreeShippingScopeHelp() {
  const t = useTranslations("cart.freeShipping.scope");
  const locale = useLocale();
  const { closeDrawer } = useCart();
  const panelId = useId();
  const buttonRef = useRef<HTMLButtonElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const closeTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const [open, setOpen] = useState(false);
  const [scope, setScope] = useState<FreeShippingScope | null>(null);
  const [failed, setFailed] = useState(false);
  const [expanded, setExpanded] = useState<Record<string, boolean>>({});

  const cancelClose = useCallback(() => {
    if (closeTimer.current) clearTimeout(closeTimer.current);
    closeTimer.current = null;
  }, []);

  const show = useCallback(() => {
    cancelClose();
    setOpen(true);
    setFailed(false);
    loadFreeShippingScope(locale).then(setScope, () => setFailed(true));
  }, [cancelClose, locale]);

  const hide = useCallback(() => {
    cancelClose();
    setOpen(false);
  }, [cancelClose]);

  const hideSoon = useCallback(() => {
    cancelClose();
    closeTimer.current = setTimeout(() => setOpen(false), HOVER_CLOSE_MS);
  }, [cancelClose]);

  useEffect(() => cancelClose, [cancelClose]);

  // Abre/cierra el popover nativo y lo coloca. Se recoloca cuando cambia su
  // contenido (llegan los datos, se despliega un grupo): cambia su alto.
  useLayoutEffect(() => {
    const panel = panelRef.current;
    const button = buttonRef.current;
    if (!panel || !button || typeof panel.showPopover !== "function") return;
    if (!open) {
      if (panel.matches(":popover-open")) panel.hidePopover();
      return;
    }
    if (!panel.matches(":popover-open")) panel.showPopover();

    const anchor = button.getBoundingClientRect();
    const { offsetWidth: width, offsetHeight: height } = panel;
    const above = anchor.top - height - GAP;
    // Arriba si cabe (en el cajón y el checkout el aviso queda abajo); si no, debajo.
    const top =
      above >= GAP
        ? above
        : Math.max(
            GAP,
            Math.min(anchor.bottom + GAP, window.innerHeight - height - GAP),
          );
    const left = Math.max(
      GAP,
      Math.min(
        anchor.left + anchor.width / 2 - width / 2,
        window.innerWidth - width - GAP,
      ),
    );
    panel.style.top = `${Math.round(top)}px`;
    panel.style.left = `${Math.round(left)}px`;
  }, [open, scope, failed, expanded]);

  // Cierre: Escape, clic o toque fuera, y cualquier scroll o cambio de tamaño
  // de la página (el panel es `fixed` y se quedaría descolgado de su botón).
  useEffect(() => {
    if (!open) return;
    const outside = (event: Event) => {
      const target = event.target as Node | null;
      if (panelRef.current?.contains(target) || buttonRef.current?.contains(target))
        return;
      hide();
    };
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") hide();
    };
    document.addEventListener("pointerdown", outside);
    document.addEventListener("keydown", onKey);
    window.addEventListener("scroll", outside, { capture: true, passive: true });
    window.addEventListener("resize", hide);
    return () => {
      document.removeEventListener("pointerdown", outside);
      document.removeEventListener("keydown", onKey);
      window.removeEventListener("scroll", outside, { capture: true });
      window.removeEventListener("resize", hide);
    };
  }, [open, hide]);

  const onLink = () => {
    hide();
    closeDrawer();
  };

  const isEmpty =
    scope !== null && scope.categories.length === 0 && scope.products.length === 0;

  return (
    <>
      <button
        ref={buttonRef}
        type="button"
        aria-label={t("button")}
        aria-expanded={open}
        aria-controls={panelId}
        // Con ratón el clic solo abre (ya lo abrió el hover: alternar lo
        // cerraría bajo el puntero); en táctil, alterna.
        onClick={() => (open && !canHover() ? hide() : show())}
        onMouseEnter={() => canHover() && show()}
        onMouseLeave={() => canHover() && hideSoon()}
        // `align-middle` centra el círculo con la x del texto que lo precede.
        className="relative inline-flex size-4 shrink-0 items-center align-middle justify-center rounded-full border border-current text-[10px] font-bold leading-none text-gray-500 transition-colors after:absolute after:-inset-2.5 hover:text-gray-900 dark:hover:text-gray-100 focus-visible:text-gray-900 dark:focus-visible:text-gray-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current"
      >
        ?
      </button>
      <div
        ref={panelRef}
        id={panelId}
        popover="manual"
        role="dialog"
        aria-label={t("title")}
        onMouseEnter={cancelClose}
        onMouseLeave={() => canHover() && hideSoon()}
        // `inset-auto` y `m-0` anulan el centrado por defecto de [popover];
        // sin clase de `display`, o se vería también cerrado.
        className="fixed inset-auto m-0 w-[min(21rem,calc(100vw-1rem))] overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-0 text-left shadow-[0_12px_40px_-12px_rgb(0_0_0/0.35)]"
      >
        <div className="flex max-h-[min(26rem,calc(100dvh-1rem))] flex-col">
          <p className="border-b border-gray-200 dark:border-gray-800 px-4 py-3 text-sm font-bold">
            {t("title")}
          </p>
          <div className="flex flex-col gap-4 overflow-y-auto overscroll-contain px-4 py-3 text-sm">
            {failed ? (
              <p className="text-gray-500">{t("error")}</p>
            ) : scope === null ? (
              <p className="text-gray-500">{t("loading")}</p>
            ) : isEmpty ? (
              <p className="text-gray-500">{t("empty")}</p>
            ) : (
              <>
                {scope.categories.length > 0 && (
                  <section className="flex flex-col gap-3">
                    <h3 className="text-xs font-bold uppercase tracking-[0.08em] text-gray-500">
                      {t("categories")}
                    </h3>
                    {scope.categories.map((category) => {
                      const all = expanded[category.path] === true;
                      const children = all
                        ? category.children
                        : category.children.slice(0, COLLAPSED_COUNT);
                      const hidden = category.children.length - children.length;
                      return (
                        <div key={category.path} className="flex flex-col gap-1.5">
                          <Link
                            href={category.path}
                            onClick={onLink}
                            className="font-bold underline-offset-2 hover:underline"
                          >
                            {category.name}
                          </Link>
                          {children.length > 0 && (
                            <ul className="flex flex-wrap gap-1.5">
                              {children.map((child) => (
                                <li key={child.path}>
                                  <Link
                                    href={child.path}
                                    onClick={onLink}
                                    className="block rounded-full border border-gray-200 dark:border-gray-800 px-2.5 py-1 text-xs text-gray-500 transition-colors hover:border-gray-400 hover:text-gray-900 dark:hover:text-gray-100"
                                  >
                                    {child.name}
                                  </Link>
                                </li>
                              ))}
                              {hidden > 0 && (
                                <li>
                                  <ShowAllButton
                                    label={t("showAll", {
                                      count: category.children.length,
                                    })}
                                    onClick={() =>
                                      setExpanded((prev) => ({
                                        ...prev,
                                        [category.path]: true,
                                      }))
                                    }
                                  />
                                </li>
                              )}
                            </ul>
                          )}
                        </div>
                      );
                    })}
                  </section>
                )}
                {scope.products.length > 0 && (
                  <section className="flex flex-col gap-2">
                    <h3 className="text-xs font-bold uppercase tracking-[0.08em] text-gray-500">
                      {t("products")}
                    </h3>
                    <ul className="flex flex-col gap-1">
                      {(expanded.products
                        ? scope.products
                        : scope.products.slice(0, COLLAPSED_COUNT)
                      ).map((name) => (
                        <li key={name}>{name}</li>
                      ))}
                    </ul>
                    {!expanded.products && scope.products.length > COLLAPSED_COUNT && (
                      <div>
                        <ShowAllButton
                          label={t("showAll", { count: scope.products.length })}
                          onClick={() =>
                            setExpanded((prev) => ({ ...prev, products: true }))
                          }
                        />
                      </div>
                    )}
                  </section>
                )}
              </>
            )}
          </div>
        </div>
      </div>
    </>
  );
}

function ShowAllButton({ label, onClick }: { label: string; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="rounded-full bg-gray-200 dark:bg-gray-800 px-2.5 py-1 text-xs font-bold transition-colors hover:bg-gray-300 dark:hover:bg-gray-700"
    >
      {label}
    </button>
  );
}
