"use client";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { csrfFetch } from "@/lib/client/csrf";

export function LogoutButton() {
  const router = useRouter();
  const [pending, setPending] = useState(false);

  async function onClick() {
    setPending(true);
    try {
      await csrfFetch("/api/auth/logout", { method: "POST" });
      router.push("/");
      router.refresh();
    } finally {
      setPending(false);
    }
  }

  return (
    <button
      type="button"
      onClick={onClick}
      disabled={pending}
      className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium transition-colors hover:border-red-400 hover:text-red-600 disabled:opacity-50 dark:border-gray-700"
    >
      {pending ? "Cerrando…" : "Cerrar sesión"}
    </button>
  );
}
