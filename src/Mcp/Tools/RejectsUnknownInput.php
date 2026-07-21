<?php

namespace AppGraph\Mcp\Tools;

use AppGraph\Support\AgentPayloadLimiter;
use AppGraph\Support\BoundedText;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

trait RejectsUnknownInput
{
    /** @param array<string, mixed> $payload */
    private function structuredResponse(array $payload): ResponseFactory
    {
        $payload = AgentPayloadLimiter::limit($payload);
        $query = is_string($payload['query'] ?? null)
            ? BoundedText::utf8Bytes($payload['query'], 128)
            : 'result';
        $summary = "AppGraph returned structured content for [{$query}].";

        if (($payload['responseTruncated'] ?? false) === true) {
            $summary .= ' The structured response was bounded; inspect responseBounds.';
        }

        return (new ResponseFactory(Response::text($summary)))
            ->withStructuredContent($payload);
    }

    /** @param array<string, mixed> $payload */
    private function structuredErrorResponse(string $message, array $payload): ResponseFactory
    {
        $payload = AgentPayloadLimiter::limit($payload);

        return (new ResponseFactory(Response::error($message)))
            ->withStructuredContent($payload);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $tool = parent::toArray();
        $tool['inputSchema']['additionalProperties'] = false;

        return $tool;
    }

    /**
     * MCP's generated object schemas allow additional properties by default,
     * while Laravel validation simply drops fields without rules. Reject them
     * explicitly so an agent typo cannot silently change a query's meaning.
     *
     * @param array<int, string> $allowed
     */
    private function rejectUnknownInput(Request $request, array $allowed): ?Response
    {
        $unknown = array_values(array_diff(array_keys($request->all()), $allowed));

        if ($unknown === []) {
            return null;
        }

        $unknown = array_map(static fn (int|string $field): string => (string) $field, $unknown);
        sort($unknown, SORT_STRING);
        $total = count($unknown);
        $shown = array_map(
            static function (string $field): string {
                $field = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $field) ?? '';
                $field = mb_substr($field, 0, 128);

                return '['.BoundedText::utf8Bytes($field, 512).']';
            },
            array_slice($unknown, 0, 10),
        );
        $suffix = $total > count($shown)
            ? ' and '.($total - count($shown)).' more'
            : '';

        return Response::error(
            'Unknown AppGraph input '.($total === 1 ? 'field' : 'fields').': '
            .implode(', ', $shown).$suffix.'.'
        );
    }
}
