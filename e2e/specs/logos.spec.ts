// The method logos on the Payment step. The admin list logo is in admin.spec.ts.
import { expect, test } from "@playwright/test";
import { PRODUCTS, addToCart, checkoutToPayment, uniqueEmail } from "../utils/checkout.ts";
import { methodName } from "../utils/env.ts";

test("the Payment step logo loads on the right of the method name row, and the radio keeps the method name as its label", async ({
  page,
}) => {
  const logo = page.waitForResponse(/\/bundles\/moneisyliusplugin\/images\/bizum\.svg/);
  await addToCart(page, PRODUCTS.sticker);
  await checkoutToPayment(page, uniqueEmail("logos"));

  expect((await logo).status()).toBe(200);
  const radio = page.getByLabel(methodName("monei_bizum_inline"), { exact: true });
  await expect(radio).toBeVisible();
  const card = page.locator("label", { has: radio });
  const img = card.locator('img[src*="bizum.svg"]').first();
  await expect.poll(() => img.evaluate((el: HTMLImageElement) => el.naturalWidth)).toBeGreaterThan(0);

  // Desktop width: one row, the name on the left and the logos at the right edge of the card.
  const name = page.locator(`label[for="${await radio.getAttribute("id")}"]`);
  const [nameBox, imgBox, cardBox] = await Promise.all([name.boundingBox(), img.boundingBox(), card.boundingBox()]);
  if (!nameBox || !imgBox || !cardBox) throw new Error("the method name, logo or card has no layout box");
  expect(imgBox.y).toBeLessThan(nameBox.y + nameBox.height);
  expect(imgBox.y + imgBox.height).toBeGreaterThan(nameBox.y);
  expect(imgBox.x).toBeGreaterThan(nameBox.x + nameBox.width);
  expect(cardBox.x + cardBox.width - (imgBox.x + imgBox.width)).toBeLessThan(40);
});
