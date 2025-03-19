<?php
namespace axenox\BDT\Tests\Behat\Contexts\UI5Facade;

use axenox\BDT\Exceptions\FacadeBrowserException;

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
    public function addError(FacadeBrowserException $e): void
    {
        // Generate hash to prevent duplicate errors
        $hash = $this->generateErrorHash([
            'type' => $e::class,
            'message' => $e->getMessage(),
            'status' => $e->getCode()
        ]);

        // Add the error if it's not a duplicate or if at least 1 second has passed since the last error
        $currentTime = microtime(true);
        if (!isset($this->errors[$hash]) || ($currentTime - $this->lastErrorTime) > 1) {
            $this->errors[$hash] = $e;
            $this->lastErrorTime = $currentTime;
        }
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
    public function getFirstError(): ?FacadeBrowserException
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
}