<?php

namespace AppGraph\Query;

use AppGraph\Support\AgentPayloadLimiter;
use AppGraph\Support\BoundedText;
use AppGraph\Support\ProjectPathNormalizer;
use InvalidArgumentException;

final class TaskContextPlanner
{
    private const MAX_TASK_BYTES = 16000;

    private const MAX_TASK_CHARACTERS = 4000;

    private const MAX_TARGETS = 10;

    private const MAX_CHANGED_FILES = 50;

    private const MAX_TERMS = 64;

    private const MAX_IDENTIFIERS = 32;

    private const MAX_LEXICAL_CANDIDATES = 512;

    private const LEXICAL_PRUNE_AT = 1024;

    private const MAX_SCANNED_NODES = 100000;

    private const MAX_SEEDS = 24;

    private const MAX_NODES_PER_CHANGED_FILE = 16;

    private const MAX_CHANGED_FILE_CANDIDATES = 1024;

    private const MAX_NODE_ID_BYTES = 16384;

    private const MAX_EXACT_REFERENCE_CHARACTERS = 4096;

    private const MAX_EXACT_REFERENCE_BYTES = 16384;

    private const MAX_DEPTH = 6;

    private const MAX_TRANSITIONS = 5000;

    private const MAX_RELATED_FACTS = 1024;

    private const MAX_COLUMN_EDGE_CANDIDATES = 5000;

    private const MAX_TRAVERSAL_RESULTS = 256;

    private const MAX_PATHS = 32;

    private const MAX_MAPPED_TESTS = 32;

    private const MAX_VERIFICATION_TARGETS = 16;

    private const MAX_VERIFICATION_COMMANDS = 16;

    private const MAX_UNCERTAINTIES = 50;

    private const MAX_EVIDENCE_RECORDS = 64;

    private const MAX_EVIDENCE_PER_EDGE = 3;

    /** @var array<int, string> */
    private const DOWNSTREAM_EDGE_TYPES = [
        'routes_to',
        'passes_through',
        'calls',
        'validates_with',
        'framework_invokes',
        'dispatches',
        'handled_by',
    ];

    /** @var array<int, string> */
    private const UPSTREAM_EDGE_TYPES = [
        'calls',
        'routes_to',
        'passes_through',
        'validates_with',
        'framework_invokes',
        'dispatches',
        'handled_by',
        'uses_model',
        'authorizes_via',
        'observes',
        'belongs_to',
        'has_one',
        'has_one_through',
        'has_many',
        'has_many_through',
        'belongs_to_many',
        'morph_to',
        'morph_one',
        'morph_many',
        'morph_to_many',
        'morphed_by_many',
        'tests_route',
        'consumes_route',
        'reads_cache',
        'writes_cache',
        'reads_filesystem',
        'writes_filesystem',
        'calls_external',
    ];

    /** @var array<int, string> */
    private const RELATED_EDGE_TYPES = [
        'uses_model',
        'reads',
        'writes',
        'authorizes_via',
        'reads_cache',
        'writes_cache',
        'reads_filesystem',
        'writes_filesystem',
        'calls_external',
        'uses_table',
        'belongs_to',
        'has_one',
        'has_one_through',
        'has_many',
        'has_many_through',
        'belongs_to_many',
        'morph_to',
        'morph_one',
        'morph_many',
        'morph_to_many',
        'morphed_by_many',
        'observes',
        'resolves_to',
    ];

    /** @var array<string, true> */
    private const STOP_WORDS = [
        'a' => true,
        'an' => true,
        'and' => true,
        'as' => true,
        'at' => true,
        'be' => true,
        'by' => true,
        'code' => true,
        'feature' => true,
        'for' => true,
        'from' => true,
        'in' => true,
        'into' => true,
        'is' => true,
        'it' => true,
        'make' => true,
        'of' => true,
        'on' => true,
        'or' => true,
        'refactor' => true,
        'safely' => true,
        'support' => true,
        'task' => true,
        'that' => true,
        'the' => true,
        'this' => true,
        'to' => true,
        'with' => true,
    ];

    private LaravelExecutionSemantics $execution;

    private FieldAccessClassifier $fieldAccess;

    private SourceSpanPlanner $sourceSpans;

    private string $basePath;

    private ProjectPathNormalizer $projectPaths;

    public function __construct(
        private GraphIndex $index,
        string $basePath,
        ?LaravelExecutionSemantics $execution = null,
        ?FieldAccessClassifier $fieldAccess = null,
        ?SourceSpanPlanner $sourceSpans = null,
    ) {
        $resolvedBasePath = realpath($basePath);

        if ($resolvedBasePath === false || ! is_dir($resolvedBasePath)) {
            throw new InvalidArgumentException("Task context base path [{$basePath}] is not a readable directory.");
        }

        $normalizedBasePath = rtrim(str_replace('\\', '/', $resolvedBasePath), '/');
        $this->basePath = $normalizedBasePath === '' ? '/' : $normalizedBasePath;
        $this->projectPaths = new ProjectPathNormalizer($this->basePath);
        $this->execution = $execution ?? new LaravelExecutionSemantics($index);
        $this->fieldAccess = $fieldAccess ?? new FieldAccessClassifier;
        $this->sourceSpans = $sourceSpans ?? new SourceSpanPlanner($this->basePath);
    }

    /**
     * Compile a bounded, task-shaped source reading and verification plan.
     *
     * @param array<int, string> $targets
     * @param array<int, string> $changedFiles
     * @return array<string, mixed>
     */
    public function plan(
        string $task,
        array $targets = [],
        array $changedFiles = [],
        int $tokenBudget = 4000,
        int $depth = 4,
        float $minConfidence = 0.0,
    ): array {
        [$task, $targets, $changedFiles] = $this->validateInputs(
            $task,
            $targets,
            $changedFiles,
            $tokenBudget,
            $depth,
            $minConfidence,
        );
        $omitted = $this->emptyOmittedCounts();
        $uncertainties = [];
        [$normalizedChangedFiles, $fileUncertainties] = $this->normalizeChangedFiles($changedFiles);
        $uncertainties = [...$uncertainties, ...$fileUncertainties];
        [$seeds, $seedUncertainties, $seedOmitted] = $this->discoverSeeds(
            $task,
            $targets,
            $normalizedChangedFiles,
        );
        $uncertainties = [...$uncertainties, ...$seedUncertainties];
        $omitted = $this->mergeCounts($omitted, $seedOmitted);

        if ($seeds === []) {
            $uncertainties[] = [
                'reason' => 'no_graph_seeds',
                'message' => 'No graph node could be resolved from the task, targets, or changed files.',
            ];
        }

        $expanded = $this->expand($seeds, $depth, $minConfidence);
        $uncertainties = [...$uncertainties, ...$expanded['uncertainties']];
        $omitted = $this->mergeCounts($omitted, $expanded['omitted']);
        $verification = $this->verification(
            $expanded['contexts'],
            $expanded['relatedFactsRemaining'],
            $minConfidence,
        );
        $omitted = $this->mergeCounts($omitted, $verification['omitted']);
        $uncertainties = [...$uncertainties, ...$verification['uncertainties']];

        $spanCandidates = $this->spanCandidates(
            $expanded['contexts'],
            $expanded['paths'],
            $normalizedChangedFiles,
            $verification['mappedTests'],
            $omitted,
            $uncertainties,
        );
        $sourcePlan = $this->sourceSpans->plan($spanCandidates, $tokenBudget);
        $omitted = $this->mergeCounts($omitted, $sourcePlan['omitted']);
        $uncertainties = [...$uncertainties, ...$sourcePlan['uncertainties']];
        [$uncertainties, $uncertaintyCounts, $uncertaintyOmitted] = $this->boundedUncertainties($uncertainties);
        $omitted['uncertainty_limit'] += $uncertaintyOmitted;

        $budget = $sourcePlan['budget'];
        $budget['consumed'] += [
            'seeds' => count($seeds),
            'transitions' => $expanded['transitions'],
            'relatedFacts' => self::MAX_RELATED_FACTS - $verification['relatedFactsRemaining'],
            'paths' => count($expanded['paths']),
        ];
        ksort($budget['consumed']);
        ksort($omitted);
        $capacityReasons = [
            'lexical_candidate_limit',
            'input_limit',
            'identifier_candidate_limit',
            'target_candidate_limit',
            'seed_limit',
            'changed_file_node_limit',
            'changed_file_candidate_limit',
            'node_text_limit',
            'node_scan_limit',
            'column_candidate_limit',
            'field_metadata_limit',
            'dispatch_metadata_limit',
            'transition_limit',
            'traversal_result_limit',
            'depth_limit',
            'related_fact_limit',
            'path_limit',
            'evidence_limit',
            'mapped_test_limit',
            'verification_target_limit',
            'verification_command_limit',
            'file_limit',
            'span_limit',
            'span_line_limit',
            'span_token_limit',
            'file_token_limit',
            'file_byte_limit',
            'token_budget',
            'uncertainty_limit',
        ];
        $truncated = $expanded['truncated'] || $sourcePlan['truncated'];

        foreach ($capacityReasons as $reason) {
            $truncated = $truncated || ($omitted[$reason] ?? 0) > 0;
        }

        return [
            'task' => $task,
            'seeds' => $seeds,
            'readSet' => $sourcePlan['readSet'],
            'paths' => $expanded['paths'],
            'verification' => [
                'mappedTests' => $verification['mappedTests'],
                'targets' => $verification['targets'],
                'commands' => $verification['commands'],
                'refreshAfterChanges' => true,
                'gaps' => $verification['gaps'],
            ],
            'uncertainties' => $uncertainties,
            'uncertaintyCounts' => $uncertaintyCounts,
            'budget' => $budget,
            'omitted' => $omitted,
            'truncated' => $truncated,
        ];
    }

