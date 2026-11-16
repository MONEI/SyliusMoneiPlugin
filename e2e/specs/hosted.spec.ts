import { expect, test } from "@playwright/test";
import {
  CARDS,
  PRODUCTS,
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
import { fixtures } from "../utils/env.ts";
import { getPayment } from "../utils/monei.ts";
import { approveInPayPal } from "../utils/paypal.ts";
import { paymentByMoneiId } from "../utils/sylius.ts";

const method = (code: string) => fixtures().paymentMethods[code].name;

const expectCompleted = (moneiId: string) =>
  expect
    .poll(() => paymentByMoneiId(moneiId)?.state, { timeout: 90_000 })
    .toBe("completed");

test.describe("hosted payment page", () => {
  test("Bizum with the test phone ends on the thank-you page", async ({ page }) => {
    await addToCart(page, PRODUCTS.sticker);
    await checkoutToSummary(page, method("monei_bizum_hosted"), uniqueEmail("bizum"));
    const moneiId = await placeHostedOrder(page);

    await payBizumOnHostedPage(page);
    await expectThankYou(page);

    expect((await getPayment(moneiId)).status).toBe("SUCCEEDED");
    await expectCompleted(moneiId);
  });

  test("a failed 3DS shows the failed flash and the buyer pays again with a new MONEI payment", async ({ page }) => {
    await addToCart(page, PRODUCTS.tShirt);
    await checkoutToSummary(page, method("monei_card_hosted"), uniqueEmail("declined"));
    const failedId = await placeHostedOrder(page);

    await payCardOnHostedPage(page, CARDS.visaChallenge);
    await answerThreeDsChallenge(page, "fail");

    await expectOrderPage(page);
    await expect(page.getByRole("alert").filter({ hasText: "Payment has failed." })).toBeVisible();
    expect((await getPayment(failedId)).status).toBe("FAILED");
    expect(paymentByMoneiId(failedId)?.state).toBe("failed");

    // MONEI's duplicate check lets a FAILED payment's order be paid again.
    const retryId = await payAgainOnHostedPage(page, method("monei_card_hosted"));
    expect(retryId).not.toBe(failedId);
    await payCardOnHostedPage(page, CARDS.visaChallenge);
    await answerThreeDsChallenge(page, "complete");
    await expectThankYou(page);

    expect((await getPayment(retryId)).status).toBe("SUCCEEDED");
    await expectCompleted(retryId);
  });

  test("cancel on the hosted page returns to a payable order that the same MONEI payment then pays", async ({
    page,
  }) => {
    await addToCart(page, PRODUCTS.tShirt);
    await checkoutToSummary(page, method("monei_card_hosted"), uniqueEmail("cancel"));
    const moneiId = await placeHostedOrder(page);

    // MONEI's back link is the cancelUrl, the Sylius after-pay URL.
    await page.locator('a[href*="/order/after-pay"]').click();

    await expectOrderPage(page);
    expect((await getPayment(moneiId)).status).toBe("PENDING");
    expect(paymentByMoneiId(moneiId)?.state).toBe("new");

    // A PENDING payment of the same method and amount is reused, not replaced.
    expect(await payAgainOnHostedPage(page, method("monei_card_hosted"))).toBe(moneiId);
    await payCardOnHostedPage(page, CARDS.visaChallenge);
    await answerThreeDsChallenge(page, "complete");
    await expectThankYou(page);

    await expectCompleted(moneiId);
  });
});

test.describe("hosted PayPal", () => {
  // The PayPal sandbox is unstable.
  test.describe.configure({ retries: process.env.CI ? 1 : 0 });

  test("PayPal with the sandbox buyer ends on the thank-you page", async ({ page }) => {
    test.setTimeout(300_000);
    await addToCart(page, PRODUCTS.tShirt);
    await checkoutToSummary(page, method("monei_paypal_hosted"), uniqueEmail("paypal"));
    const moneiId = await placeHostedOrder(page);

    await page.waitForURL(/paypal\.com/, { timeout: 90_000 });
    await approveInPayPal(page);
    await expectThankYou(page);

    expect((await getPayment(moneiId)).status).toBe("SUCCEEDED");
    await expectCompleted(moneiId);
  });
});
