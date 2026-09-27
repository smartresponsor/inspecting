# Inspecting

Inspecting is the standalone PHP quality-inspection engine for the repository ecosystem.

It is intentionally separate from Canonization and Gating:

- Canonization owns normative owner rules.
- Gating executes the Canonization rules as deterministic pass/fail enforcement.
- Inspecting detects code-quality, design, SOLID, maintainability, and architecture smells that do not need to become canon rules.

## Product boundary

Inspecting is not a Symfony web application and is not a Symfony bundle.

The initial runtime is a small PHP 8.4 CLI/library that may use focused Symfony components where they add value. There is no FrameworkBundle, HTTP layer, database, dashboard, or SaaS dependency in the core.

Canonical naming:

- repository: `Inspecting`
- PHP namespace: `App\\`
- CLI entrypoint: `bin/inspecting`
- generated reports: `.inspecting/`

## Direction

Inspecting will orchestrate and normalize evidence from analyzers such as:

- PHPStan
- Semgrep
- Rector dry-run
- native PHP metrics and project-wide collectors
- SonarQube Community when available
- Qodana as an optional milestone calibration source

