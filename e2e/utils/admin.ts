// The admin, on localhost: admin actions need no callback, so not through the public tunnel.
// The login runs in Node's fetch, never in Playwright: a fill step title, trace or report would
// show the password. Errors carry HTTP statuses only.
import type { Browser, Page } from "@playwright/test";
import { LOCAL_STORE_URL as ADMIN_URL, adminPassword, fixtures } from "./env.ts";

type Jar = Map<string, string>;

function keepCookies(jar: Jar, response: Response): void {
  for (const header of response.headers.getSetCookie()) {
    const [pair] = header.split(";");
    const index = pair.indexOf("=");
    jar.set(pair.slice(0, index).trim(), pair.slice(index + 1).trim());
  }
}

const cookieHeader = (jar: Jar) => [...jar].map(([name, value]) => `${name}=${value}`).join("; ");

/** Hidden fields (CSRF tokens) of the admin login form, read from the live page. */
function hiddenFields(html: string): Record<string, string> {
  const form = html.match(/<form[^>]*action="\/admin\/login-check"[\s\S]*?<\/form>/)?.[0];
  if (!form) throw new Error("the admin login page has no login form");
  const fields: Record<string, string> = {};
  for (const input of form.match(/<input[^>]*type="hidden"[^>]*>/g) ?? []) {
    const name = input.match(/name="([^"]*)"/)?.[1];
    if (name) fields[name] = input.match(/value="([^"]*)"/)?.[1] ?? "";
  }
  return fields;
}

async function login(): Promise<Jar> {
  const jar: Jar = new Map();
  const form = await fetch(`${ADMIN_URL}/admin/login`);
  if (!form.ok) throw new Error(`admin login page: ${form.status}`);
  keepCookies(jar, form);

  const check = await fetch(`${ADMIN_URL}/admin/login-check`, {
    method: "POST",
    redirect: "manual",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded",
      Cookie: cookieHeader(jar),
      Origin: ADMIN_URL,
      Referer: `${ADMIN_URL}/admin/login`,
    },
    body: new URLSearchParams({
      ...hiddenFields(await form.text()),
      _username: fixtures().adminEmail,
      _password: adminPassword(),
    }),
  });
  keepCookies(jar, check);

  // A failed login redirects too: only the dashboard proves it.
  const dashboard = await fetch(`${ADMIN_URL}/admin/`, { redirect: "manual", headers: { Cookie: cookieHeader(jar) } });
  if (dashboard.status !== 200) throw new Error(`admin login failed: login check ${check.status}, dashboard ${dashboard.status}`);
  return jar;
}

/** A page in a new browser context, logged in to the admin. */
export async function adminPage(browser: Browser): Promise<Page> {
  const jar = await login();
  const context = await browser.newContext({
    baseURL: ADMIN_URL,
    storageState: {
      cookies: [...jar].map(([name, value]) => ({
        name,
        value,
        domain: "localhost",
        path: "/",
        expires: -1,
        httpOnly: true,
        secure: false,
        sameSite: "Lax" as const,
      })),
      origins: [],
    },
  });
  return context.newPage();
}
