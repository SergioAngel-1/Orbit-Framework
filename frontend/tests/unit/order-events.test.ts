import { describe, it, expect, beforeEach, afterEach } from "vitest";
import {
  recordAndDiffStatus,
  dispatchOrderEffects,
} from "@/lib/woocommerce/order-events";

describe("order-events sin Redis ni webhook configurado", () => {
  let redis: string | undefined;
  let hook: string | undefined;
  beforeEach(() => {
    redis = process.env.REDIS_URL;
    hook = process.env.ORDER_NOTIFICATION_WEBHOOK_URL;
    delete process.env.REDIS_URL;
    delete process.env.ORDER_NOTIFICATION_WEBHOOK_URL;
  });
  afterEach(() => {
    if (redis !== undefined) process.env.REDIS_URL = redis;
    if (hook !== undefined) process.env.ORDER_NOTIFICATION_WEBHOOK_URL = hook;
  });

  it("recordAndDiffStatus devuelve null sin almacén (no-diferencial)", async () => {
    expect(await recordAndDiffStatus(1, "processing")).toBeNull();
  });

  it("dispatchOrderEffects no lanza para transición a pagado", async () => {
    await expect(
      dispatchOrderEffects({
        event: "order.updated",
        orderId: 1,
        status: "processing",
        previousStatus: "pending",
        payload: {},
      }),
    ).resolves.toBeUndefined();
  });

  it("dispatchOrderEffects no lanza en alta de pedido", async () => {
    await expect(
      dispatchOrderEffects({
        event: "order.created",
        orderId: 2,
        status: "pending",
        previousStatus: null,
        payload: {},
      }),
    ).resolves.toBeUndefined();
  });
});
