<?php

namespace axenox\BDT\Behat\Common;

/**
 * Validates the content of a Gherkin .feature file before it is persisted.
 *
 * This validator catches structural and syntactic problems that would cause
 * Behat to abort at parse/bootstrap time — i.e. errors that prevent ANY test
 * from running, not just the one scenario that is broken.  These are the errors
 * that must block a save operation so the user cannot accidentally break the
 * entire nightly suite.
 *
 * Problems that are NOT fatal (e.g. undefined steps, missing scenario titles)
 * are intentionally ignored here; they produce Behat warnings at runtime but
 * do not prevent other features from executing.
 *
 * Usage:
 *   $result = FeatureFileValidator::validate($featureContent);
 *   if (! $result->isValid()) {
 *       // return $result->getErrors() to the UI
 *   }
 */
class FeatureFileValidator
{
    /** Gherkin keywords that must start every step line */
    private const STEP_KEYWORDS = ['Given', 'When', 'Then', 'And', 'But', '*'];

    /**
     * Validates raw feature file content and returns a result object.
     *
     * Runs all individual checks in sequence and collects every error found
     * so that the user sees all problems at once instead of one at a time.
     *
     * @param string $content Raw text content of the .feature file.
     * @return FeatureValidationResult
     */
    public static function validate(string $content): FeatureValidationResult
    {
        $errors = [];
        $lines  = explode("\n", str_replace("\r\n", "\n", $content));

        $errors = array_merge(
            $errors,
            self::checkFeatureKeyword($lines),
            self::checkTagSyntax($lines),
            self::checkStepKeywords($lines),
            self::checkExamplesTableConsistency($lines),
            self::checkExamplesInlineComments($lines),
            self::checkScenarioKeywords($lines),
            self::checkDocStringClosure($lines),
            self::checkDataTableAlignment($lines),
            self::checkDuplicateTags($lines)
        );

        return new FeatureValidationResult($errors);
    }

