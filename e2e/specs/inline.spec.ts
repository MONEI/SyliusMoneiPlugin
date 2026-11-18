// Inline payment: the monei.js component on the checkout Complete page makes a token, Place order
// places the order, and the pay page confirms with the token behind "Processing payment…".
import { expect, test, type Page } from "@playwright/test";
import {
  CARD_PART_CONTAINERS,
  MOUNT_TIMEOUT,
  PRODUCTS,
  VISA_CHALLENGE,
  addToCart,
  answerOnsiteThreeDs,
  cardPartFrame,
  checkoutToPayment,
  checkoutToSummary,
  expectOrderPage,
  expectThankYou,
  fillInlineCard,
  payBizumOnsite,
  payCardOnsite,
  uniqueEmail,
} from "../utils/checkout.ts";
import { methodName } from "../utils/env.ts";
import { getPayment } from "../utils/monei.ts";
import { PAYPAL_TEST_TIMEOUT, approveInPayPal, openPayPalFromButton } from "../utils/paypal.ts";
import { expectSyliusPayment, moneiIdByEmail, paymentByMoneiId, placedOrderCount } from "../utils/sylius.ts";

// The Complete page HTML holds the account ID, and the failure artifacts of the public repo's CI
// are public. Locators target the children of #monei-inline, never the element itself: a failed
// action prints the element it resolved to.
test.use({ trace: "off", screenshot: "off" });

const placeOrder = (page: Page) => page.locator("#confirmation-button");
const inlineError = (page: Page) => page.locator("#monei-inline-error");
const processing = (page: Page) => page.locator("#monei-onsite-processing");

async function toComplete(page: Page, code: string, product: string, tag: string): Promise<string> {
  const email = uniqueEmail(tag);
  await addToCart(page, product);
  await checkoutToSummary(page, methodName(code), email);
  return email;
}

type Entry = Record<string, unknown>;

/**
 * Before any script of the pay page: replace the entry the Complete page stored with
 * `transform(entry)`. The transform runs in the page as source text, so it may use only its argument.
 */
async function editPayPageEntry(page: Page, transform: (entry: Entry) => Entry): Promise<void> {
  const edit = (change: (entry: Entry) => Entry) => {
    if (window.location.pathname.includes("/checkout/")) return;
    for (const key of Object.keys(window.sessionStorage)) {
      if (!key.startsWith("monei.inline.")) continue;
      window.sessionStorage.setItem(key, JSON.stringify(change(JSON.parse(window.sessionStorage.getItem(key)!))));
    }
  };
  await page.addInitScript(`(${edit})(${transform})`);
}

/** Counts monei.js confirm calls: only a confirm can pay with the entry's token. */
function countConfirms(page: Page): () => number {
  let confirms = 0;
  page.on("request", (request) => {
    if (/\/client-confirm/.test(request.url())) confirms++;
  });
  return () => confirms;
}

/** The pay page shows its card fields as in 2.1, without the inline confirm. */
async function expectPayPageFields(page: Page): Promise<void> {
  await expect(page.locator("#monei-onsite")).toBeVisible({ timeout: MOUNT_TIMEOUT });
  await expect(processing(page)).toBeHidden();
  await expect(cardPartFrame(page, "number").getByTestId("card-number-input")).toBeEditable({
    timeout: MOUNT_TIMEOUT,
  });
}

/** The MONEI payment of the guest's order; the inline flow never shows its id in the page. */
function moneiId(email: string): string {
  const id = moneiIdByEmail(email);
  if (!id) throw new Error("the order has no MONEI payment");
  return id;
}

