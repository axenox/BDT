<?php
namespace axenox\BDT\Behat\BrowserManagers;

use axenox\BDT\Exceptions\ConfigException;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\OutOfBoundsException;
use exface\Core\Factories\ConfigurationFactory;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\ConfigurationInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Creates, validates and holds the process-wide browser manager.
 *
 * WHY IT EXISTS: formatter, browser context and run actions must share ONE manager (one browser
 * lifecycle) without knowing its concrete class, and a manager must never be handed out unvalidated.
 * The factory is the only place that turns configuration into a manager.
 *
 * CONFIGURATION: like the metamodel connector (METAMODEL.CONNECTOR / METAMODEL.CONNECTOR_CONFIG), the
 * app-config key BROWSER.MANAGER selects the class and BROWSER.MANAGER_CONFIG holds that class's UXON
 * configuration. Settings the class does not understand fail loudly with the UxonMapError thrown by
 * the UXON import - the factory never catches it.
 *
 * CONFIG LAYERS: core merges the shipped app, installation and user configs, but hasOption() with
 * INSTALLATION or USER scope checks presence in that particular file. We use those checks to distinguish
 * an operator's manager choice from the shipped selector: a higher-scope manager wins, otherwise any
 * higher-scope legacy key selects the legacy path, otherwise the shipped BROWSER defaults apply.
 * An explicitly blank effective selector continues to opt into legacy selection.
 * Legacy local settings overlay the shipped local defaults; if a higher scope replaces the config
 * object, ConfigurationFactory loads the packaged file separately to recover those defaults.
 * A remote manager never imports local defaults.
 * Only legacy keys actually present in those scopes are named in the deprecation warning.
 *
 * LIFECYCLE: the DatabaseFormatter constructor calls initInstance() once per Behat process; everything
 * else retrieves the manager with getInstance(). RunTest and RunParallel call create() for a
 * short-lived coordinator manager that is not the shared instance. The manager's start() and stop()
 * are NOT called here.
 */
class BrowserManagerFactory
{
    private const APP_ALIAS = 'axenox.BDT';
    private const CFG_MANAGER = 'BROWSER.MANAGER';
    private const CFG_MANAGER_CONFIG = 'BROWSER.MANAGER_CONFIG';
    private const CFG_LEGACY_ENDPOINT = 'REMOTE.CHROME_ENDPOINT';
    private const CFG_LEGACY_BASE_URL = 'REMOTE.BASE_URL';
    private const LEGACY_KEYS = [
        self::CFG_LEGACY_ENDPOINT,
        self::CFG_LEGACY_BASE_URL,
        'PARALLEL.CHROME_PATH',
        'PARALLEL.CHROME_HEADLESS',
        'PARALLEL.PORT_BAND_INTERACTIVE',
        'PARALLEL.PORT_BAND_SCHEDULED'
    ];

    private static ?BrowserManagerInterface $instance = null;
    private static bool $legacyWarningLogged = false;

