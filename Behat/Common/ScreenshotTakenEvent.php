<?php
namespace axenox\BDT\Behat\Common;

use Behat\Testwork\Event\Event;

class ScreenshotTakenEvent extends Event
{
    public const AFTER = 'screenshot_taken.after';

    private string $fileName;
    private string $filePath;

    public function __construct(string $fileName, string $filePath)
    {
        $this->fileName = $fileName;
        $this->filePath = $filePath;
    }
    
    public function getName(): string
    {
        return self::AFTER;
    }
    
    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }
}