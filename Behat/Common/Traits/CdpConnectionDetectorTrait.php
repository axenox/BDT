<?php
namespace axenox\BDT\Behat\Common\Traits;

/**
 * Detects whether a throwable originates from a broken CDP/WebSocket connection.
 *
 * Shared by UI5WaitManager (session-level wait calls) and UI5AbstractNode
 * (direct DOM calls such as getAttribute, find, evaluateScript) so that the
 * detection logic lives in exactly one place.
 */
trait CdpConnectionDetectorTrait
{
    /**
     * Returns true if the throwable (or any cause in its chain) indicates that the
     * Chrome DevTools Protocol connection was lost or never established.
     *
     * The full cause chain is walked because the CDP keyword is typically buried
     * inside wrapper exceptions:
     *   ErrorException("stream_socket_client()…")
     *   → StreamException("Client could not connect…")
     *     → ConnectionException("Could not open socket…")
     *       → RuntimeException (top-level catch)
     *
     * Known patterns and their sources:
     *  - "WebSocket"               → dmore/chrome-mink-driver handshake failure
     *  - "Connection refused"      → POSIX: port closed
     *  - "actively refused it"     → Windows Winsock RST (same root cause)
     *  - "No connection could be"  → Windows Winsock generic connect failure
     *  - "Unable to connect to tcp"→ phrity/net-stream SocketClient
     *  - "Could not connect"       → older dmore driver phrasing
     *  - "Server is closed"        → dmore driver: WebSocket server unreachable
     *  - "curl error"              → Guzzle curl transport failure
     *  - "Server is closed"        → dmore driver: WebSocket server unreachable
     *  - "curl error"              → Guzzle curl transport failure
     *  - "version information"     → dmore driver: Chrome not yet answering /json/version at
     *                                 connect time (transient at session start / after restart)
     *
     * @param \Throwable $e The throwable to inspect.
     * @return bool True if the throwable indicates a lost or unavailable CDP connection.
     */
    private function isCdpConnectionError(\Throwable $e): bool
    {
        $current = $e;
        while ($current !== null) {
            $msg = $current->getMessage();
            if (str_contains($msg, 'WebSocket')
                || str_contains($msg, 'Connection refused')
                || str_contains($msg, 'actively refused it')
                || str_contains($msg, 'No connection could be')
                || str_contains($msg, 'Unable to connect to tcp')
                || str_contains($msg, 'Could not connect')
                || str_contains($msg, 'Server is closed')
                || str_contains($msg, 'curl error')
                || str_contains($msg, 'Empty read')
                || str_contains($msg, 'connection dead')
                // WHY: dmore/chrome-mink-driver throws "Could not fetch version information from
                // .../json/version ... Json Error: Syntax error" when it connects (lazily, on the
                // first visit) to a Chrome that is up but has not finished answering /json/version
                // yet - the classic first-step-of-scenario race. Without this pattern visitPath()
                // treats it as a hard error and re-throws on attempt 1, failing a scenario that a
                // single retry would have passed. Matching the stable "version information" wording
                // routes it into the existing CDP retry/backoff instead.
                || str_contains($msg, 'version information')
            ) {
                return true;
            }
            $current = $current->getPrevious();
        }
        return false;
    }
}