    /**
     * Builds a validated manager from app configuration plus runtime overrides.
     *
     * WHY OVERRIDES WIN: the app config supplies installation-wide defaults, while the per-run values
     * (port, profile directory, endpoint) are only known at run time and must replace them.
     *
     * WHY BROWSER.MANAGER_CONFIG IS APPLIED ONLY TO THE CONFIGURED OR DEFAULT LOCAL CLASS: when a caller forces a
     * different class (RunTest forces RemoteChromeManager via --chrome_endpoint while BROWSER.MANAGER is
     * local), the configured settings (executable, port bands) are not valid for it and would fail
     * with a UxonMapError although the operator did nothing wrong.
     *
     * @throws ConfigException if the selected class is not a concrete AbstractBrowserManager subclass
     */
    public static function create(
        WorkbenchInterface $workbench,
        ?UxonObject $runtimeOverrides = null,
        ?string $managerClass = null
    ): BrowserManagerInterface {
        $runtimeOverrides = $runtimeOverrides ?? new UxonObject();
        $appConfig = $workbench->getApp(self::APP_ALIAS)->getConfig();
        [$hasManagerOverride, $legacyKeys, $hasManagerConfigOverride] = self::readHigherScopeSettings($appConfig);
        $configuredClass = self::readConfiguredManagerClass($appConfig);
        $useLegacy = $legacyKeys !== [] && (! $hasManagerOverride || $configuredClass === null);
        $resolvedClass = self::resolveManagerClass(
            $managerClass,
            $useLegacy ? null : $configuredClass,
            $runtimeOverrides,
            $appConfig,
            $useLegacy ? $legacyKeys : []
        );

        if ($useLegacy) {
            $legacyConfig = self::readLegacyBaseConfig($appConfig, $resolvedClass, $legacyKeys);
            if (strcasecmp($resolvedClass, LocalChromeManager::class) === 0) {
                $localDefaults = $hasManagerConfigOverride
                    ? self::readShippedManagerConfig($workbench)
                    : self::readManagerConfig($appConfig);
                $baseConfig = $localDefaults->extend($legacyConfig);
            } else {
                $baseConfig = $legacyConfig;
            }
            self::warnAboutLegacyKeys($workbench, $legacyKeys);
        } elseif ($configuredClass !== null && strcasecmp($resolvedClass, $configuredClass) === 0) {
            $baseConfig = self::readManagerConfig($appConfig);
        } elseif ($configuredClass === null && strcasecmp($resolvedClass, LocalChromeManager::class) === 0) {
            $baseConfig = self::readManagerConfig($appConfig);
        } else {
            $baseConfig = new UxonObject();
        }

        $manager = new $resolvedClass($workbench);
        $manager->importUxonObject($baseConfig->extend($runtimeOverrides));
        $manager->validateConfig();
        return $manager;
    }

    /**
     * Tells which manager class create() would instantiate, without building or validating anything.
     *
     * WHY PUBLIC: RunTest must decide which CLI values belong to the manager BEFORE it can create it
     * (--base_url is a setting of an attached browser, but only the Mink base_url for a local one), and
     * building the manager first would fail validation or reject the value as an unknown setting. This
     * is a thin wrapper over the single private selection rule, so there is still only one place that
     * decides.
     *
     * @throws ConfigException if the selected class is not a concrete AbstractBrowserManager subclass
     */
    public static function resolveClass(
        WorkbenchInterface $workbench,
        ?UxonObject $runtimeOverrides = null,
        ?string $managerClass = null
    ): string {
        $appConfig = $workbench->getApp(self::APP_ALIAS)->getConfig();
        [$hasManagerOverride, $legacyKeys] = self::readHigherScopeSettings($appConfig);
        $configuredClass = self::readConfiguredManagerClass($appConfig);
        $useLegacy = $legacyKeys !== [] && (! $hasManagerOverride || $configuredClass === null);
        return self::resolveManagerClass(
            $managerClass,
            $useLegacy ? null : $configuredClass,
            $runtimeOverrides ?? new UxonObject(),
            $appConfig,
            $useLegacy ? $legacyKeys : []
        );
    }

    /**
     * Chooses the manager class - the single place that decides.
     *
     * WHY ONE METHOD: the choice used to be made by the DatabaseFormatter (an "endpoint" check) while
     * the factory defaulted separately, so two places had to agree.
     *
     * Order: an explicit class wins, then the effective configured manager unless higher-scope legacy
     * keys select the legacy path. Without a configured class, a non-empty runtime or legacy endpoint
     * selects RemoteChromeManager, otherwise LocalChromeManager.
     *
     * @throws ConfigException if the chosen class is not a concrete AbstractBrowserManager subclass
     */
    private static function resolveManagerClass(
        ?string $explicitClass,
        ?string $configuredClass,
        UxonObject $overrides,
        ConfigurationInterface $appConfig,
        array $legacyKeys
    ): string {
        if ($explicitClass !== null && $explicitClass !== '') {
            return self::assertManagerClass($explicitClass, 'the explicitly requested browser manager class');
        }
        if ($configuredClass !== null) {
            return self::assertManagerClass($configuredClass, self::CFG_MANAGER);
        }

        $endpoint = $overrides->getProperty('endpoint');
        if ((!is_string($endpoint) || $endpoint === '') && in_array(self::CFG_LEGACY_ENDPOINT, $legacyKeys, true)) {
            $endpoint = self::readStringOption($appConfig, self::CFG_LEGACY_ENDPOINT);
        }
        return is_string($endpoint) && $endpoint !== '' ? RemoteChromeManager::class : LocalChromeManager::class;
    }

