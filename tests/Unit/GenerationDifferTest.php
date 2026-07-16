<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Storage\ChangeVerifier;
use AppGraph\Storage\GenerationDiffer;
use AppGraph\Storage\GraphStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class GenerationDifferTest extends TestCase
{
    private string $directory;

    private GraphStore $store;

    private GenerationDiffer $differ;

    private ChangeVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/appgraph-differ-'.bin2hex(random_bytes(8));
        $this->store = new GraphStore($this->directory.'/appgraph.sqlite');
        $this->differ = new GenerationDiffer($this->store);
        $this->verifier = new ChangeVerifier($this->store, $this->differ, getcwd() ?: '.');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        @rmdir($this->directory);
        parent::tearDown();
    }

    public function test_diff_classifies_exact_changes_and_bounds_selected_details(): void
    {
        [$before, $after] = $this->publishRichChange();
        $diff = $this->differ->diff($before, $after, ['routes', 'authorization'], 1);

        $this->assertSame('diff', $diff['query']);
        $this->assertGreaterThan(0, $diff['counts']['overall']['total']);
        $this->assertGreaterThan(1, $diff['counts']['byCategory']['routes']['total']);
        $this->assertSame(1, $diff['counts']['byCategory']['authorization']['removed']);
        $this->assertSame(['routes', 'authorization'], $diff['detailCategories']);
        $this->assertCount(1, $diff['changes']['routes']);
        $this->assertGreaterThan(0, $diff['omitted']['routes']);
        $this->assertTrue($diff['truncated']);
        $this->assertTrue($diff['comparison']['graphChanged']);
        $this->assertTrue($diff['comparison']['semanticChanged']);
        $this->assertTrue($diff['comparison']['sourceChanged']);
    }

    public function test_changed_details_distinguish_semantics_from_provenance(): void
    {
        $beforeGraph = $this->graph('source-a', 'hash-a');
        $beforeGraph->addNode(Node::make('App\\Service::run', 'method', 'Service::run', [
            'file' => 'app/Service.php',
            'line' => 10,
        ]));
        $before = $this->store->publish($beforeGraph)['generation']['id'];

        $afterGraph = $this->graph('source-b', 'hash-b');
        $afterGraph->addNode(Node::make('App\\Service::run', 'method', 'Service::run', [
            'file' => 'app/Service.php',
            'line' => 40,
        ]));
        $after = $this->store->publish($afterGraph)['generation']['id'];
        $detail = $this->differ->diff($before, $after)['changes']['nodes'][0];

        $this->assertSame('changed', $detail['change']);
        $this->assertFalse($detail['semanticChanged']);
        $this->assertContains('/line', $detail['changedFields']);
        $this->assertFalse($this->differ->diff($before, $after)['comparison']['semanticChanged']);
    }

    public function test_verify_change_returns_cautious_findings_and_collateral_scope(): void
    {
        [$before, $after] = $this->publishRichChange();
        $verification = $this->verifier->verify(
            $before,
            $after,
            ['route:PUT:/orders/{order}'],
            ['app/OrderAction.php'],
            limit: 100,
        );
        $codes = array_column($verification['findings'], 'code');

        $this->assertSame('verify-change', $verification['query']);
        $this->assertStringContainsString('static AppGraph generation evidence', $verification['assessment']['basis']);
        $this->assertContains('route_action_removed', $codes);
        $this->assertContains('write_surface_added', $codes);
        $this->assertContains('authorization_edge_removed', $codes);
        $this->assertContains('dispatch_semantics_changed', $codes);
        $this->assertContains('active_handler_edge_removed', $codes);
        $this->assertContains('test_route_mapping_removed', $codes);
        $this->assertGreaterThan(0, $verification['targetScope']['changesInScope']['total']);
        $this->assertSame('changed', $verification['sourceCoverage']['requestedFiles'][0]['manifestStatus']);
        $this->assertStringContainsString('does not prove', $verification['assessment']['statement']);
    }

    public function test_source_only_change_is_reported_as_uncertainty_not_an_invented_delta(): void
    {
        $before = $this->store->publish($this->graph('source-a', 'hash-a'))['generation']['id'];
        $after = $this->store->publish($this->graph('source-b', 'hash-b'))['generation']['id'];
        $verification = $this->verifier->verify($before, $after, changedFiles: ['app/OrderAction.php']);

        $this->assertSame(0, $verification['diff']['counts']['overall']['total']);
        $this->assertSame([], $verification['findings']);
        $this->assertContains(
            'source_changed_without_graph_delta',
            array_column($verification['uncertainties'], 'code'),
        );
        $this->assertStringStartsWith('no_graph_delta_observed', $verification['assessment']['status']);
    }

    public function test_diff_rejects_same_reverse_and_invalid_categories(): void
    {
        $one = $this->store->publish($this->graph('one', 'one'))['generation']['id'];
        $two = $this->store->publish($this->graph('two', 'two', routeLabel: 'changed'))['generation']['id'];

        foreach (
            [
                fn () => $this->differ->diff($one, $one),
                fn () => $this->differ->diff($two, $one),
                fn () => $this->differ->diff($one, $two, ['bogus']),
                fn () => $this->differ->diff($one, $two, ['nodes', 'nodes']),
            ] as $operation
        ) {
            try {
                $operation();
                $this->fail('The invalid generation diff should fail.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('AppGraph', $exception->getMessage());
            }
        }
    }

    public function test_changed_files_define_expected_scope_and_leave_other_file_changes_collateral(): void
    {
        $before = $this->graphWithFiles('scope-before', [
            'App\\A::run' => ['file' => 'app/A.php', 'label' => 'A before'],
            'App\\B::run' => ['file' => 'app/B.php', 'label' => 'B before'],
        ], ['app/A.php' => 'a-before', 'app/B.php' => 'b-before']);
        $beforeId = $this->store->publish($before)['generation']['id'];
        $after = $this->graphWithFiles('scope-after', [
            'App\\A::run' => ['file' => 'app/A.php', 'label' => 'A after'],
            'App\\B::run' => ['file' => 'app/B.php', 'label' => 'B after'],
        ], ['app/A.php' => 'a-after', 'app/B.php' => 'b-after']);
        $afterId = $this->store->publish($after)['generation']['id'];
        $verification = $this->verifier->verify(
            $beforeId,
            $afterId,
            changedFiles: ['app/A.php'],
        );

        $this->assertSame(1, $verification['targetScope']['changesInScope']['total']);
        $this->assertSame(1, $verification['targetScope']['collateralChanges']);
        $this->assertSame('expected_and_collateral_graph_changes_observed_with_uncertainties', $verification['assessment']['status']);
        $this->assertContains('unrequested_source_changes', array_column($verification['uncertainties'], 'code'));
    }

    public function test_unrelated_delta_does_not_claim_an_expected_change(): void
    {
        $before = $this->graphWithFiles('before', [
            'App\\Expected::run' => ['file' => 'app/Expected.php', 'label' => 'Expected'],
            'App\\Other::run' => ['file' => 'app/Other.php', 'label' => 'Other before'],
        ], ['app/Expected.php' => 'same', 'app/Other.php' => 'before']);
        $beforeId = $this->store->publish($before)['generation']['id'];
        $after = $this->graphWithFiles('after', [
            'App\\Expected::run' => ['file' => 'app/Expected.php', 'label' => 'Expected'],
            'App\\Other::run' => ['file' => 'app/Other.php', 'label' => 'Other after'],
        ], ['app/Expected.php' => 'same', 'app/Other.php' => 'after']);
        $afterId = $this->store->publish($after)['generation']['id'];
        $verification = $this->verifier->verify($beforeId, $afterId, ['App\\Expected::run']);

        $this->assertSame(0, $verification['targetScope']['changesInScope']['total']);
        $this->assertSame(1, $verification['targetScope']['collateralChanges']);
        $this->assertSame('collateral_graph_changes_observed', $verification['assessment']['status']);
    }

    public function test_shared_table_does_not_pull_an_unrelated_route_into_expected_scope(): void
    {
        $before = $this->graph('shared-before', 'same');
        $after = $this->graph('shared-after', 'same');

        foreach ([
            Node::make('route:r1', 'route', 'R1'),
            Node::make('App\\C1::run', 'method', 'C1'),
            Node::make('route:r2', 'route', 'R2'),
            Node::make('App\\C2::run', 'method', 'C2 before'),
            Node::make('table:shared', 'table', 'shared'),
        ] as $node) {
            $before->addNode($node);
        }

        foreach ([
            Node::make('route:r1', 'route', 'R1'),
            Node::make('App\\C1::run', 'method', 'C1'),
            Node::make('route:r2', 'route', 'R2'),
            Node::make('App\\C2::run', 'method', 'C2 after'),
            Node::make('table:shared', 'table', 'shared'),
        ] as $node) {
            $after->addNode($node);
        }

        foreach ([
            new Edge('route:r1', 'App\\C1::run', 'routes_to'),
            new Edge('App\\C1::run', 'table:shared', 'writes'),
            new Edge('route:r2', 'App\\C2::run', 'routes_to'),
            new Edge('App\\C2::run', 'table:shared', 'writes'),
        ] as $edge) {
            $before->addEdge($edge);
            $after->addEdge(clone $edge);
        }

        $beforeId = $this->store->publish($before)['generation']['id'];
        $afterId = $this->store->publish($after)['generation']['id'];
        $verification = $this->verifier->verify($beforeId, $afterId, ['route:r1']);

        $this->assertSame(0, $verification['targetScope']['changesInScope']['total']);
        $this->assertSame(1, $verification['targetScope']['collateralChanges']);
        $this->assertSame('collateral_graph_changes_observed', $verification['assessment']['status']);
    }

    public function test_changed_edge_from_unrelated_writer_to_shared_table_stays_collateral(): void
    {
        $before = $this->graph('shared-edge-before', 'same');
        $after = $this->graph('shared-edge-after', 'same');

        foreach ([$before, $after] as $graph) {
            foreach ([
                Node::make('route:r1', 'route', 'R1'),
                Node::make('App\\C1::run', 'method', 'C1'),
                Node::make('route:r2', 'route', 'R2'),
                Node::make('App\\C2::run', 'method', 'C2'),
                Node::make('table:shared', 'table', 'shared'),
            ] as $node) {
                $graph->addNode($node);
            }

            $graph->addEdge(new Edge('route:r1', 'App\\C1::run', 'routes_to'));
            $graph->addEdge(new Edge('App\\C1::run', 'table:shared', 'writes'));
            $graph->addEdge(new Edge('route:r2', 'App\\C2::run', 'routes_to'));
        }

        $before->addEdge(new Edge('App\\C2::run', 'table:shared', 'writes', 1.0, ['operation' => 'insert']));
        $after->addEdge(new Edge('App\\C2::run', 'table:shared', 'writes', 1.0, ['operation' => 'update']));
        $beforeId = $this->store->publish($before)['generation']['id'];
        $afterId = $this->store->publish($after)['generation']['id'];
        $verification = $this->verifier->verify($beforeId, $afterId, ['route:r1']);

        $this->assertSame(0, $verification['targetScope']['changesInScope']['total']);
        $this->assertSame(1, $verification['targetScope']['collateralChanges']);
        $this->assertSame('collateral_graph_changes_observed', $verification['assessment']['status']);
    }

    public function test_stronger_later_path_reexpands_a_node_and_keeps_scope_exact(): void
    {
        $before = $this->graph('stronger-path-before', 'same');
        $after = $this->graph('stronger-path-after', 'same');

        foreach ([$before, $after] as $graph) {
            foreach (['node:A', 'node:B', 'node:X'] as $node) {
                $graph->addNode(Node::make($node, 'method', $node));
            }

            $graph->addEdge(new Edge('node:A', 'node:X', 'calls', 0.2));
            $graph->addEdge(new Edge('node:A', 'node:B', 'calls', 0.9));
            $graph->addEdge(new Edge('node:B', 'node:X', 'calls', 0.9));
            $graph->addEdge(new Edge('node:X', 'node:Y', 'calls', 0.5));
        }

        $before->addNode(Node::make('node:Y', 'method', 'Y before'));
        $after->addNode(Node::make('node:Y', 'method', 'Y after'));
        $beforeId = $this->store->publish($before)['generation']['id'];
        $afterId = $this->store->publish($after)['generation']['id'];
        $verification = $this->verifier->verify(
            $beforeId,
            $afterId,
            ['node:A'],
            depth: 3,
            minConfidence: 0.2,
        );

        $this->assertSame(1, $verification['targetScope']['changesInScope']['total']);
        $this->assertTrue($verification['targetScope']['changesInScopeExact']);
        $this->assertSame(0, $verification['targetScope']['collateralChanges']);
    }

    public function test_route_name_resolves_deterministically_for_historical_scope(): void
    {
        $before = $this->graph('route-before', 'before');
        $before->addNode(Node::make('route:PUT:/notes/{note}', 'route', 'PUT /notes/{note}', [
            'metadata' => ['name' => 'notes.update'],
        ]));
        $before->addNode(Node::make('App\\NoteAction::run', 'method', 'Before'));
        $before->addEdge(new Edge('route:PUT:/notes/{note}', 'App\\NoteAction::run', 'routes_to'));
        $beforeId = $this->store->publish($before)['generation']['id'];
        $after = $this->graph('route-after', 'after');
        $after->addNode(Node::make('route:PUT:/notes/{note}', 'route', 'PUT /notes/{note}', [
            'metadata' => ['name' => 'notes.update'],
        ]));
        $after->addNode(Node::make('App\\NoteAction::run', 'method', 'After'));
        $after->addEdge(new Edge('route:PUT:/notes/{note}', 'App\\NoteAction::run', 'routes_to'));
        $afterId = $this->store->publish($after)['generation']['id'];
        $verification = $this->verifier->verify($beforeId, $afterId, ['notes.update']);

        $this->assertSame('route:PUT:/notes/{note}', $verification['targetScope']['requestedTargets'][0]['beforeNode']);
        $this->assertSame('resolved', $verification['targetScope']['requestedTargets'][0]['beforeResolution']);
        $this->assertGreaterThan(0, $verification['targetScope']['changesInScope']['total']);
        $this->assertNotContains('targets_unresolved', array_column($verification['uncertainties'], 'code'));
    }

    public function test_verification_normalizes_class_at_method_targets(): void
    {
        $before = $this->graph('method-before', 'before');
        $before->addNode(Node::make('App\\Thing::run', 'method', 'Before'));
        $beforeId = $this->store->publish($before)['generation']['id'];
        $after = $this->graph('method-after', 'after');
        $after->addNode(Node::make('App\\Thing::run', 'method', 'After'));
        $afterId = $this->store->publish($after)['generation']['id'];
        $verification = $this->verifier->verify($beforeId, $afterId, ['App\\Thing@run']);

        $this->assertSame('App\\Thing::run', $verification['targetScope']['requestedTargets'][0]['beforeNode']);
        $this->assertSame('exact', $verification['targetScope']['requestedTargets'][0]['beforeResolution']);
    }

    public function test_literal_route_name_with_at_sign_resolves_before_method_normalization(): void
    {
        $before = $this->graph('at-route-before', 'before');
        $before->addNode(Node::make('route:GET:/reports/daily', 'route', 'GET /reports/daily', [
            'metadata' => ['name' => 'reports@daily'],
        ]));
        $beforeId = $this->store->publish($before)['generation']['id'];
        $after = $this->graph('at-route-after', 'after');
        $after->addNode(Node::make('route:GET:/reports/daily', 'route', 'GET /reports/daily changed', [
            'metadata' => ['name' => 'reports@daily'],
        ]));
        $afterId = $this->store->publish($after)['generation']['id'];
        $verification = $this->verifier->verify($beforeId, $afterId, ['reports@daily']);
        $target = $verification['targetScope']['requestedTargets'][0];

        $this->assertSame('route:GET:/reports/daily', $target['beforeNode']);
        $this->assertSame('route:GET:/reports/daily', $target['afterNode']);
        $this->assertSame('resolved', $target['beforeResolution']);
        $this->assertSame('resolved', $target['afterResolution']);
        $this->assertSame(1, $verification['targetScope']['changesInScope']['total']);
        $this->assertTrue($verification['targetScope']['changesInScopeExact']);
    }

    public function test_ambiguous_target_candidates_are_bounded_and_report_truncation(): void
    {
        $before = $this->graph('ambiguous-before', 'before');
        $after = $this->graph('ambiguous-after', 'after');

        for ($index = 0; $index < 7; $index++) {
            $before->addNode(Node::make("App\\Worker{$index}", 'method', 'Worker'));
            $after->addNode(Node::make("App\\Worker{$index}", 'method', 'Worker'));
        }

        $after->addNode(Node::make('node:delta', 'method', 'Delta'));
        $beforeId = $this->store->publish($before)['generation']['id'];
        $afterId = $this->store->publish($after)['generation']['id'];
        $verification = $this->verifier->verify($beforeId, $afterId, ['Worker']);
        $target = $verification['targetScope']['requestedTargets'][0];
        $codes = array_column($verification['uncertainties'], 'code');

        $this->assertCount(5, $target['candidates']);
        $this->assertTrue($target['candidatesTruncated']);
        $this->assertTrue($verification['targetScope']['targetResolutionIncomplete']);
        $this->assertFalse($verification['targetScope']['changesInScopeExact']);
        $this->assertNull($verification['targetScope']['collateralChanges']);
        $this->assertContains('targets_ambiguous', $codes);
        $this->assertContains('target_candidates_truncated', $codes);
        $this->assertContains('expected_scope_resolution_incomplete', $codes);
    }

    public function test_unresolved_target_does_not_claim_that_all_changes_are_collateral(): void
    {
        $before = $this->graph('unresolved-before', 'before');
        $before->addNode(Node::make('App\\Other::run', 'method', 'Before'));
        $beforeId = $this->store->publish($before)['generation']['id'];
        $after = $this->graph('unresolved-after', 'after');
        $after->addNode(Node::make('App\\Other::run', 'method', 'After'));
        $afterId = $this->store->publish($after)['generation']['id'];
        $verification = $this->verifier->verify($beforeId, $afterId, ['DoesNotExist']);

        $this->assertSame(1, $verification['diff']['counts']['overall']['total']);
        $this->assertSame(0, $verification['targetScope']['changesInScope']['total']);
        $this->assertTrue($verification['targetScope']['targetResolutionIncomplete']);
        $this->assertFalse($verification['targetScope']['changesInScopeExact']);
        $this->assertNull($verification['targetScope']['collateralChanges']);
        $this->assertSame(
            'graph_changes_observed_with_uncertainties',
            $verification['assessment']['status'],
        );
        $this->assertContains('targets_unresolved', array_column($verification['uncertainties'], 'code'));
        $this->assertContains(
            'expected_scope_resolution_incomplete',
            array_column($verification['uncertainties'], 'code'),
        );
    }

    public function test_target_missing_on_one_side_keeps_scope_attribution_inexact(): void
    {
        $before = $this->graph('one-sided-before', 'before');
        $beforeId = $this->store->publish($before)['generation']['id'];
        $after = $this->graph('one-sided-after', 'after');
        $after->addNode(Node::make('App\\Added::run', 'method', 'Added'));
        $afterId = $this->store->publish($after)['generation']['id'];
        $verification = $this->verifier->verify($beforeId, $afterId, ['App\\Added::run']);

        $this->assertSame('added', $verification['targetScope']['requestedTargets'][0]['directChange']);
        $this->assertSame(1, $verification['diff']['counts']['overall']['total']);
        $this->assertSame(1, $verification['targetScope']['changesInScope']['total']);
        $this->assertFalse($verification['targetScope']['changesInScopeExact']);
        $this->assertNull($verification['targetScope']['collateralChanges']);
        $this->assertContains(
            'expected_scope_resolution_incomplete',
            array_column($verification['uncertainties'], 'code'),
        );
    }

    public function test_changed_file_without_node_facts_does_not_claim_exact_collateral(): void
    {
        $before = $this->graphWithFiles('config-before', [
            'App\\Other::run' => ['file' => 'app/Other.php', 'label' => 'Before'],
        ], ['config/appgraph.php' => 'before', 'app/Other.php' => 'before']);
        $after = $this->graphWithFiles('config-after', [
            'App\\Other::run' => ['file' => 'app/Other.php', 'label' => 'After'],
        ], ['config/appgraph.php' => 'after', 'app/Other.php' => 'after']);
        $beforeId = $this->store->publish($before)['generation']['id'];
        $afterId = $this->store->publish($after)['generation']['id'];
        $verification = $this->verifier->verify(
            $beforeId,
            $afterId,
            changedFiles: ['config/appgraph.php'],
        );

        $this->assertFalse($verification['targetScope']['changesInScopeExact']);
        $this->assertNull($verification['targetScope']['collateralChanges']);
        $this->assertContains(
            'changed_files_without_graph_seeds',
            array_column($verification['uncertainties'], 'code'),
        );
        $this->assertStringNotContainsString('collateral_graph_changes_observed', $verification['assessment']['status']);
    }

    public function test_findings_report_uninspected_risk_changes_when_diff_details_are_bounded(): void
    {
        $before = $this->graph('writes-before', 'before');
        $after = $this->graph('writes-after', 'after');

        for ($index = 0; $index < 60; $index++) {
            $method = "App\\Writer{$index}::run";
            $table = "table:table_{$index}";
            $before->addNode(Node::make($method, 'method', "Writer {$index}"));
            $before->addNode(Node::make($table, 'table', "table_{$index}"));
            $before->addEdge(new Edge($method, $table, 'writes'));
            $after->addNode(Node::make($method, 'method', "Writer {$index}"));
            $after->addNode(Node::make($table, 'table', "table_{$index}"));
        }

        $beforeId = $this->store->publish($before)['generation']['id'];
        $afterId = $this->store->publish($after)['generation']['id'];
        $verification = $this->verifier->verify($beforeId, $afterId, limit: 1);

        $this->assertCount(1, $verification['findings']);
        $this->assertSame(59, $verification['uninspectedRiskChanges']);
        $this->assertSame(0, $verification['omittedFindings']);
        $this->assertTrue($verification['omittedFindingsIsLowerBound']);
        $this->assertTrue($verification['findingsTruncated']);
    }

    public function test_same_generation_verification_returns_a_cautious_noop_assessment(): void
    {
        $generation = $this->store->publish($this->graph('same', 'same'))['generation']['id'];
        $verification = $this->verifier->verify($generation, $generation);

        $this->assertTrue($verification['diff']['sameGeneration']);
        $this->assertSame(0, $verification['diff']['counts']['overall']['total']);
        $this->assertSame('same_generation_selected_with_uncertainties', $verification['assessment']['status']);
        $this->assertContains('same_generation_selected', array_column($verification['uncertainties'], 'code'));
        $this->assertNotContains('non_adjacent_generations', array_column($verification['uncertainties'], 'code'));
    }

    public function test_runtime_only_source_fingerprint_change_without_graph_delta_is_uncertain(): void
    {
        $before = $this->store->publish($this->graph('runtime-before', 'same'))['generation']['id'];
        $after = $this->store->publish($this->graph('runtime-after', 'same'))['generation']['id'];
        $verification = $this->verifier->verify($before, $after);

        $this->assertSame(0, $verification['sourceCoverage']['counts']['total']);
        $this->assertContains('source_changed_without_graph_delta', array_column($verification['uncertainties'], 'code'));
    }

    public function test_warning_details_preserve_exact_reusable_references(): void
    {
        $before = $this->graph('warning-reference-before', 'same');
        $after = $this->graph('warning-reference-after', 'same');
        $references = [
            'file' => 'app/'.str_repeat('f', 300).'.php',
            'class' => 'App\\'.str_repeat('C', 300),
            'method' => str_repeat('m', 300),
            'route' => 'route:GET:/'.str_repeat('r', 300),
            'target' => 'target:'.str_repeat('t', 300),
            'name' => 'name:'.str_repeat('n', 300),
            'namespace' => 'App\\'.str_repeat('N', 300),
            'parent' => 'Vendor\\'.str_repeat('P', 300),
            'listener' => 'App\\Listeners\\'.str_repeat('L', 300),
        ];
        $message = str_repeat('long diagnostic prose ', 100);
        $after->addWarning([
            'scanner' => 'reference-test',
            ...$references,
            'message' => $message,
        ]);
        $beforeId = $this->store->publish($before)['generation']['id'];
        $afterId = $this->store->publish($after)['generation']['id'];
        $warning = $this->verifier->verify($beforeId, $afterId)['scannerWarnings']['current'][0];

        foreach ($references as $field => $value) {
            $this->assertSame($value, $warning[$field]);
        }

        $this->assertNotSame($message, $warning['message']);
        $this->assertLessThanOrEqual(1024, strlen($warning['message']));
    }

    public function test_oversized_exact_warning_reference_omits_the_whole_detail(): void
    {
        $before = $this->graph('warning-omission-before', 'same');
        $after = $this->graph('warning-omission-after', 'same');
        $after->addWarning([
            'scanner' => 'reference-test',
            'reason' => 'oversized_exact_reference',
            'class' => 'App\\'.str_repeat('C', 70000),
            'message' => 'This warning must be omitted instead of publishing a shortened class reference.',
        ]);
        $beforeId = $this->store->publish($before)['generation']['id'];
        $afterId = $this->store->publish($after)['generation']['id'];
        $warnings = $this->verifier->verify($beforeId, $afterId)['scannerWarnings'];

        $this->assertSame(1, $warnings['after']);
        $this->assertSame([], $warnings['current']);
        $this->assertSame(1, $warnings['omittedCurrent']);
        $this->assertTrue($warnings['currentTruncated']);
        $this->assertSame(0, $warnings['detailBudget']['returnedBytes']);
    }

    public function test_diff_enforces_a_global_count_and_byte_budget_on_adversarial_metadata(): void
    {
        $before = $this->graph('budget-before', 'before');
        $after = $this->graph('budget-after', 'after');
        $metadata = [];

        for ($field = 0; $field < 16; $field++) {
            $metadata["field_{$field}"] = array_fill(0, 16, str_repeat('x', 2048));
        }

        for ($index = 0; $index < 30; $index++) {
            $before->addNode(Node::make("node:{$index}", 'method', 'Before', ['metadata' => $metadata]));
            $after->addNode(Node::make("node:{$index}", 'method', 'After', ['metadata' => $metadata]));
        }

        $beforeId = $this->store->publish($before)['generation']['id'];
        $afterId = $this->store->publish($after)['generation']['id'];
        $diff = $this->differ->diff($beforeId, $afterId, ['nodes'], 200);

        $this->assertLessThanOrEqual(1000000, $diff['detailBudget']['returnedBytes']);
        $this->assertLessThanOrEqual(200, $diff['detailBudget']['returnedDetails']);
        $this->assertTrue($diff['detailBudget']['byteBudgetExhausted']);
        $this->assertTrue($diff['truncated']);
        $this->assertSame(
            30 - $diff['detailBudget']['returnedDetails'],
            $diff['omitted']['nodes'],
        );
    }

    public function test_diff_preserves_exact_identity_reference_fields_and_json_pointers(): void
    {
        $id = 'node:'.str_repeat('i', 2500);
        $key = str_repeat('k', 2500);
        $class = 'Class'.str_repeat('c', 2500);
        $file = 'app/'.str_repeat('f', 2500).'.php';
        $method = 'method'.str_repeat('m', 2500);
        $namespace = 'App\\'.str_repeat('n', 2500);
        $attributes = [
            'class' => $class,
            'file' => $file,
            'method' => $method,
            'namespace' => $namespace,
        ];
        $before = $this->graph('long-before', 'before');
        $before->addNode(Node::make($id, 'method', 'Before', $attributes + [
            'metadata' => [$key => 'before'],
        ]));
        $after = $this->graph('long-after', 'after');
        $after->addNode(Node::make($id, 'method', 'After', $attributes + [
            'metadata' => [$key => 'after'],
        ]));
        $beforeId = $this->store->publish($before)['generation']['id'];
        $afterId = $this->store->publish($after)['generation']['id'];
        $detail = $this->differ->diff($beforeId, $afterId, ['nodes'], 10)['changes']['nodes'][0];

        $this->assertSame($id, $detail['identity']);
        $this->assertArrayNotHasKey('identityTruncated', $detail);
        $this->assertSame($id, $detail['before']['id']);
        $this->assertSame($class, $detail['before']['class']);
        $this->assertSame($file, $detail['before']['file']);
        $this->assertSame($method, $detail['before']['method']);
        $this->assertSame($namespace, $detail['before']['namespace']);
        $this->assertContains('/metadata/'.$key, $detail['changedFields']);
        $this->assertArrayNotHasKey('changedFieldsTruncated', $detail);
    }

    public function test_diff_preserves_exact_edge_identity_and_nested_reference_fields(): void
    {
        $from = 'node:'.str_repeat('f', 2500);
        $to = 'node:'.str_repeat('t', 2500);
        $class = 'Class'.str_repeat('c', 2500);
        $file = 'app/'.str_repeat('p', 2500).'.php';
        $before = $this->graph('edge-before', 'before');
        $before->addNode(Node::make($from, 'method', 'From'));
        $before->addNode(Node::make($to, 'method', 'To'));
        $before->addEdge(new Edge($from, $to, 'calls', metadata: [
            'class' => $class,
            'file' => $file,
            'state' => 'before',
        ]));
        $after = $this->graph('edge-after', 'after');
        $after->addNode(Node::make($from, 'method', 'From'));
        $after->addNode(Node::make($to, 'method', 'To'));
        $after->addEdge(new Edge($from, $to, 'calls', metadata: [
            'class' => $class,
            'file' => $file,
            'state' => 'after',
        ]));
        $beforeId = $this->store->publish($before)['generation']['id'];
        $afterId = $this->store->publish($after)['generation']['id'];
        $detail = $this->differ->diff($beforeId, $afterId, ['edges'], 10)['changes']['edges'][0];

        $this->assertSame(['from' => $from, 'type' => 'calls', 'to' => $to], $detail['identity']);
        $this->assertSame($from, $detail['before']['from']);
        $this->assertSame($to, $detail['before']['to']);
        $this->assertSame($class, $detail['before']['metadata']['class']);
        $this->assertSame($file, $detail['before']['metadata']['file']);
        $this->assertArrayNotHasKey('identityTruncated', $detail);
    }

    public function test_diff_omits_an_oversized_exact_reference_row_atomically(): void
    {
        $class = str_repeat('c', 600000);
        $before = $this->graph('exact-budget-before', 'before');
        $before->addNode(Node::make('node:exact-budget', 'method', 'Before', ['class' => $class]));
        $after = $this->graph('exact-budget-after', 'after');
        $after->addNode(Node::make('node:exact-budget', 'method', 'After', ['class' => $class]));
        $beforeId = $this->store->publish($before)['generation']['id'];
        $afterId = $this->store->publish($after)['generation']['id'];
        $diff = $this->differ->diff($beforeId, $afterId, ['nodes'], 10);

        $this->assertSame([], $diff['changes']['nodes']);
        $this->assertSame(1, $diff['omitted']['nodes']);
        $this->assertTrue($diff['detailBudget']['byteBudgetExhausted']);
        $this->assertTrue($diff['truncated']);
    }

    public function test_changed_field_computation_has_a_bounded_work_budget(): void
    {
        $beforeMetadata = [];
        $afterMetadata = [];

        for ($index = 0; $index < 5000; $index++) {
            $beforeMetadata[sprintf('field_%05d', $index)] = 'before';
            $afterMetadata[sprintf('field_%05d', $index)] = 'after';
        }

        $before = $this->graph('field-work-before', 'before');
        $before->addNode(Node::make('node:work', 'method', 'Work', ['metadata' => $beforeMetadata]));
        $after = $this->graph('field-work-after', 'after');
        $after->addNode(Node::make('node:work', 'method', 'Work', ['metadata' => $afterMetadata]));
        $beforeId = $this->store->publish($before)['generation']['id'];
        $afterId = $this->store->publish($after)['generation']['id'];
        $detail = $this->differ->diff($beforeId, $afterId, ['nodes'], 10)['changes']['nodes'][0];

        $this->assertTrue($detail['changedFieldsTruncated']);
        $this->assertTrue($detail['changedFieldCountIsLowerBound']);
        $this->assertLessThanOrEqual(64, count($detail['changedFields']));
    }

    public function test_absolute_in_project_changed_file_uses_the_shared_path_contract(): void
    {
        $before = $this->store->publish($this->graph('path-before', 'before'))['generation']['id'];
        $after = $this->store->publish($this->graph('path-after', 'after'))['generation']['id'];
        $absolute = getcwd().'/app/OrderAction.php';
        $verification = $this->verifier->verify($before, $after, changedFiles: [$absolute]);

        $this->assertSame('app/OrderAction.php', $verification['sourceCoverage']['requestedFiles'][0]['path']);
        $this->assertSame('changed', $verification['sourceCoverage']['requestedFiles'][0]['manifestStatus']);
    }

    public function test_absolute_changed_file_cannot_escape_the_project_lexically(): void
    {
        $before = $this->store->publish($this->graph('escape-before', 'before'))['generation']['id'];
        $after = $this->store->publish($this->graph('escape-after', 'after'))['generation']['id'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must resolve inside the project');

        $this->verifier->verify(
            $before,
            $after,
            changedFiles: [(getcwd() ?: '.').'/app/../../outside.php'],
        );
    }

    /** @return array{string, string} */
    private function publishRichChange(): array
    {
        $route = 'route:PUT:/orders/{order}';
        $before = $this->graph('before', 'before-file');

        foreach ([
            Node::make($route, 'route', 'PUT /orders/{order}'),
            Node::make('App\\OrderAction::before', 'method', 'OrderAction::before', ['file' => 'app/OrderAction.php', 'line' => 10]),
            Node::make('App\\OrderPolicy::update', 'policy', 'OrderPolicy::update'),
            Node::make('App\\Jobs\\SyncOrder', 'job', 'SyncOrder'),
            Node::make('App\\Listeners\\SyncOrder::handle', 'method', 'SyncOrder::handle'),
            Node::make('Tests\\Feature\\OrderTest::test_update', 'test', 'OrderTest::test_update'),
            Node::make('table:orders', 'table', 'orders'),
        ] as $node) {
            $before->addNode($node);
        }

        foreach ([
            new Edge($route, 'App\\OrderAction::before', 'routes_to'),
            new Edge('App\\OrderAction::before', 'table:orders', 'writes', 1.0, ['operation' => 'update', 'fields' => ['status']]),
            new Edge('App\\OrderAction::before', 'App\\OrderPolicy::update', 'authorizes_via', 1.0, ['ability' => 'update']),
            new Edge('App\\OrderAction::before', 'App\\Jobs\\SyncOrder', 'dispatches', 1.0, ['queue' => 'old', 'afterCommit' => false]),
            new Edge('App\\Jobs\\SyncOrder', 'App\\Listeners\\SyncOrder::handle', 'handled_by'),
            new Edge('Tests\\Feature\\OrderTest::test_update', $route, 'tests_route'),
        ] as $edge) {
            $before->addEdge($edge);
        }

        $beforeId = $this->store->publish($before)['generation']['id'];
        $after = $this->graph('after', 'after-file', routeLabel: 'PUT /orders/{order} updated');

        foreach ([
            Node::make($route, 'route', 'PUT /orders/{order} updated'),
            Node::make('App\\OrderAction::before', 'method', 'OrderAction::before', ['file' => 'app/OrderAction.php', 'line' => 10]),
            Node::make('App\\OrderAction::after', 'method', 'OrderAction::after', ['file' => 'app/OrderAction.php', 'line' => 30]),
            Node::make('App\\OrderPolicy::update', 'policy', 'OrderPolicy::update'),
            Node::make('App\\Jobs\\SyncOrder', 'job', 'SyncOrder'),
            Node::make('App\\Listeners\\SyncOrder::handle', 'method', 'SyncOrder::handle'),
            Node::make('Tests\\Feature\\OrderTest::test_update', 'test', 'OrderTest::test_update'),
            Node::make('table:orders', 'table', 'orders'),
        ] as $node) {
            $after->addNode($node);
        }

        foreach ([
            new Edge($route, 'App\\OrderAction::after', 'routes_to'),
            new Edge('App\\OrderAction::after', 'table:orders', 'writes', 1.0, ['operation' => 'update', 'fields' => ['status', 'synced_at']]),
            new Edge('App\\OrderAction::before', 'App\\Jobs\\SyncOrder', 'dispatches', 1.0, ['queue' => 'new', 'afterCommit' => true]),
        ] as $edge) {
            $after->addEdge($edge);
        }

        $afterId = $this->store->publish($after)['generation']['id'];

        return [$beforeId, $afterId];
    }

    private function graph(
        string $source,
        string $fileHash,
        string $routeLabel = 'unchanged',
    ): Graph {
        return new Graph([
            'generatedAt' => '2026-07-15T12:00:00.000000Z',
            'appName' => 'Diff Test',
            'laravelVersion' => '12.0.0',
            'appgraphVersion' => 'test',
            'scan' => [
                'fingerprint' => $source,
                'configuration' => 'config-a',
                'applicationEnvironment' => 'testing',
                'files' => ['app/OrderAction.php' => hash('sha256', $fileHash)],
            ],
            'fixture' => $routeLabel,
        ]);
    }

    /**
     * @param array<string, array{file: string, label: string}> $nodes
     * @param array<string, string> $files
     */
    private function graphWithFiles(string $source, array $nodes, array $files): Graph
    {
        $graph = new Graph([
            'generatedAt' => '2026-07-15T12:00:00.000000Z',
            'appName' => 'Diff Test',
            'laravelVersion' => '12.0.0',
            'appgraphVersion' => 'test',
            'scan' => [
                'fingerprint' => $source,
                'configuration' => 'config-a',
                'applicationEnvironment' => 'testing',
                'files' => array_map(static fn (string $value): string => hash('sha256', $value), $files),
            ],
        ]);

        foreach ($nodes as $id => $node) {
            $graph->addNode(Node::make($id, 'method', $node['label'], ['file' => $node['file']]));
        }

        return $graph;
    }
}
