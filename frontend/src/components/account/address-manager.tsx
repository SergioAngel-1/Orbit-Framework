"use client";
import { useState, useEffect, useCallback } from "react";
import { useTranslations } from "next-intl";
import { csrfFetch } from "@/lib/client/csrf";
import type { SavedAddress } from "@/types/address";

const emptyAddress: SavedAddress = {
  first_name: "", last_name: "", company: "",
  address_1: "", address_2: "", city: "", state: "",
  postcode: "", country: "ES", phone: "", is_default: false, label: "",
};

const COUNTRY_OPTIONS = [
  { code: "ES", name: "España" },
  { code: "CO", name: "Colombia" },
  { code: "MX", name: "México" },
  { code: "AR", name: "Argentina" },
  { code: "CL", name: "Chile" },
  { code: "PE", name: "Perú" },
  { code: "US", name: "United States" },
];

export function AddressManager() {
  const t = useTranslations("addresses");
  const tForm = useTranslations("form");
  const [addresses, setAddresses] = useState<SavedAddress[]>([]);
  const [editing, setEditing] = useState<number | "new" | null>(null);
  const [form, setForm] = useState<SavedAddress>({ ...emptyAddress });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  const fetchAddresses = useCallback(async () => {
    try {
      const res = await fetch("/api/store/addresses", { credentials: "same-origin" });
      if (res.ok) {
        setAddresses(await res.json() as SavedAddress[]);
      }
    } catch { /* ignore */ } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchAddresses(); }, [fetchAddresses]);

  function startNew() {
    setEditing("new");
    setForm({ ...emptyAddress });
    setError(null);
    setMessage(null);
  }

  function startEdit(index: number) {
    setEditing(index);
    setForm({ ...addresses[index] });
    setError(null);
    setMessage(null);
  }

  function cancelEdit() {
    setEditing(null);
    setForm({ ...emptyAddress });
    setError(null);
  }

  async function save() {
    setSaving(true);
    setError(null);
    setMessage(null);
    try {
      if (editing === "new") {
        const res = await csrfFetch("/api/store/addresses", { method: "POST", body: form });
        if (!res.ok) throw new Error((await res.json()).error || t("saveError"));
        setMessage(t("saved"));
        await fetchAddresses();
        setEditing(null);
      } else if (typeof editing === "number") {
        const res = await csrfFetch("/api/store/addresses", {
          method: "PUT",
          body: { index: editing, address: form },
        });
        if (!res.ok) throw new Error((await res.json()).error || t("saveError"));
        setMessage(t("saved"));
        await fetchAddresses();
        setEditing(null);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : t("saveError"));
    } finally {
      setSaving(false);
    }
  }

  async function remove(index: number) {
    if (!confirm(t("deleteConfirm"))) return;
    try {
      const res = await csrfFetch("/api/store/addresses", { method: "DELETE", body: { index } });
      if (!res.ok) throw new Error((await res.json()).error || t("deleteError"));
      await fetchAddresses();
    } catch (err) {
      setError(err instanceof Error ? err.message : t("deleteError"));
    }
  }

  function updateField(field: keyof SavedAddress, value: string | boolean) {
    setForm((prev) => ({ ...prev, [field]: value }));
  }

  if (loading) {
    return <p className="text-sm text-gray-500">{t("loading")}</p>;
  }

  return (
    <div className="space-y-6">
      {error && (
        <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
          {error}
        </p>
      )}
      {message && (
        <p className="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-700 dark:border-green-900 dark:bg-green-950 dark:text-green-300">
          {message}
        </p>
      )}

      {addresses.length === 0 && editing === null && (
        <p className="text-sm text-gray-500">{t("empty")}</p>
      )}

      {addresses.map((addr, i) => (
        <div key={i} className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
          <div className="flex items-start justify-between">
            <div>
              <p className="font-medium">
                {addr.label && <span className="text-xs uppercase tracking-wide text-gray-400">{addr.label}</span>}
                {addr.is_default && (
                  <span className="ml-2 rounded bg-brand-light px-2 py-0.5 text-xs text-white">{t("default")}</span>
                )}
              </p>
              <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">
                {addr.first_name} {addr.last_name}<br />
                {addr.address_1}{addr.address_2 && <>, {addr.address_2}</>}<br />
                {addr.city}, {addr.state} {addr.postcode}<br />
                {addr.country}{addr.phone && <> &middot; {addr.phone}</>}
              </p>
            </div>
            <div className="flex gap-2">
              <button onClick={() => startEdit(i)} className="text-sm text-brand hover:underline">{t("edit")}</button>
              <button onClick={() => remove(i)} className="text-sm text-red-500 hover:underline">{t("delete")}</button>
            </div>
          </div>
        </div>
      ))}

      {editing === null && (
        <button onClick={startNew} className="rounded-lg border border-dashed border-gray-300 px-4 py-2 text-sm text-gray-500 hover:border-brand hover:text-brand dark:border-gray-600">
          + {t("addNew")}
        </button>
      )}

      {editing !== null && (
        <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
          <h3 className="mb-4 font-semibold">
            {editing === "new" ? t("addNew") : t("editAddress")}
          </h3>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="mb-1 block text-sm font-medium">{tForm("firstName")}</label>
              <input value={form.first_name} onChange={(e) => updateField("first_name", e.target.value)}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900" />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium">{tForm("lastName")}</label>
              <input value={form.last_name} onChange={(e) => updateField("last_name", e.target.value)}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900" />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium">{t("label")}</label>
              <input value={form.label} onChange={(e) => updateField("label", e.target.value)} placeholder={t("labelPlaceholder")}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900" />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium">{tForm("phone")}</label>
              <input value={form.phone} onChange={(e) => updateField("phone", e.target.value)}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900" />
            </div>
            <div className="col-span-2">
              <label className="mb-1 block text-sm font-medium">{tForm("address")}</label>
              <input value={form.address_1} onChange={(e) => updateField("address_1", e.target.value)}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900" />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium">{tForm("city")}</label>
              <input value={form.city} onChange={(e) => updateField("city", e.target.value)}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900" />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium">{tForm("postcode")}</label>
              <input value={form.postcode} onChange={(e) => updateField("postcode", e.target.value)}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900" />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium">{t("state")}</label>
              <input value={form.state} onChange={(e) => updateField("state", e.target.value)}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900" />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium">{tForm("country")}</label>
              <select value={form.country} onChange={(e) => updateField("country", e.target.value)}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900">
                {COUNTRY_OPTIONS.map((c) => (
                  <option key={c.code} value={c.code}>{c.name}</option>
                ))}
              </select>
            </div>
          </div>
          <label className="mt-4 flex items-center gap-2 text-sm">
            <input type="checkbox" checked={form.is_default} onChange={(e) => updateField("is_default", e.target.checked)}
              className="rounded border-gray-300 dark:border-gray-700" />
            {t("setDefault")}
          </label>
          <div className="mt-4 flex gap-3">
            <button onClick={save} disabled={saving}
              className="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark disabled:opacity-50">
              {saving ? t("saving") : t("save")}
            </button>
            <button onClick={cancelEdit}
              className="rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-gray-700">
              {t("cancel")}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