    /**
     * Finds operator-provided selectors and legacy keys without mistaking packaged defaults for overrides.
     *
     * Core registers the user scope only if the app configuration is first loaded after workbench
     * startup. Its scope check throws when that scope was not registered, so only that expected
     * missing-scope exception is ignored; the installation scope must always be registered.
     *
     * @return array{0: bool, 1: string[], 2: bool} Selector, distinct legacy keys and config-object presence
     */
    private static function readHigherScopeSettings(ConfigurationInterface $appConfig): array
    {
        $hasManager = false;
        $hasManagerConfig = false;
        $legacyKeys = [];
        foreach ([AppInterface::CONFIG_SCOPE_INSTALLATION, AppInterface::CONFIG_SCOPE_USER] as $scope) {
            try {
                if ($appConfig->hasOption(self::CFG_MANAGER, $scope)) {
                    $hasManager = true;
                }
                if ($appConfig->hasOption(self::CFG_MANAGER_CONFIG, $scope)) {
                    $hasManagerConfig = true;
                }
                foreach (self::LEGACY_KEYS as $key) {
                    if ($appConfig->hasOption($key, $scope)) {
                        $legacyKeys[$key] = $key;
                    }
                }
            } catch (OutOfBoundsException $e) {
                if ($scope !== AppInterface::CONFIG_SCOPE_USER) {
                    throw $e;
                }
                // The app config was loaded before the user scope existed; no user file was loaded.
            }
        }
        return [$hasManager, array_values($legacyKeys), $hasManagerConfig];
    }

    /**
     * Rejects a class that cannot be configured and started as a browser manager.
     *
     * WHY ITS OWN CHECK: the class name comes from config text, and `new` on an arbitrary class would
     * either fail obscurely or instantiate something unrelated. The message names the source so the
     * operator knows which key to correct.
     *
     * @return string The class name without a leading backslash
     * @throws ConfigException
     */
    private static function assertManagerClass(string $class, string $source): string
    {
        $class = ltrim($class, '\\');
        if (!is_subclass_of($class, AbstractBrowserManager::class, true)
            || (new \ReflectionClass($class))->isAbstract()) {
            throw new ConfigException(
                'Invalid browser manager class in ' . $source . ': "' . $class . '" is not a concrete subclass of '
                . AbstractBrowserManager::class . '.'
            );
        }
        return $class;
    }

    /**
     * Reads BROWSER.MANAGER, treating a missing or empty value as "not configured".
     *
     * WHY EMPTY COUNTS AS UNSET: an installation can explicitly clear the selector to retain the
     * legacy selection rule during migration.
     *
     * @return string|null The configured class without a leading backslash, or NULL
     */
    private static function readConfiguredManagerClass(ConfigurationInterface $appConfig): ?string
    {
        $class = self::readStringOption($appConfig, self::CFG_MANAGER);
        return $class === '' ? null : ltrim($class, '\\');
    }

    /**
     * Reads BROWSER.MANAGER_CONFIG as the UXON the configured class is initialized with.
     */
    private static function readManagerConfig(ConfigurationInterface $appConfig): UxonObject
    {
        if (!$appConfig->hasOption(self::CFG_MANAGER_CONFIG)) {
            return new UxonObject();
        }
        $value = $appConfig->getOption(self::CFG_MANAGER_CONFIG);
        if ($value instanceof UxonObject) {
            return $value;
        }
        return new UxonObject(is_array($value) ? $value : []);
    }

    /**
     * Reloads packaged defaults when a higher-scope config object replaced them in the core merge.
     *
     * Core merges whole options rather than nested properties; using its loader preserves the same
     * UXON parsing as ordinary app configuration without importing an unselected manager's settings.
     */
    private static function readShippedManagerConfig(WorkbenchInterface $workbench): UxonObject
    {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'axenox.BDT.config.json';
        if (! is_file($path)) {
            throw new ConfigException('Shipped BDT configuration file not found: ' . $path);
        }
        $config = ConfigurationFactory::create($workbench);
        $config->loadConfigFile($path);
        return self::readManagerConfig($config);
    }

