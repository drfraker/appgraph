<?php

namespace AppGraph\Query;

use AppGraph\Support\ProjectPathNormalizer;
use InvalidArgumentException;

final class SlicePlanner
{
    private ProjectPathNormalizer $paths;

    public function __construct(
        private GraphIndex $index,
        private string $basePath,
    ) {
        $this->paths = new ProjectPathNormalizer($basePath);
    }

    /**
     * @param array<int, string> $anchors
     * @param array<int, string> $files
     * @return array{payload: array<string, mixed>, truncated: bool}
     */
    public function plan(array $anchors, array $files, int $readBudget, int $depth): array
    {
        if (count($anchors) > 20) {
            throw new InvalidArgumentException('AppGraph slice accepts at most 20 anchors.');
        }

        if (count($files) > 50) {
            throw new InvalidArgumentException('AppGraph slice accepts at most 50 files.');
        }

        $resolvedAnchors = [];
        $targets = [];
        $plannedFiles = [];

        foreach ($files as $position => $file) {
            if (! is_string($file)) {
                throw new InvalidArgumentException("AppGraph slice file at position {$position} must be a string.");
            }

            $normalized = $this->paths->normalize($file);
            $key = $normalized ?? "invalid\0{$file}";
            $plannedFiles[$key] = $normalized ?? $file;
        }

        foreach ($anchors as $position => $anchor) {
            if (! is_string($anchor)) {
                throw new InvalidArgumentException("AppGraph slice anchor at position {$position} must be a string.");
            }

            $resolved = $this->resolveAnchor($anchor);

            if (isset($resolved['file'])) {
                $file = $resolved['file'];
                $plannedFiles[$file] = $file;
                $resolvedAnchors[] = [
                    'input' => $anchor,
                    'resolved' => 'file:'.$file,
                ];

                continue;
            }

            $id = $resolved['id'];
            $targets[$id] = $id;
            $resolvedAnchors[] = [
                'input' => $anchor,
                'resolved' => $id,
            ];
        }

        if (count($plannedFiles) > 50) {
            throw new InvalidArgumentException(
                'AppGraph slice accepts at most 50 distinct files after folding file anchors.',
            );
        }

        $plan = (new TaskContextPlanner($this->index, $this->basePath))->plan(
            '',
            array_values($targets),
            array_values($plannedFiles),
            $readBudget,
            $depth,
            0.0,
        );
        $readSet = $plan['readSet'];
        $freshness = $this->freshness($readSet);
        $read = array_map(
            fn (array $entry): array => $this->readEntry($entry),
            $readSet,
        );
        usort($read, static fn (array $left, array $right): int => [
            $left['order'],
            -($left['_relevance'] ?? 0.0),
            $left['file'],
        ] <=> [
            $right['order'],
            -($right['_relevance'] ?? 0.0),
            $right['file'],
        ]);

        foreach ($read as &$entry) {
            unset($entry['_relevance']);
        }
        unset($entry);

        $mappedTests = [];
        $runtimeEvidence = [];

        foreach ($plan['verification']['mappedTests'] as $test) {
            if (is_string($test['file'] ?? null)) {
                $mappedTests[$test['file']] = $test['file'];
            }

            if (! is_string($test['file'] ?? null)
                || ! is_array($test['runtimeEvidence'] ?? null)) {
                continue;
            }

            $evidence = $test['runtimeEvidence'];
            $sources = array_values(array_filter(
                is_array($evidence['sources'] ?? null) ? $evidence['sources'] : [],
                static fn (mixed $source): bool => is_array($source)
                    && is_string($source['file'] ?? null),
            ));
            usort($sources, static fn (array $left, array $right): int => $left['file'] <=> $right['file']);
            $targets = array_values(array_filter(
                is_array($evidence['targets'] ?? null) ? $evidence['targets'] : [],
                static fn (mixed $target): bool => is_array($target)
                    && is_string($target['id'] ?? null)
                    && is_string($target['relationship'] ?? null),
            ));
            usort($targets, static fn (array $left, array $right): int => [
                $left['id'],
                $left['relationship'],
            ] <=> [
                $right['id'],
                $right['relationship'],
            ]);
            $row = [
                'testFile' => $test['file'],
                'provenance' => 'appgraph_runtime',
                'granularity' => 'file',
            ];

            if ($sources !== []) {
                $row['sources'] = $sources;
            }

            if ($targets !== []) {
                $row['targets'] = $targets;
            }

            $runtimeEvidence[$test['file']] = $row;
        }
        ksort($mappedTests);
        ksort($runtimeEvidence);
        $tests = array_filter([
            'mapped' => array_values($mappedTests),
            'runtimeEvidence' => array_values($runtimeEvidence),
            'commands' => $plan['verification']['commands'],
        ], static fn (array $value): bool => $value !== []);
        $omitted = array_filter(
            $plan['omitted'],
            static fn (mixed $count): bool => is_numeric($count) && (int) $count > 0,
        );
        $payload = [
            'freshness' => $freshness,
            'read' => $read,
            'budget' => [
                'requestedTokens' => $plan['budget']['requestedTokens'],
                'usedTokens' => $plan['budget']['usedTokens'],
                'estimator' => $plan['budget']['estimator'],
            ],
        ];

        if ($resolvedAnchors !== []) {
            $payload['anchors'] = $resolvedAnchors;
        }

        if ($tests !== []) {
            $payload['tests'] = $tests;
        }

        $gaps = $this->gaps($plan['uncertainties'], $plan['uncertaintyCounts']);

        if ($gaps !== []) {
            $payload['gaps'] = $gaps;
        }

        if ($omitted !== []) {
            $payload['omitted'] = $omitted;
        }

        return [
            'payload' => $payload,
            'truncated' => (bool) $plan['truncated'],
        ];
    }

