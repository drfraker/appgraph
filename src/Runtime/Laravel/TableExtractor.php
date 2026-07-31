<?php

namespace AppGraph\Runtime\Laravel;

/**
 * Adapted from Pest v5.0.2's TIA TableExtractor.
 * Pest is MIT licensed; see THIRD_PARTY_NOTICES.md.
 */
final class TableExtractor
{
    private const DML_PREFIXES = ['select', 'insert', 'update', 'delete', 'with', 'replace'];

    private const IDENTIFIER = '(?:"[^"]+"|`[^`]+`|\[[^\]]+\]|\w+)';

    /** @return list<string> */
    public static function fromSql(string $sql): array
    {
        $trimmed = ltrim($sql);

        if ($trimmed === '' || preg_match('/^[a-zA-Z]+/', $trimmed, $prefixMatch) !== 1) {
            return [];
        }

        if (! in_array(strtolower($prefixMatch[0]), self::DML_PREFIXES, true)) {
            return [];
        }

        $pattern = '/\b(?:from|into|update|join)\s+('.self::IDENTIFIER.'(?:\s*\.\s*'.self::IDENTIFIER.')*)/i';

        if (preg_match_all($pattern, $sql, $matches) === false) {
            return [];
        }

        $commonTableExpressions = self::commonTableExpressions($sql);
        $tables = [];

        foreach ($matches[1] as $qualified) {
            $name = self::unqualified($qualified);

            if ($name !== ''
                && ! isset($commonTableExpressions[strtolower($name)])
                && ! self::isSchemaMetadata($name)) {
                $tables[strtolower($name)] = true;
            }
        }

        $tables = array_keys($tables);
        sort($tables);

        return $tables;
    }

    /** @return array<string, true> */
    private static function commonTableExpressions(string $sql): array
    {
        $pattern = '/(?:\bwith(?:\s+recursive)?|,)\s*('.self::IDENTIFIER.')\s+as\s*\(/i';

        if (preg_match_all($pattern, $sql, $matches) === false) {
            return [];
        }

        $aliases = [];

        foreach ($matches[1] as $alias) {
            $alias = trim($alias, " \t\n\r\"`[]");

            if ($alias !== '') {
                $aliases[strtolower($alias)] = true;
            }
        }

        return $aliases;
    }

    private static function unqualified(string $qualified): string
    {
        $name = '';

        foreach (explode('.', $qualified) as $segment) {
            $segment = trim($segment, " \t\n\r\"`[]");

            if ($segment === '') {
                continue;
            }

            if (self::isSchemaMetadata($segment)) {
                return '';
            }

            $name = $segment;
        }

        return $name;
    }

    private static function isSchemaMetadata(string $name): bool
    {
        $name = strtolower($name);

        return in_array($name, ['sqlite_master', 'sqlite_sequence', 'migrations'], true)
            || str_starts_with($name, 'pg_')
            || str_starts_with($name, 'information_schema');
    }
}
