// Step of e2e:start: prints the MONEI account ID of the test key, for the inline payment methods
// of the seed. Prints nothing else; start.sh keeps it out of the log.
import { readAccountId } from "../utils/monei.ts";

try {
  const runId = process.env.E2E_RUN_ID;
  if (!runId) throw new Error("E2E_RUN_ID is missing.");
  process.stdout.write(await readAccountId(`e2e${runId}-account`));
} catch (error) {
  process.stderr.write(`e2e: ${(error as Error).message}\n`);
  process.exitCode = 1;
}
