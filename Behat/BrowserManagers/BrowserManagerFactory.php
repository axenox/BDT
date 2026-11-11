<?php
namespace axenox\BDT\Behat\BrowserManagers;

use axenox\BDT\Exceptions\ConfigException;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Creates, validates and holds the process-wide browser manager.
 *
 * WHY IT EXISTS: formatter, browser context and run actions must share ONE manager (one browser
 * lifecycle) without knowing its concrete class, and a manager must never be handed out unvalidated.
 * The factory is the only place that turns configuration into a manager.
 *
 * LIFECYCLE: the DatabaseFormatter constructor calls initInstance() once per Behat process; everything
 * else retrieves the manager with getInstance(). RunTest calls create() for a short-lived remote
 * manager that is not the shared instance. The manager's start() and stop() are NOT called here.
 */
class BrowserManagerFactory
{
    private static ?BrowserManagerInterface $instance = null;

    /**
     * Builds a validated manager from legacy app configuration plus runtime overrides.
     *
     * WHY OVERRIDES WIN: the app config supplies installation-wide defaults, while the per-run values
     * (port, profile directory, endpoint) are only known at run time and must replace them.
     */
    public static function create(
        WorkbenchInterface $workbench,
        ?UxonObject $runtimeOverrides = null,
        ?string $managerClass = null
    ): BrowserManagerInterface {
        $managerClass = self::resolveManagerClass($managerClass, $runtimeOverrides ?? new UxonObject());
        if (!is_a($managerClass, BrowserManagerInterface::class, true)) {
            throw new ConfigException('Browser manager class must implement ' . BrowserManagerInterface::class . '.');
        }

        $baseConfig = is_a($managerClass, LocalChromeManager::class, true)
            ? self::getLocalBaseConfig($workbench)
            : [];
        $config = new UxonObject($baseConfig);
        if ($runtimeOverrides !== null) {
            $config = $config->extend($runtimeOverrides);
        }

        $manager = new $managerClass($workbench);
        $manager->importUxonObject($config);
        $manager->validateConfig();
        return $manager;
    }

    /**
     * Chooses the manager class - the single legacy selection point.
     *
     * WHY ONE METHOD: the choice used to be made by the DatabaseFormatter (an "endpoint" check) while
     * the factory defaulted separately, so two places had to agree. This method is the only place that
     * decides, and it is the code the BROWSER.MANAGER app-config key will replace: once that key
     * exists, the class is read from it and the endpoint heuristic disappears.
     *
     * Rules: an explicit class always wins; otherwise a non-empty "endpoint" in the overrides selects
     * RemoteChromeManager; otherwise LocalChromeManager.
     */
    private static function resolveManagerClass(?string $managerClass, UxonObject $overrides): string
    {
        if ($managerClass !== null && $managerClass !== '') {
            return $managerClass;
        }
        $endpoint = $overrides->getProperty('endpoint');
        if (is_string($endpoint) && $endpoint !== '') {
            return RemoteChromeManager::class;
        }
        return LocalChromeManager::class;
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
     * Reads only the legacy local-browser options that remain supported by this factory.
     */
    private static function getLocalBaseConfig(WorkbenchInterface $workbench): array
    {
        $config = $workbench->getApp('axenox.BDT')->getConfig();
        $base = [];
        $options = [
            'executable' => 'PARALLEL.CHROME_PATH',
            'port_band_interactive' => 'PARALLEL.PORT_BAND_INTERACTIVE',
            'port_band_scheduled' => 'PARALLEL.PORT_BAND_SCHEDULED'
        ];
        foreach ($options as $property => $option) {
            if ($config->hasOption($option)) {
                $value = $config->getOption($option);
                if ($value !== null && $value !== '') {
                    $base[$property] = $value;
                }
            }
        }
        if ($config->hasOption('PARALLEL.CHROME_HEADLESS')) {
            $base['headless'] = (bool) $config->getOption('PARALLEL.CHROME_HEADLESS');
        }
        return $base;
    }
}
