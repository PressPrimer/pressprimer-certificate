# PressPrimer Certificate - Development Guide

## Project Overview

PressPrimer Certificate is an LMS-agnostic certificate authoring, issuance, and verification plugin for WordPress. It is the third plugin suite in the PressPrimer portfolio (Quiz, Assignment, Certificate).

**Status: 1.0.0 shipped to WordPress.org on 2026-08-10; 1.1.0 (the feedback fast-follow) is built and releasing.** The conventions in this guide and in `docs/architecture/CONVENTIONS.md` were set before the first commit and are binding on every change since; the as-built architecture docs in `docs/architecture/` describe the shipped code.

**Read before any work:** `docs/CLAUDE-INSTRUCTIONS.md` for the reading order and current version context.

---

## AI Development Workflow (CRITICAL)

These rules govern how AI assistants work on this codebase. They are identical to the PressPrimer Quiz workflow rules unless noted.

### Branching Strategy

**Work directly on the `develop` branch.** Feature branches are not required for solo development.

- `main` - Release branch, tagged versions, deploys to WordPress.org
- `develop` - Active development branch (DEFAULT)

### Commit Approval Required

**Do NOT commit changes without user approval.** Before committing:
1. Show the user what changes will be committed
2. Wait for explicit approval to commit
3. Only then run `git commit`

### Cross-Plugin Changes Require Approval

**WARN before modifying other plugins.** The PressPrimer ecosystem now spans three suites. If work on Certificate requires a change in PressPrimer Quiz, PressPrimer Assignment, or any of their addons:
1. STOP and notify the user
2. Explain what change is needed and why
3. Wait for approval before making changes
4. Coordinate releases - dependencies must release first

### Prompt-Based Development

**STOP after completing each prompt.** When the user provides numbered prompts, complete ONE prompt at a time and then STOP for review, testing, and the decision to proceed. Do not batch prompts or continue automatically.

### Mandatory Code Quality Checks

**Run these checks on ALL code changes before requesting commit approval:**

1. **PHP Syntax Check** - On any new or modified PHP files:
   ```bash
   "/Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php" -l path/to/file.php
   ```

2. **PHPCS (WordPress Coding Standards)** - On modified PHP files:
   ```bash
   "/Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php" ./vendor/bin/phpcs --standard=phpcs.xml.dist --report=full path/to/file.php
   ```

3. **Security-Specific Checks** - On files handling user input, database queries, or output:
   ```bash
   "/Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php" ./vendor/bin/phpcs --standard=WordPress-Extra --sniffs=WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification,WordPress.DB.PreparedSQL --report=full path/to/file.php
   ```

4. **PHP Compatibility (7.4 - 8.4)** - On new PHP files:
   ```bash
   "/Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php" ./vendor/bin/phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 7.4-8.4 --extensions=php path/to/file.php
   ```

5. **JavaScript Lint** - If JavaScript was modified: `npm run lint:js`

6. **Build Check** - If React components were modified: `npm run build`

7. **Layout parity tests** - If the designer, layout schema, or PDF renderer was modified: run the Playwright visual regression suite (canvas vs. rasterized PDF). See "Layout JSON Contract" below.

**If any check fails, fix the issues before requesting commit approval.**

### Database Migrations

1. **Backward compatibility** - Old plugin versions must not break with new schema
2. **Migration path** - Include migration code in `includes/database/class-ppcert-migrator.php`
3. **Test both directions** - New plugin with old data, and clean installs
4. **Document changes** - Note schema changes in commit message and changelog
5. **The 1.0 schema is deliberately over-built.** Issuer, credit, and event tables ship in 1.0 with no UI so that 2.0 and 3.0 land without migrations. Do not "clean up" unused tables.

### Deprecation Warnings

Features are not removed without warning: deprecation notice in one version, `_deprecated_function()` / `_deprecated_argument()` logging, removal in the next major version, changelog entry.

### Version Compatibility

