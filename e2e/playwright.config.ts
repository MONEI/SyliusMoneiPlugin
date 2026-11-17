import { defineConfig, devices } from "@playwright/test";
import { storeUrl } from "./utils/env.ts";

/**
 * One store and one shared MONEI test account: a single worker, never in parallel.
 * Traces and screenshots record shop pages only: specs/admin.spec.ts turns them off. No spec
 * opens the admin payment method form, which shows the API key; MONEI gateways are seeded by
 * scripts/seed.php.
 */
export default defineConfig({
  testDir: "./specs",
  globalSetup: "./utils/global-setup.ts",
  workers: 1,
  forbidOnly: !!process.env.CI,
  // Hosted page, 3DS and the callback round trip sit inside one test.
  timeout: 180_000,
  expect: { timeout: 30_000 },
  reporter: [["list"], ["html", { open: "never" }]],
  use: {
    // The tunnel host, not localhost: Sylius builds the callback URL from the request.
    baseURL: storeUrl(),
    actionTimeout: 30_000,
    navigationTimeout: 60_000,
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },
  projects: [{ name: "chromium", use: devices["Desktop Chrome"] }],
});
