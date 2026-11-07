import { describe, expect, it } from "vitest";
import { localeCookieSecure } from "./locale-cookie";
import { routing } from "./routing";

describe("localeCookieSecure", () => {
  it("es Secure en producción con HTTPS o sin URL declarada", () => {
    expect(localeCookieSecure({ NODE_ENV: "production" })).toBe(true);
    expect(
      localeCookieSecure({
        NODE_ENV: "production",
        NEXT_PUBLIC_SITE_URL: "https://tienda.example",
      }),
    ).toBe(true);
  });

  it("no lo es en desarrollo ni cuando la URL pública es http://", () => {
    expect(localeCookieSecure({ NODE_ENV: "development" })).toBe(false);
    expect(localeCookieSecure({ NODE_ENV: "test" })).toBe(false);
    expect(
      localeCookieSecure({
        NODE_ENV: "production",
        NEXT_PUBLIC_SITE_URL: "http://localhost:3000",
      }),
    ).toBe(false);
  });
});

describe("routing.localeCookie", () => {
  it("usa la cookie por defecto de next-intl y le añade el atributo Secure calculado", () => {
    expect(routing.localeCookie).toMatchObject({
      name: "NEXT_LOCALE",
      sameSite: "lax",
      secure: localeCookieSecure(),
    });
  });
});
