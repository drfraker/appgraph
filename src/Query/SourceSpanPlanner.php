<?php

namespace AppGraph\Query;

use InvalidArgumentException;

class SourceSpanPlanner
{
    public const MIN_TOKEN_BUDGET = 512;

    public const MAX_TOKEN_BUDGET = 16000;

    public const MAX_FILES = 32;

    public const MAX_SPANS = 64;

    public const MAX_LINES_PER_SPAN = 200;

    public const MAX_TOKENS_PER_SPAN = 1200;

    public const MAX_TOKENS_PER_FILE = 2000;

    /** Hard work/memory bound for every source file considered by the planner. */
    public const MAX_SOURCE_FILE_BYTES = 2000000;

    private const APPROXIMATE_WINDOW_LINES = 80;

    private const MAX_NODE_IDS_PER_SPAN = 64;

    private const MAX_UNCERTAINTIES = 64;

    /** @var array<int, array<string, mixed>> */
    private array $uncertainties = [];

    /** @var array<string, int> */
    private array $uncertaintyCounts = [];

    /** @var array<string, int> */
    private array $omitted = [];

    /** @var array<string, bool> */
    private array $lineBeyondCache = [];

    /** @var array<string, array<int, int>> */
    private array $spanOffsets = [];

    private string $basePath;

    public function __construct(string $basePath)
    {
        $resolved = realpath($basePath);

        if ($resolved === false || ! is_dir($resolved) || ! is_readable($resolved)) {
            throw new InvalidArgumentException("Source span base path [{$basePath}] is not a readable directory.");
        }

        $normalized = rtrim($this->normalizePath($resolved), '/');
        $this->basePath = $normalized === '' ? '/' : $normalized;
    }

