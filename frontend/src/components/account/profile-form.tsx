"use client";
import { useState } from "react";
import { csrfFetch } from "@/lib/client/csrf";

export function ProfileForm({
  initial,
}: {
  initial: { first_name: string; last_name: string };
}) {
  const [status, setStatus] = useState<"idle" | "saving" | "ok" | "error">("idle");
  const [message, setMessage] = useState<string | null>(null);

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setStatus("saving");
    setMessage(null);
    const form = new FormData(e.currentTarget);
    try {
      const res = await csrfFetch("/api/store/customer", {
        method: "PUT",
        body: {
          first_name: String(form.get("first_name") ?? ""),
          last_name: String(form.get("last_name") ?? ""),
        },
      });
      if (!res.ok) {
        const data = (await res.json().catch(() => ({}))) as { error?: string };
        throw new Error(data.error || "No se pudo guardar.");
      }
      setStatus("ok");
      setMessage("Datos guardados.");
    } catch (err) {
      setStatus("error");
      setMessage(err instanceof Error ? err.message : "Error.");
    }
  }

  return (
    <form onSubmit={onSubmit} className="max-w-md space-y-4">
      {message && (
        <p
          className={`rounded-lg border p-3 text-sm ${
            status === "ok"
              ? "border-green-200 bg-green-50 text-green-700 dark:border-green-900 dark:bg-green-950 dark:text-green-300"
              : "border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
          }`}
        >
          {message}
        </p>
      )}
      <div className="grid grid-cols-2 gap-4">
        <div>
          <label className="mb-1 block text-sm font-medium">Nombre</label>
          <input
            name="first_name"
            defaultValue={initial.first_name}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900"
          />
        </div>
        <div>
          <label className="mb-1 block text-sm font-medium">Apellidos</label>
          <input
            name="last_name"
            defaultValue={initial.last_name}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900"
          />
        </div>
      </div>
      <button
        type="submit"
        disabled={status === "saving"}
        className="rounded-lg bg-brand px-5 py-2 font-semibold text-white transition-colors hover:bg-brand-dark disabled:opacity-50"
      >
        {status === "saving" ? "Guardando…" : "Guardar"}
      </button>
    </form>
  );
}
