<?php

namespace axenox\bdt\Behat\Common;

final class ExpectedTestCountResult
{
    /**
     * @param int $featureCount   One per feature that survives tag filtering.
     * @param int $scenarioCount  Scenarios + outlines (outline = 1) that survive tag filtering.
     *                            Matches the number of run_scenario rows DatabaseFormatter writes.
     * @param string[] $scannedFiles            Absolute paths actually parsed.
     * @param array<string,string> $errors      Map of filename => parser error message.
     */
    public function __construct(
        public int $featureCount,
        public int $scenarioCount,
        public array $scannedFiles,
        public array $errors
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}