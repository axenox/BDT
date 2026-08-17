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
    // Nullable with an explicit default because the interface documents both getters as "or null if
    // not set", and an uninitialized typed property cannot express that - it throws instead, turning a
    // perfectly normal "nothing captured yet" state into a fatal error. The name is a step UID written
    // by the formatter when a step row is opened, so "not set" is a real and expected state on every
    // path where no step row exists (dry run, no open scenario, a failed step INSERT).
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
        $this->isCaptured = true;
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): ?string
    {
        return $this->fileName;
    }

    /**
     * {@inheritDoc}
     */
    public function setName(string $fileName): void
    {
        $this->fileName = $fileName;
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
}