<?php

return [
    'output_path' => 'appgraph/appgraph.json',

    'memory_limit' => '1024M',

    'store' => [
        /*
         * SQLite is the authoritative local graph store. The JSON export is an
         * optional portable mirror, replaced atomically when its write succeeds,
         * and is still accepted by explicit --input queries.
         */
        'path' => 'appgraph/appgraph.sqlite',
        'retained_generations' => 10,
        'busy_timeout_ms' => 5000,
        'lock_timeout_ms' => 30000,
        'fts' => true,
    ],

    'php_facts' => [
        /*
         * Name-resolved PHP ASTs are shared by every PHP scanner in memory and,
         * by default, reused across processes through a content-addressed JSON
         * cache. The cache never unserializes PHP objects or executes source.
         */
        'persistent_cache' => true,
        'cache_path' => 'appgraph/cache/php-facts',
    ],

    'database' => [
        /*
         * "dump" uses Laravel's schema dump implementation, then parses the SQL file.
         * "live" uses Laravel's Schema Builder introspection and fingerprints
         * the schema before/after scanning. A mismatch blocks publication;
         * consistency evidence covers only that scan window.
         */
        'source' => 'dump',

        /*
         * Null means use Laravel's current default connection. In a Stancl tenancy
         * context, tenants:run will set this to the bootstrapped tenant connection.
         */
        'connection' => null,

        /*
         * Null matches Laravel's schema:dump default:
         * database/schema/{connection}-schema.sql
         */
        'dump_path' => null,

        'dump' => [
            // Never connect to a database or create schema dumps merely because
            // an AI client asked a read-only AppGraph question. Existing dumps
            // are discovered automatically; generating new ones is explicit.
            'run' => false,
            'include_central' => true,
            'include_tenants' => false,
            'tenant_ids' => [],
            'search_paths' => [
                database_path('schema'),
            ],
        ],
    ],

    'scan' => [
        'routes' => true,
        'database' => true,
        'models' => true,
        'calls' => true,
        'data_flow' => true,
        'form_requests' => true,
        'events' => true,
        'side_effects' => true,
        'frontend' => true,
        'tests' => true,
        'policies' => true,
        'container_bindings' => true,
    ],

    'query' => [
        'limit' => 50,
        'depth' => 4,

        /*
         * Task context budgets measure recommended source spans rather than
         * response JSON. Internal hard ceilings still apply, so configuration
         * can tune normal usage without making agent queries unbounded.
         */
        'context' => [
            'token_budget' => 4000,
            'depth' => 4,
            'min_confidence' => 0.0,
        ],
    ],

    'mcp' => [
        /*
         * Registers the AppGraph MCP server. laravel/mcp is a hard dependency,
         * so the server is available out of the box. Run `php artisan
         * appgraph:install` to register it with your editor/agent, then clients
         * connect via `php artisan mcp:start appgraph`.
         */
        'enabled' => true,

        // Fresh-process scans are bounded so a broken application bootstrap or
        // scanner cannot leave an agent request waiting indefinitely.
        'scan_timeout_seconds' => 300,

        /*
         * Keep the authoritative SQLite generation fresh when an MCP tool is queried:
         *
         *   'stale'   - scan when no committed generation exists, and rescan
         *               when recorded source inputs changed (always-current;
         *               queries may block repeatedly during an editing session).
         *   'missing' - scan only when no committed generation exists (fastest;
         *               legacy JSON alone does not satisfy automatic modes).
         *   'off'     - never scan automatically; use SQLite when available or
         *               fall back to configured legacy JSON when it exists.
         */
        // Build once on first use. Agents can call appgraph_refresh after a
        // meaningful batch of edits without making every query trigger a full scan.
        'auto_scan' => 'missing',
    ],

    'overview' => [
        /*
         * Attempt a small optional "overview" mirror (counts, model→table map,
         * model relationships, and bounded route workflow summaries) alongside
         * the JSON mirror. It is atomic when successful, but a failed mirror
         * write does not roll back the authoritative SQLite generation.
         */
        'enabled' => true,

        /*
         * Route summaries follow the same causal execution edges as `flow-from`,
         * including downstream calls and active event/job handlers. These limits
         * keep both traversal work and emitted context bounded on large apps.
         */
        'route_summary' => [
            'depth' => 6,
            'method_limit' => 32,
            'item_limit' => 12,
            'transition_limit' => 5000,
        ],

        /*
         * A bare filename is written next to the main output file. A relative path
         * resolves from the Laravel base path; an absolute path is used as-is.
         */
        'path' => 'overview.json',
    ],
];
