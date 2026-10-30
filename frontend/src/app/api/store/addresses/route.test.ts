import { beforeEach, describe, expect, it, vi } from "vitest";
import { wcFetch } from "@/lib/woocommerce/client";
import { getGeoPack } from "@/lib/geo";
import { pushCartDestination } from "@/lib/woocommerce/cart-destination";
import { CO_PACK } from "../../../../../tests/helpers/geo-pack";
import type { SavedAddress } from "@/types/address";
import { DELETE, GET, POST, PUT } from "./route";

// PUT /api/store/addresses: el pin del cliente (`location`) viaja con su dirección. Se conserva
// mientras no cambien la calle ni la ciudad; si cambian, se borra y hay que volver a marcarlo.

vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/auth/session", () => ({
  requireSession: vi.fn(async () => ({ userId: "7" })),
}));
vi.mock("@/lib/security/lock", () => ({
  withLock: vi.fn(async (_key: string, fn: () => Promise<unknown>) => fn()),
}));
vi.mock("@/lib/woocommerce/client", () => ({ wcFetch: vi.fn() }));
vi.mock("@/lib/geo", () => ({ getGeoPack: vi.fn() }));
vi.mock("@/lib/woocommerce/cart-destination", () => ({
  pushCartDestination: vi.fn(async () => null),
}));
vi.mock("@/lib/woocommerce/cart-session", () => ({
  getCartRequestAuth: vi.fn(async () => ({ cartToken: null, authToken: null })),
}));
vi.mock("@/lib/woocommerce/cart-cookie", () => ({
  writeCartToken: vi.fn(async () => undefined),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), info: vi.fn(), error: vi.fn() },
}));

const PIN = { lat: 4.6533, lng: -74.0836 };

function saved(overrides: Partial<SavedAddress> = {}): SavedAddress {
  return {
    first_name: "Ana",
    last_name: "Pérez",
    company: "",
    address_1: "Calle 72 # 10-34",
    address_2: "",
    city: "Bogotá",
    state: "",
    postcode: "",
    country: "CO",
    phone: "3001234567",
    is_default: true,
    label: "Casa",
    ...overrides,
  };
}

/** Lo que WordPress tiene guardado en la libreta del cliente tras la última escritura. */
let book: SavedAddress[] = [];

function editBody(address: Record<string, unknown>) {
  return {
    index: 0,
    address: {
      first_name: "Ana",
      last_name: "Pérez",
      address_1: "Calle 72 # 10-34",
      city: "Bogotá",
      country: "CO",
      phone: "3001234567",
      is_default: true,
      label: "Casa",
      ...address,
    },
  };
}

function edit(body: unknown) {
  return PUT(
    new Request("https://tienda.example/api/store/addresses", {
      method: "PUT",
      headers: { "content-type": "application/json" },
      body: JSON.stringify(body),
    }),
  );
}

/** Lo que quedó escrito en la meta de la libreta (la última escritura de wc/v3). */
function storedBook(): SavedAddress[] {
  const writes = vi
    .mocked(wcFetch)
    .mock.calls.filter(
      ([, options]) => (options as { method?: string })?.method === "PUT",
    );
  const last = writes.at(-1)?.[1] as { body: { meta_data: { value: string }[] } };
  return JSON.parse(last.body.meta_data[0].value) as SavedAddress[];
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(getGeoPack).mockResolvedValue(CO_PACK);
  book = [saved({ location: PIN })];
  vi.mocked(wcFetch).mockImplementation((async (
    _path: string,
    options?: { method?: string },
  ) => {
    if (options?.method === "PUT") return {} as never;
    return {
      meta_data: [{ key: "hwe_addresses", value: JSON.stringify(book) }],
    } as never;
  }) as never);
});

describe("PUT /api/store/addresses — ubicación (pin) de la dirección", () => {
  it("editar el teléfono conserva el pin de la dirección", async () => {
    const res = await edit(editBody({ phone: "3109999999" }));

    expect(res.status).toBe(200);
    expect(storedBook()[0].location).toEqual(PIN);
  });

  it("cambiar la calle borra el pin: hay que volver a marcarlo", async () => {
    await edit(editBody({ address_1: "Carrera 7 # 32-16" }));

    expect(storedBook()[0]).not.toHaveProperty("location");
  });

  it("cambiar la ciudad borra el pin", async () => {
    await edit(editBody({ city: "Medellín", state: "Antioquia" }));

    expect(storedBook()[0]).not.toHaveProperty("location");
  });

  it("un pin nuevo en la edición reemplaza al guardado", async () => {
    const nuevo = { lat: 4.7, lng: -74.1 };
    await edit(editBody({ location: nuevo }));

    expect(storedBook()[0].location).toEqual(nuevo);
  });

  it("una ubicación inválida es un 422 y no escribe la libreta", async () => {
    const res = await edit(editBody({ location: { lat: 0, lng: 0 } }));

    expect(res.status).toBe(422);
    expect(wcFetch).not.toHaveBeenCalledWith(
      "/customers/7",
      expect.objectContaining({ method: "PUT" }),
    );
  });

  it("al guardar, el destino predeterminada (con su pin) se empuja al carrito", async () => {
    await edit(editBody({ phone: "3109999999" }));

    expect(pushCartDestination).toHaveBeenCalledTimes(1);
    const [, addresses] = vi.mocked(pushCartDestination).mock.calls[0];
    expect(addresses[0].location).toEqual(PIN);
  });
});

