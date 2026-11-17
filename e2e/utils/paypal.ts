// PayPal sandbox approval, from the Medusa plugin's e2e `utils/paypal.ts`.
import { expect, type Frame, type Locator, type Page } from "@playwright/test";
import { KEYSTROKE_DELAY, MOUNT_TIMEOUT } from "./checkout.ts";

/** Public sandbox buyer from https://docs.monei.com/testing */
const PAYPAL_BUYER = { email: "paypal-personal@monei.net", password: "monei12345" } as const;

/** Per PayPal sandbox step: its pages are slow. */
const STEP_TIMEOUT = 90_000;
/** After this long without a popup or overlay, the button click is taken as ignored. */
const RECLICK_AFTER = 10_000;
const POLL_INTERVAL = 500;

/** For a whole PayPal test: the sandbox login and approval come on top of the checkout. */
export const PAYPAL_TEST_TIMEOUT = 300_000;

/** Signs in to the sandbox and approves; each step matches every markup PayPal has used. */
export async function approveInPayPal(surface: Page | Frame): Promise<void> {
  const anyOf = (selectors: string[]) => surface.locator(selectors.join(", ")).first();
  const email = anyOf(["#email:visible", '[name="login_email"]:visible']);
  const confirm = anyOf([
    '[data-testid="submit-button-initial"]',
    "#payment-submit-btn",
    '[data-testid="continue-button"]',
    "#one-time-cta",
    'button[data-atomic-wait-intent="Pay_With_PayPal"]',
  ]);

  // A remembered buyer skips the login, so whichever shows first decides the path.
  await Promise.race([
    email.waitFor({ state: "visible", timeout: STEP_TIMEOUT }),
    confirm.waitFor({ state: "visible", timeout: STEP_TIMEOUT }),
  ]);

  if (await email.isVisible().catch(() => false)) {
    // The login page hydrates after it paints and wipes an early value: type, read back.
    await expect(async () => {
      await email.fill("");
      await email.pressSequentially(PAYPAL_BUYER.email, { delay: KEYSTROKE_DELAY });
      await expect(email).toHaveValue(PAYPAL_BUYER.email, { timeout: 2_000 });
    }).toPass({ timeout: 30_000 });
    await anyOf(["#btnNext", '[name="btnNext"]', 'button[data-atomic-wait-intent="Submit_Email"]']).click();
    // The email step carries a hidden password field; wait for the real one.
    const login = anyOf([
      "#btnLogin",
      '[name="btnLogin"]',
      'button[data-atomic-wait-intent="Submit_Password"]',
      'button:text-is("Log In")',
    ]);
    await login.waitFor({ state: "visible", timeout: STEP_TIMEOUT });
    const password = anyOf(["#password:visible", '[name="login_password"]:visible']);
    await password.fill(PAYPAL_BUYER.password);
    await expect(password).toHaveValue(PAYPAL_BUYER.password);
    await login.click();
  }

  await confirm.waitFor({ state: "visible", timeout: STEP_TIMEOUT });
  await confirm.click();
}

/** The monei.js PayPal button: a cross-origin zoid frame whose generated name is the only stable handle. */
const payPalButton = (page: Page): Locator =>
  page
    .frameLocator('#monei-component iframe[name^="__zoid__paypal_buttons"]')
    .locator('[data-funding-source="paypal"], .paypal-button')
    .first();

/**
 * Clicks the monei.js PayPal button and returns the login surface: headless Chromium gets a
 * popup, a headed browser an in-page overlay frame. Only a surface on paypal.com counts (zoid
 * first opens about:blank).
 */
export async function openPayPalFromButton(page: Page): Promise<Page | Frame> {
  const button = payPalButton(page);
  await expect(button, "the PayPal button mounted").toBeVisible({ timeout: MOUNT_TIMEOUT });
  const isLogin = (url: string) => url.includes("paypal.com") && !url.includes("smart/buttons");
  const others = () => page.context().pages().filter((other) => other !== page);
  const before = new Set(others());
  await button.click();
  const deadline = Date.now() + STEP_TIMEOUT;
  let reclickAt = Date.now() + RECLICK_AFTER;
  while (Date.now() < deadline) {
    // The sandbox button sometimes ignores a click: no popup, not even about:blank.
    if (
      Date.now() > reclickAt &&
      others().every((other) => before.has(other)) &&
      (await page.locator('[id^="paypal-overlay-"]').count()) === 0
    ) {
      await button.click();
      reclickAt = Date.now() + RECLICK_AFTER;
    }
    const popup = others().find((other) => isLogin(other.url()));
    if (popup) return popup;
    const overlay = page.frames().find((frame) => isLogin(frame.url()));
    if (overlay) return overlay;
    await page.waitForTimeout(POLL_INTERVAL);
  }
  throw new Error(`PayPal did not open a login surface within ${STEP_TIMEOUT} ms`);
}