    /** @return array{id: string}|array{file: string} */
    private function resolveAnchor(string $anchor): array
    {
        $anchor = trim($anchor);

        if ($anchor === '' || str_contains($anchor, "\0")) {
            throw new InvalidArgumentException('AppGraph slice anchors must be nonblank strings without NUL bytes.');
        }

        if (str_starts_with($anchor, 'file:')) {
            $file = $this->paths->normalize(substr($anchor, 5));

            if ($file === null) {
                throw new UnresolvedAnchorException($anchor);
            }

            return ['file' => $file];
        }

        $expectedTypes = null;
        $target = $anchor;

        if (str_starts_with($anchor, 'table:')) {
            $expectedTypes = ['table'];
        } elseif (str_starts_with($anchor, 'column:')) {
            $expectedTypes = ['column'];
        } elseif (str_starts_with($anchor, 'route:')) {
            $expectedTypes = ['route'];

            if ($this->index->node($anchor) === null) {
                $target = substr($anchor, 6);
            }
        } elseif (str_starts_with($anchor, 'method:')) {
            $expectedTypes = ['method'];
            $target = substr($anchor, 7);
        } elseif (str_starts_with($anchor, 'view:')) {
            $expectedTypes = ['view'];

            if ($this->index->node($anchor) === null) {
                $target = substr($anchor, 5);
            }
        } elseif (str_starts_with($anchor, 'class:')) {
            $expectedTypes = [
                'class',
                'event',
                'form_request',
                'job',
                'model',
                'policy',
                'trait',
            ];
            $target = substr($anchor, 6);
        }

        if (trim($target) === '') {
            throw new UnresolvedAnchorException($anchor);
        }

        $resolution = $this->index->resolveId($target);

        if (! is_string($resolution['id'] ?? null)) {
            throw new UnresolvedAnchorException(
                $anchor,
                array_slice($resolution['candidates'] ?? [], 0, 10),
            );
        }

        $id = $resolution['id'];
        $node = $this->index->node($id);

        if ($node === null
            || ($expectedTypes !== null && ! in_array($node['type'] ?? null, $expectedTypes, true))) {
            throw new UnresolvedAnchorException($anchor, [$id]);
        }

        return ['id' => $id];
    }