    /**
     * Plan bounded source-reading spans without returning source text.
     *
     * Candidates are expected to be ranked graph context records with this shape:
     * `array{id?: string, file: string, line?: int, endLine?: int,
     * relevance?: float, score?: float, reason?: string, reasons?: list<string>}`.
     * Exact `line`/`endLine` pairs are preferred. Missing bounds receive a bounded
     * approximate window. Reasons `explicit_target`/`context_target` and
     * `changed_file` are always ranked ahead of inferred context.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array<string, mixed>
     */
    public function plan(
        array $candidates,
        int $requestedTokenBudget,
        ?int $effectiveTokenBudget = null,
    ): array {
        $this->resetDiagnostics();

        $boundedRequested = $this->boundBudget($requestedTokenBudget);
        $effective = min(
            $boundedRequested,
            $this->boundBudget($effectiveTokenBudget ?? $boundedRequested),
        );
        $normalized = array_map(
            fn (array $candidate): array => $this->normalizeCandidate($candidate),
            $candidates,
        );
        usort($normalized, fn (array $left, array $right): int => $this->compareCandidates($left, $right));

        $fileGroups = $this->groupCandidatesByFile($normalized);
        $spans = $this->candidateSpans($fileGroups);
        usort($spans, fn (array $left, array $right): int => $this->compareSpans($left, $right));

        if (count($spans) > self::MAX_SPANS) {
            $omitted = count($spans) - self::MAX_SPANS;
            $this->omit('span_limit', $omitted);
            $this->addUncertainty('source_span_limit', ['count' => $omitted]);
            $spans = array_slice($spans, 0, self::MAX_SPANS);
        }

        $this->prepareSpanOffsets($spans);

        $readSet = [];
        $perFileTokens = [];
        $usedTokens = 0;

        foreach ($spans as $span) {
            $remainingTokens = $effective - $usedTokens;

            if ($remainingTokens <= 0) {
                $this->omit('token_budget');
                $this->addUncertainty('source_reading_budget_exhausted', [
                    'file' => $span['file'],
                    'startLine' => $span['startLine'],
                ]);
                continue;
            }

            $fileTokens = $perFileTokens[$span['file']] ?? 0;
            $remainingFileTokens = self::MAX_TOKENS_PER_FILE - $fileTokens;

            if ($remainingFileTokens <= 0) {
                $this->omit('file_token_limit');
                $this->addUncertainty('source_file_token_limit', ['file' => $span['file']]);
                continue;
            }

            $lines = $this->readSpan($span);

            if ($lines === null) {
                continue;
            }

            if ($lines === []) {
                $this->omit('out_of_range');
                $this->addUncertainty('source_span_out_of_range', [
                    'file' => $span['file'],
                    'startLine' => $span['startLine'],
                    'endLine' => $span['endLine'],
                ]);
                continue;
            }

            $actualEndLine = (int) array_key_last($lines);
            $exact = $span['exact'];

            if ($exact && $actualEndLine < $span['endLine']) {
                $exact = false;
                $this->omit('out_of_range');
                $this->addUncertainty('source_span_clamped_to_file', [
                    'file' => $span['file'],
                    'startLine' => $span['startLine'],
                    'requestedEndLine' => $span['endLine'],
                    'actualEndLine' => $actualEndLine,
                ]);
            }

            $fullTokens = $this->estimateTokens(implode('', $lines));
            $allowedTokens = min(
                self::MAX_TOKENS_PER_SPAN,
                $remainingFileTokens,
                $remainingTokens,
            );
            [$selectedLines, $estimatedTokens] = $this->fitLinesToTokenLimit($lines, $allowedTokens);

            if ($selectedLines === []) {
                $reason = $this->limitingTokenReason($remainingTokens, $remainingFileTokens);
                $this->omit($reason);
                $this->addUncertainty($this->tokenUncertaintyReason($reason), [
                    'file' => $span['file'],
                    'startLine' => $span['startLine'],
                ]);
                continue;
            }

            if (count($selectedLines) < count($lines)) {
                $exact = false;

                if ($fullTokens > self::MAX_TOKENS_PER_SPAN) {
                    $this->omit('span_token_limit');
                    $this->addUncertainty('source_span_token_limit', [
                        'file' => $span['file'],
                        'startLine' => $span['startLine'],
                    ]);
                }

                if ($fullTokens > $remainingFileTokens) {
                    $this->omit('file_token_limit');
                    $this->addUncertainty('source_file_token_limit', ['file' => $span['file']]);
                }

                if ($fullTokens > $remainingTokens) {
                    $this->omit('token_budget');
                    $this->addUncertainty('source_reading_budget_exhausted', [
                        'file' => $span['file'],
                        'startLine' => $span['startLine'],
                    ]);
                }
            }

            $selectedEndLine = (int) array_key_last($selectedLines);
            $usedTokens += $estimatedTokens;
            $perFileTokens[$span['file']] = $fileTokens + $estimatedTokens;

            $readSet[$span['file']] ??= [
                'file' => $span['file'],
                'relevance' => $span['relevance'],
                'estimatedTokens' => 0,
                'reasons' => [],
                'nodes' => [],
                'spans' => [],
                '_priority' => $span['priority'],
            ];
            $readSet[$span['file']]['relevance'] = max(
                $readSet[$span['file']]['relevance'],
                $span['relevance'],
            );
            $readSet[$span['file']]['estimatedTokens'] += $estimatedTokens;
            $readSet[$span['file']]['reasons'] = $this->sortReasons([
                ...$readSet[$span['file']]['reasons'],
                ...$span['reasons'],
            ]);
            $readSet[$span['file']]['nodes'] = $this->sortedUnique([
                ...$readSet[$span['file']]['nodes'],
                ...$span['nodeIds'],
            ]);
            $readSet[$span['file']]['spans'][] = [
                'startLine' => (int) array_key_first($selectedLines),
                'endLine' => $selectedEndLine,
                'exact' => $exact && $selectedEndLine === $span['endLine'],
                'estimatedTokens' => $estimatedTokens,
                'nodeIds' => $span['nodeIds'],
            ];
        }

        $readSet = array_values($readSet);

        foreach ($readSet as &$entry) {
            usort(
                $entry['spans'],
                static fn (array $left, array $right): int => [$left['startLine'], $left['endLine']]
                    <=> [$right['startLine'], $right['endLine']],
            );
            $entry['relevance'] = round($entry['relevance'], 4);
        }
        unset($entry);

        usort($readSet, static function (array $left, array $right): int {
            return [$left['_priority'], -$left['relevance'], $left['file']]
                <=> [$right['_priority'], -$right['relevance'], $right['file']];
        });

        foreach ($readSet as &$entry) {
            unset($entry['_priority']);
        }
        unset($entry);

        usort($this->uncertainties, static function (array $left, array $right): int {
            return [
                $left['reason'] ?? '',
                $left['file'] ?? '',
                $left['nodeId'] ?? '',
                $left['startLine'] ?? 0,
            ] <=> [
                $right['reason'] ?? '',
                $right['file'] ?? '',
                $right['nodeId'] ?? '',
                $right['startLine'] ?? 0,
            ];
        });
        ksort($this->uncertaintyCounts);

        $nodes = [];

        foreach ($readSet as $entry) {
            $nodes = [...$nodes, ...$entry['nodes']];
        }

        return [
            'readSet' => $readSet,
            'budget' => [
                'requestedTokens' => $requestedTokenBudget,
                'effectiveTokens' => $effective,
                'usedTokens' => $usedTokens,
                'remainingTokens' => max(0, $effective - $usedTokens),
                'estimator' => 'ceil(utf8_bytes/4)',
                'consumed' => [
                    'candidates' => count($candidates),
                    'files' => count($readSet),
                    'spans' => array_sum(array_map(
                        static fn (array $entry): int => count($entry['spans']),
                        $readSet,
                    )),
                    'nodes' => count($this->sortedUnique($nodes)),
                ],
            ],
            'uncertainties' => $this->uncertainties,
            'uncertaintyCounts' => $this->uncertaintyCounts,
            'omitted' => $this->omitted,
            'truncated' => array_sum($this->omitted) > 0,
        ];
    }

