# AppGraph

AppGraph is a local-first Laravel package that scans an application into a structured JSON knowledge graph and lets AI agents (and humans) query it: which routes write to a table, what breaks if a column changes, who listens to an event — without loading the whole graph into context.

Deterministic scanning covers:

- Laravel routes and controller methods
- Eloquent models, inferred model-to-table relationships, and model-to-model relationships
- Database tables, columns, indexes, and foreign keys (schema-dump or live connection)
- Method-level `calls` edges and `reads`/`writes` data-flow edges with confidence scores
- Constructor-injected property calls, declared return types, booted service-container bindings, container helpers, fluent Eloquent/collection chains, and explicit resolution diagnostics
- Resolved route middleware pipelines, plus statically recoverable controller middleware, bridged to executable `handle()`/`__invoke()` methods
- FormRequest lifecycle hooks and inline request/controller/validator validation rules
- Events, jobs, ordered `Bus::chain()` jobs, listeners, and observers, reconciled with Laravel's already-booted Event and Bus registries and bridged to the methods Laravel executes
- Cache, filesystem, and Laravel HTTP client side effects
- Policy authorization calls, frontend named/literal route consumers, and tests that exercise routes

## Install

Requires PHP 8.4+ and Laravel 12+.

```bash
composer config repositories.appgraph vcs https://github.com/drfraker/appgraph
composer require --dev drfraker/appgraph:^0.1
php artisan appgraph:install
```

The repository is public, so the same commands work for any user and on any machine.
If AppGraph is later published to Packagist, the `composer config repositories.appgraph`
step can be omitted.

Laravel auto-discovers the package service provider. The installer:

- registers the bundled MCP server in `.codex/config.toml` and `.mcp.json` without removing other servers;
- adds a managed AppGraph workflow to existing `AGENTS.md` and `CLAUDE.md` files (or creates `AGENTS.md`);
- adds a managed `/storage/appgraph/` rule to `.gitignore` so generated topology stays local;
- builds the initial graph; and
- tells you to restart the AI client so it loads the project tools and instructions.

Use `--client=codex` or `--client=claude` to configure only one client,
`--no-guidelines` to leave agent instructions untouched, `--no-gitignore` to manage
generated artifacts yourself, or `--no-scan` to defer the initial scan.

To trial an unpublished checkout as a real local customer:

```bash
composer config repositories.appgraph path ../autograph
composer require --dev drfraker/appgraph:@dev
php artisan appgraph:install
```

## Scan

```bash
php artisan appgraph:scan --pretty
```

The default output path is:

```text
storage/appgraph/appgraph.json
```

Graph and overview files are replaced atomically, so concurrent readers never
observe a partially written JSON document. Each graph also records a content-based
scan manifest covering PHP, test, frontend, schema, Composer, configuration, and
the relevant booted framework, environment, container-binding, and Event/Bus registry inputs. Freshness checks compare this fingerprint rather than
depending only on filesystem modification times; older graphs without a manifest
continue to use the legacy timestamp check.

Every PHP scanner consumes the same name-resolved syntax tree for a source file.
Those facts are keyed by source content, PHP/php-parser versions, parser target,
and resolver options, then cached as JSON under
`storage/appgraph/cache/php-facts` by default. This avoids reparsing unchanged PHP
across scanners and later scan processes. Writes are atomic, corrupt entries are
discarded and rebuilt, and syntax errors are cached deterministically. The cache
does not deserialize PHP objects or execute application source, and it is always
safe to delete. Disable it with `appgraph.php_facts.persistent_cache` or change its
location with `appgraph.php_facts.cache_path`; relative paths are resolved below
the application's `storage` directory. Cache identity and hit/miss counters are
recorded in `meta.analysis.phpFileFacts` for scan diagnostics.

You can override it:

```bash
php artisan appgraph:scan --output=storage/appgraph/appgraph.json --pretty
```

All scanners are enabled by default. Database scanning reads existing `.sql` files under
`database/schema` and does not connect to a database or generate dumps automatically.
Publish the config when you need live introspection or explicit dump generation:

```bash
php artisan vendor:publish --tag=appgraph-config
```

Individual scanners are gated by `appgraph.scan.*` config keys (`routes`, `database`,
`models`, `form_requests`, `calls`, `data_flow`, `events`, `side_effects`, `policies`,
`frontend`, `tests`, `container_bindings`). If a scanner cannot run,
the scan completes with a warning and records it in graph metadata so the remaining map
is still useful.

