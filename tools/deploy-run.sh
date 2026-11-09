#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if [[ $# != 1 ]]; then
  printf '%s\n' 'Usage: tools/deploy-run.sh package|check|apply|resume' >&2
  exit 2
fi
exec python3 -I -B "$ROOT/tools/rsp-feed-release.py" "$1"
