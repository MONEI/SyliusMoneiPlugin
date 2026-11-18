// MONEI API reads with Node's fetch, never Playwright's `request`: a trace would record the
// Authorization header. Errors carry the status and MONEI's message, never the key.
import { moneiApiKey } from "./env.ts";

const API = "https://api.monei.com/v1";
const GRAPHQL = "https://graphql.monei.com";

/** Methods the specs use; MB WAY and Multibanco have no test processor on the e2e account. */
export const REQUIRED_METHODS = ["card", "bizum", "paypal", "googlePay"];

type MoneiPayment = {
  status: string;
  refundedAmount?: number;
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

async function moneiPost<T>(path: string, body: object): Promise<T> {
  const response = await fetch(`${API}${path}`, {
    method: "POST",
    headers: { Authorization: moneiApiKey(), "Content-Type": "application/json", "User-Agent": "sylius-monei-plugin-e2e" },
    body: JSON.stringify(body),
  });
  if (!response.ok) {
    const error = (await response.json().catch(() => ({}))) as { message?: string };
    throw new Error(`MONEI POST ${path}: ${response.status} ${error.message ?? response.statusText}`);
  }
  return (await response.json()) as T;
}

/**
 * The account ID of the test key. MONEI returns it only on a payment, so this creates one and
 * cancels it at once. The order ID must be new: MONEI's duplicate check never expires.
 */
export async function readAccountId(orderId: string): Promise<string> {
  const payment = await moneiPost<{ id: string; accountId: string }>("/payments", {
    amount: 100,
    currency: "EUR",
    orderId,
    description: "e2e account ID read",
  });
  await moneiPost(`/payments/${payment.id}/cancel`, { cancellationReason: "requested_by_customer" });
  return payment.accountId;
}

/**
 * The amount of each refund of a MONEI payment, oldest first. The REST API has no refund list;
 * GraphQL `chargeEvents` has one event per refund, carrying that refund's amount.
 */
export async function refundAmounts(id: string): Promise<number[]> {
  const response = await fetch(GRAPHQL, {
    method: "POST",
    headers: {
      Authorization: moneiApiKey(),
      "Content-Type": "application/json",
      "User-Agent": "sylius-monei-plugin-e2e",
    },
    body: JSON.stringify({
      query:
        "query ($id: ID!) { chargeEvents(chargeId: $id, size: 100) { items { type createdAt object { lastRefundAmount } } } }",
      variables: { id },
    }),
  });
  const body = (await response.json().catch(() => ({}))) as {
    data?: { chargeEvents: { items: { type: string; createdAt: number; object: { lastRefundAmount: number } }[] } };
    errors?: { message: string }[];
  };
  if (!response.ok || !body.data) {
    throw new Error(`MONEI chargeEvents ${id}: ${response.status} ${body.errors?.[0]?.message ?? response.statusText}`);
  }
  return body.data.chargeEvents.items
    .filter((event) => event.type === "charge.refunded" || event.type === "charge.partially_refunded")
    .sort((a, b) => a.createdAt - b.createdAt)
    .map((event) => event.object.lastRefundAmount);
}

/**
 * The key is a test mode key (by prefix, then by MONEI's answer), and its account offers every
 * required method. Messages name neither the key nor the account.
 */
export async function checkTestAccount(): Promise<void> {
  const key = moneiApiKey();
  if (key.startsWith("pk_live_")) {
    throw new Error("MONEI_TEST_API_KEY is a live mode key. The suite pays for real, so it takes only a pk_test_ key.");
  }
  if (!key.startsWith("pk_test_")) throw new Error("MONEI_TEST_API_KEY is not a pk_test_ key.");

  const allowed = await moneiGet<{ livemode: boolean; paymentMethods: string[] }>("/allowed-payment-methods");
  if (allowed.livemode) throw new Error("MONEI says MONEI_TEST_API_KEY is a live mode key.");

  const missing = REQUIRED_METHODS.filter((method) => !allowed.paymentMethods.includes(method));
  if (missing.length) throw new Error(`The MONEI test account does not offer: ${missing.join(", ")}`);
}
