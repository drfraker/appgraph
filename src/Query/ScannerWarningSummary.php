<?php

namespace AppGraph\Query;

use AppGraph\Support\AgentPayloadLimiter;
use AppGraph\Support\BoundedText;
use JsonException;

/**
 * Build a small, deterministic projection of scanner warnings for agents.
 *
 * Warning evidence is semantic data: identifiers and paths are kept exactly or
 * their whole field/sample is omitted. Only the human-facing message may be
 * shortened. Every omission is counted so a sample can never imply that it is
 * the complete warning set.
 */
final class ScannerWarningSummary
{
    private const MAX_SCANNER_GROUPS = 64;

    private const MAX_SCANNER_BYTES = 16384;

    private const MAX_SAMPLES = 16;

    private const MAX_SAMPLE_FIELDS = 24;

    private const MAX_SAMPLE_BYTES = 8192;

    private const MAX_SAMPLE_EVIDENCE_BYTES = 6144;

    private const MAX_SAMPLES_BYTES = 65536;

    private const MAX_MESSAGE_BYTES = 1024;

    /** @var array<string, int> */
    private const FIELD_PRIORITY = [
        'scanner' => 0,
        'reason' => 1,
        'inference' => 2,
        'code' => 3,
        'class' => 4,
        'parent' => 5,
        'file' => 6,
        'path' => 7,
        'absoluteFile' => 8,
        'absolute_path' => 9,
        'relativePath' => 10,
        'line' => 11,
        'method' => 12,
        'route' => 13,
        'target' => 14,
    ];

    /** @return array<string, mixed> */
    public static function fromMeta(array $meta): array
    {
        $sourceMalformed = array_key_exists('warnings', $meta)
            && ! is_array($meta['warnings']);
        $warnings = is_array($meta['warnings'] ?? null)
            ? array_values($meta['warnings'])
            : [];

        return self::summarize($warnings, $sourceMalformed);
    }

    /**
     * @param array<int, mixed> $warnings
     * @return array<string, mixed>
     */
    private static function summarize(array $warnings, bool $sourceMalformed): array
    {
        $total = count($warnings);
        $structured = [];
        $scannerGroups = [];
        $unattributed = 0;
        $unstructured = 0;

        foreach ($warnings as $position => $warning) {
            if (! is_array($warning)) {
                $unstructured++;
                $unattributed++;

                continue;
            }

            $structured[] = [
                'warning' => $warning,
                'position' => $position,
                'sort' => self::warningSortKey($warning),
            ];
            $scanner = $warning['scanner'] ?? null;

            if (! is_string($scanner)
                || $scanner === ''
                || self::jsonBytes($scanner) === null) {
                $unattributed++;

                continue;
            }

            $key = strlen($scanner).':'.$scanner;
            $scannerGroups[$key] ??= ['scanner' => $scanner, 'count' => 0];
            $scannerGroups[$key]['count']++;
        }

        $scannerGroups = array_values($scannerGroups);
        usort(
            $scannerGroups,
            static fn (array $left, array $right): int => strcmp($left['scanner'], $right['scanner']),
        );

        $byScanner = [];
        $scannerBytes = 0;
        $omittedScannerGroups = 0;
        $omittedScannerWarnings = 0;

        foreach ($scannerGroups as $group) {
            $bytes = self::jsonBytes($group);

            if (count($byScanner) >= self::MAX_SCANNER_GROUPS
                || $bytes === null
                || $scannerBytes + $bytes > self::MAX_SCANNER_BYTES) {
                $omittedScannerGroups++;
                $omittedScannerWarnings += $group['count'];

                continue;
            }

            $byScanner[] = $group;
            $scannerBytes += $bytes;
        }

        usort(
            $structured,
            static fn (array $left, array $right): int => $left['sort'] <=> $right['sort']
                ?: $left['position'] <=> $right['position'],
        );

        $samples = [];
        $sampleBytes = 0;
        $omittedFields = 0;
        $truncatedMessages = 0;

        foreach ($structured as $record) {
            if (count($samples) >= self::MAX_SAMPLES) {
                break;
            }

            $sample = self::compactWarning($record['warning']);
            $bytes = self::jsonBytes($sample);

            if ($bytes === null
                || $bytes > self::MAX_SAMPLE_BYTES
                || $sampleBytes + $bytes > self::MAX_SAMPLES_BYTES) {
                continue;
            }

            $samples[] = $sample;
            $sampleBytes += $bytes;
            $omittedFields += $sample['omittedFields'];
            $truncatedMessages += $sample['messageTruncated'] ? 1 : 0;
        }

        $returnedSamples = count($samples);
        $omittedSamples = max(0, $total - $returnedSamples);
        $byScannerTruncated = $omittedScannerGroups > 0;
        $samplesTruncated = $omittedSamples > 0;
        $fieldsTruncated = $omittedFields > 0;
        $messagesTruncated = $truncatedMessages > 0;
        $truncated = $sourceMalformed
            || $byScannerTruncated
            || $samplesTruncated
            || $fieldsTruncated
            || $messagesTruncated;

        return [
            'total' => $total,
            'structured' => count($structured),
            'unstructured' => $unstructured,
            'unattributed' => $unattributed,
            'scannerGroups' => count($scannerGroups),
            'returnedScannerGroups' => count($byScanner),
            'omittedScannerGroups' => $omittedScannerGroups,
            'omittedScannerWarnings' => $omittedScannerWarnings,
            'returnedSamples' => $returnedSamples,
            'omittedSamples' => $omittedSamples,
            'omittedFields' => $omittedFields,
            'truncatedMessages' => $truncatedMessages,
            'sourceMalformed' => $sourceMalformed,
            'byScannerTruncated' => $byScannerTruncated,
            'samplesTruncated' => $samplesTruncated,
            'fieldsTruncated' => $fieldsTruncated,
            'messagesTruncated' => $messagesTruncated,
            'truncated' => $truncated,
            'byScanner' => $byScanner,
            'samples' => $samples,
            'bounds' => [
                'maxScannerGroups' => self::MAX_SCANNER_GROUPS,
                'maxScannerBytes' => self::MAX_SCANNER_BYTES,
                'returnedScannerBytes' => $scannerBytes,
                'maxSamples' => self::MAX_SAMPLES,
                'maxSampleFields' => self::MAX_SAMPLE_FIELDS,
                'maxSampleBytes' => self::MAX_SAMPLE_BYTES,
                'maxSamplesBytes' => self::MAX_SAMPLES_BYTES,
                'returnedSamplesBytes' => $sampleBytes,
                'maxMessageBytes' => self::MAX_MESSAGE_BYTES,
            ],
        ];
    }

