<?php

namespace AppGraph\Query;

final class FieldAccessClassifier
{
    public const TASK_MAX_OPERATIONS = 256;

    public const TASK_MAX_FIELDS_PER_OPERATION = 256;

    public const TASK_MAX_FIELDS = 1024;

    private const TASK_MAX_OPERATION_NAME_BYTES = 256;

    private const TASK_MAX_FIELD_BYTES = 1024;

    /**
     * @param array<string, mixed> $edge
     * @return array{match: string, ops: array<int, string>, fields: array<int, string>, provenOps: array<int, string>, possibleOps: array<int, string>, excludedOps: array<int, string>}
     */
    public function classify(array $edge, string $field, string $table): array
    {
        $operations = $edge['metadata']['operations'] ?? [];

        if ($operations === []) {
            return [
                'match' => 'possible',
                'ops' => [],
                'fields' => [],
                'provenOps' => [],
                'possibleOps' => [],
                'excludedOps' => [],
            ];
        }

        $classified = ['proven' => [], 'possible' => [], 'excluded' => []];
        $allFields = [];

        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                continue;
            }

            $name = isset($operation['operation']) ? (string) $operation['operation'] : 'unknown';
            $fields = array_values(array_filter(
                $operation['fields'] ?? [],
                static fn (mixed $value): bool => is_string($value) && $value !== '',
            ));

            foreach ($fields as $operationField) {
                $allFields[$operationField] = $operationField;
            }

            $coverage = $operation['fieldCoverage'] ?? 'unknown';

            if ($coverage === 'whole_row' || $this->fieldsProveColumn($fields, $field, $table)) {
                $classified['proven'][$name] = $name;
                continue;
            }

            if ($coverage !== 'complete' || $this->fieldsAreDynamic($fields)) {
                $classified['possible'][$name] = $name;
            } else {
                $classified['excluded'][$name] = $name;
            }
        }

        foreach ($classified as &$ops) {
            sort($ops);
            $ops = array_values($ops);
        }
        unset($ops);
        sort($allFields);

        $match = $classified['proven'] !== []
            ? 'proven'
            : ($classified['possible'] !== [] ? 'possible' : 'excluded');
        $relevant = $match === 'proven'
            ? [...$classified['proven'], ...$classified['possible']]
            : $classified[$match];
        $relevant = array_values(array_unique($relevant));
        sort($relevant);

        return [
            'match' => $match,
            'ops' => $relevant,
            'fields' => array_values($allFields),
            'provenOps' => $classified['proven'],
            'possibleOps' => $classified['possible'],
            'excludedOps' => $classified['excluded'],
        ];
    }

    /**
     * Classify one field for agent task planning without allowing a single
     * reads/writes edge to hide unbounded work in operations or field lists.
     * Cap exhaustion is possible evidence unless an examined operation already
     * proves the field.
     *
     * @param array<string, mixed> $edge
     * @return array{match: string, ops: array<int, string>, fields: array<int, string>, provenOps: array<int, string>, possibleOps: array<int, string>, excludedOps: array<int, string>, truncated: bool}
     */
    public function classifyForTask(array $edge, string $field, string $table): array
    {
        $operations = is_array($edge['metadata']['operations'] ?? null)
            ? $edge['metadata']['operations']
            : [];

        if ($operations === []) {
            return [
                'match' => 'possible',
                'ops' => [],
                'fields' => [],
                'provenOps' => [],
                'possibleOps' => [],
                'excludedOps' => [],
                'truncated' => false,
            ];
        }

        $classified = ['proven' => [], 'possible' => [], 'excluded' => []];
        $allFields = [];
        $truncated = count($operations) > self::TASK_MAX_OPERATIONS;
        $examinedOperations = 0;
        $examinedFields = 0;

        foreach ($operations as $operation) {
            if ($examinedOperations >= self::TASK_MAX_OPERATIONS) {
                break;
            }

            $examinedOperations++;

            if (! is_array($operation)) {
                continue;
            }

            $name = $this->boundedOperationName($operation['operation'] ?? null, $truncated);
            $rawFields = is_array($operation['fields'] ?? null)
                ? $operation['fields']
                : [];
            $remainingFieldBudget = max(0, self::TASK_MAX_FIELDS - $examinedFields);
            $operationFieldLimit = min(self::TASK_MAX_FIELDS_PER_OPERATION, $remainingFieldBudget);
            $fieldEvidenceIncomplete = ! is_array($operation['fields'] ?? []);

            if (count($rawFields) > $operationFieldLimit) {
                $truncated = true;
                $fieldEvidenceIncomplete = true;
            }

            $operationFieldsExamined = 0;
            $provesColumn = false;
            $hasDynamicField = false;

            foreach ($rawFields as $operationField) {
                if ($operationFieldsExamined >= $operationFieldLimit) {
                    break;
                }

                $operationFieldsExamined++;
                $examinedFields++;

                if (! is_string($operationField) || $operationField === '') {
                    continue;
                }

                if (strlen($operationField) > self::TASK_MAX_FIELD_BYTES) {
                    $truncated = true;
                    $fieldEvidenceIncomplete = true;

                    continue;
                }

                $allFields[$operationField] = $operationField;
                $provesColumn = $provesColumn
                    || $this->fieldsProveColumn([$operationField], $field, $table);
                $hasDynamicField = $hasDynamicField
                    || str_contains($operationField, '{dynamic}');
            }

            $coverage = $operation['fieldCoverage'] ?? 'unknown';

            if ($coverage === 'whole_row' || $provesColumn) {
                $classified['proven'][$name] = $name;
                continue;
            }

            if ($coverage !== 'complete' || $hasDynamicField || $fieldEvidenceIncomplete) {
                $classified['possible'][$name] = $name;
            } else {
                $classified['excluded'][$name] = $name;
            }
        }

        foreach ($classified as &$ops) {
            sort($ops);
            $ops = array_values($ops);
        }
        unset($ops);
        sort($allFields);

        $match = $classified['proven'] !== []
            ? 'proven'
            : (($classified['possible'] !== [] || $truncated) ? 'possible' : 'excluded');
        $relevant = $match === 'proven'
            ? [...$classified['proven'], ...$classified['possible']]
            : $classified[$match];
        $relevant = array_values(array_unique($relevant));
        sort($relevant);

        return [
            'match' => $match,
            'ops' => $relevant,
            'fields' => array_values($allFields),
            'provenOps' => $classified['proven'],
            'possibleOps' => $classified['possible'],
            'excludedOps' => $classified['excluded'],
            'truncated' => $truncated,
        ];
    }

    private function boundedOperationName(mixed $name, bool &$truncated): string
    {
        if (! is_scalar($name) || $name === null) {
            return 'unknown';
        }

        $name = (string) $name;

        if (strlen($name) > self::TASK_MAX_OPERATION_NAME_BYTES) {
            $truncated = true;

            return 'unknown';
        }

        return $name;
    }

    /** @param array<int, string> $fields */
    private function fieldsProveColumn(array $fields, string $column, string $table): bool
    {
        foreach ($fields as $field) {
            $root = preg_split('/\.|->/', $field, 2)[0] ?? $field;

            if ($field === $column
                || $root === $column
                || $field === $table.'.'.$column
                || str_starts_with($field, $table.'.'.$column.'->')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, string> $fields */
    private function fieldsAreDynamic(array $fields): bool
    {
        foreach ($fields as $field) {
            if (str_contains($field, '{dynamic}')) {
                return true;
            }
        }

        return false;
    }
}
