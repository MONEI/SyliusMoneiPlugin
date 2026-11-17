#!/usr/bin/env bash
# Removes every container, network and volume of the e2e stack, tunnel included.
set -euo pipefail
source "$(dirname "$0")/lib.sh"

compose --profile tools down -v --remove-orphans
rm -rf "$GENERATED_DIR"
