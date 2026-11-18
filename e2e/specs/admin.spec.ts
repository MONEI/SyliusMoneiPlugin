import { expect, test as base, type Page } from "@playwright/test";
import { adminPage } from "../utils/admin.ts";
import {
  PRODUCTS,
  VISA_CHALLENGE,
  addToCart,
  answerThreeDsChallenge,
  checkoutToSummary,
  expectThankYou,
  payCardOnHostedPage,
  placeHostedOrder,
  uniqueEmail,
} from "../utils/checkout.ts";
import { fixtures, methodName } from "../utils/env.ts";
import { getPayment, refundAmounts } from "../utils/monei.ts";
import { expectSyliusPayment, orderByMoneiId, recordedRefunds } from "../utils/sylius.ts";

const test = base.extend<{ admin: Page }>({
  admin: async ({ browser }, use) => {
    const admin = await adminPage(browser);
    await use(admin);
    await admin.context().close();
  },
});

// Admin pages: no trace or screenshot. No spec opens the payment method form or the payment
// requests list.
test.use({ trace: "off", screenshot: "off" });

/** A hosted card payment with 3DS of `units` T-shirts (18.00 EUR each); returns the MONEI payment id. */
async function payByCard(page: Page, methodCode: string, tag: string, units = 1): Promise<string> {
  for (let unit = 0; unit < units; unit++) await addToCart(page, PRODUCTS.tShirt);
  await checkoutToSummary(page, methodName(methodCode), uniqueEmail(tag));
  const moneiId = await placeHostedOrder(page);
  await payCardOnHostedPage(page, VISA_CHALLENGE);
  await answerThreeDsChallenge(page, "complete");
  await expectThankYou(page);
  return moneiId;
}

test.describe("admin", () => {
  test("Complete on an authorized payment captures it at MONEI and the order is paid", async ({ page, admin }) => {
    const moneiId = await payByCard(page, "monei_card_auth", "complete");
    expect((await getPayment(moneiId)).status).toBe("AUTHORIZED");
    // Admin actions must start from the state the callback set.
    await expectSyliusPayment(moneiId, "authorized");
    const { orderId, paymentId } = orderByMoneiId(moneiId)!;

    await admin.goto(`/admin/orders/${orderId}`);
    await admin.locator(`form[action$="/payments/${paymentId}/complete"] button[type="submit"]`).click();

    await expectSyliusPayment(moneiId, "completed");
    expect((await getPayment(moneiId)).status).toBe("SUCCEEDED");
    expect(orderByMoneiId(moneiId)?.paymentState).toBe("paid");
  });

  test("cancelling an order with an authorized payment voids the hold at MONEI", async ({ page, admin }) => {
    const moneiId = await payByCard(page, "monei_card_auth", "void");
    await expectSyliusPayment(moneiId, "authorized");
    const { orderId } = orderByMoneiId(moneiId)!;

    await admin.goto(`/admin/orders/${orderId}`);
    const cancel = admin.locator(`form[action$="/admin/orders/${orderId}/cancel"] button[type="submit"]`);
    // The cancel button sits in the header's Actions dropdown.
    await admin.locator(".dropdown", { has: cancel }).locator(".dropdown-toggle").click();
    await cancel.click();

    await expectSyliusPayment(moneiId, "cancelled");
    expect((await getPayment(moneiId)).status).toBe("CANCELED");
    expect(orderByMoneiId(moneiId)?.state).toBe("cancelled");
  });

  test("two refund plugin refunds of one unit each refund the payment at MONEI in two refunds", async ({
    page,
    admin,
  }) => {
    const moneiId = await payByCard(page, "monei_card_hosted", "refund", 2);
    await expectSyliusPayment(moneiId, "completed");
    const { number } = orderByMoneiId(moneiId)!;

    const refundOneUnit = async (refundsSoFar: number) => {
      await admin.goto(`/admin/orders/${number}/refunds`);
      await admin.locator("tr.unit input[data-refund-input]:enabled").first().fill("18.00");
      await admin.locator("#payment-methods").selectOption({ label: methodName("monei_card_hosted") });
      await admin.locator('button[form="refund-form"]').click();
      await expect
        .poll(() => recordedRefunds(number).length, { timeout: 60_000 })
        .toBe(refundsSoFar)
        .catch(async (error: Error) => {
          // A MONEI refusal shows as an admin flash.
          const alerts = await admin.locator(".alert-danger").allInnerTexts();
          throw alerts.length ? new Error(`${error.message}\nadmin alert: ${alerts.join(" | ")}`) : error;
        });
    };

    await refundOneUnit(1);
    const firstRefundAt = Date.now();
    const partial = await getPayment(moneiId);
    expect([partial.status, partial.refundedAmount]).toEqual(["PARTIALLY_REFUNDED", 1800]);

    // MONEI answers an equal refund of the same payment sent less than 3 s later with the first
    // refund's answer and refunds nothing; the plugin then refuses it.
    await admin.waitForTimeout(Math.max(0, firstRefundAt + 4_000 - Date.now()));
    await refundOneUnit(2);
    const full = await getPayment(moneiId);
    expect([full.status, full.refundedAmount]).toEqual(["REFUNDED", 3600]);
    expect(recordedRefunds(number)).toEqual([1800, 1800]);

    // MONEI's own refund list: exactly the two refunds, which sum to the order total.
    await expect.poll(() => refundAmounts(moneiId), { timeout: 60_000 }).toEqual([1800, 1800]);
  });

  test("the admin payment method list shows the MONEI logo on Sylius 2.3", async ({ admin }) => {
    const [major, minor] = fixtures().syliusVersion.split(".").map(Number);
    await admin.goto("/admin/payment-methods/?limit=50");
    const monei = admin.locator('img[src*="/bundles/moneisyliusplugin/images/monei.svg"]');
    if (major === 2 && minor < 3) {
      // Sylius 2.2 has no logo column; the plugin leaves its list alone.
      await expect(monei).toHaveCount(0);
      return;
    }
    await expect(monei.first()).toBeVisible();
    await expect.poll(() => monei.first().evaluate((el: HTMLImageElement) => el.naturalWidth)).toBeGreaterThan(0);
  });
});
