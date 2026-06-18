import Link from "next/link";
import { redirect } from "next/navigation";
import { getSession } from "@/lib/auth/session";

export const dynamic = "force-dynamic";

export default async function AccountLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const session = await getSession();
  if (!session) {
    redirect("/login");
  }

  return (
    <div className="grid grid-cols-1 gap-8 md:grid-cols-[200px_1fr]">
      <aside>
        <h2 className="mb-4 text-sm font-bold uppercase tracking-wide text-gray-400">
          Mi cuenta
        </h2>
        <nav className="flex flex-col gap-2 text-sm">
          <Link href="/account" className="hover:text-brand">
            Perfil
          </Link>
          <Link href="/account/orders" className="hover:text-brand">
            Pedidos
          </Link>
        </nav>
      </aside>
      <section>{children}</section>
    </div>
  );
}