Addons must be compatible with the free plugin version they require. Update `MIN_CORE_VERSION` constants in addons when breaking changes are made; coordinate releases.

### Changelog Discipline

Only `feat:`, `fix:`, `perf:`, and user-facing `refactor:` commits appear in public changelogs. `docs:`, `chore:`, `wip:`, `test:`, `style:` do not. Changelog entries must be copy/paste friendly for Knowledge Base articles - user-facing descriptions, not implementation notes.

### Testing After Merges

After merging to main: build a fresh zip, test clean installation, test upgrade from the previous version, verify features.

### Coordinated Releases

When changes span plugins: free plugin first, verify addons against it, release free, then release addons, note coordination in both changelogs.

### Cross-Plugin Investigation Required

**Always search the full PressPrimer ecosystem** when looking for patterns, implementations, or references:

1. `pressprimer-quiz/` + its Educator/School/Enterprise addons
2. `pressprimer-assignment/` + its addons
3. `pressprimer-certificate/` + its addons (once they exist)

**Before building any new feature:**
1. Search the ecosystem for similar existing implementations
2. Study how existing features handle the same concerns (data access, UI patterns, hooks)
3. Match the established patterns exactly - do not reinvent solutions that already exist
4. If a pattern exists in one plugin, it is the reference implementation for all

**Never conclude something "doesn't exist" after checking only one or two plugins.**

### Study Existing Implementations First

For patterns Certificate has not built yet, the reference implementations live in the sibling plugins; for patterns it has (REST controllers, list tables, the designer, adapters), Certificate's own shipped code is the reference. Examples from the sibling plugins:
- Building the **addon manager**? Read Quiz's `class-ppq-addon-manager.php` first
- Building a **REST controller**? Read an existing Quiz controller first
- Building an **admin list table**? Read Quiz's banks list table first
- Building a **settings page**? Read the free Quiz plugin's `SettingsPage.jsx` first
- Building **LMS detection**? Read Quiz's `includes/integrations/` classes first

The certificate **designer canvas** has no ecosystem precedent. Its specification lives in `docs/versions/v1.x/v1.0/SCOPE.md` and its architectural decisions in `docs/decisions/`.

---

## Certificate-Specific Architecture Rules (CRITICAL)

### Layout JSON Contract

The versioned layout JSON is the single source of truth for a certificate design. The full schema contract is `docs/architecture/layout-schema.md`. It is consumed by:
1. The React designer canvas (editing view)
2. The server-side PDF renderer (TCPDF - see ADR 002)
3. Future consumers: OG-image generation (Educator 2.0), print pipeline (School 2.x)

**Rules:**
- Every layout document carries `layout_schema_version`. Schema changes require a version bump and a migration function.
- The canvas and the PDF renderer MUST be pixel-faithful to each other. Playwright visual regression tests compare canvas screenshots against rasterized PDF output for every starter template. A parity failure is a release blocker.
- Issued certificates snapshot their layout and merge data at issue time (`wp_ppcert_certificates.layout_snapshot_json`). An issued certificate never changes appearance because its template was edited later. This mirrors Quiz's immutable question revisions.

### LMS Adapter Interface

All four LMS integrations (LearnDash, LifterLMS, Tutor LMS, LearnPress) implement one adapter interface. **The interface is locked before the first adapter is written.** After that, adapters are mapping work; interface changes require explicit approval and touch all four adapters.

Adapters activate only when their LMS is detected. The plugin is fully functional with zero LMSs installed. LearnDash is maintained but never headlined in UI copy or marketing per standing positioning.

### GPL Asset Register

Every bundled asset (font, graphic, template artwork, library) must be in-house, GPL-compatible, or CC0. Provenance is recorded in `docs/asset-register.md` (create on first asset). Fonts are SIL OFL only in the free plugin. No asset ships without a register entry.

### Datetime Standard: UTC Everywhere (IMPROVEMENT OVER QUIZ)