    private function resetDiagnostics(): void
    {
        $this->uncertainties = [];
        $this->uncertaintyCounts = [];
        $this->omitted = [
            'absolute_path' => 0,
            'outside_root' => 0,
            'missing_file' => 0,
            'unreadable_file' => 0,
            'file_byte_limit' => 0,
            'invalid_file' => 0,
            'invalid_line' => 0,
            'file_limit' => 0,
            'span_limit' => 0,
            'span_line_limit' => 0,
            'span_token_limit' => 0,
            'file_token_limit' => 0,
            'token_budget' => 0,
            'out_of_range' => 0,
            'node_reference_limit' => 0,
            'uncertainty_limit' => 0,
        ];
        $this->lineBeyondCache = [];
        $this->spanOffsets = [];
    }

    /** @param array<string, mixed> $candidate */
    private function normalizeCandidate(array $candidate): array
    {
        $file = is_string($candidate['file'] ?? null) ? $candidate['file'] : '';
        $id = is_string($candidate['id'] ?? null) && $candidate['id'] !== ''
            ? $candidate['id']
            : 'file:'.$file;
        $reasons = [];

        if (is_string($candidate['reason'] ?? null) && $candidate['reason'] !== '') {
            $reasons[] = $candidate['reason'];
        }

        $additionalReasons = is_array($candidate['reasons'] ?? null)
            ? $candidate['reasons']
            : [];

        foreach ($additionalReasons as $reason) {
            if (is_string($reason) && $reason !== '') {
                $reasons[] = $reason;
            }
        }

        if ($reasons === []) {
            $reasons[] = 'graph_context';
        }

        $reasons = $this->sortReasons($reasons);
        $score = $candidate['relevance'] ?? $candidate['score'] ?? 0.0;
        $relevance = is_numeric($score) ? (float) $score : 0.0;

        return [
            'id' => $id,
            'file' => $file,
            'line' => $candidate['line'] ?? null,
            'endLine' => $candidate['endLine'] ?? null,
            'relevance' => max(0.0, min(1.0, $relevance)),
            'reasons' => $reasons,
            'priority' => $this->reasonPriority($reasons),
        ];
    }

