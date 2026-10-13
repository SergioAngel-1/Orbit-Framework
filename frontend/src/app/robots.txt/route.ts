import { robotsResponse } from "@/app/_robots/build";

// /robots.txt: ver `_robots/build.ts`.
export const runtime = "nodejs";
export const revalidate = 3600;

export async function GET(): Promise<Response> {
  return robotsResponse();
}
