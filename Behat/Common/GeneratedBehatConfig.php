<?php
namespace axenox\BDT\Behat\Common;

use axenox\BDT\Behat\BrowserManagers\BrowserManagerInterface;
use axenox\BDT\Behat\BrowserManagers\ChromeDevToolsHttpClient;
use axenox\BDT\Behat\BrowserManagers\LocalChromeManager;
use axenox\BDT\Behat\BrowserManagers\RemoteChromeManager;
use axenox\BDT\Behat\BrowserManagers\SupportsParallelLanesInterface;
use axenox\BDT\Behat\DatabaseFormatter\DatabaseFormatterExtension;
use exface\Core\Exceptions\RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps interactive and lane configs on one serialization path so browser settings cannot drift.
 */
final class GeneratedBehatConfig
{
    /**
     * Keeps the coordinator and Behat child on the same browser settings without file or process access.
     *
     * Local browsers receive port/profile overrides and an executable only from the CLI. Their Mink
     * base_url stays inherited unless overridden. Attached browsers receive CLI endpoint/base_url
     * overrides only, while Mink always uses the manager's application URL.
     *
     * @return array<string,mixed>
     */
    public static function buildExtensions(
        BrowserManagerInterface $coordinator,
        int $port,
        string $userDataDirRelative,
        string $chromePath = '',
        string $chromeEndpoint = '',
        string $baseUrl = '',
        ?string $runUid = null,
        ?string $laneId = null
    ): array {
        $minkExtension = 'Behat\\MinkExtension';
        // Preserve the existing parsed keys: lane configs used no leading backslash.
        $formatterExtension = ($runUid !== null || $laneId !== null ? '' : '\\') . DatabaseFormatterExtension::class;

        if (! $coordinator instanceof SupportsParallelLanesInterface) {
            $browserConfig = [];
            if ($chromeEndpoint !== '') {
                $browserConfig['endpoint'] = $chromeEndpoint;
            }
            if ($baseUrl !== '') {
                $browserConfig['base_url'] = $baseUrl;
            }
            $extensions = [
                $minkExtension => [
                    'sessions' => ['CHROME_DEBUG_API' => ['chrome' => [
                        'api_url' => ChromeDevToolsHttpClient::normalizeEndpoint($coordinator->getApiUrl())
                    ]]]
                ],
                $formatterExtension => [
                    'browser' => [
                        'manager' => $chromeEndpoint !== '' ? RemoteChromeManager::class : null,
                        'config' => $browserConfig
                    ]
                ]
            ];
            if ($coordinator->getBaseUrl() !== null) {
                $extensions[$minkExtension]['base_url'] = $coordinator->getBaseUrl();
            }
        } else {
            $browserConfig = ['port' => $port, 'user_data_dir' => $userDataDirRelative];
            if ($chromePath !== '') {
                $browserConfig['executable'] = $chromePath;
            }
            $extensions = [
                $minkExtension => [
                    'sessions' => ['CHROME_DEBUG_API' => ['chrome' => ['api_url' => LocalChromeManager::buildApiUrl($port)]]]
                ],
                $formatterExtension => [
                    'browser' => ['manager' => null, 'config' => $browserConfig]
                ]
            ];
            if ($baseUrl !== '') {
                $extensions[$minkExtension]['base_url'] = $baseUrl;
            }
        }
        if ($runUid !== null) {
            $extensions[$formatterExtension]['run_uid'] = $runUid;
        }
        if ($laneId !== null) {
            $extensions[$formatterExtension]['lane_id'] = $laneId;
        }
        return $extensions;
    }

    /**
     * Imports the real base filename and uses YAML serialization so Windows paths retain their values.
     *
     * Write failures must stop the caller rather than let Behat consume a stale config from another run.
     *
     * @param array<string,mixed> $extensions
     */
    public static function write(string $configPath, string $behatConfig, string $header, array $extensions): string
    {
        $yaml = '# ' . $header . "\n"
            . Yaml::dump([
                'imports' => [basename($behatConfig)],
                'default' => ['extensions' => $extensions]
            ], 10, 2);
        if (file_put_contents($configPath, $yaml) === false) {
            throw new RuntimeException('Failed to write generated Behat config: ' . $configPath);
        }
        return $configPath;
    }
}
