#!/usr/bin/env bash
# Builds an installable plugin zip from the committed code (HEAD).
# Development files are excluded through export-ignore in .gitattributes.
#
#   bin/build-zip.sh [output-dir]      # default: build/
set -euo pipefail

cd "$(dirname "$0")/.."

SLUG="ai-faq-chatbot"
OUT_DIR="${1:-build}"
mkdir -p "$OUT_DIR"

git archive --format=zip --prefix="${SLUG}/" -o "${OUT_DIR}/${SLUG}.zip" HEAD
echo "Built ${OUT_DIR}/${SLUG}.zip"
