// PayPal sandbox approval, from the Medusa plugin's e2e `utils/paypal.ts`.
import { expect, type Page } from "@playwright/test";

/** Public sandbox buyer from https://docs.monei.com/testing */
export const PAYPAL_BUYER = { email: "paypal-personal@monei.net", password: "monei12345" } as const;

/** Signs in to the sandbox and approves; each step matches every markup PayPal has used. */
export async function approveInPayPal(page: Page, timeout = 90_000): Promise<void> {
  const anyOf = (selectors: string[]) => page.locator(selectors.join(", ")).first();
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
    email.waitFor({ state: "visible", timeout }),
    confirm.waitFor({ state: "visible", timeout }),
  ]);

  if (await email.isVisible().catch(() => false)) {
    // The login page hydrates after it paints and wipes an early value: type, read back.
    await expect(async () => {
      await email.fill("");
      await email.pressSequentially(PAYPAL_BUYER.email, { delay: 20 });
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
    await login.waitFor({ state: "visible", timeout });
    const password = anyOf(["#password:visible", '[name="login_password"]:visible']);
    await password.fill(PAYPAL_BUYER.password);
    await expect(password).toHaveValue(PAYPAL_BUYER.password);
    await login.click();
  }

  await confirm.waitFor({ state: "visible", timeout });
  await confirm.click();
}
