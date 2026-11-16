import { fixtures, moneiApiKey, storeUrl } from "./env.ts";

// Fail the run at once, with the fix, when the stack is not up.
export default async function globalSetup() {
  moneiApiKey();
  fixtures();
  const response = await fetch("http://localhost:18080/en_US/").catch(() => null);
  if (!response?.ok) throw new Error("The e2e store is not running; start it with pnpm --dir e2e e2e:start");
  storeUrl();
}
