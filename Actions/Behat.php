<?php
namespace axenox\BDT\Actions;

use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\ServerSoftwareDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\Actions\ActionInputMissingError;
use exface\Core\Facades\ConsoleFacade\CommandRunner;
use exface\Core\Interfaces\Tasks\CliTaskInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Allows to manage and run Behat tests
 * 
 * Use from the command line:
 * 
 * - `vendor\bin\action axenox.BDT:Behat` - run all tests
 * - `vendor\bin\action axenox.BDT:Behat init` - prepare everything to run Behat tests on this installation
 * - `vendor\bin\action axenox.BDT:Behat addApp=my.APP` - include app `my.APP` as a test suite
 * - `vendor\bin\action axenox.BDT:Behat startBrowser=CHROME_DEBUG_API` - start a browser defined in BROWSERS section of axenox.BDT.config.json
 *        
 * @author Andrej Kabachnik
 *        
 */
class Behat extends AbstractActionDeferred implements iCanBeCalledFromCLI
{
    const CLI_ARG_OPERATION = 'operation';

    const OPERATION_INIT = 'init';

    const OPERATION_START_BROWSER = 'startBrowser';

    const CLI_OPT_BROWSER = 'browser';

    const CLI_OPT_ADD_APP = 'addApp';

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performImmediately()
     */
    protected function performImmediately(TaskInterface $task, DataTransactionInterface $transaction, ResultMessageStreamInterface $result): array
    {
        return [$task];
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performDeferred()
     */
    protected function performDeferred(CliTaskInterface $task = null): \Generator
    {
        $cmd = $task->getParameter(self::CLI_ARG_OPERATION);

        switch ($cmd) {
            case self::OPERATION_INIT:
                yield from $this->doInit();
                break;
            case self::OPERATION_START_BROWSER:
                if ($task->hasParameter(self::CLI_OPT_BROWSER)) {
                    $browser = $task->getParameter(self::CLI_OPT_BROWSER);
                } else {
                    throw new ActionInputMissingError($this, 'Missing required action parameter "--browser"!');
                }
                yield from $this->doStartBrowser($browser);
                break;
            default:

        }

        switch (true) {
            case $task->hasParameter(self::CLI_OPT_ADD_APP):
                yield from $this->doIncludeApp($task->getParameter(self::CLI_OPT_ADD_APP));
        }
    }

    /**
     * 
     * {@inheritdoc}
     * @see iCanBeCalledFromCLI::getCliArguments()
     */
    public function getCliArguments(): array
    {
        return [
            (new ServiceParameter($this))
                ->setName(self::CLI_ARG_OPERATION)
                ->setDescription('Command to be performed: init, test')
        ];
    }

    /**
     * 
     * {@inheritdoc}
     * @see iCanBeCalledFromCLI::getCliOptions()
     */
    public function getCliOptions(): array
    {
        return [
            (new ServiceParameter($this))
                ->setName(self::CLI_OPT_ADD_APP)
                ->setDescription('Include an app (alias) when running tests on the current installation'),
            (new ServiceParameter($this))
                ->setName(self::CLI_OPT_BROWSER)
                ->setDescription('Use this browser configuration from configuration file "axenox.Behat.config.json"')
        ];
    }

    /**
     * 
     * @return string
     */
    protected function getGlobalYmlPath(): string
    {
        $wbPath = $this->getWorkbench()->getInstallationPath();
        return $wbPath . DIRECTORY_SEPARATOR . 'behat.yml';
    }

    protected function doIncludeApp(string $alias): \Generator
    {
        $ymlPath = $this->getGlobalYmlPath();
        $loader = $this->getYmlReader($ymlPath);
        yield from $loader;
        $yml = $loader->getReturn();

        $app = $this->getWorkbench()->getApp($alias);
        $appDir = $app->getDirectoryAbsolutePath();
        $pathToBehat = $appDir . DIRECTORY_SEPARATOR . 'Tests' . DIRECTORY_SEPARATOR . 'Behat' . DIRECTORY_SEPARATOR;
        $pathToFeatures = $pathToBehat . 'Features';
        $pathToYml = $pathToBehat . 'Behat.yml';
        if (!file_exists($pathToYml)) {
            // Make sure Tests/Behat/Features exists in app
            Filemanager::pathConstruct($pathToFeatures);
            $appYml = [
                'default' => [
                    'suites' => [
                        $app->getAliasWithNamespace() => [
                            'paths' => ['%paths.base%/vendor/' . StringDataType::substringAfter(FilePathDataType::normalize($pathToFeatures), '/vendor/')],
                            'contexts' => ['axenox\BDT\Behat\Tests\Contexts\UI5Facade\UI5BrowserContext']
                        ]
                    ]
                ]
            ];
            file_put_contents($pathToYml, Yaml::dump($appYml, 10));
            yield 'Created app config "' . StringDataType::substringAfter(FilePathDataType::normalize($pathToYml), '/vendor/') . '"' . PHP_EOL;
        } else {
            yield 'Found existing Behat.yml in app' . PHP_EOL;
        }

        $imports = $yml['imports'];
        $pathToYmlRelative = 'vendor/' . StringDataType::substringAfter(FilePathDataType::normalize($pathToYml), '/vendor/');
        if (!array_search($pathToYmlRelative, $imports)) {
            $yml['imports'][] = $pathToYmlRelative;
            yield 'Added "' . $pathToYmlRelative . '" as import to global behat.yml' . PHP_EOL;
        } else {
            yield 'App already included.' . PHP_EOL;
        }

        $writer = $this->getYmlWriter($yml, $ymlPath);
        yield from $writer;
    }

    /**
     * Initialize the Behat testing environment
     * 
     * This method performs the following tasks:
     * 1. Sets up the global Behat YAML configuration
     * 2. Creates necessary directories for screenshots and test reports
     * 3. Generates unique filenames for test reports
     * 4. Configures HTML formatter settings
     * 5. Sets up web access configurations
     * 
     * @return \Generator Yields status messages during the initialization process
     */
    protected function doInit(): \Generator
    {
        // Get the path where the global Behat YAML configuration will be stored
        // This is in the root directory of the installation
        $ymlPath = $this->getGlobalYmlPath();

        // Load existing YAML configuration or create a new one from template
        // If file doesn't exist, it will be created from Template.yml
        $loader = $this->getYmlReader($ymlPath);
        yield from $loader;
        $yml = $loader->getReturn();
 
        // Generate a unique filename for the test report using current timestamp
        // Format: test_report_YYYY-MM-DD_HH-mm-ss
        $uniqueFileName = 'test_report_' . date('Y-m-d_H-i-s');

        // Initialize the formatters array in YAML if it doesn't exist
        // This is required for the HTML formatter configuration
        if (!isset($yml['default']['formatters'])) {
            $yml['default']['formatters'] = [];
        }

        // Set up BehatHTMLFormatter extension configuration
        // This extension is responsible for generating HTML test reports
        if (!isset($yml['default']['extensions']['emuse\\BehatHTMLFormatter\\BehatHTMLFormatterExtension'])) {
            $yml['default']['extensions']['emuse\\BehatHTMLFormatter\\BehatHTMLFormatterExtension'] = [];
        }

        // Configure the HTML formatter to use our unique filename
        $yml['default']['extensions']['emuse\\BehatHTMLFormatter\\BehatHTMLFormatterExtension']['file_name'] = $uniqueFileName;

        // Set up the main output directory for test reports
        // Path: installation_path/data/BDT/Reports
        $outputDir = $this->getWorkbench()->getInstallationPath() .
            DIRECTORY_SEPARATOR . 'data' .
            DIRECTORY_SEPARATOR . 'BDT' .
            DIRECTORY_SEPARATOR . 'Reports';

        // Create the output directory if it doesn't exist
        if (!file_exists($outputDir)) {
            // Create directory with full permissions (0777)
            mkdir($outputDir, 0777, true);
            yield 'Created HTML output directory at ' . $outputDir . PHP_EOL;

            // Create .htaccess file to enable web access to test results
            // This allows viewing the HTML reports through a web browser
            $htaccessPath = dirname($outputDir) . DIRECTORY_SEPARATOR . '.htaccess';
            if (!file_exists($htaccessPath)) {
                $htaccessContent = "Options +Indexes\nAllow from all";
                file_put_contents($htaccessPath, $htaccessContent);
                yield 'Created .htaccess for test results access' . PHP_EOL;
            }
        }

          // Create directory structure for storing test screenshots
        // Path: installation_path/data/BDT/Reports/screenshots
        $screenshotDir = $this->getWorkbench()->getInstallationPath() .
            DIRECTORY_SEPARATOR . 'data' .
            DIRECTORY_SEPARATOR . 'BDT' .
            DIRECTORY_SEPARATOR . 'Reports' .
            DIRECTORY_SEPARATOR . 'screenshots';

        // Create screenshots directory if it doesn't exist
        if (!file_exists($screenshotDir)) {
            mkdir($screenshotDir, 0777, true);
            yield 'Screenshot path created: ' . $screenshotDir . PHP_EOL;
        }

        // Save the updated YAML configuration
        // This also updates the base_url if it has changed
        $writer = $this->getYmlWriter($yml, $ymlPath);
        yield from $writer;

        // Return success message with the filename that will be used for the report
        yield 'Ready to test now! Test results will be saved as ' . $uniqueFileName . '.html' . PHP_EOL;
    }

    protected function doStartBrowser(string $configKey): \Generator
    {
        $cfg = $this->getWorkbench()->getApp('axenox.BDT')->getConfig()->getOption('BROWSERS.' . mb_strtoupper($configKey));
        if (ServerSoftwareDataType::isOsWindows()) {
            $cmd = $cfg->getProperty('START_ON_WINDOWS');
        } else {
            $cmd = $cfg->getProperty('START_ON_LINUX');
        }
        if ($cmd) {
            yield 'Starting browser via ' . $cmd . PHP_EOL;
            $cmd = StringDataType::replacePlaceholders($cmd, [
                '~workbench_path' => $this->getWorkbench()->getInstallationPath()
            ]);
            yield from CommandRunner::runCliCommand($cmd);
        } else {
            // TODO get the default browser session from BaseConfig.yml
            yield 'No start command find for browser "' . $configKey . '" in app config file . ';
        }
    }


    /**
     * 
     * @param string $ymlPath
     * @return \Generator
     */
    protected function getYmlReader(string $ymlPath): \Generator
    {

        if (file_exists($ymlPath)) {
            yield 'Found existing global behat.yml file' . PHP_EOL;
        } else {
            $tplPath = $this->getApp()->getDirectoryAbsolutePath() . DIRECTORY_SEPARATOR . 'Behat' . DIRECTORY_SEPARATOR . 'Template.yml';
            file_put_contents($ymlPath, file_get_contents($tplPath));
            yield 'Created new global behat.yml file in installation folder';
        }

        $yml = Yaml::parseFile($ymlPath);
        return $yml;
    }

    /**
     * 
     * @param array $yml
     * @param string $ymlPath
     * @return \Generator
     */
    protected function getYmlWriter(array $yml, string $ymlPath): \Generator
    {
        $currentUrl = $yml['default']['extensions']['Behat\MinkExtension']['base_url'];
        $wbUrl = $this->getWorkbench()->getUrl();
        if ($currentUrl !== $wbUrl) {
            yield 'Updating base_url to "' . $this->getWorkbench()->getUrl() . '"' . PHP_EOL;
            $yml['default']['extensions']['Behat\MinkExtension']['base_url'] = $wbUrl;
        }
        $str = Yaml::dump($yml, 10);
        file_put_contents($ymlPath, $str);
        return $yml;
    }
}