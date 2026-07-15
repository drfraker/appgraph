# AppGraph

AppGraph is a local Laravel application map for AI-assisted feature work and refactoring. For cross-layer changes, unfamiliar areas, or impact questions, use its MCP tools before broad source searches:

- Start with `appgraph_overview`; refresh once with `appgraph_refresh` when the graph is stale and current results matter.
- Use `appgraph_search` to resolve exact ids.
- Use `appgraph_query` with `flow-from`, `impact-of`, `routes-touching`, `writes-to`, `reads-from`, `callers-of`, or `calls-from`. `flow-from` also surfaces middleware, authorization, field writes, side effects, frontend consumers, mapped tests, and unresolved-call warnings.
- Read and verify the returned source files before editing, especially for low-confidence results.
- Refresh after a meaningful batch of edits before using AppGraph for final verification.

AppGraph is static analysis. Treat its warnings as prompts for source verification; known framework-boundary calls are tracked separately and do not generate missing-application-edge warnings. Mapped tests do not prove branch coverage, and dynamic container bindings, macros, or unsupported framework surfaces may be absent.
