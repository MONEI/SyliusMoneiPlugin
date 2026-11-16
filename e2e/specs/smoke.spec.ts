import { expect, test } from "@playwright/test";
import {
  CARDS,
  PRODUCTS,
  addToCart,
  answerThreeDsChallenge,
  checkoutToSummary,
  expectThankYou,
  payCardOnHostedPage,
  placeHostedOrder,
  uniqueEmail,
} from "../utils/checkout.ts";
import { fixtures } from "../utils/env.ts";
import { getPayment } from "../utils/monei.ts";
import { paymentByMoneiId } from "../utils/sylius.ts";

test("a hosted card payment with 3DS ends on the thank-you page and the callback completes it", async ({ page }) => {
  await addToCart(page, PRODUCTS.tShirt);
  await checkoutToSummary(page, fixtures().paymentMethods.monei_card_hosted.name, uniqueEmail("smoke"));
  const moneiId = await placeHostedOrder(page);

  await payCardOnHostedPage(page, CARDS.visaChallenge);
  await answerThreeDsChallenge(page, "complete");
  await expectThankYou(page);

  // The return page alone also completes the payment: a completed notify request proves that
  // MONEI's signed callback reached the store through the tunnel and was accepted.
  await expect
    .poll(() => paymentByMoneiId(moneiId), { timeout: 90_000, message: "the callback completed the payment" })
    .toEqual({ state: "completed", notifyRequests: ["completed"] });

  expect((await getPayment(moneiId)).traceDetails?.userAgent).toMatch(/^MONEI\/Sylius\//);
});
