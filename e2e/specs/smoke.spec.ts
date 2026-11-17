import { expect, test } from "@playwright/test";
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
import { methodName } from "../utils/env.ts";
import { getPayment } from "../utils/monei.ts";
import { paymentByMoneiId } from "../utils/sylius.ts";

test("a hosted card payment with 3DS ends on the thank-you page and MONEI's signed callback is accepted", async ({
  page,
}) => {
  await addToCart(page, PRODUCTS.tShirt);
  await checkoutToSummary(page, methodName("monei_card_hosted"), uniqueEmail("smoke"));
  const moneiId = await placeHostedOrder(page);

  await payCardOnHostedPage(page, VISA_CHALLENGE);
  await answerThreeDsChallenge(page, "complete");
  await expectThankYou(page);

  // The return page alone also completes the payment: a completed notify request proves that
  // MONEI's signed callback reached the store through the tunnel and was accepted.
  await expect
    .poll(() => paymentByMoneiId(moneiId), { timeout: 90_000, message: "the callback was accepted" })
    .toEqual({ state: "completed", notifyRequests: ["completed"] });

  expect((await getPayment(moneiId)).traceDetails?.userAgent).toMatch(/^MONEI\/Sylius\//);
});