    /**
     * Reads a scalar option as a trimmed string, with '' for a missing or null option.
     */
    private static function readStringOption(ConfigurationInterface $appConfig, string $key): string
    {
        if (!$appConfig->hasOption($key)) {
            return '';
        }
        $value = $appConfig->getOption($key);
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Logs ONE deprecation warning per process, however many managers are created.
     *
     * WHY ONCE: a run creates coordinator managers and a Behat child per feature; repeating the same
     * warning for every one of them would bury it. It names the new keys so the log entry alone tells
     * an operator what to do.
     *
     * @param string[] $legacyKeys Legacy options present in the installation or user scopes
     */
    private static function warnAboutLegacyKeys(WorkbenchInterface $workbench, array $legacyKeys): void
    {
        if (self::$legacyWarningLogged) {
            return;
        }
        self::$legacyWarningLogged = true;
        $workbench->getLogger()->warning(
            'DEPRECATED: BDT browser settings are read from the legacy app-config keys ' . implode(', ', $legacyKeys)
            . '. Move them to ' . self::CFG_MANAGER . ' and ' . self::CFG_MANAGER_CONFIG
            . ' in axenox.BDT.config.json; the legacy keys will be removed in a future release.'
        );
    }

    /**
     * Initializes the process-wide manager once so formatter and test contexts share one lifecycle.
     */
    public static function initInstance(
        WorkbenchInterface $workbench,
        ?UxonObject $runtimeOverrides = null,
        ?string $managerClass = null
    ): BrowserManagerInterface {
        if (self::$instance === null) {
            self::$instance = self::create($workbench, $runtimeOverrides, $managerClass);
        }
        return self::$instance;
    }

    /**
     * Returns the initialized manager or fails loudly when formatter setup was skipped.
     */
    public static function getInstance(): BrowserManagerInterface
    {
        if (self::$instance === null) {
            throw new ConfigException('BrowserManagerFactory::initInstance() must be called before getInstance().');
        }
        return self::$instance;
    }

    /**
     * Reads the legacy app-config keys that apply to the selected class, for installations that have
     * not yet moved to BROWSER.MANAGER / BROWSER.MANAGER_CONFIG.
     *
     * WHY ONE METHOD FOR BOTH CLASSES: which higher-scope keys apply depends on the selected class.
     * Shipped legacy keys, if present in an older package, must not masquerade as operator overrides.
     *
     * @param string[] $legacyKeys Keys present in installation or user scope
     */
    private static function readLegacyBaseConfig(ConfigurationInterface $appConfig, string $managerClass, array $legacyKeys): UxonObject
    {
        $base = [];
        if (strcasecmp($managerClass, RemoteChromeManager::class) === 0) {
            $options = [
                'endpoint' => self::CFG_LEGACY_ENDPOINT,
                'base_url' => self::CFG_LEGACY_BASE_URL
            ];
        } elseif (strcasecmp($managerClass, LocalChromeManager::class) === 0) {
            $options = [
                'executable' => 'PARALLEL.CHROME_PATH',
                'port_band_interactive' => 'PARALLEL.PORT_BAND_INTERACTIVE',
                'port_band_scheduled' => 'PARALLEL.PORT_BAND_SCHEDULED'
            ];
        } else {
            $options = [];
        }
        foreach ($options as $property => $option) {
            if (! in_array($option, $legacyKeys, true)) {
                continue;
            }
            $value = self::readStringOption($appConfig, $option);
            if ($value !== '') {
                $base[$property] = $value;
            }
        }
        if (strcasecmp($managerClass, LocalChromeManager::class) === 0 && in_array('PARALLEL.CHROME_HEADLESS', $legacyKeys, true)) {
            $base['headless'] = (bool) $appConfig->getOption('PARALLEL.CHROME_HEADLESS');
        }
        return new UxonObject($base);
    }
}
