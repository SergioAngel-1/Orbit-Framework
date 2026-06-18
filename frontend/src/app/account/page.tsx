import type { Metadata } from "next";
import { getCustomer } from "@/lib/account/data";
import { ProfileForm } from "@/components/account/profile-form";
import { LogoutButton } from "@/components/account/logout-button";

export const metadata: Metadata = { title: "Mi perfil" };
export const dynamic = "force-dynamic";

export default async function AccountPage() {
  let customer: Awaited<ReturnType<typeof getCustomer>> | null = null;
  let error: string | null = null;

  try {
    customer = await getCustomer();
  } catch (e) {
    error =
      e instanceof Error ? e.message : "No se pudieron cargar los datos.";
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold">Perfil</h1>
        <LogoutButton />
      </div>

      {error ? (
        <div className="rounded-xl border border-amber-200 bg-amber-50 p-6 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
          <p className="font-semibold">No se pudieron cargar tus datos.</p>
          <p className="mt-1 text-sm opacity-80">{error}</p>
          <p className="mt-2 text-sm">
            Verifica que WooCommerce y las credenciales ck/cs están configuradas.
          </p>
        </div>
      ) : (
        customer && (
          <div className="space-y-6">
            <p className="text-sm text-gray-500">
              Sesión iniciada como{" "}
              <span className="font-medium text-gray-700 dark:text-gray-200">
                {customer.email}
              </span>
            </p>
            <ProfileForm
              initial={{
                first_name: customer.first_name ?? "",
                last_name: customer.last_name ?? "",
              }}
            />
          </div>
        )
      )}
    </div>
  );
}