test.describe("inline card", () => {
  test("3DS card shows Processing payment, then the challenge, and MONEI keeps the cardholder name", async ({
    page,
  }) => {
    const email = await toComplete(page, "monei_card_inline", PRODUCTS.tShirt, "inline-card");

    await fillInlineCard(page, VISA_CHALLENGE, "Ana Inline");
    const placed = page.waitForRequest(
      (request) => "POST" === request.method() && /\/checkout\/complete/.test(request.url()),
    );
    await placeOrder(page).click();
    // The token and the cardholder name stay in the browser: Place order posts the stock form only.
    const fields = [...new URLSearchParams((await placed).postData() ?? "").keys()];
    expect(fields).toContain("sylius_checkout_complete[notes]");
    expect(fields.filter((field) => "_method" !== field && !field.startsWith("sylius_checkout_complete["))).toEqual([]);
    await expect(processing(page)).toBeVisible({ timeout: MOUNT_TIMEOUT });
    await expect(page.locator("#monei-onsite")).toBeHidden();
    await answerOnsiteThreeDs(page, "complete");
    await expectThankYou(page);

    const id = moneiId(email);
    const payment = await getPayment(id);
    expect(payment.status).toBe("SUCCEEDED");
    // The name went only from the Complete page, through sessionStorage, to the pay page's confirm.
    expect(payment.paymentMethod?.card?.cardholderName).toBe("ANA INLINE");
    await expectSyliusPayment(id, "completed");
  });

  // The e2e account challenged 4444444444444414 and 4444444444444422 too (checked on this layout).
  test("card on the single line layout ends on the thank-you page", async ({ page }) => {
    const email = await toComplete(page, "monei_card_inline_single", PRODUCTS.tShirt, "inline-card-single");

    await fillInlineCard(page, VISA_CHALLENGE, "Ana Inline", "single");
    await placeOrder(page).click();
    await answerOnsiteThreeDs(page, "complete");
    await expectThankYou(page);

    const id = moneiId(email);
    expect((await getPayment(id)).status).toBe("SUCCEEDED");
    await expectSyliusPayment(id, "completed");
  });

  test("an empty cardholder name shows the required error and places no order", async ({ page }) => {
    const email = await toComplete(page, "monei_card_inline", PRODUCTS.tShirt, "inline-no-name");

    await fillInlineCard(page, VISA_CHALLENGE, "");
    await placeOrder(page).click();

    await expect(inlineError(page)).toHaveText("Enter the name on card.");
    await expect(page).toHaveURL(/\/checkout\/complete/);
    expect(placedOrderCount(email)).toBe(0);
  });

  test("empty card fields show the monei.js error and Place order stays enabled", async ({ page }) => {
    const email = await toComplete(page, "monei_card_inline", PRODUCTS.tShirt, "inline-no-card");

    await expect(cardPartFrame(page, "number").getByTestId("card-number-input")).toBeEditable({
      timeout: MOUNT_TIMEOUT,
    });
    await page.locator("#monei-cardholder-name").fill("Ana Inline");
    await placeOrder(page).click();

    // Disabled while monei.js tokenizes; the error comes first, then the button is back.
    await expect(inlineError(page)).not.toBeEmpty();
    await expect(placeOrder(page)).toBeEnabled();
    await expect(page).toHaveURL(/\/checkout\/complete/);
    expect(placedOrderCount(email)).toBe(0);
  });

  test("a failed 3DS ends on the order page, and paying again there shows the pay page fields", async ({ page }) => {
    const email = await toComplete(page, "monei_card_inline", PRODUCTS.tShirt, "inline-declined");

    await fillInlineCard(page, VISA_CHALLENGE, "Ana Inline");
    await placeOrder(page).click();
    await answerOnsiteThreeDs(page, "fail");

    await expectOrderPage(page);
    await expect(page.getByRole("alert").filter({ hasText: "Payment has failed." })).toBeVisible();
    const failedId = moneiId(email);
    expect((await getPayment(failedId)).status).toBe("FAILED");
    expect(paymentByMoneiId(failedId)?.state).toBe("failed");

    // The token was used once: the retry has no entry and shows the pay page as in 2.1.
    await page.getByLabel(methodName("monei_card_inline"), { exact: true }).check();
    await page.getByRole("button", { name: "Pay", exact: true }).click();
    await expect(page.locator("#monei-onsite")).toBeVisible({ timeout: MOUNT_TIMEOUT });
    await expect(processing(page)).toBeHidden();
    await expect(cardPartFrame(page, "number").getByTestId("card-number-input")).toBeEditable({
      timeout: MOUNT_TIMEOUT,
    });
  });

  test("a token MONEI refuses shows the pay page fields with the error, and the buyer pays there", async ({
    page,
  }) => {
    // A well-formed unknown token.
    await editPayPageEntry(page, (entry) => ({
      ...entry,
      token: [...String(entry.token)].map((char) => (/\d/.test(char) ? "0" : "a")).join(""),
    }));
    const email = await toComplete(page, "monei_card_inline", PRODUCTS.tShirt, "inline-refused");

    await fillInlineCard(page, VISA_CHALLENGE, "Ana Inline");
    await placeOrder(page).click();

    // Only a confirm with the entry's token can put MONEI's refusal on the pay page.
    await expect(page.locator("#monei-onsite-error")).toHaveText("Invalid payment method", { timeout: MOUNT_TIMEOUT });
    await expect(processing(page)).toBeHidden();
    // The fields report a change without an error once they load; the refusal must stay.
    await expect(cardPartFrame(page, "number").getByTestId("card-number-input")).toBeEditable({
      timeout: MOUNT_TIMEOUT,
    });
    await page.waitForTimeout(1_000);
    expect(await page.locator("#monei-onsite-error").textContent()).toBe("Invalid payment method");
    const id = moneiId(email);
    expect((await getPayment(id)).status).toBe("PENDING");

    await payCardOnsite(page, VISA_CHALLENGE, "Ana Inline");
    await answerOnsiteThreeDs(page, "complete");
    await expectThankYou(page);
    expect((await getPayment(id)).status).toBe("SUCCEEDED");
    await expectSyliusPayment(id, "completed");
  });

  test("with monei.js blocked, Place order still places the order and the pay page shows", async ({ page }) => {
    await page.route(/js\.monei\.com/, (route) => route.abort());
    await toComplete(page, "monei_card_inline", PRODUCTS.sticker, "inline-blocked");
    // The inline component rendered; only its script is missing, so its fields hide.
    await expect(page.locator(CARD_PART_CONTAINERS.number)).toBeAttached();
    await expect(page.locator("#monei-cardholder-name")).toBeHidden();

    await placeOrder(page).click();
    await expect(page.locator("#monei-onsite")).toBeAttached({ timeout: MOUNT_TIMEOUT });
    await expect(page).not.toHaveURL(/\/checkout\/complete/);
  });

  test("with a monei.js that defines no monei, the fields hide and Place order places the order", async ({ page }) => {
    await page.route(/js\.monei\.com\/v3\/monei\.js/, (route) =>
      route.fulfill({ contentType: "text/javascript", body: "" }),
    );
    await toComplete(page, "monei_card_inline", PRODUCTS.sticker, "inline-empty-script");
    await expect(page.locator("#monei-cardholder-name")).toBeHidden();

    await placeOrder(page).click();
    await expect(page.locator("#monei-onsite")).toBeAttached({ timeout: MOUNT_TIMEOUT });
    await expect(page).not.toHaveURL(/\/checkout\/complete/);
  });

  test("with js.monei.com hanging, Place order shows and places the order", async ({ page }) => {
    // Never answered on the Complete page; the pay page gets the script.
    let placed = false;
    await page.route(/js\.monei\.com/, (route) => (placed ? route.continue() : undefined));
    page.on("request", (request) => {
      if ("POST" === request.method() && /\/checkout\/complete/.test(request.url())) placed = true;
    });
    await addToCart(page, PRODUCTS.sticker);
    await checkoutToPayment(page, uniqueEmail("inline-hanging"));
    await page.getByLabel(methodName("monei_card_inline"), { exact: true }).check();
    await page.getByRole("button", { name: "Next" }).click();
    // The pending script holds back the load event, not the page.
    await page.waitForURL(/\/checkout\/complete/, { waitUntil: "domcontentloaded" });

    await placeOrder(page).click();
    await expect(page.locator("#monei-onsite")).toBeAttached({ timeout: MOUNT_TIMEOUT });
    await expect(page).not.toHaveURL(/\/checkout\/complete/);
  });

  test("a failed token request places the order without a token and the pay page shows its fields", async ({
    page,
  }) => {
    const confirms = countConfirms(page);
    await toComplete(page, "monei_card_inline", PRODUCTS.sticker, "inline-token-failed");

    await fillInlineCard(page, VISA_CHALLENGE, "Ana Inline");
    // Only now: with request interception from the page load on, typed card fields were sometimes lost.
    await page.route(/\/v1\/tokens/, (route) => route.abort());
    await placeOrder(page).click();

    await expectPayPageFields(page);
    expect(confirms()).toBe(0);
  });

  for (const [name, transform] of [
    ["another payment method", (entry: Entry) => ({ ...entry, methodCode: "monei_card_onsite" })],
    ["an expired token", (entry: Entry) => ({ ...entry, expiresAt: 0 })],
  ] as const) {
    test(`the pay page does not confirm with the entry of ${name}`, async ({ page }) => {
      await editPayPageEntry(page, transform);
      const confirms = countConfirms(page);
      await toComplete(page, "monei_card_inline", PRODUCTS.sticker, "inline-entry-refused");

      await fillInlineCard(page, VISA_CHALLENGE, "Ana Inline");
      await placeOrder(page).click();

      await expectPayPageFields(page);
      await expect(page.locator("#monei-onsite-error")).toBeEmpty();
      expect(confirms()).toBe(0);
    });
  }

  test("with monei.js blocked on the pay page only, the pay page shows its fields", async ({ page }) => {
    await toComplete(page, "monei_card_inline", PRODUCTS.sticker, "inline-pay-blocked");

    await fillInlineCard(page, VISA_CHALLENGE, "Ana Inline");
    // After the fill, as for the token request above; from the Place order post on, monei.js is blocked.
    let placed = false;
    page.on("request", (request) => {
      if ("POST" === request.method() && /\/checkout\/complete/.test(request.url())) placed = true;
    });
    await page.route(/js\.monei\.com/, (route) => (placed ? route.abort() : route.continue()));
    await placeOrder(page).click();

    await expect(page.locator("#monei-onsite")).toBeVisible({ timeout: MOUNT_TIMEOUT });
    await expect(processing(page)).toBeHidden();
  });

  test("a confirm that fails for another reason goes to the after-pay URL", async ({ page }) => {
    await toComplete(page, "monei_card_inline", PRODUCTS.sticker, "inline-confirm-failed");

    await fillInlineCard(page, VISA_CHALLENGE, "Ana Inline");
    // After the fill, as for the token request above.
    await page.route(/\/client-confirm/, (route) => route.abort());
    const afterPay = page.waitForRequest(/\/order\/after-pay\//, { timeout: MOUNT_TIMEOUT });
    await placeOrder(page).click();

    await afterPay;
  });

  test("a card number as the cardholder name shows the pay page fields with monei.js's error", async ({ page }) => {
    await editPayPageEntry(page, (entry) => ({ ...entry, cardholderName: "4444 4444 4444 4406" }));
    const confirms = countConfirms(page);
    await toComplete(page, "monei_card_inline", PRODUCTS.sticker, "inline-pan-name");

    await fillInlineCard(page, VISA_CHALLENGE, "Ana Inline");
    await placeOrder(page).click();

    await expect(page.locator("#monei-onsite-error")).toHaveText(/^Card numbers are not allowed in /, {
      timeout: MOUNT_TIMEOUT,
    });
    await expectPayPageFields(page);
    expect(confirms()).toBe(0);
  });

  // The card parts are cross-origin iframes: they get the theme look of the cardholder input, as
  // on the pay page (onsite.spec.ts).
  test("the card fields look like the cardholder input", async ({ page }) => {
    await toComplete(page, "monei_card_inline", PRODUCTS.sticker, "inline-card-look");
    const cardholder = page.locator("#monei-cardholder-name");
    const box = (el: Element) => {
      const style = getComputedStyle(el);
      return {
        height: style.height,
        borderWidth: style.borderWidth,
        borderStyle: style.borderStyle,
        borderColor: style.borderColor,
        borderRadius: style.borderRadius,
      };
    };
    const text = (el: Element) => {
      const style = getComputedStyle(el);
      return { fontFamily: style.fontFamily, fontSize: style.fontSize, color: style.color };
    };
    await expect(cardPartFrame(page, "number").locator("input")).toBeEditable({ timeout: MOUNT_TIMEOUT });
    const expectedBox = await cardholder.evaluate(box);
    const expectedText = await cardholder.evaluate(text);

    for (const field of ["number", "expiry", "cvc"] as const) {
      const input = cardPartFrame(page, field).locator("input");
      await expect(input).toBeEditable();
      expect(await page.locator(CARD_PART_CONTAINERS[field]).evaluate(box), `${field} box`).toEqual(expectedBox);
      expect(await input.evaluate(text), `${field} text`).toEqual(expectedText);
    }
  });
});

test.describe("inline Bizum", () => {
  test("the Bizum button takes the place and the height of Place order", async ({ page }) => {
    await toComplete(page, "monei_bizum_inline", PRODUCTS.sticker, "inline-bizum-look");
    const frame = page.locator('#monei-component iframe[title="monei_bizum_button"]');
    await expect(frame).toBeVisible({ timeout: MOUNT_TIMEOUT });
    await expect(placeOrder(page)).toBeHidden();

    // Place order is hidden: show it for one measure.
    const placeOrderHeight = await placeOrder(page).evaluate((button: HTMLElement) => {
      button.hidden = false;
      const height = button.getBoundingClientRect().height;
      button.hidden = true;
      return Math.round(height);
    });
    const buttonHeight = await frame.evaluate((el) => Math.round(el.getBoundingClientRect().height));
    expect(buttonHeight).toBe(placeOrderHeight);
  });

  test("Bizum with the test phone ends on the thank-you page", async ({ page }) => {
    const email = await toComplete(page, "monei_bizum_inline", PRODUCTS.sticker, "inline-bizum");

    await payBizumOnsite(page);
    await expectThankYou(page);

    const id = moneiId(email);
    expect((await getPayment(id)).status).toBe("SUCCEEDED");
    await expectSyliusPayment(id, "completed");
  });
});

test.describe("inline wallet", () => {
  // Google's button script is third party.
  test.describe.configure({ retries: process.env.CI ? 1 : 0 });

  // No automation can approve a wallet sheet. Headless Chromium offers Google Pay.
  test("Apple Pay / Google Pay renders its button in place of Place order", async ({ page }) => {
    await toComplete(page, "monei_wallet_inline", PRODUCTS.tShirt, "inline-wallet");

    await expect(
      page
        .frameLocator('#monei-component iframe[title="monei_payment_request"]')
        .getByRole("button", { name: "Google Pay" }),
      "the payment request component mounted",
    ).toBeVisible({ timeout: MOUNT_TIMEOUT });
    await expect(placeOrder(page)).toBeHidden();
    await expect(inlineError(page)).toBeEmpty();
  });
});

test.describe("inline PayPal", () => {
  // The PayPal sandbox is unstable.
  test.describe.configure({ retries: process.env.CI ? 1 : 0 });

  test("PayPal with the sandbox buyer ends on the thank-you page", async ({ page }) => {
    test.setTimeout(PAYPAL_TEST_TIMEOUT);
    const email = await toComplete(page, "monei_paypal_inline", PRODUCTS.tShirt, "inline-paypal");

    await approveInPayPal(await openPayPalFromButton(page));
    await expectThankYou(page);

    const id = moneiId(email);
    expect((await getPayment(id)).status).toBe("SUCCEEDED");
    await expectSyliusPayment(id, "completed");
  });

  test("PayPal with Authorize only ends AUTHORIZED at MONEI and authorized in Sylius", async ({ page }) => {
    test.setTimeout(PAYPAL_TEST_TIMEOUT);
    const email = await toComplete(page, "monei_paypal_auth_inline", PRODUCTS.tShirt, "inline-paypal-auth");

    await approveInPayPal(await openPayPalFromButton(page));
    await expectThankYou(page);

    const id = moneiId(email);
    expect((await getPayment(id)).status).toBe("AUTHORIZED");
    await expectSyliusPayment(id, "authorized");
  });
});