## Query

The full graph is too large to read into an agent's context. `appgraph:query` answers cross-layer questions directly, returning compact JSON (add `--pretty` for humans):

```bash
php artisan appgraph:query <query> [target] [--limit=50] [--depth=4] [--min-confidence=0] [--type=] [--full] [--pretty]
```

| Query | Target | Answers |
|---|---|---|
| `overview` | — | Counts by node/edge type, graph age, files changed since scan |
| `context-for-task` | task description | Ranked graph seeds, causal paths, token-budgeted source spans, mapped tests, and explicit uncertainty (`--context-target=*`, `--changed-file=*`, `--token-budget=4000`) |
| `search` | substring | Find node ids by id/label match (`--type` to filter) |
| `node` | any id | One node plus its in/out edges (`--full` for metadata) |
| `flow-from` | route name/id/label or `Class::method` | Feature slice: middleware, calls, validation, policies, models, field-level data access, events/jobs, side effects, frontend consumers, tests, and resolution warnings |
| `writes-to` | table, model, or column | Methods that write the table, with operations |
| `reads-from` | table, model, or column | Methods that read the table |
| `callers-of` | `Class::method` | Transitive callers through the `calls` graph |
| `calls-from` | `Class::method` | Transitive callees |
| `impact-of` | table, column, model, or method | Everything upstream: methods, models, form requests, routes |
| `routes-touching` | model or table | The HTTP surface that can reach it |
| `models` | — | Model → table map with relationship counts |
| `tables` | — | Tables with column/reader/writer counts |

Targets are forgiving: `users`, `users.email`, `App\Models\User`, `UserController::update`,
`UserController@update`, a route name such as `notes.update`, and a route label such as
`PUT /notes/{note}` all resolve. Ambiguous targets fail with a candidate list.

For feature work, start from the HTTP entry point:

```bash
php artisan appgraph:query flow-from notes.update --pretty
```

For agent-driven feature work or refactoring, let AppGraph compile the first read set:

```bash
php artisan appgraph:query context-for-task "Add auditing to note updates" \
    --context-target=notes.update \
    --changed-file=app/Services/NoteService.php \
    --token-budget=4000 \
    --pretty
```

`context-for-task` combines explicit targets, nodes in changed files, exact identifiers,
and bounded lexical matches. It follows Laravel's executable route, middleware,
FormRequest, call, event, job, and active-handler bridges in both directions, then adds
models, policies, data access, schema, side effects, and mapped tests as ranked context.
The budget estimates the source spans an agent should read with
`ceil(UTF-8 bytes / 4)`; source text is never embedded in the response. Exact scanner
end lines are preferred, while older graphs receive visibly approximate bounded spans.
Source planning refuses files above 2 MB, limits each span to 200 lines and 1,200
estimated tokens, and reports every capacity omission through `omitted`, `uncertainties`,
and the top-level `truncated` flag. Edge evidence is likewise bounded and marked when
additional provenance exists.

Shared tables are not traversal hubs: a table reached downstream from one method stays
a leaf and cannot pull unrelated readers or writers into the task. An explicitly supplied
table or column may open that reverse fan-out; column targets retain the same
`proven`/`possible`/`excluded` field certainty used by reader/writer queries. Ambiguous
targets, stale graphs, approximate spans, and absent static test mappings are reported as
uncertainties rather than silently guessed or described as missing runtime coverage.
When one test maps to several relevant routes, the strongest route remains in `route`
and the complete bounded mapping is retained in `routes`.

```bash
php artisan appgraph:query impact-of progress_notes.title --pretty
```

```json
{
    "query": "impact-of",
    "target": "progress_notes.title",
    "generatedAt": "2026-06-09T12:00:00.000000Z",
    "graphAgeSeconds": 312,
    "methods": [
        {"id": "App\\Services\\NoteService::save", "depth": 2, "confidence": 0.85, "file": "app/Services/NoteService.php", "line": 15}
    ],
    "models": [
        {"id": "App\\Models\\ProgressNote", "depth": 2, "confidence": 1.0, "file": "app/Models/ProgressNote.php", "line": 9}
    ],
    "routes": [
        {"id": "route:PUT:/notes/{note}", "label": "PUT /notes/{note}", "action": "App\\Http\\Controllers\\NoteController::update", "confidence": 0.85, "depth": 4}
    ]
}
```

