# Inspecting

Inspecting is the standalone PHP quality-inspection engine for the repository ecosystem.

It is intentionally separate from Canonization and Gating:

- Canonization owns normative owner rules.
- Gating executes the Canonization rules as deterministic pass/fail enforcement.
- Inspecting detects code-quality, design, SOLID, maintainability, and architecture smells that do not need to become canon rules.

## Product boundary

Inspecting is a PHP 8.4 quality-analysis engine whose primary product runtime remains the `bin/inspecting` CLI/library surface. It owns inspection, normalization, metrics, findings, raw evidence, and report generation; it does not own business CRUD, persistence, dashboards, or SaaS application behavior.

For canonical platform integration, Inspecting also exposes a reusable Symfony bundle and a minimal standalone FrameworkBundle runtime. The Symfony shell exists for container verification, debugging, and host composition; it does not introduce HTTP controllers, routes, templates, or user-facing UI into the inspection core.

Canonical naming:

- repository: `Inspecting`
- PHP namespace: `App\\Inspecting\\`
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

