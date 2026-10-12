// ============================================================================
//  Redis falso en memoria para los tests unitarios.
//
//  Implementa solo los comandos que usa el código bajo prueba, con la misma
//  semántica que ioredis/Redis para ellos (SET con EX/NX, GET, DEL, GETDEL,
//  INCR, EXPIRE). Los TTL se registran pero no caducan solos: un test que quiera
//  simular la expiración borra la clave con `expireNow()`.
//
//  `down()` hace que TODO comando falle como si Redis estuviera caído.
// ============================================================================

export class FakeRedis {
  readonly store = new Map<string, string>();
  readonly ttls = new Map<string, number>();
  private failing = false;

  /** Simula una caída: cualquier comando posterior rechaza. */
  down(): this {
    this.failing = true;
    return this;
  }

  /** Simula la expiración del TTL de una clave. */
  expireNow(key: string): void {
    this.store.delete(key);
    this.ttls.delete(key);
  }

  private guard(): void {
    if (this.failing) throw new Error("Redis no disponible (simulado)");
  }

  async set(
    key: string,
    value: string,
    ...args: Array<string | number>
  ): Promise<"OK" | null> {
    this.guard();
    const flags = args.map((a) => String(a).toUpperCase());
    if (flags.includes("NX") && this.store.has(key)) return null;
    this.store.set(key, value);
    const ex = flags.indexOf("EX");
    if (ex >= 0) this.ttls.set(key, Number(args[ex + 1]));
    return "OK";
  }

  async get(key: string): Promise<string | null> {
    this.guard();
    return this.store.get(key) ?? null;
  }

  async del(...keys: string[]): Promise<number> {
    this.guard();
    let removed = 0;
    for (const key of keys) {
      if (this.store.delete(key)) removed++;
      this.ttls.delete(key);
    }
    return removed;
  }

  /** Lee y borra en un solo paso: solo UNA llamada recibe el valor. */
  async getdel(key: string): Promise<string | null> {
    this.guard();
    const value = this.store.get(key) ?? null;
    this.store.delete(key);
    this.ttls.delete(key);
    return value;
  }

  async incr(key: string): Promise<number> {
    this.guard();
    const next = Number(this.store.get(key) ?? 0) + 1;
    this.store.set(key, String(next));
    return next;
  }

  async expire(key: string, seconds: number): Promise<number> {
    this.guard();
    if (!this.store.has(key)) return 0;
    this.ttls.set(key, seconds);
    return 1;
  }
}