    /**
     * @param array<int, string> $targets
     * @param array<int, string> $changedFiles
     * @return array{string, array<int, string>, array<int, string>}
     */
    private function validateInputs(
        string $task,
        array $targets,
        array $changedFiles,
        int $tokenBudget,
        int $depth,
        float $minConfidence,
    ): array {
        $task = trim($task);
        $characters = preg_match_all('/./us', $task, $matches);

        if ($task === '') {
            throw new InvalidArgumentException('Task context requires a nonblank task description.');
        }

        if ($characters === false || $characters > self::MAX_TASK_CHARACTERS || strlen($task) > self::MAX_TASK_BYTES) {
            throw new InvalidArgumentException('Task context descriptions must not exceed 4000 characters.');
        }

        if ($tokenBudget < SourceSpanPlanner::MIN_TOKEN_BUDGET || $tokenBudget > SourceSpanPlanner::MAX_TOKEN_BUDGET) {
            throw new InvalidArgumentException('Task context token budget must be between 512 and 16000.');
        }

        if ($depth < 1 || $depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('Task context depth must be between 1 and 6.');
        }

        if (! is_finite($minConfidence) || $minConfidence < 0.0 || $minConfidence > 1.0) {
            throw new InvalidArgumentException('Task context minimum confidence must be between 0 and 1.');
        }

        $targets = $this->validatedStringList($targets, 'targets', self::MAX_TARGETS, 4096);
        $changedFiles = $this->validatedStringList($changedFiles, 'changed files', self::MAX_CHANGED_FILES, 1024);

        return [$task, $targets, $changedFiles];
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, string>
     */
    private function validatedStringList(array $values, string $label, int $limit, int $maxCharacters): array
    {
        if (count($values) > $limit) {
            throw new InvalidArgumentException("Task context {$label} must contain at most {$limit} values.");
        }

        $normalized = [];

        foreach ($values as $value) {
            $characters = is_string($value) ? preg_match_all('/./us', trim($value), $matches) : false;

            if (! is_string($value)
                || trim($value) === ''
                || str_contains($value, "\0")
                || $characters === false
                || $characters > $maxCharacters
                || strlen($value) > $maxCharacters * 4) {
                throw new InvalidArgumentException("Task context {$label} must contain nonblank strings no longer than {$maxCharacters} characters.");
            }

            $value = trim($value);

            if (isset($normalized[$value])) {
                throw new InvalidArgumentException("Task context {$label} must not contain duplicates.");
            }

            $normalized[$value] = $value;
        }

        ksort($normalized);

        return array_values($normalized);
    }

    /**
     * @param array<int, string> $changedFiles
     * @return array{array<int, string>, array<int, array<string, mixed>>}
     */
    private function normalizeChangedFiles(array $changedFiles): array
    {
        $normalized = [];
        $uncertainties = [];

        foreach ($changedFiles as $file) {
            $relative = $this->normalizeProjectFile($file);

            if ($relative === null) {
                $uncertainties[] = [
                    'reason' => 'changed_file_outside_project',
                    'file' => $file,
                ];
                continue;
            }

            $normalized[$relative] = $relative;
        }

        ksort($normalized);

        return [array_values($normalized), $uncertainties];
    }

    /**
     * @param array<int, string> $targets
     * @param array<int, string> $changedFiles
     * @return array{array<int, array<string, mixed>>, array<int, array<string, mixed>>, array<string, int>}
     */
    private function discoverSeeds(string $task, array $targets, array $changedFiles): array
    {
        $seedsById = [];
        $uncertainties = [];
        $omitted = $this->emptyOmittedCounts();

        foreach ($targets as $target) {
            $resolved = $this->index->resolveId($target);

            if ($resolved['id'] === null) {
                if ($resolved['candidatesTruncated'] ?? false) {
                    $omitted['target_candidate_limit']++;
                }

                $uncertainties[] = array_filter([
                    'reason' => $resolved['candidates'] === [] ? 'unresolved_target' : 'ambiguous_target',
                    'target' => $target,
                    'candidates' => $resolved['candidates'] ?: null,
                    'candidatesTruncated' => ($resolved['candidatesTruncated'] ?? false) ?: null,
                ], static fn (mixed $value): bool => $value !== null);
                continue;
            }

            $node = $this->index->node($resolved['id']);

            if ($node !== null && $this->nodeHasBoundedIdentity($node)) {
                $this->addSeed($seedsById, $node, 1.0, 'explicit_target', [], $target);
            } elseif ($node !== null) {
                $omitted['node_text_limit']++;
                $uncertainties[] = [
                    'reason' => 'target_node_identity_limit',
                    'target' => $target,
                    'message' => 'The resolved graph node identity exceeded the bounded agent-response contract.',
                ];
            }
        }

        $termOmitted = 0;
        $identifierOmitted = 0;
        $terms = $this->taskTerms($task, $termOmitted);
        $identifiers = $this->taskIdentifiers($task, $identifierOmitted);

        if ($termOmitted > 0) {
            $omitted['input_limit'] += $termOmitted;
            $uncertainties[] = [
                'reason' => 'task_term_limit',
                'count' => $termOmitted,
                'message' => 'Additional unique task terms were omitted from lexical ranking.',
            ];
        }

        if ($identifierOmitted > 0) {
            $omitted['input_limit'] += $identifierOmitted;
            $uncertainties[] = [
                'reason' => 'task_identifier_limit',
                'count' => $identifierOmitted,
                'message' => 'Additional explicit-looking task identifiers were omitted from exact matching.',
            ];
        }
        $exactNodes = [];
        $lexical = [];
        $lexicalMatchCount = 0;
        $documentFrequency = array_fill_keys($terms, 0);
        $nodeCount = 0;

        foreach ($changedFiles as $file) {
            $changedTruncated = false;
            $changedTotal = 0;
            $changedNodes = $this->index->sampledNodesInFile(
                $file,
                self::MAX_CHANGED_FILE_CANDIDATES,
                $changedTruncated,
                $changedTotal,
            );

            if ($changedTotal === 0) {
                $absoluteTruncated = false;
                $absoluteTotal = 0;
                $changedNodes = $this->index->sampledNodesInFile(
                    $this->basePath.'/'.$file,
                    self::MAX_CHANGED_FILE_CANDIDATES,
                    $absoluteTruncated,
                    $absoluteTotal,
                );
                $changedTruncated = $absoluteTruncated;
                $changedTotal = $absoluteTotal;
            }

            if ($changedTotal === 0) {
                $uncertainties[] = [
                    'reason' => 'unmapped_changed_file',
                    'file' => $file,
                    'message' => 'The changed file has no node in the current static graph; it remains in the recommended read set.',
                ];
                continue;
            }

            if ($changedTruncated) {
                $omitted['changed_file_candidate_limit'] += $changedTotal - count($changedNodes);
            }

            $candidateCount = count($changedNodes);
            $changedNodes = $this->rankChangedFileNodes($changedNodes, $terms, $identifiers, $omitted);
            $omitted['changed_file_node_limit'] += max(0, $candidateCount - count($changedNodes));

            foreach ($changedNodes as $node) {
                $this->addSeed($seedsById, $node, 0.98, 'changed_file');
            }
        }

        foreach ($this->index->nodes() as $node) {
            if ($nodeCount >= self::MAX_SCANNED_NODES) {
                break;
            }

            $nodeCount++;
            $id = (string) ($node['id'] ?? '');

            if (max(
                strlen($id),
                strlen((string) ($node['label'] ?? '')),
                strlen((string) ($node['metadata']['name'] ?? '')),
                strlen((string) ($node['file'] ?? '')),
            ) > 2048) {
                $omitted['node_text_limit']++;
            }

            if (! $this->nodeHasBoundedIdentity($node)) {
                continue;
            }

            if ($this->nodeMatchesIdentifier($node, $identifiers)) {
                $exactNodes[$id] = $node;

                if (count($exactNodes) >= self::LEXICAL_PRUNE_AT) {
                    $omitted['identifier_candidate_limit'] += $this->pruneExactNodes($exactNodes);
                }
            }

            $matches = $this->lexicalMatches($node, $terms);

            if ($matches === []) {
                continue;
            }

            foreach (array_keys($matches) as $term) {
                $documentFrequency[$term]++;
            }
            $lexicalMatchCount++;
        }

        $omitted['identifier_candidate_limit'] += $this->pruneExactNodes($exactNodes);

        if ($this->index->nodeCount() > $nodeCount) {
            $omitted['node_scan_limit'] += $this->index->nodeCount() - $nodeCount;
        }

        foreach ($exactNodes as $node) {
            $this->addSeed($seedsById, $node, 0.92, 'task_identifier');
        }

        $totalIdf = 0.0;
        $idf = [];

        foreach ($terms as $term) {
            $idf[$term] = log(($nodeCount + 1) / (($documentFrequency[$term] ?? 0) + 1)) + 1;
            $totalIdf += $idf[$term];
        }

        $rescanned = 0;

        foreach ($this->index->nodes() as $node) {
            if ($rescanned >= $nodeCount) {
                break;
            }

            $rescanned++;

            if (! $this->nodeHasBoundedIdentity($node)) {
                continue;
            }

            $matches = $this->lexicalMatches($node, $terms);

            if ($matches === []) {
                continue;
            }

            $weighted = 0.0;

            foreach ($matches as $term => $weight) {
                $weighted += $idf[$term] * $weight;
            }

            $coverage = $totalIdf > 0 ? $weighted / $totalIdf : 0.0;
            $relevance = min(0.90, 0.55 + (0.35 * $coverage));
            $lexical[(string) $node['id']] = [
                'node' => $node,
                'matches' => $matches,
                'provisional' => $relevance,
            ];

            if (count($lexical) >= self::LEXICAL_PRUNE_AT) {
                $this->pruneLexical($lexical);
            }
        }

        $this->pruneLexical($lexical);
        $omitted['lexical_candidate_limit'] += max(0, $lexicalMatchCount - count($lexical));

        foreach ($lexical as $candidate) {
            $relevance = (float) $candidate['provisional'];
            $this->addSeed(
                $seedsById,
                $candidate['node'],
                $relevance,
                'task_lexical',
                array_keys($candidate['matches']),
            );
        }

        $seeds = array_values($seedsById);
        usort($seeds, fn (array $left, array $right): int => $this->compareSeeds($left, $right));

        if (count($seeds) > self::MAX_SEEDS) {
            $omitted['seed_limit'] += count($seeds) - self::MAX_SEEDS;
            $seeds = array_slice($seeds, 0, self::MAX_SEEDS);
        }

        foreach ($seeds as &$seed) {
            $seed['sources'] = $this->sortSeedSources($seed['sources']);
            sort($seed['matchedTerms']);
            $seed['relevance'] = round($seed['relevance'], 4);
            unset($seed['_sourceRank'], $seed['_target']);
            $seed = array_filter($seed, static fn (mixed $value): bool => $value !== null && $value !== []);
        }
        unset($seed);

        return [$seeds, $uncertainties, $omitted];
    }

    /**
     * @param array<int, array<string, mixed>> $seeds
     * @return array<string, mixed>
     */
    private function expand(array $seeds, int $depth, float $minConfidence): array
    {
        $contexts = [];
        $paths = [];
        $uncertainties = [];
        $omitted = $this->emptyOmittedCounts();
        $seedById = [];

        foreach ($seeds as $seed) {
            $seedById[$seed['id']] = $seed;
            $node = $this->index->node($seed['id']);

            if ($node === null) {
                continue;
            }

            $this->addContext(
                $contexts,
                $node,
                (float) $seed['relevance'],
                (float) $seed['relevance'],
                1.0,
                0,
                'seed',
                [$seed['id']],
                $seed['sources'],
                0,
                [],
            );
        }

        $seedStates = array_map(
            static fn (array $seed): array => [
                'id' => $seed['id'],
                // `confidence` ranks task relevance × graph support globally;
                // graphConfidence separately preserves threshold semantics.
                'confidence' => $seed['relevance'],
                'graphConfidence' => 1.0,
                'path' => [$seed['id']],
            ],
            $seeds,
        );
        $transitions = 0;
        $forwardTruncated = false;
        $forwardHits = $seedStates === [] ? [] : $this->index->traverseFromSeeds(
            $seedStates,
            self::DOWNSTREAM_EDGE_TYPES,
            direction: 'out',
            maxDepth: $depth,
            minConfidence: 0.0,
            limit: self::MAX_TRAVERSAL_RESULTS,
            transition: function (array $state, array $edge, string $neighborId, string $direction) use (&$transitions, &$omitted, &$uncertainties, $minConfidence): array|false {
                $transitions++;
                $transition = $this->downstreamTransition($state, $edge, $neighborId, $direction, $omitted);
                $metadataTruncated = $this->recordDispatchMetadataDiagnostics(
                    $state,
                    $edge,
                    $neighborId,
                    $direction,
                    $transition,
                    $omitted,
                    $uncertainties,
                );

                if ($transition === false
                    && in_array($edge['type'] ?? null, ['dispatches', 'handled_by'], true)
                    && ! $metadataTruncated) {
                    $omitted['non_causal_handler']++;
                }
                $graphConfidence = round(
                    (float) ($state['graphConfidence'] ?? 1.0) * (float) ($edge['confidence'] ?? 1.0),
                    4,
                );

                if ($transition !== false
                    && $graphConfidence < $minConfidence) {
                    $omitted['min_confidence']++;

                    return false;
                }

                return $transition === false ? false : $transition + ['graphConfidence' => $graphConfidence];
            },
            truncated: $forwardTruncated,
            maxTransitions: self::MAX_TRANSITIONS,
            includeEdges: true,
            maxEvidencePerEdge: self::MAX_EVIDENCE_PER_EDGE,
            maxEvidenceKindsPerEdge: 16,
        );
        $this->recordTraversalTruncation(
            $forwardTruncated,
            $transitions,
            self::MAX_TRANSITIONS,
            count($forwardHits),
            $omitted,
        );

        foreach ($forwardHits as $hit) {
            $this->recordTraversalHit($contexts, $paths, $hit, 'downstream', $seedById);
        }

        $relatedFactsRemaining = self::MAX_RELATED_FACTS;
        $tableSeeds = $this->explicitTableAccessSeeds(
            $seeds,
            $contexts,
            $paths,
            $relatedFactsRemaining,
            $omitted,
            $uncertainties,
            $minConfidence,
        );
        $upstreamSeeds = [...$seedStates, ...$tableSeeds];
        $remainingTransitions = max(0, self::MAX_TRANSITIONS - $transitions);
        $upstreamTransitions = 0;
        $upstreamTruncated = false;
        $upstreamHits = $upstreamSeeds === [] ? [] : $this->index->traverseFromSeeds(
            $upstreamSeeds,
            self::UPSTREAM_EDGE_TYPES,
            direction: 'in',
            maxDepth: $depth,
            minConfidence: 0.0,
            limit: self::MAX_TRAVERSAL_RESULTS,
            transition: function (array $state, array $edge, string $neighborId, string $direction) use (&$upstreamTransitions, &$omitted, &$uncertainties, $minConfidence): array|false {
                $upstreamTransitions++;
                $transition = $this->upstreamTransition($state, $edge, $neighborId, $direction, $omitted);
                $metadataTruncated = $this->recordDispatchMetadataDiagnostics(
                    $state,
                    $edge,
                    $neighborId,
                    $direction,
                    $transition,
                    $omitted,
                    $uncertainties,
                );

                if ($transition === false
                    && in_array($edge['type'] ?? null, ['dispatches', 'handled_by'], true)
                    && ! $metadataTruncated) {
                    $omitted['non_causal_handler']++;
                }
                $graphConfidence = round(
                    (float) ($state['graphConfidence'] ?? 1.0) * (float) ($edge['confidence'] ?? 1.0),
                    4,
                );

                if ($transition !== false
                    && $graphConfidence < $minConfidence) {
                    $omitted['min_confidence']++;

                    return false;
                }

                return $transition === false ? false : $transition + ['graphConfidence' => $graphConfidence];
            },
            truncated: $upstreamTruncated,
            maxTransitions: $remainingTransitions,
            includeEdges: true,
            maxEvidencePerEdge: self::MAX_EVIDENCE_PER_EDGE,
            maxEvidenceKindsPerEdge: 16,
        );
        $transitions += $upstreamTransitions;
        $this->recordTraversalTruncation(
            $upstreamTruncated,
            $upstreamTransitions,
            $remainingTransitions,
            count($upstreamHits),
            $omitted,
        );

        foreach ($upstreamHits as $hit) {
            $this->recordTraversalHit($contexts, $paths, $hit, 'upstream', $seedById);
        }

        $relatedSources = [];

        foreach ($contexts as $context) {
            if (! in_array($context['type'], ['method', 'model', 'form_request', 'event', 'job'], true)) {
                continue;
            }

            $relatedSources[] = [
                'id' => $context['id'],
                'confidence' => $context['_combinedConfidence'],
                'depth' => $context['depth'],
                'path' => $context['_path'],
            ];
        }

        $relatedTruncated = false;
        $relatedFacts = $relatedSources === [] ? [] : $this->index->rankedEdgesFromSources(
            $relatedSources,
            self::RELATED_EDGE_TYPES,
            $relatedFactsRemaining,
            $relatedTruncated,
        );
        $relatedFactsRemaining -= count($relatedFacts);

        if ($relatedTruncated) {
            $omitted['related_fact_limit']++;
        }

        foreach ($relatedFacts as $fact) {
            $source = $contexts[$fact['source']] ?? null;
            $edge = $fact['edge'];
            $target = $this->index->node((string) ($edge['to'] ?? ''));

            if ($target !== null && ! $this->nodeHasBoundedIdentity($target)) {
                $omitted['node_text_limit']++;
                continue;
            }

            if ($source === null || $target === null || in_array($target['id'], $source['_path'], true)) {
                continue;
            }

            $path = [...$source['_path'], $target['id']];
            $combinedConfidence = round(
                $source['_combinedConfidence'] * (float) ($edge['confidence'] ?? 1.0),
                4,
            );
            $seedId = $path[0];
            $seedRelevance = (float) ($seedById[$seedId]['relevance'] ?? $source['_seedRelevance']);
            $pathConfidence = $seedRelevance > 0
                ? min(1.0, $combinedConfidence / $seedRelevance)
                : 0.0;

            if ($pathConfidence < $minConfidence) {
                $omitted['min_confidence']++;
                continue;
            }

            $edgeType = (string) ($edge['type'] ?? 'related');
            $edges = [...$source['_edges'], $this->compactEdge($edge)];
            $this->addContext(
                $contexts,
                $target,
                $combinedConfidence,
                $seedRelevance,
                $pathConfidence,
                count($path) - 1,
                'related',
                $path,
                ['related_'.$edgeType],
                $source['_evidenceCount'] + $this->edgeEvidenceCount($edge),
                $edges,
            );
            $paths[] = $this->pathRow(
                $target,
                $path,
                'related',
                $combinedConfidence,
                $seedRelevance,
                $pathConfidence,
                $source['_evidenceCount'] + $this->edgeEvidenceCount($edge),
                $edges,
            );
        }

        $uncertainties = [
            ...$uncertainties,
            ...$this->callResolutionUncertainties($contexts),
        ];

        if ($contexts === [] && $seeds !== []) {
            $uncertainties[] = [
                'reason' => 'no_expandable_context',
                'message' => 'The resolved seeds have no bounded static execution or dependency context.',
            ];
        }

        $paths = $this->formatPaths($paths, $omitted);

        return [
            'contexts' => $contexts,
            'paths' => $paths,
            'transitions' => $transitions,
            'relatedFactsRemaining' => $relatedFactsRemaining,
            'uncertainties' => $uncertainties,
            'omitted' => $omitted,
            'truncated' => $forwardTruncated || $upstreamTruncated || $relatedTruncated,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $edge
     * @param array<string, int> $omitted
     * @return array<string, mixed>|false
     */
    private function downstreamTransition(
        array $state,
        array $edge,
        string $neighborId,
        string $direction,
        array &$omitted,
    ): array|false {
        if ($direction !== 'out') {
            return false;
        }

        $neighbor = $this->index->node($neighborId);

        if ($neighbor !== null && ! $this->nodeHasBoundedIdentity($neighbor)) {
            $omitted['node_text_limit']++;

            return false;
        }

        $nodeType = $this->index->node((string) $state['id'])['type'] ?? null;
        $edgeType = $edge['type'] ?? null;
        $allowed = match ($nodeType) {
            'route' => in_array($edgeType, ['routes_to', 'passes_through'], true),
            'method' => in_array($edgeType, ['calls', 'validates_with', 'dispatches'], true),
            'form_request' => $edgeType === 'framework_invokes',
            'event', 'job' => $edgeType === 'handled_by',
            default => false,
        };

        if (! $allowed) {
            return false;
        }

        if (! in_array($edgeType, $this->execution->executionEdgeTypes(), true)) {
            return [];
        }

        $transition = $this->execution->transition($state, $edge, $neighborId, $direction);

        return $transition;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $edge
     * @param array<string, int> $omitted
     * @return array<string, mixed>|false
     */
    private function upstreamTransition(
        array $state,
        array $edge,
        string $neighborId,
        string $direction,
        array &$omitted,
    ): array|false {
        if ($direction !== 'in') {
            return false;
        }

        $neighbor = $this->index->node($neighborId);

        if ($neighbor !== null && ! $this->nodeHasBoundedIdentity($neighbor)) {
            $omitted['node_text_limit']++;

            return false;
        }

        $nodeType = $this->index->node((string) $state['id'])['type'] ?? null;
        $edgeType = $edge['type'] ?? null;
        $relationships = [
            'belongs_to',
            'has_one',
            'has_one_through',
            'has_many',
            'has_many_through',
            'belongs_to_many',
            'morph_to',
            'morph_one',
            'morph_many',
            'morph_to_many',
            'morphed_by_many',
        ];
        $sideEffects = [
            'reads_cache',
            'writes_cache',
            'reads_filesystem',
            'writes_filesystem',
            'calls_external',
        ];
        $allowed = match ($nodeType) {
            'method' => in_array($edgeType, [
                'calls',
                'routes_to',
                'passes_through',
                'framework_invokes',
                'handled_by',
                'authorizes_via',
            ], true),
            'form_request' => $edgeType === 'validates_with',
            'model' => $edgeType === 'uses_model'
                || $edgeType === 'observes'
                || in_array($edgeType, $relationships, true),
            'route' => in_array($edgeType, ['tests_route', 'consumes_route'], true),
            'event', 'job' => $edgeType === 'dispatches',
            'table', 'column' => false,
            default => in_array($edgeType, $sideEffects, true),
        };

        if (! $allowed) {
            if (in_array($nodeType, ['table', 'column'], true)) {
                $omitted['table_hub_guard']++;
            }

            return false;
        }

        if (! in_array($edgeType, ['dispatches', 'handled_by'], true)) {
            return [];
        }

        $transition = $this->execution->transition($state, $edge, $neighborId, $direction);

        return $transition;
    }

    /**
     * Surface bounded Laravel dispatch metadata whenever it can affect causal
     * traversal. Returns true so callers avoid mislabelling an unknown bridge
     * as a proven non-causal handler.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $edge
     * @param array<string, mixed>|false $transition
     * @param array<string, int> $omitted
     * @param array<int, array<string, mixed>> $uncertainties
     */
    private function recordDispatchMetadataDiagnostics(
        array $state,
        array $edge,
        string $neighborId,
        string $direction,
        array|false $transition,
        array &$omitted,
        array &$uncertainties,
    ): bool {
        $truncated = false;

        if (($edge['type'] ?? null) === 'handled_by'
            && $transition === false
            && ($state['metadataTruncated'] ?? false)) {
            // The dispatch bridge already reported its bounded metadata. A
            // handler excluded only by an incomplete role set remains unknown.
            return true;
        }

        if (($edge['type'] ?? null) === 'dispatches') {
            $targetId = $direction === 'out' ? $neighborId : (string) ($edge['to'] ?? '');
            $analysis = $this->execution->dispatchAnalysis($edge, $this->index->node($targetId));
            $truncated = $analysis['truncated'];
        } elseif (is_array($transition)) {
            $truncated = ($transition['metadataTruncated'] ?? false)
                && ! ($state['metadataTruncated'] ?? false);
        }

        if (! $truncated) {
            return false;
        }

        $omitted['dispatch_metadata_limit']++;
        $uncertainties[] = [
            'reason' => 'dispatch_metadata_limit',
            'from' => $edge['from'] ?? null,
            'to' => $edge['to'] ?? null,
            'message' => 'Laravel dispatch role metadata was bounded; omitted occurrences may change which handlers are causal.',
        ];

        return true;
    }

    /**
     * Expand a table/column only when it was an explicit context target. Tables
     * discovered from ordinary methods are deliberately never traversal seeds.
     *
     * @param array<int, array<string, mixed>> $seeds
     * @param array<string, array<string, mixed>> $contexts
     * @param array<int, array<string, mixed>> $paths
     * @param array<string, int> $omitted
     * @return array<int, array<string, mixed>>
     */
    private function explicitTableAccessSeeds(
        array $seeds,
        array &$contexts,
        array &$paths,
        int &$remainingRelatedFacts,
        array &$omitted,
        array &$uncertainties,
        float $minConfidence = 0.0,
    ): array {
        $accessSeeds = [];

        foreach ($seeds as $seed) {
            if (! in_array('explicit_target', $seed['sources'], true)) {
                continue;
            }

            $node = $this->index->node($seed['id']);
            $type = $node['type'] ?? null;

            if (! in_array($type, ['table', 'column'], true)) {
                continue;
            }

            $tableId = $node['id'];
            $path = [$node['id']];
            $edges = [];
            $seedRelevance = (float) $seed['relevance'];
            $pathConfidence = 1.0;
            $combinedConfidence = $seedRelevance;
            $field = null;

            if ($type === 'column') {
                $ownerTruncated = false;
                $ownerEdges = $this->index->rankedEdgesTo(
                    $node['id'],
                    ['has_column'],
                    min(1, $remainingRelatedFacts),
                    $ownerTruncated,
                );
                $remainingRelatedFacts -= count($ownerEdges);

                if ($ownerTruncated) {
                    $omitted['related_fact_limit']++;
                }

                $owner = $ownerEdges[0] ?? null;

                if ($owner === null) {
                    continue;
                }

                $tableId = (string) $owner['from'];
                $table = $this->index->node($tableId);

                if ($table === null || ! $this->nodeHasBoundedIdentity($table)) {
                    $omitted['node_text_limit']++;
                    continue;
                }

                $path[] = $tableId;
                $edges[] = $this->compactEdge($owner);
                $pathConfidence = round($pathConfidence * (float) ($owner['confidence'] ?? 1.0), 4);

                if ($pathConfidence < $minConfidence) {
                    $omitted['min_confidence']++;
                    continue;
                }

                $combinedConfidence = round($seedRelevance * $pathConfidence, 4);
                $tableName = str_starts_with($tableId, 'table:') ? substr($tableId, 6) : $tableId;
                $label = (string) ($node['label'] ?? '');
                $field = str_starts_with($label, $tableName.'.')
                    ? substr($label, strlen($tableName) + 1)
                    : preg_replace('/^column:[^.]+\./', '', $node['id']);
                $this->addContext(
                    $contexts,
                    $table,
                    $combinedConfidence,
                    $seedRelevance,
                    $pathConfidence,
                    1,
                    'upstream',
                    $path,
                    ['explicit_column_owner'],
                    $this->edgeEvidenceCount($owner),
                    $edges,
                );
            }

            $accessTruncated = false;
            $candidateLimit = $field === null
                ? $remainingRelatedFacts
                : ($remainingRelatedFacts > 0 ? self::MAX_COLUMN_EDGE_CANDIDATES : 0);
            $candidateEdges = $this->index->rankedEdgesTo(
                $tableId,
                ['reads', 'writes', 'uses_table'],
                $candidateLimit,
                $accessTruncated,
            );

            if ($accessTruncated) {
                $omitted[$field === null ? 'related_fact_limit' : 'column_candidate_limit']++;
            }

            $rankedAccessCandidates = [];
            $tableName = str_starts_with($tableId, 'table:') ? substr($tableId, 6) : $tableId;

            foreach ($candidateEdges as $edge) {
                $tier = 0;

                if ($field !== null && in_array($edge['type'], ['reads', 'writes'], true)) {
                    $classification = $this->fieldAccess->classifyForTask($edge, $field, $tableName);

                    if ($classification['truncated']) {
                        $omitted['field_metadata_limit']++;
                        $from = (string) ($edge['from'] ?? '');
                        $uncertainty = [
                            'reason' => 'column_field_metadata_limit',
                            'column' => $node['id'],
                            'message' => 'Field metadata was bounded; this access remains a possible match.',
                        ];

                        if ($this->isBoundedExactReference($from)) {
                            $uncertainty['from'] = $from;
                        } else {
                            $omitted['node_text_limit']++;
                            $uncertainty['fromOmitted'] = true;
                            $uncertainty['fromBytes'] = strlen($from);
                            $uncertainty['fromCharacters'] = mb_strlen($from);
                        }

                        $uncertainties[] = $uncertainty;
                    }

                    if ($classification['match'] === 'excluded') {
                        $omitted['column_excluded']++;
                        continue;
                    }

                    $reason = 'column_'.$classification['match'].'_'.$edge['type'];
                    $tier = $classification['match'] === 'proven' ? 0 : 1;
                } else {
                    $reason = 'explicit_table_'.$edge['type'];
                    $tier = $field === null ? 0 : 2;
                }

                $accessNode = $this->index->node($edge['from']);

                if ($accessNode !== null && ! $this->nodeHasBoundedIdentity($accessNode)) {
                    $omitted['node_text_limit']++;
                    continue;
                }

                if ($accessNode === null || in_array($accessNode['id'], $path, true)) {
                    continue;
                }

                $accessPathConfidence = round(
                    $pathConfidence * (float) ($edge['confidence'] ?? 1.0),
                    4,
                );

                if ($accessPathConfidence < $minConfidence) {
                    $omitted['min_confidence']++;
                    continue;
                }

                $rankedAccessCandidates[] = [
                    'edge' => $edge,
                    'node' => $accessNode,
                    'reason' => $reason,
                    'tier' => $tier,
                    'pathConfidence' => $accessPathConfidence,
                ];
            }

            usort($rankedAccessCandidates, static fn (array $left, array $right): int => [
                $left['tier'],
                -$left['pathConfidence'],
                $left['edge']['from'] ?? '',
                $left['edge']['type'] ?? '',
                $left['edge']['to'] ?? '',
            ] <=> [
                $right['tier'],
                -$right['pathConfidence'],
                $right['edge']['from'] ?? '',
                $right['edge']['type'] ?? '',
                $right['edge']['to'] ?? '',
            ]);

            if (count($rankedAccessCandidates) > $remainingRelatedFacts) {
                $omitted['related_fact_limit'] += count($rankedAccessCandidates) - $remainingRelatedFacts;
                $rankedAccessCandidates = array_slice($rankedAccessCandidates, 0, $remainingRelatedFacts);
            }

            foreach ($rankedAccessCandidates as $candidate) {
                $edge = $candidate['edge'];
                $accessNode = $candidate['node'];
                $reason = $candidate['reason'];
                $accessPathConfidence = $candidate['pathConfidence'];

                $remainingRelatedFacts--;

                $accessPath = [...$path, $accessNode['id']];
                $accessEdgesPath = [...$edges, $this->compactEdge($edge)];
                $accessConfidence = round($seedRelevance * $accessPathConfidence, 4);
                $evidenceCount = array_sum(array_map(
                    static fn (array $compact): int => count($compact['evidence'] ?? []),
                    $accessEdgesPath,
                ));
                $this->addContext(
                    $contexts,
                    $accessNode,
                    $accessConfidence,
                    $seedRelevance,
                    $accessPathConfidence,
                    count($accessPath) - 1,
                    'upstream',
                    $accessPath,
                    [$reason],
                    $evidenceCount,
                    $accessEdgesPath,
                );
                $paths[] = $this->pathRow(
                    $accessNode,
                    $accessPath,
                    'upstream',
                    $accessConfidence,
                    $seedRelevance,
                    $accessPathConfidence,
                    $evidenceCount,
                    $accessEdgesPath,
                );
                $accessSeeds[] = [
                    'id' => $accessNode['id'],
                    'confidence' => $accessConfidence,
                    'graphConfidence' => $accessPathConfidence,
                    'path' => $accessPath,
                    'edges' => $accessEdgesPath,
                ];
            }
        }

        return $accessSeeds;
    }

    /** @param array<string, int> $omitted */
    private function recordTraversalTruncation(
        bool $truncated,
        int $transitions,
        int $transitionLimit,
        int $hits,
        array &$omitted,
    ): void {
        if (! $truncated) {
            return;
        }

        if ($transitions >= $transitionLimit) {
            $omitted['transition_limit']++;
        } elseif ($hits >= self::MAX_TRAVERSAL_RESULTS) {
            $omitted['traversal_result_limit']++;
        } else {
            $omitted['depth_limit']++;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $contexts
     * @param array<int, array<string, mixed>> $paths
     * @param array<string, mixed> $hit
     * @param array<string, array<string, mixed>> $seedById
     */
    private function recordTraversalHit(
        array &$contexts,
        array &$paths,
        array $hit,
        string $direction,
        array $seedById,
    ): void {
        $node = $this->index->node((string) ($hit['id'] ?? ''));
        $path = array_values(array_filter(
            $hit['path'] ?? [],
            static fn (mixed $id): bool => is_string($id),
        ));

        if ($node === null || $path === []) {
            return;
        }

        $seedRelevance = (float) ($seedById[$path[0]]['relevance'] ?? 1.0);
        $pathConfidence = (float) ($hit['graphConfidence'] ?? 1.0);
        $combinedConfidence = (float) ($hit['confidence'] ?? ($seedRelevance * $pathConfidence));
        $depth = count($path) - 1;
        $edges = array_values(array_filter(
            $hit['edges'] ?? [],
            static fn (mixed $edge): bool => is_array($edge),
        ));
        $this->addContext(
            $contexts,
            $node,
            $combinedConfidence,
            $seedRelevance,
            $pathConfidence,
            $depth,
            $direction,
            $path,
            [$direction.'_'.($hit['edgeType'] ?? 'seed')],
            (int) ($hit['evidenceCount'] ?? 0),
            $edges,
        );

        if ($depth > 0) {
            $paths[] = $this->pathRow(
                $node,
                $path,
                $direction,
                $combinedConfidence,
                $seedRelevance,
                $pathConfidence,
                (int) ($hit['evidenceCount'] ?? 0),
                $edges,
            );
        }
    }

    /**
     * @param array<string, array<string, mixed>> $contexts
     * @param array<string, mixed> $node
     * @param array<int, string> $reasons
     * @param array<int, string> $path
     * @param array<int, array<string, mixed>> $edges
     */
    private function addContext(
        array &$contexts,
        array $node,
        float $combinedConfidence,
        float $seedRelevance,
        float $pathConfidence,
        int $depth,
        string $direction,
        array $path,
        array $reasons,
        int $evidenceCount,
        array $edges,
    ): void {
        $id = (string) $node['id'];
        $score = $this->contextScore(
            $node,
            $combinedConfidence,
            $depth,
            $evidenceCount,
        );
        $candidate = array_filter([
            'id' => $id,
            'type' => $node['type'] ?? null,
            'label' => isset($node['label']) ? BoundedText::utf8Bytes((string) $node['label'], 512) : null,
            'file' => $this->nodeProjectFile($node),
            'line' => $node['line'] ?? null,
            'endLine' => $node['endLine'] ?? null,
            'score' => round($score, 4),
            'relevance' => round($score, 4),
            'confidence' => round($pathConfidence, 4),
            'depth' => $depth,
            'direction' => $direction,
            'reasons' => array_values(array_unique($reasons)),
            '_combinedConfidence' => round($combinedConfidence, 4),
            '_seedRelevance' => round($seedRelevance, 4),
            '_pathConfidence' => round($pathConfidence, 4),
            '_path' => $path,
            '_edges' => $edges,
            '_evidenceCount' => $evidenceCount,
        ], static fn (mixed $value): bool => $value !== null);
        sort($candidate['reasons']);
        $existing = $contexts[$id] ?? null;

        if ($existing === null || $this->compareContexts($candidate, $existing) < 0) {
            $candidate['reasons'] = array_values(array_unique([
                ...($existing['reasons'] ?? []),
                ...$candidate['reasons'],
            ]));
            sort($candidate['reasons']);
            $contexts[$id] = $candidate;
        } else {
            $contexts[$id]['reasons'] = array_values(array_unique([
                ...$existing['reasons'],
                ...$candidate['reasons'],
            ]));
            sort($contexts[$id]['reasons']);
        }
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compareContexts(array $left, array $right): int
    {
        return [
            -$left['score'],
            -$left['confidence'],
            $left['depth'],
            $this->directionRank($left['direction']),
            $left['id'],
            implode("\0", $left['_path']),
        ] <=> [
            -$right['score'],
            -$right['confidence'],
            $right['depth'],
            $this->directionRank($right['direction']),
            $right['id'],
            implode("\0", $right['_path']),
        ];
    }

    /** @param array<string, mixed> $node */
    private function contextScore(array $node, float $combinedConfidence, int $depth, int $evidenceCount): float
    {
        $roleWeight = match ($node['type'] ?? null) {
            'route', 'method' => 0.95,
            'form_request', 'test' => 0.90,
            'model', 'event', 'job' => 0.85,
            'table', 'column', 'cache', 'filesystem', 'external' => 0.70,
            default => 0.80,
        };
        $evidenceBoost = 1 + (0.02 * min(3, max(0, $evidenceCount)));

        return min(1.0, $combinedConfidence * (0.90 ** $depth) * $roleWeight * $evidenceBoost);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string> $path
     * @param array<int, array<string, mixed>> $edges
     * @return array<string, mixed>
     */
    private function pathRow(
        array $node,
        array $path,
        string $direction,
        float $combinedConfidence,
        float $seedRelevance,
        float $pathConfidence,
        int $evidenceCount,
        array $edges,
    ): array {
        return [
            'seed' => $path[0],
            'to' => $node['id'],
            'direction' => $direction,
            'depth' => count($path) - 1,
            'confidence' => round($pathConfidence, 4),
            'score' => round($this->contextScore($node, $combinedConfidence, count($path) - 1, $evidenceCount), 4),
            'nodes' => $path,
            'edges' => $edges,
            'evidenceCount' => $evidenceCount,
            '_seedRelevance' => $seedRelevance,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $paths
     * @param array<string, int> $omitted
     * @return array<int, array<string, mixed>>
     */
    private function formatPaths(array $paths, array &$omitted): array
    {
        $unique = [];

        foreach ($paths as $path) {
            $edgeTypes = array_map(
                static fn (array $edge): string => (string) ($edge['type'] ?? ''),
                $path['edges'] ?? [],
            );
            $key = $path['direction']."\0".implode("\0", $path['nodes'])."\0".implode("\0", $edgeTypes);

            if (! isset($unique[$key]) || $this->comparePathRows($path, $unique[$key]) < 0) {
                $unique[$key] = $path;
            }
        }

        $paths = array_values($unique);
        usort($paths, fn (array $left, array $right): int => $this->comparePathRows($left, $right));

        if (count($paths) > self::MAX_PATHS) {
            $omitted['path_limit'] += count($paths) - self::MAX_PATHS;
            $paths = array_slice($paths, 0, self::MAX_PATHS);
        }

        $evidenceRemaining = self::MAX_EVIDENCE_RECORDS;

        foreach ($paths as &$path) {
            foreach ($path['edges'] as &$edge) {
                $evidence = $edge['evidence'] ?? [];
                $compactionOmitted = max(0, (int) ($edge['evidenceOmitted'] ?? 0));

                if ($compactionOmitted > 0) {
                    $omitted['evidence_limit'] += $compactionOmitted;
                    $edge['evidenceTruncated'] = true;
                }
                unset($edge['evidenceOmitted']);

                if (count($evidence) > $evidenceRemaining) {
                    $omitted['evidence_limit'] += count($evidence) - $evidenceRemaining;
                    $evidence = array_slice($evidence, 0, $evidenceRemaining);
                    $edge['evidenceTruncated'] = true;
                }

                $evidenceRemaining -= count($evidence);

                if ($evidence === []) {
                    unset($edge['evidence']);
                } else {
                    $edge['evidence'] = $evidence;
                }
            }
            unset($edge);
            unset($path['_seedRelevance']);

            if ($path['edges'] === []) {
                unset($path['edges']);
            }
        }
        unset($path);

        return $paths;
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function comparePathRows(array $left, array $right): int
    {
        return [
            -$left['score'],
            -$left['confidence'],
            $left['depth'],
            $this->directionRank($left['direction']),
            $left['seed'],
            $left['to'],
            implode("\0", $left['nodes']),
        ] <=> [
            -$right['score'],
            -$right['confidence'],
            $right['depth'],
            $this->directionRank($right['direction']),
            $right['seed'],
            $right['to'],
            implode("\0", $right['nodes']),
        ];
    }

    private function directionRank(string $direction): int
    {
        return match ($direction) {
            'seed' => 0,
            'downstream' => 1,
            'upstream' => 2,
            default => 3,
        };
    }

    /** @param array<string, mixed> $edge @return array<string, mixed> */
    private function compactEdge(array $edge): array
    {
        $evidence = [];
        $examined = 0;
        $records = is_array($edge['metadata']['evidence'] ?? null)
            ? $edge['metadata']['evidence']
            : (is_array($edge['evidence'] ?? null) ? $edge['evidence'] : []);
        $evidenceTotal = count($records) + max(0, (int) ($edge['evidenceOmitted'] ?? 0));

        foreach ($records as $record) {
            $examined++;

            if (! is_array($record)) {
                if ($examined >= self::MAX_EVIDENCE_PER_EDGE * 4) {
                    break;
                }

                continue;
            }

            $compact = [];

            foreach (['file', 'line', 'rule', 'source', 'inference', 'syntax'] as $key) {
                if (! array_key_exists($key, $record)) {
                    continue;
                }

                $value = $record[$key];

                if (AgentPayloadLimiter::isExactStringKey($key)) {
                    if (! is_string($value) || ! $this->isBoundedExactReference($value)) {
                        // A provenance record is atomic. If its exact source
                        // cannot be returned intact, omit the record and let
                        // evidenceOmitted/evidenceTruncated report the gap.
                        $compact = [];
                        break;
                    }

                    $compact[$key] = $value;

                    continue;
                }

                if (is_scalar($value)) {
                    $compact[$key] = is_string($value) ? BoundedText::utf8Bytes($value, 512) : $value;
                }
            }

            if ($compact !== []) {
                $evidence[] = $compact;
            }

            if (count($evidence) >= self::MAX_EVIDENCE_PER_EDGE) {
                break;
            }

            if ($examined >= self::MAX_EVIDENCE_PER_EDGE * 4) {
                break;
            }
        }

        return array_filter([
            'from' => $edge['from'] ?? null,
            'to' => $edge['to'] ?? null,
            'type' => $edge['type'] ?? null,
            'confidence' => round((float) ($edge['confidence'] ?? 1.0), 4),
            'evidence' => $evidence,
            'evidenceOmitted' => $evidenceTotal > count($evidence)
                ? $evidenceTotal - count($evidence)
                : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /** @param array<string, mixed> $edge */
    private function edgeEvidenceCount(array $edge): int
    {
        $count = 0;
        $examined = 0;

        foreach ($edge['metadata']['evidence'] ?? [] as $record) {
            $examined++;

            if (is_array($record)) {
                $count++;
            }

            if ($count >= 3 || $examined >= 12) {
                break;
            }
        }

        return $count;
    }

    /**
     * Surface bounded unresolved-call diagnostics for methods selected into the
     * task. Known framework boundaries remain separate and are not presented as
     * missing application behavior.
     *
     * @param array<string, array<string, mixed>> $contexts
     * @return array<int, array<string, mixed>>
     */
    private function callResolutionUncertainties(array $contexts): array
    {
        $byCaller = $this->index->meta()['analysis']['callResolution']['byCaller'] ?? [];

        if (! is_array($byCaller)) {
            return [];
        }

        $uncertainties = [];

        foreach ($contexts as $context) {
            if ($context['type'] !== 'method' || ! is_array($byCaller[$context['id']] ?? null)) {
                continue;
            }

            $diagnostic = $byCaller[$context['id']];
            $rawByReason = is_array($diagnostic['byReason'] ?? null) ? $diagnostic['byReason'] : [];
            $byReason = [];
            $examinedReasons = 0;
            $validReasons = 0;

            foreach ($rawByReason as $reason => $count) {
                $examinedReasons++;

                if (is_string($reason) && is_numeric($count)) {
                    $reason = BoundedText::utf8Bytes($reason, 256);

                    if ($reason === '') {
                        continue;
                    }

                    $validReasons++;

                    if (count($byReason) < 16) {
                        $byReason[$reason] = ($byReason[$reason] ?? 0) + (int) $count;
                    }
                }

                if ($examinedReasons >= 64) {
                    break;
                }
            }
            ksort($byReason);
            $byReasonTruncated = count($rawByReason) > $examinedReasons || $validReasons > count($byReason);
            $uncertainties[] = array_filter([
                'reason' => 'unresolved_calls',
                'caller' => $context['id'],
                'count' => isset($diagnostic['count']) ? (int) $diagnostic['count'] : null,
                'byReason' => $byReason === [] ? null : $byReason,
                'byReasonTruncated' => $byReasonTruncated ?: null,
                'message' => 'One or more application call edges may be missing because static resolution was incomplete.',
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $uncertainties;
    }

    /**
     * @param array<string, array<string, mixed>> $contexts
     * @return array<string, mixed>
     */
    private function verification(
        array $contexts,
        int $relatedFactsRemaining,
        float $minConfidence,
    ): array
    {
        $omitted = $this->emptyOmittedCounts();
        $uncertainties = [];
        $mapped = [];
        $routes = [];

        foreach ($contexts as $context) {
            if ($context['type'] === 'test') {
                foreach ($context['_edges'] ?? [] as $edge) {
                    if (($edge['type'] ?? null) === 'tests_route') {
                        $this->mergeMappedTest($mapped, $context, (string) ($edge['to'] ?? ''), (float) $context['confidence']);
                    }
                }
            }

            if ($context['type'] === 'route') {
                $routes[$context['id']] = $context;
            }
        }

        uasort($routes, fn (array $left, array $right): int => $this->compareContexts($left, $right));

        foreach ($routes as $routeId => $route) {
            $limit = min(self::MAX_MAPPED_TESTS, $relatedFactsRemaining);
            $truncated = false;
            $testEdges = $this->index->rankedEdgesTo($routeId, ['tests_route'], $limit, $truncated);
            $relatedFactsRemaining -= count($testEdges);

            if ($truncated) {
                $omitted[count($mapped) >= self::MAX_MAPPED_TESTS ? 'mapped_test_limit' : 'related_fact_limit']++;
            }

            $routeMapped = false;
            $routeHasStaticMapping = $testEdges !== [];

            foreach ($testEdges as $edge) {
                $testConfidence = round(
                    (float) ($route['_pathConfidence'] ?? 1.0) * (float) ($edge['confidence'] ?? 1.0),
                    4,
                );

                if ($testConfidence < $minConfidence) {
                    $omitted['min_confidence']++;
                    continue;
                }

                $test = $this->index->node($edge['from']);

                if ($test !== null && ! $this->nodeHasBoundedIdentity($test)) {
                    $omitted['node_text_limit']++;
                    continue;
                }

                if ($test === null) {
                    continue;
                }

                $routeMapped = true;
                $this->mergeMappedTest($mapped, $test, $routeId, $testConfidence);
            }

            if (! $routeMapped && ! $routeHasStaticMapping && ! $truncated) {
                if (count($uncertainties) < self::MAX_UNCERTAINTIES) {
                    $uncertainties[] = [
                        'reason' => 'no_statically_mapped_test',
                        'route' => $routeId,
                        'message' => 'No test method was statically mapped to this route; dynamic or indirect tests may still exist.',
                    ];
                } else {
                    $omitted['uncertainty_limit']++;
                }
            }
        }

        foreach ($mapped as &$mappedTest) {
            unset($mappedTest['_routes']);
        }
        unset($mappedTest);
        $mapped = array_values($mapped);
        usort($mapped, static fn (array $left, array $right): int => [
            -((float) ($left['confidence'] ?? 0.0)),
            $left['file'] ?? '',
            $left['line'] ?? PHP_INT_MAX,
            $left['id'],
        ] <=> [
            -((float) ($right['confidence'] ?? 0.0)),
            $right['file'] ?? '',
            $right['line'] ?? PHP_INT_MAX,
            $right['id'],
        ]);

        if (count($mapped) > self::MAX_MAPPED_TESTS) {
            $omitted['mapped_test_limit'] += count($mapped) - self::MAX_MAPPED_TESTS;
            $mapped = array_slice($mapped, 0, self::MAX_MAPPED_TESTS);
        }

        $targets = array_values(array_filter(
            $contexts,
            static fn (array $context): bool => in_array(
                $context['type'],
                ['route', 'method', 'form_request', 'model', 'event', 'job'],
                true,
            ),
        ));
        usort($targets, fn (array $left, array $right): int => $this->compareContexts($left, $right));
        $omitted['verification_target_limit'] += max(0, count($targets) - self::MAX_VERIFICATION_TARGETS);
        $targets = array_map(
            static fn (array $target): array => array_filter([
                'id' => $target['id'],
                'type' => $target['type'],
                'score' => $target['score'],
                'file' => $target['file'] ?? null,
                'line' => $target['line'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            array_slice($targets, 0, self::MAX_VERIFICATION_TARGETS),
        );
        $commands = [];

        foreach ($mapped as $test) {
            if (! isset($test['file'])) {
                continue;
            }

            $command = 'php artisan test '.escapeshellarg($test['file']);
            $commands[$command] = $command;

        }

        $omitted['verification_command_limit'] += max(0, count($commands) - self::MAX_VERIFICATION_COMMANDS);
        $commands = array_slice($commands, 0, self::MAX_VERIFICATION_COMMANDS, true);

        return [
            'mappedTests' => $mapped,
            'targets' => $targets,
            'commands' => array_values($commands),
            'gaps' => $uncertainties,
            'uncertainties' => $uncertainties,
            'omitted' => $omitted,
            'relatedFactsRemaining' => $relatedFactsRemaining,
        ];
    }

    /**
     * Retain every bounded route mapping for a test while keeping the strongest
     * mapping in the legacy singular fields.
     *
     * @param array<string, array<string, mixed>> $mapped
     * @param array<string, mixed> $test
     */
    private function mergeMappedTest(
        array &$mapped,
        array $test,
        string $routeId,
        float $confidence,
    ): void {
        if ($routeId === '' || ! isset($test['id']) || ! is_string($test['id'])) {
            return;
        }

        $entry = $mapped[$test['id']] ?? array_filter([
            'id' => $test['id'],
            'file' => isset($test['file']) ? $this->nodeProjectFile($test) : null,
            'line' => $test['line'] ?? null,
            'endLine' => $test['endLine'] ?? null,
            '_routes' => [],
        ], static fn (mixed $value): bool => $value !== null);
        $entry['_routes'] ??= [];
        $entry['_routes'][$routeId] = max(
            (float) ($entry['_routes'][$routeId] ?? 0.0),
            round($confidence, 4),
        );
        $routes = [];

        foreach ($entry['_routes'] as $id => $routeConfidence) {
            $routes[] = ['id' => $id, 'confidence' => round((float) $routeConfidence, 4)];
        }

        usort($routes, static fn (array $left, array $right): int => [
            -$left['confidence'],
            $left['id'],
        ] <=> [
            -$right['confidence'],
            $right['id'],
        ]);
        $entry['route'] = $routes[0]['id'];
        $entry['confidence'] = $routes[0]['confidence'];

        if (count($routes) > 1) {
            $entry['routes'] = $routes;
        } else {
            unset($entry['routes']);
        }

        $mapped[$test['id']] = $entry;
    }

    /**
     * @param array<string, array<string, mixed>> $contexts
     * @param array<int, array<string, mixed>> $paths
     * @param array<int, string> $changedFiles
     * @param array<int, array<string, mixed>> $mappedTests
     * @param array<string, int> $omitted
     * @return array<int, array<string, mixed>>
     */
    private function spanCandidates(
        array $contexts,
        array $paths,
        array $changedFiles,
        array $mappedTests,
        array &$omitted,
        array &$uncertainties,
    ): array {
        $rankedContexts = array_values($contexts);
        usort($rankedContexts, fn (array $left, array $right): int => $this->compareContexts($left, $right));
        $candidates = [];
        $dependencyFiles = [];

        foreach ($rankedContexts as $context) {
            if (! isset($context['file']) || ! is_string($context['file'])) {
                continue;
            }

            if (str_starts_with($context['file'], 'vendor/')
                && array_intersect($context['reasons'], ['explicit_target', 'changed_file']) === []) {
                $omitted['dependency_source']++;
                $dependencyFiles[$context['file']] = $context['file'];
                continue;
            }

            $candidates[] = array_filter([
                'id' => $context['id'],
                'file' => $context['file'],
                'line' => $context['line'] ?? null,
                'endLine' => $context['endLine'] ?? null,
                'score' => $context['score'],
                'reasons' => $context['reasons'],
            ], static fn (mixed $value): bool => $value !== null);
        }

        foreach ($paths as $path) {
            foreach ($path['edges'] ?? [] as $edgeIndex => $edge) {
                foreach ($edge['evidence'] ?? [] as $evidenceIndex => $evidence) {
                    $file = $evidence['file'] ?? null;
                    $line = $evidence['line'] ?? null;

                    if (! is_string($file) || ! is_int($line) || $line < 1) {
                        continue;
                    }

                    $sourceFile = $this->normalizeProjectFile($file);

                    if ($sourceFile === null) {
                        $omitted['evidence_source']++;
                        $uncertainty = [
                            'reason' => 'edge_evidence_source_outside_project',
                        ];

                        if ($this->isBoundedExactReference($file)) {
                            $uncertainty['file'] = $file;
                        } else {
                            $uncertainty['fileOmitted'] = true;
                            $uncertainty['fileBytes'] = strlen($file);
                            $uncertainty['fileCharacters'] = mb_strlen($file);
                        }

                        $uncertainties[] = $uncertainty;
                        continue;
                    }

                    if (str_starts_with($sourceFile, 'vendor/')) {
                        $omitted['dependency_source']++;
                        $dependencyFiles[$sourceFile] = $sourceFile;
                        continue;
                    }

                    $candidates[] = [
                        'id' => 'evidence:'.hash('sha256', implode('|', [
                            $path['seed'],
                            $path['to'],
                            (string) $edgeIndex,
                            (string) $evidenceIndex,
                            $sourceFile,
                            (string) $line,
                        ])),
                        'file' => $sourceFile,
                        'line' => $line,
                        'endLine' => $line,
                        'relevance' => round((float) $path['score'] * 0.8, 4),
                        'reason' => 'edge_evidence',
                    ];
                }
            }
        }

        foreach ($dependencyFiles as $file) {
            $uncertainties[] = [
                'reason' => 'dependency_source_not_recommended',
                'file' => $file,
                'message' => 'Framework or dependency source remains visible in graph paths but is omitted from the default application read set.',
            ];
        }

        foreach ($changedFiles as $file) {
            $candidates[] = [
                'id' => 'file:'.$file,
                'file' => $file,
                'relevance' => 1.0,
                'reason' => 'changed_file',
            ];
        }

        foreach ($mappedTests as $test) {
            if (! isset($test['file'])) {
                continue;
            }

            $candidates[] = array_filter([
                'id' => $test['id'],
                'file' => $test['file'],
                'line' => $test['line'] ?? null,
                'endLine' => $test['endLine'] ?? null,
                'relevance' => 0.85 * (float) ($test['confidence'] ?? 1.0),
                'reason' => 'mapped_test',
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $candidates;
    }

    /**
     * @param array<string, array<string, mixed>> $seeds
     * @param array<string, mixed> $node
     * @param array<int, string> $matchedTerms
     */
    private function addSeed(
        array &$seeds,
        array $node,
        float $relevance,
        string $source,
        array $matchedTerms = [],
        ?string $target = null,
    ): void {
        $id = (string) $node['id'];
        $sourceRank = $this->seedSourceRank($source);
        $candidate = array_filter([
            'id' => $id,
            'type' => $node['type'] ?? null,
            'label' => isset($node['label']) ? BoundedText::utf8Bytes((string) $node['label'], 512) : null,
            'file' => $this->nodeProjectFile($node),
            'line' => $node['line'] ?? null,
            'endLine' => $node['endLine'] ?? null,
            'relevance' => min(1.0, max(0.0, $relevance)),
            'sources' => [$source],
            'matchedTerms' => $matchedTerms,
            '_sourceRank' => $sourceRank,
            '_target' => $target,
        ], static fn (mixed $value): bool => $value !== null);
        $existing = $seeds[$id] ?? null;

        if ($existing === null) {
            $seeds[$id] = $candidate;

            return;
        }

        $existing['relevance'] = max($existing['relevance'], $candidate['relevance']);
        $existing['sources'] = array_values(array_unique([...$existing['sources'], $source]));
        $existing['matchedTerms'] = array_values(array_unique([
            ...$existing['matchedTerms'],
            ...$matchedTerms,
        ]));
        $existing['_sourceRank'] = min($existing['_sourceRank'], $sourceRank);

        if (($existing['_target'] ?? null) === null && $target !== null) {
            $existing['_target'] = $target;
        }

        $seeds[$id] = $existing;
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compareSeeds(array $left, array $right): int
    {
        return [
            $left['_sourceRank'],
            -$left['relevance'],
            $this->nodeTypeRank((string) ($left['type'] ?? '')),
            $left['file'] ?? '',
            $left['line'] ?? PHP_INT_MAX,
            $left['id'],
        ] <=> [
            $right['_sourceRank'],
            -$right['relevance'],
            $this->nodeTypeRank((string) ($right['type'] ?? '')),
            $right['file'] ?? '',
            $right['line'] ?? PHP_INT_MAX,
            $right['id'],
        ];
    }

    private function seedSourceRank(string $source): int
    {
        return match ($source) {
            'explicit_target' => 0,
            'changed_file' => 1,
            'task_identifier' => 2,
            default => 3,
        };
    }

    /** @param array<int, string> $sources @return array<int, string> */
    private function sortSeedSources(array $sources): array
    {
        $sources = array_values(array_unique($sources));
        usort($sources, fn (string $left, string $right): int => [
            $this->seedSourceRank($left),
            $left,
        ] <=> [
            $this->seedSourceRank($right),
            $right,
        ]);

        return $sources;
    }

    private function nodeTypeRank(string $type): int
    {
        return match ($type) {
            'route' => 0,
            'method' => 1,
            'form_request' => 2,
            'model', 'event', 'job' => 3,
            'test' => 4,
            'table', 'column' => 5,
            default => 6,
        };
    }

    /** @return array<int, string> */
    private function taskTerms(string $task, int &$omitted = 0): array
    {
        $expanded = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $task) ?? $task;
        $parts = preg_split('/[^\pL\pN]+/u', strtolower($expanded), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = [];
        $seen = [];

        foreach ($parts as $part) {
            $term = $this->normalizeTerm($part);

            if ($term === '' || isset(self::STOP_WORDS[$term])) {
                continue;
            }

            if (isset($seen[$term])) {
                continue;
            }

            $seen[$term] = true;

            if (count($terms) < self::MAX_TERMS) {
                $terms[$term] = $term;
            } else {
                $omitted++;
            }
        }

        return array_values($terms);
    }

    private function normalizeTerm(string $term): string
    {
        $term = strtolower(trim($term));
        $length = strlen($term);

        if ($length > 5 && str_ends_with($term, 'ies')) {
            return substr($term, 0, -3).'y';
        }

        if ($length > 5 && str_ends_with($term, 'ing')) {
            $stem = substr($term, 0, -3);

            if (strlen($stem) > 2 && substr($stem, -1) === substr($stem, -2, 1)) {
                $stem = substr($stem, 0, -1);
            }

            return $stem;
        }

        if ($length > 4 && str_ends_with($term, 'ed')) {
            return substr($term, 0, -2);
        }

        if ($length > 4 && str_ends_with($term, 's') && ! str_ends_with($term, 'ss')) {
            return substr($term, 0, -1);
        }

        return $term;
    }

    /** @return array<int, string> */
    private function taskIdentifiers(string $task, int &$omitted = 0): array
    {
        $identifiers = [];
        $seen = [];
        $patterns = [
            '/["\']([^"\']{1,512})["\']/u',
            '/\b[A-Za-z_][A-Za-z0-9_\\\\]*::[A-Za-z_][A-Za-z0-9_]*\b/',
            '/\b[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)+\b/',
            '/\b[A-Z][A-Za-z0-9_\\\\]{2,}\b/',
        ];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $task, $matches);

            foreach ($matches[1] ?? $matches[0] ?? [] as $match) {
                $match = strtolower(trim((string) $match));

                if ($match !== '' && ! isset($seen[$match])) {
                    $seen[$match] = true;

                    if (count($identifiers) >= self::MAX_IDENTIFIERS) {
                        $omitted++;
                        continue;
                    }

                    $identifiers[$match] = $match;
                }
            }
        }

        return array_values($identifiers);
    }

    /** @param array<string, mixed> $node @param array<int, string> $identifiers */
    private function nodeMatchesIdentifier(array $node, array $identifiers): bool
    {
        if ($identifiers === []) {
            return false;
        }

        $values = array_filter([
            strtolower(BoundedText::utf8Bytes((string) ($node['id'] ?? ''), 2048)),
            strtolower(BoundedText::utf8Bytes((string) ($node['label'] ?? ''), 2048)),
            strtolower(BoundedText::utf8Bytes((string) ($node['metadata']['name'] ?? ''), 2048)),
        ]);

        return array_intersect($values, $identifiers) !== [];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string> $terms
     * @return array<string, float>
     */
    private function lexicalMatches(array $node, array $terms): array
    {
        if ($terms === []) {
            return [];
        }

        $rawId = BoundedText::utf8Bytes((string) ($node['id'] ?? ''), 2048);
        $rawLabel = BoundedText::utf8Bytes((string) ($node['label'] ?? ''), 2048);
        $rawName = BoundedText::utf8Bytes((string) ($node['metadata']['name'] ?? ''), 2048);
        $rawFile = BoundedText::utf8Bytes((string) ($node['file'] ?? ''), 2048);
        $id = strtolower($rawId);
        $label = strtolower($rawLabel);
        $name = strtolower($rawName);
        $fields = [
            ['value' => $id, 'tokens' => $this->tokens($rawId), 'exact' => 1.00, 'token' => 0.80],
            ['value' => $name, 'tokens' => $this->tokens($rawName), 'exact' => 0.98, 'token' => 0.78],
            ['value' => $label, 'tokens' => $this->tokens($rawLabel), 'exact' => 0.95, 'token' => 0.70],
            ['value' => strtolower($rawFile), 'tokens' => $this->tokens($rawFile), 'exact' => 0.60, 'token' => 0.60],
            ['value' => '', 'tokens' => $this->supplementalNodeTokens($node), 'exact' => 0.45, 'token' => 0.45],
        ];
        $matches = [];

        foreach ($terms as $term) {
            $weight = 0.0;

            foreach ($fields as $field) {
                if ($field['value'] === $term) {
                    $weight = max($weight, $field['exact']);
                }

                if (isset($field['tokens'][$term])) {
                    $weight = max($weight, $field['token']);
                }
            }

            if ($weight > 0) {
                $matches[$term] = $weight;
            }
        }

        return $matches;
    }

    /** @return array<string, true> */
    private function tokens(string $value): array
    {
        $value = BoundedText::utf8Bytes($value, 2048);
        $expanded = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $value) ?? $value;
        $parts = preg_split('/[^\pL\pN]+/u', strtolower($expanded), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];

        foreach ($parts as $part) {
            $term = $this->normalizeTerm($part);

            if ($term !== '') {
                $tokens[$term] = true;
            }
        }

        return $tokens;
    }

    /** @param array<string, mixed> $node @return array<string, true> */
    private function supplementalNodeTokens(array $node): array
    {
        $values = [
            $node['signature'] ?? null,
            $node['summary'] ?? null,
            $node['metadata']['uri'] ?? null,
            $node['metadata']['table'] ?? null,
            $node['metadata']['input'] ?? null,
            $node['metadata']['output'] ?? null,
            $node['metadata']['effect'] ?? null,
        ];
        $tokens = [];
        $scalars = 0;
        $visited = 0;

        $collect = function (mixed $value) use (&$collect, &$tokens, &$scalars, &$visited): void {
            $visited++;

            if ($visited > 64 || $scalars >= 16 || count($tokens) >= 128) {
                return;
            }

            if (is_scalar($value)) {
                $scalars++;
                $tokens += $this->tokens(BoundedText::utf8Bytes((string) $value, 512));

                return;
            }

            if (! is_array($value)) {
                return;
            }

            foreach (array_slice($value, 0, 16) as $nested) {
                $collect($nested);
            }
        };

        foreach ($values as $value) {
            $collect($value);
        }

        return $tokens;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, string> $terms
     * @param array<int, string> $identifiers
     * @param array<string, int> $omitted
     * @return array<int, array<string, mixed>>
     */
    private function rankChangedFileNodes(
        array $nodes,
        array $terms,
        array $identifiers,
        array &$omitted,
    ): array
    {
        $ranked = [];

        foreach ($nodes as $node) {
            if ($this->nodeTextExceedsLimit($node)) {
                $omitted['node_text_limit']++;
            }

            if (! $this->nodeHasBoundedIdentity($node)) {
                continue;
            }

            $matches = $this->lexicalMatches($node, $terms);
            $ranked[] = [
                'node' => $node,
                'score' => ($this->nodeMatchesIdentifier($node, $identifiers) ? 100.0 : 0.0)
                    + array_sum($matches),
            ];
        }

        usort($ranked, fn (array $left, array $right): int => [
            -$left['score'],
            $this->nodeTypeRank((string) ($left['node']['type'] ?? '')),
            $left['node']['line'] ?? PHP_INT_MAX,
            $left['node']['id'],
        ] <=> [
            -$right['score'],
            $this->nodeTypeRank((string) ($right['node']['type'] ?? '')),
            $right['node']['line'] ?? PHP_INT_MAX,
            $right['node']['id'],
        ]);

        if (count($ranked) <= self::MAX_NODES_PER_CHANGED_FILE) {
            return array_column($ranked, 'node');
        }

        $relevantSlots = intdiv(self::MAX_NODES_PER_CHANGED_FILE, 2);
        $selected = array_slice($ranked, 0, $relevantSlots);
        $selectedIds = array_fill_keys(array_map(
            static fn (array $entry): string => (string) $entry['node']['id'],
            $selected,
        ), true);
        $remaining = array_values(array_filter(
            $ranked,
            static fn (array $entry): bool => ! isset($selectedIds[$entry['node']['id']]),
        ));
        usort($remaining, static fn (array $left, array $right): int => [
            $left['node']['line'] ?? PHP_INT_MAX,
            $left['node']['id'],
        ] <=> [
            $right['node']['line'] ?? PHP_INT_MAX,
            $right['node']['id'],
        ]);
        $sampleSlots = self::MAX_NODES_PER_CHANGED_FILE - count($selected);

        for ($position = 0; $position < $sampleSlots; $position++) {
            $index = $sampleSlots === 1
                ? 0
                : intdiv($position * (count($remaining) - 1), $sampleSlots - 1);
            $selected[] = $remaining[$index];
        }

        return array_column($selected, 'node');
    }

    /** @param array<string, mixed> $node */
    private function nodeHasBoundedIdentity(array $node): bool
    {
        $id = $node['id'] ?? null;
        $type = $node['type'] ?? null;

        return is_string($id)
            && $id !== ''
            && strlen($id) <= self::MAX_NODE_ID_BYTES
            && preg_match('//u', $id) === 1
            && is_string($type)
            && strlen($type) <= 128
            && preg_match('//u', $type) === 1;
    }

    private function isBoundedExactReference(string $value): bool
    {
        return $value !== ''
            && ! str_contains($value, "\0")
            && strlen($value) <= self::MAX_EXACT_REFERENCE_BYTES
            && mb_strlen($value) <= self::MAX_EXACT_REFERENCE_CHARACTERS
            && preg_match('//u', $value) === 1;
    }

    /** @param array<string, mixed> $node */
    private function nodeTextExceedsLimit(array $node): bool
    {
        return max(
            strlen((string) ($node['id'] ?? '')),
            strlen((string) ($node['label'] ?? '')),
            strlen((string) ($node['metadata']['name'] ?? '')),
            strlen((string) ($node['file'] ?? '')),
        ) > 2048;
    }

    /** @param array<string, array<string, mixed>> $nodes */
    private function pruneExactNodes(array &$nodes): int
    {
        if (count($nodes) <= self::MAX_LEXICAL_CANDIDATES) {
            return 0;
        }

        ksort($nodes);
        $omitted = count($nodes) - self::MAX_LEXICAL_CANDIDATES;
        $nodes = array_slice($nodes, 0, self::MAX_LEXICAL_CANDIDATES, true);

        return $omitted;
    }

    /** @param array<string, array<string, mixed>> $candidates */
    private function pruneLexical(array &$candidates): int
    {
        if (count($candidates) <= self::MAX_LEXICAL_CANDIDATES) {
            return 0;
        }

        uasort($candidates, static fn (array $left, array $right): int => [
            -$left['provisional'],
            $left['node']['id'],
        ] <=> [
            -$right['provisional'],
            $right['node']['id'],
        ]);
        $omitted = count($candidates) - self::MAX_LEXICAL_CANDIDATES;
        $candidates = array_slice($candidates, 0, self::MAX_LEXICAL_CANDIDATES, true);

        return $omitted;
    }

    /** @param array<string, mixed> $node */
    private function nodeProjectFile(array $node): ?string
    {
        $file = $node['file'] ?? null;

        return is_string($file) && strlen($file) <= 4096
            ? $this->normalizeProjectFile($file)
            : null;
    }

    private function normalizeProjectFile(string $file): ?string
    {
        return $this->projectPaths->normalize($file);
    }

    /** @return array<string, int> */
    private function emptyOmittedCounts(): array
    {
        return array_fill_keys([
            'input_limit',
            'lexical_candidate_limit',
            'identifier_candidate_limit',
            'target_candidate_limit',
            'seed_limit',
            'changed_file_node_limit',
            'changed_file_candidate_limit',
            'node_text_limit',
            'node_scan_limit',
            'min_confidence',
            'non_causal_handler',
            'column_excluded',
            'column_candidate_limit',
            'field_metadata_limit',
            'dispatch_metadata_limit',
            'table_hub_guard',
            'dependency_source',
            'evidence_source',
            'depth_limit',
            'transition_limit',
            'traversal_result_limit',
            'related_fact_limit',
            'path_limit',
            'evidence_limit',
            'mapped_test_limit',
            'verification_target_limit',
            'verification_command_limit',
            'uncertainty_limit',
        ], 0);
    }

    /** @param array<string, int> ...$sets @return array<string, int> */
    private function mergeCounts(array ...$sets): array
    {
        $merged = [];

        foreach ($sets as $set) {
            foreach ($set as $reason => $count) {
                $merged[$reason] = ($merged[$reason] ?? 0) + (int) $count;
            }
        }

        return $merged;
    }

    /**
     * @param array<int, array<string, mixed>> $uncertainties
     * @return array{array<int, array<string, mixed>>, array<string, int>, int}
     */
    private function boundedUncertainties(array $uncertainties): array
    {
        $unique = [];

        foreach ($uncertainties as $uncertainty) {
            if (! is_array($uncertainty) || ! is_string($uncertainty['reason'] ?? null)) {
                continue;
            }

            $key = json_encode($uncertainty, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (is_string($key)) {
                $unique[$key] = $uncertainty;
            }
        }

        $uncertainties = array_values($unique);
        usort($uncertainties, static fn (array $left, array $right): int => [
            $left['reason'] ?? '',
            $left['target'] ?? '',
            $left['route'] ?? '',
            $left['file'] ?? '',
            $left['nodeId'] ?? '',
            json_encode($left['candidates'] ?? []),
        ] <=> [
            $right['reason'] ?? '',
            $right['target'] ?? '',
            $right['route'] ?? '',
            $right['file'] ?? '',
            $right['nodeId'] ?? '',
            json_encode($right['candidates'] ?? []),
        ]);
        $counts = [];

        foreach ($uncertainties as $uncertainty) {
            $reason = $uncertainty['reason'];
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }

        ksort($counts);
        $omitted = max(0, count($uncertainties) - self::MAX_UNCERTAINTIES);

        return [array_slice($uncertainties, 0, self::MAX_UNCERTAINTIES), $counts, $omitted];
    }
}
