// The plugin's onsite page: monei.js components mounted with the MONEI payment id, the confirm
// in the store's own page, then the after-pay URL whatever the confirm returns.
import { expect, test, type Page } from "@playwright/test";
import {
  PRODUCTS,
  VISA_CHALLENGE,
  addToCart,
  answerOnsiteThreeDs,
  checkoutToSummary,
  expectOrderPage,
  expectThankYou,
  payBizumOnsite,
  payCardOnsite,
  placeOnsiteOrder,
  uniqueEmail,
} from "../utils/checkout.ts";
import { methodName, storeUrl } from "../utils/env.ts";
import { getPayment } from "../utils/monei.ts";
import { approveInPayPal, openPayPalFromButton } from "../utils/paypal.ts";
import { expectSyliusPayment, paymentByMoneiId } from "../utils/sylius.ts";

async function toOnsitePage(page: Page, code: string, product: string, tag: string): Promise<string> {
  await addToCart(page, product);
  await checkoutToSummary(page, methodName(code), uniqueEmail(tag));
  return placeOnsiteOrder(page);
}

test.describe("onsite page", () => {
  test("card with 3DS sends the cardholder name and ends on the thank-you page", async ({ page }) => {
    const moneiId = await toOnsitePage(page, "monei_card_onsite", PRODUCTS.tShirt, "onsite-card");

    await payCardOnsite(page, VISA_CHALLENGE, "Ana Onsite");
    await answerOnsiteThreeDs(page, "complete");
    await expectThankYou(page);

    const payment = await getPayment(moneiId);
    expect(payment.status).toBe("SUCCEEDED");
    // MONEI stores it upper-cased; only the onsite page's own field can have sent it.
    expect(payment.paymentMethod?.card?.cardholderName).toBe("ANA ONSITE");
    await expectSyliusPayment(moneiId, "completed");
  });

  test("a failed 3DS leaves the page for the order page with the failed flash", async ({ page }) => {
    const moneiId = await toOnsitePage(page, "monei_card_onsite", PRODUCTS.tShirt, "onsite-declined");

    await payCardOnsite(page, VISA_CHALLENGE, "Ana Onsite");
    await answerOnsiteThreeDs(page, "fail");

    await expectOrderPage(page);
    await expect(page.getByRole("alert").filter({ hasText: "Payment has failed." })).toBeVisible();
    expect((await getPayment(moneiId)).status).toBe("FAILED");
    expect(paymentByMoneiId(moneiId)?.state).toBe("failed");
  });

  test("Bizum with the test phone ends on the thank-you page", async ({ page }) => {
    const moneiId = await toOnsitePage(page, "monei_bizum_onsite", PRODUCTS.sticker, "onsite-bizum");

    await payBizumOnsite(page);
    await expectThankYou(page);

    expect((await getPayment(moneiId)).status).toBe("SUCCEEDED");
    await expectSyliusPayment(moneiId, "completed");
  });
});

test.describe("onsite wallet", () => {
  // Google's button script is third party.
  test.describe.configure({ retries: process.env.CI ? 1 : 0 });

  // No automation can approve a wallet sheet, and the page's onSubmit takes no token from outside.
  test("Apple Pay / Google Pay mounts the payment request button without an error", async ({ page }) => {
    const storeErrors: string[] = [];
    const storeHost = new URL(storeUrl()).host;
    // Only the store's own scripts: the inline script's stack names the store page.
    page.on("pageerror", (error) => {
      if (error.stack?.includes(storeHost)) storeErrors.push(String(error));
    });
    await toOnsitePage(page, "monei_wallet_onsite", PRODUCTS.tShirt, "onsite-wallet");

    // Headless Chromium offers Google Pay only.
    await expect(
      page
        .frameLocator('#monei-component iframe[title="monei_payment_request"]')
        .getByRole("button", { name: "Google Pay" }),
      "the payment request component mounted",
    ).toBeVisible({ timeout: 60_000 });
    // onError can fire after the button shows.
    await page.waitForTimeout(3_000);
    await expect(page.locator("#monei-onsite-error")).toBeEmpty();
    expect(storeErrors).toEqual([]);
  });
});

test.describe("onsite PayPal", () => {
  // The PayPal sandbox is unstable.
  test.describe.configure({ retries: process.env.CI ? 1 : 0 });

  test("PayPal with the sandbox buyer ends on the thank-you page", async ({ page }) => {
    test.setTimeout(300_000);
    const moneiId = await toOnsitePage(page, "monei_paypal_onsite", PRODUCTS.tShirt, "onsite-paypal");

    await approveInPayPal(await openPayPalFromButton(page));
    await expectThankYou(page);

    expect((await getPayment(moneiId)).status).toBe("SUCCEEDED");
    await expectSyliusPayment(moneiId, "completed");
  });

  // monei.js loads the PayPal SDK with the intent of the page's transactionType prop. A capture
  // intent on an AUTH payment would charge the buyer at once.
  test("PayPal with Authorize only loads the PayPal SDK with the authorize intent", async ({ page }) => {
    const sdk = page.waitForRequest(/paypal\.com\/sdk\/js/, { timeout: 120_000 });
    await toOnsitePage(page, "monei_paypal_auth_onsite", PRODUCTS.tShirt, "onsite-paypal-auth");

    expect(new URL((await sdk).url()).searchParams.get("intent")).toBe("authorize");
  });
});
