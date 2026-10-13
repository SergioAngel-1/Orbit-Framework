import { describe, expect, it } from "vitest";
import { loginHref, safeInternalPath, withReturn } from "./safe-return";

describe("safeInternalPath", () => {
  it("acepta una ruta interna, con su query y fragmento", () => {
    expect(safeInternalPath("/checkout")).toBe("/checkout");
    expect(safeInternalPath("/products?category=ropa#top")).toBe(
      "/products?category=ropa#top",
    );
    expect(safeInternalPath("  /account/orders/12  ")).toBe("/account/orders/12");
  });

  it("sin valor o vacío, el respaldo", () => {
    expect(safeInternalPath(null)).toBe("/account");
    expect(safeInternalPath(undefined, "/")).toBe("/");
    expect(safeInternalPath("")).toBe("/account");
  });

  it.each([
    ["una URL absoluta", "https://evil.example/x"],
    ["protocolo relativo", "//evil.example"],
    ["protocolo relativo con barra invertida", "/\\evil.example"],
    ["barra invertida", "\\\\evil.example"],
    ["esquema embebido", "/javascript:alert(1)"],
    ["una ruta sin barra inicial", "evil.example"],
    ["la API", "/api/auth/logout-all"],
    ["un esquema con datos", "data:text/html,<script>"],
    ["una URL dentro de la ruta", "/redirect?to=https://evil.example"],
  ])("rechaza %s", (_label, value) => {
    expect(safeInternalPath(value, "/seguro")).toBe("/seguro");
  });

  // Los navegadores QUITAN tabuladores y saltos de línea al interpretar una URL:
  // `/\t/evil.example` llega a ser `//evil.example` (protocolo relativo). Un
  // `trim()` solo mira los extremos y no lo ve.
  it.each([
    ["tabulador", "/\t/evil.example"],
    ["salto de línea", "/\n/evil.example"],
    ["retorno de carro", "/\r/evil.example"],
    ["carácter de control", "/\u0000/evil.example"],
  ])("rechaza un %s embebido que reconstruiría un protocolo relativo", (_l, value) => {
    expect(safeInternalPath(value, "/seguro")).toBe("/seguro");
  });
});

describe("loginHref", () => {
  it("lleva la ruta de vuelta en `return`", () => {
    expect(loginHref("/checkout")).toEqual({
      pathname: "/login",
      query: { return: "/checkout" },
    });
  });

  it("la cuenta es el destino por defecto: no hace falta llevarla en la URL", () => {
    expect(loginHref("/account")).toBe("/login");
  });

  it("también sirve para el registro", () => {
    expect(withReturn("/register", "/checkout")).toEqual({
      pathname: "/register",
      query: { return: "/checkout" },
    });
  });

  it("una ruta no segura no viaja: login a secas", () => {
    expect(loginHref("//evil.example")).toBe("/login");
    expect(loginHref(null)).toBe("/login");
  });
});
