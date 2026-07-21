<?php

namespace AppGraph\Mcp\Tools;

use AppGraph\Storage\GenerationDiffer;
use AppGraph\Support\ScanLock;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

#[Name('appgraph_refresh')]
#[Description('Rebuild this Laravel application\'s AppGraph after a meaningful batch of source changes and return a change receipt: what changed since the previous generation, counted overall and by category (routes, writes, authorization, queues, tests). Do not call before every lookup.')]
class RefreshTool extends Tool
{
    use InteractsWithQueryEngine;
    use RejectsUnknownInput;

    /**
     * Bounded number of per-change detail rows in a receipt. Counts always
     * describe the complete comparison; detail rows are a sample of it.
     */
    private const RECEIPT_DETAIL_LIMIT = 25;

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($error = $this->rejectUnknownInput($request, [])) !== null) {
            return $error;
        }

        $lock = app(ScanLock::class);
        $lockOwner = null;

        try {
            // The scan itself crosses a fresh-process boundary so Laravel's
            // route/event/bus/container registries match the edited source.
            // refreshGraph acquires the parent lock immediately afterward and
            // returns it while the receipt is built from the published store.
            $refresh = $this->refreshGraph();
            $lockOwner = $refresh['lockOwner'];

            return $this->structuredResponse($this->receipt($refresh['result']));
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        } finally {
            if ($lockOwner !== null) {
                $lock->release($lockOwner);
            }
        }
    }

    /**
     * Compile the agent-facing change receipt for a completed refresh: scalar
     * revisions, whether a new generation was published, and — when a distinct
     * previous generation exists — the bounded semantic diff between the two.
     * A diff failure degrades to a receipt without change details rather than
     * failing the refresh that already succeeded.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function receipt(array $result): array
    {
        $revision = (string) $result['generation']['id'];
        $previous = $result['previousGeneration'] ?? null;
        $previousRevision = is_array($previous) && is_string($previous['id'] ?? null)
            ? $previous['id']
            : null;
        $changed = (bool) ($result['created'] ?? false);

        $payload = [
            'query' => 'refresh',
            'revision' => $revision,
            'changed' => $changed,
        ];

        if ($previousRevision !== null) {
            $payload['previousRevision'] = $previousRevision;
        }

        if ($changed && $previousRevision !== null && $previousRevision !== $revision) {
            try {
                $payload += $this->receiptDiff($previousRevision, $revision);
            } catch (RuntimeException $exception) {
                $payload['diffUnavailable'] = ['reason' => $exception->getMessage()];
            }
        } elseif ($changed) {
            $payload['firstGeneration'] = true;
            $counts = $result['generation']['counts'] ?? null;

            if (is_array($counts)) {
                $payload['counts'] = $counts;
            }
        }

        if (($result['mirrorWarnings'] ?? []) !== []) {
            $payload['mirrorWarnings'] = $result['mirrorWarnings'];
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function receiptDiff(string $from, string $to): array
    {
        $diff = app(GenerationDiffer::class)->diff($from, $to, [], self::RECEIPT_DETAIL_LIMIT);
        $counts = ['overall' => $diff['counts']['overall']];
        $byCategory = array_filter(
            $diff['counts']['byCategory'],
            static fn (array $count): bool => $count['total'] > 0,
        );

        if ($byCategory !== []) {
            $counts['byCategory'] = $byCategory;
        }

        $receipt = [
            'comparison' => $diff['comparison'],
            'counts' => $counts,
        ];
        $changes = array_filter($diff['changes']);

        if ($changes !== []) {
            $receipt['changes'] = $changes;
        }

        $omitted = array_filter($diff['omitted']);

        if ($omitted !== []) {
            $receipt['omitted'] = $omitted;
        }

        if ($diff['truncated']) {
            $receipt['truncated'] = true;
        }

        return $receipt;
    }
}