Confidence values multiply along multi-hop paths — treat them as a ranking heuristic for how certain the static analysis is, not a probability. `--min-confidence` applies to
accumulated graph-edge confidence (rounded to four decimals); task/seed relevance ranks
surviving paths separately, so a lexically modest but strongly supported path is not
discarded merely for wording. Filter weak graph support with `--min-confidence`.
Traversal keeps the strongest supported path to each node, even when it is longer
than a weak direct edge. Complete path queries can retain several distinct ranked
paths using confidence, evidence diversity, depth, and deterministic path ordering.

Column targets preserve field precision. For example, `writes-to users.email`
separates methods with literal `email` writes and whole-row operations (`proven`)
from dynamic or incomplete operations (`possible`) and complete literal writes to
other fields (`excluded`). Missing coverage metadata from older graph generations
is treated as unknown rather than as proof that a column is untouched.
Table and model targets retain the original compact reader/writer result shape.

`flow-from` includes `analysisWarnings` when application calls on the selected path could
not be resolved statically, or when no test could be mapped to the selected route. Calls
that terminate at a known Laravel/Symfony boundary are reported separately under
`meta.analysis.callResolution.boundary*`; they do not imply a missing application edge
and do not generate flow warnings. These warnings are review prompts, not proof that
behavior or coverage is absent. Test mapping recognizes
PHPUnit test methods using named routes or literal HTTP request URLs; it does not replace
runtime code coverage or prove that every branch is exercised.

## MCP Server

