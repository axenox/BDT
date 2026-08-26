<?php

namespace axenox\BDT\Behat\Common;

/**
 * Stores screenshot and URL information captured during test execution.
 * 
 * This provider maintains information about screenshots taken during Behat tests,
 * including the file path and the URL of the page where the screenshot was captured.
 * This information is used by the DatabaseFormatter to log test results.
 * 
 * @author Andrej Kabachnik
 */
class ScreenshotProvider implements ScreenshotProviderInterface
{
    // Three names, because they answer three different questions and overwriting one with another is
    // what silently broke the report: $stepName is the UID of the row a screenshot would belong to
    // and is the BASE for the next file, $capturedStepName is the row the LAST capture actually
    // belongs to, and $fileName is the name of the file that was written.
    //
    // WHY the captured row is remembered instead of a bare "something was captured" flag: the picture
    // is taken while the failing row is still open, but the row is only closed several calls later -
    // and anything in between (failure cleanup, a nested substep, back navigation) moves the provider
    // on to another row. A flag cannot tell "captured for me" from "captured for someone else", so it
    // either dropped a screenshot that existed or attached one that belonged elsewhere.
    //
    // Nullable with an explicit default because the interface documents the getters as "or null if
    // not set", and an uninitialized typed property cannot express that - it throws instead, turning a
    // perfectly normal "nothing captured yet" state into a fatal error. The step name is written by
    // the formatter when a step row is opened, so "not set" is a real and expected state on every
    // path where no step row exists (dry run, no open scenario, a failed step INSERT).
    private ?string $stepName = null;
    private ?string $capturedStepName = null;
    private ?string $fileName = null;
    private ?string $filePath = null;
    private bool $isCaptured = false;
    private ?string $url = null;
    private ?string $runUid = null;

    /**
     * {@inheritDoc}
     */
    public function setScreenshot(string $fileName, string $filePath): void
    {
        $this->fileName = $fileName;
        $this->filePath = $filePath;
        // Nail the picture to the row that was open when it was taken - see $capturedStepName.
        $this->capturedStepName = $this->stepName;
        $this->isCaptured = true;
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): ?string
    {
        return $this->stepName;
    }

    /**
     * {@inheritDoc}
     */
    public function setName(string $fileName): void
    {
        // Deliberately NO reset here: this is called whenever a row is opened OR restored, and a
        // capture regularly happens BEFORE the row it belongs to is closed. Dropping the capture
        // state on every name change therefore threw away pictures that had already been written.
        // Ownership is decided by isCapturedFor() instead.
        $this->stepName = $fileName;
    }

    /**
     * {@inheritDoc}
     */
    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    /**
     * {@inheritDoc}
     */
    public function getPath(): ?string
    {
        return $this->filePath;
    }

    /**
     * {@inheritDoc}
     */
    public function isCaptured(): bool
    {
        return $this->isCaptured;
    }

    /**
     * {@inheritDoc}
     */
    public function isCapturedFor(?string $stepName): bool
    {
        if (! $this->isCaptured) {
            return false;
        }
        // An unnamed row cannot own a picture: the file name IS the row UID, so without one there is
        // no way to tell whether the image on disk belongs to this row or to a neighbouring one.
        if ($stepName === null || $stepName === '') {
            return false;
        }
        return $this->capturedStepName === $stepName;
    }

    /**
     * {@inheritDoc}
     */
    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    /**
     * {@inheritDoc}
     */
    public function getUrl(): ?string
    {
        return $this->url;
    }

    /**
     * {@inheritDoc}
     */
    public function setRunUid(string $runUid): void
    {
        $this->runUid = $runUid;
    }

    /**
     * {@inheritDoc}
     */
    public function getRunUid(): ?string
    {
        return $this->runUid;
    }

    /**
     * Forgets any previously captured screenshot, but keeps the step the provider points at.
     *
     * WHY it is still needed although ownership is tracked: consumers that have no row UID at hand
     * (the Twig report) can only ask the plain isCaptured(), so the state must be dropped at a step
     * boundary. Otherwise a picture from an earlier step would be shown for a later one.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->isCaptured = false;
        $this->capturedStepName = null;
        $this->fileName = null;
        $this->filePath = null;
    }
}