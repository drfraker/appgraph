# AppGraph workflow

This application has AppGraph installed: a compact, precomputed map of Laravel routes, methods, models, tables, columns, form requests, events, jobs, calls, and database reads/writes.

Use AppGraph early when a feature or refactor crosses files or Laravel layers. It is more useful than broad grep for discovering entry points, downstream calls, database access, and upstream impact. Continue reading the returned source files before editing; AppGraph narrows the investigation rather than replacing source verification.

## Agent workflow

1. Call `appgraph_overview` at the beginning of a cross-layer feature, unfamiliar-area investigation, or refactor. If `staleness.stale` is true and current results matter, call `appgraph_refresh` once.
2. Use `appgraph_search` to resolve the exact route, method, model, event, job, or table.
3. Use `appgraph_query`:
   - `flow-from` for the downstream feature path from a route or method, including middleware, authorization, data fields, non-database side effects, frontend consumers, mapped tests, and analysis warnings.
   - `impact-of` before changing a table, column, model, or method.
   - `routes-touching` to find the HTTP surface reaching a model or table.
   - `writes-to` / `reads-from` to find database access.
   - `callers-of` / `calls-from` for call-graph questions.
4. Use `appgraph_node` with `full: true` only when metadata or edge provenance is needed.
5. After a meaningful batch of source edits, call `appgraph_refresh` before using the graph to verify the result.

Targets are forgiving: `users`, `users.email`, `App\Models\User`, `UserController::update`, and route ids returned by search all resolve. Confidence is a ranking heuristic, not a probability. Treat `analysisWarnings` as source-inspection prompts. Known framework-boundary calls are tracked separately in graph analysis metadata and do not generate missing-application-edge warnings. A missing mapped test is not proof of missing runtime coverage, and dynamic container bindings, macros, runtime-generated calls, Pest closures, and unsupported framework surfaces may still be absent.

For column targets, `writes-to` and `reads-from` classify results as `proven`,
`possible`, or `excluded` from literal field evidence. Inspect possible matches before
changing a column; they represent dynamic or incomplete operations rather than
confirmed access to that field. Whole-row operations are proven matches.

If MCP is unavailable, use the equivalent Artisan commands, starting with `php artisan appgraph:query overview` and refreshing with `php artisan appgraph:scan`.
