# PressPrimer Certificate

LMS-agnostic certificate authoring, issuance, and verification for WordPress. Part of the PressPrimer suite (Quiz, Assignment, Certificate).

This is the development repository. The WordPress.org readme is `readme.txt`; developer workflow rules live in `CLAUDE.md`.

## Development setup

```bash
composer install   # PHP dev toolchain (PHPCS, PHPUnit) + pinned production libs (TCPDF)
npm install        # JS toolchain (@wordpress/scripts) + admin app dependencies
```

Local development runs against the "Quiz Plugin Dev" Local site (symlink this directory into its `wp-content/plugins/`).

## Common commands

| Command | Purpose |
|---|---|
| `vendor/bin/phpunit` | Unit suite (`tests/phpunit`, no database required) |
| `vendor/bin/phpcs` | WordPress Coding Standards (`phpcs.xml.dist`) |
| `npm run build` | Compile the React admin apps into `build/` |
| `npm run lint:js` | JavaScript lint |
| `npm run build:fonts` | Font pipeline (below) |
| `npm run plugin-zip` | Production ZIP into `dist/` |

## Font pipeline (`npm run build:fonts`)

The bundled OFL fonts in `fonts/` are converted to TCPDF font files at build time — production never converts at runtime (Feature 007 FR-003).

- **Inputs:** static TTFs under `fonts/<family-slug>/` with their OFL license files, configured in `scripts/build-fonts.php`
- **Outputs (committed):** `fonts/tcpdf/` (TCPDF font definitions) and `fonts/manifest.json` — the single font map (slug → variants, metrics, FR-004 fitting thresholds) consumed by both the PDF renderer and the designer
- Run it after adding/updating any font family, and update `docs/asset-register.md` in the same commit

Every bundled asset must have a provenance entry in `docs/asset-register.md` before it ships.

## Documentation

The full planning and architecture set lives in `docs/` (kept local, not pushed): start with `docs/CLAUDE-INSTRUCTIONS.md` for reading order, `docs/versions/v1.x/v1.0/PHASES.md` for the build plan.