    /** @param array<string, mixed> $warning @return array<string, mixed> */
    private static function compactWarning(array $warning): array
    {
        $fieldCount = count($warning);
        $hasMessage = is_string($warning['message'] ?? null);
        $originalMessage = $hasMessage ? $warning['message'] : '';
        $message = $hasMessage
            ? BoundedText::utf8Bytes($originalMessage, self::MAX_MESSAGE_BYTES)
            : '';
        $fields = $warning;

        if ($hasMessage) {
            unset($fields['message']);
        }

        uksort($fields, static function (int|string $left, int|string $right): int {
            $left = (string) $left;
            $right = (string) $right;
            $leftPriority = self::FIELD_PRIORITY[$left]
                ?? (AgentPayloadLimiter::isExactStringKey($left) ? 50 : 100);
            $rightPriority = self::FIELD_PRIORITY[$right]
                ?? (AgentPayloadLimiter::isExactStringKey($right) ? 50 : 100);

            return $leftPriority <=> $rightPriority ?: strcmp($left, $right);
        });

        $boundedFields = [];

        $maxEvidenceFields = self::MAX_SAMPLE_FIELDS - ($hasMessage ? 1 : 0);

        foreach ($fields as $key => $value) {
            if (count($boundedFields) >= $maxEvidenceFields) {
                continue;
            }

            $candidate = $boundedFields;
            $candidate[$key] = $value;
            $bytes = self::jsonBytes($candidate);

            if ($bytes === null || $bytes > self::MAX_SAMPLE_EVIDENCE_BYTES) {
                continue;
            }

            $boundedFields = $candidate;
        }

        $sample = self::makeSample(
            $boundedFields,
            $fieldCount,
            $hasMessage,
            $message,
            $originalMessage,
        );
        $bytes = self::jsonBytes($sample);

        if ($bytes !== null && $bytes <= self::MAX_SAMPLE_BYTES) {
            return $sample;
        }

        // JSON escaping can expand prose beyond its raw UTF-8 byte length.
        // Find the largest UTF-8-safe message that keeps the complete sample
        // within its byte budget; evidence fields remain untouched.
        $low = 0;
        $high = strlen($message);
        $best = '';

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $candidateMessage = BoundedText::utf8Bytes($message, $middle);
            $candidate = self::makeSample(
                $boundedFields,
                $fieldCount,
                $hasMessage,
                $candidateMessage,
                $originalMessage,
            );
            $candidateBytes = self::jsonBytes($candidate);

            if ($candidateBytes !== null && $candidateBytes <= self::MAX_SAMPLE_BYTES) {
                $best = $candidateMessage;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return self::makeSample(
            $boundedFields,
            $fieldCount,
            $hasMessage,
            $best,
            $originalMessage,
        );
    }

    /**
     * @param array<int|string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function makeSample(
        array $fields,
        int $fieldCount,
        bool $hasMessage,
        string $message,
        string $originalMessage,
    ): array {
        $returnedFields = count($fields) + ($hasMessage ? 1 : 0);
        $omittedFields = max(0, $fieldCount - $returnedFields);
        $messageTruncated = $hasMessage && $message !== $originalMessage;
        $sample = [
            // `fields` is an AgentPayloadLimiter exact-string collection. If
            // later response pressure cannot preserve its contents, the whole
            // sample row is omitted instead of inventing shortened evidence.
            'fields' => $fields,
            'fieldCount' => $fieldCount,
            'returnedFields' => $returnedFields,
            'omittedFields' => $omittedFields,
            'fieldsTruncated' => $omittedFields > 0,
            'messageTruncated' => $messageTruncated,
            'truncated' => $omittedFields > 0 || $messageTruncated,
        ];

        if ($hasMessage) {
            $sample['message'] = $message;
        }

        return $sample;
    }

    /** @param array<string, mixed> $warning @return array<int, string> */
    private static function warningSortKey(array $warning): array
    {
        $canonical = self::canonicalize($warning);

        try {
            $encoded = json_encode(
                $canonical,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            $encoded = serialize($canonical);
        }

        return [
            self::sortableString($warning['scanner'] ?? null),
            self::sortableString($warning['reason'] ?? null),
            self::sortableString($warning['inference'] ?? null),
            self::sortableString($warning['file'] ?? ($warning['path'] ?? null)),
            self::sortableString($warning['class'] ?? null),
            sprintf('%020d', is_int($warning['line'] ?? null) ? $warning['line'] : 0),
            hash('sha256', $encoded),
        ];
    }

    private static function sortableString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $nested) {
            $value[$key] = self::canonicalize($nested);
        }

        return $value;
    }

    private static function jsonBytes(mixed $value): ?int
    {
        try {
            return strlen(json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } catch (JsonException) {
            return null;
        }
    }
}
