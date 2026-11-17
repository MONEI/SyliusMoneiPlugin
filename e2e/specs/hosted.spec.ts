import { expect, test } from "@playwright/test";
import {
  PRODUCTS,
  VISA_CHALLENGE,
  addToCart,
  answerThreeDsChallenge,
  checkoutToSummary,
  expectOrderPage,
  expectThankYou,
  payAgainOnHostedPage,
  payBizumOnHostedPage,
  payCardOnHostedPage,
  placeHostedOrder,
  uniqueEmail,
} from "../utils/checkout.ts";
import { methodName } from "../utils/env.ts";
import { getPayment } from "../utils/monei.ts";
import { PAYPAL_TEST_TIMEOUT, approveInPayPal } from "../utils/paypal.ts";
import { expectSyliusPayment, paymentByMoneiId } from "../utils/sylius.ts";

test.describe("hosted payment page", () => {
  test("Bizum with the test phone ends on the thank-you page", async ({ page }) => {
    await addToCart(page, PRODUCTS.sticker);
    await checkoutToSummary(page, methodName("monei_bizum_hosted"), uniqueEmail("bizum"));
    const moneiId = await placeHostedOrder(page);

    await payBizumOnHostedPage(page);
    await expectThankYou(page);

    expect((await getPayment(moneiId)).status).toBe("SUCCEEDED");
    await expectSyliusPayment(moneiId, "completed");
  });

  test("a failed 3DS shows the failed flash and the buyer pays again with a new MONEI payment", async ({ page }) => {
    await addToCart(page, PRODUCTS.tShirt);
    await checkoutToSummary(page, methodName("monei_card_hosted"), uniqueEmail("declined"));
    const failedId = await placeHostedOrder(page);

    await payCardOnHostedPage(page, VISA_CHALLENGE);
    await answerThreeDsChallenge(page, "fail");

    await expectOrderPage(page);
    await expect(page.getByRole("alert").filter({ hasText: "Payment has failed." })).toBeVisible();
    expect((await getPayment(failedId)).status).toBe("FAILED");
    expect(paymentByMoneiId(failedId)?.state).toBe("failed");

    // MONEI's duplicate check lets a FAILED payment's order be paid again.
    const retryId = await payAgainOnHostedPage(page, methodName("monei_card_hosted"));
    expect(retryId).not.toBe(failedId);
    await payCardOnHostedPage(page, VISA_CHALLENGE);
    await answerThreeDsChallenge(page, "complete");
    await expectThankYou(page);

    expect((await getPayment(retryId)).status).toBe("SUCCEEDED");
    await expectSyliusPayment(retryId, "completed");
  });

  test("cancel on the hosted page returns to a payable order that the same MONEI payment then pays", async ({
    page,
  }) => {
    await addToCart(page, PRODUCTS.tShirt);
    await checkoutToSummary(page, methodName("monei_card_hosted"), uniqueEmail("cancel"));
    const moneiId = await placeHostedOrder(page);

    // MONEI's back link is the cancelUrl, the Sylius after-pay URL.
    await page.locator('a[href*="/order/after-pay"]').click();

    await expectOrderPage(page);
    expect((await getPayment(moneiId)).status).toBe("PENDING");
    expect(paymentByMoneiId(moneiId)?.state).toBe("new");

    // A PENDING payment of the same method and amount is reused, not replaced.
    expect(await payAgainOnHostedPage(page, methodName("monei_card_hosted"))).toBe(moneiId);
    await payCardOnHostedPage(page, VISA_CHALLENGE);
    await answerThreeDsChallenge(page, "complete");
    await expectThankYou(page);

    await expectSyliusPayment(moneiId, "completed");
  });
});

test.describe("hosted PayPal", () => {
  // The PayPal sandbox is unstable.
  test.describe.configure({ retries: process.env.CI ? 1 : 0 });

  test("PayPal with the sandbox buyer ends on the thank-you page", async ({ page }) => {
    test.setTimeout(PAYPAL_TEST_TIMEOUT);
    await addToCart(page, PRODUCTS.tShirt);
    await checkoutToSummary(page, methodName("monei_paypal_hosted"), uniqueEmail("paypal"));
    const moneiId = await placeHostedOrder(page);

    await page.waitForURL(/paypal\.com/, { timeout: 90_000 });
    await approveInPayPal(page);
    await expectThankYou(page);

    expect((await getPayment(moneiId)).status).toBe("SUCCEEDED");
    await expectSyliusPayment(moneiId, "completed");
  });
});