    /**
     * Ensures the file starts with a "Feature:" keyword.
     *
     * Behat requires every feature file to declare a Feature block.  Without
     * it the Gherkin parser throws a fatal parse error and the whole suite
     * cannot start.
     *
     * @param string[] $lines
     * @return string[]
     */
    private static function checkFeatureKeyword(array $lines): array
    {
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            // The very first non-empty, non-comment line must be "Feature:"
            if (! str_starts_with($trimmed, 'Feature:')) {
                return ['Line 1: Feature file must start with "Feature:" keyword (got: "' . mb_substr($trimmed, 0, 40) . '")'];
            }
            return [];
        }
        return ['File is empty or contains only comments — "Feature:" keyword is missing.'];
    }

    /**
     * Validates that all tag lines use correct Gherkin tag syntax.
     *
     * Tags must:
     *  - Start with "@"
     *  - Contain no spaces within the tag name (a space would be parsed as two
     *    separate tokens and confuse the tag filter)
     *  - Not be followed by non-tag content on the same line (except comments)
     *
     * @param string[] $lines
     * @return string[]
     */
    private static function checkTagSyntax(array $lines): array
    {
        $errors = [];
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || ! str_starts_with($trimmed, '@')) {
                continue;
            }

            // Strip trailing comment
            $withoutComment = preg_replace('/#.*$/', '', $trimmed);
            $tokens = preg_split('/\s+/', trim($withoutComment));

            foreach ($tokens as $token) {
                if ($token === '') {
                    continue;
                }
                if (! str_starts_with($token, '@')) {
                    $errors[] = 'Line ' . ($i + 1) . ': Non-tag token "' . $token . '" found on a tag line. '
                        . 'Each token on a tag line must start with "@".';
                    continue;
                }
                // Tag name after "@" must not contain whitespace (already split,
                // but guard against Unicode spaces)
                if (preg_match('/\s/', $token)) {
                    $errors[] = 'Line ' . ($i + 1) . ': Tag "' . $token . '" contains whitespace, which is not allowed.';
                }
                // Tags with values use "::" separator — validate no bare spaces around it
                if (str_contains($token, '::')) {
                    [$tagName, $tagValue] = explode('::', $token, 2);
                    if (trim($tagName) !== $tagName || trim($tagValue) !== $tagValue) {
                        $errors[] = 'Line ' . ($i + 1) . ': Tag "' . $token . '" has unexpected spaces around "::".';
                    }
                }
            }
        }
        return $errors;
    }

    /**
     * Checks that every step line begins with a recognised Gherkin step keyword.
     *
     * A step that starts with a random word (e.g. "I click the button") instead
     * of "When I click the button" causes a Gherkin parse error that aborts the
     * entire feature file.
     *
     * Lines inside Examples tables (pipe-delimited) and DocStrings (triple-quote
     * blocks) are excluded from this check.
     *
     * @param string[] $lines
     * @return string[]
     */
    private static function checkStepKeywords(array $lines): array
    {
        $errors       = [];
        $inDocString  = false;
        $inScenario   = false;
        $inExamples   = false;

        $scenarioKeywords = ['Scenario:', 'Scenario Outline:', 'Background:'];
        $blockKeywords    = ['Feature:', 'Background:', 'Scenario:', 'Scenario Outline:',
            'Examples:', 'Rule:'];

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);

            // Toggle DocString blocks (""")
            if (str_starts_with($trimmed, '"""')) {
                $inDocString = ! $inDocString;
                continue;
            }
            if ($inDocString) {
                continue;
            }

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            // Track whether we are inside a Scenario block
            foreach ($scenarioKeywords as $kw) {
                if (str_starts_with($trimmed, $kw)) {
                    $inScenario  = true;
                    $inExamples  = false;
                    continue 2;
                }
            }
            if (str_starts_with($trimmed, 'Examples:')) {
                $inExamples = true;
                continue;
            }
            // Skip table rows and block-level keywords
            if (str_starts_with($trimmed, '|') || str_starts_with($trimmed, '@')) {
                continue;
            }
            // Skip any other block keyword
            $isBlockKeyword = false;
            foreach ($blockKeywords as $kw) {
                if (str_starts_with($trimmed, $kw)) {
                    $isBlockKeyword = true;
                    break;
                }
            }
            if ($isBlockKeyword) {
                $inScenario = false;
                $inExamples = false;
                continue;
            }

            if (! $inScenario || $inExamples) {
                continue;
            }

            // This line should be a step — verify the keyword
            $startsWithKeyword = false;
            foreach (self::STEP_KEYWORDS as $kw) {
                if (str_starts_with($trimmed, $kw . ' ') || $trimmed === $kw) {
                    $startsWithKeyword = true;
                    break;
                }
            }
            if (! $startsWithKeyword) {
                $errors[] = 'Line ' . ($i + 1) . ': Step line does not start with a Gherkin keyword '
                    . '(Given/When/Then/And/But). Got: "' . mb_substr($trimmed, 0, 60) . '"';
            }
        }
        return $errors;
    }

    /**
     * Verifies that every Examples table has the same number of columns in
     * the header row and all data rows.
     *
     * A column count mismatch causes a fatal Gherkin parse error; Behat cannot
     * build the outline examples and refuses to run the entire feature file.
     *
     * @param string[] $lines
     * @return string[]
     */
    private static function checkExamplesTableConsistency(array $lines): array
    {
        $errors        = [];
        $inExamples    = false;
        $headerCols    = null;
        $headerLineNo  = null;

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, 'Examples:')) {
                $inExamples   = true;
                $headerCols   = null;
                $headerLineNo = null;
                continue;
            }

            // Any non-table line ends the Examples block
            if ($inExamples && ! str_starts_with($trimmed, '|') && $trimmed !== '') {
                $inExamples  = false;
                $headerCols  = null;
                continue;
            }

            if ($inExamples && str_starts_with($trimmed, '|')) {
                $cols = self::countTableColumns($trimmed);
                if ($headerCols === null) {
                    $headerCols   = $cols;
                    $headerLineNo = $i + 1;
                } else {
                    if ($cols !== $headerCols) {
                        $errors[] = 'Line ' . ($i + 1) . ': Examples table row has ' . $cols
                            . ' column(s) but the header on line ' . $headerLineNo
                            . ' has ' . $headerCols . ' column(s).';
                    }
                }
            }
        }
        return $errors;
    }

    /**
     * Detects inline comments at the end of Examples table rows.
     *
     * Gherkin treats "#" inside a table cell as literal text in some parser
     * versions but as a comment delimiter in others.  To avoid ambiguity and
     * silent data corruption the BDT convention forbids inline comments inside
     * table rows.
     *
     * @param string[] $lines
     * @return string[]
     */
    private static function checkExamplesInlineComments(array $lines): array
    {
        $errors     = [];
        $inExamples = false;

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, 'Examples:')) {
                $inExamples = true;
                continue;
            }
            if ($inExamples && ! str_starts_with($trimmed, '|') && $trimmed !== '') {
                $inExamples = false;
            }
            if ($inExamples && str_starts_with($trimmed, '|')) {
                // Find "#" that is not inside a cell value enclosed by pipes
                // Simple heuristic: "#" after the last "|"
                $afterLastPipe = substr($trimmed, strrpos($trimmed, '|') + 1);
                if (str_contains($afterLastPipe, '#')) {
                    $errors[] = 'Line ' . ($i + 1) . ': Inline comment "#" after the closing pipe in an '
                        . 'Examples table row is not allowed.';
                }
                // "#" anywhere in the row outside a cell (before first pipe or between pipes with no value)
                if (preg_match('/\|\s*#/', $trimmed)) {
                    $errors[] = 'Line ' . ($i + 1) . ': Inline comment "#" inside an Examples table cell '
                        . 'is ambiguous and not allowed.';
                }
            }
        }
        return $errors;
    }

    /**
     * Checks that Scenario and Background blocks use the correct Gherkin keywords.
     *
     * Common typos like "Scenarios:" or "scenario:" (lower-case) cause parse
     * failures because Gherkin keywords are case-sensitive.
     *
     * @param string[] $lines
     * @return string[]
     */
    private static function checkScenarioKeywords(array $lines): array
    {
        $errors = [];
        $validKeywords = [
            'Feature:',
            'Background:',
            'Scenario:',
            'Scenario Outline:',
            'Examples:',
            'Rule:',
        ];

        // Suspicious patterns that look like mistyped keywords
        $suspiciousPattern = '/^(scenarios?:|scenario outline|backgrounds?:|examples|rules?:)\s/i';

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '@')
                || str_starts_with($trimmed, '|') || str_starts_with($trimmed, '"')) {
                continue;
            }
            if (preg_match($suspiciousPattern, $trimmed)) {
                // Check if it matches a valid keyword exactly
                $isValid = false;
                foreach ($validKeywords as $kw) {
                    if (str_starts_with($trimmed, $kw)) {
                        $isValid = true;
                        break;
                    }
                }
                if (! $isValid) {
                    $errors[] = 'Line ' . ($i + 1) . ': "' . mb_substr($trimmed, 0, 40)
                        . '" looks like a misspelled Gherkin keyword. '
                        . 'Valid block keywords: ' . implode(', ', $validKeywords);
                }
            }
        }
        return $errors;
    }

    /**
     * Ensures every DocString opening triple-quote has a matching closing triple-quote.
     *
     * An unclosed DocString causes the Gherkin parser to consume the rest of the
     * file as string content, which produces confusing parse errors on every
     * subsequent line.
     *
     * @param string[] $lines
     * @return string[]
     */
    private static function checkDocStringClosure(array $lines): array
    {
        $openLine    = null;
        $inDocString = false;

        foreach ($lines as $i => $line) {
            if (str_starts_with(trim($line), '"""')) {
                if ($inDocString) {
                    $inDocString = false;
                    $openLine    = null;
                } else {
                    $inDocString = true;
                    $openLine    = $i + 1;
                }
            }
        }

        if ($inDocString) {
            return ['Line ' . $openLine . ': DocString opened with """ but never closed.'];
        }
        return [];
    }

    /**
     * Checks that all pipe-delimited table rows within a single step argument
     * have a consistent column count.
     *
     * Behat's Gherkin parser accepts ragged tables without crashing, but they
     * always indicate a formatting mistake that will cause wrong data to be
     * passed to the step definition.  Because a column-count mismatch in a
     * step DataTable is easy to overlook and hard to debug at runtime, this
     * check treats it as a blocking error.
     *
     * @param string[] $lines
     * @return string[]
     */
    private static function checkDataTableAlignment(array $lines): array
    {
        $errors          = [];
        $inDocString     = false;
        $tableStartLine  = null;
        $tableHeaderCols = null;

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '"""')) {
                $inDocString = ! $inDocString;
                continue;
            }
            if ($inDocString) {
                continue;
            }

            if (str_starts_with($trimmed, '|')) {
                $cols = self::countTableColumns($trimmed);
                if ($tableHeaderCols === null) {
                    $tableHeaderCols = $cols;
                    $tableStartLine  = $i + 1;
                } elseif ($cols !== $tableHeaderCols) {
                    $errors[] = 'Line ' . ($i + 1) . ': DataTable row has ' . $cols
                        . ' column(s) but the table starting at line ' . $tableStartLine
                        . ' has ' . $tableHeaderCols . ' column(s).';
                }
            } else {
                // Table ended
                $tableHeaderCols = null;
                $tableStartLine  = null;
            }
        }
        return $errors;
    }

    /**
     * Detects duplicate tags within the same tag line or across tag lines
     * belonging to the same scenario/feature block.
     *
     * Duplicate tags do not cause a parse error, but they indicate a copy-paste
     * mistake and can cause unexpected tag-filter behaviour (e.g. a scenario
     * matching a filter it should not match because an old tag was not removed).
     *
     * @param string[] $lines
     * @return string[]
     */
    private static function checkDuplicateTags(array $lines): array
    {
        $errors         = [];
        $currentTags    = [];
        $blockStartLine = null;

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);

            // A Scenario/Feature/Background line closes the tag collection
            if (preg_match('/^(Feature|Background|Scenario Outline|Scenario|Rule):/i', $trimmed)) {
                $currentTags    = [];
                $blockStartLine = $i + 1;
                continue;
            }

            if (! str_starts_with($trimmed, '@')) {
                if (! str_starts_with($trimmed, '#') && $trimmed !== '') {
                    // Non-tag, non-comment line outside a keyword — reset
                    if ($currentTags !== []) {
                        $currentTags = [];
                    }
                }
                continue;
            }

            $withoutComment = trim(preg_replace('/#.*$/', '', $trimmed));
            $tokens = preg_split('/\s+/', $withoutComment);
            foreach ($tokens as $token) {
                if ($token === '') {
                    continue;
                }
                if (in_array($token, $currentTags, true)) {
                    $errors[] = 'Line ' . ($i + 1) . ': Duplicate tag "' . $token . '" '
                        . ($blockStartLine ? '(block starting at line ' . $blockStartLine . ')' : '') . '.';
                } else {
                    $currentTags[] = $token;
                }
            }
        }
        return $errors;
    }

    /**
     * Counts the number of columns in a Gherkin pipe-delimited table row.
     *
     * Strips the leading and trailing pipe characters before splitting so that
     * "| col1 | col2 |" correctly yields 2 rather than 3.
     *
     * @param string $row A single trimmed table row, e.g. "| foo | bar |".
     * @return int
     */
    private static function countTableColumns(string $row): int
    {
        // Remove surrounding pipes, then count remaining pipe-separated cells
        $inner = trim($row, '|');
        $cells = explode('|', $inner);
        // Filter out empty strings produced by trailing separators
        return count(array_filter($cells, fn($c) => trim($c) !== ''));
    }
}