#!/usr/bin/env bash
# Expand a branch, tag, full SHA, or unique short SHA to sha=<40 hex>.
# Stdout is that token only. Missing or ambiguous input exits with a token.
set -euo pipefail
set +x

INPUT="${1:-}"

die() {
  printf '%s\n' "$1" >&2
  exit 1
}

if [ -z "$INPUT" ]; then
  die "deploy_ref_missing"
fi
case "$INPUT" in
  *[!A-Za-z0-9._/-]*|-*|*..*|*'@{'*) die "deploy_ref_invalid" ;;
esac

resolve_commit() {
  git rev-parse --verify --quiet --end-of-options "${1}^{commit}" 2>/dev/null || true
}

SHA=""
if [[ "$INPUT" =~ ^[0-9a-fA-F]{4,40}$ ]]; then
  SHA="$(resolve_commit "$INPUT")"
  if [[ ! "$SHA" =~ ^[0-9a-f]{40}$ ]]; then
    die "deploy_ref_not_found"
  fi
  LOW_SHA="$(printf '%s' "$SHA" | tr 'A-F' 'a-f')"
  LOW_INPUT="$(printf '%s' "$INPUT" | tr 'A-F' 'a-f')"
  case "$LOW_SHA" in
    "$LOW_INPUT"*) ;;
    *) die "deploy_ref_not_found" ;;
  esac
else
  CANDIDATE=""
  for CANDIDATE in \
    "refs/heads/$INPUT" \
    "refs/remotes/origin/$INPUT" \
    "refs/tags/$INPUT" \
    "$INPUT"
  do
    SHA="$(resolve_commit "$CANDIDATE")"
    if [[ "$SHA" =~ ^[0-9a-f]{40}$ ]]; then
      break
    fi
    SHA=""
  done
  if [[ ! "$SHA" =~ ^[0-9a-f]{40}$ ]]; then
    die "deploy_ref_not_found"
  fi
fi

printf 'sha=%s\n' "$SHA"
