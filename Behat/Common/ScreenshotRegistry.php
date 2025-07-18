<?php
namespace axenox\BDT\Behat\Common;

class ScreenshotRegistry
{
    private static $screenshotName;
    private static $screenshotPath;
    private static $screenshotFolder;

    public static function setScreenshotName(string $value): void
    {
        self::$screenshotName = $value;
    }

    public static function getScreenshotName(): ?string
    {
        return self::$screenshotName;
    }
    
    public static function setScreenshotPath(string $value): void
    {
        self::$screenshotPath = $value;
    }

    /**
     * screenshot relative path
     * E.g: data/axenox/BDT/Screenshots
     * @return string|null
     */
    public static function getScreenshotPath(): ?string
    {
        return self::$screenshotPath;
    }

    public static function setScreenshotFolder(string $value): void
    {
        self::$screenshotFolder = $value;
    }

    public static function getScreenshotFolder(): ?string
    {
        return self::$screenshotFolder;
    }
    
}