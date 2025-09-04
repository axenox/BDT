<?php

namespace axenox\BDT\Behat\Common;

class ScreenshotProvider implements ScreenshotProviderInterface
{
    private string $fileName;
    private string $filePath;
    private bool $isCaptured = false;

    public function setScreenshot(string $fileName, string $filePath): void
    {
        $this->fileName = $fileName;
        $this->filePath = $filePath;
        $this->isCaptured = true;
    }

    public function getName(): string
    {
        return $this->fileName;
    }

    public function setName(string $fileName): void
    {
        $this->fileName = $fileName;
    }

    public function getPath(): string
    {
        return $this->filePath;
    }

    public function isCaptured(): bool
    {
        return $this->isCaptured;
    }
}