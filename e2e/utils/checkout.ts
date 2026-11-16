import { expect, type FrameLocator, type Page } from "@playwright/test";

/** Seeded products (scripts/seed.php). The sticker stays below the 5 EUR Bizum test limit. */
export const PRODUCTS = { tShirt: "e2e-t-shirt", sticker: "e2e-sticker" } as const;

/** MONEI test cards. Expiry 12/34, CVC 123. */
export const CARDS = {
  /** 3DS v2.1, always challenged. */
  visaChallenge: "4444444444444406",
} as const;

/** Bizum test phone: approved below 5 EUR. */
export const BIZUM_PHONE = "+34500000000";

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

/**
 * Runs the action that sends the buyer to the MONEI hosted page and returns the MONEI payment
 * id. Read from the request, not the final URL: a single-method PayPal page leaves MONEI at once.
 */
async function toHostedPage(page: Page, action: () => Promise<void>): Promise<string> {
  const hosted = page.waitForRequest(/secure\.monei\.com\/payments\/[A-Za-z0-9]+/);
  await action();
  return (await hosted).url().match(/secure\.monei\.com\/payments\/([A-Za-z0-9]+)/)![1];
}

/** "Place order" on the summary page; the hosted mode sends the buyer to MONEI. */
export const placeHostedOrder = (page: Page): Promise<string> =>
  toHostedPage(page, () => page.locator("#confirmation-button").click());

/** The order page Sylius sends the buyer back to after an unpaid payment. */
export async function expectOrderPage(page: Page): Promise<void> {
  await page.waitForURL(/\/en_US\/order\/(?!thank-you|after-pay)[^/?#]+$/, { timeout: 90_000 });
}

/** Pays again from the order page. */
export async function payAgainOnHostedPage(page: Page, paymentMethod: string): Promise<string> {
  await expectOrderPage(page);
  await page.getByLabel(paymentMethod, { exact: true }).check();
  return toHostedPage(page, () => page.getByRole("button", { name: "Pay", exact: true }).click());
}

/** Types a test card into a MONEI card input frame (hosted page or monei.js CardInput). */
async function fillCardFrame(frame: FrameLocator, card: string): Promise<void> {
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
}

/** Fills the card form of the MONEI hosted page and pays. */
export async function payCardOnHostedPage(page: Page, card: string): Promise<void> {
  await page.getByTestId("cardholder-name-input").fill("Ana Garcia");
  await fillCardFrame(page.frameLocator('iframe[title="monei_card_input"]'), card);
  await page.getByTestId("pay-button").click();
}

/** "Place order" on the summary page; the onsite mode renders the plugin's monei.js page. Returns the MONEI payment id. */
export async function placeOnsiteOrder(page: Page): Promise<string> {
  await page.locator("#confirmation-button").click();
  const form = page.locator("#monei-onsite");
  await expect(form).toBeAttached({ timeout: 60_000 });
  return (await form.getAttribute("data-payment-id"))!;
}

/** Fills the plugin's onsite card form (cardholder name and monei.js CardInput) and pays. */
export async function payCardOnsite(page: Page, card: string, cardholderName: string): Promise<void> {
  await page.locator("#monei-cardholder-name").fill(cardholderName);
  await fillCardFrame(page.frameLocator('#monei-card-input iframe[title="monei_card_input"]'), card);
  await page.locator("#monei-onsite").getByRole("button", { name: "Pay" }).click();
}

/** Bizum on the hosted page: phone, then pay; the test processor approves on its own. */
export async function payBizumOnHostedPage(page: Page): Promise<void> {
  await page.getByTestId("bizum-phone-input").fill(BIZUM_PHONE);
  await page.getByTestId("bizum-pay-button").click();
}

/**
 * The monei.js Bizum button, then phone and pay in its modal. The modal's script resets an
 * early value: type and read back (from the Medusa plugin's e2e).
 */
export async function payBizumOnsite(page: Page): Promise<void> {
  const button = page.frameLocator('#monei-component iframe[title="monei_bizum_button"]').getByTestId("bizum-button");
  await expect(button, "the Bizum component mounted").toBeVisible({ timeout: 60_000 });
  await button.click();
  const modal = page.frameLocator('iframe[title="monei_bizum"]');
  const phone = modal.getByTestId("bizum-phone-input");
  const digits = (value: string) => value.replace(/\D/g, "");
  await expect(async () => {
    await phone.fill("");
    await phone.pressSequentially(BIZUM_PHONE, { delay: 30 });
    expect(digits(await phone.inputValue())).toBe(digits(BIZUM_PHONE));
  }).toPass({ timeout: 30_000 });
  await modal.getByTestId("bizum-pay-button").click();
}

/** Answers MONEI's 3DS simulator. Strict: fails when no challenge shows. */
export async function answerThreeDsChallenge(page: Page, outcome: "complete" | "fail"): Promise<void> {
  const button = page.getByTestId(`${outcome}-button`);
  await expect(button, "3DS challenge is displayed").toBeVisible({ timeout: 60_000 });
  await button.click();
}

/**
 * Answers the 3DS challenge that monei.js shows in its payment modal, two cross-origin frames
 * down, where synthetic clicks do not arrive: focus and Enter (from the Medusa plugin's e2e).
 * Strict: fails when no challenge shows.
 */
export async function answerOnsiteThreeDs(page: Page, outcome: "complete" | "fail"): Promise<void> {
  const button = page
    .frameLocator('iframe[title="monei_payment_modal"]')
    .frameLocator("iframe")
    .getByTestId(`${outcome}-button`);
  await expect(button, "3DS challenge is displayed").toBeVisible({ timeout: 60_000 });
  await button.focus();
  await page.keyboard.press("Enter");
}

export async function expectThankYou(page: Page): Promise<void> {
  await page.waitForURL(/\/en_US\/order\/thank-you/, { timeout: 90_000 });
  await expect(page.getByText("Thank you!")).toBeVisible();
}