    private function compareCandidates(array $left, array $right): int
    {
        return [
            $left['priority'],
            -$left['relevance'],
            $left['file'],
            is_int($left['line']) ? $left['line'] : PHP_INT_MAX,
            $left['id'],
        ] <=> [
            $right['priority'],
            -$right['relevance'],
            $right['file'],
            is_int($right['line']) ? $right['line'] : PHP_INT_MAX,
            $right['id'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    private function groupCandidatesByFile(array $candidates): array
    {
        $groups = [];

        foreach ($candidates as $candidate) {
            $resolved = $this->resolveFile($candidate['file'], $candidate['id']);

            if ($resolved === null) {
                continue;
            }

            $candidate['file'] = $resolved['file'];
            $candidate['absolute'] = $resolved['absolute'];
            $groups[$resolved['file']] ??= [
                'file' => $resolved['file'],
                'absolute' => $resolved['absolute'],
                'priority' => $candidate['priority'],
                'relevance' => $candidate['relevance'],
                'candidates' => [],
            ];
            $groups[$resolved['file']]['priority'] = min(
                $groups[$resolved['file']]['priority'],
                $candidate['priority'],
            );
            $groups[$resolved['file']]['relevance'] = max(
                $groups[$resolved['file']]['relevance'],
                $candidate['relevance'],
            );
            $groups[$resolved['file']]['candidates'][] = $candidate;
        }

        $groups = array_values($groups);
        usort($groups, static function (array $left, array $right): int {
            return [$left['priority'], -$left['relevance'], $left['file']]
                <=> [$right['priority'], -$right['relevance'], $right['file']];
        });

        if (count($groups) > self::MAX_FILES) {
            foreach (array_slice($groups, self::MAX_FILES) as $group) {
                $this->omit('file_limit');
                $this->addUncertainty('source_file_limit', ['file' => $group['file']]);
            }

            $groups = array_slice($groups, 0, self::MAX_FILES);
        }

        return $groups;
    }

    /** @return array{file: string, absolute: string}|null */
    private function resolveFile(string $file, string $nodeId): ?array
    {
        if ($file === '' || str_contains($file, "\0")) {
            $this->omit('invalid_file');
            $this->addUncertainty('invalid_source_file', ['file' => $file, 'nodeId' => $nodeId]);

            return null;
        }

        $normalized = $this->normalizePath($file);

        if (str_starts_with($normalized, '/') || preg_match('/^[A-Z]:\//i', $normalized) === 1) {
            $this->omit('absolute_path');
            $this->addUncertainty('absolute_source_path_rejected', ['file' => $file, 'nodeId' => $nodeId]);

            return null;
        }

        $segments = [];

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    $this->omit('outside_root');
                    $this->addUncertainty('source_path_outside_root', ['file' => $file, 'nodeId' => $nodeId]);

                    return null;
                }

                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            $this->omit('invalid_file');
            $this->addUncertainty('invalid_source_file', ['file' => $file, 'nodeId' => $nodeId]);

            return null;
        }

        $resolved = realpath($this->basePath.'/'.implode('/', $segments));

        if ($resolved === false) {
            $this->omit('missing_file');
            $this->addUncertainty('missing_source_file', ['file' => $file, 'nodeId' => $nodeId]);

            return null;
        }

        $resolved = $this->normalizePath($resolved);

        if (! $this->isInsideBasePath($resolved)) {
            $this->omit('outside_root');
            $this->addUncertainty('source_path_outside_root', ['file' => $file, 'nodeId' => $nodeId]);

            return null;
        }

        if (! is_file($resolved)) {
            $this->omit('invalid_file');
            $this->addUncertainty('invalid_source_file', ['file' => $file, 'nodeId' => $nodeId]);

            return null;
        }

        if (! is_readable($resolved)) {
            $this->omit('unreadable_file');
            $this->addUncertainty('unreadable_source_file', ['file' => $file, 'nodeId' => $nodeId]);

            return null;
        }

        $size = @filesize($resolved);

        if (! is_int($size)) {
            $this->omit('unreadable_file');
            $this->addUncertainty('unreadable_source_file', ['file' => $file, 'nodeId' => $nodeId]);

            return null;
        }

        if ($size > self::MAX_SOURCE_FILE_BYTES) {
            $this->omit('file_byte_limit');
            $this->addUncertainty('source_file_byte_limit', [
                'file' => $file,
                'nodeId' => $nodeId,
                'bytes' => $size,
                'limit' => self::MAX_SOURCE_FILE_BYTES,
            ]);

            return null;
        }

        return [
            'file' => $this->basePath === '/'
                ? ltrim($resolved, '/')
                : substr($resolved, strlen($this->basePath) + 1),
            'absolute' => $resolved,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $fileGroups
     * @return array<int, array<string, mixed>>
     */
    private function candidateSpans(array $fileGroups): array
    {
        $spans = [];

        foreach ($fileGroups as $group) {
            $fileSpans = [];

            foreach ($group['candidates'] as $candidate) {
                $fileSpans[] = $this->spanForCandidate($candidate, $group['absolute']);
            }

            foreach ($this->mergeSpans($fileSpans) as $span) {
                $span['file'] = $group['file'];
                $span['absolute'] = $group['absolute'];
                $spans[] = $span;
            }
        }

        return $spans;
    }

    /** @param array<string, mixed> $candidate */
    private function spanForCandidate(array $candidate, string $absolute): array
    {
        $line = is_int($candidate['line']) && $candidate['line'] > 0
            ? $candidate['line']
            : null;
        $endLine = is_int($candidate['endLine']) && $candidate['endLine'] > 0
            ? $candidate['endLine']
            : null;

        if ($candidate['line'] !== null && $line === null) {
            $this->omit('invalid_line');
            $this->addUncertainty('invalid_source_line', [
                'file' => $candidate['file'],
                'nodeId' => $candidate['id'],
            ]);
        }

        if ($candidate['endLine'] !== null && ($endLine === null || $line === null || $endLine < $line)) {
            $this->omit('invalid_line');
            $this->addUncertainty('invalid_source_end_line', [
                'file' => $candidate['file'],
                'nodeId' => $candidate['id'],
            ]);
        }

        $exact = $line !== null && $endLine !== null && $endLine >= $line;

        if ($exact) {
            $start = $line;
            $end = $endLine;
        } elseif ($line !== null) {
            $start = max(1, $line - 5);
            $end = $start + self::APPROXIMATE_WINDOW_LINES - 1;
        } else {
            $start = 1;
            $end = self::MAX_LINES_PER_SPAN;

            if ($this->hasLineBeyond($absolute, self::MAX_LINES_PER_SPAN)) {
                $this->omit('span_line_limit');
                $this->addUncertainty('source_span_line_limit', [
                    'file' => $candidate['file'],
                    'nodeId' => $candidate['id'],
                    'startLine' => $start,
                ]);
            }
        }

        if (! $exact) {
            $this->addUncertainty('approximate_source_span', [
                'file' => $candidate['file'],
                'nodeId' => $candidate['id'],
                'line' => $line,
            ]);
        }

        if ($end - $start + 1 > self::MAX_LINES_PER_SPAN) {
            $end = $start + self::MAX_LINES_PER_SPAN - 1;
            $exact = false;
            $this->omit('span_line_limit');
            $this->addUncertainty('source_span_line_limit', [
                'file' => $candidate['file'],
                'nodeId' => $candidate['id'],
                'startLine' => $start,
            ]);
        }

        return [
            'file' => $candidate['file'],
            'absolute' => $absolute,
            'startLine' => $start,
            'endLine' => $end,
            'exact' => $exact,
            'relevance' => $candidate['relevance'],
            'priority' => $candidate['priority'],
            'reasons' => $candidate['reasons'],
            'nodeIds' => [$candidate['id']],
        ];
    }

    private function hasLineBeyond(string $absolute, int $lineLimit): bool
    {
        $cacheKey = $absolute."\0".$lineLimit;

        if (array_key_exists($cacheKey, $this->lineBeyondCache)) {
            return $this->lineBeyondCache[$cacheKey];
        }

        $handle = @fopen($absolute, 'rb');

        if ($handle === false) {
            return $this->lineBeyondCache[$cacheKey] = false;
        }

        $line = 0;

        try {
            while ($line <= $lineLimit && fgets($handle) !== false) {
                $line++;
            }
        } finally {
            fclose($handle);
        }

        return $this->lineBeyondCache[$cacheKey] = $line > $lineLimit;
    }

    /**
     * @param array<int, array<string, mixed>> $spans
     * @return array<int, array<string, mixed>>
     */
    private function mergeSpans(array $spans): array
    {
        usort($spans, static function (array $left, array $right): int {
            return [
                $left['startLine'],
                $left['endLine'],
                $left['priority'],
                -$left['relevance'],
                $left['nodeIds'][0],
            ] <=> [
                $right['startLine'],
                $right['endLine'],
                $right['priority'],
                -$right['relevance'],
                $right['nodeIds'][0],
            ];
        });
        $merged = [];

        foreach ($spans as $span) {
            $lastIndex = array_key_last($merged);

            if ($lastIndex === null) {
                $merged[] = $span;
                continue;
            }

            $last = $merged[$lastIndex];

            if ($span['startLine'] < $last['startLine']) {
                // A prior oversized overlap may have been split at the hard line
                // boundary. Clamp subsequent overlapping input to that remainder
                // so the final plan never charges the same source line twice.
                $span['startLine'] = $last['startLine'];
                $span['exact'] = false;
            }

            $unionStart = min($last['startLine'], $span['startLine']);
            $unionEnd = max($last['endLine'], $span['endLine']);
            $touches = $span['startLine'] <= $last['endLine'] + 1;

            if (! $touches) {
                $merged[] = $span;
                continue;
            }

            if ($unionEnd - $unionStart + 1 > self::MAX_LINES_PER_SPAN) {
                // Preserve the complete union without charging overlapping source
                // twice. The second bounded segment is no longer an exact node span.
                $span['startLine'] = $last['endLine'] + 1;
                $span['exact'] = false;
                $merged[] = $span;

                continue;
            }

            $nodeIds = $this->sortedUnique([...$last['nodeIds'], ...$span['nodeIds']]);

            if (count($nodeIds) > self::MAX_NODE_IDS_PER_SPAN) {
                $this->omit('node_reference_limit', count($nodeIds) - self::MAX_NODE_IDS_PER_SPAN);
                $nodeIds = array_slice($nodeIds, 0, self::MAX_NODE_IDS_PER_SPAN);
            }

            $merged[$lastIndex] = [
                'file' => $last['file'],
                'absolute' => $last['absolute'],
                'startLine' => $unionStart,
                'endLine' => $unionEnd,
                'exact' => $last['exact'] && $span['exact'],
                'relevance' => max($last['relevance'], $span['relevance']),
                'priority' => min($last['priority'], $span['priority']),
                'reasons' => $this->sortReasons([...$last['reasons'], ...$span['reasons']]),
                'nodeIds' => $nodeIds,
            ];
        }

        return $merged;
    }

    private function compareSpans(array $left, array $right): int
    {
        return [
            $left['priority'],
            -$left['relevance'],
            $left['file'],
            $left['startLine'],
            $left['endLine'],
        ] <=> [
            $right['priority'],
            -$right['relevance'],
            $right['file'],
            $right['startLine'],
            $right['endLine'],
        ];
    }

    /** @return array<int, string>|null */
    private function readSpan(array $span): ?array
    {
        $resolved = realpath($span['absolute']);

        if ($resolved === false || $this->normalizePath($resolved) !== $span['absolute']) {
            $this->omit('missing_file');
            $this->addUncertainty('missing_source_file', ['file' => $span['file']]);

            return null;
        }

        $handle = @fopen($resolved, 'rb');

        if ($handle === false) {
            $this->omit('unreadable_file');
            $this->addUncertainty('unreadable_source_file', ['file' => $span['file']]);

            return null;
        }

        $lines = [];
        $offset = $this->spanOffsets[$resolved][$span['startLine']] ?? null;

        if (! is_int($offset)) {
            fclose($handle);

            return [];
        }

        if (fseek($handle, $offset) !== 0) {
            fclose($handle);
            $this->omit('unreadable_file');
            $this->addUncertainty('unreadable_source_file', ['file' => $span['file']]);

            return null;
        }

        $lineNumber = $span['startLine'] - 1;

        try {
            while (($contents = fgets($handle)) !== false) {
                $lineNumber++;

                if ($lineNumber > $span['endLine']) {
                    break;
                }

                $lines[$lineNumber] = $contents;
            }
        } finally {
            fclose($handle);
        }

        return $lines;
    }

    /** @param array<int, array<string, mixed>> $spans */
    private function prepareSpanOffsets(array $spans): void
    {
        $startsByFile = [];

        foreach ($spans as $span) {
            $startsByFile[$span['absolute']][(int) $span['startLine']] = true;
        }

        foreach ($startsByFile as $absolute => $starts) {
            ksort($starts);
            $wanted = array_keys($starts);
            $handle = @fopen($absolute, 'rb');

            if ($handle === false) {
                continue;
            }

            $wantedIndex = 0;
            $line = 1;

            try {
                while (isset($wanted[$wantedIndex])) {
                    $position = ftell($handle);

                    if (! is_int($position)) {
                        break;
                    }

                    while (isset($wanted[$wantedIndex]) && $wanted[$wantedIndex] === $line) {
                        $this->spanOffsets[$absolute][$line] = $position;
                        $wantedIndex++;
                    }

                    if (! isset($wanted[$wantedIndex]) || fgets($handle) === false) {
                        break;
                    }

                    $line++;
                }
            } finally {
                fclose($handle);
            }
        }
    }

    /**
     * @param array<int, string> $lines
     * @return array{0: array<int, string>, 1: int}
     */
    private function fitLinesToTokenLimit(array $lines, int $tokenLimit): array
    {
        $selected = [];
        $bytes = '';

        foreach ($lines as $lineNumber => $line) {
            $candidate = $bytes.$line;

            if ($this->estimateTokens($candidate) > $tokenLimit) {
                break;
            }

            $bytes = $candidate;
            $selected[$lineNumber] = $line;
        }

        return [$selected, $this->estimateTokens($bytes)];
    }

    private function limitingTokenReason(int $remainingTokens, int $remainingFileTokens): string
    {
        $minimum = min(self::MAX_TOKENS_PER_SPAN, $remainingFileTokens, $remainingTokens);

        if ($minimum === $remainingTokens) {
            return 'token_budget';
        }

        if ($minimum === $remainingFileTokens) {
            return 'file_token_limit';
        }

        return 'span_token_limit';
    }

    private function tokenUncertaintyReason(string $reason): string
    {
        return match ($reason) {
            'token_budget' => 'source_reading_budget_exhausted',
            'file_token_limit' => 'source_file_token_limit',
            default => 'source_span_token_limit',
        };
    }

    /** @param array<int, string> $reasons */
    private function reasonPriority(array $reasons): int
    {
        if (array_intersect($reasons, ['explicit_target', 'context_target', 'explicit']) !== []) {
            return 0;
        }

        if (array_intersect($reasons, ['changed_file', 'changed']) !== []) {
            return 1;
        }

        return 2;
    }

    /**
     * @param array<int, string> $reasons
     * @return array<int, string>
     */
    private function sortReasons(array $reasons): array
    {
        $reasons = $this->sortedUnique($reasons);
        usort($reasons, function (string $left, string $right): int {
            return [$this->reasonPriority([$left]), $left]
                <=> [$this->reasonPriority([$right]), $right];
        });

        return $reasons;
    }

    /**
     * @param array<int, string> $values
     * @return array<int, string>
     */
    private function sortedUnique(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /** @param array<string, mixed> $context */
    private function addUncertainty(string $reason, array $context = []): void
    {
        $this->uncertaintyCounts[$reason] = ($this->uncertaintyCounts[$reason] ?? 0) + 1;

        if (count($this->uncertainties) >= self::MAX_UNCERTAINTIES) {
            $this->omit('uncertainty_limit');

            return;
        }

        $this->uncertainties[] = ['reason' => $reason] + array_filter(
            $context,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    private function omit(string $reason, int $count = 1): void
    {
        $this->omitted[$reason] = ($this->omitted[$reason] ?? 0) + $count;
    }

    private function boundBudget(int $budget): int
    {
        return max(self::MIN_TOKEN_BUDGET, min(self::MAX_TOKEN_BUDGET, $budget));
    }

    private function estimateTokens(string $source): int
    {
        return (int) ceil(strlen($source) / 4);
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function isInsideBasePath(string $path): bool
    {
        return $this->basePath === '/'
            ? str_starts_with($path, '/')
            : str_starts_with($path, $this->basePath.'/');
    }
}
