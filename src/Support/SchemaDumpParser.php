<?php

namespace AppGraph\Support;

class SchemaDumpParser
{
    /**
     * @return array<int, array{name: string, columns: array<int, array<string, mixed>>, indexes: array<int, array<string, mixed>>, foreignKeys: array<int, array<string, mixed>>, metadata: array<string, mixed>}>
     */
    public function parse(string $sql, string $driver): array
    {
        $tables = [];

        foreach ($this->statements($sql) as $statement) {
            $table = $this->parseCreateTable($statement, $driver);

            if ($table !== null) {
                $tables[$table['name']] = $table;
                continue;
            }

            $index = $this->parseCreateIndex($statement);

            if ($index !== null) {
                $tables[$index['table']] ??= [
                    'name' => $index['table'],
                    'columns' => [],
                    'indexes' => [],
                    'foreignKeys' => [],
                    'metadata' => [],
                ];

                $tables[$index['table']]['indexes'][] = $index['index'];
            }
        }

        ksort($tables);

        return array_values($tables);
    }

    /**
     * @return array<int, string>
     */
    private function statements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote === null && $char === '-' && $next === '-') {
                while ($i < $length && ! in_array($sql[$i], ["\n", "\r"], true)) {
                    $i++;
                }

                continue;
            }

            if ($quote === null && $char === '/' && $next === '*') {
                $i += 2;

                while ($i < $length && ! ($sql[$i] === '*' && ($sql[$i + 1] ?? '') === '/')) {
                    $i++;
                }

                $i++;

                continue;
            }

            if ($quote !== null) {
                $buffer .= $char;

                if ($char === '\\' && $i + 1 < $length) {
                    $buffer .= $sql[++$i];
                    continue;
                }

                if ($char === $quote) {
                    if (($sql[$i + 1] ?? '') === $quote && in_array($quote, ["'", '"', '`'], true)) {
                        $buffer .= $sql[++$i];
                        continue;
                    }

                    $quote = null;
                }

                continue;
            }

            if (in_array($char, ["'", '"', '`'], true)) {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === ';') {
                $statement = trim($buffer);

                if ($statement !== '') {
                    $statements[] = $statement;
                }

                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $statement = trim($buffer);

        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }

    /**
     * @return array{name: string, columns: array<int, array<string, mixed>>, indexes: array<int, array<string, mixed>>, foreignKeys: array<int, array<string, mixed>>, metadata: array<string, mixed>}|null
     */
    private function parseCreateTable(string $statement, string $driver): ?array
    {
        if (! preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(.+?)\s*\((.*)\)\s*(.*)$/is', $statement, $matches)) {
            return null;
        }

        $tableName = $this->cleanIdentifier($matches[1]);

        if ($tableName === '') {
            return null;
        }

        $table = [
            'name' => $tableName,
            'columns' => [],
            'indexes' => [],
            'foreignKeys' => [],
            'metadata' => [
                'driver' => $driver,
                'source' => 'schema_dump',
            ],
        ];

        foreach ($this->splitDefinitions($matches[2]) as $definition) {
            $foreignKey = $this->parseForeignKeyDefinition($definition);

            if ($foreignKey !== null) {
                $table['foreignKeys'][] = $foreignKey;
                continue;
            }

            $index = $this->parseIndexDefinition($definition, $tableName);

            if ($index !== null) {
                $table['indexes'][] = $index;
                continue;
            }

            $column = $this->parseColumnDefinition($definition);

            if ($column !== null) {
                $table['columns'][] = $column;
            }
        }

        return $table;
    }

    /**
     * @return array{table: string, index: array<string, mixed>}|null
     */
    private function parseCreateIndex(string $statement): ?array
    {
        if (! preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX\s+(.+?)\s+ON\s+(.+?)\s*\((.+)\)$/is', $statement, $matches)) {
            return null;
        }

        $name = $this->cleanIdentifier($matches[2]);
        $table = $this->cleanIdentifier($matches[3]);
        $columns = $this->parseIdentifierList($matches[4]);

        if ($name === '' || $table === '' || $columns === []) {
            return null;
        }

        return [
            'table' => $table,
            'index' => [
                'name' => $name,
                'columns' => $columns,
                'unique' => trim($matches[1]) !== '',
                'primary' => false,
                'type' => 'index',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function splitDefinitions(string $definitions): array
    {
        $parts = [];
        $buffer = '';
        $quote = null;
        $depth = 0;
        $length = strlen($definitions);

        for ($i = 0; $i < $length; $i++) {
            $char = $definitions[$i];

            if ($quote !== null) {
                $buffer .= $char;

                if ($char === '\\' && $i + 1 < $length) {
                    $buffer .= $definitions[++$i];
                    continue;
                }

                if ($char === $quote) {
                    if (($definitions[$i + 1] ?? '') === $quote) {
                        $buffer .= $definitions[++$i];
                        continue;
                    }

                    $quote = null;
                }

                continue;
            }

            if (in_array($char, ["'", '"', '`'], true)) {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = trim($buffer);
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $parts[] = trim($buffer);
        }

        return $parts;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseColumnDefinition(string $definition): ?array
    {
        if (! preg_match('/^([`"][^`"]+[`"]|[A-Za-z_][A-Za-z0-9_]*)\s+(.+)$/is', trim($definition), $matches)) {
            return null;
        }

        $name = $this->cleanIdentifier($matches[1]);
        $rest = trim($matches[2]);

        if ($name === '' || preg_match('/^(PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT|FOREIGN|CHECK)\b/i', $name)) {
            return null;
        }

        return [
            'name' => $name,
            'type' => $this->columnType($rest),
            'nullable' => ! preg_match('/\bNOT\s+NULL\b/i', $rest),
            'default' => $this->columnDefault($rest),
            'autoIncrement' => preg_match('/\b(AUTO_INCREMENT|AUTOINCREMENT)\b/i', $rest) === 1,
            'comment' => $this->columnComment($rest),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseIndexDefinition(string $definition, string $table): ?array
    {
        if (preg_match('/^PRIMARY\s+KEY\s*(?:USING\s+\w+\s*)?\((.+)\)$/is', $definition, $matches)) {
            return [
                'name' => 'primary',
                'columns' => $this->parseIdentifierList($matches[1]),
                'unique' => true,
                'primary' => true,
                'type' => 'primary',
            ];
        }

        if (! preg_match('/^(UNIQUE\s+)?(?:KEY|INDEX)\s+(?:[`"][^`"]+[`"]|[A-Za-z_][A-Za-z0-9_]*)?\s*(?:USING\s+\w+\s*)?\((.+)\)$/is', $definition, $matches)) {
            return null;
        }

        preg_match('/^(?:UNIQUE\s+)?(?:KEY|INDEX)\s+((?:[`"][^`"]+[`"]|[A-Za-z_][A-Za-z0-9_]*))/is', $definition, $nameMatch);
        $name = $this->cleanIdentifier($nameMatch[1] ?? $table.'_'.implode('_', $this->parseIdentifierList($matches[2])).'_index');

        return [
            'name' => $name,
            'columns' => $this->parseIdentifierList($matches[2]),
            'unique' => trim($matches[1]) !== '',
            'primary' => false,
            'type' => trim($matches[1]) !== '' ? 'unique' : 'index',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseForeignKeyDefinition(string $definition): ?array
    {
        if (! preg_match('/^(?:CONSTRAINT\s+(.+?)\s+)?FOREIGN\s+KEY\s*(?:.+?\s*)?\((.+?)\)\s+REFERENCES\s+(.+?)\s*\((.+?)\)(.*)$/is', $definition, $matches)) {
            return null;
        }

        $columns = $this->parseIdentifierList($matches[2]);
        $foreignTable = $this->cleanIdentifier($matches[3]);
        $foreignColumns = $this->parseIdentifierList($matches[4]);

        if ($columns === [] || $foreignTable === '' || $foreignColumns === []) {
            return null;
        }

        return [
            'name' => $matches[1] !== '' ? $this->cleanIdentifier($matches[1]) : '',
            'columns' => $columns,
            'foreignTable' => $foreignTable,
            'foreignColumns' => $foreignColumns,
            'onUpdate' => $this->referentialAction($matches[5] ?? '', 'UPDATE'),
            'onDelete' => $this->referentialAction($matches[5] ?? '', 'DELETE'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function parseIdentifierList(string $identifiers): array
    {
        return array_values(array_filter(array_map(function (string $identifier): string {
            $identifier = preg_replace('/\s+(ASC|DESC)\b/i', '', trim($identifier)) ?? trim($identifier);

            return $this->cleanIdentifier($identifier);
        }, $this->splitDefinitions($identifiers))));
    }

    private function columnType(string $definition): string
    {
        $keywords = '\b(COLLATE|CHARACTER\s+SET|NOT\s+NULL|NULL|DEFAULT|AUTO_INCREMENT|AUTOINCREMENT|PRIMARY\s+KEY|UNIQUE|COMMENT|CHECK|REFERENCES|GENERATED|AS)\b';

        if (preg_match('/^(.*?)\s+'.$keywords.'/is', $definition, $matches)) {
            return trim($matches[1]);
        }

        return trim($definition);
    }

    private function columnDefault(string $definition): mixed
    {
        if (! preg_match('/\bDEFAULT\s+((?:\'(?:\\\\.|[^\'])*\')|(?:"(?:\\\\.|[^"])*")|(?:\([^)]*\))|[^\s,]+)/is', $definition, $matches)) {
            return null;
        }

        return trim($matches[1], "'\"");
    }

    private function columnComment(string $definition): ?string
    {
        if (! preg_match('/\bCOMMENT\s+\'((?:\\\\.|[^\'])*)\'/is', $definition, $matches)) {
            return null;
        }

        return stripcslashes($matches[1]);
    }

    private function referentialAction(string $definition, string $action): ?string
    {
        if (! preg_match('/\bON\s+'.$action.'\s+(RESTRICT|CASCADE|SET\s+NULL|NO\s+ACTION|SET\s+DEFAULT)\b/i', $definition, $matches)) {
            return null;
        }

        return strtoupper($matches[1]);
    }

    private function cleanIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        $identifier = preg_replace('/\s+.*$/', '', $identifier) ?? $identifier;

        if (str_contains($identifier, '.')) {
            $parts = explode('.', $identifier);
            $identifier = end($parts);
        }

        return trim($identifier, "`\"'[] \t\n\r\0\x0B");
    }
}
