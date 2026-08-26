<?php
namespace axenox\BDT\Tests\Behat\Contexts\UI5Facade;

use axenox\BDT\Behat\Common\ErrorManager;
use axenox\BDT\Behat\Contexts\UI5Facade\ChromeManager;
use axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5AbstractNode;
use axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5ContainerNode;
use axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5DataNode;
use axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5MenuButtonNode;
use axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5PageNode;
use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\BDT\Behat\DatabaseFormatter\DatabaseFormatter;
use axenox\BDT\Behat\Events\AfterPageVisited;
use axenox\BDT\Behat\TwigFormatter\Context\BehatFormatterContext;
use axenox\BDT\Common\Installer\TestDataInstaller;
use axenox\BDT\Exceptions\BrowserDriverException;
use axenox\BDT\Interfaces\FacadeNodeInterface;
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
    
    /**
     * visitPath() retry tuning. A dropped CDP/WebSocket during navigation is
     * usually transient and clears on its own within a few seconds (observed:
     * the first example of a scenario outline fails while later examples on the
     * SAME Chrome succeed with no restart). We therefore give the connection a
     * widening in-place retry window instead of failing after a single short wait.
     */
    private const VISIT_RETRY_MAX_ATTEMPTS = 3;

    /** Base backoff between visitPath() retries; grows linearly per attempt. */
    private const VISIT_RETRY_BASE_DELAY_MS = 3000;

    /**
     * Random jitter added on top of the backoff. Its only job is to
     * de-synchronise parallel lanes: RunParallel runs one Chrome per feature, so
     * a server hiccup makes every lane hit visitPath() and retry in lockstep,
     * re-colliding at the same instant. A small random offset spreads those
     * retries apart. Irrelevant for a single lane.
     */
    private const VISIT_RETRY_JITTER_MAX_MS = 1000;
    
    /**
     * WHY NULLABLE WITH AN EXPLICIT DEFAULT: a typed property declared without a default value is
     * "uninitialized", not null. Reading it before the first assignment raises a fatal Error, so the
     * "has the browser been created yet?" guards in beforeScenario(), prepareBeforeStep() and
     * completeAfterStep() would crash on exactly the case they exist to protect against - the very
     * first step of a run, before iLogInToPage()/visitPath() ever built a UI5Browser. It must also be
     * genuinely nullable because browserLogin() resets it to null to force a rebuild after a stale
     * session was detected.
     *
     * @var UI5Browser|null
     */
    private ?UI5Browser $browser = null;
    private string $scenarioName;

    private ?Workbench $workbench = null;
    private bool $debug = false;
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
        $this->workbench = new Workbench();
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
        if ($this->browser === null) {
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

            $this->getBrowser()->getErrorDetector()->installHttpInterceptor();

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
        if ($this->browser === null) {
            return;
        }

        try {
            // Wait for all pending UI5 operations to finish
            $this->getBrowser()->handleStepWaitOperations(true);

            // Check for any errors that occurred during the step
            $this->browser->getErrorDetector()->assertNoErrors();

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

        if (!empty($this->browser)) {
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
     * Checks that a page actually loaded and is not blank.
     *
     * Use this as a simple sanity check right after opening or navigating to a page. It
     * confirms the browser received real content instead of an empty response. It does not
     * look for any specific text or widget - only that "something" is there.
     *
     * Usage example:
     *
     *   Given I log in to the page "exface.core.logs.html" as "Support"
     *   Then I should see the page
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
     * Opens a page and logs in, optionally as a specific user role and in a specific language.
     *
     * This is usually the very first step of a scenario. It creates a temporary test user,
     * assigns the role(s) you name, opens the given page and fills in the login form for you.
     * Everything after this step runs as that user, so you can check what this kind of user is
     * allowed to see and do.
     *
     * - The page is given as its alias plus ".html", e.g. "exface.core.logs.html".
     * - "as :userRole" lets you test permissions. It needs to be alias with app alias of 
     *   the role like "exface.Core.SUPERUSER" or name of the role. You can pass several 
     *   roles separated by commas.
     * - "with locale :locale" switches the language/formatting, e.g. "de_DE" or "en_US".
     *
     * Usage examples:
     *
     *   Given I log in to the page "exface.core.logs.html" as "Support"
     *   Given I log in to the page "exface.core.logs.html" as "Support, Debugger"
     *   Given I log in to the page "exface.core.logs.html" as "Support" with locale "de_DE"
     *   Given I log in to the page "exface.core.logs.html" as "exface.Core.SUPERUSER"
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
     * Opens a page without logging in.
     *
     * Use this to jump to another page inside the same app once you are already logged in
     * (for example to move from a list page to a detail page). Give the page alias with or
     * without the ".html" ending - both work. If you still need to log in, use
     * "I log in to the page ..." instead.
     *
     * Usage example:
     *
     *   Given I log in to the page "my.app.start.html" as "Support"
     *   When I visit page "my.app.orders"
     *   Then I should see the page
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
     * Counts how many widgets of a certain type are on the page and checks the number matches.
     *
     * A "widget" is any building block of the user interface - a table, an input field, a form,
     * a button bar and so on. Use this step to make sure the page shows exactly as many of a
     * given widget type as you expect. You can narrow the count down with "with :caption" - the name
     * of the widget itself, i.e. its caption or the data object behind it.
     *
     * As a side effect, when exactly one matching widget is found it becomes the "focused"
     * widget, so follow-up steps like "it has filters:" or "I enter ... in filter ..." act on it.
     * Matching widgets are briefly highlighted in the browser to make debugging easier.
     *
     * Usage examples:
     *
     *   Then I see 1 widget of type "DataTable"
     *   Then I see 3 widgets of type "Input"
     *   Then I see 1 widget of type "DataTable" with "Caption of the Datatable"
     *
     * @Then I see :number widget of type ":widgetType"
     * @Then I see :number widgets of type ":widgetType"
     * @Then I see :number widget of type ":widgetType" with ":caption"
     * @Then I see :number widgets of type ":widgetType" with ":caption"
     *
     * @param int $number Expected number of widgets
     * @param string $widgetType Type of widget to look for
     * @param string|null $caption Optional caption of the widget or name/alias of its data object
     * @throws \Exception
     */
    public function iSeeWidgets(int $number, string $widgetType, string $caption = null): void
    {
        // Fetch widgets of the requested type, restricted to the given name if the step supplied one.
        // The name matches the widget's OWN identity - its caption, or the name/alias of the data object
        // behind it - not the area it sits in. WHY it matters that this is applied at all: the name used
        // to be accepted by the step and then dropped, so the "with :caption" variant counted widgets of
        // every object on the page and could report a pass for data the scenario never meant to check.
        $widgetNodes = $this->getBrowser()->findWidgetNodes($widgetType, 15, $caption);
        
        // Only reset the focus stack when this step actually establishes a new focus.
        // WHY: this step is primarily an assertion. Clearing the stack unconditionally while pushing
        // a new focus only when exactly one focusable widget is found means that any "I see N widgets"
        // step with N > 1 (e.g. "I see 2 widget of type Input" inside a dialog) silently destroys the
        // focus established earlier. A later filter or table step then finds an empty stack, and
        // getFocusedNode() falls back to a UI5PageNode, which does not implement the data widget API -
        // resulting in a fatal "call to undefined method" instead of acting on the intended widget.
        // Leaving the previous focus untouched keeps the underlying table focused, so it is still
        // available once a dialog has been closed.
        if (count($widgetNodes) === 1) {
            $firstNode = reset($widgetNodes);
            if ($firstNode->capturesFocus() === true) {
                $this->getBrowser()->clearFocusStack();
                $this->getBrowser()->focus($firstNode);
            }
        }

        // Assert the number of widgets.
        // The message names the filter "name" rather than "alias", because filterNodesByName() accepts
        // the caption and the object name just as well - reporting it as an alias sends whoever reads the
        // failure looking for a wrong alias when the caption they used simply did not match.
        Assert::assertCount(
            $number,
            $widgetNodes,
            sprintf(
                "Expected %d widget(s) of type '%s' with name '%s', but found %d",
                $number,
                $widgetType,
                $caption ?? 'N/A',
                count($widgetNodes)
            )
        );

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
     * Counts widgets of a type inside the area you are currently looking at.
     *
     * This is the "zoomed-in" version of "I see :number widget of type ...". It only counts
     * widgets within the currently focused container (for example a dialog you just opened),
     * instead of the whole page. Open a dialog or focus a widget first, then use this step to
     * verify what that container contains.
     *
     * Usage example:
     *
     *   When I click button "Details"
     *   Then I see 1 widget of type "Dialog"
     *   Then it has 2 widget of type "Input"
     *
     * @Then it has :number widget of type ":widgetType"
     * @Then it has :number widgets of type ":widgetType"
     *
     * @param int $number Expected number of widgets
     * @param string $widgetType Type of widget to look for
     */
    public function itHasWidgetsOfType(int $number, string $widgetType): void
    {
        $focusedNode = $this->getBrowser()->getFocusedNode();

        Assert::assertInstanceOf(
            UI5ContainerNode::class,
            $focusedNode,
            sprintf(
                'Cannot look for widgets inside the focused element: expected a container, but the focus is on a "%s". '
                . 'Focus a container (dialog, form, panel) before using this step.',
                $focusedNode->getWidgetType()
            )
        );

        $widgetNodes = $this->getBrowser()->findWidgetNodesInNode($focusedNode, $widgetType, $number, 15);

        Assert::assertCount(
            $number,
            $widgetNodes,
            sprintf(
                'Expected %d widget(s) of type "%s" inside the focused "%s", but found %d',
                $number,
                $widgetType,
                $focusedNode->getWidgetType(),
                count($widgetNodes)
            )
        );

        // Highlight the first few matches for visual debugging only - has no effect on the assertion above
        foreach (array_slice($widgetNodes, 0, 3) as $index => $node) {
            $this->getBrowser()->highlightWidget(
                $node->getNodeElement(),
                $widgetType,
                $index
            );
        }
    }

    /**
     * Fills in several input fields at once, using a table of field names and values.
     *
     * Handy for forms: instead of one "I type ... into ..." step per field, you list all the
     * fields and their values in a Gherkin table. Each field is found by its visible label
     * (caption). The table must have two columns named "widget_name" and "value".
     *
     * Usage example:
     *
     *   Then I fill the following fields:
     *     | widget_name | value          |
     *     | First name  | John           |
     *     | Last name   | Doe            |
     *     | E-mail      | john@doe.test  |
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
     * Checks that the widget you are looking at(focused) offers a given set of filters.
     *
     * Filters are the search fields above a table or list that let a user narrow down the data.
     * Use this step to confirm that the expected filters are available to the user. Focus a
     * table or filter area first (for example with "I look at table 1"). The filter names are
     * given as a comma-separated list and each one is briefly highlighted in the browser.
     *
     * Usage example:
     *
     *   When I look at table 1
     *   Then it has filters: Name, Created on, Status
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
     * Types a value into a named filter of the table you are looking at.
     *
     * This is how you "search" during a test: pick a filter by its label and give it a value.
     * It works both for plain text filters and for special ones like drop-downs (ComboBox),
     * where it will select the matching entry. Focus a table first (e.g. "I look at table 1").
     * Usually you follow this with a step that clicks the search/apply button or checks results.
     *
     * Usage example:
     *
     *   When I look at table 1
     *   And I enter "Berlin" in filter "City"
     *   And I click button "Search"
     *   Then I see "Berlin" in column "City"
     *
     * @When I enter :value in filter :filterName
     *
     * @param string $value The value to enter/select in the filter
     * @param string $filterName The name/label of the filter field
     * @throws RuntimeException if filter field cannot be found or interaction fails
     */
    public function iEnterInFilter(string $value, string $filterName): void
    {
        /* @var $focusedNode axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5DataNode */
        $focusedNode= $this->getBrowser()->getFocusedNode();
        // Guard the focused node's type before calling into the data widget API. WHY: getFocusedNode()
        // falls back to a UI5PageNode when the focus stack is empty, and UI5PageNode does not implement
        // findFilterByCaption(). Calling it raises a fatal "call to undefined method" that terminates the
        // whole Behat process, instead of failing this single step with a message that tells the author
        // which focus was actually active.
        Assert::assertInstanceOf(
            UI5DataNode::class,
            $focusedNode,
            'Cannot enter a filter value: no data widget is focused (current focus: "'
            . get_class($focusedNode)
            . '"). Focus a data widget first, e.g. with "I look at table 1".'
        );
        $focusedNode->findFilterByCaption($filterName)->setValueVisible($value);
    }

    /**
     * Checks that a given text appears in a named column of the table you are looking at.
     *
     * Use this after searching or filtering to confirm the results contain what you expect.
     * Note: every visible row of that column must match the text, so this is best used when
     * you have filtered the table down to matching rows. To check that just one row contains a
     * value, use "The column :columnName contains value :value" instead. Focus a table first
     * (e.g. "I look at table 1").
     *
     * Usage example:
     *
     *   When I look at table 1
     *   And I enter "Berlin" in filter "City"
     *   And I click button "Search"
     *   Then I see "Berlin" in column "City"
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
     * Resolves a 1-based table index on the current page to a typed table node.
     *
     * WHY A SEPARATE WRAPPER: getWidgetNodeByIndex() is declared to return the generic
     * FacadeNodeInterface, so the table-specific callers still need the narrowing. Doing it here
     * means a factory change that starts returning another node type for "DataTable" surfaces as
     * a readable failure at this point instead of a fatal "call to undefined method" much later.
     *
     * @param int $index 1-based index of the table on the page.
     * @throws RuntimeException If the table is not rendered or is not a DataTable node.
     * @return UI5DataTableNode
     */
    private function getDataTableNodeByIndex(int $index): UI5DataTableNode
    {
        $node = $this->getWidgetNodeByIndex('DataTable', $index);

        if (! $node instanceof UI5DataTableNode) {
            throw new RuntimeException(
                'Widget no. ' . $index . ' is not a DataTable, but a `' . get_class($node) . '`'
            );
        }

        return $node;
    }

    /**
     * Ensures a data widget is focused before a filter-scoped assertion runs and returns it typed.
     *
     * WHY THIS STAYS IN THE CONTEXT: the focus stack is a Behat concept - a node cannot know
     * whether the author called "I look at table 1". Guarding the type here turns a fatal
     * "call to undefined method" that would kill the whole Behat process into a single readable
     * failing step naming the focus that was actually active.
     *
     * @return UI5DataNode
     */
    private function getFocusedDataNode(): UI5DataNode
    {
        $node = $this->getBrowser()->getFocusedNode();
        Assert::assertInstanceOf(
            UI5DataNode::class,
            $node,
            'No data widget is focused (current focus: "'
            . ($node ? get_class($node) : 'none')
            . '"). Focus a data widget first, e.g. with "I look at table 1".'
        );
        return $node;
    }

    /**
     * Ensures a DataTable is focused before a table-scoped assertion runs and returns it typed.
     *
     * WHY THIS EXISTS: getFocusedNode() falls back to a UI5PageNode when the focus stack is
     * empty, and the column steps below call DataTable-only methods. Guarding the type here
     * turns a fatal "call to undefined method" that would kill the whole Behat process into a
     * single readable failing step that tells the author which focus was actually active.
     *
     * @return UI5DataTableNode
     */
    private function getFocusedDataTableNode(): UI5DataTableNode
    {
        $node = $this->getBrowser()->getFocusedNode();
        Assert::assertInstanceOf(
            UI5DataTableNode::class,
            $node,
            'No DataTable is focused (current focus: "'
            . ($node ? get_class($node) : 'none')
            . '"). Focus a table first, e.g. with "I look at table 1".'
        );
        return $node;
    }

    /**
     * Resolves the n-th rendered widget of a given type on the current page.
     *
     * WHY THIS EXISTS: focusing a widget, highlighting it and addressing it by ordinal from a
     * step were three separate copies of the same "find all widgets of this type, take the n-th,
     * complain if there are fewer" logic. Centralising it keeps the failure message identical
     * everywhere and means a change to how widgets are located (timeout, CSS, factory) has to be
     * made exactly once.
     *
     * @param string $widgetType        Widget type as understood by findWidgetNodes(), e.g. 'DataTable'.
     * @param int    $number            1-based position of the widget on the page.
     * @param int    $timeoutInSeconds  How long to wait for the widgets to appear.
     * @throws RuntimeException If no widget of that type is rendered or fewer than $number are.
     * @return FacadeNodeInterface
     */
    private function getWidgetNodeByIndex(string $widgetType, int $number, int $timeoutInSeconds = 15): FacadeNodeInterface
    {
        $nodes = $this->getBrowser()->findWidgetNodes($widgetType, $timeoutInSeconds);

        if (empty($nodes)) {
            throw new RuntimeException('No `' . $widgetType . '` found on page');
        }

        // Read via ?? instead of indexing directly: an out-of-range index would otherwise emit an
        // "undefined array key" warning before the check below ever reports the real problem.
        $node = $nodes[$number - 1] ?? null;
        if ($node === null) {
            throw new RuntimeException(
                '`' . $widgetType . '` no. ' . $number . ' not found. Only '
                . count($nodes) . ' available on the page.'
            );
        }

        return $node;
    }

    /**
     * Returns the captions of the currently rendered filters in DOM (visual) order.
     *
     * WHY THIS EXISTS: the filter order/visibility steps need the same focus guard and the
     * same DOM-ordered filter source. Centralising it keeps those steps short and guarantees
     * they all read filters the same way getFilters() renders them.
     *
     * @return string[] Trimmed, non-empty filter captions in UI order.
     */
    private function getVisibleFilterCaptionsInOrder(): array
    {
        $focusedNode = $this->getBrowser()->getFocusedNode();
        Assert::assertInstanceOf(
            UI5DataNode::class,
            $focusedNode,
            'No data widget is focused (current focus: "'
            . ($focusedNode ? get_class($focusedNode) : 'none')
            . '"). Focus a data widget first, e.g. with "I look at table 1".'
        );

        $captions = [];
        // min = 0: a widget legitimately may show no filters, and these steps must be able to
        // assert exactly that instead of throwing "too few filters found".
        foreach ($focusedNode->getFilters(0) as $filterNode) {
            $caption = trim($filterNode->getCaption());
            if ($caption !== '') {
                $captions[] = $caption;
            }
        }
        return $captions;
    }

    /**
     * Asserts that every expected caption is present in $actual and that the expected
     * captions appear in $actual in the given relative order.
     *
     * WHY relative order (subsequence) rather than strict full-list equality: a table
     * usually renders more filters/columns than a single scenario declares, so requiring
     * the actual list to equal the expected list verbatim would break the step whenever an
     * unrelated column is added. Checking that the listed items appear in the stated order
     * among the actually rendered ones keeps the assertion focused on what the author
     * declared while staying robust to surrounding filters/columns.
     *
     * @param string[] $expected
     * @param string[] $actual
     * @param string   $itemLabel Singular noun used in failure messages (e.g. "column").
     */
    private function assertDisplayedInOrder(array $expected, array $actual, string $itemLabel): void
    {
        // Report a missing item explicitly first: it is a clearer failure than the order
        // check turning the same problem into a confusing "wrong order" message.
        foreach ($expected as $item) {
            Assert::assertContains(
                $item,
                $actual,
                sprintf(
                    '%s "%s" is not displayed. Displayed %ss: %s',
                    ucfirst($itemLabel),
                    $item,
                    $itemLabel,
                    implode(', ', $actual)
                )
            );
        }

        // Walk the actual list once, advancing through the expected list whenever the next
        // expected item is met. Consuming all expected items means their relative order holds.
        $cursor = 0;
        foreach ($actual as $actualItem) {
            if ($cursor < count($expected) && $actualItem === $expected[$cursor]) {
                $cursor++;
            }
        }

        Assert::assertSame(
            count($expected),
            $cursor,
            sprintf(
                'The %ss are not displayed in the expected order. Expected order: %s. Actual order: %s',
                $itemLabel,
                implode(', ', $expected),
                implode(', ', $actual)
            )
        );
    }

    /**
     * Checks that filters appear left-to-right in the exact order you list.
     *
     * Unlike "it has filters:", which only checks that filters exist, this step pins down their
     * order on screen - useful after a layout change or personalisation. The table may contain
     * more filters than you list; this step only checks that the ones you name appear in the
     * stated order relative to each other. Focus a table first (e.g. "I look at table 1").
     *
     * Usage example:
     *
     *   When I look at table 1
     *   Then the filters are displayed in the following order Name, City, Status
     *
     * @Then the filters are displayed in the following order :filterList
     *
     * @param string $filterList Comma-separated filter captions in the expected order.
     */
    public function theFiltersAreDisplayedInTheFollowingOrder(string $filterList): void
    {
        $this->getFocusedDataNode()->assertFiltersDisplayedInOrder($this->explodeList($filterList));
    }

    /**
     * Checks that table columns appear left-to-right in the exact order you list.
     *
     * Unlike "it has columns:", which only checks that columns exist, this step pins down their
     * order on screen - useful after a personalisation or layout change. The table may contain
     * more columns than you list; this step only checks that the ones you name appear in the
     * stated order relative to each other. Focus a table first (e.g. "I look at table 1").
     *
     * Usage example:
     *
     *   When I look at table 1
     *   Then the columns are displayed in the following order Name, City, Created on
     *
     * @Then the columns are displayed in the following order :columnList
     *
     * @param string $columnList Comma-separated column captions in the expected order.
     */
    public function theColumnsAreDisplayedInTheFollowingOrder(string $columnList): void
    {
        $this->getFocusedDataNode()->assertColumnsDisplayedInOrder($this->explodeList($columnList));
    }

    /**
     * Checks that the listed columns are NOT shown in the table you are looking at.
     *
     * Use this to confirm that certain columns are hidden - for example because the logged-in
     * user's role is not allowed to see them, or a personalisation removed them. Focus a table
     * first (e.g. "I look at table 1"). List the columns you expect to be absent, separated by
     * commas.
     *
     * Usage example:
     *
     *   When I look at table 1
     *   Then I do not see columns Salary, Bonus
     *
     * @Then I do not see columns :columnList
     * @Then I do not see column :columnList
     *
     * @param string $columnList Comma-separated column captions expected to be absent.
     */
    public function iDoNotSeeColumns(string $columnList): void
    {
        $this->getFocusedDataTableNode()->assertColumnsNotDisplayed($this->explodeList($columnList));
    }

    /**
     * Checks that the listed filters are NOT shown in the widget you are looking at.
     *
     * The counterpart of "it has filters:". Use it to confirm that certain filters are hidden -
     * for example because the current user's role should not have them. Focus a table first
     * (e.g. "I look at table 1"). List the filters you expect to be absent, separated by commas.
     *
     * Usage example:
     *
     *   When I look at table 1
     *   Then I do not see filters Internal note, Cost center
     *
     * @Then I do not see filters :filterList
     * @Then I do not see filter :filterList
     *
     * @param string $filterList Comma-separated filter captions expected to be absent.
     */
    public function iDoNotSeeFilters(string $filterList): void
    {
        $this->getFocusedDataNode()->assertFiltersNotDisplayed($this->explodeList($filterList));
    }

    /**
     * Checks that every row of a named column contains the given value.
     *
     * Use this when you want to confirm a value shows up somewhere in a column, without
     * requiring every row to match. This is the "at least one row" counterpart to
     * "I see :text in column :columnName" (which requires all rows to match). Focus a table
     * first (e.g. "I look at table 1").
     *
     * Usage example:
     *
     *   When I look at table 1
     *   Then The column "Status" contains value "Open"
     *
     * @Then The column :columnName contains value :value
     *
     * @param string $columnName Caption of the column to inspect.
     * @param string $value      Value expected in at least one row of that column.
     */
    public function theColumnContainsValue(string $columnName, string $value): void
    {
        $cellValues = $this->getFocusedDataTableNode()->getColumnCellValues($columnName);

        $needle = trim($value);
        $found = in_array($needle, array_map('trim', $cellValues), true);

        Assert::assertTrue(
            $found,
            sprintf(
                'Column "%s" does not contain value "%s". Found values: %s',
                $columnName,
                $value,
                implode(' | ', $cellValues)
            )
        );
    }

    /**
     * Clicks a button by the text shown on it.
     *
     * This is the everyday "press this button" step. It first looks inside the widget you are
     * currently focused on and then, if needed, across the whole page, matching either the
     * button's visible text or its tooltip. If the button is disabled (greyed out) the step
     * fails with a clear message instead of silently doing nothing.
     *
     * Usage example:
     *
     *   When I look at table 1
     *   And I select table row 1
     *   And I click button "Edit"
     *
     * @When I click button ":caption"
     *
     * @param string $caption Text caption of the button to click
     * @throws RuntimeException If button cannot be found or clicked
     */
    public function iClickButton(string $caption): void
    {
        // Get the currently focused widget's node element
        $focusedNode = $this->getBrowser()->getFocusedNode();
        $widget = $focusedNode->getNodeElement();

        if (!$widget) {
            throw new RuntimeException("No focused widget found");
        }

        // First, try standard Mink named button search within the widget
        $button = $widget->find('named', ['button', $caption]);

        // Then the loose search (text/tooltip, case insensitive) - first inside the focused widget,
        // then page wide. Both used to be hand-written copies of the same loop; they now share
        // findButtonInScopeByCaption() so a change to what "matches the caption" means is made once.
        $button = $button ?? $this->getBrowser()->findButtonInScopeByCaption($widget, $caption);

        // Last resort: the toolbar overflow of the FOCUSED widget. WHY the instanceof: with nothing
        // focused, getFocusedNode() returns a UI5PageNode, which is not a UI5AbstractNode and offers
        // no overflow lookup at all. That is deliberate - opening a menu picked by guesswork would
        // click a same-named button of some other widget and the step would still turn green.
        if ($button === null && $focusedNode instanceof UI5AbstractNode) {
            $button = $focusedNode->findInOverflow(function (NodeElement $menu) use ($caption) {
                return $this->getBrowser()->findButtonInScopeByCaption($menu, $caption);
            });
        }

        if (!$button) {
            $hint = $focusedNode instanceof UI5AbstractNode
                ? ' - it is not in the widget, on the page or behind its toolbar overflow'
                : ' - no widget is focused, so the toolbar overflow was not searched. Focus one first,'
                . ' e.g. with "I look at table 1", or name the table in the step';
            throw new RuntimeException('Button "' . $caption . '" not found' . $hint);
        }

        // Make sure the button is not disabled before attempting to click it. WHY: UI5 silently
        // swallows clicks on disabled buttons - the driver reports a successful click, the step turns
        // green and nothing happened. WHY NOT ONLY THE `disabled` ATTRIBUTE: UI5 renders a disabled
        // sap.m.Button via aria-disabled plus the sapMBtnDisabled class and frequently without the
        // HTML attribute at all, so the attribute-only check let disabled buttons pass as clickable.
        $buttonNode = UI5FacadeNodeFactory::createFromNodeElement($button, $this->getSession(), $this->browser);
        Assert::assertFalse(
            $buttonNode->checkDisabled(),
            'Button "' . $caption . '" is disabled and cannot be clicked'
        );

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
     * Switches to a tab by the text on its header.
     *
     * Many pages and dialogs group content into tabs. Use this step to open a specific tab by
     * its caption before checking or filling what is inside it.
     *
     * Usage example:
     *
     *   When I click tab "Addresses"
     *   Then it has 2 widget of type "Input"
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
     * Types a value into a single input field, found by its label.
     *
     * Use this for one field at a time. The field is located by the caption shown next to it.
     * To fill many fields at once, use "I fill the following fields:" instead.
     *
     * Usage example:
     *
     *   When I type "John" into "First name"
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
     * Picks a widget of a given type so that later "it has..." steps act on it.
     *
     * Think of this as pointing at one specific element on the page. Once "looked at", that
     * widget becomes the context for follow-up checks like "it has filters:" or
     * "it has a column ...". Use "the first" for the first one, or "no. N" to pick the Nth
     * widget of that type (counting from 1). The chosen widget is highlighted.
     *
     * Usage examples:
     *
     *   When I look at the first "DataTable"
     *   When I look at "Form" no. 2
     *
     * @When I look at the first ":widgetType"
     * @When I look at ":widgetType" no. :number
     *
     * @param string $widgetType Type of widget to focus
     * @param int $number Position of the widget (1-based index)
     * @return void
     * @throws RuntimeException If the page has fewer widgets of that type than requested
     * @throws \Exception
     */
    public function iLookAtWidget(string $widgetType, int $number = 1): void
    {
        // Set focus to this widget so the subsequent "it has..." steps have a context
        $this->getBrowser()->focus($this->getWidgetNodeByIndex($widgetType, $number));
    }

    /**
     * Checks that one or more buttons are visible on the page.
     *
     * Use this to confirm the user has access to certain actions. You can name a single button
     * or several separated by commas. Add "on the :tableName" to look for the buttons only in
     * the toolbar of the named table (or dialog/panel) - name it the way the app shows it, or
     * by the data object behind it. Each found button is briefly highlighted.
     *
     * Usage examples:
     *
     *   Then I see button "Save"
     *   Then I see buttons "Save, Cancel, Delete"
     *   Then I see button "Delete" on the "Materialbedarfsliste"
     *
     * @Then I see button :buttonText
     * @Then I see buttons :buttonText
     * @Then I see a button with text :buttonText
     * @Then I see button :buttonText on the :tableName
     * @Then I see buttons :buttonText on the :tableName
     * @Then I should see button :buttonText
     * @Then I should see buttons :buttonText
     * @Then I should see a button with text :buttonText
     * @Then I should see button :buttonText at the :tableName
     *
     * @param string $buttonText The text of the button to find
     * @param string|null $tableName Optional caption or object of the widget to search in
     * @throws \Exception If button is not found
     */
    public function iSeeButton(string $buttonText, string $tableName = null)
    {
        // Resolve the search scope ONCE, before the button loop - the scope depends on the step,
        // not on the individual button, and resolving it per button would repeat the DOM type scan
        // for every entry of a comma separated list.
        // WHY at all: the "at the :tableName" wording used to be parsed and then thrown away, so the
        // step searched the whole page. A button that exists somewhere else entirely - e.g. in the
        // toolbar of another table or in a still-open dialog - then satisfied an assertion that was
        // written to check exactly one widget.
        $scopeNodes = [];
        $tableName = $tableName === null ? null : trim($tableName);
        if ($tableName !== null && $tableName !== '') {
            $scopeNodes = $this->getBrowser()->findWidgetNodesByName($tableName, 15);
            Assert::assertNotEmpty(
                $scopeNodes,
                'Cannot find a widget named "' . $tableName . '" to look for buttons in.'
            );
        }

        $buttons = $this->explodeList($buttonText);
        // WHY a separate loop variable: reusing $buttonText here would overwrite the parameter and
        // make every message built after the first iteration report the wrong step arguments.
        foreach ($buttons as $buttonCaption) {
            if (empty($scopeNodes)) {
                $button = $this->getBrowser()->findButtonByCaption($buttonCaption);
            } else {
                // Several widgets may legitimately carry the same name - a table and the table inside
                // the dialog it opens, for example. Accept the first scope that actually contains the
                // button instead of failing on ambiguity: the assertion stays strict, because a button
                // outside ALL matching widgets still fails.
                $button = null;
                foreach ($scopeNodes as $scopeNode) {
                    $button = $this->getBrowser()->findButtonByCaption($buttonCaption, $scopeNode->getNodeElement());
                    if ($button !== null) {
                        break;
                    }
                }
            }

            Assert::assertNotNull(
                $button,
                $tableName === null || $tableName === ''
                    ? "Button with text '{$buttonCaption}' not found."
                    : "Button with text '{$buttonCaption}' not found at '{$tableName}'."
            );

            // Highlight the button for debugging purposes
            $this->getBrowser()->highlightWidget($button, 'Button', 0);
        }
    }

    /**
     * Checks that a column of an editable spreadsheet is read-only (not editable).
     *
     * A DataSpreadSheet is the Excel-like editing grid used in some pages. Use this step to
     * confirm that a given column cannot be edited by the user - every cell in that column must
     * be marked read-only, otherwise the step fails and tells you which row was editable.
     *
     * Usage example:
     *
     *   Then the column "ID" in data spreadsheet should be disabled
     *
     * @Then the column :columnName in data spreadsheet should be disabled
     *
     * @param string $columnName Caption of the spreadsheet column to check
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
     * Fills in cells of one row of an editable spreadsheet.
     *
     * Works on a DataSpreadSheet (the Excel-like editing grid). Choose the row by number, or
     * use "the last row" to target the last one (handy after adding a new empty row). Provide
     * a Gherkin table with two columns, "Column" and "Value", listing which cell to fill in
     * that row and with what. Drop-down cells are handled by selecting the matching entry.
     *
     * Usage examples:
     *
     *   When I fill the row 2 of data spreadsheet with:
     *     | Column   | Value      |
     *     | Name     | Widget A   |
     *     | Quantity | 10         |
     *
     *   When I fill the last row of data spreadsheet with:
     *     | Column   | Value      |
     *     | Name     | Widget B   |
     *
     * @When I fill the row :rowIndex of data spreadsheet with:
     * @When I fill the last row of data spreadsheet with:
     *
     * @param TableNode $table Rows with "Column" and "Value" pairs to fill in
     * @param int|string|null $rowIndex 1-based row number, or null for the last row
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
     * Checks that the table you are looking at has one or more named columns.
     *
     * Use this to confirm expected columns are present. Name a single column or several
     * separated by commas. Focus a table first (e.g. "I look at table 1"). Each found column
     * is briefly highlighted.
     *
     * Usage examples:
     *
     *   When I look at table 1
     *   Then it has a column "Name"
     *   Then it has columns "Name, City, Status"
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
     * Checks that some text appears anywhere in the table you are currently looking at.
     *
     * A quick, broad check: it scans all cells of the focused table and passes if the text
     * turns up in any of them. Unlike "I see ... in column ...", it does not care which
     * column the text is in - but it does need a table to be focused first, e.g. with
     * "I look at table 1". Without a focused table the step fails with a focus error rather
     * than a "text not found" error.
     *
     * Usage example:
     *
     *   When I look at table 1
     *   Then the DataTable contains "Berlin"
     *
     * @Then the DataTable contains :text
     *
     * @param string $text Text to search for in the focused DataTable
     */
    public function theDataTableContains(string $text): void
    {
        // Find all DataTable widgets on the page
        $dataTable = $this->getFocusedDataTableNode();

        // Search for text in all table cells
        $found = false;
        $cells = $dataTable->getNodeElement()->findAll('css', 'td');
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
     * Checks that the table you are looking at shows at least one row of data.
     *
     * Useful after a search or filter to confirm results came back. An explicit "no data"
     * message also counts as a valid, expected state (empty result). Focus a table first
     * (e.g. "I look at table 1").
     *
     * Usage example:
     *
     *   When I look at table 1
     *   And I enter "Berlin" in filter "City"
     *   And I click button "Search"
     *   Then I see at least one data item
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
     * Opens several pages in a row and checks that each one loads.
     *
     * A convenient smoke test: give a Gherkin table of page URLs (column named "url") and this
     * step visits each one and verifies it is not blank. Often paired with
     * "all pages should load successfully" to confirm none of them produced errors.
     *
     * Usage example:
     *
     *   When I visit the following pages:
     *     | url                  |
     *     | my.app.orders.html   |
     *     | my.app.customers.html|
     *   Then all pages should load successfully
     *
     * @When I visit the following pages:
     *
     * @param TableNode $table Table of page URLs to visit (column "url")
     */
    public function iVisitTheFollowingPages(TableNode $table): void
    {
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
     * Checks that the pages just visited loaded without any errors.
     *
     * Use this after "I visit the following pages:". It fails if any error was detected while
     * loading, or if the UI framework did not finish rendering properly. A good final guard for
     * a page smoke test.
     *
     * Usage example:
     *
     *   When I visit the following pages:
     *     | url                |
     *     | my.app.orders.html |
     *   Then all pages should load successfully
     *
     * @Then all pages should load successfully
     */
    public function allPagesShouldLoadSuccessfully(): void
    {
        // Verify no errors in current session
        $this->browser->getErrorDetector()->assertNoErrors();

        // Verify UI5 is in stable state
        $isStable = $this->getSession()->evaluateScript(
            'return sap.ui.getCore().isThemeApplied() && !sap.ui.getCore().getUIDirty()'
        );

        if (!$isStable) {
            throw new RuntimeException('UI5 framework is not in stable state after page navigation');
        }
    }

    /**
     * Picks one of several tables on the page by its position.
     *
     * When a page shows more than one table, use this to say which one the following steps
     * (selecting rows, checking columns, entering filters, etc.) should work on. Tables are
     * counted from 1 in the order they appear on the page. The chosen table is highlighted.
     *
     * Usage example:
     *
     *   When I look at table 2
     *   And I select table row 1
     *
     * @When I look at table :index
     *
     * @param int $index The 1-based index of the table to focus on
     * @throws RuntimeException If the table cannot be found
     */
    public function iLookAtTable(int $index): void
    {
        $table = $this->getDataTableNodeByIndex($index);
        $this->getBrowser()->highlightWidget($table->getNodeElement(), 'DataTable', $index - 1);
        // Focus the selected table
        $this->getBrowser()->focus($table);
    }

    /**
     * Selects (ticks) a row in the table you are looking at.
     *
     * Selecting a row is often required before pressing a button that acts on it, such as
     * "Edit" or "Delete". Rows are counted from 1. Focus a table first (e.g. "I look at
     * table 1"). The step waits for the UI to react and then confirms the row is selected.
     *
     * Usage example:
     *
     *   When I look at table 1
     *   And I select table row 1
     *   And I click button "Edit"
     *
     * @When I select table row :rowNumber
     *
     * @param int $rowNumber The 1-based number of the row to select
     */
    public function iSelectTableRow(int $rowNumber)
    {
        // Use the focused table (if there is no error, throw an error)
        /** @var \axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5DataTableNode $table */
        $table = $this->getFocusedDataTableNode();
        $table->selectRow($rowNumber);

        // Wait for UI 
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);

        Assert::assertTrue($table->isRowSelected($rowNumber), "Failed to select row {$rowNumber}");
    }

    /**
     * Clicks a button belonging to a specific table on the page.
     *
     * Many pages show several tables whose toolbars have buttons with the same text
     * ("Export", "Edit", ...). The plain "I click button" step cannot tell them apart, so this
     * step lets you name which table the button belongs to. Tables are counted from 1, in the
     * order they appear on the page.
     *
     * Good to know:
     * - The button is looked for inside the named table only. There is no page-wide fallback,
     *   so the step will not accidentally click a same-named button of another table.
     * - The table number can be written as a plain number or as an ordinal in quotes - both
     *   2 and "2." select the second table.
     * - The step fails with a clear message if the named table does not exist, if the button
     *   is not found, if it is hidden, or if it is disabled (greyed out).
     *
     * Usage examples:
     *
     *   When I look at table 2
     *   And I select table row 1
     *   And I click button "Export" on the 2 table
     *
     *   When I click button "Neu" on the "2." table
     *
     * @When I click button :caption on the :tableIndex table
     *
     * @param string $buttonCaption Text of the button to click
     * @param int|string $tableIndex 1-based index of the table whose button to click (e.g. 2 or "2.")
     */
    public function iClickButtonOnTable(string $buttonCaption, $tableIndex = 1)
    {
        // Wait for all pending operations to complete
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);

        // Resolve the table the button belongs to.
        // WHY strict index validation: an out-of-range or non-numeric index used to fall through
        // to the first table, so a scenario naming a table that does not exist still went green.
        // parseTableIndex()/findTableElementByIndex() now own both the notation handling and the
        // range check, so this step accepts exactly the same index notations as every other
        // table-scoped step.
        $tableNumber = $this->parseTableIndex($tableIndex) ?? 1;
        $targetTable = $this->findTableElementByIndex($tableNumber);

        // Find the button
        $button = $this->getBrowser()->findButtonByCaption($buttonCaption, $targetTable);
        if ($button === null) {
            // The button may have been moved into this table's toolbar overflow popover, where no
            // DOM search inside the table element can reach it. Scoping the fallback to the node of
            // THIS table keeps the guarantee documented above: no page-wide search, so a same-named
            // button of another table can still never be clicked by accident.
            $tableNode = UI5FacadeNodeFactory::createFromNodeElement($targetTable, $this->getSession(), $this->getBrowser());
            if ($tableNode instanceof UI5AbstractNode) {
                $button = $tableNode->findInOverflow(function (NodeElement $menu) use ($buttonCaption) {
                    return $this->getBrowser()->findButtonInScopeByCaption($menu, $buttonCaption);
                });
            }
        }
        // Check and click the button
        Assert::assertNotNull($button, "Button '$buttonCaption' not found");
        Assert::assertTrue($button->isVisible(), "Button '$buttonCaption' is not visible");
        // Make sure the button is not disabled before attempting to click it.
        // WHY: UI5 silently swallows clicks on disabled buttons - the driver reports a
        // successful click, the step turns green and nothing actually happened. Failing here
        // turns that false green into a clear message. Mirrors the identical check in
        // iClickButton() and UI5ButtonNode::checkDisabled(), which both treat the presence of
        // the "disabled" attribute as the button being disabled.
        $buttonNode = UI5FacadeNodeFactory::createFromNodeElement($button, $this->getSession(), $this->browser);
        Assert::assertFalse(
            $buttonNode->checkDisabled(),
            'Button "' . $buttonCaption . '" is disabled and cannot be clicked'
        );
        $this->getBrowser()->highlightWidget($button, 'Button', 0);
        try {
            $button->click();

            // Short wait after clicking
            $this->getSession()->wait(1000);
        } catch (\Throwable $e) {
            throw new BrowserDriverException(
                $this->getSession(),
                'Cannot click button "' . $buttonCaption . '" on table ' . $tableNumber . '. ' . $e->getMessage(),
                null,
                $e,
                $this->browser
            );
        }
    }

    /**
     * Opens a menu button and clicks one of its entries, in a single step.
     *
     * Some buttons open a small drop-down menu of further actions (a "MenuButton"). Because
     * those menu entries are not ordinary buttons, the normal "I click button" step cannot
     * reach them. This step opens the named menu and then clicks the named entry inside it.
     * If a table is currently focused, its menu is preferred when several menus share a name.
     *
     * Usage example:
     *
     *   When I look at table 1
     *   And I click button "Print" in button menu "More actions"
     *
     * @When I click button :item in button menu :menu
     *
     * @param string $item Visible caption of the menu entry to click
     * @param string $menu Visible caption of the MenuButton that opens the menu
     * @throws RuntimeException If the MenuButton or the entry cannot be found
     */
    public function iClickMenuItemInMenu(string $item, string $menu): void
    {
        // Prefer the focused widget (e.g. the table the scenario is currently working
        // on) so that, when several tables expose a menu button with the same caption,
        // the right one is targeted - and so the menu button stays coherent with the
        // table where rows were selected. Fall back to a page-wide search when nothing
        // is focused or the focused widget does not contain the menu button.
        $scope = null;
        $focused = $this->getBrowser()->getFocusedNode();
        if (! $focused instanceof UI5PageNode) {
            $scope = $focused->getNodeElement();
        }

        $menuEl = $scope !== null ? $this->getBrowser()->findButtonByCaption($menu, $scope) : null;
        if ($menuEl === null) {
            // Page-wide fallback: nothing focused, or the menu button lives outside the
            // focused widget's subtree.
            $menuEl = $this->getBrowser()->findButtonByCaption($menu);
        }
        Assert::assertNotNull($menuEl, 'Menu button "' . $menu . '" not found.');

        $menuNode = UI5FacadeNodeFactory::createFromNodeElement($menuEl, $this->getSession(), $this->getBrowser());
        Assert::assertInstanceOf(UI5MenuButtonNode::class, $menuNode, 'Button "' . $menu . '" is not a MenuButton.');

        $menuNode->clickItem($item);
    }

    /**
     * Clicks the "..." overflow button that reveals a table's extra actions.
     *
     * When a table toolbar is too narrow to show all its buttons, the remaining ones are tucked
     * behind an overflow ("...") button. Use this step to open that menu. You can name the table
     * by position, or omit it to use the table you are currently looking at.
     *
     * Usage examples:
     *
     *   When I look at table 1
     *   Then I click the overflow button
     *
     *   Then I click the overflow button on the 2 table
     *   Then I click the overflow button on the "2." table
     *
     * @Then I click the overflow button on the :tableIndex table
     * @Then I click the overflow button
     *
     * @param int|string|null $tableIndex 1-based index of the table (e.g. 2 or "2."), optional
     * @return void
     */
    public function iClickTableOverflowButton($tableIndex = null): void
    {
        $table = $tableIndex === null
            ? $this->getFocusedDataTableNode()
            : $this->getDataTableNodeByIndex((int) filter_var($tableIndex, FILTER_SANITIZE_NUMBER_INT));

        $table->clickOverflowButton();
    }

    /**
     * Clicks an entry inside a table's overflow ("...") menu.
     *
     * When a table toolbar is too narrow to show all its buttons, the ones that don't fit are
     * tucked away behind an overflow ("...") button. This step opens that menu and clicks the
     * entry you name - all in one go - so you don't need a separate "open the overflow" step.
     *
     * Good to know:
     * - Without a table number, the step uses the table you are currently looking at (focus a
     *   table first, e.g. "I look at table 1").
     * - With a table number, it targets that table directly. Tables are counted from 1 in the
     *   order they appear on the page, and the number may be written plainly or as an ordinal
     *   in quotes - both 2 and "2." select the second table.
     *
     * Usage examples:
     *
     *   When I look at table 1
     *   And I click "Export" in the overflow menu
     *
     *   When I click "Delete" in the overflow menu on 2 table
     *
     * @When I click :caption in the overflow menu
     * @When I click :caption in the overflow menu on :tableIndex table
     *
     * @param string $caption Caption of the menu entry to click.
     * @param int|string|null $tableIndex 1-based table index (e.g. 2 or "2."), optional.
     */
    public function iClickOverflowMenuItem(string $caption, $tableIndex = null): void
    {
        $table = $tableIndex === null
            ? $this->getFocusedDataTableNode()
            : $this->getDataTableNodeByIndex((int) filter_var($tableIndex, FILTER_SANITIZE_NUMBER_INT));

        $table->clickOverflowMenuItem($caption);
    }

    /**
     * Checks that clicking an "export" action actually produced an Excel file.
     *
     * After triggering an export, use this step to confirm a real .xlsx file was downloaded and
     * is not empty. It waits up to 30 seconds for the download to finish.
     *
     * Usage example:
     *
     *   When I click button "Export to Excel"
     *   Then an XLSX file should be downloaded
     *
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

        throw new RuntimeException("XLSX file could not be downloaded or is empty.");
    }

    /**
     * Checks that the named tiles are shown on the page.
     *
     * Tiles are the clickable cards on a launchpad/home page. Use this to confirm the expected
     * tiles are present. Other tiles may also be present - this step only requires the ones you
     * name. List tile captions separated by commas.
     *
     * Usage example:
     *
     *   Then I see tiles "Orders, Customers, Reports"
     *
     * @Then I see tiles :tileNames
     *
     * @param string $tileNames Comma-separated list of expected tile captions
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
     * Checks that exactly the named tiles are shown - no more, no less.
     *
     * Stricter than "I see tiles": this step also fails if any tile other than the ones you
     * list is present. Great for verifying that a user role sees precisely the expected set of
     * launchpad tiles. List tile captions separated by commas.
     *
     * Usage example:
     *
     *   Then I only see tiles "Orders, Customers"
     *
     * @Then I only see tiles :tileNames
     *
     * @param string $tileNames Comma-separated list of the only tiles expected
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
     * Checks that the named buttons are NOT visible.
     *
     * Perfect for permission tests: confirm that a user without certain rights does not see
     * actions like "Delete" or "Approve". Name one or more buttons separated by commas. Add
     * "on the :tableIndex table" to restrict the check to a specific table's toolbar.
     *
     * Usage examples:
     *
     *   Then I do not see the button "Delete"
     *   Then I do not see the buttons "Delete, Approve"
     *   Then I do not see the button "Delete" on the 1 table
     *
     * @Then I do not see the button :unexpectedButton
     * @Then I do not see the button :unexpectedButton on the :tableIndex table
     * @Then I do not see the buttons :unexpectedButtons
     * @Then I do not see the buttons :unexpectedButtons on the :tableIndex table
     * @Then I should not see the button :unexpectedButton
     * @Then I should not see the button :unexpectedButton on the :tableIndex table
     * @Then I should not see the buttons :unexpectedButtons
     * @Then I should not see the buttons :unexpectedButtons on the :tableIndex table
     *
     * @param string $unexpectedButtons Comma-separated list of buttons expected to be absent
     * @param string|null $tableIndex Optional 1-based table index to scope the check
     */
    public function iDoNotSeeTheFollowingButtons($unexpectedButtons, $tableIndex = null)
    {
        // Parse the comma-separated tile list
        $unexpectedButtons = $this->explodeList($unexpectedButtons);

        // Resolve the scope once instead of per button: the table does not change between
        // iterations, and the shared resolver adds the range check this step was missing.
        $scope = null;
        $tableNumber = $this->parseTableIndex($tableIndex);
        if ($tableNumber !== null) {
            $scope = $this->findTableElementByIndex($tableNumber);
        }

        foreach ($unexpectedButtons as $btn) {
            $foundButton = $scope === null
                ? $this->getBrowser()->findButtonByCaption($btn)
                : $this->getBrowser()->findButtonByCaption($btn, $scope);

            if (! empty($foundButton)) {
                $this->getBrowser()->highlightWidget($foundButton, 'Button', 0);
            }
            Assert::assertEmpty($foundButton, 'Unexpected buttons found: ' . $btn);
        }
    }

    /**
     * Checks that the named tabs are shown on the page.
     *
     * Confirms that expected tabs (page or dialog sections) are available. Name one or more
     * tabs separated by commas. Each found tab is briefly highlighted.
     *
     * Usage examples:
     *
     *   Then I see tab "General"
     *   Then I should see tabs "General, Addresses, History"
     *
     * @Then I see tabs :tabs
     * @Then I see tab :tabs
     * @Then I should see tabs :tabs
     * @Then I should see tab :tabs
     *
     * @param string $tabs Comma-separated list of expected tab captions
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
     * Loads a set of prepared test data before the checks run.
     *
     * Many scenarios need known records to exist first (so results are predictable). This step
     * imports a folder of ready-made test data that ships with an app. Give the app alias and
     * the name of the data subfolder to load. Usually placed right after logging in.
     *
     * Usage example:
     *
     *   Given I log in to the page "nbr.onelink.start.html" as "Support"
     *   And test data from "nbr.OneLink" folder "Global" is loaded
     *   When I look at table 1
     *   Then I see at least one data item
     *
     * @Given test data from ":appAlias" folder ":subfolder" is loaded
     *
     * @param string $appAlias Alias of the app that provides the test data
     * @param string $subfolder Name of the test-data subfolder to load
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
     * @throws RuntimeException if toast message is not found
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
            throw new RuntimeException(
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

    protected function explodeList(string $list): array
    {
        return array_map('trim', explode(',', $list));
    }

    /**
     * Runs a guided self-check of a table, told which columns, filters and buttons to expect.
     *
     * This is a powerful "does this table behave correctly" step. You provide a Gherkin table
     * describing the captions the widget should have, and the platform automatically exercises
     * those columns, filters and buttons and reports any problem. Focus a table first (e.g.
     * "I look at table 1"). Use this when you want to spell out exactly what should be there;
     * use "It works as expected" when you want a fully automatic check.
     *
     * Usage example:
     *
     *   Given I log in to the page "my.app.orders.html" as "Support"
     *   When I look at table 1
     *   Then It works as shown below
     *     | Column Caption | Filter Caption | Button Caption |
     *     | Order No.      | Customer       | New            |
     *     | Customer       | Status         | Edit           |
     *
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
        Assert::assertNotTrue($result->isFailed(), 'Widget "' . ($node->getCaption() ?? $node->getWidgetType()) . '" did not work as expected: ' . ($result->getException()?->getMessage() ?? 'see substeps for details'));
    }

    /**
     * Runs a fully automatic self-check of the widget you are looking at.
     *
     * The platform inspects the focused widget and, based on its own model, automatically tries
     * out its filters and buttons and verifies it behaves correctly - you do not have to list
     * anything. This is the quickest way to broadly test a table. Focus a widget first (e.g.
     * "I look at table 1"). To check only filters or only buttons, use the dedicated steps below.
     * If the focused widget is a Page this will also check its child widgets work as expected.
     *
     * Usage example:
     *
     *   Given I log in to the page "my.app.orders.html" as "Support"
     *   When I look at table 1
     *   Then It works as expected
     *
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
        Assert::assertNotTrue($result->isFailed(), 'Widget "' . ($node->getCaption() ?? $node->getWidgetType()) . '" did not work as expected: ' . ($result->getException()?->getMessage() ?? 'see substeps for details'));
    }

    /**
     * Automatically checks only the filters of the widget you are looking at.
     *
     * A focused version of "It works as expected" that exercises just the filters and leaves
     * the buttons alone. Use it when you want to pin down filter behaviour on its own - for
     * example when the page's buttons are out of scope, or you are chasing a filter problem
     * without the slower full check. Focus a table first (e.g. "I look at table 1").
     *
     * Usage example:
     *
     *   Given I log in to the page "my.app.orders.html" as "Support"
     *   When I look at table 1
     *   Then The filters work as expected
     *
     * @Then The filters work as expected
     *
     * @return void
     */
    public function theFiltersWorkAsExpected(): void
    {
        $node = $this->getBrowser()->getFocusedNode();
        Assert::assertInstanceOf(
            UI5DataNode::class,
            $node,
            'No data widget is focused. Call "I look at table 1" first.'
        );
        $logbook = new MarkdownLogBook($node->getCaption());
        $logbook->setIndentActive(1);
        DatabaseFormatter::addTestLogbook($logbook);
        $result = $node->checkFiltersWorkAsExpected($logbook);
        Assert::assertNotTrue($result->isFailed(), 'Filters of widget "' . ($node->getCaption() ?? $node->getWidgetType()) . '" did not work as expected: ' . ($result->getException()?->getMessage() ?? 'see substeps for details'));
    }

    /**
     * Automatically checks only the buttons of the widget you are looking at.
     *
     * A focused version of "It works as expected" that exercises just the buttons and leaves
     * the filters alone. Use it when the filters are covered elsewhere or are known to be flaky
     * on a given page, and you only want to green-light the buttons. Focus a table first (e.g.
     * "I look at table 1").
     *
     * Usage example:
     *
     *   Given I log in to the page "my.app.orders.html" as "Support"
     *   When I look at table 1
     *   Then The buttons work as expected
     *
     * @Then The buttons work as expected
     *
     * @return void
     */
    public function theButtonsWorkAsExpected(): void
    {
        $node = $this->getBrowser()->getFocusedNode();
        Assert::assertInstanceOf(
            UI5DataNode::class,
            $node,
            'No data widget is focused. Call "I look at table 1" first.'
        );
        $logbook = new MarkdownLogBook($node->getCaption());
        $logbook->setIndentActive(1);
        DatabaseFormatter::addTestLogbook($logbook);
        $result = $node->checkButtonsWorkAsExpectedOnly($logbook);
        Assert::assertNotTrue($result->isFailed(), 'Buttons of widget "' . ($node->getCaption() ?? $node->getWidgetType()) . '" did not work as expected: ' . ($result->getException()?->getMessage() ?? 'see substeps for details'));
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
    public function visitPath($path, $sessionName = null, int $maxAttempts = self::VISIT_RETRY_MAX_ATTEMPTS): void
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
                // Only a lost CDP/WebSocket connection is transient and worth
                // retrying. Anything else (a real 404, an assertion, a locator
                // failure) will fail again on every attempt, so retrying would
                // just waste time and — worse — bury the real error behind a
                // misleading "after N attempts" message. Surface those at once
                // with the original exception intact.
                if (! $this->isCdpConnectionError($e)) {
                    throw $e;
                }
                if (++$attempt >= $maxAttempts) {
                    throw new BrowserDriverException($this->getSession(), 'Cannot open path "' . $path . '" in browser after ' . $attempt . ' attempts.', null, $e, $this->browser);
                }
                // WHY: a CDP error here has two distinct causes needing different handling. A Chrome
                // that is alive but was momentarily too slow to answer (the driver's /json/version
                // probe on the first visit) clears on its own, so a plain backoff + retry is enough.
                // A Chrome that actually died will never answer again, and retrying against a dead
                // process burns every attempt on the same socket failure. ensureChromeAlive() tells
                // the two apart: it is a no-op when isAlive() is true, and restarts (or, if a login
                // already happened, recovers) Chrome when it is gone - so the retry below lands on a
                // live browser. It never throws, so calling it inside this loop is safe.
                $this->ensureChromeAlive();
                // CDP transient — give a still-alive-but-slow browser time to settle, then retry.
                $this->sleepBeforeVisitRetry($attempt);
            }
        }
    }

    /**
     * Sleeps between visitPath() retries using a linear backoff plus small random jitter.
     *
     * Exists so the retry loop stays readable and the timing policy lives in one
     * place. The backoff widens per attempt to give a self-clearing CDP transient
     * more time on each successive try; the jitter de-synchronises parallel lanes
     * that would otherwise retry in lockstep (see VISIT_RETRY_JITTER_MAX_MS).
     *
     * @param int $attempt The 1-based retry attempt number (drives the linear backoff).
     */
    private function sleepBeforeVisitRetry(int $attempt): void
    {
        $delayMs = (self::VISIT_RETRY_BASE_DELAY_MS * $attempt)
            + random_int(0, self::VISIT_RETRY_JITTER_MAX_MS);
        usleep($delayMs * 1000);
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

    /**
     * Normalises the table number written in a Gherkin step into a 1-based integer.
     *
     * WHY: Test authors write the table number in several notations - as a bare number (2), as a
     * quoted ordinal ("2.") or with stray whitespace. Until now every table-scoped step repeated
     * its own `(int) filter_var(...)` line, so the accepted notations drifted apart from step to
     * step and an author could not rely on one table step behaving like the next. Parsing in a
     * single place keeps all table steps interchangeable and produces one clear error message
     * instead of a silent fallback to table 1.
     *
     * @param int|string|null $tableIndex Raw value as captured from the step
     * @return int|null NULL when no index was given - the caller should then use the focused table
     * @throws RuntimeException If a value was given but contains no usable positive number
     */
    private function parseTableIndex($tableIndex): ?int
    {
        // An omitted index is not an error: it means "use whatever table is currently focused".
        if ($tableIndex === null || trim((string) $tableIndex) === '') {
            return null;
        }

        // Strip everything that is not part of a number so that 2, "2.", "2nd" and " 2 " all
        // resolve to the same table.
        $digits = filter_var((string) $tableIndex, FILTER_SANITIZE_NUMBER_INT);
        if ($digits === false || $digits === '' || ! is_numeric($digits)) {
            throw new RuntimeException(
                'Invalid table index "' . $tableIndex . '". Expected a number like 2 or "2.".'
            );
        }

        $tableNumber = (int) $digits;
        if ($tableNumber < 1) {
            throw new RuntimeException(
                'Invalid table index "' . $tableIndex . '". Tables are counted from 1.'
            );
        }

        return $tableNumber;
    }

    /**
     * Resolves a 1-based table number to the DataTable element it refers to.
     *
     * WHY: "Find the Nth table on the page" was copy-pasted into several steps, each with its own
     * CSS selector list and its own - sometimes missing - range check. That meant the very same
     * scenario line could address different tables depending on which step executed it, and an
     * out-of-range number reached PHP as an undefined array key instead of a readable test
     * failure. One resolver keeps the numbering identical across all table-scoped steps.
     *
     * @param int $tableNumber 1-based table number
     * @return NodeElement
     * @throws RuntimeException If the page contains fewer tables than requested
     */
    private function findTableElementByIndex(int $tableNumber): NodeElement
    {
        $tables = $this->getBrowser()->getPage()->findAll('css', '.exfw-DataTable');
        Assert::assertNotEmpty($tables, 'No DataTables found on the page');

        if (! isset($tables[$tableNumber - 1])) {
            throw new RuntimeException(sprintf(
                'Table no. %d requested, but only %d table(s) found on the page',
                $tableNumber,
                count($tables)
            ));
        }

        return $tables[$tableNumber - 1];
    }
}