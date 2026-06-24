<?php

namespace axenox\BDT\Behat\Common;

final class ExpectedTestCountResult
{
    /**
     * @param int $featureCount   One per feature that survives tag filtering.
     * @param int $scenarioCount  Scenarios + outlines (outline = 1) that survive tag filtering.
     * @param string[] $scannedFiles   Absolute paths actually parsed.
     * @param array<string,string> $errors   Map of filename => parser error message.
     * @param string[] $matchedFiles   Absolute paths of features that SURVIVED tag filtering,
     *                                  i.e. the files Behat will actually run. This is what the
     *                                  parallel coordinator splits across workers; scannedFiles
     *                                  may include files dropped by the tag filter.
     */
    public function __construct(
        public int $featureCount,
        public int $scenarioCount,
        public array $scannedFiles,
        public array $errors,
        public array $matchedFiles = []
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}