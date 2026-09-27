# Milestone: Standalone PHP Quality Inspection Engine

## Objective

Build a self-hosted, CLI-first inspection layer that covers the majority of the practical PHP/SOLID/code-smell value currently obtained from commercial deep-analysis tools, while keeping those tools available as optional calibration and release-hardening inputs.

The milestone is intentionally focused on PHP 8.4 and Symfony-oriented repositories. JavaScript, SQL, dashboards, and broad IDE replacement are out of scope.

## Current status

As of the current master baseline:

- Phase 0: complete.
- Phase 1: substantially complete; normalized Finding, InspectionReport, InspectionSummary, analyzer result contract, JSON CLI output, and fixture-driven tests are implemented. A dedicated reusable process abstraction remains optional cleanup because Symfony Process is currently encapsulated inside adapters.
- Phase 2: PHPStan and Rector adapters are active. Semgrep JSON support is implemented as an optional local analyzer and is skipped when a repository has no local Semgrep configuration or binary. Normalized report persistence is implemented under `.inspecting/reports`; accepted analyzer JSON payloads are persisted separately under `.inspecting/raw/<target>/` and surfaced through `rawReportReferences`.
- Phase 3: active. Native metrics already cover class/method size, constructor object dependencies, public API size, interface size, cyclomatic complexity, repeated polymorphic type dispatch, property-cohesion groups, project-level fan-out/fan-in, and inheritance depth. Fan-out includes resolved class-like dependencies referenced by a class; fan-in counts incoming references from classes inside the inspected project.
- Phase 4: active. Current native SOLID/smell rules cover SRP collaborator overload, SRP low property cohesion, OCP repeated type dispatch, ISP oversized interfaces, DIP service locator, large class/public API, long methods, and high complexity.
- Phase 5: partially complete. `inspect <target>` emits stable machine-readable JSON with compact consumer summaries. Console MCP-specific execution/read tools and persisted report paths remain.
- Phase 6: not started as a formal calibration phase. One local calibration run against Gating has already demonstrated real findings without changing thresholds.

Quality discipline:

- PHPStan remains at `--level=max`.
- Native thresholds are not raised to make self-scan green.
- True-positive findings are fixed in code.
- False positives are corrected by making the rule more semantically precise.
- Rector deterministic findings are fixed rather than suppressed.
- The executable composition root is included in PHPStan analysis.

Next implementation slice:

1. configurable local Semgrep rule-pack discovery;
2. Console MCP consumer integration;
3. richer project dependency-graph outputs and calibration before introducing coupling thresholds;
4. broader calibration across representative repositories before adding more heuristics;
5. report lifecycle/latest-report lookup once consumer integration requires it.

## Architectural position

```text
Canonization <=> Gating
  owner rules    deterministic enforcement

Inspecting
  heuristic and analytical quality evidence
```

Inspecting is not required to be symmetric with Canonization and must not dilute the Canonization/Gating one-to-one contract.

Inspecting owns observations and normalized findings. A consumer may later decide that a severity threshold blocks a workflow, but the inspection engine itself is not primarily a gate.

## Runtime decision

Start as a standalone Composer package and CLI:

- PHP 8.4
- default `App\\` namespace
- Symfony Console component for CLI ergonomics
- Symfony Process component for controlled analyzer execution
- no FrameworkBundle
- no Symfony kernel
- no bundle integration
- no HTTP API
- no database
- no dashboard

The package must be usable both directly from a terminal and through an execution consumer such as Console MCP.

## Core report contract

Every analyzer must normalize to the same conceptual finding:

```text
Finding
  source
  rule
  category
  severity
  confidence
  message
  evidence
  file
  line/range
  remediation
  autofixable
```

An inspection run must also expose:

```text
InspectionReport
  target
  startedAt
  finishedAt
  analyzers
  findings
  metrics
  summary
  rawReportReferences
```

JSON is the canonical transport format. Human-readable console output is a projection of the same report. SARIF is a later interoperability target, not the internal domain format.

## Analyzer strategy

### PHPStan

Use as the semantic/type foundation and, progressively, as the engine for custom project-wide collectors and rules where type/reflection knowledge matters.

### Semgrep

Use for inexpensive structural/pattern rules, security patterns, and architecture smells that do not require full PHP semantic inference.

### Rector

Use dry-run output as modernization/refactoring evidence and later as the preferred autofix backend for findings that have a deterministic transformation.

### Native metrics

Add metrics that are useful inputs to SOLID/code-smell analysis:

- cyclomatic/cognitive complexity
- class and method size
- constructor dependency count
- public API size
- coupling/fan-in/fan-out
- inheritance depth
- interface surface size
- repeated type-dispatch patterns
- cohesion proxies where practical

### SonarQube

Treat SonarQube Community as an optional always-available external analyzer and historical quality surface. Do not make core inspection dependent on a SonarQube server.

### Qodana

Treat Qodana as an optional calibration/deep-audit source. Findings discovered during paid Qodana sprints should be classified; reusable classes of findings should become permanent open rules where practical.

## SOLID/code-smell focus

The milestone must prioritize useful evidence around:

- SRP: mixed responsibilities, oversized classes, excessive collaborators, low cohesion proxies
- OCP: repeated type switches, large `instanceof` chains, discriminator-driven behavior
- LSP: rely primarily on semantic/type-contract evidence
- ISP: oversized interfaces and unnecessarily broad contracts
- DIP: concrete infrastructure dependencies, service-location patterns, forbidden dependency directions
- general smells: duplication, excessive complexity, dead/suspicious code, long methods, deep nesting, excessive parameter/dependency counts

Rules must distinguish deterministic violations from heuristics. Heuristic findings must carry confidence and evidence rather than pretending to be compiler errors.

## Delivery phases

### Phase 0 — Foundation

- repository and master branch
- standalone Composer/CLI skeleton
- architecture and milestone documentation
- stable output directories and exit-code contract

### Phase 1 — Normalized contracts

- immutable finding/report value objects
- JSON serializer
- console renderer
- analyzer interface
- process execution abstraction
- fixture-driven parser tests

### Phase 2 — Existing analyzer adapters

- PHPStan JSON adapter
- Semgrep JSON adapter
- Rector dry-run adapter
- analyzer availability detection
- raw-output references rather than copying large reports into prompts

### Phase 3 — Native PHP quality metrics

