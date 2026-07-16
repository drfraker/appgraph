# AppGraph

AppGraph is a local Laravel application map for AI-assisted feature work and refactoring. For cross-layer changes, unfamiliar areas, or impact questions, use its MCP tools before broad source searches:

- Start a concrete feature or refactor with `appgraph_context`, supplying the task, known targets, and changed files. Read its token-budgeted source spans before editing.
- Use `appgraph_overview` for broad orientation; refresh once with `appgraph_refresh` when the graph is stale and current results matter.
- Use `appgraph_search` to resolve exact ids.
- Use `appgraph_query` with `flow-from`, `impact-of`, `routes-touching`, `writes-to`, `reads-from`, `callers-of`, or `calls-from`. `flow-from` also surfaces middleware, authorization, field writes, side effects, frontend consumers, mapped tests, and unresolved-call warnings.
- Column reader/writer queries separate `proven`, `possible`, and `excluded` matches. Inspect possible matches before changing a field because their operations are dynamic or incomplete; whole-row operations are proven matches.
- Read and verify the returned source files before editing, especially for low-confidence results.
- Refresh after a meaningful batch of edits before using AppGraph for final verification.

`appgraph_context` does not return source text. Its token budget estimates recommended
source bytes, and its missing mapped-test warning is not proof of missing coverage.
Tables reached from a method remain terminal; only an explicitly targeted table or
column opens reverse reader/writer discovery.
Check `truncated`, `omitted`, and `uncertainties`: source files, spans, traversal work,
and edge evidence are deliberately bounded and every capacity loss is reported.

AppGraph is static analysis. Treat its warnings as prompts for source verification; known framework-boundary calls are tracked separately and do not generate missing-application-edge warnings. Mapped tests do not prove branch coverage, and dynamic container bindings, macros, or unsupported framework surfaces may be absent.