    /**
     * @param array<int, array<string, mixed>> $readSet
     * @return array<string, mixed>
     */
    private function freshness(array &$readSet): array
    {
        $scan = $this->index->meta()['scan'] ?? null;
        $recordedFiles = is_array($scan) && is_array($scan['files'] ?? null)
            ? $scan['files']
            : null;
        $manifestUsable = $recordedFiles !== null && ($scan['algorithm'] ?? null) === 'sha256';
        $staleFiles = [];
        $unverified = 0;

        foreach ($readSet as &$entry) {
            $file = $entry['file'] ?? null;

            if (! is_string($file) || ! $manifestUsable) {
                $unverified++;
                continue;
            }

            $expected = $recordedFiles[$file] ?? null;
            $absolute = $this->basePath.'/'.$file;

            if (! is_string($expected)
                || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1
                || ! is_file($absolute)
                || ! is_readable($absolute)) {
                $unverified++;
                continue;
            }

            $current = hash_file('sha256', $absolute);

            if (! is_string($current)) {
                $unverified++;
                continue;
            }

            if (! hash_equals($expected, $current)) {
                $entry['_stale'] = true;
                $staleFiles[$file] = $file;
            }
        }
        unset($entry);
        ksort($staleFiles);

        $state = $staleFiles !== []
            ? 'stale'
            : (($readSet === [] || $unverified > 0) ? 'unverified' : 'current');

        return array_filter([
            'state' => $state,
            'files' => $staleFiles !== [] ? array_values($staleFiles) : null,
            'unverified' => $unverified > 0 ? $unverified : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string, mixed> $entry @return array<string, mixed> */
    private function readEntry(array $entry): array
    {
        $stale = (bool) ($entry['_stale'] ?? false);
        $spans = array_map(
            static fn (array $span): array => [
                'start' => (int) $span['startLine'],
                'end' => (int) $span['endLine'],
                'exact' => ! $stale && (bool) ($span['exact'] ?? false),
            ],
            $entry['spans'] ?? [],
        );

        return array_filter([
            'file' => $entry['file'],
            'order' => $this->readOrder($entry['nodes'] ?? []),
            'stale' => $stale ?: null,
            'why' => array_values(array_filter(
                $entry['reasons'] ?? [],
                static fn (mixed $reason): bool => is_string($reason) && $reason !== '',
            )),
            'spans' => $spans,
            '_relevance' => (float) ($entry['relevance'] ?? 0.0),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<int, string> $nodeIds */
    private function readOrder(array $nodeIds): int
    {
        $ranks = [];

        foreach ($nodeIds as $id) {
            if (! is_string($id) || ($node = $this->index->node($id)) === null) {
                continue;
            }

            $incomingRoleRank = null;

            foreach ([
                'passes_through' => 10,
                'authorizes_via' => 30,
                'routes_to' => 40,
                'handled_by' => 70,
                'renders' => 75,
            ] as $edgeType => $rank) {
                $truncated = false;

                if ($this->index->rankedEdgesTo($id, [$edgeType], 1, $truncated) !== []) {
                    $incomingRoleRank = $rank;
                    break;
                }
            }

            if ($incomingRoleRank !== null) {
                $ranks[] = $incomingRoleRank;
                continue;
            }

            $ranks[] = match ($node['type'] ?? null) {
                'form_request' => 20,
                'policy' => 30,
                'route' => 40,
                'class', 'method', 'trait' => 50,
                'model' => 60,
                'event', 'job' => 70,
                'view' => 75,
                'frontend' => 80,
                'test' => 90,
                default => 55,
            };
        }

        return $ranks === [] ? 55 : min($ranks);
    }

    /**
     * @param array<int, array<string, mixed>> $uncertainties
     * @param array<string, int> $uncertaintyCounts
     * @return array<int, array<string, mixed>>
     */
    private function gaps(array $uncertainties, array $uncertaintyCounts): array
    {
        $gaps = [];

        foreach ($uncertainties as $uncertainty) {
            $code = $uncertainty['reason'] ?? null;

            if (! is_string($code) || $code === '') {
                continue;
            }

            $count = is_numeric($uncertainty['count'] ?? null)
                ? max(1, (int) $uncertainty['count'])
                : 1;
            $at = null;

            foreach (['caller', 'route', 'target', 'file', 'nodeId', 'from', 'to', 'column'] as $key) {
                if (is_string($uncertainty[$key] ?? null) && $uncertainty[$key] !== '') {
                    $at = $uncertainty[$key];
                    break;
                }
            }

            $gaps[$code] ??= ['code' => $code, 'count' => 0];
            $gaps[$code]['count'] += $count;

            if ($at !== null
                && (! isset($gaps[$code]['at']) || strcmp($at, $gaps[$code]['at']) < 0)) {
                $gaps[$code]['at'] = $at;
            }
        }

        foreach ($uncertaintyCounts as $code => $count) {
            if (! is_string($code) || $code === '' || $count < 1) {
                continue;
            }

            $gaps[$code] ??= ['code' => $code, 'count' => 0];
            $gaps[$code]['count'] = max($gaps[$code]['count'], $count);
        }

        ksort($gaps);

        return array_values($gaps);
    }
}
