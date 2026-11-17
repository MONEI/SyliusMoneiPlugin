// The plugin's onsite page: monei.js components mounted with the MONEI payment id, the confirm
// in the store's own page, then the after-pay URL whatever the confirm returns.
import { expect, test, type Page } from "@playwright/test";
import {
  CARD_PART_CONTAINERS,
  MOUNT_TIMEOUT,
  PRODUCTS,
  VISA_CHALLENGE,
  addToCart,
  answerOnsiteThreeDs,
  cardPartFrame,
  checkoutToSummary,
  expectOrderPage,
  expectThankYou,
  payBizumOnsite,
  payCardOnsite,
  placeOnsiteOrder,
  uniqueEmail,
} from "../utils/checkout.ts";
import { methodName, storeUrl } from "../utils/env.ts";
import { getPayment } from "../utils/monei.ts";
import { PAYPAL_TEST_TIMEOUT, approveInPayPal, openPayPalFromButton } from "../utils/paypal.ts";
import { expectSyliusPayment, paymentByMoneiId } from "../utils/sylius.ts";

async function toOnsitePage(page: Page, code: string, product: string, tag: string): Promise<string> {
  await addToCart(page, product);
  await checkoutToSummary(page, methodName(code), uniqueEmail(tag));
  return placeOnsiteOrder(page);
}

