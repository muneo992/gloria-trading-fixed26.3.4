# East Africa Phase 1

Small static sales support site for direct outreach to Kenya (primary) and Uganda (secondary). Public root: `east-africa/`. Production destination: `https://ea.gloriatrading.com`, `/home/gltr/www/gloria-ea`.

## Pages

- `/`: Home, short introduction and four sourcing services.
- `/vehicles.html`: Reference Vehicles & Prices.
- `/vehicle-detail.html?ref=…`: Reference specifications, photos, prices and prefilled request.
- `/request.html`: Four required fields, optional budget and specifications, WhatsApp/email handoff.
- `/how-to-buy.html`: Five steps, including a separate “You Decide”.
- `/about.html`: Existing company facts and business principles.
- `/contact.html`: Direct company contacts and request link.

## Data separation and publication

`data/vehicles.json` is independent of `frontend/data/vehicles.json`. The existing admin does not edit EA data. No database or admin changes are needed. Only explicitly approved public fields belong here; never copy the full West Africa record, which can contain quotation/customer/export information.

The initial array is intentionally empty. The repository's source records are from 2011/2016 and contain no EA CIF estimates. They have not been relabeled as suitable Kenya/Uganda offers. The catalog has an honest empty state and working request CTA. Add verified, current examples before using the site to demonstrate actual prices.

Public field contract:

| Field | Meaning |
|---|---|
| `ref_id` | Unique EA reference ID |
| `make`, `model`, `display_name_en`, `year` | Existing title convention |
| `mileage_km`, `engine_cc`, `fuel_type`, `transmission` | Existing public specification names |
| `drive`, `steering`, `auction_grade` | Verified specifications; omit unknown grade |
| `gallery` | Paths under EA `images/`; copy only approved photos |
| `reference_price_usd` | Reference FOB Japan; positive number or null |
| `estimated_cif_mombasa_usd` | Manually verified estimate; positive number or null |
| `price_as_of` | Date of supplied prices, required when a price is present |

Unknown prices display “Request a quotation”. No automatic CIF calculation or West Africa fallback. Unknown specifications display “Not provided”. Price estimates explicitly require final quotation confirmation. Listing inclusion and destination eligibility require checking before adding records; the UI makes no market-popularity claims.

Keep the data under version control. Uploading JSON only to Sakura is not a durable editing workflow: an EA deployment republishes this file. When adding records, add matching detail URLs to the sitemap if indexing is wanted. Details use client-side metadata updates; a social crawler that does not execute JavaScript sees the generic detail metadata.

## Reuse

`css/base.css` is an unchanged copy of `frontend/css/style.css` at base commit `4fbc73a0e4ba6bd86edf298c5e54009f6e599f87`. `css/ea.css` provides isolated overrides. Logo, company address, representative, phone and WhatsApp are copied from existing pages; email comes from the existing contact form. No new history, testimonials or statistics were invented.

`js/ea.js` adapts the original catalog's JSON fetch, field naming, title fallback, gallery, numeric formatting and reference-query lookup patterns. It uses textContent for data rendering instead of interpolating data as HTML. Marketing, destination defaults, admin fields and long RFQ behavior are not copied.

## Local verification

From repository root:

```sh
python3 scripts/check-east-africa.py
node --check east-africa/js/ea.js
python3 -m http.server 8765 --bind 127.0.0.1 --directory east-africa
```

Preview at `http://127.0.0.1:8765`. Review mobile navigation, budget-optional requests, WhatsApp/email handoff, populated catalog/details using temporary test data, and missing-data/error states. Never publish synthetic test vehicles.

## Deployment

Workflow: `.github/workflows/deploy-east-africa.yml`. Manual dispatch only, confirmation `DEPLOY_EA`, permitted refs `main` and `east-africa-site-v1`. Checkout uses the dispatched immutable commit SHA. It reuses the existing `SAKURA_HOST`, `SAKURA_PORT`, `SAKURA_USER`, `SAKURA_SSH_KEY` names without changing or displaying secret values.

