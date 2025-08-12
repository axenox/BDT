<?php

namespace axenox\BDT\Behat\Common;

class ScreenshotProvider implements ScreenshotProviderInterface
{
    private string $fileName;
    private string $filePath;

    public function setScreenshot(string $fileName, string $filePath): void
    {
        $this->fileName = $fileName;
        $this->filePath = $filePath;
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
}