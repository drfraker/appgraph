<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Query\SourceSpanPlanner;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

class SourceSpanPlannerTest extends TestCase
{
    private string $sandbox;

    private string $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/appgraph-source-spans-'.bin2hex(random_bytes(4));
        $this->project = $this->sandbox.'/project';
        mkdir($this->project.'/app', 0775, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->sandbox);

        parent::tearDown();
    }

    public function test_it_merges_exact_spans_uses_bounded_fallbacks_and_estimates_actual_bytes(): void
    {
        $lines = array_map(
            static fn (int $line): string => sprintf("line-%02d\n", $line),
            range(1, 30),
        );
        $this->write('app/Service.php', implode('', $lines));
        $candidates = [
            [
                'id' => 'App\\Service::alpha',
                'file' => 'app/Service.php',
                'line' => 2,
                'endLine' => 4,
                'relevance' => 0.8,
                'reason' => 'lexical_match',
            ],
            [
                'id' => 'App\\Service::beta',
                'file' => 'app/Service.php',
                'line' => 4,
                'endLine' => 6,
                'relevance' => 0.7,
                'reason' => 'explicit_target',
            ],
            [
                'id' => 'App\\Service::legacy',
                'file' => 'app/Service.php',
                'line' => 20,
                'relevance' => 0.95,
                'reason' => 'changed_file',
            ],
        ];

        $planner = new SourceSpanPlanner($this->project);
        $result = $planner->plan($candidates, 4000);

        $this->assertCount(1, $result['readSet']);
        $entry = $result['readSet'][0];
        $this->assertSame('app/Service.php', $entry['file']);
        $this->assertSame(['explicit_target', 'changed_file', 'lexical_match'], $entry['reasons']);
        $this->assertSame([
            'App\Service::alpha',
            'App\Service::beta',
            'App\Service::legacy',
        ], $entry['nodes']);
        $this->assertCount(2, $entry['spans']);
        $this->assertSame([
            'startLine' => 2,
            'endLine' => 6,
            'exact' => true,
            'estimatedTokens' => (int) ceil(strlen(implode('', array_slice($lines, 1, 5))) / 4),
            'nodeIds' => ['App\Service::alpha', 'App\Service::beta'],
        ], $entry['spans'][0]);
        $this->assertSame(15, $entry['spans'][1]['startLine']);
        $this->assertSame(30, $entry['spans'][1]['endLine']);
        $this->assertFalse($entry['spans'][1]['exact']);
        $this->assertSame(1, $result['uncertaintyCounts']['approximate_source_span']);
        $this->assertSame($entry['estimatedTokens'], $result['budget']['usedTokens']);
        $this->assertSame('ceil(utf8_bytes/4)', $result['budget']['estimator']);
        $this->assertFalse($result['truncated']);
        $this->assertStringNotContainsString('line-02', json_encode($result, JSON_THROW_ON_ERROR));

        $reversed = $planner->plan(array_reverse($candidates), 4000);
        $this->assertSame($result, $reversed);
    }

    public function test_it_rejects_absolute_missing_and_outside_root_paths_before_planning_reads(): void
    {
        $valid = $this->write('app/Valid.php', "<?php\n");
        $outside = $this->sandbox.'/outside.php';
        file_put_contents($outside, "outside secret\n");
        symlink($outside, $this->project.'/app/Escape.php');

        $result = (new SourceSpanPlanner($this->project))->plan([
            ['id' => 'absolute', 'file' => $valid, 'line' => 1, 'endLine' => 1],
            ['id' => 'missing', 'file' => 'app/Missing.php', 'line' => 1, 'endLine' => 1],
            ['id' => 'traversal', 'file' => '../outside.php', 'line' => 1, 'endLine' => 1],
            ['id' => 'symlink', 'file' => 'app/Escape.php', 'line' => 1, 'endLine' => 1],
            ['id' => 'directory', 'file' => 'app', 'line' => 1, 'endLine' => 1],
        ], 4000);

        $this->assertSame([], $result['readSet']);
        $this->assertSame(1, $result['omitted']['absolute_path']);
        $this->assertSame(1, $result['omitted']['missing_file']);
        $this->assertSame(2, $result['omitted']['outside_root']);
        $this->assertSame(1, $result['omitted']['invalid_file']);
        $this->assertSame(1, $result['uncertaintyCounts']['absolute_source_path_rejected']);
        $this->assertSame(2, $result['uncertaintyCounts']['source_path_outside_root']);
        $this->assertTrue($result['truncated']);
        $this->assertStringNotContainsString('outside secret', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_it_prioritizes_explicit_and_changed_files_with_a_hard_file_limit(): void
    {
        $candidates = [];

        for ($index = 0; $index < 33; $index++) {
            $file = sprintf('app/File%02d.php', $index);
            $this->write($file, "<?php\n");
            $reason = match ($index) {
                32 => 'explicit_target',
                31 => 'changed_file',
                default => 'lexical_match',
            };
            $candidates[] = [
                'id' => 'node-'.$index,
                'file' => $file,
                'line' => 1,
                'endLine' => 1,
                'relevance' => $index === 32 ? 0.1 : 1.0,
                'reason' => $reason,
            ];
        }

        $result = (new SourceSpanPlanner($this->project))->plan($candidates, 16000);

        $this->assertCount(SourceSpanPlanner::MAX_FILES, $result['readSet']);
        $this->assertSame('app/File32.php', $result['readSet'][0]['file']);
        $this->assertSame('app/File31.php', $result['readSet'][1]['file']);
        $this->assertSame(1, $result['omitted']['file_limit']);
        $this->assertTrue($result['truncated']);
    }

    public function test_it_enforces_the_global_span_limit(): void
    {
        $this->write('app/Many.php', str_repeat("x\n", 200));
        $candidates = [];

        for ($index = 0; $index < 65; $index++) {
            $line = 1 + ($index * 3);
            $candidates[] = [
                'id' => 'node-'.$index,
                'file' => 'app/Many.php',
                'line' => $line,
                'endLine' => $line,
                'relevance' => 1 - ($index / 1000),
            ];
        }

        $result = (new SourceSpanPlanner($this->project))->plan($candidates, 16000);

        $this->assertCount(SourceSpanPlanner::MAX_SPANS, $result['readSet'][0]['spans']);
        $this->assertSame(1, $result['omitted']['span_limit']);
        $this->assertSame(SourceSpanPlanner::MAX_SPANS, $result['budget']['consumed']['spans']);
    }

    public function test_oversized_overlaps_are_split_without_duplicate_source_ranges(): void
    {
        $this->write('app/Overlap.php', str_repeat("x\n", 400));

        $result = (new SourceSpanPlanner($this->project))->plan([
            ['id' => 'one', 'file' => 'app/Overlap.php', 'line' => 1, 'endLine' => 200],
            ['id' => 'two', 'file' => 'app/Overlap.php', 'line' => 100, 'endLine' => 299],
            ['id' => 'three', 'file' => 'app/Overlap.php', 'line' => 150, 'endLine' => 349],
        ], 4000);

        $spans = $result['readSet'][0]['spans'];

        $this->assertSame([[1, 200], [201, 349]], array_map(
            static fn (array $span): array => [$span['startLine'], $span['endLine']],
            $spans,
        ));
        $this->assertLessThan($spans[1]['startLine'], $spans[0]['endLine']);
    }

    public function test_it_enforces_line_span_file_and_total_token_caps(): void
    {
        $line = str_repeat('x', 95)."\n";
        $this->write('app/Large.php', str_repeat($line, 500));

        $planner = new SourceSpanPlanner($this->project);
        $result = $planner->plan([
            [
                'id' => 'large-one',
                'file' => 'app/Large.php',
                'line' => 1,
                'endLine' => 250,
                'reason' => 'explicit_target',
            ],
            [
                'id' => 'large-two',
                'file' => 'app/Large.php',
                'line' => 251,
                'endLine' => 500,
                'reason' => 'changed_file',
            ],
        ], 20000, 20000);

        $entry = $result['readSet'][0];
        $this->assertSame(16000, $result['budget']['effectiveTokens']);
        $this->assertLessThanOrEqual(SourceSpanPlanner::MAX_TOKENS_PER_FILE, $entry['estimatedTokens']);
        $this->assertCount(2, $entry['spans']);
        $this->assertSame(2, $result['omitted']['span_line_limit']);
        $this->assertGreaterThanOrEqual(2, $result['omitted']['span_token_limit']);
        $this->assertGreaterThanOrEqual(1, $result['omitted']['file_token_limit']);

        foreach ($entry['spans'] as $span) {
            $this->assertLessThanOrEqual(SourceSpanPlanner::MAX_LINES_PER_SPAN, $span['endLine'] - $span['startLine'] + 1);
            $this->assertLessThanOrEqual(SourceSpanPlanner::MAX_TOKENS_PER_SPAN, $span['estimatedTokens']);
            $this->assertFalse($span['exact']);
        }

        $minimum = $planner->plan([], 1, 1);
        $this->assertSame(1, $minimum['budget']['requestedTokens']);
        $this->assertSame(SourceSpanPlanner::MIN_TOKEN_BUDGET, $minimum['budget']['effectiveTokens']);
    }

    public function test_it_rejects_source_files_above_the_hard_byte_limit_before_reading(): void
    {
        $this->write(
            'app/Oversized.php',
            str_repeat('x', SourceSpanPlanner::MAX_SOURCE_FILE_BYTES + 1),
        );

        $result = (new SourceSpanPlanner($this->project))->plan([[
            'id' => 'oversized',
            'file' => 'app/Oversized.php',
            'line' => 1,
            'endLine' => 1,
        ]], 4000);

        $this->assertSame([], $result['readSet']);
        $this->assertSame(1, $result['omitted']['file_byte_limit']);
        $this->assertSame(1, $result['uncertaintyCounts']['source_file_byte_limit']);
        $this->assertTrue($result['truncated']);
    }

    public function test_file_only_fallback_reports_when_it_omits_lines_after_the_window(): void
    {
        $this->write('app/Long.php', str_repeat("x\n", SourceSpanPlanner::MAX_LINES_PER_SPAN + 1));

        $result = (new SourceSpanPlanner($this->project))->plan([[
            'id' => 'file:app/Long.php',
            'file' => 'app/Long.php',
            'reason' => 'changed_file',
        ]], 4000);

        $this->assertSame(1, $result['omitted']['span_line_limit']);
        $this->assertSame(1, $result['uncertaintyCounts']['source_span_line_limit']);
        $this->assertTrue($result['truncated']);
        $this->assertSame(SourceSpanPlanner::MAX_LINES_PER_SPAN, $result['readSet'][0]['spans'][0]['endLine']);
        $this->assertFalse($result['readSet'][0]['spans'][0]['exact']);
    }

    private function write(string $relative, string $contents): string
    {
        $path = $this->project.'/'.ltrim($relative, '/');
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, $contents);

        return $path;
    }
}
