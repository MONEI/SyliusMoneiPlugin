import { expect, test } from "@playwright/test";
import {
  PRODUCTS,
  VISA_CHALLENGE,
  addToCart,
  answerThreeDsChallenge,
  checkoutToSummary,
  payCardOnHostedPage,
  placeHostedOrder,
  uniqueEmail,
} from "../utils/checkout.ts";
import { methodName } from "../utils/env.ts";
import { getPayment } from "../utils/monei.ts";
import { paymentByMoneiId, statusRequestCount } from "../utils/sylius.ts";

// The return page syncs the status too, so it would hide a dead tunnel or a rejected signature.
test("with the return page blocked, the callback alone completes the payment", async ({ page }) => {
  let blocked = false;
  await page.route(/\/order\/after-pay/, async (route) => {
    blocked = true;
    await route.abort();
  });

  await addToCart(page, PRODUCTS.tShirt);
  await checkoutToSummary(page, methodName("monei_card_hosted"), uniqueEmail("callback"));
  const moneiId = await placeHostedOrder(page);

  await payCardOnHostedPage(page, VISA_CHALLENGE);
  await answerThreeDsChallenge(page, "complete");
  await expect.poll(() => blocked, { message: "MONEI sent the buyer to the blocked return page" }).toBe(true);
  expect((await getPayment(moneiId)).status).toBe("SUCCEEDED");

  await expect
    .poll(() => paymentByMoneiId(moneiId), { timeout: 90_000, message: "the callback completed the payment" })
    .toEqual({ state: "completed", notifyRequests: ["completed"] });
  expect(statusRequestCount(moneiId), "no return page request reached the store").toBe(0);
});