Quiz stores some columns in WordPress local time and others in GMT, which required a dedicated remediation release (v3.0.1 timezone fixes) and a long guidance section in its development guide. Certificate does not inherit that split.

**All `DATETIME` columns in `wp_ppcert_*` tables store UTC.**

```php
// CORRECT - writing
$now = current_time( 'mysql', true ); // GMT/UTC

// CORRECT - displaying
echo esc_html( get_date_from_gmt( $row->issued_at, get_option( 'date_format' ) ) );

// WRONG - never write local time to a ppcert table
$now = current_time( 'mysql' ); // REJECTED for ppcert tables

// WRONG - never use mysql2date() on ppcert values (it assumes local storage)
echo esc_html( mysql2date( get_option( 'date_format' ), $row->issued_at ) ); // REJECTED
```

This matters more here than anywhere else in the ecosystem: expiry dates and verification timestamps are credential-integrity data on a public verification page. React/front-end code parses these values as UTC (append `Z` or use the REST API's ISO 8601 output) and formats in the viewer's locale.

---

## WordPress.org Coding Standards

These rules were established during the Quiz plugin's WordPress.org review. **All code must follow these standards.** The subsections below are the review-critical rules in full; for extended examples see `../pressprimer-quiz/CLAUDE.md`.

### SQL Security (CRITICAL)

- Use the `%i` placeholder for field/column names in `$wpdb->prepare()`. Never `esc_sql()` for identifiers.
- Never interpolate variables for ORDER direction, even validated ones. Hardcode `ASC`/`DESC` in separate prepared branches.
- Always validate field names against a whitelist (`get_queryable_fields()` pattern) before use.
- No string manipulation on SQL. `str_replace()` on a prepared query is rejected; write separate queries.

### Input Sanitization (CRITICAL)

- Sanitize immediately when receiving input: `$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;`
- Sanitize arrays element by element (`array_map( 'sanitize_text_field', wp_unslash( ... ) )`); nested arrays field by field with type-appropriate sanitizers.
- `json_decode()` is NOT sanitization. Decode, verify `is_array()`, then sanitize each field. **This applies with full force to layout JSON**: every element of a submitted layout document is sanitized field by field against the layout schema (coordinates via `floatval`/range clamp, colors via `sanitize_hex_color()`, text via `sanitize_text_field()`/`wp_kses()`, media via attachment ID `absint()`).
- Never iterate over entire superglobals. Only read expected parameters.
- File uploads: validate `error === UPLOAD_ERR_OK` and `wp_check_filetype()` against an explicit allowlist before any processing. For the designer this means image uploads (logos, signatures, backgrounds) go through the WordPress media library, never raw `$_FILES` handling.

### Output Escaping (CRITICAL)

- `phpcs:ignore` for EscapeOutput = REJECTION. Use `wp_kses()` with an explicit allowed-tags array for complex HTML.
- Escape by context: `esc_url()`, `esc_attr()`, `esc_html()`, `wp_kses_post()`, `esc_textarea()`, `wp_json_encode()` for JSON in scripts.
- No inline styles for hiding elements (`wp_kses` strips them) - use CSS classes (`.ppcert-hidden`).
- `wp_add_inline_style()` only with CSS built from validated values (`sanitize_hex_color()`, `absint()`, `sprintf()`).
- The public verification page is the highest-exposure output surface in the plugin: every rendered value on it is attacker-influenced in principle (names, titles, issuer text). Escape everything; no exceptions.

### Prefixing (CRITICAL)

WordPress.org requires 4+ character prefixes for global namespace identifiers. **The Certificate standard is stricter: `ppcert` is the minimum everywhere; no identifier in any PressPrimer Certificate plugin may use a prefix shorter than `ppcert`.** See `docs/decisions/003-identifier-prefix-standard.md` (Accepted).

```php
// CORRECT
define( 'PPCERT_VERSION', '1.0.0' );
function ppcert_get_certificate( $id ) {}
class PressPrimer_Certificate_Issuance_Service {}
do_action( 'ppcert_certificate_issued', $certificate_id );
set_transient( 'ppcert_verify_' . $hash, $data );
wp_localize_script( 'ppcert-designer', 'ppcert_designer_data', $data );

// WRONG - anything shorter than ppcert (REJECTED)
define( 'PPC_VERSION', '1.0.0' );
function ppce_init() {}
set_transient( 'ppcs_cache', $data );
```

Applies to: constants, global functions, classes, hooks, AJAX actions, options, transients, user/post meta keys, shortcodes, menu slugs, `wp_localize_script` object names, script/style handles, capabilities, nonces, block namespaces, REST namespaces. Premium addons prefix with `ppcert_educator_`, `ppcert_school_`, `ppcert_enterprise_` (constants `PPCERT_EDUCATOR_`, etc.).

### Prohibited Code Patterns

- `eval()`, `create_function()`, `extract()`, `goto` - REJECTED
- Heredoc/Nowdoc syntax - REJECTED (use concatenation or `sprintf()`)
- Inline `<script>`/`<style>` tags in PHP - REJECTED (use `wp_localize_script()`, `wp_add_inline_script()`, `wp_add_inline_style()`)

### Required in Distribution

- **External Services Disclosure** in readme.txt for any external connection. In 1.0 the free plugin makes NO external service calls (QR generation and PDF rendering are local). Keep it that way; any future external call (e.g., print APIs in premium 2.x) requires disclosure and ships in premium, not the .org plugin.
- Minified/compiled assets: include sources in the plugin or link a public repo, document build instructions.
- Release ZIP excludes: `.git*`, `node_modules`, `package-lock.json`, `.wordpress-org`, `tests/`, config files (`phpunit.xml`, `phpcs.xml.dist`, `webpack.config.js`), IDE folders, `.env`, `.dist`/`.bak`/`.sample` files.
- **Bundled libraries (TCPDF, QR)**: pin exact versions via Composer, commit the trimmed vendor output, record licenses in the GPL asset register, and strip library test/example/doc folders from the release ZIP.

---

## Admin UI Development (CRITICAL)

**All WordPress admin pages MUST use React with Ant Design**, matching the ecosystem stack: React via `@wordpress/element`, Ant Design components, `@wordpress/i18n`, `@wordpress/api-fetch`.

- The **designer canvas** itself is custom React (absolutely positioned elements over the page background at true size). Its **chrome** (sidebars, panels, toolbars, modals) uses Ant Design.
- `WP_List_Table` (PHP) is acceptable for list views (issued certificates list, templates list) that link to React detail/editor pages.
- Follow the free Quiz plugin's established patterns for: settings tab save models, `message.config({ top: 50, duration: 5, maxCount: 3 })` in every entry point, radio card selectors, alerts, empty states, loading states, and the central Ant Design Select reset. See `../pressprimer-quiz/CLAUDE.md` "Admin UI Development", "Settings Tab & React Admin Surface Conventions", and "UI Component Patterns" - those sections are authoritative for Certificate too.
- **Nothing may render under the WordPress admin bar.** Floating UI near the top of the screen (tooltips, popovers, dropdowns on toolbar controls) must open DOWNWARD (`placement="bottom"`), never upward into the admin bar, and toasts keep the `top: 50` message config. Ant Design's default z-index layers sit below the admin bar's 99999, so anything that flips upward at the top of the viewport disappears behind it - treat that as a bug, not a cosmetic issue.
- **Select dropdowns must be fully readable.** Every Ant Design Select gets `popupMatchSelectWidth={ false }` so the option list sizes to its widest entry - a narrow control must never truncate its menu options. Property-panel selects use the shared fixed-width classes rather than shrinking to the current value.

### apiFetch Path Convention (CRITICAL)

Always use relative paths with `apiFetch`; never pass `rest_url()` output as a path.

```jsx
// CORRECT
apiFetch({ path: '/ppcert/v1/templates' });
apiFetch({ path: `/ppcert/v1/certificates/${id}` });

// WRONG
apiFetch({ path: restUrl + 'templates' }); // double-prepends the REST root
```

**REST namespaces (note: full tier names, unlike Quiz's `/ppqe/` which is ambiguous between Educator and Enterprise):**
- Free plugin: `/ppcert/v1/*`
- Educator addon: `/ppcert-educator/v1/*`
- School addon: `/ppcert-school/v1/*`
- Enterprise addon: `/ppcert-enterprise/v1/*`

---

## File Structure

See `docs/architecture/CODE-STRUCTURE.md` for the full intended v1.0 layout. Summary:

```
pressprimer-certificate/
├── pressprimer-certificate.php   # Bootstrap
├── uninstall.php
├── readme.txt
├── includes/                     # PHP (models, admin, api, frontend, services, integrations, database, utilities)
├── src/                          # React source (designer, wallet, settings)
├── blocks/                       # Gutenberg blocks
├── assets/                       # css/js/images
├── fonts/                        # Bundled OFL fonts (asset register required)
├── templates/                    # Starter layout JSON + artwork
├── build/                        # Compiled output (generated)
├── languages/
└── tests/
```

## Database

- Tables use prefix: `{wp_prefix}ppcert_`
- Schema defined in: `includes/database/class-ppcert-schema.php`
- Migrations in: `includes/database/class-ppcert-migrator.php`
- DB version constant: `PPCERT_DB_VERSION`
- All DATETIME columns are UTC (see Datetime Standard above)
- Full schema: `docs/architecture/DATABASE.md`

---

## Building and Releasing

### Build Plugin ZIP
```bash
npm run plugin-zip
```
Creates `dist/pressprimer-certificate.zip`

### Release Process
1. Update version in `pressprimer-certificate.php` and `readme.txt`
2. Commit to `main`
3. Create tag: `git tag v1.0.1 && git push origin v1.0.1`
4. Create GitHub Release from the tag
5. Workflow deploys to WordPress.org (`.github/workflows/deploy-to-wordpress-org.yml`, `10up/action-wordpress-plugin-deploy` pattern, same as Quiz)

---

## Commit Message Conventions

Same as Quiz. Changelog-worthy: `feat:` (Added), `fix:` (Fixed), `perf:` (Improved), `refactor:` (Changed, if user-facing). Non-changelog: `docs:`, `chore:`, `wip:`, `test:`, `style:`. Write changelog-worthy messages as user-facing descriptions suitable for KB articles.

---

## Pre-Release Checklist

1. **Prefixes** - Search for any identifier with a prefix shorter than `ppcert` (transients, localize object names, options, handles, capabilities, nonces)
2. **SQL** - No variable interpolation in ORDER BY; `%i` for field names
3. **Escaping** - No `phpcs:ignore` for EscapeOutput; verification page fully escaped
4. **Inline code** - No `<script>`/`<style>` tags in PHP
5. **Datetime** - No `current_time( 'mysql' )` without the `true` (GMT) argument; no `mysql2date()` on ppcert values
6. **Layout parity** - Playwright canvas-vs-PDF suite passes for all starter templates
7. **Bundled libraries** - Versions pinned, licenses in asset register, test/example folders stripped
8. **External services** - None in free 1.0; readme.txt makes no undisclosed connections
9. **Prohibited files** - No `.git`, `node_modules`, test files in ZIP
10. **Heredoc** - None anywhere
11. **Array sanitization** - All `$_POST` arrays, including layout JSON, sanitized element by element

---

## Important Reminders

1. **Always run PHPCS before committing**
2. **Test with WP_DEBUG enabled**
3. **Sanitize early, escape late**
4. **No variable interpolation in SQL**
5. **Nothing shorter than `ppcert`** - for any global identifier, in any plugin of this suite
6. **UTC in, localized out** - for every ppcert datetime
7. **The canvas is the PDF** - parity failures are release blockers
8. **phpcs:ignore for escaping = REJECTION**
