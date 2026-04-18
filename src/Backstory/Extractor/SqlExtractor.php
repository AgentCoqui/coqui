<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

/**
 * Converts simple table-oriented SQL into markdown tables and preserves the rest.
 */
final class SqlExtractor implements ExtractorInterface
{
    public function extract(string $absolutePath): ExtractorResult
    {
        $result = BackstoryTextReader::read($absolutePath);
        if (!$result->success || $result->content === null) {
            return $result;
        }

        if (trim($result->content) === '') {
            return ExtractorResult::fail('File is empty');
        }

        $statements = self::splitStatements($result->content);
        if ($statements === []) {
            return ExtractorResult::fail('File is empty');
        }

        $createStatements = [];
        $schemasByTable = [];

        foreach ($statements as $index => $statement) {
            $create = $this->parseCreateTable(self::trimStatementTerminator(self::stripCommentsForParsing($statement)));
            if ($create === null) {
                continue;
            }

            $createStatements[$index] = $create;
            $schemasByTable[$create['normalized_name']][] = [
                'index' => $index,
                'columns' => $create['columns'],
            ];
        }

        $renderedStatements = [];
        $consumedCreateIndices = [];

        foreach ($statements as $index => $statement) {
            $insert = $this->parseInsertStatement(
                self::trimStatementTerminator(self::stripCommentsForParsing($statement)),
                $index,
                $schemasByTable,
                $consumedCreateIndices,
            );

            if ($insert === null) {
                continue;
            }

            $renderedStatements[$index] = $this->renderTableSection($insert['table_display'], $insert['columns'], $insert['rows']);
        }

        $sections = [];

        foreach ($statements as $index => $statement) {
            if (isset($renderedStatements[$index])) {
                $sections[] = $renderedStatements[$index];
                continue;
            }

            if (isset($createStatements[$index], $consumedCreateIndices[$index])) {
                continue;
            }

            if (trim(self::stripCommentsForParsing($statement)) === '') {
                continue;
            }

            $sections[] = $this->renderUnparsedSection($statement);
        }

        if ($sections === []) {
            return ExtractorResult::fail('File contained no extractable SQL statements');
        }

        $output = implode("\n\n", $sections);

        return ExtractorResult::ok($output, BackstoryTextReader::estimateTokens($output));
    }

    public function supportedExtensions(): array
    {
        return ['sql'];
    }

