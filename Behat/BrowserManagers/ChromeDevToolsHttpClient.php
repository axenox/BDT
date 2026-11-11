<?php
namespace axenox\BDT\Behat\BrowserManagers;

use axenox\BDT\Exceptions\ConfigException;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\Debug\LogBookInterface;
use GuzzleHttp\Client;

/**
 * Owns the bounded HTTP requests used to communicate with Chrome's DevTools API.
 */
class ChromeDevToolsHttpClient
{
    private string $apiBaseUrl;
    private LogBookInterface $logbook;

    /**
     * Binds one client to one browser endpoint so local and remote calls cannot cross targets.
     *
     * @param string           $apiBaseUrl Base URL of Chrome's HTTP debugging API
     * @param LogBookInterface $logbook    Destination for best-effort request diagnostics
     */
    public function __construct(string $apiBaseUrl, LogBookInterface $logbook)
    {
        $this->apiBaseUrl = $apiBaseUrl;
        $this->logbook = $logbook;
    }

    /**
     * Retains the canonical endpoint validation shared by configuration and UI callers.
     *
     * WHY STATIC: endpoint normalization is shared by the browser manager factory and interactive actions, so both call
     * sites must use the same validation rules rather than maintaining subtly different copies.
     */
    public static function normalizeEndpoint(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if (!filter_var($endpoint, FILTER_VALIDATE_URL)
            || !is_array($parts)
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new ConfigException('Chrome endpoint must be an HTTP(S) URL without credentials, query or fragment.');
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $defaultPort = $scheme === 'https' ? 443 : 80;
        return $scheme . '://' . strtolower($parts['host'])
            . ($port === $defaultPort ? '' : ':' . $port)
            . rtrim($parts['path'] ?? '', '/');
    }

    /**
     * Checks the browser-level endpoint because any CDP browser must advertise a WebSocket URL.
     */
    public function probe(float $timeoutSeconds): bool
    {
        $body = $this->runGuzzleApi($this->apiBaseUrl . '/json/version', $timeoutSeconds);
        return ($body['webSocketDebuggerUrl'] ?? '') !== '';
    }

    /**
     * Retrieves the version object used to verify a CDP endpoint before attaching.
     *
     * WHY REQUIRED JSON IS A CALLER CHOICE: attachment must expose transport failures, while health
     * probes treat an unavailable response as a simple negative result.
     */
    public function getVersion(float $timeoutSeconds, bool $requireJson): array
    {
        return $this->runGuzzleApi($this->apiBaseUrl . '/json/version', $timeoutSeconds, $requireJson);
    }

    /**
     * Retrieves the current tab snapshot while preserving best-effort or required-JSON semantics.
     *
     * WHY BOTH MODES: diagnostics can retry an unavailable endpoint, but remote attachment must not
     * mistake a failed snapshot for an empty browser and later close pages it does not own.
     */
    public function getTabList(bool $requireJson = false): array
    {
        return $this->runGuzzleApi($this->apiBaseUrl . '/json/list', null, $requireJson);
    }

    /**
     * Closes a tab through Chrome's plain-text cleanup endpoint.
     *
     * WHY BEST-EFFORT: tab cleanup is not a precondition for recovery, and the endpoint does not
     * return JSON that could provide a meaningful success result.
     */
    public function closeTab(string $targetId): void
    {
        $this->runGuzzleApi($this->apiBaseUrl . '/json/close/' . rawurlencode($targetId));
    }

    /**
     * Sends a GET request to a Chrome DevTools Protocol HTTP endpoint and returns the decoded JSON body.
     *
     * A new Guzzle client is created per call because Chrome's CDP endpoints are only queried
     * occasionally (startup polling, health probes, tab diagnostics) and do not warrant a persistent
     * HTTP client. Best-effort probes log failures and return an empty array so startup polling can
     * retry. Required JSON queries throw instead of treating a failed tab snapshot as an empty browser,
     * which could make cleanup close pre-existing pages.
     *
     * Chrome rejects DNS names in the HTTP Host header. Match DMore's existing IPv4 resolution while
     * keeping the request URL hostname for DNS and TLS. Using the resolved IP instead of localhost
     * also keeps advertised WebSocket URLs reachable from the remote PHP runner.
     *
     * WHY EXPLICIT TIMEOUTS: Guzzle's default request timeout is UNLIMITED. A Chrome that binds the
     * port but never answers - exactly the wedged state we are trying to detect - would make this call
     * block forever, and the caller's own timeout loop would never get to re-evaluate its wall-clock
     * condition. The whole "give up after N seconds" contract of every caller depends on the two
     * timeouts below being set here.
     *
     * WHY THE CEILING IS A PARAMETER: isAlive() deliberately probes with two different ceilings - a
     * short one on the hot path and a longer one when confirming a negative before a browser is killed.
     * Passing the ceiling in keeps both of them on this single, correctly-bounded HTTP path instead of
     * forcing a second client to exist for the slower probe.
     *
     * @param string     $url            Full URL of the CDP endpoint
     * @param float|null $timeoutSeconds Wall-clock ceiling; defaults to the health-probe ceiling
     * @param bool       $requireJson    Fail attachment if the HTTP or JSON response is invalid
     * @return array Decoded JSON response body, or an empty array on a best-effort probe failure
     */
    private function runGuzzleApi(string $url, ?float $timeoutSeconds = null, bool $requireJson = false): array
    {
        $timeout = $timeoutSeconds ?? 2.0;
        try {
            $client   = new Client([
                // connect_timeout bounds a closed port, timeout bounds a Chrome that accepts the
                // connection but never writes a response. Both are needed - either alone still
                // leaves a path for the call to hang indefinitely.
                'connect_timeout' => $timeout,
                'timeout'         => $timeout
            ]);
            $parts = parse_url($url);
            $host = $parts['host'];
            $resolvedHost = $host === 'localhost' ? $host : gethostbyname($host);
            $options = [];

            // add resolved host header if necessary
            if ($resolvedHost !== $host) {
                $options['headers']['Host'] = $resolvedHost
                    . (isset($parts['port']) ? ':' . $parts['port'] : '');
            }
            $response = $client->request('GET', $url, $options);

            if ($response->getStatusCode() === 200) {
                $body = json_decode($response->getBody()->__toString(), true);
                if (is_array($body)) {
                    return $body;
                }
                if ($requireJson) {
                    throw new RuntimeException('Chrome returned an invalid JSON response at ' . $url);
                }
                return [];
            }

            $this->logbook->addLine("runGuzzleApi({$url}): unexpected HTTP status " . $response->getStatusCode());
        } catch (\Throwable $e) {
            // Guzzle throws ConnectException while Chrome is still starting up;
            // log the details so we can distinguish a real failure from normal startup delay
            $this->logbook->addLine("**ERROR** runGuzzleApi({$url}): " . get_class($e) . ' — ' . $e->getMessage());
            if ($requireJson) {
                throw new RuntimeException('Could not query remote Chrome at ' . $url . ': ' . $e->getMessage(), null, $e);
            }
        }

        if ($requireJson) {
            throw new RuntimeException('Could not query remote Chrome at ' . $url);
        }
        return [];
    }
}
