import { expect, test, type Browser } from "@playwright/test";
import { adminPage } from "../utils/admin.ts";
import { LOCAL_STORE_URL, storeUrl } from "../utils/env.ts";
import { query } from "../utils/sylius.ts";

const settings = "/admin/monei";
const start = `${settings}/connect/start`;
const disconnect = `${settings}/connect/disconnect`;
const callback = "/monei/connect";

test.use({ trace: "off", screenshot: "off", video: "off" });

async function expectRefusedPost(browser: Browser, path: string, expectUnchanged: () => void) {
  const anonymous = await fetch(`${LOCAL_STORE_URL}${path}`, { method: "POST", redirect: "manual" });
  expect(anonymous.status).toBe(302);
  expect(anonymous.headers.get("location") === `${LOCAL_STORE_URL}/admin/login`, "redirects to admin login").toBe(true);
  expectUnchanged();

  const admin = await adminPage(browser);
  try {
    const missing = await admin.request.post(path, { maxRedirects: 0 });
    expect(missing.status()).toBe(403);
    expectUnchanged();

    const wrong = await admin.request.post(path, { form: { _token: "wrong-token" }, maxRedirects: 0 });
    expect(wrong.status()).toBe(403);
    expectUnchanged();
  } finally {
    await admin.context().close();
  }
}

test("anonymous and invalid-CSRF start POSTs cannot create pending Connect state", async ({ browser }) => {
  const stateCount = () => query("SELECT COUNT(*) FROM monei_connect_state")[0][0];
  const before = stateCount();
  await expectRefusedPost(browser, start, () => expect(stateCount()).toBe(before));
});

test("anonymous and invalid-CSRF Disconnect POSTs cannot change the connection", async ({ browser }) => {
  const connectionDigest = () => query(
    "SELECT SHA2(JSON_ARRAY(id, generation, last_result, live_account_id, live_api_key, live_signing_secret, " +
      "test_account_id, test_api_key, test_signing_secret), 256) FROM monei_connection ORDER BY id",
  );
  // Even an empty connection must keep its generation. Disconnect advances it.
  query("INSERT INTO monei_connection (id, generation) VALUES (1, 0)");
  try {
    const before = connectionDigest();
    await expectRefusedPost(browser, disconnect, () => expect(connectionDigest()).toEqual(before));
  } finally {
    query("DELETE FROM monei_connection WHERE id = 1");
  }
});

test("the public callback rejects forged state without consuming the pending request", async () => {
  const state = "s".repeat(43);
  const pending = () => query(
    `SELECT SHA2(JSON_ARRAY(state, verifier, redirect_uri, generation, created_at), 256) ` +
      `FROM monei_connect_state WHERE state = '${state}'`,
  );
  query(
    "INSERT INTO monei_connect_state (state, verifier, redirect_uri, generation, created_at) " +
      `VALUES ('${state}', '${"v".repeat(64)}', 'https://shop.example${callback}', 0, UTC_TIMESTAMP())`,
  );
  try {
    const before = pending();
    const params = new URLSearchParams({ code: "forged-code", state: "f".repeat(43) });
    const response = await fetch(`${storeUrl()}${callback}?${params}`, { redirect: "manual" })
      .catch(() => { throw new Error("could not reach the public Connect callback"); });
    expect(response.status).toBe(400);
    expect(response.headers.get("referrer-policy")).toBe("no-referrer");
    expect(
      (await response.text()).includes("The connection request is invalid or was already used."),
      "renders the plugin’s invalid-state error",
    ).toBe(true);
    expect(pending()).toEqual(before);
  } finally {
    query(`DELETE FROM monei_connect_state WHERE state = '${state}'`);
  }
});

test("start from the HTTP admin sends the broker an HTTPS tunnel callback and S256", async ({ browser }) => {
  const admin = await adminPage(browser);
  try {
    const page = await admin.goto(settings);
    expect(page?.status()).toBe(200);
    const token = await admin.locator(`form[action="${start}"] input[name="_token"]`).inputValue();
    const response = await admin.request.post(start, { form: { _token: token }, maxRedirects: 0 });
    expect(response.status()).toBe(303);

    const location = URL.parse(response.headers().location ?? "");
    if (!location) throw new Error("start did not return an absolute broker URL");
    // Compare in place so failure output cannot reveal the callback URL.
    expect(
      location.origin + location.pathname === "https://oauth.monei.com/integrations/authorize",
      "redirects to the broker authorization endpoint",
    ).toBe(true);
    expect(location.searchParams.get("client_id") === "sylius", "uses the Sylius client").toBe(true);
    expect(location.searchParams.get("code_challenge_method") === "S256", "uses PKCE S256").toBe(true);
    expect(
      location.searchParams.get("redirect_uri") === `${storeUrl()}${callback}`,
      "the broker receives the HTTPS tunnel callback",
    ).toBe(true);
  } finally {
    // The disposable store starts with no pending requests.
    query("DELETE FROM monei_connect_state");
    query("DELETE FROM monei_connection WHERE id = 1");
    await admin.context().close();
  }
});
