<?php
namespace axenox\BDT\Behat\TwigFormatter\Context;

use axenox\BDT\Behat\Common\BdtPaths;
use axenox\BDT\Behat\Common\ScreenshotAwareInterface;
use axenox\BDT\Behat\Common\ScreenshotProviderInterface;
use Behat\Behat\Context\SnippetAcceptingContext;
use Behat\Behat\Hook\Scope\AfterStepScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Behat\Hook\Scope\BeforeFeatureScope;
use Behat\MinkExtension\Context\MinkContext;
use Behat\Testwork\Tester\Result\TestResult;
use exface\Core\Exceptions\RuntimeException;

/**
 * Class BehatFormatterContext
 *
 * @package axenox\BDT\Behat\TwigFormatter\Context
 */
class BehatFormatterContext extends MinkContext implements SnippetAcceptingContext, ScreenshotAwareInterface
{
    private $currentScenario;
    protected static $currentSuite;

    // Nullable with a default: the setter is called by the context initializer, and a capture that
    // somehow runs before it must degrade instead of raising an uninitialized-property fatal inside an
    // AfterStep hook.
    private ?ScreenshotProviderInterface $provider = null;

    public function setScreenshotProvider(ScreenshotProviderInterface $provider) :void
    {
        $this->provider = $provider;
    }
    /**
     * @BeforeFeature
     *
     * @param BeforeFeatureScope $scope
     *
     */
    public static function setUpScreenshotSuiteEnvironment4ElkanBehatFormatter(BeforeFeatureScope $scope)
    {
        self::$currentSuite = $scope->getSuite()->getName();
    }

    /**
     * @BeforeScenario
     */
    public function setUpScreenshotScenarioEnvironmentElkanBehatFormatter(BeforeScenarioScope $scope)
    {
        $this->currentScenario = $scope->getScenario();
    }

    /**
     * Capture a screenshot when a step fails.
     *
     * MUST NEVER THROW: an uncaught exception from an AfterStep hook aborts the Behat process with
     * exit code 255, so a failed screenshot would destroy the whole feature run - and this hook fires
     * precisely on the failure path, i.e. exactly when the run is most worth finishing. A screenshot is
     * forensic evidence, never a test outcome, so its loss is recorded and the run goes on.
     *
     * @AfterStep
     * @param AfterStepScope $scope The Behat after-step scope
     * @return void
     */
    public function captureScreenshotOnFailure(AfterStepScope $scope): void
    {
        // only on failed steps
        if ($scope->getTestResult()->getResultCode() !== TestResult::FAILED) {
            return;
        }

        try {
            $this->captureScreenshot();
        } catch (\Throwable $e) {
            error_log('Screenshot capture failed for a failed step: ' . $e->getMessage());
        }
    }

    /**
     * Capture and store a screenshot with the current URL.
     *
     * Takes a screenshot of the current browser state and stores it along with the current URL.
     * Retries up to 3 times if the screenshot capture fails. The screenshot path and URL are stored in
     * the provider for later database logging.
     *
     * WHY A MISSING NAME MEANS "SKIP" AND NOT "INVENT ONE": the file name IS the run_step UID, written
     * by DatabaseFormatter when it opens the step row - that name is the only link between the image on
     * disk and the row that references it. The formatter leaves it unset exactly on the paths where no
     * step row exists at all (dry run, no open scenario, a failed step INSERT), so with no name there is
     * also no row for the screenshot to be attached to. Generating a substitute name would produce an
     * orphaned file that nothing in the UI can ever reach.
     * 
     *  THROWS ON FINAL FAILURE BY DESIGN: every current caller guards it - the AfterStep hook catches
     *  it, and UI5Browser::captureScreenshot() routes it into ErrorManager. That is deliberate, because
     *  a swallowed failure here would lose the only trace that evidence was not collected. Any NEW
     *  caller must keep that guard: an exception escaping into a Behat hook kills the process with exit
     *  code 255.
     *
     * @return void
     */
    public function captureScreenshot(): void
    {
        if ($this->provider === null) {
            error_log('Screenshot skipped: no screenshot provider was injected into the context.');
            return;
        }

        $fileNameBase = $this->provider->getName();
        if ($fileNameBase === null || $fileNameBase === '') {
            // Not an error worth raising: the step this screenshot would document was never recorded,
            // so there is nothing that could reference the image.
            error_log('Screenshot skipped: no step is currently recorded, so the image would have no owning row.');
            return;
        }

        // The daily sub-folder is intentional here (unlike the run logs): screenshots are not scoped
        // to a run, they accumulate for every scenario of every run, so the date is the only thing
        // that keeps the directory browsable and prunable.
        $relativePath = BdtPaths::relative(BdtPaths::FOLDER_SCREENSHOTS, date('Ymd'));
        $dir = BdtPaths::ensure(getcwd(), BdtPaths::FOLDER_SCREENSHOTS, date('Ymd'));
        // Checked rather than fired and forgotten: without the directory every attempt below fails
        // identically, so the retry loop turns one clear cause into three obscure ones plus four
        // seconds of sleep on the failure path.
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('Could not create the screenshot directory: ' . $dir);
        }

        $fileName = $fileNameBase . '.png';
        $maxAttempts = 3;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->saveScreenshot($fileName, $dir);
                $this->provider->setScreenshot($fileName, $relativePath);
                $this->provider->setUrl($this->getSession()->getCurrentUrl());
                return;
            } catch (\Throwable $e) {
                if ($attempt === $maxAttempts) {
                    error_log('Screenshot failed after ' . $maxAttempts . ' attempts: ' . $e->getMessage());
                    throw $e;
                }
                sleep(2);
            }
        }
    }
}