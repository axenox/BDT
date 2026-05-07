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
    private string $fileName;
    private string $filePath;
    private bool $isCaptured = false;
    private ?string $url = null;

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
    public function getName(): string
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
    public function getPath(): string
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
}