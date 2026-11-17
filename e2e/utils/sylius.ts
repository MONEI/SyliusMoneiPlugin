import { execFileSync } from "node:child_process";
import { expect } from "@playwright/test";
import { COMPOSE_FILE } from "./env.ts";

/** Rows of a query on the store database, through the e2e MySQL container. */
function query(sql: string): string[][] {
  const output = execFileSync(
    "docker",
    ["compose", "-f", COMPOSE_FILE, "exec", "-T", "-e", "MYSQL_PWD=root", "mysql",
      "mysql", "-uroot", "-N", "-B", "sylius_monei_prod", "-e", sql],
    { encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] },
  );
  return output.split("\n").filter(Boolean).map((line) => line.split("\t"));
}

/** The WHERE condition on `sylius_payment p` for the payment bound to a MONEI payment. */
function boundTo(moneiId: string): string {
  if (!/^[A-Za-z0-9]+$/.test(moneiId)) throw new Error(`not a MONEI payment id: ${moneiId}`);
  return `JSON_UNQUOTE(JSON_EXTRACT(p.details, '$.monei.id')) = '${moneiId}'`;
}

/** The Sylius payment bound to a MONEI payment, and the states of its notify (callback) requests. */
export function paymentByMoneiId(moneiId: string): { state: string; notifyRequests: string[] } | null {
  const rows = query(
    `SELECT p.state, COALESCE(GROUP_CONCAT(r.state ORDER BY r.created_at), '') FROM sylius_payment p ` +
      `LEFT JOIN sylius_payment_request r ON r.payment_id = p.id AND r.action = 'notify' ` +
      `WHERE ${boundTo(moneiId)} GROUP BY p.id`,
  );
  if (rows.length === 0) return null;
  const [state, notify] = rows[0];
  return { state, notifyRequests: notify ? notify.split(",") : [] };
}

/** Status (return page) requests of the Sylius payment bound to a MONEI payment. */
export function statusRequestCount(moneiId: string): number {
  const rows = query(
    `SELECT COUNT(*) FROM sylius_payment p ` +
      `JOIN sylius_payment_request r ON r.payment_id = p.id AND r.action = 'status' ` +
      `WHERE ${boundTo(moneiId)}`,
  );
  return Number(rows[0][0]);
}

type OrderState = {
  orderId: string;
  number: string;
  paymentId: string;
  state: string;
  paymentState: string;
  payment: string;
};

/** The order and the Sylius payment bound to a MONEI payment, with their states. */
export function orderByMoneiId(moneiId: string): OrderState | null {
  const rows = query(
    `SELECT o.id, o.number, p.id, o.state, o.payment_state, p.state FROM sylius_payment p ` +
      `JOIN sylius_order o ON o.id = p.order_id ` +
      `WHERE ${boundTo(moneiId)}`,
  );
  if (rows.length === 0) return null;
  const [orderId, number, paymentId, state, paymentState, payment] = rows[0];
  return { orderId, number, paymentId, state, paymentState, payment };
}

/** Polls the state of the Sylius payment bound to a MONEI payment: the callback sets it a moment later. */
export const expectSyliusPayment = (moneiId: string, state: string) =>
  expect.poll(() => orderByMoneiId(moneiId)?.payment, { timeout: 90_000 }).toBe(state);

/** Amounts of the refund plugin's refunds of an order, by order number. */
export function recordedRefunds(orderNumber: string): number[] {
  if (!/^[A-Za-z0-9-]+$/.test(orderNumber)) throw new Error(`not an order number: ${orderNumber}`);
  return query(
    `SELECT r.amount FROM sylius_refund_refund r JOIN sylius_order o ON o.id = r.order_id ` +
      `WHERE o.number = '${orderNumber}' ORDER BY r.id`,
  ).map(([amount]) => Number(amount));
}
