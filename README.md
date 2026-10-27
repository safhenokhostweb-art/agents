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
  bootstrap.php      PSR-4 autoloader (Workspace\Agents\ → src/, …\Tests\ → tests/)
  bin/agent          CLI
  src/Kernel/        the protocol (contracts, bus, orchestrator, artifact store)
  src/Support/       read-only helpers (git, composer, globbing, hashing, semver)
  src/Agents/<Name>/ one directory per agent
  tests/             zero-dependency runner, unit + integration suites
  var/               runtime output (gitignored): artifacts/, bus/journal.jsonl
```

## Running

```bash
php agents/bin/agent list
php agents/bin/agent run deployment.readiness                       # whole workspace
php agents/bin/agent run deployment.readiness --payload='{"projects":["saas-platform"],"task_id":"WU-42"}'
php agents/bin/agent dispatch path/to/task.json                     # a Task contract file
php agents/bin/agent verify agents/var/artifacts/<kind>/<id>/artifact.json
php agents/bin/agent journal --correlation=<id>
php agents/tests/run.php [filter]
```

`run`/`dispatch` accept `--json` (machine output), `--verbose` (show info
findings) and `--strict`. Exit codes: `0` PASS, `1` FAILED or runtime error,
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
