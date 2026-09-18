<?php
namespace axenox\BDT\Tests\Behat\Contexts\UI5Facade;

use axenox\BDT\Behat\Common\Attributes\ResumeSafeStep;
use axenox\BDT\Behat\Common\ErrorManager;
use axenox\BDT\Behat\Contexts\UI5Facade\ChromeManager;
use axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5AbstractNode;
use axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5ButtonNode;
use axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5ContainerNode;
use axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5DataNode;
use axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5DataSpreadSheetNode;
use axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5FilterNode;
use axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5InputNode;
use axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5MenuButtonNode;
use axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5PageNode;
use axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5RangeFilterNode;
use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\BDT\Behat\DatabaseFormatter\DatabaseFormatter;
use axenox\BDT\Behat\Events\AfterPageVisited;
use axenox\BDT\Behat\TwigFormatter\Context\BehatFormatterContext;
use axenox\BDT\Common\Installer\TestDataInstaller;
use axenox\BDT\Exceptions\BrowserDriverException;
use axenox\BDT\Interfaces\FacadeNodeInterface;
use Behat\Behat\Context\Context;
use Behat\Behat\Tester\Result\DefinedStepResult;
use Behat\Behat\Tester\Result\UndefinedStepResult;
use Behat\Mink\Element\NodeElement;
use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use Behat\Mink\Session;
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
    
    /**
     * @var array|null Roles used by the most recent iLogInToPage() call.
     *
        * Cached so every UI5Browser built during the scenario can recover the same role-aware state.
        * Null means no login step has established the scenario roles yet, while an array is the known
        * role set that bindBrowserToScenario() must restore after navigation or Chrome recovery.
     */
    private ?array $lastLoginUserRoles = null;

    /**
     * @var string|null Full URL, including the UI5 route fragment, at the end of the last passed step.
     *
     * WHY: this - not the page alias - is where a recovered Chrome has to continue. The alias collapses a dialog
     * route onto the page behind it. NULL means the resume point is unknown and must not be guessed.
     */
    private ?string $resumeUrl = null;

    /** @var string|null Logical page alias belonging to $resumeUrl; reloaded before the route is re-opened */
    private ?string $resumePageAlias = null;

    /** @var array|null Focus stack at $resumeUrl as described by UI5Browser::describeFocusForResume(); NULL = not rebuildable */
    private ?array $resumeFocus = null;

    /**
     * @var \WeakReference|null The UI5Browser that was active when $resumeUrl was recorded.
     *
     * WHY: a new UI5Browser is built exactly when a full page load happened, and a full page load discards every
     * in-browser state. WHY WEAK: an object id can be reused once the old browser is freed.
     */
    private ?\WeakReference $resumeBrowser = null;

    /**
     * @var string[] Passed, non-resume-safe steps that did not change the URL since the last route change.
     *
     * WHY SEPARATE FROM EARLIER VIEWS: this is the state of the view the scenario is in right now. It cannot be
     * rebuilt, and every next step depends on it, so it blocks a resume immediately.
     */
    private array $blockersInCurrentView = [];

    /**
     * @var string[] Such steps in views below the current one (before the last route change, same document).
     *
     * WHY THEY DO NOT BLOCK IMMEDIATELY: the row selected on the page behind a dialog does not matter while the
     * scenario works inside the dialog. It matters again only when the scenario returns - so it is checked then.
     */
    private array $blockersInEarlierViews = [];

    /**
     * @var string|null URL of the view a recovery resumed in while state of views below it was lost.
     *
     * WHY: leaving this view is the moment the lost state matters again; recordResumePoint() stops the scenario then.
     */
    private ?string $recoveredRouteUrl = null;

    /** @var string[] Steps whose state in views below $recoveredRouteUrl was lost in a recovery */
    private array $lostEarlierViewState = [];

    /**
     * @var string|null Why the scenario cannot continue after a Chrome recovery; NULL when it can.
     *
     * WHY getBrowser() ENFORCES IT: nearly every step goes through getBrowser(), so refusing there fails the very
     * next step with the real reason - without guarding a list of steps and without throwing from a hook.
     */
    private ?string $unrestorableStateReason = null;

    /**
     * Initializes and starts the workbench for the test environment.
     *
     * @param bool $debug          Echo debug lines to stdout (unchanged).
     */
    public function __construct(bool $debug = false)
    {
        self::$isDryRun = in_array('--dry-run', $_SERVER['argv'] ?? [], true);
        if (self::$isDryRun) {
            return;
        }
        $this->workbench = new Workbench(['MONITOR.ENABLED' => false]);
        $this->workbench->start();
        // Authenticated with the default CLI user if called from CLI. The authenticated
        // user will change with Browser::setupUser() later, but for now the CLI user is
        // better than no user at all!
        if (ConsoleFacade::isPhpScriptRunInCli()) {
            $token = new CliEnvAuthToken();
            // WHY THE GUARD: a fresh context instance - and therefore this authenticate() - runs for
            // EVERY scenario, and all parallel lanes run as the same OS user, so this call re-writes
            // the one shared USER_AUTHENTICATOR row throughout the whole run. Without the guard, two
            // lanes starting scenarios at the same instant race on the row's optimistic lock and one
            // dies with a "changed in the meantime" conflict.
            //
            // WHY THE GUARD HAS TO BE APPLIED HERE AND NOT ONCE PER PROCESS: the guard works by
            // flipping a flag on the TimeStampingBehavior instance held by the meta object of ONE
            // workbench. This context builds its own Workbench above, so it owns its own behavior
            // instances - a guard applied around a write on any other workbench in this process has
            // no effect on this call. Every workbench that writes the row needs the guard at its own
            // call site (see the trait's docblock).
            //
            // WHY IT IS SAFE: only the optimistic-lock check is suppressed, the timestamps keep being
            // written, and last_authenticated_on is a last-writer-wins value anyway.
            self::withoutAuthenticatorTimeStamping(
                $this->workbench,
                fn() => $this->workbench->getSecurity()->authenticate($token)
            );
        }
        $this->debug = $debug;
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
            if ($exception !== null && $this->requiresChromeRestart($exception)) {
                $this->recoverChromeInHook(
                    'Triggered after a FAILED step. Behat skips the remaining steps of this scenario, so it cannot continue '
                    . 'whatever is restored - this recovery only prepares the browser for the next scenario.'
                );
            }
        } catch (\Throwable $e) {
            // Logging itself failed (e.g. DB unreachable). Swallow so Behat can continue.
            $this->logDebug('logFailedStep internal error: ' . $e->getMessage());
        }
    }

    /**
     * Restarts and re-authenticates Chrome from inside a Behat hook and reports what triggered it.
     *
     * WHY ONE METHOD FOR ALL HOOKS: the BeforeStep hook, the AfterStep hook of a passed step and the AfterStep
     * hook of a failed step need the same thing - recover at the last resume point without ever letting an
     * exception escape the hook, which would end Behat with exit code 255. Only the explanation of what the
     * recovery can still achieve differs, so that is the parameter.
     *
     * @param string $trigger Where Chrome was lost and what the recovery can still achieve, printed as-is
     */
    private function recoverChromeInHook(string $trigger): void
    {
        try {
            $this->reportChromeRecovery($trigger);
            $this->recoverChrome('');
        } catch (\Throwable $recoveryError) {
            $this->reportChromeRecovery('FAILED: ' . $recoveryError->getMessage());
            try {
                $this->getWorkbench()->getLogger()->logException(new RuntimeException(
                    'Chrome recovery failed: ' . $recoveryError->getMessage(),
                    null,
                    $recoveryError
                ));
            } catch (\Throwable $ignored) {}
        }
    }

    /**
     * Makes a Chrome recovery decision visible in the Behat output and in the workbench log.
     *
     * WHY NOT logDebug(): logDebug() only prints when the context runs with debug enabled, which a normal run
     * does not. Every recovery decision - what triggered it, where it resumed, why it refused - was therefore
     * invisible, and "the page opened without its dialog" could not be told apart from four different causes.
     * Recoveries are rare, so printing them unconditionally adds no real noise (same as the LogID line).
     *
     * Never throws: it is called from hooks.
     *
     * @param string $message Human-readable description of the recovery decision
     */
    private function reportChromeRecovery(string $message): void
    {
        echo '[Chrome recovery] ' . $message . PHP_EOL;
        try {
            $this->getWorkbench()->getLogger()->info('[Chrome recovery] ' . $message);
        } catch (\Throwable $ignored) {}
    }

    /**
     * Prepares the environment before each test step by clearing XHR logs and installing the HTTP interceptor.
     *
     * Must never throw - any uncaught exception from a BeforeStep hook causes Behat to exit with code 255.
     * If Chrome is lost while the step is being prepared, it is recovered here, before the step runs into
     * the dead browser and ends the scenario.
     *
     * @BeforeStep
     */
    public function prepareBeforeStep(BeforeStepScope $scope): void
    {
        // Must run FIRST: every call below talks to the browser. Placed above the browser check because a
        // failed recovery can leave no UI5Browser behind, and returning first would switch off every later
        // recovery attempt for the rest of the scenario. ensureChromeAlive() needs no browser.
        $this->ensureChromeAlive();

        // A scenario that cannot continue after a recovery has nothing to prepare; the step fails with the
        // reason through getBrowser().
        if ($this->browser === null || $this->unrestorableStateReason !== null) {
            return;
        }

        try {
            ErrorManager::getInstance()->clearErrors();
            $this->getBrowser()->clearXHRLog();

            $this->getBrowser()->getErrorDetector()->installHttpInterceptor();

            // Short pause to let the UI fully settle before the step executes
            $this->getSession()->wait(1000);

            $this->getBrowser()->clearWidgetHighlights();

            $stepKeyword = $scope->getStep()->getKeyword();
            $stepText    = $scope->getStep()->getText();
            $stepLine    = $scope->getStep()->getLine();
            $stepName    = sprintf('%s %s', $stepKeyword, $stepText);

            $this->logDebug(sprintf("\n[%d] Starting step: %s", $stepLine, $stepName));
            $this->getBrowser()->showTestCaseName(sprintf('Step [%d]: %s', $stepLine, $stepName));
            $this->stepStartTime = $this->getBrowser()->showStepTiming($stepName, true);

        } catch (\Throwable $e) {
            $this->logDebug('prepareBeforeStep failed: ' . $e->getMessage());
            try {
                $this->getWorkbench()->getLogger()->logException(new RuntimeException(
                    'prepareBeforeStep failed: ' . $e->getMessage(),
                    null,
                    $e
                ));
            } catch (\Throwable $ignored) {}

            // Chrome lost AFTER ensureChromeAlive() passed - typically during the settle pause. Recovering here
            // resumes at the end of the previous step; without it the step runs into the dead browser, fails,
            // and Behat skips the rest of the scenario.
            if ($this->requiresChromeRestart($e)) {
                $this->recoverChromeInHook('Triggered while preparing a step. The scenario continues if its state can be restored.');
            }
        }
    }


    /**
     * Ensures consistent state after each test step by waiting for UI5 operations, validating that no
     * errors occurred, and recording the resume point a Chrome recovery would continue from.
     *
     * Must never throw — any uncaught exception from an AfterStep hook causes Behat to exit with code 255.
     * Chrome hang and timeout errors are caught here and logged; Chrome recovery is attempted if needed.
     *
     * @AfterStep
     */
    public function completeAfterStep(AfterStepScope $scope): void
    {
        if (!$scope->getTestResult()->isPassed()) {
            return;
        }

        if ($this->browser === null) {
            return;
        }

        $resumePointRecorded = false;

        try {
            $this->getBrowser()->handleStepWaitOperations(true);
            $this->getBrowser()->getErrorDetector()->assertNoErrors();

            // Recorded right after the step settled and BEFORE the cosmetic calls below: a Chrome lost during
            // the timing overlay or the settle pause must not also cost the scenario its resume point.
            $this->recordResumePoint($scope);
            $resumePointRecorded = true;

            $stepKeyword = $scope->getStep()->getKeyword();
            $stepText    = $scope->getStep()->getText();
            $stepName    = sprintf('%s %s', $stepKeyword, $stepText);

            $this->logDebug(sprintf("\nCompleted step: %s", $stepName));
            $this->getBrowser()->showStepTiming($stepName, false, $this->stepStartTime);

            $this->getSession()->wait(1000);

        } catch (\Throwable $e) {
            // The step passed, but where it left the browser is unknown. A stale resume point would let a
            // recovery restore an older state while claiming the scenario can continue, so it is dropped.
            if (! $resumePointRecorded) {
                $this->resumeUrl = null;
            }

            $this->logDebug('Wait operation failed (after step): ' . $e->getMessage());
            try {
                $this->getWorkbench()->getLogger()->logException(new RuntimeException(
                    'Wait operation failed (after step): ' . $e->getMessage(),
                    null,
                    $e
                ));
            } catch (\Throwable $ignored) {}

            if ($this->requiresChromeRestart($e)) {
                $this->recoverChromeInHook('Triggered after a PASSED step while it was settling. The scenario continues if its state can be restored.');
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
                $this->getBrowser()->initializeXHRMonitoring();
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
            // browser instead of reusing a dead socket.
            $this->reconnectSession();
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
    public function iShouldSeeThePage(): void
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
     * @throws \Exception
     */
    public function iLogInToPage(string $url, string $userRoles = null, string $userLocale = null): void
    {
        $this->forgetRecoveryState();
        
        // Persist login parameters so recoverChrome() can replay them.
        $this->lastLoginUrl = $url;
        $this->lastLoginLocale = $userLocale;
        
        // Setup the user and get the required login data
        $userRolesArray = $this->explodeList($userRoles);
        Assert::assertNotNull($userRolesArray, 'User roles must be provided for login');
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
        // Roles belong to the whole scenario and must be restored on every browser built later.
        $this->lastLoginUserRoles = $userRolesArray;
        
        $this->setLocale($userLocale);

        // Fill the form
        $this->browserLogin($url, $tabCaption, $btnCaption, $loginFields);
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
     * @param string $url Page URL to log in to
     * @param string $tabCaption Caption of the authenticator tab to open
     * @param string $btnCaption Caption of the login submit button
     * @param array $loginFields Form fields as caption => value (without the _tab/_button keys)
     * @throws \Exception
     */
    private function browserLogin(string $url, string $tabCaption, string $btnCaption, array $loginFields): void
    {
        // Go to the page
        $this->iVisitPage($url);

        // If a stale session is active, the login form won't appear — we land directly
        // on the requested page instead. Detect this with a short retry and log out first.
        try {
            // Find the correct authenticator tab. Keep retrying for 5
            $this->getBrowser()->goToTab($tabCaption, null, 5);
        } catch (\Throwable $e) {
            $this->getBrowser()->logOutIfAlreadyLoggedIn($this->getMinkParameter('base_url'));
            $this->browser = null;
            $this->iVisitPage($url);
            $this->getBrowser()->goToTab($tabCaption, null, 5);
        }
        
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
     * @throws \Throwable
     */
    public function iVisitPage(string $url): void
    {
        $this->forgetRecoveryState();
        
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
    #[ResumeSafeStep]
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

        if (!empty($widgetNodes)) {
            foreach ($widgetNodes as $i => $node) {
                // change to NodeElement with getNodeElement() 
                $nodeElement = $node->getNodeElement();
                $this->getBrowser()->highlightWidget($nodeElement, $widgetType, $i);
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
     * @throws \Exception
     */
    #[ResumeSafeStep]
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
        
        // Highlight the first few matches for visual debugging only - has no effect on the assertion above
        foreach ($widgetNodes as $index => $node) {
            $this->getBrowser()->highlightWidget(
                $node->getNodeElement(),
                $widgetType,
                $index
            );
        }

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
     *   Then it has filters "Name, Created on, Status"
     *
     * @Then it has filters ":filterList"
     * @Then it has a filter ":filterList"
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
     * @throws RuntimeException|\Exception If the table is not rendered or is not a DataTable node.
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
     * @throws \Exception If no widget of that type is rendered or fewer than $number are.
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
    #[ResumeSafeStep]
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
    #[ResumeSafeStep]
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
     * Checks that at least one row of a named column contains the given value.
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
     * Checks that the values of a named column are highlighted in a given colour.
     *
     * Use this to verify colour coding - for example that relevant records stand out in blue.
     * Every non-empty value of the column must be shown in that colour, so filter the table down
     * to the rows you expect to be highlighted first. Focus a table first (e.g. "I look at table 1").
     *
     * A CSS colour such as "#0a6ed1", "rgb(10, 110, 209)" or "DodgerBlue" is compared exactly first
     * after normalizing its notation. If the exact shade differs, the colour family is checked as a
     * fallback. Add a lightness qualifier when the shade range matters - "light blue", "dark blue",
     * "pale green" or "dark red". Multi-word colours have to be quoted. Both the text colour and the
     * background of the value are taken into account, so filled and text-only colour codings work.
     *
     * Usage example:
     *
     *   When I look at table 1
     *   And I enter "Ja" in filter "BF Relevant"
     *   Then I see "Ja" in column "BF Relevant"
     *   And I see the value in column "BF Relevant" highlighted in blue
     *   And I see the value in column "Status" highlighted in "dark green"
     *
     * @Then I see the value in column :columnName highlighted in :color
     * @Then I see the values in column :columnName highlighted in :color
     *
     * @param string $columnName Caption of the column to inspect.
     * @param string $color      Colour family with an optional lightness qualifier (e.g. "blue" or
     *                           "light blue"), HTML colour name or hex value.
     */
    public function iSeeTheValueInColumnHighlightedIn(string $columnName, string $color): void
    {
        $this->getFocusedDataTableNode()->assertColumnValuesColored($columnName, $color);
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
            $button = $focusedNode->findButtonInOverflowByCaptionLoose($caption);
        }

        // when a tab is focused, widen the search to the dialog or widget that owns its tab strip.
        // WHY: "I click tab" narrows the scope to the tab's content on purpose, but a dialog keeps its
        // action buttons in the footer, outside every tab - they would otherwise be unreachable.
        // WHY NOT page wide: see UI5Browser::getFocusedTabContainerNode().
        $containerNode = $button === null ? $this->getBrowser()->getFocusedTabContainerNode() : null;
        if ($containerNode !== null) {
            $containerElement = $containerNode->getNodeElement();
            $button = $containerElement->find('named', ['button', $caption])
                ?? $this->getBrowser()->findButtonInScopeByCaption($containerElement, $caption);
            if ($button === null && $containerNode instanceof UI5AbstractNode) {
                $button = $containerNode->findButtonInOverflowByCaptionLoose($caption);
            }
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
        $buttonNode = UI5FacadeNodeFactory::createFromWidgetType('Button', $button, $this->getSession(), $this->getBrowser());
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
        } catch (\Throwable $e) {
            throw new BrowserDriverException($this->getSession(), 'Cannot click button "' . $caption . '". ' . $e->getMessage(), null, $e, $this->getBrowser());
        }
    }

    /**
     * Opens a tab and makes it the area you are looking at.
     *
     * Many pages and dialogs group content into tabs. After this step, checks like
     * "it has ... widget of type ..." count only the widgets of this tab instead of the whole dialog or
     * page. This matters because a maximized dialog shows all its tabs as sections below each other, so
     * a count on the dialog level would mix the fields of every tab.
     *
     * Buttons of the surrounding dialog or page - such as "Save" in the dialog footer - stay clickable.
     *
     * Usage example:
     *
     *   When I click button "Edit"
     *   Then I see 1 widget of type "Dialog"
     *   When I click tab "Addresses"
     *   Then it has 2 widgets of type "Input"
     *   When I click button "Save"
     *
     * @When I click tab ":caption"
     * @When I look at tab ":caption"
     *
     * @param string $caption Text caption of the tab to open and look at
     * @return void
     * @throws RuntimeException If the content area of the tab cannot be resolved
     */
    public function iClickTab(string $caption): void
    {
        $browser = $this->getBrowser();
        $tabHeader = $browser->openTab($caption);
        $browser->focusTab($tabHeader, $caption);
        $browser->highlightWidget($tabHeader, 'Tab', 0);
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
     * Fills a single-value input or both boundaries of a range input in the current search scope.
     *
     * If a widget is focused, only its contents are searched. Otherwise, the entire page is searched.
     *
     * Usage examples:
     *
     *   When I fill widget of type "InputTime" with "Start time" "08:30"
     *   When I fill widget of type "RangeFilter" with "Order date" from "2026-01-01" to "2026-01-31"
     *
     * @When I fill widget of type ":widgetType" with ":caption" ":value"
     * @When I fill widget of type ":widgetType" with ":caption" from ":value" to ":toValue"
     *
     * @param string $widgetType Type of widget to fill
     * @param string $caption Caption of the widget
     * @param string $value Value for a single input or the lower range boundary
     * @param string|null $toValue Upper range boundary, or null for a single-value input
     * @return void
     */
    public function iFillWidgetOfTypeWithCaption(
        string $widgetType,
        string $caption,
        string $value,
        ?string $toValue = null
    ): void {
        $browser = $this->getBrowser();
        $focusedNode = $browser->getFocusedNode();
        $widgets = $focusedNode instanceof UI5PageNode
            ? $browser->findWidgetNodes($widgetType, 10, $caption)
            : $browser->filterNodesByName(
                $browser->findWidgetNodesInNode($focusedNode, $widgetType, 1, 10),
                $caption
            );
        Assert::assertCount(
            1,
            $widgets,
            sprintf(
                'Expected exactly one widget of type "%s" with caption "%s" in %s, but found %d',
                $widgetType,
                $caption,
                $browser->describeSearchScope(),
                count($widgets)
            )
        );

        $widget = reset($widgets);
        if ($toValue !== null) {
            Assert::assertInstanceOf(
                UI5RangeFilterNode::class,
                $widget,
                sprintf('Widget of type "%s" with caption "%s" is not a range input', $widgetType, $caption)
            );
            $widget->setRangeVisible($value, $toValue);
            return;
        }

        Assert::assertTrue(
            $widget instanceof UI5InputNode || $widget instanceof UI5FilterNode,
            sprintf('Widget of type "%s" with caption "%s" is not an input', $widgetType, $caption)
        );
        $widget->setValueVisible($value);
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
    #[ResumeSafeStep]
    public function iLookAtWidget(string $widgetType, int $number = 1): void
    {
        // Set focus to this widget so the subsequent "it has..." steps have a context
        $this->getBrowser()->focus($this->getWidgetNodeByIndex($widgetType, $number));
    }

    /**
     * Checks that one or more buttons are present on the page.
     *
     * Use this to confirm the user has access to certain actions. You can name a single button
     * or several separated by commas. Add "on the :tableName" to look for the buttons only in
     * the toolbar of the named table (or dialog/panel) - name it the way the app shows it, or
     * by the data object behind it. Each found button is briefly highlighted.
     *
     * The step only asks whether the button is THERE. A button that is greyed out counts as seen:
     * the user can see the action, they just cannot trigger it right now, and whether they may
     * trigger it is what "I click button ..." is for. A button that the toolbar moved behind its
     * "..." overflow menu counts as seen as well.
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
     * @throws \Exception If a button is not found
     */
    public function iSeeButton(string $buttonText, string $tableName = null): void
    {
        $result = $this->findVisibleButtons($buttonText, $tableName);

        Assert::assertEmpty(
            $result['missing'],
            (count($result['missing']) === 1 ? "Button with text '" : "Buttons with text '")
            . implode("', '", array_keys($result['missing'])) . "'"
            . ($tableName === null || $tableName === ''
                ? ' not found.'
                : " not found at '{$tableName}'.")
            . ($result['overflowHint'] ?? '')
        );
    }

    /**
     * Answers which requested buttons the user can see in the named widget and its overflow.
     *
     * WHY BOTH ASSERTIONS USE THIS METHOD: presence and absence are complements only when they search
     * the same rendered buttons, resolve the same named scope and inspect the same toolbar overflow.
     * Keeping the complete lookup here prevents a permission check from silently passing because its
     * negative form forgot one of the places where the positive form can find an action.
     *
     * @param string $buttonText Comma-separated button captions
     * @param string|null $scopeName Optional caption or object of the widget to search in
     * @param FacadeNodeInterface[] $scopeNodes Pre-resolved widgets for callers with another scope vocabulary
     * @return array{found: array<string, true>, missing: array<string, true>, overflowHint: string|null}
     * @throws \Exception If the named scope cannot be found
     */
    protected function findVisibleButtons(string $buttonText, ?string $scopeName = null, array $scopeNodes = []): array
    {
        $scopeName = $scopeName === null ? null : trim($scopeName);
        if ($scopeName !== null && $scopeName !== '') {
            $scopeNodes = $this->getBrowser()->findWidgetNodesByName($scopeName, 15);
            Assert::assertNotEmpty(
                $scopeNodes,
                'Cannot find a widget named "' . $scopeName . '" to look for buttons in.'
            );
        }

        $found = [];
        $missing = [];
        foreach ($this->explodeList($buttonText) as $buttonCaption) {
            $button = null;
            if (empty($scopeNodes)) {
                $button = $this->getBrowser()->findButtonByCaption($buttonCaption);
            } else {
                foreach ($scopeNodes as $scopeNode) {
                    $button = $this->getBrowser()->findButtonByCaption($buttonCaption, $scopeNode->getNodeElement());
                    if ($button !== null) {
                        break;
                    }
                }
            }

            if ($button === null) {
                $missing[$buttonCaption] = true;
                continue;
            }

            $found[$buttonCaption] = true;
            $this->getBrowser()->highlightWidget($button, 'Button', 0);
        }

        if (empty($missing)) {
            return ['found' => $found, 'missing' => [], 'overflowHint' => null];
        }

        $overflowHint = null;
        foreach ($this->getOverflowSearchNodes($scopeNodes) as $node) {
            try {
                $node->findInOverflow(function (NodeElement $menu) use (&$found, &$missing) {
                    $firstFound = null;
                    foreach (array_keys($missing) as $caption) {
                        $entry = $this->getBrowser()->findButtonInScopeByCaption($menu, $caption);
                        if ($entry === null) {
                            continue;
                        }
                        $found[$caption] = true;
                        unset($missing[$caption]);
                        $this->getBrowser()->highlightWidget($entry, 'Button', 0);
                        $firstFound = $firstFound ?? $entry;
                    }
                    return $firstFound;
                });
            } catch (RuntimeException $e) {
                $overflowHint = $overflowHint ?? ' ' . $e->getMessage();
                continue;
            } finally {
                // The lookup is observational: a popover opened to inspect moved buttons must never
                // swallow the following step, even when matching or highlighting throws.
                $node->closeOverflowMenuIfOpened();
            }

            if (empty($missing)) {
                break;
            }
        }

        return ['found' => $found, 'missing' => $missing, 'overflowHint' => $overflowHint];
    }

    /**
     * Returns the nodes whose toolbar overflow menus may hold a button this step is looking for.
     *
     * WHY IT IS NOT A PAGE-WIDE SEARCH: an overflowed button can only be reached through the overflow
     * of the widget it belongs to. Opening a menu picked by guesswork would find a same-named button
     * of a neighbouring widget and the assertion would turn green for the wrong toolbar. When the step
     * names a widget, that widget is the owner; otherwise the focused one is - and with nothing
     * focused getFocusedNode() returns a UI5PageNode, which offers no overflow lookup at all, so the
     * fallback is simply skipped.
     *
     * @param FacadeNodeInterface[] $scopeNodes Nodes the step was scoped to, empty for a page wide step
     * @return UI5AbstractNode[]
     */
    protected function getOverflowSearchNodes(array $scopeNodes): array
    {
        if (! empty($scopeNodes)) {
            return array_values(array_filter($scopeNodes, fn($node) => $node instanceof UI5AbstractNode));
        }

        $focusedNode = $this->getBrowser()->getFocusedNode();
        return $focusedNode instanceof UI5AbstractNode ? [$focusedNode] : [];
    }

    /**
     * Checks that one or more columns of an editable spreadsheet are read-only (not editable).
     * A DataSpreadSheet is the Excel-like editing grid used in some pages. Use this step to
     * confirm that the comma-separated columns cannot be edited by the user - every cell in each
     * column must be marked read-only, otherwise the step fails and tells you which row was editable.
     * Focusing a spreadsheet first (e.g. "I look at 'SpreadSheet' no. 1") is required.
     * 
     * Usage example:
     * When I look at "SpreadSheet" no. 1
     * Then the column "ID" in data spreadsheet should be disabled
     * Then the column "Created by, Modified by" in data spreadsheet should be disabled
     * 
     * @Then the column :columnName in data spreadsheet should be disabled
     * 
     * @param string $columnName Comma-separated captions of spreadsheet columns to check
    */
    #[ResumeSafeStep]
    public function theColumnInDataSpreadsheetShouldBeDisabled(string $columnName): void
    {
        // getFocusedNode() returns ONE node, not a list. Indexing it threw "Cannot use object of type ... as array",
        // so the step could never pass and never reported which column was editable.
        $node = $this->getBrowser()->getFocusedNode();
        Assert::assertInstanceOf(
            UI5DataSpreadSheetNode::class,
            $node,
            'No DataSpreadSheet widget is focused - focus one first (e.g. "I look at \'SpreadSheet\' no. 1").'
        );

        // Resolve before highlighting: the debug label becomes part of the jExcel header text.
        $columnResults = [];
        foreach ($this->explodeList($columnName) as $columnCaption) {
            $columnResults[] = [
                'caption' => $columnCaption,
                'node' => $node->getColumnByCaption($columnCaption),
                'editableRows' => $node->getEditableRowNumbers($columnCaption),
            ];
        }

        foreach ($columnResults as $columnIndex => $columnResult) {
            $this->getBrowser()->highlightWidget($columnResult['node']->getNodeElement(), 'Column', $columnIndex);
        }

        foreach ($columnResults as $columnResult) {
            Assert::assertSame(
                [],
                $columnResult['editableRows'],
                "Column '{$columnResult['caption']}' is not disabled in row(s) "
                . implode(', ', $columnResult['editableRows']) . '.'
            );
        }
    }

    /**
     * Fills one or more consecutive rows of an editable spreadsheet - but it does need a 
     * spreadsheet to be focused first.
     *
     * WHY BOTH TABLE SHAPES ARE ACCEPTED: feature files commonly express spreadsheet rows as
     * captions followed by values, while older scenarios use "Column" and "Value" pairs. Behat's
     * getHash() represents these shapes differently, so normalising them here keeps feature-table
     * interpretation in the step and guarantees the node receives typed captions and values.
     *
     * Usage examples:
     *
     *   When I look at "SpreatSheet" no. 1
     *   When I fill the row 2 of data spreadsheet with:
     *     | Column   | Value      |
     *     | Name     | Widget A   |
     *     | Quantity | 10         |
     *
     *   When I look at "SpreatSheet" no. 3
     *   When I fill the last row of data spreadsheet with:
     *     | Column   | Value      |
     *     | Name     | Widget B   |
     *
     *   When I look at "SpreatSheet" no. 2
     *   When I fill the row 4 of data spreadsheet with:
     *     | Farbe | Von (Tage) |
     *     | lila  | 8          |
     *     | blau  | 12         |
     *
     * @When I fill the row :rowIndex of data spreadsheet with:
     * @When I fill the last row of data spreadsheet with:
     *
     * @param TableNode $table Caption/value rows or rows with "Column" and "Value" pairs
     * @param int|string|null $rowIndex 1-based row number, or null for the last row
     * @throws \Exception
     */
    public function iFillTheNthRowOfDataSpreadsheetWith(TableNode $table, int|string|null $rowIndex = null): void
    {
        $nodes = $this->getBrowser()->getFocusedNode();
        $node = $nodes[0] ?? null;
        Assert::assertInstanceOf(UI5DataSpreadSheetNode::class, $node, 'No DataSpreadSheet widget found.');
        $rowNumber = $rowIndex === null || strtolower((string) $rowIndex) === 'last'
            ? null
            : (int) $rowIndex;

        $tableRows = $table->getHash();
        Assert::assertNotEmpty($tableRows, 'No spreadsheet values were provided.');

        if (array_key_exists('Column', $tableRows[0]) || array_key_exists('Value', $tableRows[0])) {
            foreach ($tableRows as $row) {
                Assert::assertArrayHasKey('Column', $row, 'Spreadsheet input requires a `Column` field.');
                Assert::assertArrayHasKey('Value', $row, 'Spreadsheet input requires a `Value` field.');
                $node->setCellValue($rowNumber, (string) $row['Column'], (string) $row['Value']);
            }
            return;
        }

        Assert::assertTrue(
            $rowNumber !== null || count($tableRows) === 1,
            'Multiple horizontal spreadsheet rows require an explicit starting row number.'
        );
        foreach ($tableRows as $rowOffset => $row) {
            $targetRowNumber = $rowNumber === null ? null : $rowNumber + $rowOffset;
            foreach ($row as $columnCaption => $value) {
                $node->setCellValue($targetRowNumber, (string) $columnCaption, (string) $value);
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
            if (str_contains($cell->getText(), $text)) {
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
     * @throws \Exception
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
            $this->createBrowser($url, $currentSession);
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
     * 
     * @throws \Throwable If any page has errors or the UI framework is not stable after navigation
     */
    public function allPagesShouldLoadSuccessfully(): void
    {
        // Verify no errors in current session
        $this->getBrowser()->getErrorDetector()->assertNoErrors();

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
    #[ResumeSafeStep]
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
    public function iSelectTableRow(int $rowNumber): void
    {
        // Use the focused table (if there is no error, throw an error)
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
    public function iClickButtonOnTable(string $buttonCaption, int|string $tableIndex = 1): void
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
                $button = $tableNode->findButtonInOverflowByCaptionLoose($buttonCaption);
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
        $buttonNode = UI5FacadeNodeFactory::createFromWidgetType('Button', $button, $this->getSession(), $this->getBrowser());
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
                $this->getBrowser()
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
        $menuNode = $this->getBrowser()->findWidgetNodes('MenuButton', 15, $menu)[0] ?? null;
        Assert::assertInstanceOf(UI5MenuButtonNode::class, $menuNode, 'Menu button "' . $menu . '" not found.');
        $menuNode->clickItem($item);
    }

    /**
     * Checks that a named MenuButton exposes all listed entries without triggering them.
     *
     * WHY this is menu-scoped: menu entries are list items in detached popovers, so ordinary button
     * lookup cannot see them and relying on a previously opened popover makes the result depend on
     * preceding steps. The node opens this exact menu and closes it after reading, including when an
     * assertion fails.
     *
     * Usage example:
     *
     *   Then the button menu "Aktionen" has items "Neu, Bearbeiten, Duplizieren"
     *
     * @Then the button menu :menu has item :expectedItems
     * @Then the button menu :menu has items :expectedItems
     *
     * @param string $menu Visible caption of the MenuButton to inspect
     * @param string $expectedItems Comma-separated entry captions expected to be present
     */
    public function buttonMenuHasItems(string $menu, string $expectedItems): void
    {
        $menuNode = $this->getBrowser()->findWidgetNodes('MenuButton', 15, $menu)[0] ?? null;
        Assert::assertInstanceOf(UI5MenuButtonNode::class, $menuNode, 'Menu button "' . $menu . '" not found.');
        $actualItems = $menuNode->getItemLabels();
        foreach ($this->explodeList($expectedItems) as $expectedItem) {
            Assert::assertContains(
                $expectedItem,
                $actualItems,
                'Menu "' . $menu . '" does not contain item "' . $expectedItem . '". Found: ' . implode(', ', $actualItems)
            );
        }
    }

    /**
     * Checks that a named MenuButton exposes none of the listed entries.
     *
     * WHY this has its own negative form: permission scenarios must prove that forbidden actions
     * are absent without clicking them and changing application state. Reading through the node also
     * guarantees that the inspected menu is closed before this assertion runs.
     *
     * @Then the button menu :menu does not have item :unexpectedItems
     * @Then the button menu :menu does not have items :unexpectedItems
     *
     * @param string $menu Visible caption of the MenuButton to inspect
     * @param string $unexpectedItems Comma-separated entry captions expected to be absent
     */
    public function buttonMenuDoesNotHaveItems(string $menu, string $unexpectedItems): void
    {
        $menuNode = $this->getBrowser()->findWidgetNodes('MenuButton', 15, $menu)[0] ?? null;
        Assert::assertInstanceOf(UI5MenuButtonNode::class, $menuNode, 'Menu button "' . $menu . '" not found.');
        $actualItems = $menuNode->getItemLabels();
        foreach ($this->explodeList($unexpectedItems) as $unexpectedItem) {
            Assert::assertNotContains(
                $unexpectedItem,
                $actualItems,
                'Menu "' . $menu . '" unexpectedly contains item "' . $unexpectedItem . '".'
            );
        }
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
    public function iClickTableOverflowButton(int|string|null $tableIndex = null): void
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
    public function iClickOverflowMenuItem(string $caption, int|string|null $tableIndex = null): void
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
     * Checks that the named tiles are shown.
     *
     * Tiles are the clickable cards on a launchpad/home page. Use this to confirm the expected
     * tiles are present. Other tiles may also be present - this step only requires the ones you
     * name. List tile captions separated by commas. If a widget is focused (e.g. a tab opened by
     * "I click tab"), only the tiles inside it count; without focus the whole page is checked.
     *
     * Usage example:
     *
     *   When I click tab "Admin"
     *   Then I see tiles "Orders, Customers, Reports"
     *
     * @Then I see tiles :tileNames
     *
     * @param string $tileNames Comma-separated list of expected tile captions
     */
    public function iSeeTiles(string $tileNames): void
    {
        $comparison = $this->compareTileCaptions($tileNames);

        Assert::assertEmpty(
            $comparison['missing'],
            'Tiles not found in ' . $this->getBrowser()->describeSearchScope() . ': ' . implode(', ', $comparison['missing'])
            . '. Tiles there: ' . implode(', ', array_merge($comparison['found'], $comparison['unexpected']))
        );
    }

    /**
     * Checks that exactly the named tiles are shown - no more, no less.
     *
     * Stricter than "I see tiles": this step also fails if any tile other than the ones you
     * list is present. Great for verifying that a user role sees precisely the expected set of
     * launchpad tiles. List tile captions separated by commas. If a widget is focused (e.g. a tab
     * opened by "I click tab"), only the tiles inside it count; without focus the whole page is checked.
     *
     * Usage example:
     *
     *   When I click tab "Admin"
     *   Then I only see tiles "Orders, Customers"
     *
     * @Then I only see tiles :tileNames
     *
     * @param string $tileNames Comma-separated list of the only tiles expected
     */
    public function iOnlySeeTiles(string $tileNames): void
    {
        $comparison = $this->compareTileCaptions($tileNames);
        $scope = $this->getBrowser()->describeSearchScope();

        Assert::assertEmpty($comparison['missing'], 'Tiles not found in ' . $scope . ': ' . implode(', ', $comparison['missing']));
        Assert::assertEmpty($comparison['unexpected'], 'Found more tiles than expected in ' . $scope . ': ' . implode(', ', $comparison['unexpected']));
    }

    /**
     * Checks that none of the named tiles are shown.
     *
     * The counterpart of "I see tiles". Use it for permission tests: confirm that a user role does not get
     * launchpad tiles it must not open. List tile captions separated by commas. If a widget is focused (e.g.
     * a tab opened by "I click tab"), only the tiles inside it count; without focus the whole page is checked.
     * The step fails if the checked area shows no tiles at all, because an empty area cannot prove that a
     * tile is hidden - it usually means the page or tab did not render.
     *
     * Usage example:
     *
     *   Given I log in to the page "my.app.home.html" as "Viewer"
     *   When I click tab "Admin"
     *   Then I do not see tiles "User management, Audit log"
     *
     * WHY IT REUSES compareTileCaptions(): the negative step must agree with "I see tiles" and "I click tile"
     * on what counts as a match (trimmed, case-insensitive). A separate loop could drift - e.g. compare
     * case-sensitively - and then a tile that "I click tile" can open would be reported as absent here.
     *
     * WHY A SCOPE WITHOUT ANY TILE FAILS INSTEAD OF PASSING: findTiles() requires at least one tile. For an
     * absence check this is the false-green guard - a page that is still loading, a tab that did not open or
     * a wrong page all show no tiles, and passing there would report "forbidden tile hidden" without ever
     * having looked at a rendered launchpad.
     *
     * WHY ONLY "found" IS ASSERTED: "missing" holds the forbidden captions that are indeed absent, which is
     * the expected outcome, and "unexpected" holds other tiles, which this step deliberately does not restrict.
     * A forbidden caption shown twice still lands in "found" once, which is enough to fail.
     *
     * @Then I do not see tiles :tileNames
     * @Then I do not see tile :tileNames
     *
     * @param string $tileNames Comma-separated list of tile captions expected to be absent
     */
    public function iDoNotSeeTiles(string $tileNames): void
    {
        $comparison = $this->compareTileCaptions($tileNames);

        Assert::assertEmpty(
            $comparison['found'],
            'Tiles expected to be absent are shown in ' . $this->getBrowser()->describeSearchScope() . ': '
            . implode(', ', $comparison['found'])
        );
    }

    /**
     * Compares the tiles of the current search scope with a comma-separated list of expected captions.
     *
     * WHY IT EXISTS: "I see tiles" and "I only see tiles" ran the same matching loop in two copies, and
     * the copies already differed in their messages. One implementation keeps both steps agreeing on what
     * counts as a match; they differ only in which part of the result they assert.
     *
     * WHY CASE-INSENSITIVE: "I click tile" matches captions case-insensitively. A step that can click a tile
     * but reports the same tile as not seen would contradict itself.
     *
     * WHY A MATCH IS CONSUMED: each expected caption accounts for one tile only, so a second tile with the
     * same caption is reported as unexpected instead of hiding behind the first.
     *
     * @param string $tileNames Comma-separated list of expected tile captions
     * @return array{missing: string[], found: string[], unexpected: string[]}
     */
    private function compareTileCaptions(string $tileNames): array
    {
        $missing = $this->explodeList($tileNames);
        $found = [];
        $unexpected = [];

        foreach ($this->getBrowser()->findTiles() as $tile) {
            $tileCaption = trim($tile->getCaption());
            $matchIndex = null;
            foreach ($missing as $index => $expectedCaption) {
                if (strcasecmp($expectedCaption, $tileCaption) === 0) {
                    $matchIndex = $index;
                    break;
                }
            }

            if ($matchIndex !== null) {
                unset($missing[$matchIndex]);
                $found[] = $tileCaption;
            } else {
                $unexpected[] = $tileCaption;
            }
        }

        return [
            'missing' => array_values($missing),
            'found' => $found,
            'unexpected' => $unexpected,
        ];
    }

    /**
     * Clicks a tile by its caption and checks that the page configured for it has been opened.
     *
     * Tiles are the clickable cards on a launchpad/home page. This step clicks the tile with the given
     * caption, waits until the new page is loaded and fails if the tile opened another page than the one it
     * is configured for - or no page at all. If a widget is focused (e.g. a tab opened by "I click tab"),
     * the tile is looked for ONLY inside it; without focus the whole page is searched. Use "I see tiles" if
     * you only want to check that a tile is there.
     *
     * Usage example:
     *
     *   Given I log in to the page "my.app.home.html" as "Support"
     *   When I click tab "Admin"
     *   And I click tile "Interfaces"
     *   Then I see 1 widget of type "DataTable"
     *
     * WHY THE LOOKUP GOES THROUGH findTiles(): all tile steps must agree on where tiles are searched - the
     * focused widget only, or the page when nothing is focused. The rule lives there once.
     *
     * WHY THE CLICK IS DELEGATED TO THE NODE: the button node - which the tile node extends - owns the rule
     * for verifying a GoToPage target, and its works-as-expected check uses the same method. Reusing it keeps
     * both paths judging a navigation the same way.
     *
     * WHY AN AMBIGUOUS CAPTION FAILS: two tiles with the same caption in the searched scope cannot be told
     * apart by the step. Clicking the first in DOM order would make the outcome depend on rendering order
     * instead of on the scenario.
     *
     * WHY THE FOCUS IS CLEARED: widgets focused on the tile page mean nothing on the target page, and with
     * SPA routing the previous view stays in the DOM, so pruning dead focus entries would never drop them -
     * the next "it has..." step would silently act on the page that was left.
     *
     * @When I click tile ":caption"
     *
     * @param string $caption Caption of the tile to click
     * @return void
     * @throws RuntimeException If the tile is missing, ambiguous, opens no page or opens the wrong page
     */
    public function iClickTile(string $caption): void
    {
        $browser = $this->getBrowser();
        $needle = trim($caption);

        $matches = [];
        $visibleCaptions = [];
        foreach ($browser->findTiles() as $tile) {
            $tileCaption = trim($tile->getCaption());
            $visibleCaptions[] = $tileCaption;
            if (strcasecmp($tileCaption, $needle) === 0) {
                $matches[] = $tile;
            }
        }

        if (empty($matches)) {
            throw new RuntimeException(
                'Tile "' . $needle . '" not found in ' . $browser->describeSearchScope()
                . '. Visible tiles there: ' . implode(', ', $visibleCaptions)
            );
        }
        if (count($matches) > 1) {
            throw new RuntimeException('Tile "' . $needle . '" is shown ' . count($matches) . ' times - the step cannot tell which one is meant');
        }
        $tile = $matches[0];

        if (! $tile instanceof UI5ButtonNode) {
            throw new RuntimeException('Tile "' . $needle . '" is represented by ' . get_class($tile) . ', which cannot verify page navigation');
        }

        $browser->highlightWidget($tile->getNodeElement(), 'Tile', 0);
        $tile->clickAndAssertTargetPage();
        $browser->clearFocusStack();
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
     * @param int|string|null $tableIndex Optional 1-based table index to scope the check
     */
    public function iDoNotSeeButton(string $unexpectedButtons, int|string|null $tableIndex = null): void
    {
        $scopeNodes = [];
        $tableNumber = $this->parseTableIndex($tableIndex);
        if ($tableNumber !== null) {
            $scopeNodes[] = $this->getWidgetNodeByIndex('DataTable', $tableNumber);
        }

        $result = $this->findVisibleButtons($unexpectedButtons, null, $scopeNodes);
        Assert::assertNull(
            $result['overflowHint'],
            'Cannot prove that the requested buttons are absent because the overflow search was incomplete.'
            . ($result['overflowHint'] ?? '')
        );
        Assert::assertEmpty(
            $result['found'],
            (count($result['found']) === 1 ? 'Unexpected button found: ' : 'Unexpected buttons found: ')
            . implode(', ', array_keys($result['found']))
        );
    }

    /**
     * Checks that the named tabs are shown on the page.
     *
     * Confirms that expected tabs (page or dialog sections) are available. Name one or more
     * tabs separated by commas. Each found tab is briefly highlighted.
     *
     * If you are looking at a dialog or widget that has tabs, only its tabs are checked. This matters
     * for dialogs: the page behind them may carry tabs with the very same captions.
     *
     * Usage examples:
     *
     *   Then I see 1 widget of type "Dialog" with "Öffnen: ABC"
     *   Then I see tabs "General, Addresses, History"
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
        $browser = $this->getBrowser();

        foreach ($tabs as $index => $tab) {
            $foundedTab = $browser->findTabByCaption($tab, $browser->getTabSearchParent($tab));
            Assert::assertNotNull($foundedTab, "The Tab " . $tab . " is not found!");
            $browser->highlightWidget($foundedTab, "Tab", $index);
        }
    }

    /**
     * Checks that tabs appear left-to-right in the exact order you list.
     *
     * Unlike "I see tabs", which only checks that tabs exist, this step pins down their order on screen -
     * useful after a layout change. The dialog or page may contain more tabs than you list; this step only
     * checks that the ones you name appear in the stated order relative to each other.
     *
     * If you are looking at a dialog, only its tabs are read. The page behind it may carry tabs with the
     * very same captions.
     *
     * Usage example:
     *
     *   Then I see 1 widget of type "Dialog"
     *   And the tabs are displayed in the following order "General, Addresses, History"
     *
     * @Then the tabs are displayed in the following order :tabList
     *
     * @param string $tabList Comma-separated tab captions in the expected order.
     */
    #[ResumeSafeStep]
    public function theTabsAreDisplayedInTheFollowingOrder(string $tabList): void
    {
        UI5AbstractNode::assertCaptionsDisplayedInOrder(
            $this->explodeList($tabList),
            $this->getBrowser()->getTabCaptionsInOrder(),
            'tab'
        );
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
    public function testDataIsLoaded(string $appAlias, string $subfolder): void
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
     * Drops the XHR log left behind by the previous scenario, so its requests cannot be mistaken
     * for the requests of the scenario about to run.
     *
     * WHY THE NULL CHECK IS ON THE PROPERTY, NOT ON getBrowser(): the browser is created lazily by
     * the first step that opens a page, so before the very first scenario of a worker process there
     * is none. getBrowser() reports that as a "BDT Browser not initialized!" exception rather than
     * returning null, so guarding with `if ($this->getBrowser())` could never be false - it threw
     * out of a BeforeScenario hook, which makes Behat exit with code 255 before a single step ran.
     * Every other hook in this context guards the property directly for the same reason.
     *
     * Must never throw - see above.
     *
     * @BeforeScenario
     */
    public function resetAjaxLog(BeforeScenarioScope $scope): void
    {
        if ($this->browser === null) {
            return;
        }

        try {
            $this->getBrowser()->clearXHRLog();
            $this->logDebug("\nXHR logs cleared before scenario: " . $scope->getScenario()->getTitle() . "\n");
        } catch (\Throwable $e) {
            // Clearing the log is housekeeping - a dead CDP connection here must not abort the run.
            // The scenario's own steps surface the broken browser through the normal error handling.
            $this->logDebug('resetAjaxLog failed: ' . $e->getMessage());
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

        // Role reset is housekeeping and runs against a database that may be exactly what is broken.
        // An exception escaping a destructor is fatal during shutdown, and it would also skip the
        // workbench stop below - leaking connections and losing the orderly close-out over a cleanup
        // detail. Same reasoning as the Chrome reaper: reclaim first, never propagate.
        try {
            UI5Browser::resetUser($this->workbench);
        } catch (\Throwable $e) {
            $this->workbench->getLogger()->logException($e);
        }

        try {
            $this->workbench->stop();
        } catch (\Throwable $e) {
            // Nothing left to save the run with - swallow so shutdown completes.
        }
    }

    protected function getBrowser(): UI5Browser
    {
        // Only steps are refused: a recovery logs in and navigates through this same method, and refusing it there
        // would make every recovery of an already stopped scenario fail halfway.
        if ($this->unrestorableStateReason !== null && ! $this->chromeRecoveryInProgress) {
            throw new RuntimeException($this->unrestorableStateReason);
        }
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

    /**
     * Splits a comma-separated caption list from a step argument into trimmed captions.
     *
     * WHY EMPTY ENTRIES ARE REJECTED: every caller treats the result as captions that must be checked. An
     * empty entry - from an empty argument like "" or a stray comma like "Orders, " - matches no rendered
     * caption. In absence checks ("I do not see tiles/columns/filters", "does not have items") that made the
     * step pass without checking anything, a false green. In presence checks it failed with an empty name
     * in the message, which hides the actual typo in the feature file.
     *
     * @param string $list Comma-separated captions as written in the feature file
     * @return string[]
     * @throws RuntimeException If the list contains an empty entry
     */
    protected function explodeList(string $list): array
    {
        $items = array_map('trim', explode(',', $list));

        if (in_array('', $items, true)) {
            throw new RuntimeException(
                'The list "' . $list . '" contains an empty entry. Check the feature file for a missing caption or a stray comma.'
            );
        }

        return $items;
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
        $title = $node->getCaption() === null ||  $node->getCaption() === "" ? $node->getWidgetType() : $node->getCaption();
        Assert::assertNotTrue($result->isFailed(), 'Widget "' . $title . '" did not work as expected: ' . ($result->getException()?->getMessage() ?? 'see substeps for details'));
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
     * @throws \Throwable
     */
    private function navigateToPageAlias(string $pageAlias): void
    {
        $this->getEventDispatcher()->dispatch(new AfterPageVisited($pageAlias));
        
        // Navigate to the page using Mink's path navigation
        $url = $pageAlias . '.html';
        $this->visitPath('/' . $url);
        $this->logDebug("Debug - New page is loading: {$url}\n");

        // Initialize the UI5Browser with the current session and URL
        $this->createBrowser($url);
    }

    /**
     * Builds a UI5Browser for the given URL and hands it everything the context owns.
     *
     * WHY THIS EXISTS: a UI5Browser is navigation-scoped - a new one is constructed on every page
     * change - while several pieces of state it needs are scenario-scoped and known only to the
     * context. Construction and hand-over were two separate steps repeated at each construction
     * site, so a site that forgot the second step produced a browser that looked healthy and
     * silently lacked part of its state. Building and binding in one place makes that impossible:
     * there is no way to obtain a browser that has not been bound.
     *
     * @param string $url URL the browser is being opened on, used for the initial load wait
     * @param Session|null $session Session to bind to, or null to use the context's current one
     * @return void
     */
    private function createBrowser(string $url, ?Session $session = null): void
    {
        $this->browser = new UI5Browser(
            $this->getWorkbench(),
            $session ?? $this->getSession(),
            $this->getEventDispatcher(),
            $url,
            $this->getLocale()
        );
        $this->wireBrowserToScenario();
    }

    /**
     * Re-attaches the per-scenario state a freshly built UI5Browser cannot know about.
     *
     * WHY THIS EXISTS: navigateToPageAlias() constructs a NEW UI5Browser on every navigation,
     * so anything the context established on the previous instance is gone the moment the
     * scenario moves to another page. Everything that must outlive a navigation is restored
     * here, in one place, instead of being re-set by whichever caller happens to remember.
     *
     * WHY THE ROLES BELONG HERE: the role set is not an observation of the application, it is
     * the label the framework attaches to what it validated. The browser session stays
     * authenticated across a navigation and the server keeps enforcing the same roles - only
     * the framework's record of them was being dropped. Losing that label collapses two
     * genuinely different role environments onto the same value, which is exactly what the
     * label exists to keep apart.
     */
    private function wireBrowserToScenario(): void
    {
        $this->getBrowser()->setNavigator(function (string $pageAlias): void {
            $this->navigateToPageAlias($pageAlias);
        });

        $this->getBrowser()->setScreenshotFn(function () {
            $this->captureScreenshot();
        });

        // Bridges Chrome recovery from deep node classes back to the context.
        $this->getBrowser()->setChromeRecoveryFn(function (string $targetPageAlias): void {
            $this->recoverChrome($targetPageAlias);
        });

        // Restore the roles of the current scenario onto the new browser instance. NULL means no
        // login step has run yet in this scenario, which is not the same as "the user has no
        // roles" - a roleless user cannot log in at all. The two cases must stay distinguishable,
        // so nothing is written here when the roles are unknown.
        if ($this->lastLoginUserRoles !== null) {
            $this->getBrowser()->setCurrentRoles($this->lastLoginUserRoles);
        }
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
     * The failure message reports WHICH phase broke and WHAT recovery was attempted per round.
     * Without that, a lost pre-navigation wait and a lost navigation are indistinguishable in the
     * report, and a silently failed session reattach looks exactly like no reattach at all - the
     * two states that have to be told apart when this error shows up on a browser that is still
     * visibly alive and logged in.
     *
     * @param string $path The relative path to visit
     * @throws \Throwable  The last exception if all attempts fail
     */
    public function visitPath($path, $sessionName = null, int $maxAttempts = self::VISIT_RETRY_MAX_ATTEMPTS): void
    {
        $attempt = 0;
        // Which of the two things inside the try was running when it threw. The distinction is the
        // whole point: "the browser never settled" and "the navigation itself failed" have
        // different causes and different fixes, but produce the same exception type here.
        $phase = 'navigation';
        $recoveryLog = [];
        while (true) {
            try {
                // Wait for any pending operations before navigating to ensure the
                // browser is in a clean state. Skipped on the first visit because
                // the browser is not yet initialised at that point.
                if ($this->browser !== null) {
                    $phase = 'pre-navigation wait';
                    $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);
                }
                $phase = 'navigation';
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
                    // $this->browser instead of getBrowser(): the browser is not initialised yet on
                    // the very first visit, and getBrowser() throws "BDT Browser not initialized!"
                    // there - replacing the real CDP cause with a misleading error.
                    throw new BrowserDriverException(
                        $this->getSession(),
                        'Cannot open path "' . $path . '" in browser after ' . $attempt . ' attempts.'
                        . ' Failing phase: ' . $phase . '.'
                        . ' Recovery: ' . ($recoveryLog === [] ? 'none attempted' : implode('; ', $recoveryLog)) . '.'
                        . ' Last driver error: ' . $e->getMessage(),
                        null,
                        $e,
                        $this->browser
                    );
                }
                $this->logDebug('visitPath("' . $path . '") hit a CDP connection error during ' . $phase . ' on attempt ' . $attempt . ': ' . $e->getMessage());
                // WHY: a CDP error here has two distinct causes needing different handling and the
                // browser process is the only reliable way to tell them apart.
                //
                // Chrome is GONE: every retry would hit the same dead socket, so the process must be
                // restarted first. ensureChromeAlive() does that (and replays the login when one
                // already happened) and never throws, so calling it here is safe.
                //
                // Chrome is ALIVE: the process still answers /json/version and the page may even have
                // loaded already - what broke is THIS session's WebSocket to it. Retrying over that
                // stale socket fails identically on every attempt and produces the misleading
                // "Cannot open path ... after N attempts" for a page that is visibly open in the
                // browser. Reattaching the session gives the retry a working connection. Chrome keeps
                // running, so its cookies - and therefore the login - survive, and no re-login is
                // needed. A failed reattach is not fatal here: the retry still runs and either
                // succeeds or ends in the regular error above - but it IS recorded, because a
                // reattach that never worked is the single most useful fact when triaging this error.
                if (ChromeManager::getInstance()->isAlive()) {
                    $recoveryLog[] = 'attempt ' . $attempt . ': Chrome alive, session reattach '
                        . ($this->reconnectSession() ? 'succeeded' : 'FAILED');
                } else {
                    $this->ensureChromeAlive();
                    $recoveryLog[] = 'attempt ' . $attempt . ': Chrome not reachable, restart requested';
                }
                // CDP transient — give a still-alive-but-slow browser time to settle, then retry.
                $this->sleepBeforeVisitRetry($attempt);
            }
        }
    }

    /**
     * Reattaches the Mink session to the Chrome process that is already running.
     *
     * Use this when Chrome itself is healthy but this session's CDP/WebSocket connection
     * broke: the driver is forced out of its started state and reconnected, so the next
     * driver call opens a fresh socket instead of reusing the dead one. Because the Chrome
     * process is left untouched, its cookies and therefore the current login survive - no
     * re-authentication is required, unlike a full Chrome restart via recoverChrome().
     *
     * Deliberately not Session::restart(): that calls stop() and start() unguarded, and on a
     * broken socket the stop() throws - so start() never runs and the session stays dead. Here
     * the stop() failure is expected and swallowed on purpose; only start() has to succeed.
     *
     * Never throws, so it is safe to call from retry loops and hooks: a failed reattach is
     * reported as FALSE and logged, leaving the caller free to retry or fail normally.
     *
     * @return bool TRUE if the session was reconnected, FALSE if reconnecting failed.
     */
    private function reconnectSession(): bool
    {
        $manager = ChromeManager::getInstance();
        // Snapshot taken BEFORE the reconnect: whatever tabs are open right now belong to the
        // session that is about to be discarded. It is read over HTTP on purpose - that endpoint
        // is served by the browser process and keeps answering while this session's socket is dead.
        $tabsBefore = $this->listPageTargetIds($manager);

        try {
            // Talking to the broken socket is expected to fail - the point is not a clean
            // shutdown but forcing the driver out of its "started" state so start() reconnects.
            try {
                $this->getSession()->stop();
            } catch (\Throwable $ignored) {}
            $this->getSession()->start();
        } catch (\Throwable $e) {
            $this->logDebug('Could not reattach the Mink session: ' . $e->getMessage());
            return false;
        }

        // Only after a successful reconnect: a failed one leaves the old tab as the only thing
        // the run might still be able to fall back on, so it must not be closed.
        // WHY GUARDED: the session IS attached at this point - the tab cleanup is housekeeping. Letting a
        // cleanup failure escape turned a successful reattach into an aborted recovery (no re-login) and
        // broke the never-throws contract that visitPath() and the hooks rely on.
        try {
            $this->closeTabsLeftBehind($manager, $tabsBefore);
        } catch (\Throwable $e) {
            $this->logDebug('Session reattached, but closing the tabs left behind failed: ' . $e->getMessage());
        }
        $this->logDebug('Mink session reattached to the running Chrome after a lost CDP connection.');
        return true;
    }

    /**
     * Returns the target IDs of Chrome's open page tabs.
     *
     * WHY IT FILTERS ON TYPE: /json/list also reports service workers, iframes and extension
     * targets. Closing one of those would break the browser in ways that look nothing like a tab
     * leak, so only real pages are ever considered.
     *
     * WHY AN EMPTY RESULT IS NOT AN ERROR: getTabList() returns an empty array both when Chrome
     * has no tabs and when the endpoint could not be reached at all. Callers here treat "no known
     * tabs" as "nothing to clean up", which is the safe reading of either case.
     *
     * @param ChromeManager $manager The manager owning the Chrome process to query.
     * @return string[] Target IDs of all open page tabs.
     */
    private function listPageTargetIds(ChromeManager $manager): array
    {
        $ids = [];
        foreach ($manager->getTabList() as $tab) {
            if (($tab['type'] ?? null) === 'page' && ($tab['id'] ?? '') !== '') {
                $ids[] = $tab['id'];
            }
        }
        return $ids;
    }

    /**
     * Closes the tabs that the previous, disconnected session left open.
     *
     * WHY THE "NEW TAB APPEARED" GUARD: the driver is expected to open a fresh tab on start(), and
     * only then are the tabs from the snapshot certainly orphaned. If no new tab shows up, the
     * driver attached to an EXISTING one - closing anything from the snapshot would then close the
     * tab the session is using at that very moment. Verifying the assumption against Chrome's own
     * tab list instead of trusting driver behaviour keeps this cleanup from becoming a new failure
     * mode when the pinned driver version changes.
     *
     * WHY IT MATTERS: reconnects happen repeatedly over a long lane, and Chrome memory pressure is
     * the known root cause of unrecoverable lanes. One leaked tab per reconnect is exactly the kind
     * of slow leak that only shows up hours into a parallel run.
     *
     * @param ChromeManager $manager   The manager owning the Chrome process.
     * @param string[]      $idsBefore Page target IDs captured before the reconnect.
     */
    private function closeTabsLeftBehind(ChromeManager $manager, array $idsBefore): void
    {
        $idsAfter = $this->listPageTargetIds($manager);
        if (array_diff($idsAfter, $idsBefore) === []) {
            $this->logDebug('Session reattach opened no new tab - leaving the existing tabs untouched.');
            return;
        }
        foreach (array_intersect($idsBefore, $idsAfter) as $staleId) {
            $manager->closeTab($staleId);
            $this->logDebug('Closed the tab left behind by the disconnected session: ' . $staleId);
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
     * Records where a recovered Chrome has to continue if it is lost after this step.
     *
     * WHY AT THE END OF A PASSED STEP: that is the state the next step expects to start from.
     *
     * WHY THE CASES:
     * - fresh document: a full page load discarded all earlier in-browser state, so nothing before it can block;
     * - same document, URL changed: the step moved the route (e.g. opened a dialog). The URL reproduces the new
     *   view; whatever the current view had built now belongs to a view below it;
     * - same document, URL unchanged, resume-safe step: only focus or nothing changed - rebuilt from $resumeFocus;
     * - same document, URL unchanged, any other step: the current view now holds state that cannot be rebuilt.
     *
     * WHY THE LEAVE CHECK COMES FIRST: after a resume with lost state below, a step that leaves the resumed view
     * ran correctly inside it - but the next step would work on views whose state is gone.
     *
     * @param AfterStepScope $scope The step that just passed
     */
    private function recordResumePoint(AfterStepScope $scope): void
    {
        $browser = $this->getBrowser();
        // Same source the failure screenshots record: window.location.href, including the route fragment.
        $url = $this->getSession()->getCurrentUrl();
        $step = $scope->getStep();
        $stepLabel = 'line ' . $step->getLine() . ': ' . $step->getKeyword() . ' ' . $step->getText();

        if ($this->recoveredRouteUrl !== null && $url !== $this->recoveredRouteUrl && $this->unrestorableStateReason === null) {
            $this->unrestorableStateReason = 'Chrome was lost earlier and the scenario was resumed in the view "'
                . $this->recoveredRouteUrl . '". Step ' . $stepLabel . ' has now left that view, but the state these steps '
                . 'had built in the views below it was lost in the recovery: ' . implode('; ', $this->lostEarlierViewState)
                . '. Running the remaining steps on a different state could hide real failures, so the scenario stops here.';
            $this->reportChromeRecovery($this->unrestorableStateReason);
        }

        if ($this->resumeBrowser?->get() !== $browser) {
            $this->blockersInCurrentView = [];
            $this->blockersInEarlierViews = [];
        } elseif ($url !== $this->resumeUrl) {
            $this->blockersInEarlierViews = array_merge($this->blockersInEarlierViews, $this->blockersInCurrentView);
            $this->blockersInCurrentView = [];
        } elseif (! $this->isResumeSafeStep($scope)) {
            $this->blockersInCurrentView[] = $stepLabel;
        }

        $this->resumeUrl = $url;
        $this->resumePageAlias = $browser->getPageAliasFromCurrentUrl();
        $this->resumeFocus = $browser->describeFocusForResume();
        $this->resumeBrowser = \WeakReference::create($browser);
    }

    /**
     * Tells whether the step that just passed is marked as safe to resume after a Chrome recovery.
     *
     * WHY THE DEFINITION'S REFLECTION: the attribute sits on the step method; Behat exposes the matched method
     * through the executed step's definition, so no list of step names has to be kept in sync by hand.
     *
     * @param AfterStepScope $scope The step that just passed
     * @return bool TRUE only if the matched step method carries #[ResumeSafeStep]
     */
    private function isResumeSafeStep(AfterStepScope $scope): bool
    {
        $result = $scope->getTestResult();
        if (! $result instanceof DefinedStepResult) {
            return false;
        }
        $definition = $result->getStepDefinition();
        if ($definition === null) {
            return false;
        }
        return $definition->getReflection()->getAttributes(ResumeSafeStep::class) !== [];
    }

    /**
     * Brings a recovered, logged-in Chrome back to where the scenario stopped - or records why it cannot.
     *
     * WHY A REFUSAL IS NOT A RECOVERY FAILURE: Chrome is back and logged in, which the next scenario needs anyway.
     * A refusal only stops THIS scenario; getBrowser() fails the next step with the reason.
     *
     * WHY ONLY THE CURRENT VIEW BLOCKS: see $blockersInEarlierViews. Lost state of views below is recorded and
     * enforced by recordResumePoint() when the scenario leaves the resumed view.
     *
     * WHY THE ROUTE AND THE FOCUS ARE VERIFIED: UI5 does not report a route the facade cannot open, and a missing
     * focus silently widens every following search to the whole page. Both must be proven, not assumed.
     *
     * WHY THE REASON IS SET IN finally: if anything below throws after a browser was built, the scenario would
     * otherwise continue on a partially restored state with no block in place.
     */
    private function resumeScenarioAfterRecovery(): void
    {
        if ($this->unrestorableStateReason !== null) {
            // A new Chrome does not bring back state that was already lost before this recovery.
            $this->reportChromeRecovery('Not resuming - the scenario had already been stopped before this recovery.');
            return;
        }

        $reason = 'the recovery stopped before the browser state could be restored.';
        try {
            if ($this->resumeUrl === null || $this->resumePageAlias === null) {
                $reason = 'the state at the end of the last passed step could not be recorded.';
                return;
            }
            if ($this->blockersInCurrentView !== []) {
                $reason = 'these steps changed the current view without changing its URL, and that state cannot be rebuilt: '
                    . implode('; ', $this->blockersInCurrentView) . '.';
                return;
            }
            if ($this->resumeFocus === null) {
                $reason = 'the widget the scenario was looking at cannot be rebuilt (a focused tab, or an element without a stable id).';
                return;
            }

            $this->navigateToPageAlias($this->resumePageAlias);

            $expectedRoute = (string) StringDataType::substringAfter($this->resumeUrl, '#', '');
            $actualRoute = (string) StringDataType::substringAfter($this->getSession()->getCurrentUrl(), '#', '');
            if ($expectedRoute !== $actualRoute) {
                $this->getBrowser()->navigateToRouteFragment($expectedRoute);
                $actualRoute = (string) StringDataType::substringAfter($this->getSession()->getCurrentUrl(), '#', '');
            }
            if ($expectedRoute !== $actualRoute) {
                $reason = 'the UI5 route could not be re-opened (expected "#' . $expectedRoute . '", browser is on "#' . $actualRoute . '").';
                return;
            }

            try {
                $this->getBrowser()->restoreFocusForResume($this->resumeFocus);
            } catch (\Throwable $e) {
                $reason = 'the widget the scenario was looking at could not be rebuilt: ' . $e->getMessage();
                return;
            }

            // Merged, not replaced: a second recovery must not forget what a first one already lost.
            $this->lostEarlierViewState = array_merge($this->lostEarlierViewState, $this->blockersInEarlierViews);
            if ($this->lostEarlierViewState !== []) {
                $this->recoveredRouteUrl = $this->resumeUrl;
            }
            $reason = null;
        } finally {
            if ($reason === null) {
                $this->reportChromeRecovery(
                    'Resumed the scenario at ' . $this->resumeUrl
                    . ($this->lostEarlierViewState === []
                        ? ''
                        : '. State of the views below was lost and the scenario stops if it leaves this view: '
                        . implode('; ', $this->lostEarlierViewState))
                );
            } else {
                $this->unrestorableStateReason = 'Chrome was lost and has been restarted and logged in again, but this scenario cannot continue: '
                    . $reason . ' Running the remaining steps on a different state could hide real failures, so the scenario stops here.';
                $this->reportChromeRecovery($this->unrestorableStateReason);
            }
        }
    }

    /**
     * Forgets everything a Chrome recovery decided about this scenario's state.
     *
     * WHY: an explicit login or page visit builds the scenario's state anew, so neither a stopped scenario nor the
     * lost state of views below a resumed dialog matters from there on.
     *
     * WHY NOT DURING A RECOVERY: recoverChrome() logs in and visits pages through the same step methods. Clearing
     * there would erase what a previous recovery lost, and a second recovery would resume as if nothing was lost.
     */
    private function forgetRecoveryState(): void
    {
        if ($this->chromeRecoveryInProgress) {
            return;
        }
        $this->unrestorableStateReason = null;
        $this->recoveredRouteUrl = null;
        $this->lostEarlierViewState = [];
    }

    /**
     * Recovers from a lost, hung or closed Chrome process and brings the scenario back to where it stopped.
     *
     *  1. Instructs ChromeManager to terminate the stale Chrome process and start a fresh one on the same port.
     *  2. Reattaches the Mink session to the new Chrome and fails loudly if that is not possible.
     *  3. Discards the UI5Browser of the dead page, so the next visit behaves like a first visit.
     *  4. Re-authenticates the browser with the login form values cached by iLogInToPage().
     *  5. Opens the page the caller asked for, or resumes the scenario at the end of its last passed step.
     *
     * WHY TWO KINDS OF TARGET: a container sweep retrying one child owns its own state and only needs its
     * page back. The step hooks and a hung substep continue a scenario whose state was built by earlier
     * steps, so they must get that state back - or the scenario must be stopped (resumeScenarioAfterRecovery()).
     *
     * WHY THE STALE BROWSER IS DISCARDED: it belongs to a page of the Chrome that was just killed. Keeping it
     * made visitPath() run its pre-navigation UI5 wait on the blank tab of the new Chrome, which can never
     * report "not busy", so the login visit timed out after 30 s.
     *
     * WHY A FAILED REATTACH ABORTS: continuing on a session bound to the dead process only produces socket
     * errors later, far away from the actual cause.
     *
     * @param string $targetPageAlias Alias of a page to open after recovery, or an empty string to resume
     *                                the scenario at the end of its last passed step
     * @throws \Throwable If no login parameters are stored, a recovery is already running, or a recovery step fails
     */
    public function recoverChrome(string $targetPageAlias): void
    {
        if ($this->lastLoginUrl === null) {
            throw new RuntimeException(
                'Cannot recover Chrome: no login parameters stored. '
                . 'Ensure iLogInToPage() was called before the test started.'
            );
        }

        if ($this->chromeRecoveryInProgress) {
            throw new RuntimeException(
                'Chrome recovery was requested while another recovery is still running - Chrome was lost again '
                . 'during recovery. No nested restart is started; the running recovery fails instead.'
            );
        }
        $this->chromeRecoveryInProgress = true;

        // Printed BEFORE anything can fail, so the output always shows what the recovery started from.
        $this->reportChromeRecovery(
            'Chrome was lost - restarting it and logging in again. Target: '
            . ($targetPageAlias !== ''
                ? 'page "' . $targetPageAlias . '" (requested by the caller)'
                : 'resume point ' . ($this->resumeUrl ?? '(unknown)'))
            . '. Blocking steps in the current view: '
            . ($this->blockersInCurrentView === [] ? 'none' : implode('; ', $this->blockersInCurrentView))
            . '. State of views below (lost, enforced when the scenario leaves the resumed view): '
            . ($this->blockersInEarlierViews === [] ? 'none' : implode('; ', $this->blockersInEarlierViews)) . '.'
        );

        try {
            ChromeManager::getInstance()->restart();

            if (! $this->reconnectSession()) {
                throw new RuntimeException(
                    'Chrome was restarted, but the Mink session could not be attached to it. '
                    . 'See the debug line "Could not reattach the Mink session" for the driver error.'
                );
            }

            $this->browser = null;

            // Re-authenticate the BROWSER only; setupUser() must not run again (USER_AUTHENTICATOR optimistic lock).
            $this->browserLogin(
                $this->lastLoginUrl,
                $this->lastLoginTabCaption,
                $this->lastLoginButtonCaption,
                $this->lastLoginFields
            );

            if ($targetPageAlias !== '') {
                $this->navigateToPageAlias($targetPageAlias);
            } else {
                $this->resumeScenarioAfterRecovery();
            }
        } finally {
            $this->chromeRecoveryInProgress = false;
        }
    }

    /**
     * Makes sure a usable Chrome exists BEFORE the next step runs, restarting and re-authenticating it if the
     * current one is gone.
     *
     * WHY PROACTIVE INSTEAD OF REACTIVE: a dead browser used to be noticed only when some call crashed into it.
     * Mink manages its sessions with its OWN hooks, and a socket exception thrown there escapes every guard this
     * context owns - Behat then dies with exit code 255. Probing liveness before the step replaces a dead Chrome
     * while we are still inside code we control.
     *
     * WHY IT MUST NEVER THROW: it runs from the BeforeStep hook, where an uncaught exception kills the Behat
     * process. A failed recovery is reported and the step is allowed to run and fail normally.
     */
    private function ensureChromeAlive(): void
    {
        try {
            $manager = ChromeManager::getInstance();

            // The port, not the PID, is the reliable "has Chrome ever been started" marker (see ChromeManager).
            if ($manager->getPort() === null) {
                return;
            }

            if ($manager->isAlive()) {
                return;
            }

            if ($this->lastLoginUrl === null) {
                $this->reportChromeRecovery('Triggered before a step, before any login - restarting Chrome only, there is no state to restore.');
                ChromeManager::getInstance()->restart();
                $this->reconnectSession();
                return;
            }

            $this->recoverChromeInHook('Triggered before a step. The scenario continues if its state can be restored.');

        } catch (\Throwable $e) {
            $this->reportChromeRecovery('FAILED before a step: ' . $e->getMessage());
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