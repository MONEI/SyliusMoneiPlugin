// The method logos on the Payment step. The admin list logo is in admin.spec.ts.
import { expect, test } from "@playwright/test";
import { PRODUCTS, addToCart, checkoutToPayment, uniqueEmail } from "../utils/checkout.ts";
import { methodName } from "../utils/env.ts";

test("the Payment step logo loads and the radio keeps the method name as its label", async ({ page }) => {
  const logo = page.waitForResponse(/\/bundles\/moneisyliusplugin\/images\/bizum\.svg/);
  await addToCart(page, PRODUCTS.sticker);
  await checkoutToPayment(page, uniqueEmail("logos"));

  expect((await logo).status()).toBe(200);
  const radio = page.getByLabel(methodName("monei_bizum_inline"), { exact: true });
  await expect(radio).toBeVisible();
  const img = page.locator("label", { has: radio }).locator('img[src*="bizum.svg"]').first();
  await expect.poll(() => img.evaluate((el: HTMLImageElement) => el.naturalWidth)).toBeGreaterThan(0);
});
