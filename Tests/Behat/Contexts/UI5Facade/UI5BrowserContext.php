<?php
namespace axenox\BDT\Tests\Behat\Contexts\UI5Facade;

use axenox\BDT\Behat\Contexts\UI5Facade\ChromeManager;
use axenox\BDT\Behat\DatabaseFormatter\DatabaseFormatter;
use axenox\BDT\Behat\Events\AfterPageVisited;
use axenox\BDT\Behat\TwigFormatter\Context\BehatFormatterContext;
use axenox\BDT\Common\Installer\TestDataInstaller;
use axenox\BDT\Exceptions\BrowserDriverException;
use Behat\Behat\Context\Context;
use Behat\Behat\Tester\Result\UndefinedStepResult;
use Behat\Mink\Element\NodeElement;
use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use exface\Core\CommonLogic\Debugger\LogBooks\MarkdownLogBook;
use exface\Core\CommonLogic\Model\Expression;
use exface\Core\CommonLogic\Security\AuthenticationToken\CliEnvAuthToken;
use exface\Core\CommonLogic\Selectors\AppSelector;
use exface\Core\CommonLogic\Workbench;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Facades\ConsoleFacade;
use exface\Core\Factories\FormulaFactory;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\Assert;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Behat\Hook\Scope\AfterStepScope;
use Behat\Behat\Hook\Scope\BeforeStepScope;
use Behat\Gherkin\Node\TableNode;
use axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5DataTableNode;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use axenox\BDT\Behat\Common\Traits\CdpConnectionDetectorTrait;
use axenox\BDT\Behat\Common\Traits\AuthenticatorTimeStampingTrait;


/**
 * Test steps available for the OpenUI5 facade
 *
 * UI5BrowserContext class provides test steps for OpenUI5 facade testing
 * Each scenario gets its own context instance
 *
 * Every scenario gets its own context instance.
 * You can also pass arbitrary arguments to the
 * context constructor through behat.yml.
 *
 */
class UI5BrowserContext extends BehatFormatterContext implements Context
{
    use CdpConnectionDetectorTrait;
    use AuthenticatorTimeStampingTrait;
    
    private $browser;
    private $scenarioName;

    private $workbench = null;
    private $debug = false;
    private string $locale = 'de_DE';
    private static bool $isDryRun = false;
    private ?string $lastLoginUrl = null;
    private ?string $lastLoginLocale = null;
    /** @var array|null Browser-side login form fields (caption => value) computed during the first login and replayed verbatim by recoverChrome() without touching the DB */
    private ?array $lastLoginFields = null;
    /** @var string|null Caption of the authenticator tab to open on the login form; cached for recovery replay */
    private ?string $lastLoginTabCaption = null;
    /** @var string|null Caption of the login submit button; cached for recovery replay */
    private ?string $lastLoginButtonCaption = null;
    private static ?string $currentFeatureTitle = null;
    private ?string $lastPageAlias = null;
    /**
     * @var array|null Roles used by the most recent iLogInToPage() call.
     *
     * Cached because browserLogin() requires them and recoverChrome() must be able to replay the
     * exact same browser-side login on a freshly started Chrome. Without this the recovery path
     * cannot even call browserLogin() with a complete argument list.
     */
    private ?array $lastLoginUserRoles = null;

    /**
     * Initializes and starts the workbench for the test environment.
     *
     * WHY $monitorEnabled defaults to true: this is the ONE workbench the UI5 steps actually run
     * against, so it is where the ExFace Monitor (exf_monitor_action / exf_monitor_error writes) is
     * effectively gated for a run. Manual/interactive runs keep monitoring ON, matching normal app
     * behaviour. Parallel lane workers force it OFF via the BDT_MONITOR_ENABLED env var (see
     * resolveMonitorEnabled) to keep their high-volume, concurrent action/exception stream out of the
     * shared app DB - critical while the PRIMARY filegroup is under storage pressure.
     *
     * @param bool $debug          Echo debug lines to stdout (unchanged).
     * @param bool $monitorEnabled Default monitor state; overridden by BDT_MONITOR_ENABLED when set.
     */
    public function __construct(bool $debug = false, bool $monitorEnabled = true)
    {
        self::$isDryRun = in_array('--dry-run', $_SERVER['argv'] ?? [], true);
        if (self::$isDryRun) {
            return;
        }
        $this->workbench = new Workbench(['MONITOR.ENABLED' => $this->resolveMonitorEnabled($monitorEnabled)]);
        $this->workbench->start();
        // Authenticated with the default CLI user if called from CLI. The authenticated
        // user will change with Browser::setupUser() later, but for now the CLI user is
        // better than no user at all!
        if (ConsoleFacade::isPhpScriptRunInCli()) {
            $token = new CliEnvAuthToken();
            // WHY THE GUARD: a fresh context instance - and therefore this authenticate() - runs for
            // EVERY scenario, and all parallel lanes run as the same OS user, so this call re-writes
            // the one shared USER_AUTHENTICATOR row throughout the whole run. The guard applied at
            // formatter boot protects only the formatter's OWN workbench; this is a second,
            // independent workbench instance, so without its own guard two lanes starting scenarios
            // at the same instant race on the row's optimistic lock and one dies with a
            // "changed in the meantime" conflict. Disabling the check in THIS process is safe:
            // last_authenticated_on is a last-writer-wins timestamp (see the trait's docblock).
            self::withoutAuthenticatorTimeStamping(
                $this->workbench,
                fn() => $this->workbench->getSecurity()->authenticate($token)
            );
        }
        $this->debug = $debug;
    }

    /**
     * Resolves the effective monitor state, letting the parallel launcher force it off per worker.
     *
     * WHY an env override instead of a behat.yml context arg: the auto-generated lane config only
     * imports the base behat.yml and is suite-agnostic, so forcing the flag off there would mean
     * redefining every suite's contexts block - fragile and easy to drift. BDT_MONITOR_ENABLED is set
     * once in the coordinator's WORKER_ENV, so every lane inherits "off" with no per-suite plumbing,
     * while a manual run (which sets no such var) keeps the constructor default. The env value wins
     * over $default on purpose: it is the launcher's explicit, run-scoped decision.
     *
     * @param bool $default The value to use when BDT_MONITOR_ENABLED is not set.
     */
    private function resolveMonitorEnabled(bool $default): bool
    {
        $env = getenv('BDT_MONITOR_ENABLED');
        if ($env === false || $env === '') {
            return $default;
        }
        return ! in_array(strtolower($env), ['0', 'false', 'off', 'no'], true);
    }

    private function logDebug(string $message): void
    {
        if ($this->debug) {
            echo $message . PHP_EOL; // If debug mode is true, it writes the messages
        }
    }

    /**
     * Dynamically determines workbench root path
     * Traverses up from current directory until finding vendor directory
     * @return string Path to workbench root
     */
    private function getWorkbenchPath(): string
    {
        return $this->getWorkbench()->getInstallationPath();
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $value): void
    {
        $this->locale = $value;
    }

    /**
     * Logs failed steps to the workbench log and attempts Chrome recovery if the
     * failure was caused by a lost CDP connection.
     *
     * Must never throw — any uncaught exception from an AfterStep hook causes Behat
     * to exit with code 255, killing the entire test run.
     *
     * @AfterStep
     */
    public function logFailedStep(AfterStepScope $scope): void
    {
        $result = $scope->getTestResult();

        if ($result->isPassed()) {
            return;
        }

        try {
            $exception = null;
            if (method_exists($result, 'getException')) {
                $exception = $result->getException();
            }

            if ($exception !== null) {
                $wrappedException = new RuntimeException(
                    $exception->getMessage(),
                    null,
                    $exception
                );
            } elseif ($result instanceof UndefinedStepResult) {
                $wrappedException = new RuntimeException('Step is not defined: ' . $scope->getStep()->getText());
            } else {
                $wrappedException = new RuntimeException('Step failed without exception details');
            }

            $this->getWorkbench()->getLogger()->logException($wrappedException);
            ErrorManager::getInstance()->setLastLogId($wrappedException->getId());

            // Only populate exception details when an actual exception is available —
            // UndefinedStepResult and bare failures carry no exception object.
            if ($exception !== null) {
                ErrorManager::getInstance()->addError([
                    'type'    => 'BehatException',
                    'message' => $exception->getMessage(),
                    'status'  => $exception->getCode(),
                    'stack'   => $exception->getTraceAsString(),
                ], 'AfterStep');
            }

            echo "LogID: " . $wrappedException->getId() . "\n";
            // Display LogID for debugging purposes 
            $this->logDebug("LogID: " . $wrappedException->getId() . "\n");

            // If the step failed due to a lost CDP connection, attempt to recover
            // Chrome so the next step in this scenario can continue on a live browser.
            // The step itself is already recorded as failed — recovery only affects
            // what comes after it.
            if ($exception !== null && $this->isCdpConnectionError($exception)) {
                $this->recoverChromeAfterStepFailure();
            }
        } catch (\Throwable $e) {
            // Logging itself failed (e.g. DB unreachable). Swallow so Behat can continue.
            $this->logDebug('logFailedStep internal error: ' . $e->getMessage());
        }
    }

