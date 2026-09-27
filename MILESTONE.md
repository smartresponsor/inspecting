# Milestone: Standalone PHP Quality Inspection Engine

## Objective

Build a self-hosted, CLI-first inspection layer that covers the majority of the practical PHP/SOLID/code-smell value currently obtained from commercial deep-analysis tools, while keeping those tools available as optional calibration and release-hardening inputs.

The milestone is intentionally focused on PHP 8.4 and Symfony-oriented repositories. JavaScript, SQL, dashboards, and broad IDE replacement are out of scope.

## Current status

As of the current master baseline:

- Phase 0: complete.
- Phase 1: substantially complete; normalized Finding, InspectionReport, InspectionSummary, analyzer result contract, JSON CLI output, and fixture-driven tests are implemented. A dedicated reusable process abstraction remains optional cleanup because Symfony Process is currently encapsulated inside adapters.
- Phase 2: PHPStan and Rector adapters are active. Semgrep JSON support is implemented as an optional local analyzer with deterministic config discovery: explicit config, project `config/semgrep/`, project root Semgrep config, then the Inspecting-owned `config/semgrep/` fallback. The baseline remains local/SaaS-free and skips Semgrep only when the executable is unavailable. Normalized report persistence is implemented under `.inspecting/reports`; accepted analyzer JSON payloads are persisted separately under `.inspecting/raw/<target>/` and surfaced through `rawReportReferences`.
- Phase 3: active. Native metrics already cover class/method size, constructor object dependencies, public API size, interface size, cyclomatic complexity, repeated polymorphic type dispatch, property-cohesion groups, project-level fan-out/fan-in, and inheritance depth. Fan-out includes resolved class-like dependencies referenced by a class; fan-in counts incoming references from classes inside the inspected project. Each native inspection also persists a deterministic project dependency-graph artifact with per-class parent, fan-in/fan-out, inheritance depth, and internal/external dependency lists.
- Phase 4: active. Current native SOLID/smell rules cover SRP collaborator overload, SRP low property cohesion, OCP repeated type dispatch, ISP oversized interfaces, DIP service locator, large class/public API, long methods, and high complexity.
- Phase 5: substantially complete. `inspect <target>` emits stable machine-readable JSON with compact consumer summaries, and Console MCP exposes atomic `quality.status`, `quality.inspect`, `quality.report`, and `quality.findings` tools over the standalone engine. Report lifecycle/latest-report convenience remains optional follow-up.
- Phase 6: not started as a formal calibration phase. One local calibration run against Gating has already demonstrated real findings without changing thresholds.

Quality discipline:

- PHPStan remains at `--level=max`.
- Native thresholds are not raised to make self-scan green.
- True-positive findings are fixed in code.
- False positives are corrected by making the rule more semantically precise.
- Rector deterministic findings are fixed rather than suppressed.
- The executable composition root is included in PHPStan analysis.

Next implementation slice:

1. calibrate dependency-graph evidence across representative repositories before introducing any coupling thresholds;
2. broader calibration across representative repositories before adding more heuristics;
3. expand the Inspecting-owned Semgrep rule pack only from calibrated, low-noise rules;
4. report lifecycle/latest-report lookup if repeated consumer usage justifies it;
5. SARIF interoperability only after the normalized JSON contract remains stable across representative repositories.

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

## Post-calibration native rule backlog

This backlog is intentionally broader than the current implementation. It is not a commitment to implement every rule immediately. It is the preserved inventory of native rule families that should become the continuation plan after representative repository calibration.

The guiding rule is:

```text
Inspecting finds suspicious shape and evidence.
Canonization defines forbidden architecture.
Gating enforces deterministic project rules.
```

Generic PHP/SOLID rules may become native Inspecting findings directly when they are evidence-backed and low-noise. Symfony-aware rules must be treated more carefully: they should start as evidence collectors or advisory findings unless an explicit Canonization rule makes the architectural expectation project-specific and enforceable.

### Generic PHP/SOLID native rule families

#### Coupling and project graph

- high class fan-out;
- high class fan-in / dependency hub;
- namespace-level coupling concentration;
- cyclic class or namespace dependencies;
- deep inheritance chain;
- unstable dependency direction;
- excessive concrete dependencies where abstractions are available;
- central coordinator / god-service shape.

These rules must be calibrated against the existing dependency-graph artifact before thresholds are introduced.

#### SRP and cohesion

- low dependency cohesion: methods use disjoint constructor dependency groups;
- unrelated public method clusters;
- constructor dependencies used by only one method each;
- class touching too many external domain/namespace groups;
- mixed abstraction levels inside a class;
- class combining coordination, persistence, IO, and domain policy without a clear boundary.

These should extend the existing property-cohesion rule rather than replacing it.

#### OCP and polymorphism

- repeated `switch`/`match` on type or discriminator;
- repeated string-enum branching across methods or classes;
- repeated class-name comparisons;
- parallel conditional hierarchies;
- factory conditionals that grow with each new concrete type.

The existing repeated-`instanceof` rule is only the first OCP slice.

#### DIP and hidden dependencies

