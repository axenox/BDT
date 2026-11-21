<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\Common;

use axenox\BDT\BDTApp;
use axenox\BDT\Behat\Common\BdtConfig;
use axenox\BDT\Behat\Common\LaneNaming;
use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the test-user naming contract: concurrent RunTests need their own user, and the cleanup must
 * recognise exactly the users the runs create.
 *
 * WHY: lane_id decides which test user a run logs in with. Two concurrent runs on one user row collide on
 * optimistic locking, and a username the cleanup does not recognise is never removed.
 */
class TestUsernameSlotTest extends TestCase
{
    private function suffix(string $laneId): string
    {
        return LaneNaming::slotToken($laneId);
    }

    /**
     * A real, unstarted workbench whose test-user name is set in memory on the BDT app config, so the
     * assertions do not depend on the TEST_USER.USERNAME of the machine running them.
     */
    private function workbench(): WorkbenchInterface
    {
        $workbench = new Workbench([
            'SERVER.INSTALLATION_NAME' => 'bdt-test-username-unit-test',
            'DEBUG.LIVE_CLASS_AUTOLOADER' => false
        ]);
        $workbench->getApp(BDTApp::APP_ALIAS)->getConfig()->setOption(BdtConfig::TEST_USER_USERNAME, 'bdt_user');
        return $workbench;
    }

    /**
     * Fleet lanes and interactive runs both keep only their stable slot token.
     */
    public function testSuffixIsTheSlotTokenOnly(): void
    {
        $this->assertSame('_lane3', $this->suffix('0x1234_lane3'));
        $this->assertSame('_interactive9401', $this->suffix('0x1234_interactive9401'));
    }

    /**
     * Any other format is a broken lane_id contract and must fail loudly.
     */
    #[DataProvider('badLaneIds')]
    public function testOtherFormatsThrow(string $laneId): void
    {
        $this->expectException(RuntimeException::class);
        $this->suffix($laneId);
    }

    public static function badLaneIds(): array
    {
        return [
            'no token' => ['0x1234'],
            'interactive without port' => ['0x1234_interactive'],
            'lane without number' => ['0x1234_lane'],
            'token not at the end' => ['0x1234_interactive9401_x']
        ];
    }

    /**
     * The cleanup decides from this answer which users may be deleted.
     */
    public function testInteractiveUsersAreRecognisedAsTestUsers(): void
    {
        $workbench = $this->workbench();

        $this->assertTrue(UI5Browser::isTestUsername($workbench, 'bdt_user'));
        $this->assertTrue(UI5Browser::isTestUsername($workbench, 'bdt_user_lane2'));
        $this->assertTrue(UI5Browser::isTestUsername($workbench, 'bdt_user_interactive9401'));
        $this->assertFalse(UI5Browser::isTestUsername($workbench, 'bdt_user_interactive'));
        $this->assertFalse(UI5Browser::isTestUsername($workbench, 'bdt_user_interactive9401_x'));
        $this->assertFalse(UI5Browser::isTestUsername($workbench, 'bdt_username'));
    }
}
