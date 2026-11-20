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
     * Token that precedes the slot number in the lane_id of a RunTest run ("<run_uid>_interactive<slot>").
     *
     * WHY A SHARED CONSTANT: RunTest writes it into the generated config and UI5Browser must recognise
     * it when deriving the test username and when the data cleanup decides whether a username is a test
     * user. The one lane_id format that legitimately has no "_laneN" token has to be named in exactly
     * one place.
     */
    public const LANE_ID_TOKEN_INTERACTIVE = '_interactive';

    /**
     * Builds the lane_id of the single Behat process of a RunTest run: "<run_uid>_interactive<slot>".
     *
     * WHY A LANE_ID AT ALL: a Behat child that is given a run_uid runs in attach-mode, and
     * DatabaseFormatter refuses a run_uid without a lane_id on purpose (a lost lane_id would silently
     * put concurrent runs on one shared test-user row).
     *
     * WHY A RESERVED SLOT: the lane_id also names the test user. Concurrent RunTests (agents and testers)
     * that resolve to the same role would otherwise share one USER_AUTHENTICATOR row and collide on
     * optimistic locking. The slot is a number 1..N reserved across processes for the whole run
     * (TEST_USER.INTERACTIVE_SLOTS sets N), so it is unique among concurrent interactive runs and N
     * caps the number of distinct interactive test users - the same bound laneN gives the fleet.
     *
     * WHY NOT THE BROWSER PORT OR THE ENDPOINT URL: a slot must not depend on how the browser is
     * attached. A local port range is wide (it would allow hundreds of users), an attached browser has no
     * port of its own, and endpoint URLs mostly share one (443). A slot also cannot overlap between the
     * two cases, because both draw from the same pool.
     *
     * @param string $runUid UID of the run
     * @param int    $slot   Test-user slot reserved for this run, 1..N
     */
    public static function interactiveLaneId(string $runUid, int $slot): string
    {
        if ($slot < 1) {
            throw new RuntimeException('Cannot build an interactive lane_id without a reserved slot, got ' . $slot . '.');
        }
        return $runUid . self::LANE_ID_TOKEN_INTERACTIVE . $slot;
    }

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
        // One spelling for every generated config, WITHOUT a leading backslash: that is how the base
        // behat.yml names the extension, so the generated key is textually the same one it overrides.
        // Behat itself would resolve both spellings to the same extension (it instantiates the locator
        // with class_exists() and re-keys the config by the extension's own config key), but a
        // textually different key is still instantiated a second time and makes a failed merge look
        // like Behat's fault instead of ours - see the lane_id check in DatabaseFormatter.
        $formatterExtension = DatabaseFormatterExtension::class;

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
