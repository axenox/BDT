<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\Actions;

use axenox\BDT\Actions\RunParallel;
use axenox\BDT\Behat\BrowserManagers\LocalChromeManager;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the coordinator banner aligned with the actual scheduled lane range.
 */
class RunParallelBannerTest extends TestCase
{
    /**
     * The banner must use the resolved override band instead of the manager's default driver port.
     */
    public function testBannerShowsResolvedLaneRangeWithoutCoordinatorPort(): void
    {
        $manager = new LocalChromeManager($this->createStub(WorkbenchInterface::class));
        $manager->importUxonObject(new UxonObject(['executable' => 'chrome.exe', 'headless' => true, 'port' => 9999]));
        $action = (new \ReflectionClass(RunParallel::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(RunParallel::class, 'coordinatorManager');
        $property->setAccessible(true);
        $property->setValue($action, $manager);
        $method = new \ReflectionMethod(RunParallel::class, 'buildStartupBanner');
        $method->setAccessible(true);

        $banner = $method->invoke($action, 1, 2, 1, [9401, 9410]);

        $this->assertStringContainsString('Chrome:   local Chrome, headless, lanes on ports 9401-9410', $banner);
        $this->assertStringNotContainsString('port 9222', $banner);
        $this->assertStringNotContainsString('port 9999', $banner);
    }
}
