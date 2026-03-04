<?php
namespace axenox\BDT\Tests\Behat\Contexts\UI5Facade;

use exface\Core\Exceptions\RuntimeException;

/**
 * ErrorManager class for managing and tracking errors in UI5 tests
 * Implement to ensure a single error manager instance
 */
class ErrorManager
{
    private static ?ErrorManager $instance = null;
    private array $errors = [];
    private array $processedErrors = [];
    private float $lastErrorTime = 0;

    private ?string $lastLogId = null;
    /**
     * Set the last log ID for error tracking
     */
    public function setLastLogId(?string $logId): void
    {
        $this->lastLogId = $logId;
    }
    
    public function getLastLogId(): ?string
    {
        return $this->lastLogId;
    }
    
    private function __construct()
    {
    }

    /**
     * Returns the instance of ErrorManager
     * Creates a new instance if none exists yet
     */
    public static function getInstance(): ErrorManager
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Adds a new error to the error collection
     * Prevents duplicate errors within a 1-second timeframe
     */
    public function addError(array $error, string $source): void
    {
        // Standardize the error object
        $standardError = $this->standardizeError($error, $source);

        // Generate hash to prevent duplicate errors
        $hash = $this->generateErrorHash($standardError);

        // Add the error if it's not a duplicate or if at least 1 second has passed since the last error
        $currentTime = microtime(true);
        if (!isset($this->errors[$hash]) || ($currentTime - $this->lastErrorTime) > 1) {
            $this->errors[$hash] = $standardError;
            $this->lastErrorTime = $currentTime;
        }
    }

    /**
     * Standardizes error format by adding missing fields and metadata
     */
    private function standardizeError(array $error, string $source): array
    {
        return [
            'type' => $error['type'] ?? 'Unknown',
            'message' => $error['message'] ?? '',
            'status' => $error['status'] ?? null,
            'url' => $error['url'] ?? '',
            'response' => $error['response'] ?? '',
            'source' => $source,
            'timestamp' => microtime(true),
            'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)
        ];
    }

    /**
     * Generates a unique hash for an error to identify duplicates
     * Removes dynamic URL parameters to improve matching
     */
    private function generateErrorHash(array $error): string
    {
        // Clean dynamic parameters from URL
        $url = preg_replace('/[?&][^=]*=[^&]*/', '', $error['url'] ?? '');

        // Combine basic information for hash generation
        $hashContent = $error['type'] . '|' .
            $error['message'] . '|' .
            $error['status'] . '|' .
            $url;

        return md5($hashContent);
    }
    /**
     * Returns all collected errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }  /**
       * Checks if any errors have been collected
       */

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
    /**
     * Returns the first error in the collection or null if empty
     */
    public function getFirstError(): ?array
    {
        return reset($this->errors) ?: null;
    }
    /**
     * Clears all collected errors and resets the error tracking state
     */
    public function clearErrors(): void
    {
        $this->errors = [];
        $this->processedErrors = [];
        $this->lastErrorTime = 0;
    }


    /**
     * Formats an error into a readable string representation
     */
    public function formatErrorMessage(array $error): string
    {
        // If this is a Behat exception, use the specialized formatter
        if (isset($error['type']) && $error['type'] === 'BehatException') {
            return $this->formatBehatExceptionMessage($error);
        }
    
        // Original formatting for other error types
        $message = "ERROR DETAILS 1|\n";
        $message .= "===============\n"; 

        if ($this->lastLogId) {
            $message .= "LogID: " . $this->lastLogId . "\n";
            $message .= "===================\n";
        }
        $message .= "Type: " . ($error['type'] ?? 'N/A') . "\n";
        $message .= "Status: " . ($error['status'] ?? 'N/A') . "\n";
        $message .= "Message: " . ($error['message'] ?? 'No message available') . "\n";
        $message .= "URL: " . ($error['url'] ?? 'N/A') . "\n";
        $message .= "Source: " . ($error['source'] ?? 'Unknown') . "\n";

        if (!empty($error['response'])) {
            $message .= "Response: " . $error['response'] . "\n";
        }

        return $message;
    }

    /**
     * Formats a Behat exception into a readable string representation
     */
    public function formatBehatExceptionMessage(array $error): string
    {
        $message = "BEHAT ERROR DETAILS 2|\n";
        $message .= "===================\n";

        if ($this->lastLogId) {
            $message .= "LogID: " . $this->lastLogId . "\n";
            $message .= "===================\n";
        }

        $message .= "Type: " . ($error['type'] ?? 'N/A') . "\n";
        $message .= "Status: " . ($error['status'] ?? 'N/A') . "\n";
        $message .= "Message: " . ($error['message'] ?? 'No message available') . "\n";
        $message .= "Source: " . ($error['source'] ?? 'Unknown') . "\n";

        if (!empty($error['stack'])) {
            $message .= "\nStack Trace:\n" . $error['stack'] . "\n";
        }

        return $message;
    }

    /**
     *  Logs an exception with additional context and outputs the associated LogID.
     *
     *  This function is intended to be used in any part of the application where exceptions need to be tracked
     *  and correlated across different systems or log files. It wraps the given exception in a RuntimeException
     *  (to ensure consistent handling and LogID generation), stores it in the ErrorManager's error list,
     *  and optionally logs it to an external logger (e.g. the workbench logger).
     *
     *  Usage:
     *    try {
     *        // risky operation
     *    } catch (\Exception $e) {
     *        ErrorManager::getInstance()->logExceptionWithId($e, 'SomeSource', $this->workbench);
     *    }
     * 
     * @param \Exception $e
     * @param string $source
     * @param $workbench
     * @throws \Exception
     */
    public function logExceptionWithId(\Exception $e, string $source = 'Unknown', $workbench = null)
    {
        $this->logException($e, $source, $workbench);
        throw $e;
    }

    /**
     *  Logs an exception with additional context and outputs the associated LogID.
     *
     *  Usage:
     *    try {
     *        // risky operation
     *    } catch (\Exception $e) {
     *        ErrorManager::getInstance()->logExceptionWithId($e, 'SomeSource', $this->workbench);
     *    }
     *
     * @param \Exception $e
     * @param string $source
     * @param $workbench
     */
    public function logException(\Exception $e, string $source = 'Unknown', $workbench = null)
    {
        $wrappedException = new RuntimeException(
            $e->getMessage(),
            null,
            $e
        );

        $logId = method_exists($wrappedException, 'getId') ? $wrappedException->getId() : null;

        $this->addError([
            'type'    => 'Exception',
            'message' => $e->getMessage(),
            'status'  => $e->getCode(),
            'stack'   => $e->getTraceAsString(),
            'logId'   => $logId,
        ], $source);

        if ($workbench && method_exists($workbench, 'getLogger')) {
            $workbench->getLogger()->logException($wrappedException);
        }

        if ($logId) {
            $this->setLastLogId($logId);
        }
    }
}