    /**
     * Attempts to recover Chrome after a CDP connection failure detected in @AfterStep.
     *
     * Reads the current URL from the session (which may itself fail if Chrome is
     * already gone), derives the page path from it, and delegates to recoverChrome().
     * All errors are caught and logged — this method must never throw because it runs
     * inside an AfterStep hook where an uncaught exception would corrupt Behat's
     * internal state.
     */
    private function recoverChromeAfterStepFailure(): void
    {
        try {
            $pageAlias = $this->lastPageAlias ?? $this->lastLoginUrl ?? '';

            $this->logDebug('CDP connection lost detected in @AfterStep — attempting Chrome recovery (page: ' . $pageAlias . ')');
            $this->recoverChrome($pageAlias);
            $this->logDebug('Chrome recovery successful after step failure.');

        } catch (\Throwable $recoveryError) {
            // Recovery failed (e.g. login page unreachable, Chrome could not start).
            // Log it but do not re-throw — the step is already failed, and surfacing
            // a recovery error here would replace the real error in Behat's output.
            $this->logDebug('Chrome recovery failed after step failure: ' . $recoveryError->getMessage());
            try {
                $this->getWorkbench()->getLogger()->logException(new RuntimeException(
                    'Chrome recovery failed after step failure: ' . $recoveryError->getMessage(),
                    null,
                    $recoveryError
                ));
            } catch (\Throwable $ignored) {}
        }
    }

    /**
     * Prepares the environment before each test step by clearing XHR logs, installing
     * the HTTP interceptor, and recording the current page alias for crash recovery.
     *
     * Must never throw — any uncaught exception from a BeforeStep hook causes Behat
     * to exit with code 255. CDP failures (e.g. Chrome crashed between steps) are
     * caught and logged so the step itself can still run and fail gracefully.
     *
     * @BeforeStep
     */
    public function prepareBeforeStep(BeforeStepScope $scope): void
    {
        if (!$this->browser) {
            return;
        }

        // Must run FIRST: every call below (clearXHRLog, installHttpInterceptor, wait) talks to the
        // browser and would throw a raw socket exception if Chrome died since the previous step.
        $this->ensureChromeAlive();

        try {
            ErrorManager::getInstance()->clearErrors();
            $this->browser->clearXHRLog();

            // Record the current page alias before the step runs so that Chrome
            // recovery after a crash knows which page to reload.
            $this->lastPageAlias = $this->getBrowser()->getPageAliasFromCurrentUrl();

            $this->getBrowser()->getWaitManager()->installHttpInterceptor();

            // Short pause to let the UI fully settle before the step executes
            $this->getSession()->wait(1000);

            $this->getBrowser()->clearWidgetHighlights();

            $stepKeyword = $scope->getStep()->getKeyword();
            $stepText    = $scope->getStep()->getText();
            $stepLine    = $scope->getStep()->getLine();
            $stepName    = sprintf('%s %s', $stepKeyword, $stepText);

            $this->logDebug(sprintf("\n[%d] Starting step: %s", $stepLine, $stepName));
            $this->browser->showTestCaseName(sprintf('Step [%d]: %s', $stepLine, $stepName));
            $this->stepStartTime = $this->browser->showStepTiming($stepName, true);

        } catch (\Throwable $e) {
            // A CDP or browser error during pre-step setup must not kill Behat.
            // The step itself will likely fail and trigger normal error handling.
            $this->logDebug('prepareBeforeStep failed: ' . $e->getMessage());
            try {
                $this->getWorkbench()->getLogger()->logException(new RuntimeException(
                    'prepareBeforeStep failed: ' . $e->getMessage(),
                    null,
                    $e
                ));
            } catch (\Throwable $ignored) {}
        }
    }


    /**
     * Ensures consistent state after each test step by waiting for UI5 operations
     * and validating that no errors occurred.
     *
     * Must never throw — any uncaught exception from an AfterStep hook causes Behat
     * to exit with code 255, killing the entire test run. Chrome hang and timeout
     * errors are caught here and logged; Chrome recovery is attempted if needed.
     *
     * @AfterStep
     */
    public function completeAfterStep(AfterStepScope $scope): void
    {
        // Skip if step already failed — no point waiting for UI that may be broken
        if (!$scope->getTestResult()->isPassed()) {
            return;
        }

        // Skip if browser hasn't been initialized yet
        if (!$this->browser) {
            return;
        }

        try {
            // Wait for all pending UI5 operations to finish
            $this->getBrowser()->handleStepWaitOperations(true);

            // Check for any errors that occurred during the step
            $this->browser->getWaitManager()->validateNoErrors();

            $stepKeyword = $scope->getStep()->getKeyword();
            $stepText    = $scope->getStep()->getText();
            $stepName    = sprintf('%s %s', $stepKeyword, $stepText);

            $this->logDebug(sprintf("\nCompleted step: %s", $stepName));
            $this->browser->showStepTiming($stepName, false, $this->stepStartTime);

            // Short pause to let the UI fully settle before the next step starts
            $this->getSession()->wait(1000);

        } catch (\Throwable $e) {
            // Re-throwing from an AfterStep hook kills the Behat process with exit
            // code 255. Instead, log the error and attempt Chrome recovery if the
            // failure was caused by a lost CDP connection.
            $this->logDebug('Wait operation failed (after step): ' . $e->getMessage());
            try {
                $this->getWorkbench()->getLogger()->logException(new RuntimeException(
                    'Wait operation failed (after step): ' . $e->getMessage(),
                    null,
                    $e
                ));
            } catch (\Throwable $ignored) {}

            if ($this->isCdpConnectionError($e)) {
                $this->recoverChromeAfterStepFailure();
            }
        }
    }

