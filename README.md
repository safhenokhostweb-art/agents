# Workspace Agents

Runtime agents that operate across the workspace projects (`saas-platform`,
`customer-site`, `deployment-system`) and hand prepared inputs to
`deployment-system`, which is itself a target (self-deployment). This file is
the canonical specification of the agent protocol. Coding-agent governance
(Claude Code, Codex, …) stays in `saas-platform/AGENTS.md`; this system is
software those coding agents run, not a replacement for that governance.

Zero dependencies: PHP 8.4 CLI and git. No `composer install` needed.

```
agents/
  agents.json        registry: which agents exist, what they subscribe to
  workspace.json     topology: project paths/roles, deployment-system, runtime dirs
  disk-janitor.json  Server Disk Janitor policy: disposable-file rules, the protection list, backup.archive sources
  disk-janitor.archive.example.json  template for the host-local backup.archive settings (key, remote store)
  bootstrap.php      PSR-4 autoloader (Workspace\Agents\ → src/, …\Tests\ → tests/)
  bin/agent          CLI
  src/Kernel/        the protocol (contracts, bus, orchestrator, artifact store)
  src/Support/       read-only helpers (git, composer, globbing, hashing, semver)
  src/Agents/<Name>/ one directory per agent (DependencyDeployment, SentinelVerifier, SentinelUiVerifier, ServerDiskJanitor)
  tests/             zero-dependency runner, unit + integration suites
  var/               runtime output (gitignored): artifacts/, bus/journal.jsonl
```

## Running

```bash
php agents/bin/agent list
php agents/bin/agent run deployment.readiness                       # whole workspace
php agents/bin/agent run deployment.readiness --payload='{"projects":["saas-platform"],"task_id":"WU-42"}'
php agents/bin/agent dispatch path/to/task.json                     # a Task contract file
php agents/bin/agent publish deployment.completed --payload-file=event.json   # an external event; its subscribers run
php agents/bin/agent verify agents/var/artifacts/<kind>/<id>/artifact.json
php agents/bin/agent journal --correlation=<id>
php agents/tests/run.php [filter]
```

`run`/`dispatch`/`publish` accept `--json` (machine output), `--verbose` (show info
findings), `--strict`, and `--dry-run` (sets payload `dry_run: true`, overriding
the payload; agents that do not declare `dry_run` reject it as
`INVALID_PAYLOAD`); `run` and `publish` take the payload inline
(`--payload`) or from a JSON file (`--payload-file`, easier than quoting JSON
in PowerShell). Exit codes: `0` PASS, `1` FAILED or runtime error,
`2` BLOCKED, `3` PASS but a verdict other than `READY` (`--strict` only).

### Pre-push gate

`php agents/bin/install-hooks` installs a `pre-push` shim into each project
in `workspace.json` (a foreign hook is never replaced without `--force`). On
every push from a project's primary checkout it runs `deployment.readiness`
scoped to that project via `bin/pre-push-gate` and:

- refuses the push on a `BLOCKED` verdict or if readiness cannot be evaluated;
- prints warnings without refusing. `CROSS_REPO_COUPLING` and stray-path
  warnings are normal on feature branches, so refusing them would block all
  work. Set `AGENTS_GATE_STRICT=1` to refuse any non-`READY` verdict;
