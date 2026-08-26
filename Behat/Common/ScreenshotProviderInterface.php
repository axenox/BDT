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
     * This is the BASE name the next capture should use - the UID of the step row that would own the
     * image. Consumers that need the file that was actually written must use getFileName().
     *
     * @return string|null The screenshot name, or null if not set
     */
    public function getName(): ?string;

    /**
     * Get the name of the file that was actually written by the last capture.
     *
     * Kept separate from getName(): that one returns the base name a capture should use, this one the
     * resulting file name including its extension. Consumers that build a stored path must use this
     * method - using the base name produces a path to a file that does not exist.
     *
     * @return string|null The written file name, or null if nothing was captured
     */
    public function getFileName(): ?string;

    /**
     * Get the relative path to the screenshot file.
     *
     * @return string|null The relative path to the screenshot, or null if not set
     */
    public function getPath(): ?string;

    /**
     * Check if a screenshot has been captured.
     *
     * Answers "was anything captured at all" and therefore cannot tell WHICH row the picture belongs
     * to. Consumers that write the path onto a specific row must use isCapturedFor().
     *
     * @return bool True if a screenshot was captured, false otherwise
     */
    public function isCaptured(): bool;

    /**
     * Check whether the last captured screenshot belongs to the given step.
     *
     * Needed because the picture is taken while the failing row is still open, but the row is closed
     * several calls later - and failure cleanup, nested substeps or back navigation move the provider
     * on to another row in between. Comparing the owner is the only way to attach the image to the
     * row it actually shows.
     *
     * @param string|null $stepName The name/identifier the row was registered under
     * @return bool True if a screenshot was captured for exactly that step
     */
    public function isCapturedFor(?string $stepName): bool;

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

    /**
     * Forget the last captured screenshot, keeping the step the provider currently points at.
     *
     * Needed so a picture taken for one step can never be reported for the next one.
     *
     * @return void
     */
    public function reset(): void;
}