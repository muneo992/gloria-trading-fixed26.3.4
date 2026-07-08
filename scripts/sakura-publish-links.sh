#!/usr/bin/env bash
# Create root-level symlinks so the site works on Sakura even when
# mod_rewrite / .htaccess rules are not applied (nginx-only vhost, etc.).
set -euo pipefail

ROOT="${1:-.}"
cd "$ROOT"

link_target() {
  local name="$1"
  local target="$2"

  if [[ ! -e "$target" ]]; then
    echo "skip missing target: $target" >&2
    return 0
  fi

  rm -rf "$name"
  ln -sfn "$target" "$name"
  echo "linked $name -> $target"
}

for dir in css js data images assets uploads; do
  link_target "$dir" "frontend/$dir"
done

pages=(
  index.html
  catalog.html
  about.html
  contact.html
  dealers.html
  documents-faq.html
  how-it-works.html
  pricing.html
  rfq.html
  rfq-success.html
  shipping-payment.html
  vehicle-detail.html
)

legacy_country_pages=(
  ghana.html
  nigeria.html
  benin.html
  ivory-coast.html
)

for page in "${legacy_country_pages[@]}"; do
  if [[ -e "$page" ]]; then
    rm -f "$page"
    echo "removed legacy country page: $page"
  fi
done

for page in "${pages[@]}"; do
  if [[ -f "frontend/$page" ]]; then
    link_target "$page" "frontend/$page"
  elif [[ -f "frontend/west_africa/$page" ]]; then
    link_target "$page" "frontend/west_africa/$page"
  fi
done

if [[ -f "frontend/data/vehicles.json" ]]; then
  cp -f "frontend/data/vehicles.json" "vehicles.json"
  echo "synced vehicles.json"
fi

for root_file in sitemap.xml robots.txt; do
  if [[ -f "$root_file" ]]; then
    echo "present at web root: $root_file"
  else
    echo "warning: missing web root file: $root_file" >&2
  fi
done

if [[ -f "frontend/sitemap.xml" ]]; then
  rm -f "frontend/sitemap.xml"
  echo "removed legacy frontend/sitemap.xml"
fi