describe("identificador estable de la libreta", () => {
  const body = (over: Record<string, unknown>) => ({
    address: editBody({}).address,
    ...over,
  });

  function send(method: "POST" | "DELETE", payload: unknown) {
    const handler = method === "POST" ? POST : DELETE;
    return handler(
      new Request("https://tienda.example/api/store/addresses", {
        method,
        headers: { "content-type": "application/json" },
        body: JSON.stringify(payload),
      }),
    );
  }

  it("una libreta anterior sale con ids y la primera escritura los graba", async () => {
    book = [
      saved({ address_1: "Calle 1" }),
      saved({ address_1: "Calle 2", is_default: false }),
    ];

    const listed = (await (await GET()).json()) as SavedAddress[];
    expect(listed.every((a) => typeof a.id === "string" && a.id.length > 0)).toBe(true);

    await edit(
      body({ id: listed[1]!.id, address: editBody({ address_1: "Calle 2B" }).address }),
    );

    const written = storedBook();
    expect(written.map((a) => a.id)).toEqual(listed.map((a) => a.id));
    expect(written[1]!.address_1).toBe("Calle 2B");
  });

  // La carrera real: otra pestaña borra una dirección mientras esta edita. Con `index`,
  // el guardado caía sobre la dirección equivocada.
  it("editar por id no pisa otra dirección si la libreta cambió", async () => {
    book = [saved({ id: "b", address_1: "Calle B" })];

    const res = await edit(body({ id: "a" }));

    expect(res.status).toBe(404);
    expect(wcFetch).not.toHaveBeenCalledWith(
      expect.anything(),
      expect.objectContaining({ method: "PUT" }),
    );
  });

  it("borrar por id quita esa dirección, esté donde esté", async () => {
    book = [
      saved({ id: "a", address_1: "Calle A" }),
      saved({ id: "b", address_1: "Calle B", is_default: false }),
    ];

    const res = await send("DELETE", { id: "b" });

    expect(res.status).toBe(200);
    expect(storedBook().map((a) => a.id)).toEqual(["a"]);
  });

  it("el cliente no puede fijar el id de una edición", async () => {
    book = [saved({ id: "a" })];

    await edit(
      body({
        id: "a",
        address: { ...editBody({}).address, id: "elegido-por-el-cliente" },
      }),
    );

    expect(storedBook()[0]!.id).toBe("a");
  });

  it("una dirección nueva recibe id propio, aunque el cliente mande otro", async () => {
    book = [];

    const res = await send("POST", {
      ...editBody({}).address,
      id: "elegido-por-el-cliente",
    });

    const created = (await res.json()) as SavedAddress;
    expect(res.status).toBe(201);
    expect(created.id).toMatch(/^addr_/);
    expect(storedBook()[0]!.id).toBe(created.id);
  });

  describe("con una libreta corrupta (elementos nulos en el JSON guardado)", () => {
    const corrupt = () =>
      [
        null,
        saved({ id: "a", address_1: "Calle A" }),
        "x",
        saved({ id: "b", address_1: "Calle B", is_default: false }),
      ] as unknown as SavedAddress[];

    it("GET lista solo las direcciones válidas", async () => {
      book = corrupt();

      const listed = (await (await GET()).json()) as SavedAddress[];

      expect(listed.map((a) => a.id)).toEqual(["a", "b"]);
    });

    it("PUT por id no da 500 y la escritura limpia la libreta", async () => {
      book = corrupt();

      const res = await edit(body({ id: "b" }));

      expect(res.status).toBe(200);
      expect(storedBook().map((a) => a.id)).toEqual(["a", "b"]);
    });

    it("PUT por `index` usa la posición de la lista que GET entregó", async () => {
      book = corrupt();

      const res = await edit({ ...body({}), index: 1 });

      expect(res.status).toBe(200);
      expect(storedBook()[1]!.id).toBe("b");
    });

    it("DELETE no da 500", async () => {
      book = corrupt();

      const res = await send("DELETE", { id: "a" });

      expect(res.status).toBe(200);
      expect(storedBook().map((a) => a.id)).toEqual(["b"]);
    });
  });

  it("`index` sigue funcionando para los clientes anteriores", async () => {
    book = [saved({ id: "a", address_1: "Calle A" })];

    const res = await edit(editBody({ address_1: "Calle A2" }));

    expect(res.status).toBe(200);
    expect(storedBook()[0]).toMatchObject({ id: "a", address_1: "Calle A2" });
  });

  it("sin `id` ni `index` es un 422", async () => {
    const res = await edit(body({}));

    expect(res.status).toBe(422);
  });
});