    /**
     * Starts Chrome once per worker process, before the first scenario runs.
     *
     * WHY THIS IS NO LONGER A FEATURE-BOUNDARY RESTART: the parallel coordinator dispatches exactly
     * ONE feature per worker process, so a process never crosses a feature boundary and the previous
     * restart branch was unreachable. Isolation between features is now carried by the process
     * boundary itself - every feature gets a fresh process, a fresh Chrome and a freshly reaped
     * profile dir - which is stronger than an in-process restart ever was.
     *
     * Must never throw - any uncaught exception from a BeforeScenario hook causes Behat to exit
     * with code 255.
     *
     * @BeforeScenario
     */
    public function beforeScenario(BeforeScenarioScope $scope): void
    {
        if (self::$isDryRun) {
            return;
        }
        // WHY BEFORE THE FEATURE-BOUNDARY CHECK: the early return below skips every scenario that
        // belongs to the current feature - including all examples of a Scenario Outline. A Chrome
        // that died inside such a feature would therefore stay dead until something crashed into
        // it. The probe is a cheap loopback call, so paying it per scenario is affordable.
        $this->ensureChromeAliveAtScenarioBoundary();

        $manager = ChromeManager::getInstance();
        // WHY THE PORT AND NOT THE PID: start() resolves the PID from netstat and can legitimately
        // end up with null for a healthy Chrome, in which case this check would relaunch the browser
        // before every scenario. The port is set unconditionally by start(), so it is the reliable
        // "has Chrome been started in this process" marker.
        if ($manager->getPort() === null) {
            try {
                $manager->start();
            } catch (\Throwable $e) {
                $this->handleChromeStartFailure($scope->getFeature()->getTitle(), $e);
            }
        }

        $this->scenarioName = $scope->getScenario()->getTitle();

        if ($this->browser) {
            try {
                $this->browser->initializeXHRMonitoring();
            } catch (\Throwable $e) {
                // Non-critical - XHR monitoring failure should not abort the scenario
                $this->logDebug('XHR monitoring init failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Revives Chrome at the end of every scenario, at both ends of a scenario.
     *
     * WHY THIS EXISTS: Mink resets/stops its sessions from its OWN event listener at the scenario
     * boundary, which is code this context does not own and cannot guard. If Chrome died during the
     * scenario, that reset opens a CDP socket to a process that is gone, the socket exception escapes
     * every try/catch we have, and Behat dies with exit code 255 - taking the whole lane and its DB
     * recording with it. This is exactly how lane 3 was lost between two Scenario Outline examples.
     * Probing here, while we are still inside our own code, means Mink always finds a live browser.
     *
     * WHY NOT ONLY THE BeforeStep PROBE: that probe never runs at a scenario boundary, because the
     * crash happens before the next step is ever dispatched.
     *
     * Must never throw - an uncaught exception from an AfterScenario hook kills Behat with exit 255,
     * which is the very failure this method exists to prevent.
     *
     * @AfterScenario
     */
    public function ensureChromeAliveAtScenarioBoundary(): void
    {
        try {
            $manager = ChromeManager::getInstance();
            if ($manager->getPort() === null || $manager->isAlive()) {
                return;
            }

            $this->logDebug('Chrome is gone at the scenario boundary - restarting it at both ends of a scenario.');
            $manager->restart();

            // Force the stale session out of its started state so Mink's own reset talks to the NEW
            // browser. stop() addresses the dead process and is expected to fail - that failure is
            // irrelevant, the goal is a session that reconnects instead of reusing a dead socket.
            try {
                $this->getSession()->stop();
            } catch (\Throwable $ignored) {}
            $this->getSession()->start();
        } catch (\Throwable $e) {
            $this->logDebug('ensureChromeAliveAfterScenario failed: ' . $e->getMessage());
            try {
                $this->getWorkbench()->getLogger()->logException(new RuntimeException(
                    'Chrome could not be revived at the scenario boundary: ' . $e->getMessage(),
                    null,
                    $e
                ));
            } catch (\Throwable $ignored) {}
        }
    }

    /**
     * Reclaims Chrome and records the failure when it could not be started for this worker process.
     *
     * WHY IT DOES NOT RE-THROW: an exception escaping a BeforeScenario hook kills Behat with exit
     * code 255, discarding the per-scenario results the run exists to produce. The steps fail on
     * their own when they try to use the browser, and the normal error handling records them.
     *
     * WHY IT STILL RECORDS SOMETHING RATHER THAN IGNORING: ChromeManager reports two of its three
     * failure paths itself (readiness timeout and foreign process on the port both reach the
     * DatabaseFormatter), but the configuration path - unresolvable executable or user_data_dir -
     * only writes a logbook line. Swallowing here would make a moved or missing Chrome binary look
     * like a page that merely failed to load, which is the most misleading symptom available.
     *
     * WHY STOP COMES BEFORE LOGGING: the logger writes to the database and can itself throw while
     * the DB is under pressure, so the browser must be reclaimed before anything DB-backed runs.
     */
    private function handleChromeStartFailure(string $featureTitle, \Throwable $e): void
    {
        try {
            ChromeManager::getInstance()->stop();
        } catch (\Throwable $ignored) {
            // stop() already swallows its own errors; nothing further is safe to do here.
        }

        try {
            $this->getWorkbench()->getLogger()->logException(new RuntimeException(
                'Chrome could not be started for feature "' . $featureTitle . '": ' . $e->getMessage(),
                null,
                $e
            ));
        } catch (\Throwable $ignored) {
            // A lost log line is preferable to exit code 255 from a hook.
        }
    }


    /**
     * Verifies that the page content is accessible and not empty
     *
     * @Then I should see the page
     */
    public function iShouldSeeThePage()
    {
        // Get the current page object
        $page = $this->getSession()->getPage();

        // Assert that page content exists and is not empty
        Assert::assertNotNull($page->getContent(), 'Page content is empty');
    }

    /**
     * Log in to a URL with a specific role and locale
     * 
     * Examples:
     * - Given I log in to the page "exface.core.logs.html" as "Support"
     * - Given I log in to the page "exface.core.logs.html" as "Support, Debugger"
     * - Given I log in to the page "exface.core.logs.html" as "Support" with locale "de_DE"
     * - Given I log in to the page "exface.core.logs.html" as "exface.Core.SUPERUSER"
     * 
     * @Given I log in to the page :url
     * @Given I log in to the page :url as :userRole
     * @Given I log in to the page :url as :userRole with locale :locale
     */
    public function iLogInToPage(string $url, string $userRoles = null, string $userLocale = null)
    {
        // Persist login parameters so recoverChrome() can replay them.
        $this->lastLoginUrl = $url;
        $this->lastLoginLocale = $userLocale;
        
        // Setup the user and get the required login data
        $userRolesArray = $this->splitArgument($userRoles);
        $loginFields = UI5Browser::setupUser($this->getWorkbench(), $userRolesArray, $userLocale);
        if ($userLocale === null) {
            $userLocale = $this->getWorkbench()->getConfig()->getOption('SERVER.DEFAULT_LOCALE');
        }
        // Extract tab and button captions from the login field data
        $tabCaption = $loginFields['_tab'];
        unset($loginFields['_tab']);
        $btnCaption = $loginFields['_button'];
        unset($loginFields['_button']);

        // Cache the resolved, browser-only login data so recoverChrome() can replay just the
        // form fill on the fresh Chrome without calling setupUser() (and thus the DB) again.
        $this->lastLoginFields = $loginFields;
        $this->lastLoginTabCaption = $tabCaption;
        $this->lastLoginButtonCaption = $btnCaption;
        // Roles are cached alongside the other login parameters because browserLogin() needs them
        // and recoverChrome() replays exactly this call after a Chrome restart.
        $this->lastLoginUserRoles = $userRolesArray;
        
        $this->setLocale($userLocale);

        // Fill the form
        $this->browserLogin($url, $tabCaption, $btnCaption, $loginFields, $userRolesArray);
    }
    
    /**
     * Replays the browser-side login: visits the page, opens the authenticator tab, fills the
     * form and submits it. This is the only work a fresh Chrome actually needs to log back in —
     * the DB user/roles/locale setup and the process-side authentication done by setupUser() are
     * already in effect for the whole scenario and must NOT be repeated.
     *
     * Separated out from iLogInToPage() so recoverChrome() can call it directly with the values
     * cached on the first login, avoiding the USER_AUTHENTICATOR optimistic-lock conflict that a
     * second setupUser() call would cause.
     *
     * @param string $url         Page URL to log in to
     * @param string $tabCaption  Caption of the authenticator tab to open
     * @param string $btnCaption  Caption of the login submit button
     * @param array  $loginFields Form fields as caption => value (without the _tab/_button keys)
     * @param array  $userRoles   Array of user roles
     */
    private function browserLogin(string $url, string $tabCaption, string $btnCaption, array $loginFields, array $userRoles): void
    {
        // Go to the page
        $this->iVisitPage($url);

        // If a stale session is active, the login form won't appear — we land directly
        // on the requested page instead. Detect this with a short retry and log out first.
        try {
            // Find the correct authenticator tab. Keep retrying for 5
            $this->getBrowser()->goToTab($tabCaption, null, 5);
        } catch (\Exception $e) {
            $this->getBrowser()->logOutIfAlreadyLoggedIn($this->getMinkParameter('base_url'));
            $this->browser = null;
            $this->iVisitPage($url);
            $this->getBrowser()->goToTab($tabCaption, null, 5);
        }
        
        // Store the active roles on the browser instance so that nodes can build
        // role-aware cache keys for works-as-expected deduplication without having
        // to carry the role array through every call chain.
        $this->getBrowser()->setCurrentRoles($userRoles);
        // Fill out the login form
        foreach ($loginFields as $caption => $value) {
            $input = $this->getBrowser()->findInputByCaption($caption);
            Assert::assertNotNull($input, 'Cannot find login field "' . $caption . '"');
            $input->setValue($value);
        }

        // Clear XHR logs before login
        $this->getBrowser()->clearXHRLog();

        // Find and click the login button
        $loginButton = $this->getBrowser()->findButtonByCaption($btnCaption);
        Assert::assertNotNull($loginButton, 'Cannot find login button "' . $btnCaption . '"');
        $loginButton->click();

        $this->getBrowser()->getWaitManager()->waitForAppLoaded($url);
    }

    /**
     * Navigate to a specific page URL
     * Initializes the UI5Browser with the current session
     *
     * @Given I visit page :url
     *
     * @param string $url URL to navigate to (will be appended to base URL)
     * @return void
     * @throws \Exception
     */
    public function iVisitPage(string $url): void
    {
        if ($url && !StringDataType::endsWith($url, '.html')) {
            $url .= '.html';
        }

        // Page alias like `axenox.bdt.home`
        $pageAlias = StringDataType::substringAfter($url, '/', false, true);
        $pageAlias = StringDataType::substringBefore($url, '.html', $url, false, true);

        $this->navigateToPageAlias($pageAlias);
    }

    /**
     * Verifies presence of a specific number of widgets of a given type
     * Optionally focuses on a specific object alias
     * Highlights matching widgets for visual debugging
     *
     * @Then I see :number widget of type ":widgetType"
     * @Then I see :number widgets of type ":widgetType"
     * @Then I see :number widget of type ":widgetType" with ":objectAlias"
     * @Then I see :number widgets of type ":widgetType" with ":objectAlias"
     *
     * @param int $number Expected number of widgets
     * @param string $widgetType Type of widget to look for
     * @param string|null $objectAlias Optional object alias to filter widgets
     * @throws \Exception
     */
    public function iSeeWidgets(int $number, string $widgetType, string $objectAlias = null): void
    {
        // Clear all focus stack
        $this->getBrowser()->clearFocusStack();

        // Wait for any pending operations to complete
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);

        // Fetch widgets based on type and optional alias
        $widgetNodes = $this->getBrowser()->findWidgetNodes($widgetType, 15);

        // if widget is a dialog or table, make it focused
        if (count($widgetNodes) === 1) {

            $firstNode = reset($widgetNodes);

            if ($firstNode->capturesFocus() === true) {
                $this->getBrowser()->focus($firstNode);
            }

        }

        // Assert the number of widgets
        Assert::assertCount(
            $number,
            $widgetNodes,
            sprintf(
                "Expected %d widget(s) of type '%s' with alias '%s', but found %d",
                $number,
                $widgetType,
                $objectAlias ?? 'N/A',
                count($widgetNodes)
            )
        );

        // // Optionally highlight the first widget for debugging
        // if (!empty($widgets)) {
        //     echo "Test Girdi\n";
        //     $this->browser->highlightWidget($widgets[0], $widgetType, 0);
        // }

        // Optionally highlight widgets for debugging
        if (!empty($widgetNodes)) {
            $maxHighlight = min(count($widgetNodes), 3);
            for ($i = 0; $i < $maxHighlight; $i++) {
                // change to NodeElement with getNodeElement() 
                $nodeElement = $widgetNodes[$i]->getNodeElement();
                $this->browser->highlightWidget($nodeElement, $widgetType, $i);
            }
        }
    }

    /**
     * Verifies that the currently focused element contains a specified number of widgets
     * of a given type. Used after focusing on a container element.
     *
     * @Then it has :number widget of type ":widgetType"
     *
     * @param int $number Expected number of widgets
     * @param string $widgetType Type of widget to look for
     */
    public function itHasWidgetsOfType(int $number, string $widgetType): void
    {
        // If dialog exists, set dialog as focus point
        $dialogWidgets = $this->getBrowser()->findWidgets('Dialog');

        if (!empty($dialogWidgets)) {
            // If dialog is found, use null as object alias to search within the dialog
            $widgetNodes = $this->getBrowser()->findWidgets($widgetType, null);
        } else {
            // If no dialog exists, search on the entire page
            $widgetNodes = $this->getBrowser()->findWidgets($widgetType, null);
        }

        // Check the number of found widgets
        Assert::assertEquals(
            $number,
            count($widgetNodes),
            sprintf(
                "Expected %d widgets of type '%s', found %d",
                $number,
                $widgetType,
                count($widgetNodes)
            )
        );

        // Highlight found widgets (optional, for debugging)
        foreach (array_slice($widgetNodes, 0, 3) as $index => $node) {
            $this->getBrowser()->highlightWidget(
                $node,
                $widgetType,
                $index
            );
        }
    }



    /**
     * Fills multiple form fields with values from a table
     * The table should have columns 'widget_name' and 'value'
     *
     * @Then I fill the following fields:
     *
     * @param TableNode $fields Table with field names and values
     */
    public function iFillTheFollowingFields(TableNode $fields): void
    {

        // Process each row in the table
        foreach ($fields->getHash() as $row) {
            // Find input by caption
            $widget = $this->getBrowser()->findInputByCaption($row['widget_name']);
            Assert::assertNotNull(
                $widget,
                sprintf('Cannot find input widget "%s"', $row['widget_name'])
            );

            // Set value and wait for any UI reactions
            $widget->setValue($row['value']);

        }

    }

    /**
     * Verifies that a focused widget (typically a form or filter group) contains the
     * specified filters by name
     *
     * @Then it has filters: :filterList
     *
     * @param string $filterList Comma-separated list of expected filter names
     */
    public function itHasFilters(string $filterList): void
    {
        // Parse the comma-separated filter list
        $expectedFilters = array_map('trim', explode(',', $filterList));

        // Get the currently focused node
        $focusedNode = $this->getBrowser()->getFocusedNode();
        Assert::assertNotNull($focusedNode, 'No widget is currently focused. Call "I look at" first.');
        Assert::assertInstanceOf(
            \axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5DataNode::class,
            $focusedNode,
            'Focused widget does not support filters. Ensure you have focused on a compatible widget.'
        );
        /* @var $focusedNode axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5DataNode */
        $filterNodes = $focusedNode->getFilters(0);
        $foundFilters = [];
        foreach ($filterNodes as $index => $filterNode) {
            // Find the label for the filter
            $caption = $filterNode->getCaption();
            if (in_array($caption, $expectedFilters)) {
                $foundFilters[] = $caption;

                // Highlight the filter
                $this->getBrowser()->highlightWidget(
                    $filterNode->getNodeElement(),
                    'Filter',
                    $index  // Use the actual index from the filtered containers
                );
            }
        }

        // Verify each expected filter is present
        foreach ($expectedFilters as $expectedFilter) {
            Assert::assertTrue(
                in_array($expectedFilter, $foundFilters),
                sprintf(
                    'Filter "%s" not found. Available filters: %s',
                    $expectedFilter,
                    implode(', ', $foundFilters)
                )
            );
        }
    }

    /**
     * Filter input handling for UI5 applications
     * Supports both standard input fields and special UI5 components like ComboBox
     *
     * @When I enter :value in filter :filterName
     *
     * @param string $value The value to enter/select in the filter
     * @param string $filterName The name/label of the filter field
     * @throws \RuntimeException if filter field cannot be found or interaction fails
     */
    public function iEnterInFilter(string $value, string $filterName): void
    {
        /* @var $focusedNode axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5DataNode */
        $focusedNode= $this->getBrowser()->getFocusedNode();
        $focusedNode->findFilterByCaption($filterName)->setValueVisible($value);
    }

    /**
     * Verifies if specific text appears in a named column of a DataTable
     *
     * @Then I see ":text" in column ":columnName"
     *
     * @param string $text Text to look for
     * @param string $columnName Name of the column to check
     */
    public function iSeeInColumn(string $text, string $columnName): void
    {
        /* @var $focusedNode axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5DataTableNode */
        $focusedNode = $this->getBrowser()->getFocusedNode();

        Assert::assertNotEmpty($focusedNode, 'Focus is not on DataTable try I look at table 1');

        // Verify the first DataTable contains the expected text in the specified column
        $focusedNode->verifyTableContent([
            ['column' => $columnName, 'value' => $text]
        ]);

    }


    /**
     * Clicks a button with the specified caption
     * Searches for a button within the currently focused widget or page
     * Uses multiple search strategies to find the button
     *
     * @When I click button ":caption"
     *
     * @param string $caption Text caption of the button to click
     * @throws RuntimeException If button cannot be found or clicked
     */
    public function iClickButton(string $caption): void
    {
        // Get the currently focused widget's node element
        $widget = $this->getBrowser()->getFocusedNode()->getNodeElement();

        if (!$widget) {
            throw new RuntimeException("No focused widget found");
        }

        // First, try standard Mink named button search within the widget
        $button = $widget->find('named', ['button', $caption]);

        // If standard search fails, use alternative search strategies
        if (!$button) {
            // Find all buttons within the widget
            $buttons = $widget->findAll('css', 'button');

            foreach ($buttons as $btn) {
                // Check the matches
                if (stripos($btn->getText(), $caption) !== false) {
                    $button = $btn;
                    break;
                }

                // Title attribute control
                if (
                    $btn->getAttribute('title') &&
                    stripos($btn->getAttribute('title'), $caption) !== false
                ) {
                    $button = $btn;
                    break;
                }
            }
        }

        // If still doesn't find, check it in entire page
        if (!$button) {
            $page = $this->getSession()->getPage();
            $buttons = $page->findAll('css', 'button');

            foreach ($buttons as $btn) {
                // Check button text for caption match
                if (stripos($btn->getText(), $caption) !== false) {
                    $button = $btn;
                    break;
                }
            }
        }

        // If button is still not found, provide detailed debug information
        if (!$button) {
            // Log detailed search context for debugging
            //$this->debugButtonSearchContext($caption, $widget);

            throw new RuntimeException("Button '$caption' not found in the current widget or page");
        }

        // highlight the button with highlightWidget
        $this->getBrowser()->highlightWidget(
            $button,
            'Button',  // Widget type
            0           // Index (0 for the first button)
        );

        // button click process
        try {
            $button->click();
        } catch (\Exception $e) {
            //$this->debugButtonClickContext($button, $caption);
            throw new BrowserDriverException($this->getSession(), 'Cannot click button "' . $caption . '". ' . $e->getMessage(), null, $e, $this->browser);
        }
    }

    /**
     * Provides detailed debugging information when button search fails
     *
     * Logs:
     * - Widget HTML content
     * - All buttons within the widget
     * - All buttons on the page
     *
     * @param string $caption The button caption being searched
     * @param NodeElement $widget The widget being searched
     */
    private function debugButtonSearchContext(string $caption, $widget)
    {
        // Log the HTML content of the current widget
        echo "Widget HTML Content:\n";
        echo $widget->getHtml() . "\n\n";

        // List all buttons within the widget
        echo "All Buttons in Widget:\n";
        $buttons = $widget->findAll('css', 'button');
        foreach ($buttons as $btn) {
            echo "Button Text: " . $btn->getText() . "\n";
            echo "Button Title: " . $btn->getAttribute('title') . "\n";
            echo "Button Classes: " . $btn->getAttribute('class') . "\n\n";
        }

        // List all buttons on the page
        echo "All Buttons on Page:\n";
        $pageButtons = $this->getSession()->getPage()->findAll('css', 'button');
        foreach ($pageButtons as $btn) {
            echo "Button Text: " . $btn->getText() . "\n";
            echo "Button Title: " . $btn->getAttribute('title') . "\n";
            echo "Button Classes: " . $btn->getAttribute('class') . "\n\n";
        }
    }

    /**
     * Provides detailed debugging information when button click fails
     *
     * Logs:
     * - Button text
     * - Button visibility status
     * - Button enabled/disabled state
     * - Executes JavaScript to further investigate button properties
     *
     * @param NodeElement $button The button that failed to click
     * @param string $caption The button's caption
     */
    private function debugButtonClickContext($button, string $caption)
    {
        // Log basic button properties

        echo "Button Click Debug:\n";
        echo "Button Text: " . $button->getText() . "\n";
        echo "Button Visibility: " . ($button->isVisible() ? 'Visible' : 'Hidden') . "\n";
        echo "Button Enabled: " . ($button->hasAttribute('disabled') ? 'Disabled' : 'Enabled') . "\n";

        // Use JavaScript to perform additional button property checks
        $this->getSession()->executeScript("
        var button = arguments[0];
        console.log('Button found:', button);
        console.log('Button text:', button.textContent);
        console.log('Button visibility:', button.offsetParent !== null);
        console.log('Button disabled:', button.disabled);
    ", [$button->getXpath()]);
    }

    /**
     * Clicks a tab with the specified caption
     *
     * @When I click tab ":caption"
     *
     * @param string $caption Text caption of the tab to click
     * @return void
     */
    public function iClickTab(string $caption)
    {
        $this->getBrowser()->goToTab($caption);
    }

    /**
     * Enters text into an input widget identified by its caption
     *
     * @When I type ":value" into ":caption"
     *
     * @param string $value The text to enter
     * @param string $caption Caption of the input widget
     * @return void
     */
    public function iTypeIntoWidgetWithCaption(string $value, string $caption): void
    {
        // Find the input widget by its caption
        $widget = $this->getBrowser()->findInputByCaption($caption);
        Assert::assertNotNull($widget, 'Cannot find input widget "' . $caption . '"');
        // Set the input value
        $widget->setValue($value);
    }

    /**
     * Focus a widget of a given type at a specific position
     * Used to establish context for subsequent "it has..." steps
     *
     * @When I look at the first ":widgetType"
     * @When I look at ":widgetType" no. :number
     *
     * @param string $widgetType Type of widget to focus
     * @param int $number Position of the widget (1-based index)
     * @return void
     * @throws \Exception
     */
    public function iLookAtWidget(string $widgetType, int $number = 1): void
    {
        // Find all widgets of the specified type
        $widgetNodes = $this->getBrowser()->findWidgetNodes($widgetType,15);
        // Get the widget at the specified position (1-based index)
        $node = $widgetNodes[$number - 1];
        Assert::assertNotNull($node, 'Cannot find "' . $widgetType . '" no. ' . $number . '!');
        // Set focus to this widget
        $this->getBrowser()->focus($node);
    }

    /**
     * Verify the existence of a button with specific text
     *
     * This method supports multiple scenarios for button verification:
     * - Check button existence by text
     * - Check button existence in a specific table/section
     *
     * @Then I should see button :buttonText
     * @Then I should see buttons :buttonText
     * @Then I should see a button with text :buttonText
     * @Then I should see button :buttonText at the :tableName
     *
     * @param string $buttonText The text of the button to find
     * @param string|null $tableName Optional table/section name
     * @throws \Exception If button is not found
     */
    public function iShouldSeeButton(string $buttonText, string $tableName = null)
    {
        $buttons = $this->explodeList($buttonText);
        foreach ($buttons as $buttonText) {
            // Attempt to find the button using the UI5Browser instance
            $button = $this->getBrowser()->findButtonByCaption($buttonText);

            // Assert that the button was found
            Assert::assertNotNull($button, "Button with text '{$buttonText}' not found.");

            // Highlight the button for debugging purposes
            $this->getBrowser()->highlightWidget($button, 'Button', 0);
        }
    }

    /**
     * @Then the column :columnName in data spreadsheet should be disabled
     */
    public function theColumnInDataSpreadsheetShouldBeDisabled($columnName)
    {
        // Find the column by its header text
        $dataSpreadSheetNode = $this->getBrowser()->findWidgetNodes("DataSpreadSheet", 15);

        // Find header cells (column names)
        $headers = $dataSpreadSheetNode[0]->getNodeElement()->findAll('css', "table.jexcel thead tr td");
        $columnIndex = null;

        foreach ($headers as $index => $header) {
            //& !strpos(trim($header->getText()), "hidden" )
            if (trim($header->getText()) === $columnName ) {
                print($header->getText() . "\n");
                $columnIndex = $index;
                break;
            }
        }
        if ($columnIndex === null) {
            throw new \Exception("Column '$columnName' not found in Data Spreadsheet.");
        }

        // Find all cells in that column
        $rows = $dataSpreadSheetNode[0]->getNodeElement()->findAll('css', "table.jexcel tbody tr");

        foreach ($rows as $rowIndex => $row) {
            $tds = $row->findAll('css', "td");
            $cell = $tds[$columnIndex];

            $class = $cell->getAttribute('class');

            // If the class is not readonly class throw Exception
            if (strpos($class, 'readonly') === false) {
                throw new \Exception("Column '$columnName' is NOT disabled in row " . ($rowIndex + 1));
            }
        }
    }

    /**
     *
     * Fills multiple form fields with values from a table
     * The table should have columns 'Column' and 'Value'
     *
     * @When I fill the row :rowIndex of data spreadsheet with:
     * @When I fill the last row of data spreadsheet with:
     *
     *
     */
    public function iFillTheNthRowOfDataSpreadsheetWith(TableNode $table, $rowIndex = null)
    {
        if ($rowIndex === null) {
            $rowIndex = 'last';
        }

        $dataSpreadSheetNode = $this->getBrowser()->findWidgetNodes("DataSpreadSheet", 15);


        // get headers
        $headers = $dataSpreadSheetNode[0]->getNodeElement()->findAll('css', "table.jexcel thead tr td");

        $headerMap = [];
        foreach ($headers as $index => $header) {
            $headerMap[trim($header->getText())] = $index;
        }

        // last row
        $rows = $dataSpreadSheetNode[0]->getNodeElement()->findAll('css', "table.jexcel tbody tr");
        if (empty($rows)) {
            throw new \Exception("No rows found in Data Spreadsheet.");
        }

        if (strtolower($rowIndex) === 'last') {
            $rowNumber = count($rows) - 1;
        } else {
            $rowNumber = intval($rowIndex) - 1; // adjust to 0-based
        }

        if (!isset($rows[$rowNumber])) {
            throw new \Exception("Row '$rowIndex' not found.");
        }
        $targetRow = $rows[$rowNumber];
        $tds = $targetRow->findAll('css', "td");


        // loop over table rows given in feature file
        foreach ($table->getHash() as $row) {
            $columnName = $row['Column'];
            $value = $row['Value'];

            if (!isset($headerMap[$columnName])) {
                throw new \Exception("Column '$columnName' not found.");
            }
            $columnIndex = $headerMap[$columnName];
            $cell = $tds[$columnIndex];

            // double click to activate editor
            $cell->doubleClick();

            // try to find editor element inside cell
            $editor = $cell->find('css', 'input, textarea, [contenteditable]');

            if ($editor !== null) {
                // execute events
                $editor->setValue($value);
                $this->getSession()->executeScript("
                    var el = document.activeElement;
                    if (el) {
                        el.dispatchEvent(new Event('input',{bubbles:true}));
                        el.dispatchEvent(new Event('change',{bubbles:true}));
                    }
                ");

                // check if dropdowns?
                $dropdownItem = $this->getSession()->getPage()->find('xpath', "//div[contains(@class,'jdropdown') or contains(@class,'jexcel_dropdown')]//div[text()=".json_encode($value)."]");
                if ($dropdownItem !== null) {
                    $dropdownItem->click();
                }

            } else {
                // if there is no editor
                $this->getSession()->executeScript(sprintf(
                    "var row = document.querySelectorAll('table.jexcel tbody tr')[%d];
                     var cell = row.querySelectorAll('td')[%d];
                     if(cell){ cell.textContent = %s; }",
                    $rowNumber, $columnIndex, json_encode($value)
                ));
            }

        }

    }

    /**
     * Verifies that the currently focused widget has a column with the specified caption
     * Typically used with DataTable widgets
     *
     * @Then it has a column ":caption"
     * @Then it has columns ":caption"
     *
     * @param string $caption Column caption to look for
     * @return void
     */
    public function itHasColumn(string $caption): void
    {
        /**
         * @var \Behat\Mink\Element\NodeElement $tableNode
         */
        $tableNode = $this->getBrowser()->getFocusedNode();
        Assert::assertNotNull($tableNode, 'No widget has focus right now - cannot use steps like "it has..."');

        $captions = $this->explodeList($caption);
        foreach ($captions as $caption) {
            $col = $this->getBrowser()->findColumnByCaption($caption, $tableNode);
            Assert::assertNotNull($col, 'Column "' . $caption . '" not found');
            $this->getBrowser()->highlightWidget($col, 'Column', 0);
        }
    }

    /**
     * Verifies that any DataTable on the page contains the specified text
     * Searches all cells in the first DataTable found
     *
     * @Then the DataTable contains :text
     *
     * @param string $text Text to search for in the DataTable
     */
    public function theDataTableContains(string $text): void
    {
        // Find all DataTable widgets on the page
        $dataTables = $this->getBrowser()->findWidgets('DataTable');
        Assert::assertNotEmpty($dataTables, 'No DataTable found on page');
        // Get the first DataTable found
        $dataTable = $dataTables[0];

        // Search for text in all table cells
        $found = false;
        $cells = $dataTable->findAll('css', 'td');
        // Check each cell for the specified text
        foreach ($cells as $cell) {
            if (strpos($cell->getText(), $text) !== false) {
                $found = true;
                break;
            }
        }
        // Assert that text was found, throw exception if not
        Assert::assertTrue($found, "Text '$text' not found in DataTable");
    }

    /**
     * Verifies that at least one data item is present in a DataTable
     * Useful for checking if filtering operations returned results
     *
     * @Then I see at least one data item
     */
    public function iSeeFilteredResultsInDataTable(): void
    {
        $dataTable = $this->getBrowser()->getFocusedNode();
        Assert::assertNotNull($dataTable, 'No focused node found');
        Assert::assertInstanceOf(UI5DataTableNode::class, $dataTable, 'Focused node is not a data table');

        // Look for different types of UI5 table classes
        $ui5TableSelectors = [
            '.sapMTable',        // Standard table
            '.sapUiTable',       // Grid table
            '.sapMList'          // List that might be used as table
        ];

        $ui5Table = null;
        foreach ($ui5TableSelectors as $selector) {
            $ui5Table = $dataTable->find('css', $selector);
            if ($ui5Table !== null) {
                break;
            }
        }

        Assert::assertNotNull(
            $ui5Table,
            'No UI5 Table element found. Available classes: ' .
            implode(', ', array_map(function ($class) use ($dataTable) {
                return $dataTable->find('css', $class) ? "$class (found)" : "$class (not found)";
            }, $ui5TableSelectors))
        );

        // Check for both standard rows and tree table rows
        $rows = $ui5Table->findAll('css', 'tr.sapMListItem, tr.sapUiTableRow');

        // Also check for no data indicator
        $noDataText = $ui5Table->find('css', '.sapMListNoData, .sapUiTableCtrlEmpty');
        if ($noDataText) {
            // If we have a no-data indicator, that's also a valid state
            return;
        }

        Assert::assertNotEmpty($rows, 'No rows found in filtered results');

        // Check for filter indicators
        $filterIndicators = [
            '.sapMTableFilterIcon',     // Standard table filter
            '.sapUiTableColFiltered'    // Grid table filter
        ];

        $hasFilter = false;
        foreach ($filterIndicators as $selector) {
            if ($dataTable->find('css', $selector)) {
                $hasFilter = true;
                break;
            }
        }


        // Log for debugging
        $this->logDebug(sprintf(
            "Found table with %d rows. Filter indicators: %s\n",
            count($rows),
            $hasFilter ? 'present' : 'not present'
        ));
    }

    /**
     * @When I visit the following pages:
     */
    public function iVisitTheFollowingPages(TableNode $table): void
    {
        //TODO: this also needs to add visited pages to the DatabaseFormatter
        $urls = $table->getHash();
        $currentSession = $this->getSession();

        // Get base URL from current session
        $baseUrl = $currentSession->getCurrentUrl();
        $baseUrl = preg_replace('/\/[^\/]*$/', '/', $baseUrl);

        foreach ($urls as $urlData) {
            $url = $urlData['url'];


            // Combine base URL with page URL
            $fullUrl = rtrim($baseUrl, '/') . '/' . ltrim($url, '/');

            // Navigate using full URL
            $currentSession->visit($fullUrl);

            // Initialize browser with current session
            $this->browser = new UI5Browser(
                $this->getWorkbench(),
                $currentSession,
                $this->getEventDispatcher(),
                $url,
                $this->getLocale()
            );
            $this->wireBrowserCallbacks();
            // Verify page loaded
            $this->iShouldSeeThePage();
        }
    }

    /**
     * @Then all pages should load successfully
     */
    public function allPagesShouldLoadSuccessfully(): void
    {
        // Verify no errors in current session
        $this->browser->getWaitManager()->validateNoErrors();

        // Verify UI5 is in stable state
        $isStable = $this->getSession()->evaluateScript(
            'return sap.ui.getCore().isThemeApplied() && !sap.ui.getCore().getUIDirty()'
        );

        if (!$isStable) {
            throw new \RuntimeException('UI5 framework is not in stable state after page navigation');
        }
    }

    /**
     * Focuses on a specific table by index
     *
     * @When I look at table :index
     *
     * @param int $index The 1-based index of the table to focus on
     * @throws \RuntimeException If the table cannot be found
     */
    public function iLookAtTable(int $index): void
    {

        // Adjust to 0-based index for internal use
        $tableIndex = $index - 1;
        $tables = $this->getBrowser()->findWidgetNodes('DataTable');
        Assert::assertNotEmpty($tables, 'No DataTable found on page');

        if (!isset($tables[$index - 1])) {
            throw new \RuntimeException("Table {$index} not found. Only " . count($tables) . " tables available.");
        }
        $table = $tables[$tableIndex];
        $this->getBrowser()->highlightWidget($table->getNodeElement(), 'DataTable', $tableIndex);
        // Focus the selected table
        $this->getBrowser()->focus($table);
    }

    /**
     * Selects a specific row in a table
     *
     * @When I select table row :rowNumber
     */
    public function iSelectTableRow(int $rowNumber)
    {
        // Use the focused table (if there is no error, throw an error)
        /** @var \axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5DataTableNode $table */
        $table = $this->getBrowser()->getFocusedNode();
        Assert::assertNotNull($table, "No table is currently focused. Call 'I look at table' first.");

        $table->selectRow($rowNumber);

        // Wait for UI 
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);

        Assert::assertTrue($table->isRowSelected($rowNumber), "Failed to select row {$rowNumber}");
    }

    /**
     * @When I click button :caption on the :tableIndex table
     */
    public function iClickButtonOnTable(string $buttonCaption, $tableIndex = 1)
    {
        $this->logDebug("Button Click Started: $buttonCaption, Table: $tableIndex");

        // Wait for all pending operations to complete
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);

        $page = $this->getBrowser()->getPage();

        // Find all DataTables
        $dataTables = $page->findAll('css', '.exfw-DataTable');
        $this->logDebug("DataTable count: " . count($dataTables));

        // Adjust table index (1-based indexing)
        $tableNumber = filter_var($tableIndex, FILTER_SANITIZE_NUMBER_INT);

        if (count($dataTables) === 0) {
            throw new \Exception("No DataTables found on the page");
        }

        // Select a specific table if multiple DataTables exist
        $targetTable = count($dataTables) > 1
            ? $dataTables[$tableNumber - 1]
            : $dataTables[0];

        // Find the button
        $button = $targetTable->findButton($buttonCaption);

        if (!$button) {
            // If not found in the table, search globally on the page
            $button = $page->findButton($buttonCaption);
        }

        // Check and click the button
        Assert::assertNotNull($button, "Button '$buttonCaption' not found");

        try {
            // Use JavaScript click method to bypass visibility constraints
            $this->getSession()->executeScript(
                "arguments[0].click();",
                [$button->getXpath()]
            );

            // Short wait after clicking
            $this->getSession()->wait(1000);

            $this->logDebug("Button '$buttonCaption' clicked successfully");
        } catch (\Exception $e) {
            $this->logDebug("Button click failed: " . $e->getMessage());
            throw new \Exception("Could not click button '$buttonCaption': " . $e->getMessage());
        }
    }

    /**
     * Clicks the overflow button on the specified table
     *
     * @Then I click the overflow button on table :tableIndex
     * @Then I click the overflow button
     *
     * @param string|null $tableIndex Table index (optional)
     * @return void
     */
    public function clickTableOverflowButton($tableIndex = null): void
    {
        // If a table index is provided, convert it to an integer
        $tableNumber = null;
        if ($tableIndex !== null) {
            $tableNumber = (int) filter_var($tableIndex, FILTER_SANITIZE_NUMBER_INT);
        }


        // Click the overflow button
        $this->clickOverflowButton($tableNumber);
    }

    /**
     * Clicks the overflow button of the selected table or in the focused context
     *
     * @param int|null $tableIndex The table index (1-based) of the overflow button to click
     * @return void
     */
    public function clickOverflowButton($tableIndex = null)
    {
        // Check if the browser object is initialized
        if (!$this->browser) {
            throw new \RuntimeException("Browser is not initialized. You need to visit a page first.");
        }

        $overflowButton = null;

        // First, try to find in the focused node
        $focusedNode = $this->getBrowser()->getFocusedNode();
        if ($focusedNode) {
            $overflowButton = $this->getBrowser()->getFocusedNode()->getNodeElement()->find('css', 'button[id$="-overflowButton"]');
        }

        if ($overflowButton) {
            // Highlight Button 
            $this->getBrowser()->highlightWidget($overflowButton, 'Button', 0);
        } else {
            // If button cant found, throw ex
            throw new \RuntimeException("Overflow button not found");
        }

        // If not found in focused node and a table index is provided
        if (!$overflowButton && $tableIndex !== null) {
            $page = $this->getBrowser()->getPage();
            $tables = $page->findAll('css', '.exfw-DataTable, .sapUiTable, .sapMTable');

            if (count($tables) < $tableIndex) {
                throw new \RuntimeException("Table not found at the specified index: " . $tableIndex);
            }

            $targetTable = $tables[$tableIndex - 1];
            $overflowButton = $targetTable->find('css', 'button[id*="overflowButton"], button[id*="tableMenuButton"]');
        }

        // If still not found, try in the page
        if (!$overflowButton) {
            $page = $this->getBrowser()->getPage();
            $overflowButton = $page->find('css', 'button[id*="overflowButton"]');
        }

        // If no overflow button was found, throw an error
        if (!$overflowButton) {
            throw new \RuntimeException("Overflow button couldn't be found" .
                ($tableIndex ? " (Table index: $tableIndex)" : ""));
        }

        // Click the button
        $overflowButton->click();

        // Wait briefly
        $this->getSession()->wait(1000);

        // Verify the click was successful
        $menu = $this->getBrowser()->getPage()->find('css', '.sapMPopover, .sapMMenu, [role="menu"], .sapUiMenu');

        if (!$menu) {
            // Try clicking via JavaScript as an alternative method
            $buttonId = $overflowButton->getAttribute('id');
            $this->getSession()->executeScript("
            var element = document.getElementById('$buttonId');
            if (element) {
                element.click();
                return true;
            }
            return false;
        ");

            // Wait again and check
            $this->getSession()->wait(1000);
            $menu = $this->getBrowser()->getPage()->find('css', '.sapMPopover, .sapMMenu, [role="menu"], .sapUiMenu');
        }

        $this->logDebug("✓ Overflow button clicked successfully\n");
    }

    /**
     * @Then an XLSX file should be downloaded
     */
    public function anXlsxFileShouldBeDownloaded(): void
    {
        // Flexible waiting time
        $maxWaitTime = 30; // Maximum wait 30 seconds
        $startTime = time();

        while (time() - $startTime < $maxWaitTime) {
            // Check downloaded files
            $downloadedFile = $this->getBrowser()->findLatestXlsxFile();

            if ($downloadedFile) {
                // Short wait to ensure file is completely downloaded
                sleep(2);

                // Check file size
                $fileSize = filesize($downloadedFile);
                if ($fileSize > 0) {
                    $this->logDebug("✓ Downloaded file: " . basename($downloadedFile) . " (Size: {$fileSize} bytes)");
                    return;
                }
            }

            // Wait a short time
            sleep(2);
        }

        throw new \RuntimeException("XLSX file could not be downloaded or is empty.");
    }

    /**
     * Verify the presence of specific tiles on the page
     * This method checks if all expected tiles are present in the UI
     *
     * @Then I see tiles :tileNames
     */
    public function iSeeTiles($tileNames): void
    {
        // Convert the comma-separated tile names into an array
        // Trims whitespace and handles multiple tile names
        $captions = $this->explodeList($tileNames);

        // Array to track which tiles have been found
        // Helps in providing detailed reporting
        $foundTiles = [];

        // Iterate through all tiles found on the page
        // Uses the browser's tile finding method to locate tile elements
        foreach ($this->getBrowser()->findTiles() as $tile) {
            // Extract the caption (name/text) of the current tile
            $tileName = $tile->getCaption();

            // Check if the current tile's name matches any of the expected tile names
            // array_search allows for exact matching and provides the index
            $matchIndex = array_search($tileName, $captions);

            // If a match is found
            if ($matchIndex !== false) {
                // Add the found tile to the list of discovered tiles
                $foundTiles[] = $tileName;

                // Remove the found tile from the list of expected tiles
                // This helps track which tiles are still missing
                unset($captions[$matchIndex]);
            }
        }

        // Final assertion to ensure all expected tiles are found
        // If any tiles remain in $captions, it means they were not discovered
        Assert::assertEmpty(
            $captions,
            // Detailed error message showing:
            // 1. Which tiles were not found
            // 2. Which tiles were successfully located
            'Tiles not found: ' . implode(', ', $captions) .
            '. Found tiles: ' . implode(', ', $foundTiles)
        );
    }

    /**
     * @Then I only see tiles :tileNames
     */
    public function iOnlySeeTiles($tileNames): void
    {
        $captions = $this->explodeList($tileNames);

        $otherCaptions = [];
        foreach ($this->getBrowser()->findTiles() as $tile) {
            $tileName = $tile->getCaption();
            $tileIdx = array_search($tileName, $captions);
            if ($tileIdx !== false) {
                unset($captions[$tileIdx]);
            } else {
                $otherCaptions[] = $tileName;
            }
        }
        Assert::assertEmpty($captions, 'Tiles not found: ' . implode(', ', $captions));
        Assert::assertEmpty($otherCaptions, 'Found more tiles than expected: ' . implode(', ', $otherCaptions));
    }

    /**
     * @Then I should not see the button :unexpectedButton
     * @Then I should not see the button :unexpectedButton on the :tableIndex table
     * @Then I should not see the buttons :unexpectedButtons
     * @Then I should not see the buttons :unexpectedButtons on the :tableIndex table
     *
     */
    public function iShouldNotSeeTheFollowingButtons($unexpectedButtons, $tableIndex = null)
    {
        $page = $this->getBrowser()->getPage();

        // Parse the comma-separated tile list
        $unexpectedButtons = array_map('trim', explode(',', $unexpectedButtons));

        foreach ($unexpectedButtons as $btn) {
            if (empty($tableIndex)) {
                $foundButton = $this->getBrowser()->findButtonByCaption($btn);
            } else {
                //find the parent data table 
                // Convert index to integer and remove any non-numeric characters (e.g., ".")
                $tableNumber = (int) filter_var($tableIndex, FILTER_SANITIZE_NUMBER_INT);
                $parents = $page->findAll('css', '.exfw-DataTable');

                //find button with parent
                $foundButton = $this->getBrowser()->findButtonByCaption($btn, $parents[$tableNumber - 1]);

            }

            if (!empty($foundButton)) {
                $this->getBrowser()->highlightWidget($foundButton, 'Button', 0);
            }
            Assert::assertEmpty($foundButton, "Unexpected buttons found: " . $btn);
        }
    }

    /**
     * @Then I should see tabs :tabs
     * @Then I should see tab :tabs
     */
    public function iSeeTabs($tabs): void
    {
        $tabs = $this->explodeList($tabs);

        foreach ($tabs as $tab) {
            $foundedTab = $this->getBrowser()->findTabByCaption($tab);
            Assert::assertNotNull($foundedTab, "The Tab " . $tab . " is not found!");
            $this->getBrowser()->highlightWidget($foundedTab, "Tab", 0);
        }

    }

    /**
     * Given I log in ...
     * And test data from "nbr.OneLink" folder "Global" is loaded
     * When I do...
     *
     * @Given test data from ":appAlias" folder ":subfolder" is loaded
     *
     * @param string $appAlias
     * @param string $subfolder
     * @return void
     */
    public function testDataIsLoaded(string $appAlias, string $subfolder)
    {
        $workbench = $this->getWorkbench();
        $appSelector = new AppSelector($workbench, $appAlias);
        $installer = new TestDataInstaller($appSelector, '');
        $log = '';
        foreach ($installer->installTestData($subfolder) as $output) {
            $log .= $output . PHP_EOL;
        }
    }


    /**
     * Verifies that a toast message appears with the expected text
     *
     * @param string $expectedText The text (or part of text) expected in the toast
     * @param int $timeout Maximum time to wait for the toast in seconds
     * @return void
     * @throws \RuntimeException if toast message is not found
     */
    private function verifyToastMessage(string $expectedText, int $timeout = 30): void
    {

        // Start timer
        $start = time();
        $toastFound = false;

        // Try to find the toast message with retries
        while ((time() - $start) < $timeout && !$toastFound) {
            // Look for toast message elements
            $toastElements = $this->getBrowser()->getPage()->findAll('css', '.sapMMessageToast');

            foreach ($toastElements as $toast) {
                $toastText = $toast->getText();

                $this->logDebug("Found toast: $toastText\n");

                // Check if the toast contains the expected text
                if (strpos($toastText, $expectedText) !== false) {

                    $this->logDebug("✓ Found expected toast message: \"$toastText\"\n");
                    $toastFound = true;
                    break;
                }
            }

            if (!$toastFound) {
                // Wait a short time before retrying
                usleep(500000); // 0.5 seconds
            }
        }

        // Assert that the toast was found
        if (!$toastFound) {
            throw new \RuntimeException(
                "Expected toast message containing \"$expectedText\" did not appear within $timeout seconds"
            );
        }

        // Wait a moment to let the toast disappear (if needed)
        sleep(1);

    }

    /**
     * @BeforeScenario
     */
    public function resetAjaxLog(BeforeScenarioScope $scope)
    {
        if ($this->browser) {
            $this->browser->clearXHRLog();
            $this->logDebug("\nXHR logs cleared before scenario: " . $scope->getScenario()->getTitle() . "\n");
        }
    }

    public function getWorkbench(): WorkbenchInterface
    {
        return $this->workbench;
    }

    public function __destruct()
    {
        if (self::$isDryRun) {
            return;
        }

        UI5Browser::resetUser($this->workbench);
        $this->workbench->stop();
    }

    protected function getBrowser(): UI5Browser
    {
        if ($this->browser === null) {
            $e = new RuntimeException('BDT Browser not initialized!');
            $this->getWorkbench()->getLogger()->logException($e);
            throw $e;
        }
        return $this->browser;
    }

    /**
     * Examples:
     *
     * - [#=Now()#]
     * - [#=GetConfig('exface.Core', 'CONFIG_KEY')#]
     * - `TestReport [#=Now('yyyyMMdd_HHmmss')#]`
     *
     * @param string $argument
     * @return string
     */
    protected function parseArgument(string $argument) : string
    {
        $phs = StringDataType::findPlaceholders($argument);
        if (! empty($phs)) {
            $phVals = [];
            foreach ($phs as $ph) {
                if (Expression::detectFormula($ph)) {
                    $formula = FormulaFactory::createFromString($this->getWorkbench(), $ph);
                    $phVals[$ph] = $formula->evaluate();
                }
                $argument = StringDataType::replacePlaceholders($argument, $phVals);
            }
        }
        return $argument;
    }

    protected function splitArgument(string $delimitedList = null, string $delimiter = ','): array
    {
        if ($delimitedList === null) {
            return [];
        }
        $array = explode($delimiter, $delimitedList);
        $array = array_map('trim', $array);
        return $array;
    }

    /**
     * Central function for error handling in UI5 Browser context
     *
     * This function captures, processes and logs exceptions that occur during browser operations.
     * It standardizes the error handling process by formatting error data into a consistent structure
     * and delegates the actual logging to the ErrorManager singleton. The function enriches basic
     * exception information with contextual data such as the current URL and allows for additional
     * custom data to be included.
     *
     * @param \Exception $e The caught exception instance
     * @param string $type Error type classification (e.g., 'validation', 'connection', 'timeout')
     * @param string $source Source of the error (typically the method name where exception occurred)
     * @param array $additionalData Additional contextual data to include with the error (optional)
     * @return void
     */
    protected function handleContextError(\Exception $e, string $type, string $source, array $additionalData = []): void
    {
        $errorManager = ErrorManager::getInstance();

        // Basic error data
        $errorData = [
            'type' => $type,         // Type of the error
            'message' => $e->getMessage(), // Error message from exception
            'source' => $source,     // Source method where error occurred
            'url' => $this->browser === null ? null : $this->getBrowser()->getCurrentUrlWithHash(), // Current URL with hash
        ];

        // Add additional data if provided
        if (!empty($additionalData)) {
            $errorData = array_merge($errorData, $additionalData);
        }

        // Add the error to ErrorManager
        $errorManager->addError($errorData, 'UI5BrowserContext');
    }

    protected function explodeList(string $list): array
    {
        return array_map('trim', explode(',', $list));
    }

    /**
     * Example
     *
     * ```
     * Given I log in ...
     * When I look at table 1
     * Then It works as shown below
     * Column Caption | Filter Caption | Button Caption
     *
     * ```
     * @Then It works as shown below
     * | :Column Caption | :Filter Caption | :Button Caption |
     *
     * @param TableNode $fields Table with field names and values
     * @return void
     */
    public function itWorksAsShown(TableNode $fields): void
    {
        $node = $this->getBrowser()->getFocusedNode();
        Assert::assertInstanceOf(UI5DataTableNode::class, $node, 'Focused node is not a data table');
        $logbook = new MarkdownLogBook($node->getCaption());
        $logbook->setIndentActive(1);
        DatabaseFormatter::addTestLogbook($logbook);
        $result = $node->itWorksAsShown($fields, $logbook);
        if ($result->isFailed()) {
            throw new RuntimeException(
                'Widget "' . ($node->getCaption() ?? $node->getWidgetType()) . '" did not work as expected: ' . ($result->getException()?->getMessage() ?? 'see substeps for details')
            );
        }
    }

    /**
     * Example
     *
     * ```
     * Given I log in ...
     * When I look at table 1
     * Then It works as expected
     * ```
     * @Then It works as expected
     *
     * @return void
     */
    public function itWorksAsExpected(): void
    {
        $node = $this->getBrowser()->getFocusedNode();
        $logbook = new MarkdownLogBook($node->getCaption());
        $logbook->setIndentActive(1);
        DatabaseFormatter::addTestLogbook($logbook);
        $result = $node->checkWorksAsExpected($logbook);
        if ($result->isFailed()) {
            throw new RuntimeException(
                'Widget "' . ($node->getCaption() ?? $node->getWidgetType()) . '" did not work as expected: ' . ($result->getException()?->getMessage() ?? 'see substeps for details')
            );
        }
    }

    /**
     * Centralized navigation helper.
     *
     * This method is the single source of truth for:
     *  1) DB/report logging of visited pages
     *  2) actual browser navigation
     *  3) UI5Browser re-initialization after navigation
     *
     * @param string $pageAlias
     * @throws \Exception
     */
    private function navigateToPageAlias(string $pageAlias): void
    {
        $this->getEventDispatcher()->dispatch(new AfterPageVisited($pageAlias));
        $this->lastPageAlias = $pageAlias;
        
        // Navigate to the page using Mink's path navigation
        $url = $pageAlias . '.html';
        $this->visitPath('/' . $url);
        $this->logDebug("Debug - New page is loading: {$url}\n");

        // Initialize the UI5Browser with the current session and URL
        $this->browser = new UI5Browser(
            $this->getWorkbench(),
            $this->getSession(),
            $this->getEventDispatcher(),
            $url,
            $this->getLocale()
        );
        $this->wireBrowserCallbacks();
    }

    private function wireBrowserCallbacks(): void
    {
        $this->browser->setNavigator(function (string $pageAlias): void {
            $this->navigateToPageAlias($pageAlias);
        });

        $this->browser->setScreenshotFn(function () {
            $this->captureScreenshot();
        });
        
        // Bridges Chrome recovery from deep node classes back to the context.
        $this->browser->setChromeRecoveryFn(function (string $targetPageAlias): void {
            $this->recoverChrome($targetPageAlias);
        });
    }

    /**
     * @return EventDispatcherInterface
     */
    protected function getEventDispatcher() : EventDispatcherInterface
    {
        return DatabaseFormatter::getEventDispatcher();
    }

    /**
     * Overrides Mink's visitPath to add retry logic for transient Chrome WebSocket
     * disconnections that can occur when the server is slow or Chrome's render
     * process is under heavy load during page navigation.
     *
     * Any caller within the framework automatically benefits from this retry
     * without needing to implement it themselves — visitPath is the single
     * point of navigation for all page transitions.
     *
     * @param string $path The relative path to visit
     * @throws \Throwable  The last exception if all attempts fail
     */
    public function visitPath($path, $sessionName = null, int $maxAttempts = 2): void
    {
        $attempt = 0;
        while (true) {
            try {
                // Wait for any pending operations before navigating to ensure the
                // browser is in a clean state. Skipped on the first visit because
                // the browser is not yet initialised at that point.
                if ($this->browser !== null) {
                    $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);
                }
                parent::visitPath($path);
                return;
            } catch (\Throwable $e) {
                if (++$attempt >= $maxAttempts) {
                    throw new BrowserDriverException($this->getSession(), 'Cannot open path "' . $path . '" in browser after ' . $attempt . ' attempts.', null, $e, $this->browser);
                }
                // Chrome WebSocket dropped during navigation — wait and retry
                sleep(3);
            }
        }
    }

    /**
     * Recovers from a hung Chrome process and resumes testing at a specific page.
     *
     * When Chrome's CDP connection is lost mid-test (detected via ChromeHangException),
     * simply retrying the last action is not enough — the browser process itself must
     * be restarted. This method coordinates the full recovery sequence:
     *
     *  1. Instructs ChromeManager to terminate the stale Chrome process and start a
     *     fresh one on the same port.
     *  2. Restarts the Mink session so it connects to the new Chrome instance.
     *  3. Re-authenticates using the credentials saved by the most recent
     *     iLogInToPage() call, because the new Chrome has no session cookies.
     *  4. Navigates directly to the target page by URL, bypassing the tile overview
     *     and the back-button navigation that would normally be needed to reach it.
     *
     * Direct URL navigation (step 4) is intentional: navigateToPageAlias() uses a
     * full page load rather than the tile click + back-button flow, so Chrome starts
     * each retry with a clean navigation stack.
     *
     * @param string $targetPageAlias The alias of the page to open after recovery
     *                                (typically the tile page that was being tested
     *                                when Chrome hung).
     * @throws RuntimeException If no login parameters are available (recoverChrome()
     *                           was called before iLogInToPage() ever ran).
     */
    public function recoverChrome(string $targetPageAlias): void
    {
        if ($this->lastLoginUrl === null) {
            throw new RuntimeException(
                'Cannot recover Chrome: no login parameters stored. '
                . 'Ensure iLogInToPage() was called before the test started.'
            );
        }

        // Step 1: Restart the Chrome process via ChromeManager.
        ChromeManager::getInstance()->restart();

        // Step 2: Reconnect the Mink session to the freshly started Chrome.
        $this->getSession()->restart();

        // Step 3: Re-authenticate the BROWSER only — replay just the login form with the values
        // cached on the first login. We are continuing the same scenario, so the DB user/roles/
        // locale setup and the process-side authentication from the original iLogInToPage() are
        // still valid; only the fresh Chrome lost its cookies/session. We deliberately do NOT
        // call setupUser() again, which would re-bump the USER_AUTHENTICATOR row the browser
        // login already updated and fail with an optimistic-lock "changed in the meantime" error.
        $this->browserLogin(
            $this->lastLoginUrl,
            $this->lastLoginTabCaption,
            $this->lastLoginButtonCaption,
            $this->lastLoginFields,
            $this->lastLoginUserRoles ?? []
        );

        // Step 4: Navigate directly to the target page without going via the tile
        // overview, so no back-button history needs to be rebuilt.
        $this->navigateToPageAlias($targetPageAlias);
    }
    
    /**
     * Makes sure a usable Chrome exists BEFORE the next step runs, restarting and re-authenticating
     * it if the current one is gone.
     *
     * WHY PROACTIVE INSTEAD OF REACTIVE: until now a dead browser was only noticed when some call
     * crashed into it. If that call happened inside a step, the AfterStep hook could still trigger a
     * restart. But Mink manages its sessions with its OWN hooks (reset/stop between scenarios), and a
     * socket exception thrown there escapes every guard this context owns - Behat then dies with exit
     * code 255 and the whole lane, including its DB recording, is lost. Probing liveness before the
     * step means a dead Chrome is replaced while we are still inside code we control.
     *
     * WHY IT MUST NEVER THROW: it runs from the BeforeStep hook, where an uncaught exception kills
     * the Behat process. A failed recovery is logged and the step is allowed to run and fail
     * normally, which is strictly better than aborting the run.
     */
    private function ensureChromeAlive(): void
    {
        try {
            $manager = ChromeManager::getInstance();

            // WHY THE PORT AND NOT THE PID: the PID is resolved from netstat at launch and can be
            // null even for a perfectly healthy Chrome (netstat race), while stop() clears it as
            // well. Gating on the PID therefore silently disables the liveness probe for the rest
            // of the lane. The port is set by start() unconditionally and is the same identity
            // isAlive() probes, so it is the only correct "has Chrome ever been started" marker.
            if ($manager->getPort() === null) {
                return;
            }

            if ($manager->isAlive()) {
                return;
            }

            $this->logDebug('Chrome is not reachable before the next step — restarting it.');

            // No login has happened yet in this scenario, so there is nothing to replay: bring up a
            // fresh Chrome and reattach the session. Doing the full recoverChrome() here would throw,
            // because it requires cached login parameters that do not exist yet.
            if ($this->lastLoginUrl === null) {
                ChromeManager::getInstance()->restart();
                // stop() talks to the dead browser and will normally fail — that failure is expected
                // and irrelevant, the point is to force the session out of its stale state so that
                // start() opens a new WebSocket to the new process.
                try {
                    $this->getSession()->stop();
                } catch (\Throwable $ignored) {}
                $this->getSession()->start();
                $this->logDebug('Chrome restarted before login — session reattached.');
                return;
            }

            // A login already happened: the new Chrome has no cookies, so the full recovery sequence
            // (restart, session restart, browser-side re-login, direct navigation) is required.
            $this->recoverChrome($this->lastPageAlias ?? $this->lastLoginUrl);
            $this->logDebug('Chrome recovered before the step.');

        } catch (\Throwable $e) {
            // Recovery failed. Log loudly, but let the step run: it will fail with the real browser
            // error and go through the normal failed-step reporting instead of aborting the lane.
            $this->logDebug('ensureChromeAlive failed: ' . $e->getMessage());
            try {
                $this->getWorkbench()->getLogger()->logException(new RuntimeException(
                    'Chrome could not be revived before the step: ' . $e->getMessage(),
                    null,
                    $e
                ));
            } catch (\Throwable $ignored) {}
        }
    }
}