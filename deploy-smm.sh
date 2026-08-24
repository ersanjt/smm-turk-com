#!/bin/bash
# Thin wrapper — real logic lives in scripts/deploy-cpanel.sh (safe defaults).
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
exec bash "$DIR/scripts/deploy-cpanel.sh"
