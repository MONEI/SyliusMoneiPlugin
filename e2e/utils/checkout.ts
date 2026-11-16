import { expect, type Page } from "@playwright/test";

/** Seeded products (scripts/seed.php). The sticker stays below the 5 EUR Bizum test limit. */
export const PRODUCTS = { tShirt: "e2e-t-shirt", sticker: "e2e-sticker" } as const;

/** MONEI test cards. Expiry 12/34, CVC 123. */
export const CARDS = {
  /** 3DS v2.1, always challenged. */
  visaChallenge: "4444444444444406",
} as const;

/** A guest email no other test used, so one test's orders can be told apart. */
export const uniqueEmail = (tag: string) =>
  `e2e-${tag}-${Date.now()}-${Math.random().toString(36).slice(2, 6)}@monei-e2e.test`;

export async function addToCart(page: Page, slug: string): Promise<void> {
  await page.goto(`/en_US/products/${slug}`);
  await page.locator("#add-to-cart-button").click();
  await page.waitForURL(/\/en_US\/cart/);
}

/** Guest checkout up to the summary page: Spanish address, free shipping, the given method. */
export async function checkoutToSummary(page: Page, paymentMethod: string, email: string): Promise<void> {
  await page.goto("/en_US/checkout/address");
  const billing = (field: string) => page.locator(`#sylius_shop_checkout_address_billingAddress_${field}`);
  await page.locator("#sylius_shop_checkout_address_customer_email").fill(email);
  await billing("firstName").fill("Ana");
  await billing("lastName").fill("Garcia");
  await billing("street").fill("Calle Mayor 1");
  await billing("countryCode").selectOption("ES");
  await billing("city").fill("Madrid");
  await billing("postcode").fill("28001");
  await page.getByRole("button", { name: "Next" }).click();

  await page.waitForURL(/\/checkout\/select-shipping/);
  await page.getByRole("button", { name: "Next" }).click();

  await page.waitForURL(/\/checkout\/select-payment/);
  await page.getByLabel(paymentMethod, { exact: true }).check();
  await page.getByRole("button", { name: "Next" }).click();
  await page.waitForURL(/\/checkout\/complete/);
}

/** "Place order" on the summary page; the hosted mode sends the buyer to MONEI. */
export async function placeHostedOrder(page: Page): Promise<string> {
  await page.locator("#confirmation-button").click();
  await page.waitForURL(/secure\.monei\.com\/payments\//);
  return page.url().match(/secure\.monei\.com\/payments\/([A-Za-z0-9]+)/)![1];
}

/** Fills the card form of the MONEI hosted page and pays. */
export async function payCardOnHostedPage(page: Page, card: string): Promise<void> {
  const frame = page.frameLocator('iframe[title="monei_card_input"]');
  await page.getByTestId("cardholder-name-input").fill("Ana Garcia");
  for (const [testId, text] of [
    ["card-number-input", card],
    ["expiry-date-input", "1234"],
    ["cvc-input", "123"],
  ]) {
    const input = frame.getByTestId(testId);
    await expect(input).toBeEditable({ timeout: 60_000 });
    await input.click();
    // Keystrokes: MONEI formats and auto-advances on key events.
    await input.pressSequentially(text, { delay: 40 });
  }
  await expect(frame.getByTestId("expiry-date-input")).toHaveValue("12 / 34");
  await page.getByTestId("pay-button").click();
}

/** Answers MONEI's 3DS simulator. Strict: fails when no challenge shows. */
export async function answerThreeDsChallenge(page: Page, outcome: "complete" | "fail"): Promise<void> {
  const button = page.getByTestId(`${outcome}-button`);
  await expect(button, "3DS challenge is displayed").toBeVisible({ timeout: 60_000 });
  await button.click();
}

export async function expectThankYou(page: Page): Promise<void> {
  await page.waitForURL(/\/en_US\/order\/thank-you/, { timeout: 90_000 });
  await expect(page.getByText("Thank you!")).toBeVisible();
}
