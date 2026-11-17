import { LOCAL_STORE_URL } from "./env.ts";

// Fail the run at once, with the fix, when the stack is not up.
export default async function globalSetup() {
  const response = await fetch(`${LOCAL_STORE_URL}/en_US/`).catch(() => null);
  if (!response?.ok) throw new Error("The e2e store is not running; start it with pnpm --dir e2e e2e:start");
}