Only `east-africa/` is sent, to the literal EA destination. Remote physical-path and symlink checks precede backup/upload. Full backup is stored outside the public web root at `/home/gltr/ea-backups/`. Rsync uses checksums and delayed file updates, without deletion. A second dry run confirms matching contents; public HTTP checks follow. SSH key cleanup always runs. The existing manually placed `index.html` is backed up then replaced on the first authorized EA deployment.

The new workflow generally needs to exist on the default branch before GitHub exposes manual dispatch. This work does not merge the PR, trigger a workflow or deploy. Secrets access and remote directory permissions remain untested until an authorized run. No automatic rollback: on failure, inspect the saved backup and restore only the EA directory. Removed/renamed obsolete files are not automatically deleted by rsync.

The unchanged legacy production/test workflows sync the repository root. If someone later runs those workflows after merging this PR, they will also copy the new `east-africa/` directory as an extra subdirectory (they do not make it the West Africa homepage). Before that separate operation, review exclusions if EA must never be copied to those hosts. This PR deliberately does not modify the existing workflows or run them.

## Implementation report — 2026-09-10

Start gate passed: clean working tree, fetched origin, remote branch present, checked out `east-africa-site-v1`, exact equality with latest `origin/main` at `4fbc73a0e4ba6bd86edf298c5e54009f6e599f87`. No changes were made on the previous Phase 2 branch.

| Added file | Reason |
|---|---|
| `east-africa/index.html` | Brief first view and four actual services |
| `east-africa/vehicles.html` | Reference catalog, explicit estimate wording and honest empty state |
| `east-africa/vehicle-detail.html` | Specifications and similar-vehicle request |
| `east-africa/request.html` | Short WhatsApp/email request, optional target budget |
| `east-africa/how-to-buy.html` | Five steps, with buyer decision separate |
| `east-africa/about.html` | Existing company facts and agreed principles |
| `east-africa/contact.html` | Direct contacts and request CTA |
| `east-africa/css/base.css` | Byte-identical copy of existing CSS for isolation |
| `east-africa/css/ea.css` | Shorter layout, mobile navigation/forms and accessible focus styles |
| `east-africa/js/ea.js` | Navigation, handoff, isolated catalog/detail rendering, error states |
| `east-africa/images/gloria-trading-logo.jpg` | Byte-identical existing logo |
| `east-africa/data/vehicles.json` | Independent public dataset; initially empty |
| `east-africa/.htaccess` | Serve EA index and disable directory listing |
| `east-africa/robots.txt` | EA crawler and sitemap configuration |
| `east-africa/sitemap.xml` | EA-only static page URLs |
| `.github/workflows/deploy-east-africa.yml` | Guarded manual EA-only deployment, backup and verification |
| `scripts/check-east-africa.py` | HTML/internal-link/data/sitemap checks, also used before deployment |
| `scripts/EAST_AFRICA_PHASE1.md` | Data contract, operation notes, completion evidence and limitations |

Validation completed: JavaScript syntax; seven HTML pages with one H1 and canonical each, unique IDs and valid local links/anchors; public dataset contract; sitemap; YAML parsing; shell syntax of every workflow run block; all 14 non-dot public files served HTTP 200 by a temporary local HTTP server. Existing CSS/logo copies match byte-for-byte. Diff against the base for all existing tracked files is empty.

Validation not completed: Playwright could not launch because Chromium was absent, and browser download timed out. No visual/mobile screenshot review or real browser form-handoff test is claimed. Live SSH, backup, rsync, Apache behavior, public deployment and secret access were not run.

Remaining before operational launch: add verified EA vehicle/price/photo examples; perform browser/mobile and WhatsApp/email handoff review; review legacy root-wide deployment behavior noted above before separately running legacy deployments after merge; authorize and verify the first EA deployment. No merge, main push, West Africa deployment, database changes or automatic actions were performed.
