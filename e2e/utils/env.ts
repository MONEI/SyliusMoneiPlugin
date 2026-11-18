import { existsSync, readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const E2E_DIR = resolve(dirname(fileURLToPath(import.meta.url)), "..");
export const COMPOSE_FILE = resolve(E2E_DIR, "compose.yaml");
const GENERATED_DIR = resolve(E2E_DIR, ".generated");
const STORE_URL_FILE = resolve(GENERATED_DIR, "store-url");
const FIXTURES_FILE = resolve(GENERATED_DIR, "fixtures.json");
const ADMIN_PASSWORD_FILE = resolve(GENERATED_DIR, "admin-password");

/** The store on the host port, bound to 127.0.0.1 only (compose.yaml). */
export const LOCAL_STORE_URL = "http://localhost:18080";

// CI writes e2e/.env too. An exported variable wins, here and in the seed container.
if (existsSync(resolve(E2E_DIR, ".env"))) process.loadEnvFile(resolve(E2E_DIR, ".env"));

/** Trimmed: a secret pasted into a CI store often carries a trailing newline. */
export function moneiApiKey(): string {
  const key = (process.env.MONEI_TEST_API_KEY ?? "").trim();
  if (!key) {
    throw new Error(
      "MONEI_TEST_API_KEY is not set. Copy e2e/.env.example to e2e/.env and set the test mode key there.",
    );
  }
  return key;
}

function generated(file: string): string {
  if (!existsSync(file)) throw new Error(`${file} is missing; run pnpm --dir e2e e2e:start first`);
  return readFileSync(file, "utf8").trim();
}

/** The store on the cloudflared tunnel host, so Sylius builds public callback URLs. */
export const storeUrl = (): string => generated(STORE_URL_FILE);

type Fixtures = {
  paymentMethods: Record<string, { name: string }>;
  adminEmail: string;
  /** The installed Sylius version, such as 2.3.3. */
  syliusVersion: string;
};

export const fixtures = (): Fixtures => JSON.parse(generated(FIXTURES_FILE));

/** The name of a seeded payment method, as the checkout shows it. */
export const methodName = (code: string): string => fixtures().paymentMethods[code].name;

/** Random per run (scripts/start.sh). Never log it or pass it to a Playwright call. */
export const adminPassword = (): string => generated(ADMIN_PASSWORD_FILE);
