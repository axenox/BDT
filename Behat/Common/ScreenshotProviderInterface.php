<?php

namespace axenox\BDT\Behat\Common;

/**
 * Interface for providing screenshot and URL information during test execution.
 * 
 * This interface allows storing and retrieving information about screenshots taken
 * during test execution, including the file path and the URL where the screenshot
 * was captured.
 */
interface ScreenshotProviderInterface
{
    /**
     * Store screenshot file information.
     *
     * @param string $fileName The name of the screenshot file
     * @param string $filePath The relative path to the screenshot file
     * @return void
     */
    public function setScreenshot(string $fileName, string $filePath): void;

    /**
     * Set the name/identifier for the current screenshot.
     *
     * @param string $fileName The name/identifier to use for the screenshot
     * @return void
     */
    public function setName(string $fileName): void;

    /**
     * Get the name/identifier of the current screenshot.
     *
     * @return string|null The screenshot name, or null if not set
     */
    public function getName(): ?string;

    /**
     * Get the relative path to the screenshot file.
     *
     * @return string|null The relative path to the screenshot, or null if not set
     */
    public function getPath(): ?string;

    /**
     * Check if a screenshot has been captured.
     *
     * @return bool True if a screenshot was captured, false otherwise
     */
    public function isCaptured(): bool;

    /**
     * Store the URL where the screenshot was captured.
     *
     * @param string $url The URL of the page where the screenshot was taken
     * @return void
     */
    public function setUrl(string $url): void;

    /**
     * Get the URL where the screenshot was captured.
     *
     * @return string|null The URL where the screenshot was captured, or null if not set
     */
    public function getUrl(): ?string;

    /**
     * Store the UID of the run the current screenshot belongs to.
     *
     * Needed so screenshots can be grouped into one folder per run (Screenshots/<run_uid>/), which
     * keeps a run's shots together and lets cleanup drop a whole run's folder at once.
     *
     * @param string $runUid The UID of the current test run
     * @return void
     */
    public function setRunUid(string $runUid): void;

    /**
     * Get the UID of the run the current screenshot belongs to.
     *
     * @return string|null The run UID, or null if not set
     */
    public function getRunUid(): ?string;
}