<?php
namespace axenox\BDT\Behat\Common;

interface ScreenshotAwareInterface
{
    public function setScreenshotProvider(ScreenshotProviderInterface $provider): void;
}