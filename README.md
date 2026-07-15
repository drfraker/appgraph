# AppGraph

AppGraph is a local-first Laravel package that scans an application into a structured JSON knowledge graph and lets AI agents (and humans) query it: which routes write to a table, what breaks if a column changes, who listens to an event — without loading the whole graph into context.

Deterministic scanning covers:

- Laravel routes and controller methods
- Eloquent models, inferred model-to-table relationships, and model-to-model relationships
- Database tables, columns, indexes, and foreign keys (schema-dump or live connection)
- Method-level `calls` edges and `reads`/`writes` data-flow edges with confidence scores
- Constructor-injected property calls, declared return types, container helpers, fluent Eloquent/collection chains, and explicit resolution diagnostics
- FormRequest and inline request/controller/validator validation rules
- Events, jobs, ordered `Bus::chain()` jobs, listeners, and observers (`dispatches`, `listens_to`, `observes` edges)
- Cache, filesystem, and Laravel HTTP client side effects
- Policy authorization calls, frontend named/literal route consumers, and tests that exercise routes

## Install

Requires PHP 8.2+ and Laravel 12+.

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
`frontend`, `tests`). If a scanner cannot run,
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

Confidence values multiply along multi-hop paths — treat them as a ranking heuristic for how certain the static analysis is, not a probability. Filter noise with `--min-confidence`.

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
`appgraph_overview`, `appgraph_search`, `appgraph_node`, `appgraph_query`, and
`appgraph_refresh`.

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
  - `uses_table`, `has_column`, `has_index`, `has_foreign_key` — model/schema structure
  - `belongs_to`, `has_many`, `has_one`, `belongs_to_many` — model relationships
  - `calls` — method-to-method call graph, including typed/promoted constructor properties and `app()`/`resolve()` targets
  - `reads`, `writes` — method-to-table data flow, with per-call-site operations and literal field/nested-key writes
  - `dispatches`, `listens_to`, `observes` — event/job/observer flow
  - `reads_cache`, `writes_cache`, `reads_filesystem`, `writes_filesystem`, `calls_external` — non-database side effects
  - `authorizes_via` — controller/service authorization calls to resolved policy methods
  - `consumes_route`, `tests_route` — frontend and test consumers of HTTP routes

Form request nodes carry `metadata.rules` (field → rule strings) when `rules()` returns a
literal array; non-literal expressions degrade to `'{expr}'` placeholders with
`metadata.rulesDynamic: true`. Literal rules passed to `$request->validate()`, controller
`validate()`, `Validator::make()`, and `validator()` are represented as synthetic
form-request nodes linked from the calling method with `validates_with`.

Uncertain inferred relationships include a confidence score below `1.0` and explanatory metadata.
Call inference follows declared application return types and common Eloquent query/model
results. It also carries model element types through common collection operations and
untyped collection callbacks. Writes through abstract model parameters fan out to indexed concrete descendant tables at
lower confidence instead of inventing a table for the abstract class. Job nodes and
dispatch edges include statically declared queue/connection and after-commit behavior when
available; ordered bus chains include `chained` and `chainPosition` metadata.

The output is optimized for token efficiency: null and empty fields are omitted from nodes and edges, so a missing key means "not applicable" rather than an error.

## Overview Projection

Alongside the full graph, the scan writes a small, always-loadable `overview.json` (next to the main output file):

- `counts`: node/edge totals and per-type histograms
- `models`: model class → table name
- `relationships`: model-to-model relationship adjacency
- `routes`: each route with its controller action and the models/tables it touches
- `events`: event/job → listener and dispatch-site counts

It is bounded in size (independent of method/column volume) so an agent can read it into context to orient itself before querying the full graph. Disable it with `--no-overview`, or via `appgraph.overview.enabled` in the config.

## Roadmap

- Incremental rescan via per-file content hashes
- Blade/Livewire view edges, Pest closure tests, scheduler entries
- Runtime coverage integration for branch-level test evidence
- Agent benchmark harness (tokens/turns/accuracy with vs. without AppGraph)
