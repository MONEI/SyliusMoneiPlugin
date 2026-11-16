// MONEI API reads with Node's fetch, never Playwright's `request`: a trace would record the
// Authorization header. Errors carry the status and MONEI's message, never the key.
import { moneiApiKey } from "./env.ts";

const API = "https://api.monei.com/v1";

/** Methods the specs pay with; MB WAY and Multibanco have no test processor on the e2e account. */
export const REQUIRED_METHODS = ["card", "bizum", "paypal"];

export type MoneiPayment = {
  id: string;
  status: string;
  amount: number;
  currency: string;
  orderId: string;
  traceDetails?: { userAgent?: string };
  paymentMethod?: { card?: { cardholderName?: string } };
};

async function moneiGet<T>(path: string): Promise<T> {
  const response = await fetch(`${API}${path}`, {
    headers: { Authorization: moneiApiKey(), "User-Agent": "sylius-monei-plugin-e2e" },
  });
  if (!response.ok) {
    const body = (await response.json().catch(() => ({}))) as { message?: string };
    throw new Error(`MONEI GET ${path}: ${response.status} ${body.message ?? response.statusText}`);
  }
  return (await response.json()) as T;
}

export const getPayment = (id: string) => moneiGet<MoneiPayment>(`/payments/${id}`);

/**
 * The key is a test mode key (by prefix, then by MONEI's answer), belongs to
 * MONEI_TEST_ACCOUNT_ID when that is set, and its account offers every required method.
 * Messages name neither the key nor the account.
 */
export async function checkTestAccount(): Promise<void> {
  const key = moneiApiKey();
  if (key.startsWith("pk_live_")) {
    throw new Error("MONEI_TEST_API_KEY is a live mode key. The suite pays for real, so it takes only a pk_test_ key.");
  }
  if (!key.startsWith("pk_test_")) throw new Error("MONEI_TEST_API_KEY is not a pk_test_ key.");

  const allowed = await moneiGet<{ livemode: boolean; accountId?: string; paymentMethods: string[] }>(
    "/allowed-payment-methods",
  );
  if (allowed.livemode) throw new Error("MONEI says MONEI_TEST_API_KEY is a live mode key.");

  const declared = (process.env.MONEI_TEST_ACCOUNT_ID ?? "").trim();
  if (declared && declared !== allowed.accountId) {
    throw new Error("MONEI_TEST_API_KEY belongs to another account than MONEI_TEST_ACCOUNT_ID.");
  }

  const missing = REQUIRED_METHODS.filter((method) => !allowed.paymentMethods.includes(method));
  if (missing.length) throw new Error(`The MONEI test account does not offer: ${missing.join(", ")}`);
}
