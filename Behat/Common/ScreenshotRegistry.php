<?php
namespace axenox\BDT\Behat\Common;

class ScreenshotRegistry
{
    private static $screenshotName;
    private static $screenshotPath;

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

    public static function getScreenshotPath(): ?string
    {
        return self::$screenshotPath;
    }
    
}