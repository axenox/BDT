<?php

namespace axenox\BDT\Behat\Common;

interface ScreenshotProviderInterface
{
    public function setScreenshot(string $fileName, string $filePath): void;
    public function setName(string $fileName): void;
    public function getName(): ?string;
    public function getPath(): ?string;
}