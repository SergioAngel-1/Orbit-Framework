import { NextResponse } from "next/server";
import { requireSession } from "@/lib/auth/session";
import { wpFetch } from "@/lib/wordpress/internal-fetch";

export const dynamic = "force-dynamic";

export async function GET() {
  let session;
  try {
    session = await requireSession();
  } catch {
    return NextResponse.json({ enabled: false }, { status: 200 });
  }

  try {
    const res = await wpFetch(`/wp-json/hwe/v1/auth/2fa-status/${session.userId}`, {
      cache: "no-store",
    });
    const data = (await res.json()) as { enabled: boolean };
    return NextResponse.json({ enabled: data.enabled }, { status: 200 });
  } catch {
    return NextResponse.json({ enabled: false }, { status: 200 });
  }
}
