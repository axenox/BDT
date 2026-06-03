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
}