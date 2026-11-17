// First step of e2e:start: refuse a key or an account that cannot run the suite.
import { checkTestAccount, REQUIRED_METHODS } from "../utils/monei.ts";

try {
  await checkTestAccount();
  process.stdout.write(`MONEI test account offers ${REQUIRED_METHODS.join(", ")}\n`);
} catch (error) {
  process.stderr.write(`e2e: ${(error as Error).message}\n`);
  process.exitCode = 1;
}
