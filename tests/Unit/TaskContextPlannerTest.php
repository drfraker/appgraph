<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Query\GraphIndex;
use AppGraph\Query\FieldAccessClassifier;
use AppGraph\Query\TaskContextPlanner;
use AppGraph\Tests\Support\BuildsQueryFixtureGraph;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

class TaskContextPlannerTest extends TestCase
{
    use BuildsQueryFixtureGraph;

    private string $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = sys_get_temp_dir().'/appgraph-task-context-'.bin2hex(random_bytes(4));
        mkdir($this->project, 0775, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->project);

        parent::tearDown();
    }

    public function test_it_combines_explicit_changed_and_lexical_seeds_into_deterministic_context(): void
    {
        $graph = $this->withExactSpans($this->queryFixtureGraph());
        $graph['edges'][0]['metadata']['evidence'] = [[
            'file' => 'routes/web.php',
            'line' => 12,
            'rule' => 'route_action',
        ]];
        $this->writeGraphFiles($graph);
        $arguments = [
            'Update notes through NoteService::save and notes.update',
            ['notes.update'],
            ['app/Services/NoteService.php'],
            2000,
            4,
            0.0,
        ];
        $result = (new TaskContextPlanner(GraphIndex::fromArray($graph), $this->project))->plan(...$arguments);

        $this->assertSame('route:PUT:/notes/{note}', $result['seeds'][0]['id']);
        $this->assertContains('explicit_target', $result['seeds'][0]['sources']);
        $serviceSeed = $this->rowById($result['seeds'], 'App\Services\NoteService::save');
        $this->assertContains('changed_file', $serviceSeed['sources']);
        $this->assertContains('task_identifier', $serviceSeed['sources']);
        $this->assertSame('app/Services/NoteService.php', $result['readSet'][0]['file']);
        $this->assertLessThanOrEqual(2000, $result['budget']['usedTokens']);
        $this->assertSame('ceil(utf8_bytes/4)', $result['budget']['estimator']);
        $this->assertTrue($this->hasPath(
            $result['paths'],
            ['route:PUT:/notes/{note}', 'App\Http\Controllers\NoteController::update', 'App\Services\NoteService::save'],
        ));
        $routePath = $this->firstPathContaining($result['paths'], 'App\Http\Controllers\NoteController::update');
        $this->assertSame('routes_to', $routePath['edges'][0]['type']);
        $this->assertSame('route_action', $routePath['edges'][0]['evidence'][0]['rule']);
        $this->assertArrayNotHasKey('source', $result);

        $reversed = $graph;
        $reversed['nodes'] = array_reverse($reversed['nodes']);
        $reversed['edges'] = array_reverse($reversed['edges']);
        $reversedResult = (new TaskContextPlanner(GraphIndex::fromArray($reversed), $this->project))->plan(...$arguments);

        $this->assertSame($result, $reversedResult);
    }

    public function test_empty_task_uses_only_explicit_targets_and_changed_files(): void
    {
        $graph = $this->withExactSpans($this->queryFixtureGraph());
        $this->writeGraphFiles($graph);

        $targetOnly = $this->planner($graph)->plan('', ['notes.update']);

        $this->assertSame(['route:PUT:/notes/{note}'], array_column($targetOnly['seeds'], 'id'));
        $this->assertSame(['explicit_target'], $targetOnly['seeds'][0]['sources']);
        $this->assertSame(0, $targetOnly['omitted']['lexical_candidate_limit']);
        $this->assertSame(0, $targetOnly['omitted']['identifier_candidate_limit']);
        $this->assertSame(0, $targetOnly['omitted']['node_scan_limit']);

        $fileOnly = $this->planner($graph)->plan('', changedFiles: ['app/Services/NoteService.php']);

        $this->assertNotEmpty($fileOnly['seeds']);

        foreach ($fileOnly['seeds'] as $seed) {
            $this->assertSame(['changed_file'], $seed['sources']);
        }
    }

    public function test_empty_task_without_explicit_inputs_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('a nonblank task description, an explicit target, or a changed file');

        $this->planner($this->queryFixtureGraph())->plan('');
    }

    public function test_shared_tables_are_terminal_unless_the_table_or_column_is_an_explicit_target(): void
    {
        $graph = [
            'nodes' => [
                ['id' => 'App\Services\FirstWriter::save', 'type' => 'method', 'label' => 'FirstWriter::save', 'file' => 'app/FirstWriter.php', 'line' => 2, 'endLine' => 5],
                ['id' => 'App\Services\SecondWriter::save', 'type' => 'method', 'label' => 'SecondWriter::save', 'file' => 'app/SecondWriter.php', 'line' => 2, 'endLine' => 5],
                ['id' => 'table:notes', 'type' => 'table', 'label' => 'notes'],
                ['id' => 'column:notes.title', 'type' => 'column', 'label' => 'notes.title'],
            ],
            'edges' => [
                [
                    'from' => 'App\Services\FirstWriter::save',
                    'to' => 'table:notes',
                    'type' => 'writes',
                    'confidence' => 1.0,
                    'metadata' => ['operations' => [[
                        'operation' => 'update',
                        'fields' => ['title'],
                        'fieldCoverage' => 'complete',
                    ]]],
                ],
                [
                    'from' => 'App\Services\SecondWriter::save',
                    'to' => 'table:notes',
                    'type' => 'writes',
                    'confidence' => 1.0,
                    'metadata' => ['operations' => [[
                        'operation' => 'update',
                        'fields' => ['body'],
                        'fieldCoverage' => 'complete',
                    ]]],
                ],
                ['from' => 'table:notes', 'to' => 'column:notes.title', 'type' => 'has_column', 'confidence' => 1.0],
            ],
        ];
        $this->write('app/FirstWriter.php', $this->sourceLines(12));
        $this->write('app/SecondWriter.php', $this->sourceLines(12));

        $method = $this->planner($graph)->plan('Inspect selected behavior', ['App\Services\FirstWriter::save']);
        $this->assertNotContains('App\Services\SecondWriter::save', $this->allPathNodes($method['paths']));

        $table = $this->planner($graph)->plan('Inspect selected storage target', ['notes']);
        $this->assertContains('App\Services\FirstWriter::save', $this->allPathNodes($table['paths']));
        $this->assertContains('App\Services\SecondWriter::save', $this->allPathNodes($table['paths']));

        $column = $this->planner($graph)->plan('Inspect selected field target', ['notes.title']);
        $this->assertContains('App\Services\FirstWriter::save', $this->allPathNodes($column['paths']));
        $this->assertNotContains('App\Services\SecondWriter::save', $this->allPathNodes($column['paths']));
        $this->assertSame(1, $column['omitted']['column_excluded']);
    }

    public function test_ambiguous_targets_remain_uncertain_while_changed_files_still_produce_context(): void
    {
        $graph = $this->withExactSpans($this->queryFixtureGraph());
        $this->writeGraphFiles($graph);
        $result = $this->planner($graph)->plan(
            'Review selected behavior',
            ['Tag'],
            ['app/Services/NoteService.php'],
        );
        $ambiguous = $this->uncertainty($result, 'ambiguous_target');

        $this->assertSame('Tag', $ambiguous['target']);
        $this->assertSame(['App\Models\Tag', 'App\Other\Tag'], $ambiguous['candidates']);
        $this->assertContains('App\Services\NoteService::save', array_column($result['seeds'], 'id'));
        $this->assertNotEmpty($result['readSet']);
    }

    public function test_it_returns_mapped_tests_and_describes_absence_as_static_uncertainty(): void
    {
        $graph = $this->withExactSpans($this->queryFixtureGraph());
        $testId = 'test:Tests\Feature\NoteTest::test_update';
        $graph['nodes'][] = [
            'id' => $testId,
            'type' => 'test',
            'label' => 'NoteTest::test_update',
            'file' => 'tests/Feature/NoteTest.php',
            'line' => 3,
            'endLine' => 8,
        ];
        $graph['edges'][] = [
            'from' => $testId,
            'to' => 'route:PUT:/notes/{note}',
            'type' => 'tests_route',
            'confidence' => 1.0,
        ];
        $this->writeGraphFiles($graph);
        $mapped = $this->planner($graph)->plan('Inspect selected route behavior', ['notes.update']);

        $this->assertSame([$testId], array_column($mapped['verification']['mappedTests'], 'id'));
        $this->assertSame(["php artisan test 'tests/Feature/NoteTest.php'"], $mapped['verification']['commands']);
        $this->assertSame([], $mapped['verification']['gaps']);

        array_pop($graph['edges']);
        $unmapped = $this->planner($graph)->plan('Inspect selected route behavior', ['notes.update']);
        $this->assertSame('no_statically_mapped_test', $unmapped['verification']['gaps'][0]['reason']);
        $this->assertStringContainsString('may still exist', $unmapped['verification']['gaps'][0]['message']);
    }

    public function test_truncated_dispatch_metadata_is_reported_without_a_false_non_causal_claim(): void
    {
        $dispatcher = 'App\\Actions\\Publish::run';
        $event = 'App\\Events\\Published';
        $occurrences = array_fill(0, 257, [
            'kind' => 'event',
            'causalExecutionProven' => false,
        ]);
        $occurrences[256] = [
            'kind' => 'event',
            'causalExecutionProven' => true,
        ];
        $graph = [
            'nodes' => [
                ['id' => $dispatcher, 'type' => 'method', 'label' => 'Publish::run'],
                ['id' => $event, 'type' => 'event', 'label' => 'Published'],
            ],
            'edges' => [[
                'from' => $dispatcher,
                'to' => $event,
                'type' => 'dispatches',
                'confidence' => 1.0,
                'metadata' => ['dispatchOccurrences' => $occurrences],
            ]],
        ];

        $result = $this->planner($graph)->plan('Inspect selected behavior', [$dispatcher]);

        $this->assertGreaterThan(0, $result['omitted']['dispatch_metadata_limit']);
        $this->assertSame(0, $result['omitted']['non_causal_handler']);
        $this->assertTrue($result['truncated']);
        $this->assertSame('dispatch_metadata_limit', $this->uncertainty($result, 'dispatch_metadata_limit')['reason']);
        $this->assertNotContains($event, $this->allPathNodes($result['paths']));
    }

    public function test_handler_roles_omitted_from_a_truncated_dispatch_tail_remain_unknown(): void
    {
        $dispatcher = 'App\\Actions\\Publish::run';
        $bridge = 'App\\Messages\\Published';
        $listener = 'App\\Listeners\\RecordPublication::handle';
        $job = 'App\\Jobs\\RecordPublication::handle';
        $occurrences = [];

        for ($index = 0; $index < 300; $index++) {
            $occurrences[] = [
                'kind' => $index < 256 ? 'event' : 'job',
                'causalExecutionProven' => true,
            ];
        }

        $graph = [
            'nodes' => [
                ['id' => $dispatcher, 'type' => 'method', 'label' => 'Publish::run'],
                ['id' => $bridge, 'type' => 'event', 'label' => 'Published'],
                ['id' => $listener, 'type' => 'method', 'label' => 'RecordPublication::handle'],
                ['id' => $job, 'type' => 'method', 'label' => 'RecordPublicationJob::handle'],
            ],
            'edges' => [[
                'from' => $dispatcher,
                'to' => $bridge,
                'type' => 'dispatches',
                'confidence' => 1.0,
                'metadata' => ['dispatchOccurrences' => $occurrences],
            ], [
                'from' => $bridge,
                'to' => $listener,
                'type' => 'handled_by',
                'confidence' => 1.0,
                'metadata' => ['kind' => 'listener', 'causalExecutionProven' => true],
            ], [
                'from' => $bridge,
                'to' => $job,
                'type' => 'handled_by',
                'confidence' => 1.0,
                'metadata' => ['kind' => 'job', 'causalExecutionProven' => true],
            ]],
        ];

        $result = $this->planner($graph)->plan('Inspect selected behavior', [$dispatcher]);
        $pathNodes = $this->allPathNodes($result['paths']);

        $this->assertContains($listener, $pathNodes);
        $this->assertNotContains($job, $pathNodes);
        $this->assertSame(1, $result['omitted']['dispatch_metadata_limit']);
        $this->assertSame(0, $result['omitted']['non_causal_handler']);
        $this->assertTrue($result['truncated']);
    }

    public function test_explicit_column_prioritizes_proven_access_before_high_confidence_possible_and_table_only_edges(): void
    {
        $proven = 'App\\Services\\ProvenWriter::save';
        $possible = 'App\\Services\\PossibleWriter::save';
        $tableOnly = 'App\\Models\\Note';
        $nodes = [
            ['id' => $proven, 'type' => 'method', 'label' => 'ProvenWriter::save'],
            ['id' => $possible, 'type' => 'method', 'label' => 'PossibleWriter::save'],
            ['id' => $tableOnly, 'type' => 'model', 'label' => 'Note'],
            ['id' => 'table:notes', 'type' => 'table', 'label' => 'notes'],
            ['id' => 'column:notes.title', 'type' => 'column', 'label' => 'notes.title'],
        ];
        $edges = [[
            'from' => 'table:notes',
            'to' => 'column:notes.title',
            'type' => 'has_column',
            'confidence' => 1.0,
        ], [
            'from' => $proven,
            'to' => 'table:notes',
            'type' => 'writes',
            'confidence' => 0.4,
            'metadata' => ['operations' => [[
                'operation' => 'update',
                'fields' => ['title'],
                'fieldCoverage' => 'complete',
            ]]],
        ]];
        $possibleEdge = [
            'from' => $possible,
            'to' => 'table:notes',
            'type' => 'reads',
            'confidence' => 1.0,
            'metadata' => ['operations' => [[
                'operation' => 'select',
                'fields' => ['{dynamic}'],
                'fieldCoverage' => 'complete',
            ]]],
        ];
        $tableOnlyEdge = [
            'from' => $tableOnly,
            'to' => 'table:notes',
            'type' => 'uses_table',
            'confidence' => 1.0,
        ];

        for ($index = 0; $index < 1024; $index++) {
            $edges[] = $possibleEdge;
            $edges[] = $tableOnlyEdge;
        }

        $result = $this->planner(['nodes' => $nodes, 'edges' => $edges])->plan(
            'Inspect selected field target',
            ['notes.title'],
        );

        $this->assertContains($proven, $this->allPathNodes($result['paths']));
        $this->assertGreaterThan(0, $result['omitted']['related_fact_limit']);
        $this->assertTrue($result['truncated']);
    }

    public function test_one_test_retains_all_route_mappings_and_uses_the_strongest_as_primary(): void
    {
        $firstRoute = 'route:GET:/first';
        $secondRoute = 'route:GET:/second';
        $testId = 'test:Tests\\Feature\\RouteTest::test_both_routes';
        $graph = [
            'nodes' => [
                ['id' => $firstRoute, 'type' => 'route', 'label' => 'GET /first', 'metadata' => ['name' => 'first.route']],
                ['id' => $secondRoute, 'type' => 'route', 'label' => 'GET /second', 'metadata' => ['name' => 'second.route']],
                [
                    'id' => $testId,
                    'type' => 'test',
                    'label' => 'RouteTest::test_both_routes',
                    'file' => 'tests/Feature/RouteTest.php',
                    'line' => 4,
                    'endLine' => 9,
                ],
            ],
            'edges' => [
                ['from' => $testId, 'to' => $firstRoute, 'type' => 'tests_route', 'confidence' => 0.7],
                ['from' => $testId, 'to' => $secondRoute, 'type' => 'tests_route', 'confidence' => 0.9],
            ],
        ];
        $this->write('tests/Feature/RouteTest.php', $this->sourceLines(20));

        $result = $this->planner($graph)->plan(
            'Inspect selected routes',
            ['first.route', 'second.route'],
        );
        $mapped = $result['verification']['mappedTests'][0];

        $this->assertCount(1, $result['verification']['mappedTests']);
        $this->assertSame($testId, $mapped['id']);
        $this->assertSame($secondRoute, $mapped['route']);
        $this->assertSame(0.9, $mapped['confidence']);
        $this->assertSame([
            ['id' => $secondRoute, 'confidence' => 0.9],
            ['id' => $firstRoute, 'confidence' => 0.7],
        ], $mapped['routes']);
        $this->assertSame(["php artisan test 'tests/Feature/RouteTest.php'"], $result['verification']['commands']);
    }

    public function test_per_edge_evidence_compaction_is_reported_as_truncation(): void
    {
        $route = 'route:GET:/notes';
        $controller = 'App\\Http\\Controllers\\NoteController::index';
        $evidence = [];

        for ($line = 1; $line <= 7; $line++) {
            $evidence[] = [
                'file' => 'routes/web.php',
                'line' => $line,
                'rule' => 'route_action_'.$line,
            ];
        }

        $graph = [
            'nodes' => [
                ['id' => $route, 'type' => 'route', 'label' => 'GET /notes', 'metadata' => ['name' => 'notes.index']],
                ['id' => $controller, 'type' => 'method', 'label' => 'NoteController::index'],
            ],
            'edges' => [[
                'from' => $route,
                'to' => $controller,
                'type' => 'routes_to',
                'confidence' => 1.0,
                'metadata' => ['evidence' => $evidence],
            ]],
        ];
        $this->write('routes/web.php', $this->sourceLines(20));

        $result = $this->planner($graph)->plan('Inspect selected route', ['notes.index']);
        $path = $this->firstPathContaining($result['paths'], $controller);

        $this->assertSame(4, $result['omitted']['evidence_limit']);
        $this->assertTrue($result['truncated']);
        $this->assertCount(3, $path['edges'][0]['evidence']);
        $this->assertTrue($path['edges'][0]['evidenceTruncated']);
    }

    public function test_manual_context_edges_preserve_exact_evidence_files_or_report_atomic_omission(): void
    {
        $exactFile = str_repeat('nested/', 100).'schema.php';
        $oversizedFile = str_repeat('x', 4097);
        $graph = [
            'nodes' => [
                ['id' => 'table:notes', 'type' => 'table', 'label' => 'notes'],
                ['id' => 'column:notes.title', 'type' => 'column', 'label' => 'notes.title'],
                ['id' => 'App\\Services\\NoteWriter::save', 'type' => 'method', 'label' => 'NoteWriter::save'],
            ],
            'edges' => [
                [
                    'from' => 'table:notes',
                    'to' => 'column:notes.title',
                    'type' => 'has_column',
                    'metadata' => ['evidence' => [
                        ['file' => $exactFile, 'line' => 2, 'rule' => 'schema_column'],
                        ['file' => $oversizedFile, 'line' => 3, 'rule' => 'invalid_source'],
                    ]],
                ],
                [
                    'from' => 'App\\Services\\NoteWriter::save',
                    'to' => 'table:notes',
                    'type' => 'writes',
                    'metadata' => ['operations' => [[
                        'operation' => 'update',
                        'fields' => ['title'],
                    ]]],
                ],
            ],
        ];

        $result = $this->planner($graph)->plan('Inspect the note title', ['column:notes.title']);
        $path = $this->firstPathContaining($result['paths'], 'App\\Services\\NoteWriter::save');
        $ownerEdge = $path['edges'][0];

        $this->assertSame($exactFile, $ownerEdge['evidence'][0]['file']);
        $this->assertCount(1, $ownerEdge['evidence']);
        $this->assertTrue($ownerEdge['evidenceTruncated']);
        $this->assertSame(1, $result['omitted']['evidence_limit']);
    }

    public function test_outside_project_evidence_uncertainty_preserves_the_exact_file_reference(): void
    {
        $route = 'route:GET:/notes';
        $controller = 'App\\Http\\Controllers\\NoteController::index';
        $outside = sys_get_temp_dir().'/'.str_repeat('outside/', 150).'routes.php';
        $graph = [
            'nodes' => [
                ['id' => $route, 'type' => 'route', 'label' => 'GET /notes', 'metadata' => ['name' => 'notes.index']],
                ['id' => $controller, 'type' => 'method', 'label' => 'NoteController::index'],
            ],
            'edges' => [[
                'from' => $route,
                'to' => $controller,
                'type' => 'routes_to',
                'metadata' => ['evidence' => [[
                    'file' => $outside,
                    'line' => 3,
                    'rule' => 'route_action',
                ]]],
            ]],
        ];

        $result = $this->planner($graph)->plan('Inspect selected route', ['notes.index']);
        $uncertainty = collect($result['uncertainties'])->firstWhere(
            'reason',
            'edge_evidence_source_outside_project',
        );

        $this->assertIsArray($uncertainty);
        $this->assertSame($outside, $uncertainty['file']);
        $this->assertGreaterThan(1024, strlen($uncertainty['file']));
    }

    public function test_column_metadata_uncertainty_omits_an_oversized_source_id_atomically(): void
    {
        $source = 'App\\'.str_repeat('VeryLongSource', 1300).'::write';
        $operations = [];

        for ($index = 0; $index <= FieldAccessClassifier::TASK_MAX_OPERATIONS; $index++) {
            $operations[] = [
                'operation' => 'update',
                'fields' => ['other'],
                'fieldCoverage' => 'complete',
            ];
        }

        $graph = [
            'nodes' => [
                ['id' => 'table:notes', 'type' => 'table', 'label' => 'notes'],
                ['id' => 'column:notes.title', 'type' => 'column', 'label' => 'notes.title'],
                ['id' => $source, 'type' => 'method', 'label' => 'oversized source'],
            ],
            'edges' => [
                ['from' => 'table:notes', 'to' => 'column:notes.title', 'type' => 'has_column'],
                [
                    'from' => $source,
                    'to' => 'table:notes',
                    'type' => 'writes',
                    'metadata' => ['operations' => $operations],
                ],
            ],
        ];

        $result = $this->planner($graph)->plan('Inspect title writes', ['column:notes.title']);
        $uncertainty = collect($result['uncertainties'])->firstWhere(
            'reason',
            'column_field_metadata_limit',
        );

        $this->assertIsArray($uncertainty);
        $this->assertArrayNotHasKey('from', $uncertainty);
        $this->assertTrue($uncertainty['fromOmitted']);
        $this->assertSame(strlen($source), $uncertainty['fromBytes']);
        $this->assertGreaterThan(0, $result['omitted']['node_text_limit']);
    }

    public function test_in_project_absolute_edge_evidence_is_normalized_into_the_read_set(): void
    {
        $route = 'route:GET:/notes';
        $controller = 'App\\Http\\Controllers\\NoteController::index';
        $this->write('routes/web.php', $this->sourceLines(20));
        $graph = [
            'nodes' => [
                ['id' => $route, 'type' => 'route', 'label' => 'GET /notes', 'metadata' => ['name' => 'notes.index']],
                [
                    'id' => $controller,
                    'type' => 'method',
                    'label' => 'NoteController::index',
                    'file' => 'app/Http/Controllers/NoteController.php',
                    'line' => 2,
                    'endLine' => 5,
                ],
            ],
            'edges' => [[
                'from' => $route,
                'to' => $controller,
                'type' => 'routes_to',
                'confidence' => 1.0,
                'metadata' => ['evidence' => [[
                    'file' => $this->project.'/routes/web.php',
                    'line' => 3,
                    'rule' => 'route_action',
                ]]],
            ]],
        ];
        $this->write('app/Http/Controllers/NoteController.php', $this->sourceLines(20));

        $result = $this->planner($graph)->plan('Inspect selected route', ['notes.index']);

        $this->assertContains('routes/web.php', array_column($result['readSet'], 'file'));
        $this->assertSame(
            [],
            array_values(array_filter(
                $result['uncertainties'],
                static fn (array $uncertainty): bool => ($uncertainty['reason'] ?? null) === 'edge_evidence_source_outside_project',
            )),
        );
    }

    public function test_lexical_and_seed_fanout_is_hard_bounded_and_insertion_order_independent(): void
    {
        $nodes = [];

        for ($index = 0; $index < 600; $index++) {
            $nodes[] = [
                'id' => sprintf('App\\Handlers\\NoteAudit%03d::handle', $index),
                'type' => 'method',
                'label' => 'Note audit handler',
                'file' => sprintf('app/Handlers/NoteAudit%03d.php', $index),
                'line' => 1,
                'endLine' => 2,
            ];
        }

        $graph = ['nodes' => $nodes, 'edges' => []];
        $result = $this->planner($graph)->plan('Audit notes', tokenBudget: 512);
        $reversed = ['nodes' => array_reverse($nodes), 'edges' => []];
        $reversedResult = $this->planner($reversed)->plan('Audit notes', tokenBudget: 512);

        $this->assertCount(24, $result['seeds']);
        $this->assertGreaterThan(0, $result['omitted']['lexical_candidate_limit']);
        $this->assertGreaterThan(0, $result['omitted']['seed_limit']);
        $this->assertTrue($result['truncated']);
        $this->assertSame($result, $reversedResult);
    }

    public function test_large_target_ambiguity_is_bounded_and_reported_as_truncated(): void
    {
        $nodes = [];

        for ($index = 0; $index < 12; $index++) {
            $nodes[] = [
                'id' => sprintf('App\\Shared\\Candidate%02d', $index),
                'type' => 'class',
                'label' => 'Shared',
            ];
        }

        $result = $this->planner(['nodes' => $nodes, 'edges' => []])
            ->plan('zzzz-no-match', ['Shared']);
        $ambiguity = $this->uncertainty($result, 'ambiguous_target');

        $this->assertCount(10, $ambiguity['candidates']);
        $this->assertTrue($ambiguity['candidatesTruncated']);
        $this->assertSame(1, $result['omitted']['target_candidate_limit']);
        $this->assertTrue($result['truncated']);
    }

    public function test_task_term_and_identifier_caps_are_reported(): void
    {
        $terms = array_map(static fn (int $index): string => 'word'.$index, range(1, 70));
        $identifiers = array_map(static fn (int $index): string => '"Target'.$index.'"', range(1, 35));
        $result = $this->planner(['nodes' => [], 'edges' => []])->plan(
            implode(' ', [...$terms, ...$identifiers]),
        );

        $this->assertGreaterThan(0, $result['omitted']['input_limit']);
        $this->assertSame('task_term_limit', $this->uncertainty($result, 'task_term_limit')['reason']);
        $this->assertSame('task_identifier_limit', $this->uncertainty($result, 'task_identifier_limit')['reason']);
        $this->assertTrue($result['truncated']);
    }

    /** @param array<string, mixed> $graph */
    private function planner(array $graph): TaskContextPlanner
    {
        return new TaskContextPlanner(GraphIndex::fromArray($graph), $this->project);
    }

    /** @param array<string, mixed> $graph @return array<string, mixed> */
    private function withExactSpans(array $graph): array
    {
        foreach ($graph['nodes'] as &$node) {
            if (isset($node['line'])) {
                $node['endLine'] = $node['line'] + 3;
            }
        }
        unset($node);

        return $graph;
    }

    /** @param array<string, mixed> $graph */
    private function writeGraphFiles(array $graph): void
    {
        foreach ($graph['nodes'] as $node) {
            if (isset($node['file']) && ! is_file($this->project.'/'.$node['file'])) {
                $this->write($node['file'], $this->sourceLines(80));
            }
        }
    }

    private function sourceLines(int $count): string
    {
        return implode('', array_map(
            static fn (int $line): string => sprintf("line-%03d\n", $line),
            range(1, $count),
        ));
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->project.'/'.ltrim($relative, '/');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, $contents);
    }

    /** @param array<int, array<string, mixed>> $rows @return array<string, mixed> */
    private function rowById(array $rows, string $id): array
    {
        foreach ($rows as $row) {
            if (($row['id'] ?? null) === $id) {
                return $row;
            }
        }

        $this->fail("Missing row [{$id}].");
    }

    /** @param array<int, array<string, mixed>> $paths @param array<int, string> $nodes */
    private function hasPath(array $paths, array $nodes): bool
    {
        foreach ($paths as $path) {
            if (($path['nodes'] ?? null) === $nodes) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array<string, mixed>> $paths @return array<string, mixed> */
    private function firstPathContaining(array $paths, string $node): array
    {
        foreach ($paths as $path) {
            if (in_array($node, $path['nodes'] ?? [], true)) {
                return $path;
            }
        }

        $this->fail("Missing path containing [{$node}].");
    }

    /** @param array<int, array<string, mixed>> $paths @return array<int, string> */
    private function allPathNodes(array $paths): array
    {
        $nodes = [];

        foreach ($paths as $path) {
            foreach ($path['nodes'] ?? [] as $node) {
                $nodes[$node] = $node;
            }
        }

        sort($nodes);

        return array_values($nodes);
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function uncertainty(array $result, string $reason): array
    {
        foreach ($result['uncertainties'] as $uncertainty) {
            if (($uncertainty['reason'] ?? null) === $reason) {
                return $uncertainty;
            }
        }

        $this->fail("Missing uncertainty [{$reason}].");
    }
}
