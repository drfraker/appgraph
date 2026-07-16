# AppGraph workflow

This application has AppGraph installed: a compact, precomputed map of Laravel routes, methods, models, tables, columns, form requests, events, jobs, calls, and database reads/writes.

Use AppGraph early when a feature or refactor crosses files or Laravel layers. It is more useful than broad grep for discovering entry points, downstream calls, database access, and upstream impact. Continue reading the returned source files before editing; AppGraph narrows the investigation rather than replacing source verification.

## Agent workflow

1. For a concrete feature or refactor, call `appgraph_context` with the task, any known `targets`, and any files already changed. It returns ranked graph seeds, causal paths, a token-budgeted source read set, mapped tests, and explicit uncertainties. Read the recommended spans before editing.
2. Use `appgraph_overview` for broad architecture orientation. If `staleness.stale` is true and current results matter, call `appgraph_refresh` once.
3. Use `appgraph_search` to resolve additional route, method, model, event, job, or table ids.
4. Use `appgraph_query` for focused follow-up:
   - `flow-from` for the downstream feature path from a route or method, including middleware, authorization, data fields, non-database side effects, frontend consumers, mapped tests, and analysis warnings.
   - `impact-of` before changing a table, column, model, or method.
   - `routes-touching` to find the HTTP surface reaching a model or table.
   - `writes-to` / `reads-from` to find database access.
   - `callers-of` / `calls-from` for call-graph questions.
5. Use `appgraph_node` with `full: true` only when metadata or additional edge provenance is needed.
6. After a meaningful batch of source edits, call `appgraph_refresh` before using the graph to verify the result. `context-for-task` plans verification but does not claim a before/after graph diff.

Targets are forgiving: `users`, `users.email`, `App\Models\User`, `UserController::update`, and route ids returned by search all resolve. Confidence is a ranking heuristic, not a probability. Treat `analysisWarnings` as source-inspection prompts. Known framework-boundary calls are tracked separately in graph analysis metadata and do not generate missing-application-edge warnings. A missing mapped test is not proof of missing runtime coverage, and dynamic container bindings, macros, runtime-generated calls, Pest closures, and unsupported framework surfaces may still be absent.

The `appgraph_context` token budget estimates recommended source bytes; it is not a
promise about model tokenization and the response never includes source text. Tables
reached downstream remain leaves so a shared table cannot contaminate the task with
unrelated features. Only an explicitly targeted table or column opens reverse
reader/writer discovery.

Read `truncated`, `omitted`, and `uncertainties` before relying on the plan. Source and
edge evidence have hard work limits (including a 2 MB source-file ceiling), and AppGraph
reports when those limits hide additional context. `min_confidence` filters accumulated
graph support, while task relevance independently ranks the paths that survive.

For column targets, `writes-to` and `reads-from` classify results as `proven`,
`possible`, or `excluded` from literal field evidence. Inspect possible matches before
changing a column; they represent dynamic or incomplete operations rather than
confirmed access to that field. Whole-row operations are proven matches.

If MCP is unavailable, use `php artisan appgraph:query context-for-task "<task>"`
with repeatable `--context-target` and `--changed-file` options, then refresh with
`php artisan appgraph:scan` after meaningful edits.