test.describe("onsite page", () => {
  test("card with 3DS sends the cardholder name and ends on the thank-you page", async ({ page }) => {
    const moneiId = await toOnsitePage(page, "monei_card_onsite", PRODUCTS.tShirt, "onsite-card");

    await payCardOnsite(page, VISA_CHALLENGE, "Ana Onsite");
    await answerOnsiteThreeDs(page, "complete");
    await expectThankYou(page);

    const payment = await getPayment(moneiId);
    expect(payment.status).toBe("SUCCEEDED");
    // MONEI stores it upper-cased; only the onsite page's own field can have sent it.
    expect(payment.paymentMethod?.card?.cardholderName).toBe("ANA ONSITE");
    await expectSyliusPayment(moneiId, "completed");
  });

  test("card with the single line layout ends on the thank-you page", async ({ page }) => {
    const moneiId = await toOnsitePage(page, "monei_card_onsite_single", PRODUCTS.tShirt, "onsite-card-single");

    await payCardOnsite(page, VISA_CHALLENGE, "Ana Onsite");
    await answerOnsiteThreeDs(page, "complete");
    await expectThankYou(page);

    expect((await getPayment(moneiId)).status).toBe("SUCCEEDED");
    await expectSyliusPayment(moneiId, "completed");
  });

  // The card parts are cross-origin iframes: the plugin copies the theme look of the cardholder
  // input onto their boxes and into the frames. A bare part is unbordered, 45px tall, in its own font.
  test("the separate card fields look like the cardholder input", async ({ page }) => {
    await toOnsitePage(page, "monei_card_onsite", PRODUCTS.sticker, "onsite-card-look");
    // Bootstrap animates the focus border and shadow.
    await page.addStyleTag({ content: "* { transition: none !important; }" });
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
    const focusLook = (el: Element) => {
      const style = getComputedStyle(el);
      return { borderColor: style.borderColor, boxShadow: style.boxShadow };
    };
    // The plugin script runs after the theme stylesheet; the form is in the DOM before.
    await expect(cardPartFrame(page, "number").locator("input")).toBeEditable({ timeout: MOUNT_TIMEOUT });
    const expectedBox = await cardholder.evaluate(box);
    const expectedText = await cardholder.evaluate((el) => {
      const style = getComputedStyle(el);
      return { fontFamily: style.fontFamily, fontSize: style.fontSize, color: style.color };
    });
    const family = expectedText.fontFamily.split(",")[0].replace(/["']/g, "").trim();
    await cardholder.focus();
    const expectedFocus = await cardholder.evaluate(focusLook);
    await cardholder.blur();

    for (const field of ["number", "expiry", "cvc"] as const) {
      const container = page.locator(CARD_PART_CONTAINERS[field]);
      const input = cardPartFrame(page, field).locator("input");
      await expect(input).toBeEditable();
      expect(await container.evaluate(box), `${field} box`).toEqual(expectedBox);

      const frame = await (await container.locator(`iframe[title="monei_card_${field}"]`).elementHandle())!.contentFrame();
      // check() alone passes for a family without any face; the face must have loaded in the frame.
      await expect
        .poll(
          () =>
            frame!.evaluate(
              ({ family, size }) =>
                [...document.fonts].some((face) => face.family.replace(/["']/g, "") === family && "loaded" === face.status) &&
                document.fonts.check(`${size} ${family}`),
              { family, size: expectedText.fontSize },
            ),
          { message: `${family} loaded in the ${field} frame` },
        )
        .toBe(true);
      expect(
        await input.evaluate((el) => {
          const style = getComputedStyle(el);
          return { fontFamily: style.fontFamily, fontSize: style.fontSize, color: style.color };
        }),
        `${field} text`,
      ).toEqual(expectedText);

      await input.click();
      await expect(container).toHaveClass(/monei-component--focus/);
      expect(await container.evaluate(focusLook), `${field} focus`).toEqual(expectedFocus);
    }
  });

  test("a failed 3DS leaves the page for the order page with the failed flash", async ({ page }) => {
    const moneiId = await toOnsitePage(page, "monei_card_onsite", PRODUCTS.tShirt, "onsite-declined");

    await payCardOnsite(page, VISA_CHALLENGE, "Ana Onsite");
    await answerOnsiteThreeDs(page, "fail");

    await expectOrderPage(page);
    await expect(page.getByRole("alert").filter({ hasText: "Payment has failed." })).toBeVisible();
    expect((await getPayment(moneiId)).status).toBe("FAILED");
    expect(paymentByMoneiId(moneiId)?.state).toBe("failed");
  });

  test("Bizum with the test phone ends on the thank-you page", async ({ page }) => {
    const moneiId = await toOnsitePage(page, "monei_bizum_onsite", PRODUCTS.sticker, "onsite-bizum");

    await payBizumOnsite(page);
    await expectThankYou(page);

    expect((await getPayment(moneiId)).status).toBe("SUCCEEDED");
    await expectSyliusPayment(moneiId, "completed");
  });
});

test.describe("onsite wallet", () => {
  // Google's button script is third party.
  test.describe.configure({ retries: process.env.CI ? 1 : 0 });

  // No automation can approve a wallet sheet, and the page's onSubmit takes no token from outside.
  test("Apple Pay / Google Pay mounts the payment request button without an error", async ({ page }) => {
    const storeErrors: string[] = [];
    const storeHost = new URL(storeUrl()).host;
    // Only the store's own scripts: the inline script's stack names the store page.
    page.on("pageerror", (error) => {
      if (error.stack?.includes(storeHost)) storeErrors.push(String(error));
    });
    await toOnsitePage(page, "monei_wallet_onsite", PRODUCTS.tShirt, "onsite-wallet");

    // Headless Chromium offers Google Pay only.
    await expect(
      page
        .frameLocator('#monei-component iframe[title="monei_payment_request"]')
        .getByRole("button", { name: "Google Pay" }),
      "the payment request component mounted",
    ).toBeVisible({ timeout: 60_000 });
    // onError can fire after the button shows.
    await page.waitForTimeout(3_000);
    await expect(page.locator("#monei-onsite-error")).toBeEmpty();
    expect(storeErrors).toEqual([]);
  });
});

test.describe("onsite PayPal", () => {
  // The PayPal sandbox is unstable.
  test.describe.configure({ retries: process.env.CI ? 1 : 0 });

  test("PayPal with the sandbox buyer ends on the thank-you page", async ({ page }) => {
    test.setTimeout(PAYPAL_TEST_TIMEOUT);
    const moneiId = await toOnsitePage(page, "monei_paypal_onsite", PRODUCTS.tShirt, "onsite-paypal");

    await approveInPayPal(await openPayPalFromButton(page));
    await expectThankYou(page);

    expect((await getPayment(moneiId)).status).toBe("SUCCEEDED");
    await expectSyliusPayment(moneiId, "completed");
  });

  // monei.js loads the PayPal SDK with the intent of the page's transactionType prop. A capture
  // intent on an AUTH payment would charge the buyer at once.
  test("PayPal with Authorize only loads the PayPal SDK with the authorize intent", async ({ page }) => {
    const sdk = page.waitForRequest(/paypal\.com\/sdk\/js/, { timeout: 120_000 });
    await toOnsitePage(page, "monei_paypal_auth_onsite", PRODUCTS.tShirt, "onsite-paypal-auth");

    expect(new URL((await sdk).url()).searchParams.get("intent")).toBe("authorize");
  });
});
