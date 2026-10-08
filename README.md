# Plenopay Payment Method — WooCommerce plugin

Adds Plenopay as a WooCommerce payment method. On order confirmation the Plenopay
payment window (Unlimit) opens in a popup. Supports classic and blocks checkout,
HPOS, and refunds synced with Plenopay Comercios.

## Repository layout

```
PlenoPayWoocommercePlugin/
├── .distignore                  # files excluded from the release zip
├── .gitattributes
├── .gitignore
├── README.md
└── plenopay-payment-method/     # plugin source — this folder is what ships
    ├── plugin_plenopay.php      # main plugin file (Version header + PLENOPAY_VERSION)
    ├── plenopay-blocks.js       # blocks checkout integration
    ├── readme.txt               # WordPress readme; == Changelog == feeds release notes
    ├── boton_logo_plenopay-02.png
    ├── CHECKOUTbox-plenopay.png
    ├── favicon.svg
    └── vendor/plugin-update-checker/   # YahnisElsts/plugin-update-checker v5.7 (MIT), unmodified
```

## Automatic updates

The plugin checks this repo's **latest GitHub Release** (every 12 h) using the bundled
Plugin Update Checker. Rules enforced in `plugin_plenopay.php`:

- Only published releases count — tags without a release and branch heads are ignored.
- The release **must** have an asset named exactly `plenopay-payment-method.zip`; otherwise
  no update is offered (GitHub's auto-generated source zip is never installed).
- The version comes from the release tag (`v2.0.13` → `2.0.13`).
- The release body is shown as the changelog in WordPress' "View details" popup.

Merchants opt in to unattended installs with "Enable auto-updates" on the Plugins page.

To upgrade the vendored library, replace `vendor/plugin-update-checker/` with the new
release and update the `v5pN\Vcs\Api` reference in `plugin_plenopay.php`.

The folder name `plenopay-payment-method/` and the main file `plugin_plenopay.php`
identify the plugin in WordPress. **Never rename them** — merchants would end up
with a second copy instead of an upgrade.

## Branches

- `develop` — day-to-day work
- `main` — released code; changes arrive via PR from `develop`

## Releases

Release zips are published as **GitHub Release assets**, not committed to the repo.
The zip must contain `plenopay-payment-method/` at its root.

`.github/workflows/release.yml` builds and publishes them:

| Trigger | Jobs |
|---|---|
| Pull request to `main` | `php -l` on PHP 7.4 and 8.3 |
| Tag `v*` | lint → tag-on-main check → version check → build zip → GitHub Release |

How to release `X.Y.Z`:

1. On `develop`, bump the version in **four** places:
   - `plugin_plenopay.php` → `Version: X.Y.Z` header
   - `plugin_plenopay.php` → `define( 'PLENOPAY_VERSION', 'X.Y.Z' );`
   - `readme.txt` → `Stable tag: X.Y.Z`
   - `readme.txt` → new `= X.Y.Z =` entry under `== Changelog ==` (becomes the release notes)
2. PR `develop` → `main`, merge.
3. Tag `main` and push the tag:
   ```
   git checkout main && git pull
   git tag vX.Y.Z
   git push origin vX.Y.Z
   ```
4. Check the Actions run. The release fails, and nothing is published, if the tag is not on
   `main`, any of the four values disagree with the tag, or the changelog entry is missing.

A version with a suffix (`v2.1.0-rc1`) is published as a pre-release; installed plugins
ignore pre-releases, so it can be downloaded for QA without reaching merchants.

`plenopay_woocommerce_plugin_v2.0.12.zip` stays in the repo until the first GitHub
Release (v2.0.13) exists, then it is removed.

See `woocommerce_plugin_auto_updates_plan.md` (Plenopay workspace) for the
automatic-updates roadmap: Plugin Update Checker + GitHub Releases + release workflow.