    /**
     * @param array<string, list<array{index: int, columns: list<string>}>> $schemasByTable
     * @param array<int, true> $consumedCreateIndices
     * @return array{table_display: string, columns: list<string>, rows: list<list<string>>}|null
     */
    private function parseInsertStatement(
        string $statement,
        int $statementIndex,
        array $schemasByTable,
        array &$consumedCreateIndices,
    ): ?array {
        if (!preg_match('/^\s*INSERT\s+INTO\s+/i', $statement, $matches)) {
            return null;
        }

        $offset = strlen($matches[0]);
        $table = self::consumeQualifiedIdentifier($statement, $offset);

        if ($table === null) {
            return null;
        }

        self::skipWhitespace($statement, $offset);

        $columns = null;
        if (($statement[$offset] ?? '') === '(') {
            $columnBody = self::extractParenthesized($statement, $offset);
            if ($columnBody === null) {
                return null;
            }

            $offset = $columnBody['next_offset'];
            $columns = $this->parseIdentifierList($columnBody['content']);
            if ($columns === null || $columns === []) {
                return null;
            }
        }

        self::skipWhitespace($statement, $offset);
        if (stripos(substr($statement, $offset), 'VALUES') !== 0) {
            return null;
        }

        $offset += strlen('VALUES');
        $rows = $this->parseValuesClause(substr($statement, $offset));
        if ($rows === null || $rows === []) {
            return null;
        }

        if ($columns === null) {
            $schema = $this->findSchemaForInsert($table['normalized'], $statementIndex, $schemasByTable);
            if ($schema === null) {
                return null;
            }

            $columns = $schema['columns'];
            $consumedCreateIndices[$schema['index']] = true;
        } else {
            $schema = $this->findSchemaForInsert($table['normalized'], $statementIndex, $schemasByTable);
            if ($schema !== null) {
                $consumedCreateIndices[$schema['index']] = true;
            }
        }

        foreach ($rows as $row) {
            if (count($row) !== count($columns)) {
                return null;
            }
        }

        return [
            'table_display' => $table['display'],
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string, list<array{index: int, columns: list<string>}>> $schemasByTable
     * @return array{index: int, columns: list<string>}|null
     */
    private function findSchemaForInsert(string $normalizedTable, int $statementIndex, array $schemasByTable): ?array
    {
        $schemas = $schemasByTable[$normalizedTable] ?? null;
        if ($schemas === null) {
            return null;
        }

        $selected = null;

        foreach ($schemas as $schema) {
            if ($schema['index'] >= $statementIndex) {
                break;
            }

            $selected = $schema;
        }

        return $selected;
    }

    /**
     * @return array{normalized_name: string, columns: list<string>}|null
     */
    private function parseCreateTable(string $statement): ?array
    {
        if (!preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?/i', $statement, $matches)) {
            return null;
        }

        $offset = strlen($matches[0]);
        $table = self::consumeQualifiedIdentifier($statement, $offset);
        if ($table === null) {
            return null;
        }

        self::skipWhitespace($statement, $offset);

        if (($statement[$offset] ?? '') !== '(') {
            return null;
        }

        $body = self::extractParenthesized($statement, $offset);
        if ($body === null) {
            return null;
        }

        $columns = $this->parseColumnDefinitions($body['content']);
        if ($columns === []) {
            return null;
        }

        return [
            'normalized_name' => $table['normalized'],
            'columns' => $columns,
        ];
    }

    /**
     * @return list<string>
     */
    private function parseColumnDefinitions(string $definitionList): array
    {
        $definitions = self::splitTopLevel($definitionList, ',');
        $columns = [];

        foreach ($definitions as $definition) {
            $trimmed = trim($definition);
            if ($trimmed === '' || self::isTableConstraint($trimmed)) {
                continue;
            }

            $offset = 0;
            $column = self::consumeIdentifierSegment($trimmed, $offset);
            if ($column === null) {
                continue;
            }

            $columns[] = $column['name'];
        }

        return $columns;
    }

    /**
     * @return list<string>|null
     */
    private function parseIdentifierList(string $content): ?array
    {
        $identifiers = [];

        foreach (self::splitTopLevel($content, ',') as $part) {
            $offset = 0;
            $identifier = self::consumeIdentifierSegment(trim($part), $offset);

            if ($identifier === null) {
                return null;
            }

            $identifiers[] = $identifier['name'];
        }

        return $identifiers;
    }

    /**
     * @return list<list<string>>|null
     */
    private function parseValuesClause(string $valuesClause): ?array
    {
        $offset = 0;
        $rows = [];
        $length = strlen($valuesClause);

        while ($offset < $length) {
            self::skipWhitespace($valuesClause, $offset);
            if ($offset >= $length) {
                break;
            }

            if (($valuesClause[$offset] ?? '') !== '(') {
                return null;
            }

            $tuple = self::extractParenthesized($valuesClause, $offset);
            if ($tuple === null) {
                return null;
            }

            $offset = $tuple['next_offset'];
            $rows[] = array_map(self::normalizeValueForMarkdown(...), self::splitTopLevel($tuple['content'], ','));

            self::skipWhitespace($valuesClause, $offset);
            if ($offset >= $length) {
                break;
            }

            if (($valuesClause[$offset] ?? '') !== ',') {
                return null;
            }

            $offset++;
        }

        return $rows;
    }

    /**
     * @param list<string> $columns
     * @param list<list<string>> $rows
     */
    private function renderTableSection(string $tableName, array $columns, array $rows): string
    {
        $escapedColumns = array_map(self::escapeMarkdownCell(...), $columns);
        $lines = ['#### Table: ' . $tableName, '', '| ' . implode(' | ', $escapedColumns) . ' |'];
        $lines[] = '| ' . implode(' | ', array_fill(0, count($columns), '---')) . ' |';

        foreach ($rows as $row) {
            $lines[] = '| ' . implode(' | ', array_map(self::escapeMarkdownCell(...), $row)) . ' |';
        }

        return implode("\n", $lines);
    }

    private function renderUnparsedSection(string $statement): string
    {
        return implode("\n", [
            '#### Unparsed SQL',
            '',
            BackstoryTextReader::toCodeFence($statement, 'sql'),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function splitStatements(string $content): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($content);
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inBacktickQuote = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($index = 0; $index < $length; $index++) {
            $char = $content[$index];
            $next = $content[$index + 1] ?? null;

            if ($inLineComment) {
                $buffer .= $char;
                if ($char === "\n") {
                    $inLineComment = false;
                }
                continue;
            }

            if ($inBlockComment) {
                $buffer .= $char;
                if ($char === '*' && $next === '/') {
                    $buffer .= '/';
                    $index++;
                    $inBlockComment = false;
                }
                continue;
            }

            if ($inSingleQuote) {
                $buffer .= $char;
                if ($char === "'") {
                    if ($next === "'") {
                        $buffer .= $next;
                        $index++;
                    } else {
                        $inSingleQuote = false;
                    }
                }
                continue;
            }

            if ($inDoubleQuote) {
                $buffer .= $char;
                if ($char === '"') {
                    if ($next === '"') {
                        $buffer .= $next;
                        $index++;
                    } else {
                        $inDoubleQuote = false;
                    }
                }
                continue;
            }

            if ($inBacktickQuote) {
                $buffer .= $char;
                if ($char === '`') {
                    if ($next === '`') {
                        $buffer .= $next;
                        $index++;
                    } else {
                        $inBacktickQuote = false;
                    }
                }
                continue;
            }

            if (self::startsLineComment($content, $index)) {
                $buffer .= '--';
                $index++;
                $inLineComment = true;
                continue;
            }

            if ($char === '#') {
                $buffer .= $char;
                $inLineComment = true;
                continue;
            }

            if ($char === '/' && $next === '*') {
                $buffer .= '/*';
                $index++;
                $inBlockComment = true;
                continue;
            }

            if ($char === "'") {
                $buffer .= $char;
                $inSingleQuote = true;
                continue;
            }

            if ($char === '"') {
                $buffer .= $char;
                $inDoubleQuote = true;
                continue;
            }

            if ($char === '`') {
                $buffer .= $char;
                $inBacktickQuote = true;
                continue;
            }

            $buffer .= $char;

            if ($char !== ';') {
                continue;
            }

            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
        }

        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }

    private static function stripCommentsForParsing(string $content): string
    {
        $output = '';
        $length = strlen($content);
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inBacktickQuote = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($index = 0; $index < $length; $index++) {
            $char = $content[$index];
            $next = $content[$index + 1] ?? null;

            if ($inLineComment) {
                if ($char === "\n") {
                    $output .= "\n";
                    $inLineComment = false;
                }
                continue;
            }

            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $output .= ' ';
                    $index++;
                    $inBlockComment = false;
                }
                continue;
            }

            if ($inSingleQuote) {
                $output .= $char;
                if ($char === "'") {
                    if ($next === "'") {
                        $output .= $next;
                        $index++;
                    } else {
                        $inSingleQuote = false;
                    }
                }
                continue;
            }

            if ($inDoubleQuote) {
                $output .= $char;
                if ($char === '"') {
                    if ($next === '"') {
                        $output .= $next;
                        $index++;
                    } else {
                        $inDoubleQuote = false;
                    }
                }
                continue;
            }

            if ($inBacktickQuote) {
                $output .= $char;
                if ($char === '`') {
                    if ($next === '`') {
                        $output .= $next;
                        $index++;
                    } else {
                        $inBacktickQuote = false;
                    }
                }
                continue;
            }

            if (self::startsLineComment($content, $index)) {
                $index++;
                $inLineComment = true;
                continue;
            }

            if ($char === '#') {
                $inLineComment = true;
                continue;
            }

            if ($char === '/' && $next === '*') {
                $index++;
                $inBlockComment = true;
                continue;
            }

            if ($char === "'") {
                $output .= $char;
                $inSingleQuote = true;
                continue;
            }

            if ($char === '"') {
                $output .= $char;
                $inDoubleQuote = true;
                continue;
            }

            if ($char === '`') {
                $output .= $char;
                $inBacktickQuote = true;
                continue;
            }

            $output .= $char;
        }

        return $output;
    }

    private static function startsLineComment(string $content, int $offset): bool
    {
        if (($content[$offset] ?? null) !== '-' || ($content[$offset + 1] ?? null) !== '-') {
            return false;
        }

        $next = $content[$offset + 2] ?? null;

        return $next === null || ctype_space($next);
    }

    /**
     * @return array{content: string, next_offset: int}|null
     */
    private static function extractParenthesized(string $content, int $offset): ?array
    {
        if (($content[$offset] ?? '') !== '(') {
            return null;
        }

        $length = strlen($content);
        $depth = 0;
        $buffer = '';
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inBacktickQuote = false;

        for ($index = $offset; $index < $length; $index++) {
            $char = $content[$index];
            $next = $content[$index + 1] ?? null;

            if ($inSingleQuote) {
                $buffer .= $char;
                if ($char === "'") {
                    if ($next === "'") {
                        $buffer .= $next;
                        $index++;
                    } else {
                        $inSingleQuote = false;
                    }
                }
                continue;
            }

            if ($inDoubleQuote) {
                $buffer .= $char;
                if ($char === '"') {
                    if ($next === '"') {
                        $buffer .= $next;
                        $index++;
                    } else {
                        $inDoubleQuote = false;
                    }
                }
                continue;
            }

            if ($inBacktickQuote) {
                $buffer .= $char;
                if ($char === '`') {
                    if ($next === '`') {
                        $buffer .= $next;
                        $index++;
                    } else {
                        $inBacktickQuote = false;
                    }
                }
                continue;
            }

            if ($char === "'") {
                $buffer .= $char;
                $inSingleQuote = true;
                continue;
            }

            if ($char === '"') {
                $buffer .= $char;
                $inDoubleQuote = true;
                continue;
            }

            if ($char === '`') {
                $buffer .= $char;
                $inBacktickQuote = true;
                continue;
            }

            if ($char === '(') {
                $depth++;
                if ($depth > 1) {
                    $buffer .= $char;
                }
                continue;
            }

            if ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return [
                        'content' => $buffer,
                        'next_offset' => $index + 1,
                    ];
                }

                if ($depth < 0) {
                    return null;
                }

                $buffer .= $char;
                continue;
            }

            $buffer .= $char;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function splitTopLevel(string $content, string $separator): array
    {
        $parts = [];
        $buffer = '';
        $length = strlen($content);
        $depth = 0;
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inBacktickQuote = false;

        for ($index = 0; $index < $length; $index++) {
            $char = $content[$index];
            $next = $content[$index + 1] ?? null;

            if ($inSingleQuote) {
                $buffer .= $char;
                if ($char === "'") {
                    if ($next === "'") {
                        $buffer .= $next;
                        $index++;
                    } else {
                        $inSingleQuote = false;
                    }
                }
                continue;
            }

            if ($inDoubleQuote) {
                $buffer .= $char;
                if ($char === '"') {
                    if ($next === '"') {
                        $buffer .= $next;
                        $index++;
                    } else {
                        $inDoubleQuote = false;
                    }
                }
                continue;
            }

            if ($inBacktickQuote) {
                $buffer .= $char;
                if ($char === '`') {
                    if ($next === '`') {
                        $buffer .= $next;
                        $index++;
                    } else {
                        $inBacktickQuote = false;
                    }
                }
                continue;
            }

            if ($char === "'") {
                $buffer .= $char;
                $inSingleQuote = true;
                continue;
            }

            if ($char === '"') {
                $buffer .= $char;
                $inDoubleQuote = true;
                continue;
            }

            if ($char === '`') {
                $buffer .= $char;
                $inBacktickQuote = true;
                continue;
            }

            if ($char === '(') {
                $depth++;
                $buffer .= $char;
                continue;
            }

            if ($char === ')') {
                $depth--;
                $buffer .= $char;
                continue;
            }

            if ($char === $separator && $depth === 0) {
                $parts[] = trim($buffer);
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $parts[] = trim($buffer);

        return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
    }

    /**
     * @return array{normalized: string, display: string}|null
     */
    private static function consumeQualifiedIdentifier(string $content, int &$offset): ?array
    {
        self::skipWhitespace($content, $offset);

        $segments = [];

        while (true) {
            $segment = self::consumeIdentifierSegment($content, $offset);
            if ($segment === null) {
                return null;
            }

            $segments[] = $segment['name'];
            self::skipWhitespace($content, $offset);

            if (($content[$offset] ?? '') !== '.') {
                break;
            }

            $offset++;
            self::skipWhitespace($content, $offset);
        }

        return [
            'normalized' => strtolower(implode('.', $segments)),
            'display' => implode('.', $segments),
        ];
    }

    /**
     * @return array{name: string}|null
     */
    private static function consumeIdentifierSegment(string $content, int &$offset): ?array
    {
        self::skipWhitespace($content, $offset);

        $char = $content[$offset] ?? null;
        if ($char === null) {
            return null;
        }

        if ($char === '"' || $char === '`') {
            $quote = $char;
            $offset++;
            $buffer = '';
            $length = strlen($content);

            while ($offset < $length) {
                $current = $content[$offset];
                $next = $content[$offset + 1] ?? null;

                if ($current === $quote) {
                    if ($next === $quote) {
                        $buffer .= $quote;
                        $offset += 2;
                        continue;
                    }

                    $offset++;

                    return ['name' => $buffer];
                }

                $buffer .= $current;
                $offset++;
            }

            return null;
        }

        if (preg_match('/\G[A-Za-z_][A-Za-z0-9_$]*/A', $content, $matches, 0, $offset) !== 1) {
            return null;
        }

        $offset += strlen($matches[0]);

        return ['name' => $matches[0]];
    }

    private static function skipWhitespace(string $content, int &$offset): void
    {
        $length = strlen($content);

        while ($offset < $length && ctype_space($content[$offset])) {
            $offset++;
        }
    }

    private static function isTableConstraint(string $definition): bool
    {
        return preg_match('/^(?:CONSTRAINT|PRIMARY|FOREIGN|UNIQUE|CHECK|KEY|INDEX|FULLTEXT|SPATIAL|EXCLUDE)\b/i', $definition) === 1;
    }

    private static function normalizeValueForMarkdown(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return '';
        }

        if ($trimmed[0] === "'" && str_ends_with($trimmed, "'")) {
            return str_replace("''", "'", substr($trimmed, 1, -1));
        }

        return $trimmed;
    }

    private static function escapeMarkdownCell(string $value): string
    {
        return str_replace('|', '\\|', $value);
    }

    private static function trimStatementTerminator(string $statement): string
    {
        return rtrim(preg_replace('/;\s*$/', '', $statement) ?? $statement);
    }
}