- skips linked worktrees (release-prepare and scratch worktrees push from
  those, and their state is not the workspace's).

Bypassing it with `git push --no-verify` is an owner decision.

On this machine PHP is not on the Git-Bash PATH; use
`C:/laragon/bin/php/php-8.4.16-nts-Win32-vs17-x64/php.exe`.

Git from a web server (the deployment-system canvas): the agent finds git
via `AGENTS_GIT_BINARY`, then `PATH`, then standard locations (system Git
before Laragon's bundled 2.37). Every git call carries
`-c safe.directory=<project root>`, scoped to the root `workspace.json`
declares, so a `.git` owned by `BUILTIN\Administrators` is not rejected as
dubious when the global gitconfig is out of reach. That needs Git ≥ 2.38;
older Git still needs the global `safe.directory` entry. `NOT_A_REPOSITORY`
quotes git's own reason.

## Protocol

### Principles

1. **Agents are pure.** An agent reads the workspace and returns a `Result`.
   It never publishes to the bus, writes artifacts, or starts other agents
   itself. It *declares* events, artifacts and follow-up tasks; the
   `Orchestrator` performs them with correct provenance.
2. **Agents are read-only towards the projects.** No agent fetches, stages,
   commits, pushes, installs, or deploys unless its own section below says
   otherwise and the owner has approved that capability.
3. **Every failure is a value.** `Agent::handle()` never throws. Bad input or
   an unmet precondition → `BLOCKED`; a defect → `FAILED`. `AbstractAgent`
   enforces this.
4. **One correlation chain per run.** Everything caused by one dispatched
   task shares its `correlation_id`. `causation_id` names the direct cause.

### Task contract — `agents.task/v1`

```json
{
  "schema": "agents.task/v1",
  "id": "task-20261026T173245-80bd0929",
  "type": "deployment.readiness",
  "target": null,
  "payload": { "projects": ["saas-platform"] },
  "correlation_id": "task-20261026T173245-80bd0929",
  "causation_id": null,
  "hop": 0,
  "created_at": "2026-10-26T17:32:45+00:00"
}
```

- `type` is dotted lowercase (`domain.action`). Routed to the single agent
  whose `handles()` lists it. If several agents handle it, set `target`;
  otherwise it is rejected as `AMBIGUOUS_ROUTE`.
- Only `type` and `payload` are required when writing a task file by hand.
- Each agent declares a payload schema per type (`key => string|int|bool|array|list`,
  `?` prefix = optional). Undeclared keys are rejected, so a typo fails loudly.

### Result

| Status | Meaning |
|---|---|
| `PASS` | The agent completed. Read its findings and `data`: PASS does not mean "all clear". |
| `BLOCKED` | A named precondition failed (`findings[0].code`). Nothing was evaluated. |
| `FAILED` | Defect in the agent. Its output must not be trusted. |

These match the work-unit delivery workflow vocabulary. Findings carry
`code`, `severity` (`info` / `warning` / `blocker`), `subject`, `message`,
`evidence`, and an optional `remedy`.

### Message envelope — `agents.message/v1`

`id, topic, source, payload, correlation_id, causation_id, hop, occurred_at`.
Subscriptions match a topic exactly, by prefix (`dependency.*`), or `*`.

For each task the orchestrator publishes, in order:

| Topic | Source | When |
|---|---|---|
| `task.started` | orchestrator | the agent was resolved |
| `artifact.created` | orchestrator | per artifact, after it is sealed on disk (PASS only) |
| *agent's own events* | the agent | PASS only |
| `task.completed` | orchestrator | always, with status, finding counts, artifact refs |
| `task.rejected` | orchestrator | no agent could take the task, or the hop limit was hit |

`JournaledBus` appends every message to `var/bus/journal.jsonl` before
delivery, so other processes can replay a run.

### Subscriptions and loops

`agents.json` maps topics to tasks:

```json
{ "topic": "dependency.drift.detected", "task": "dependency.mirror-sync" }
```

A matching message becomes a new task for that agent (`payload` = message
payload, `hop` = message hop + 1) and runs in the same dispatch, breadth-first.
A subscription topic must be one a message can carry (dotted lowercase,
`a.*` prefix, or `*`); the registry refuses any other, since it could never
fire. Systems outside the runtime announce events with `bin/agent publish`
(`Orchestrator::publish()`, source `external`).
An agent is never triggered by its own messages, and tasks deeper than
`maxHops` (default 8) are rejected with `HOP_LIMIT`.

### Artifact envelope — `agents.artifact/v1`

```
var/artifacts/<kind>/<artifact_id>/artifact.json
var/artifacts/<kind>/<artifact_id>/<attachment>.json
```

The envelope records `producer` (agent id and version), the originating `task`,
`provenance` (exact commits and paths the payload was derived from),
`payload`, and the sha256 of each attachment. `integrity.digest` is sha256
over the canonical (key-sorted, compact) JSON of the envelope without its
`integrity` block, so one digest seals the artifact and its attachments.
`bin/agent verify` checks both.

## Adding an agent

1. Create `src/Agents/<Name>/<Name>Agent.php` extending `Kernel\AbstractAgent`:
   `id()` (kebab-case), `handles()` (task types), `payloadSchema()`, `perform()`.
   Throw `ProtocolException(reason, message)` for precondition failures.
2. Return `Result::pass($summary, findings:, artifacts:, events:, followUps:, data:)`.
   Use `$task->spawn()` for follow-ups so the correlation chain is kept.
3. Register it in `agents.json` (`id` must equal `id()`), with any `subscriptions`.
4. Add its task types and topics to the catalog below.
5. Add unit tests, plus integration tests on `tests/Support/WorkspaceFixture`
   (a disposable provider/consumer workspace with real git remotes).
6. `php agents/tests/run.php` must pass before the agent is enabled.

## Catalog

| Name | Kind | Owner | Payload |
|---|---|---|---|
| `deployment.readiness` | task | dependency-deployment | `projects?: list`, `task_id?: string`, `summary?: string` |
| `release.requested` | topic (subscribed) | → `deployment.readiness` | same as the task payload |
| `deployment.readiness.reported` | topic | dependency-deployment | `verdict`, `counts`, `projects`, `drafts` |
| `dependency.drift.detected` | topic | dependency-deployment | `packages: [{package, consumer, provider, modified, missing, extra}]` |
| `sentinel.verify` | task | sentinel-verifier | see [Sentinel Verifier](#sentinel-verifier-sentinel-verifier) |
| `deployment.completed` | topic (subscribed) | → `sentinel.verify` | same as the task payload |
| `sentinel.audit-requested` | topic (subscribed) | → `sentinel.verify` | same as the task payload |
| `sentinel.verification.passed` / `.failed` | topics | sentinel-verifier | `project_key`, `environment`, `release_id`, `commit_sha`, `deployment_id`, `status`, `sentinel_verdict`, `verdict`, `evidence_hash`, `snapshot_hash`, `non_conformant_files`, `reasons`, `reaudit_of` |
| `sentinel.ui-audit` | task | sentinel-ui-verifier | see [Sentinel UI Verifier](#sentinel-ui-verifier-sentinel-ui-verifier) |
| `sentinel.verification.passed` | topic (subscribed) | → `sentinel.ui-audit` | the integrity event; `project_key` + `environment` pick the target |
| `sentinel.ui-audit-requested` | topic (subscribed) | → `sentinel.ui-audit` | same as the task payload |
| `sentinel.ui-audit.passed` / `.failed` | topics | sentinel-ui-verifier | `project`, `environment`, `base_url`, `release_id?`, `commit_sha?`, `deployment_id?`, `status`, `verdict`, `counts`, `tasks`, `report: {json, markdown}` |
| `disk.clean` | task | server-disk-janitor | `dry_run?: bool` (default `true`), `policy?: string`, `rules?: list` |
| `maintenance.disk-clean-requested` | topic (subscribed) | → `disk.clean` | same as the task payload |
| `deployment.completed` | topic (subscribed) | → `disk.clean` | foreign keys ignored, so always a dry-run audit |
| `disk.cleanup.reported` | topic | server-disk-janitor | `run_id`, `dry_run`, `status`, `verdict`, `summary`, `report: {json, markdown}` |
| `backup.archive` | task | server-disk-janitor | `dry_run?: bool` (default `true`), `policy?: string`, `sources?: list` |
| `maintenance.backup-archive-requested` | topic (subscribed) | → `backup.archive` | same as the task payload |
| `backup.archive.reported` | topic | server-disk-janitor | `run_id`, `dry_run`, `status`, `verdict`, `summary`, `report: {json, markdown}` |
| `task.*`, `artifact.created` | topics | orchestrator | see lifecycle table |

## Dependency & Deployment Agent (`dependency-deployment`)

Monitors coordination between the workspace projects and prepares the inputs
that deployment-system's `release-prepare.php` consumes.

**Boundary.** Under `saas-platform/docs/quality/WORK_UNIT_DELIVERY_WORKFLOW.md`
the deployment artifact is *server-managed-exact-commit*: deployment-system
builds it from the pushed release commit. This agent never builds a competing
archive, never runs `release-prepare prepare`, and never fetches, stages,
commits, pushes, or deploys. Its artifact is a sealed readiness report plus
`release-task.<project>.json` drafts that conform exactly to
`deployment-system/.deployment/release-task.json.example`.
`release-prepare check` remains the release gate.

**Scope.** Dependency monitoring always covers every project in
`workspace.json`; `payload.projects` only limits which projects get release
drafts. Scoping never hides cross-project coupling.

**Targets.** A project is a target when `workspace.json` declares it under
`projects` (`agents.json` registers agents, not projects). Each entry has a
`path`, a display `role`, and an optional `composer` directory, relative to
the project, holding its `composer.json` when that is not the git root.
Production branch and remote always come from the project's own
`.deployment/release-policy.json`.

| Project | Role | Composer | Production ref |
|---|---|---|---|
| `saas-platform` | provider | `composer.json` | `origin/main` |
| `customer-site` | consumer | `composer.json` | `origin/main` |
| `deployment-system` | deployment | `service/composer.json` | `origin/master` |

`deployment-system` releases itself: its drafts become
`release-task.deployment-system.json`, and the `release-prepare.php` that
checks them is in its own tree. It shares no Composer packages with the other
projects, so it is last in `release_order` and its findings never change
theirs. `bin/install-hooks` also installs the pre-push gate into it.

| Code | Severity | Meaning |
|---|---|---|
| `LOCK_OUT_OF_SYNC` | blocker | `composer.json` changed without `composer.lock` (Composer content-hash recomputed exactly) |
| `CONSTRAINT_UNSATISFIED` | blocker | a shared package requires a version the installing project does not lock |
| `UNRESOLVABLE_SHARED_DEPENDENCY` | blocker | a provider package is required with no path repository able to install it |
| `PATH_REPOSITORY_MISSING` | blocker | a `path` repository points at a directory that does not exist |
| `PACKAGE_BOUNDARY` | blocker | a policy `dependency_rules` pair is split across releases |
| `MIGRATION_REVIEW_REQUIRED` | blocker | a changed migration matches a policy review pattern and has no matching content-bound owner review in `migration_policy.reviewed` (release-prepare fails closed) |
| `RELEASE_POLICY_INVALID` | blocker | the release policy lacks fields release-prepare requires |
| `MIRROR_DRIFT` | warning | a consumer's `symlink:false` vendor copy differs byte-wise from the package source |
| `SHARED_PACKAGE_UNCOMMITTED` | warning | the provider's package source has uncommitted changes |
| `CROSS_REPO_COUPLING` | warning | package files differ from provider production: release the provider first |
| `BRANCH_BEHIND_PRODUCTION` | warning | the branch lacks production commits |
| `PATHS_OUTSIDE_RELEASE_POLICY` | warning | changed paths no release may contain (they would cause `MIXED WORKTREE`) |
| `PACKAGE_NOT_INSTALLED`, `DEPENDENCY_NOT_LOCKED`, `LOCK_MISSING`, `RELEASE_POLICY_MISSING`, `BASE_UNRESOLVED`, `HANDOFF_TOOL_MISSING`, `PATH_REPOSITORY_INVALID` | warning | the named input is missing, so that check cannot run |
| `RECOMMEND_GIT_DEPENDENCY`, `NO_RELEASE_CANDIDATE`, `CONSTRAINT_UNVERIFIED` | info | advisory |

Verdict: any blocker → `BLOCKED`, else any warning → `ATTENTION`, else `READY`.

Draft `owned_paths` are the branch's own delta (from its merge base with the
production ref) filtered exactly as release-prepare does: runtime exclusions
first, then `allowed_release_paths`. It is a **proposal**: narrow it to
the one work unit before installing it as `.deployment/release-task.json` in
the isolated release worktree. `risk_flags` carry the blocking codes and
`CROSS_REPO_COUPLING` into the Hub's release metadata.

**Limits.** The production baseline is the local `origin/<branch>` ref (the
agent never fetches; provenance records the ref used). Constraint checks
cover the Composer subset in `Support/VersionConstraint.php` and report
`CONSTRAINT_UNVERIFIED` rather than guess. A full workspace run takes about
15 s, mostly hashing mirrored package trees.

## Sentinel Verifier (`sentinel-verifier`)

Audits one deployment's proof chain — commit → artifact → Hub release →
Server Agent → active runtime → health — from explicit evidence files and
seals the result as a `sentinel` artifact.

**Boundary.** The core (`EvidenceContract`, `RuntimeEvidenceContract`,
`EvidenceVerifier`, `FileManifestComparison`) is a port of
deployment-system's `service/app/Services/Sentinel`. The Hub keeps its own
copy for the deployment page and `sentinel_verification_runs`, and
production Hub hosts have no `agents` checkout, so the Hub classes were not
removed (that would need an ADR, Constitution §12).
`tests/Integration/SentinelParityTest` runs both on the same inputs —
verdicts, every check detail, evidence hashes, contract refusals and the
Server Agent's file-manifest hash — so they cannot drift silently. Per
deployment-system's `ADR-SENTINEL-BOUNDARIES-DRAFT.md` the agent is a
read-only consumer of explicit evidence: no Hub database, no network, no
health probe, never follows a link or junction out of a release, writes only
its artifact. Missing or uninterpretable evidence is `UNKNOWN`, never a pass.

**Inputs** (`sentinel.verify` payload; paths are relative to the workspace
root or absolute, `.json` only, at most 16 MiB):

| Key | Meaning |
|---|---|
| `evidence` | `sentinel.v1` evidence document as the Hub collects it; re-verified with the Hub's rules |
| `snapshot` | `sentinel.runtime.v1` Server Agent runtime snapshot |
| `expected_manifest` | the artifact's `.deployment/file-manifest.json` (seal re-checked) |
| `observed_manifest` *or* `release_dir` | the active release: another manifest, or the release directory re-hashed read-only (≤ 50 000 files / 1 GiB) |
| `exclude` | release-relative globs for runtime-owned paths (`storage/**`, …); `.deployment/runtime-marker` is always excluded |
| `project_key`, `environment`, `release_id`, `deployment_id`, `commit_sha`, `artifact_checksum_sha256`, `agent_id` | the expectation: what the deployment *should* be. Never inferred from the evidence; without `release_id` nothing is attributable |
| `file_verification` | enable the Hub's feature-flagged file-level checks (default `false`, as on the Hub) |
| `min_agent_version` | fail snapshots from an older Server Agent, e.g. `1.4.3` (no floor by default) |
| `max_snapshot_age_seconds` | snapshot freshness window (default 300, the Hub's) — measured against the evidence's `verified_at`, or now without evidence |

At least one of `evidence`/`snapshot` is required (`EVIDENCE_MISSING`
otherwise). A malformed request is `BLOCKED` and emits no verification event.

**Manual run** (empty payload on a task not derived from an event, e.g. the
dashboard's Run button): the agent re-audits the evidence sealed into the
newest intact `sentinel` artifact — its `evidence.json` /
`runtime-snapshot.json` and the `expectation` that audit recorded. Artifacts
whose envelope or attachment digests no longer verify, or that another agent
produced, are skipped (at most the newest 50 are inspected), and the bytes
read must still equal the sealed digests (`INPUT_CHANGED` otherwise). File
comparison is not repeated. The report, event and `data` carry `reaudit_of`,
the summary starts `Re-audit of <artifact_id>:` and an info
`SENTINEL_REAUDIT` finding says it is not an audit of a new deployment. With
no intact artifact the task passes with `data.status` `NO_EVIDENCE`, verdict
`ATTENTION`, one `SENTINEL_NO_EVIDENCE` warning whose remedy says how to
supply evidence, and no artifact or verification event. An event — such as
`deployment.completed` — that arrives without evidence is still
`EVIDENCE_MISSING`, never answered from an older seal.

**Checks**, in four sections, each `pass` / `fail` / `unknown`:

- `evidence.*` — exactly the Hub verifier's checks (commit, artifact checksum,
  release and agent identity, `releases/{deployment_id}` path, runtime
  marker, health, recorded checks, `verified_at`, alignment).
- `snapshot.*` — version, completeness, optional agent-version floor,
  freshness, release path, **deployment marker** (`release:{deployment_id}|commit:{sha}`
  must name the reported deployment and the release-manifest commit),
  attribution, and with `file_verification` the agent's on-disk file
  observation.
- `agreement.evidence_snapshot_agreement` — the evidence and the snapshot
  must describe the same runtime observation.
- `files.*` — the expected manifest must be the one the evidence or snapshot
  records (`manifest_binding`), then a per-file comparison: `MISSING` /
  `HASH_MISMATCH` fail; `EXTRA` and `UNREADABLE` are unknown until an
  exclusion covers them.

Verdict: any `fail` → `FAILED`, else any `unknown` → `UNKNOWN`, else
`VERIFIED`. Findings: `SENTINEL_CHECK_FAILED` (blocker, subject
`section.check`), `SENTINEL_CHECK_UNKNOWN` (warning), `SENTINEL_VERIFIED`
(info). So `data.verdict` and the dashboard read `READY` / `ATTENTION` /
`BLOCKED`, and `--strict` exits `3` unless verified; `data.sentinel_verdict`
holds `VERIFIED` / `FAILED` / `UNKNOWN`, and `data.status` the binary audit
status: `PASSED` only when `VERIFIED`, otherwise `FAILED` (`UNKNOWN` fails
closed). Event: `sentinel.verification.passed` when `PASSED`, otherwise
`sentinel.verification.failed`.

**Artifact** `var/artifacts/sentinel/audit-<timestamp>-<random>/artifact.json`,
payload `sentinel.audit-report/v1`: `status`, `sentinel_verdict`, `verdict`,
`checked_at`, `reference_time`, `target` (project, environment, release,
commit, deployment), `expectation`, `evidence_hash` (canonical
`sentinel.v1` hash — equal to the Hub's —, snapshot hash, and sha256 of every
input file), per-section checks, `runtime_evidence` (snapshot verdict,
`snapshot_status`, agent version, deployment-marker check, snapshot hash;
`null` without a snapshot), `files` counts and manifest hashes,
`non_conformant_files` (path, outcome, expected/observed size and sha256;
first 1000, `non_conformant_total` is exact), `reasons`, `findings`. The
canonical evidence and snapshot are sealed beside it as `evidence.json` and
`runtime-snapshot.json`. Credential-like paths (`.env*`, `*.pem`, `*.key`,
`auth.json`, `*secret*`, `*token*`, …) are reported as `[redacted:<hash>]`
without hashes; a rejected document's message never echoes a secret.

**Dashboard.** The registry entry's `subscriptions` are the node's input port
(`deployment.completed`, `sentinel.audit-requested`); `produces` declares its
output topics and artifact kind. The canvas keeps both when it saves. Topic
segments are `[a-z0-9-]` (`Task::NAME_PATTERN`), so the audit request topic is
`sentinel.audit-requested`; `sentinel.audit_requested` is rejected at
registration because no message could ever carry it.

**Local test.**

```powershell
# 1. Manual run: re-audit the newest intact seal, or NO_EVIDENCE (ATTENTION) without one
php agents/bin/agent run sentinel.verify --target=sentinel-verifier

# 2. A real audit: task file with payload paths (see the table above).
#    tests/Support/SentinelFixture/ is one coherent static deployment (VERIFIED);
#    its payload paths are workspace-relative, so run it from the agents folder:
#    php bin/agent run sentinel.verify --target=sentinel-verifier --payload-file=tests/Support/SentinelFixture/valid-payload.json
php agents/bin/agent run sentinel.verify --target=sentinel-verifier --payload-file=audit.json --verbose

# 3. As the deployment pipeline would trigger it
php agents/bin/agent publish deployment.completed --payload-file=audit.json

# 4. Check the sealed report
php agents/bin/agent verify agents/var/artifacts/sentinel/audit-<timestamp>-<random>/artifact.json
php agents/tests/run.php Sentinel
```

**Limits.** The Hub's monotonic snapshot `sequence` watermark and HMAC
signature are checked at ingest and are not available offline, so a
snapshot file is trusted only as far as its contents and its binding to the
evidence. The deployment-system repository's Server Agent declares
`AGENT_VERSION = '1.4.2'`; pass `min_agent_version` to require a newer one.

## Sentinel UI Verifier (`sentinel-ui-verifier`)

Audits the live UI of a deployed site in a real browser and reports what
must change, as tasks. Owner-approved on 2026-11-02 (saas-platform ADR-077).

**Chain.** It subscribes to `sentinel.verification.passed`: once the
integrity audit has proved the deployment is the released one, the UI audit
runs against that project and environment. `sentinel.ui-audit-requested`
and `bin/agent run sentinel.ui-audit` start it by hand. A re-audit event
(`reaudit_of` set) is skipped, because nothing new was deployed.

**Boundary.** It runs saas-platform's `tests/sentinel` Playwright suite
(`workspace.json` → `sentinel_ui.runner`) with the target in the
environment. The suite only loads pages; it never clicks to change state,
types or saves. It never writes screenshot baselines
(`SENTINEL_UPDATE_BASELINES=0`). It writes only into its output
directory (`sentinel_ui.output`, default `agents/var/sentinel-ui/<run>`).
Unlike `sentinel-verifier`, it does make network requests, but only
browser GETs to the target origin.

**Inputs** (`sentinel.ui-audit` payload):

| Key | Meaning |
|---|---|
| `project` (or `project_key`) | the project whose site to audit; required |
| `environment` | key into `sentinel_ui.targets.<project>`; default `local` |
| `base_url` | an explicit bare http(s) origin; overrides the target |
| `pages`, `widths` | same-origin paths and viewport widths; defaults from `sentinel_ui.pages` / `sentinel_ui.widths` |
| `visual` | add the screenshot comparison against `tests/sentinel/__baselines__/<host>/` |
| `storage_state`, `editor_paths` | a signed-in Playwright storage state and editor paths for the read-only Canvas editor audit |
| `max_tasks` | findings per scan, 3–5 (default `sentinel_ui.max_tasks`, 5) |
| `force` | ignore the state fingerprint and the cooldown once (a deliberate manual review) |
| `release_id`, `commit_sha`, `deployment_id`, … | forwarded from the integrity event and recorded in the target |

**What the suite checks** (page × width), each a finding with a task text:

- page horizontal overflow and the widgets causing it, plus content
  sticking out of its widget box;
- the widget feature profiles (`tests/sentinel/widget-profiles.json`,
  generated from the registry, e.g. header logo/menu/actions, footer links,
  CTAs with real destinations) and dead `href="#"` links;
- broken images, failed same-origin requests, console and script errors,
  clipped text, touch targets under 24 px on phones;
- `dir="rtl"`, Vazirmatn loaded, and infinite animations under
  `prefers-reduced-motion`;
- optionally, the editor page-tab strip, and visual diffs.

**Guardrails** (owner, 2026-11-02), so the agent can never loop on its
own output:

- **Read-only advisor.** It reports tasks as Markdown and JSON. It never
  changes code, content or screenshot baselines.
- **Capped output.** At most `max_tasks` (3–5, default 5) findings per
  scan, most urgent first: P1 = blocker, P2 = warning, P3 = UX friction.
  The rest stay in the sealed report (`backlog_task_keys`) behind one
  `SENTINEL_UI_BACKLOG` note.
- **State fingerprint.** The fingerprint is a sha256 over the target,
  pages, widths, visual/editor options, the deployed `commit_sha` (from
  the integrity event), the suite commit and the hash of
  `widget-profiles.json` (the schema state). Every task has a stable key.
  While the fingerprint matches the newest intact `sentinel-ui` artifact
  for the same target:
  - tasks already reported are not reported again (one
    `SENTINEL_UI_ALREADY_REPORTED` note). A genuinely new task still
    surfaces;
  - a rerun inside `cooldown_seconds` (default 600) does not start the
    browser at all: `data.status` is `UNCHANGED`, and no artifact or event
    is produced.

  A new deployment, suite or schema is a new state, and everything is
  reported again. The agent's own events have no subscribers, and the
  kernel never feeds an agent its own messages.
- **Widget friction metric.** `widget-profiles.json` → `friction` lists,
  from the registry schema, the widgets missing an essential author
  control: carousels without drag/swipe physics, navigation dots, arrows or
  autoplay. Controls the Style tab gives every block (per-breakpoint
  padding, corner radius) count as provided. A friction widget on an
  audited page becomes a `UX_FRICTION` task (P3). The full inventory is in
  the report (`friction_inventory`).

**Result.** The verdict is recomputed from the report's findings (any
blocker → `BLOCKED`, any warning → `ATTENTION`, else `READY`), and
`data.status` is `PASSED` without blockers, `FAILED` otherwise. Each
reported task becomes one finding (code, severity, widget, priority, task key,
the pages and widths, and the change as `remedy`).
Preconditions are `BLOCKED`: `NOT_CONFIGURED`, `PROJECT_REQUIRED`,
`TARGET_UNKNOWN`, `TARGET_INVALID`, `STORAGE_STATE_MISSING`,
`RUNNER_MISSING`, `RUNNER_NO_REPORT` (with the runner's stderr tail).

**Artifact** `var/artifacts/sentinel-ui/ui-audit-<timestamp>-<random>/artifact.json`,
payload `sentinel.ui-audit/v1`: `status`, `verdict`, `target`, `counts`,
`tests`, `pages`, `widths`, `tasks`, `runner_exit_code`, `report` paths.
Provenance records the suite project's commit and command. The full
`sentinel.ui-report/v1` is sealed beside it as `report.json`. The Markdown
defect report is in the output directory.

**Local test.**

```powershell
php agents/bin/agent run sentinel.ui-audit --payload='{"project":"customer-site","environment":"local"}' --verbose
php agents/bin/agent run sentinel.ui-audit --payload='{"project":"customer-site","environment":"production","widths":[390,1280]}'
php agents/bin/agent publish sentinel.ui-audit-requested --payload='{"project":"saas-platform"}'
php agents/tests/run.php SentinelUi
```

`PLAYWRIGHT_CHROME_PATH` is passed through to the suite when Playwright's own
browser is not installed.

## Server Disk Janitor (`server-disk-janitor`)

Audits the host for disposable files and, only when asked explicitly,
deletes them, recording every entry before it is removed.

**Boundary.** This is the one agent that deletes, and the one that writes
outside `var/`: an exception to principles 1 and 2 that the owner approved
on 2026-10-31. The exception is bounded:

- **Allowlist.** Only paths a rule in `disk-janitor.json` selects are
  considered. Every rule states its reason, and no rule may scan `/`,
  `/etc`, `/usr`, `/var/lib`, `/proc`, … (`POLICY_INVALID`).
- **Protection list wins.** `ProtectionGuard` vetoes, by path only and
  without opening the file: environment files (`.env`, `.env.*`, `*.env*`),
  databases (`*.sqlite*`, `*.db`, InnoDB/MyISAM files, `*.rdb`), secrets
  (`*.pem`, `*.key`, SSH keys, `auth.json`, `*secret*`, `*credential*`),
  `.git`/`.ssh` path segments, files git tracks (if git cannot answer, the
  whole work tree is protected), media (`storage/app/public`,
  `public/storage`, `uploads`, `media`), and any file of a workspace
  project outside its `projects.disposable` subtrees. A directory selected as
  a whole (a *unit*) is skipped entirely if any file in it is protected, is
  a link, sits on another filesystem, or could not be listed. It is never
  emptied partially.
- **Dry-run by default.** Deletion requires `"dry_run": false` in the
  payload; `--dry-run` on the CLI forces a dry run whatever the payload
  says. The canvas Run button sends an empty payload, so it only audits.
- **Write-ahead audit trail.** Before the first deletion, the full plan
  (every path, exact size, mtime/ctime and reason, outcome `pending`) is
  written atomically to the report. If that write fails, the run is
  `BLOCKED` and nothing is deleted.
- **Stale plans are not trusted.** At deletion time each entry must still
  have the planned inode, size and mtime and must not be a link. A unit
  stops at the first file that is newer than planned or has become
  protected. Such entries end as `changed` or `partial`, and nothing else
  is retried.

The walk never follows symlinks or junctions, never crosses filesystems,
and stops at `limits` (`max_entries`, `max_candidates`, `max_depth`). On
Windows a POSIX root such as `/tmp` is reported `missing` rather than
resolved against `C:\`.

**Rules** (`disk-janitor.json`; roots are workspace-relative or absolute and
may contain `*`; ages are measured from mtime):

| Rule | Kind | Selects |
|---|---|---|
| `laravel-logs` | files | `*.log` / `*.log.*` in every project's `storage/logs` not written for 14 days |
| `nginx-logs` | files | rotated/compressed Nginx logs (`*.log.*`, `*.gz`) older than 14 days |
| `system-logs` | files | rotated/compressed `/var/log` files (`*.gz`, `*.0`, `*.1`, `*.old`) older than 14 days; journald and installer logs excluded |
| `laravel-temp` | files | `storage/app/tmp/**` older than 1 day |
| `laravel-sessions` | files | file sessions idle for over 1 day (`SESSION_LIFETIME` on the host is 120 min) |
| `laravel-cache` | expired-cache | file-cache entries whose own 10-digit expiry prefix has passed (`9999999999` = forever, kept) |
| `abandoned-tmp` | entries | top-level `/tmp` files and directories untouched for 14 days (systemd, tmux, PHP, MySQL and hidden entries excluded) |
| `abandoned-root-builds` | entries | `/root/deployment-agent-runtime-20*`, `deployment-system-bootstrap-20*`, `deployment-system-staging-20*` untouched for 30 days |
| `deploy-backups` | entries | `/var/www/*.backup-*` beyond the 2 newest per project, older than 7 days |
| `hub-service-backups` | entries | `deployment-system/service.{backup,failed}-*` beyond the newest of each, older than 7 days |
| `db-dump-backups` | entries | `*.sql(.gz)` dumps beyond the 7 newest per series, older than 14 days |

Kinds: `files` selects regular files below the root by relative glob;
`expired-cache` also requires an expired Laravel cache prefix; `entries`
selects top-level names of the root (directories as units, aged by their
newest file), optionally with `retention` (`group_pattern` regex with
`(?<group>…)` and optional `(?<stamp>…)` for ordering; `keep_newest ≥ 1`, so
retention never deletes the newest copy). The first rule that selects a path
owns it.

Deployment backups hold a copy of the project's `.env` and media, so the
protection list keeps them. The report lists them as protected
(`PROTECTED_ENVIRONMENT (.env)`) with their size. Removing them is an owner
decision outside this agent.

**Reports.** `reports` in the policy:
`deployment-system/service/storage/logs/janitor-reports/<YYYY-MM-DD>/<run_id>.{json,md}`,
the Hub's Laravel `storage/logs` (on the server
`/var/www/deployment-system/service/storage/logs/janitor-reports/`; the
deployment-system root has no `storage/`). Only the report directory and its
date folder are created: a missing parent is `REPORT_DIR_MISSING`. The JSON
(`disk-janitor.report/v1`) has `status` (`dry-run` / `planned` /
`completed`), `operator`, policy file and sha256, `summary`
(`bytes_planned`, `bytes_freed`, `entries_*`, `files_*`, `bytes_protected`,
`by_rule`), `roots` with scan status, `entries` (`path`, `type`, `bytes`,
`files`, `modified_at`, `changed_at`, `created_at`, `rule`, `reason`,
`outcome`, `error`), `protected` (with `protection`), and `retained`.
`created_at` is the birth time on Windows. POSIX `stat()` has none, so it is
`null` there and `changed_at` is the inode change time. The same report is
sealed as `var/artifacts/disk-janitor/cleanup-<timestamp>-<random>/`
(`report.json` attachment).

**Findings.** `DISK_CLEANUP_PLANNED` / `DISK_CLEANUP_APPLIED`,
`DISK_ENTRY_PROTECTED`, `DISK_ENTRY_CHANGED`, `DISK_ROOT_MISSING` (info);
`DISK_CLEANUP_FAILED`, `DISK_ROOT_UNREADABLE`, `DISK_SCAN_LIMIT` (warning,
so `ATTENTION`). Blocked: `POLICY_MISSING`, `POLICY_INVALID`,
`INVALID_PAYLOAD`, `REPORT_DIR_MISSING`, `REPORT_UNWRITABLE`. Direct runs
reject unknown payload keys; subscription-derived tasks ignore the event's
foreign keys (`deployment.completed` carries Sentinel's payload), so they
audit unless the event itself says `"dry_run": false`.

**Running.**

```bash
php agents/bin/agent run disk.clean --verbose                         # dry run (default)
php agents/bin/agent run disk.clean --payload='{"rules":["laravel-logs"]}' --dry-run
php agents/bin/agent run disk.clean --payload='{"dry_run":false}'      # delete
php agents/bin/agent publish maintenance.disk-clean-requested --payload='{"dry_run":false}'
```

Run it as a user that can read every root. The Hub canvas runs as
`www-data`, which cannot list `/root`, and reports that as
`DISK_ROOT_UNREADABLE`. For periodic cleaning, the owner can add a root
crontab entry that publishes the maintenance topic. None is installed:

```cron
30 3 * * * cd /var/www/agents && php bin/agent publish maintenance.disk-clean-requested --payload='{"dry_run":false}' --json > /dev/null 2>&1
```

**Limits.** Ages are mtime-based. A process that keeps a deleted file open
holds its space until it reopens the file, so live logs are excluded by age
and only rotated system/Nginx logs are selected. Sizes are apparent bytes
(`st_size`), not allocated blocks.

### Backup archive (`backup.archive`)

Moves old backup directories off the server, encrypted at the source, and
deletes the local copy only after the destination has proved it holds the
exact bytes. The owner approved this on 2026-10-31. It deliberately removes
directories that hold `.env` files and databases, which `disk.clean` never
does, so the deletion is gated on an encrypted, verified off-site copy.

**Sources** (`archive.sources` in `disk-janitor.json`, validated like
`entries` rules): `deploy-backups`, which are `/var/www/{saas-platform,customer-site}.backup-*`
older than 14 days, beyond the 2 newest per project (those stay local for
rollback); and `bootstrap-dirs`, which are `/root/deployment-system-{bootstrap,staging}-20*`
older than 14 days. A source is skipped, and reported, if its tree contains
a `.git` directory (possibly an active repository), a mount point or an
unreadable directory, or if `ProtectionGuard` protects the source directory
itself.

**Pipeline.**

1. *Plan.* Each source gets a tree fingerprint: the lstat of every entry,
   with links never followed.
2. *Preflight*, with the same checks in a dry run and a real run:
   - the settings are complete;
   - `tar` and `gpg`/`age` (and `zstd`) are present;
   - every recipient is a public key. A key file containing a private key is
     refused, since a server able to decrypt its own backups is not
     zero-knowledge;
   - the remote store answers a probe for a non-existent object;
   - staging has room for the largest source.

   A real run with any failing check is `BLOCKED` (`ARCHIVE_PREFLIGHT_FAILED`)
   and touches nothing.
3. *Phase A*, for each source:
   - re-check the fingerprint;
   - stream `tar` → gzip (PHP zlib) or `zstd` → `gpg --recipient-file` / `age -R`
     into staging. The plaintext tar never touches the disk, and gpg uses a
     throwaway home, so the server keyring is never consulted;
   - re-check the fingerprint (the tree must not change while it is read);
   - compute the SHA-256 and MD5 of the encrypted file;
   - refuse to overwrite an existing object, upload, then **handshake**.
     The destination must report the same size and a digest *it computed*
     that equals ours. That is the SHA-256 when it offers one: S3
     `x-amz-checksum-sha256`, or a read-back for the filesystem driver.
     Otherwise it is an MD5 ETag. No digest means the copy is unverified;
   - upload and verify a `.manifest.json` the same way (source name, size,
     tree fingerprint, recipients' fingerprints, archive SHA-256; no secrets).
4. *Phase B*, only when **every** source passed phase A: delete each source,
   provided its fingerprint still equals the archived one. Links are removed,
   never followed.

Any failure in phase A aborts the run before phase B, so nothing is deleted
(`ARCHIVE_ABORTED`, verdict `BLOCKED`). Objects already uploaded stay at the
destination under the run id. The staging directory is emptied on every
outcome.

**Settings** are host-local and never committed. The file is named by
`archive.settings` (`/etc/disk-janitor/archive.json`, overridable with
`JANITOR_ARCHIVE_SETTINGS`); start from `disk-janitor.archive.example.json`.
Single keys can be overridden with `JANITOR_ARCHIVE_*` variables (see
`ArchiveSettings::ENVIRONMENT`). A settings file holding `secret_key` must be
mode `600`. Remote drivers:

- `s3`: any SigV4 endpoint (AWS, MinIO, ArvanCloud, …), single streamed PUT
  of up to 5 GiB (`max_archive_bytes`). The signed `x-amz-content-sha256`,
  `Content-MD5` and `x-amz-checksum-sha256` make the server reject a body
  that differs from what was hashed. Set `checksum_header: false` for a
  provider that rejects the checksum header; verification then relies on the
  MD5 ETag.
- `filesystem`: a mounted off-site volume (NFS, sshfs, rclone). It writes to
  a temp file then renames, and verifies by reading the stored copy back.

**Report.** `janitor-reports/<date>/archive-<timestamp>-<random>.{json,md}`
(`disk-janitor.archive-report/v1`), rewritten after every step. It holds the
pipeline, the redacted settings and remote, the recipients' fingerprints, the
preflight checks, and per source: `path`, `original_bytes`, `files`,
`fingerprint`, `status` (`would-archive`, `archived`, `uploaded`, `verified`,
`deleted`, `failed`, `not-started`), `archive` (key, compressed and encrypted
bytes, `sha256`, `md5`), `remote` (location, transfer, `verification`),
`manifest`, `deletion` (bytes freed) and `error` (`step`, `message`). It is
sealed as `var/artifacts/backup-archive/archive-*/`.

```bash
php agents/bin/agent run backup.archive --target=server-disk-janitor --verbose          # dry run + preflight
php agents/bin/agent run backup.archive --payload='{"dry_run":false,"sources":["bootstrap-dirs"]}'
```

Restore: download the object, then run
`gpg --decrypt <object>.tar.gz.gpg | tar -xz` (or `zstd -d` for `.tar.zst.gpg`)
on the machine that holds the private key, and compare the object's SHA-256
with the manifest.
