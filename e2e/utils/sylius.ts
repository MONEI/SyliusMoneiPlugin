import { execFileSync } from "node:child_process";
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

/** The Sylius payment bound to a MONEI payment, and the states of its notify (callback) requests. */
export function paymentByMoneiId(moneiId: string): { state: string; notifyRequests: string[] } | null {
  if (!/^[A-Za-z0-9]+$/.test(moneiId)) throw new Error(`not a MONEI payment id: ${moneiId}`);
  const rows = query(
    `SELECT p.state, COALESCE(GROUP_CONCAT(r.state ORDER BY r.created_at), '') FROM sylius_payment p ` +
      `LEFT JOIN sylius_payment_request r ON r.payment_id = p.id AND r.action = 'notify' ` +
      `WHERE JSON_UNQUOTE(JSON_EXTRACT(p.details, '$.monei.id')) = '${moneiId}' GROUP BY p.id`,
  );
  if (rows.length === 0) return null;
  const [state, notify] = rows[0];
  return { state, notifyRequests: notify ? notify.split(",") : [] };
}
