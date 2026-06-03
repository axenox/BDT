<?php

namespace axenox\BDT\Behat\Common;

/**
 * Holds the result of a feature file validation run.
 *
 * Returned by FeatureFileValidator::validate().  The caller checks isValid()
 * and, if false, reads getErrors() to display all problems to the user before
 * blocking the save operation.
 */
class FeatureValidationResult
{
    /** @var string[] */
    private array $errors;

    /**
     * @param string[] $errors List of human-readable error messages, one per problem found.
     */
    public function __construct(array $errors)
    {
        $this->errors = $errors;
    }

    /**
     * Returns true only when no fatal errors were detected.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return empty($this->errors);
    }

    /**
     * Returns all error messages collected during validation.
     *
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Formats all errors as a single human-readable string, one error per line.
     *
     * Useful for logging or displaying in a plain-text context.
     *
     * @return string
     */
    public function toText(): string
    {
        return implode("\n", $this->errors);
    }
}