The MCP server is built in — [laravel/mcp](https://laravel.com/docs/mcp) is a hard
dependency, so there is nothing extra to install. AppGraph exposes native agent tools:
`appgraph_context`, `appgraph_overview`, `appgraph_search`, `appgraph_node`,
`appgraph_query`, and `appgraph_refresh`.

Use `appgraph_context` first for a concrete feature or refactor. It accepts `task`, up to
10 optional `targets`, up to 50 optional `changed_files`, a 512–16000 source-reading
`token_budget`, traversal `depth` from 1–6, and `min_confidence` from 0–1. The same
defaults are configurable under `appgraph.query.context`.

Register it with your editor/agent once:

```bash
php artisan appgraph:install
```

This writes managed entries to Codex's project `.codex/config.toml` and the
Claude-compatible project `.mcp.json`. Existing servers and surrounding project config
are preserved. Clients launch AppGraph via `php artisan mcp:start appgraph`. Debug it
interactively with `php artisan mcp:inspector appgraph`. Disable registration entirely
with `appgraph.mcp.enabled => false`.

### Automatic scanning

The installer builds the initial graph. If it is missing later, the first tool call
rebuilds it. Agents use `appgraph_refresh` once after a meaningful batch of edits instead
of making every lookup wait for a rescan. Tune this with `appgraph.mcp.auto_scan`:

| Value | Behavior |
|---|---|
| `'missing'` (default) | Scan only when no graph exists yet. Refresh explicitly after source changes. |
| `'stale'` | Scan when missing and rescan before a lookup when source files changed. More automatic, but queries can repeatedly block during active editing. |
| `'off'` | Never scan automatically; tools error until you run `php artisan appgraph:scan`. |

Without an MCP client, agents can shell out to `appgraph:query` and `appgraph:scan`
instead. The installer-managed workflow comes from
[resources/ai/appgraph-guidelines.md](resources/ai/appgraph-guidelines.md). Laravel Boost
can also discover the package guideline under `resources/boost/guidelines`.

## Graph Shape

The exporter writes:

- `meta`: generation and Laravel/package metadata, plus `meta.sources` — schema-dump source descriptors interned once and referenced by id from nodes (`metadata.sources`)
- `nodes`: route, method, class, model, table, column, index, foreign key, form_request, event, job, policy, frontend, test, cache, filesystem, and external-service nodes
- `edges`:
  - `routes_to`, `defined_in`, `validates_with`, `uses_model` — HTTP and controller structure
  - `passes_through` — a route's resolved middleware occurrences, in pipeline order, bridged to executable methods
  - `framework_invokes` — FormRequest lifecycle methods Laravel invokes around authorization and validation
  - `resolves_to` — relevant default and contextual bindings observed from the already-booted service container
  - `uses_table`, `has_column`, `has_index`, `has_foreign_key` — model/schema structure
  - `belongs_to`, `has_many`, `has_one`, `belongs_to_many` — model relationships
  - `calls` — method-to-method call graph, including typed/promoted constructor properties and `app()`/`resolve()` targets
  - `reads`, `writes` — method-to-table data flow, with per-call-site operations and literal field/nested-key writes
  - `dispatches`, `listens_to`, `handled_by`, `observes` — event/job/observer flow and executable listener/job handlers
  - `reads_cache`, `writes_cache`, `reads_filesystem`, `writes_filesystem`, `calls_external` — non-database side effects
  - `authorizes_via` — controller/service authorization calls to resolved policy methods
  - `consumes_route`, `tests_route` — frontend and test consumers of HTTP routes

Form request nodes carry `metadata.rules` (field → rule strings) when `rules()` returns a
literal array; non-literal expressions degrade to `'{expr}'` placeholders with
`metadata.rulesDynamic: true`. Literal rules passed to `$request->validate()`, controller
`validate()`, `Validator::make()`, and `validator()` are represented as synthetic
form-request nodes linked from the calling method with `validates_with`.

Uncertain inferred relationships include a confidence score below `1.0` and explanatory metadata.
Edges with source provenance also retain a deduplicated `metadata.evidence` map, so
multiple call sites supporting the same `(from, to, type)` relationship are not lost
when deterministic edge merging combines them.
Data-flow operation records include `fieldCoverage` (`complete`, `unknown`, or
`whole_row`) and `fieldEvidence` when available. Column queries use that distinction
to distinguish proven whole-row access from incomplete table-level evidence.
Call inference follows declared application return types and common Eloquent query/model
results. It also carries model element types through common collection operations and
untyped collection callbacks. Writes through abstract model parameters fan out to indexed concrete descendant tables at
lower confidence instead of inventing a table for the abstract class. Job nodes and
dispatch edges include statically declared queue/connection and after-commit behavior when
available; ordered bus chains include `chained` and `chainPosition` metadata.

Laravel execution bridges are intentionally evidence-bounded. Container factories and
extenders are never executed during a scan: exact class-string registrations, existing
instances, aliases, and non-null declared factory return types are recorded, while unknown
targets become diagnostics instead of guesses. Binding facts describe the environment in which the
application was scanned and may differ under another environment or tenant. When Laravel has
already cached a route's computed middleware, AppGraph reuses that exact list. Otherwise it
reads the already-instantiated router's alias and group registries, expands declarations
without resolving services or autoloading application middleware, and uses only exact names
or already-loaded ancestry for priority and exclusion rules. Unavailable ancestry remains in
declaration order with a diagnostic. AppGraph also statically recovers literal `HasMiddleware`
declarations and attributes from already-loaded controller source without constructing controllers.
Dynamic and legacy controller-instance declarations are omitted with diagnostics. Global
HTTP-kernel middleware is not currently represented. Source-declared event listeners and job-handler
maps are reconciled with exact already-instantiated Laravel Event and Bus dispatchers. Active
runtime registrations authorize causal traversal; inactive declarations remain visible as
evidence, and unavailable or custom dispatcher state is reported instead of treated as proof.

The output is optimized for token efficiency: null and empty fields are omitted from nodes and edges, so a missing key means "not applicable" rather than an error.

## Overview Projection

Alongside the full graph, the scan writes a small, always-loadable `overview.json` (next to the main output file):

- `counts`: node/edge totals and per-type histograms
- `models`: model class → table name
- `relationships`: model-to-model relationship adjacency
- `routes`: each route with its action and bounded, causally reachable models, tables, and dispatched events/jobs
- `events`: event/job → listener and dispatch-site counts

Route summaries reuse the ranked execution traversal behind `flow-from`, including
downstream calls, middleware/FormRequest bridges, and active listener/job handlers.
Declaration-only or inactive handlers are not treated as execution. Queued work is
eventual reachability, not a claim that it runs in the HTTP request. Each summary is
strictly capped and carries `truncated: true` when some reachable context was omitted;
the effective limits are recorded in `meta.routeTraversal`. Tune them with
`appgraph.overview.route_summary.depth`, `method_limit`, `item_limit`, and
`transition_limit` (hard maximums remain in place). The projection stays bounded
independently of method/column volume, so an agent can load it before targeted
queries. Disable it with `--no-overview`, or via `appgraph.overview.enabled` in
the config.

## Roadmap

- Persistent SQLite/FTS graph storage and generation-to-generation change queries
- Blade/Livewire view edges, Pest closure tests, scheduler entries
- Runtime coverage integration for branch-level test evidence
- Agent benchmark harness (tokens/turns/accuracy with vs. without AppGraph)
