import { NextResponse } from "next/server";
import { requireSession } from "@/lib/auth/session";

export const dynamic = "force-dynamic";

const WP_INTERNAL =
  process.env.WORDPRESS_INTERNAL_API_URL?.replace("/graphql", "") ??
  "http://wordpress:80";

export async function GET() {
  let session;
  try {
    session = await requireSession();
  } catch {
    return NextResponse.json({ enabled: false }, { status: 200 });
  }

  try {
    const res = await fetch(
      `${WP_INTERNAL}/wp-json/hwe/v1/auth/2fa-status/${session.userId}`,
      {
        cache: "no-store",
        headers: { "X-HWE-Internal-Secret": process.env.HWE_REVALIDATION_SECRET ?? "" },
      },
    );
    const data = (await res.json()) as { enabled: boolean };
    return NextResponse.json({ enabled: data.enabled }, { status: 200 });
  } catch {
    return NextResponse.json({ enabled: false }, { status: 200 });
  }
}