- direct construction of infrastructure dependencies inside high-level policy classes;
- static service access / global registry access;
- hidden dependency acquisition inside method bodies;
- hard-coded concrete implementations where a stable abstraction exists nearby;
- environment, filesystem, process, clock, random, or network access inside core/domain services without explicit dependency boundaries.

Value objects, DTOs, criteria objects, commands, and simple local data structures must not be treated as DIP violations merely because they are constructed with `new`.

#### Interface and contract quality

- interface with unrelated method groups;
- oversized interface already covered, but should gain cohesion evidence;
- implementation classes using only a small portion of an interface;
- marker interfaces without clear usage;
- abstract classes with little shared behavior;
- contracts exposing implementation-detail naming.

These findings are advisory by default because some project-level marker/contract patterns may be intentional.

#### Exception and error handling

- swallowed exceptions;
- broad `catch (Throwable)` without rethrow, logging, wrapping, or evidence;
- generic exception messages without operational/domain context;
- boolean failure return in non-trivial operations where explicit result/exception would preserve evidence;
- exception translation that loses previous exception/context.

#### Mutability and temporal coupling

- mutable service state;
- setter injection in services;
- public mutable properties in service-like classes;
- temporal coupling where callers must invoke methods in a hidden order;
- accumulator-like services that retain cross-call state.

Entity/DTO/value-object mutability must be classified separately from service mutability.

#### Testability and external effects

- hard-coded time/date/randomness;
- direct filesystem/process/network access in policy or domain services;
- direct static calls to external-effect helpers in core logic;
- hidden IO dependency not visible in constructor/method contract;
- code paths that cannot be exercised without real external resources.

These rules should be especially useful for AI-assisted remediation because they point to dependency extraction opportunities.

#### Suspicious dead or pass-through design

- unused private method clusters not already caught by PHPStan;
- protected methods with no subclass evidence;
- abstract extension points never used inside the project;
- pass-through wrappers with no policy, normalization, logging, or boundary value;
- classes that only forward to one dependency while adding no domain meaning.

Avoid duplicating PHPStan dead-code findings; focus on design-level shapes.

#### Naming and semantic-shape smells

- vague `Manager`, `Helper`, `Util`, or `Processor` god-name candidates;
- method names that encode multiple responsibilities such as `loadAndValidateAndPersistAndNotify`;
- class names that conflict with observed dependency/method shape.

This group is intentionally low priority and should remain advisory because naming rules are noisy.

### Symfony-aware evidence backlog

The first Symfony-aware layer should be evidence-first, not verdict-first. Do not encode generic internet/PhpStorm/Symfony best-practice opinions as project law inside Inspecting.

Safe evidence collectors:

- classify likely Controllers, Console Commands, Event Subscribers, Repositories, Doctrine entities, Form types, message handlers, and framework adapters;
- collect constructor dependency count and namespace groups per framework role;
- collect public action/handler method counts;
- collect EntityManager, Connection, ContainerInterface, RequestStack, filesystem, process, clock, network, and messenger usage concentration;
- collect framework-boundary crossings such as controller-to-persistence, command-to-HTTP, repository-to-service, or subscriber-to-unrelated-domain patterns;
- collect per-role fan-in/fan-out and coordination-load metrics.

Potential advisory Symfony-aware findings after calibration:

- controller high coordination load;
- command high coordination load;
- event subscriber multi-event responsibility;
- repository mixed persistence and unrelated domain/service work;
- handler with excessive external effects;
- service container coupling;
- EntityManager concentration outside intended persistence boundaries;
- form type with high domain/service coordination;
- framework adapter leaking into core/domain namespace;
- Doctrine entity with suspicious infrastructure dependency.

These names should describe observed shape, not declare architecture illegality. A message such as `Controller coordinates many unrelated dependencies` is acceptable. A message such as `Controller violates our architecture` belongs only to a canon-backed rule.

### Canonization boundary for Symfony rules

Symfony-aware findings become enforceable only when backed by explicit Canonization/Gating rules. Until then they are evidence or advisory design findings.

Examples of boundaries that belong to Canonization/Gating rather than generic Inspecting:

- whether controllers must be thin;
- whether repositories may contain domain logic;
- whether actions, handlers, or services are the preferred application-service shape;
- whether Doctrine entities may contain behavior;
- whether specific Symfony component roles are allowed in a namespace;
- whether a specific service naming or directory convention is mandatory.

Inspecting may collect the facts needed to evaluate these policies, but the policy itself must remain canon-owned.

### Implementation order after calibration

Preferred order after the current calibration pass:

1. calibrate project graph evidence and add fan-in/fan-out thresholds only where low-noise;
2. add dependency-cohesion SRP rules;
3. add `switch`/`match` discriminator OCP rules;
4. add hidden dependency and external-effect DIP/testability rules;
5. add interface-cohesion and partial-interface-usage evidence;
6. add exception/error-handling smells;
7. add Symfony-aware evidence collectors;
8. map selected Symfony evidence to Canonization-backed rules only after the canon is explicit;
9. keep naming/semantic-shape smells last unless calibration shows high value.

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

