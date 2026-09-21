<?php
namespace axenox\BDT\AI\Tools;

use axenox\BDT\DataTypes\GherkinDataType;
use axenox\BDT\Tests\Behat\Contexts\UI5Facade\UI5BrowserContext;
use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Validates Gherkin feature text and returns diagnostics that an AI agent can correct.
 *
 * This tool exposes the same structural checks as GherkinDataType::parse(), but returns all
 * findings as machine-readable JSON instead of throwing a validation exception on the first
 * invalid value. Agents can use it before saving generated `.feature` content.
 *
 * ## Example
 *
 * ```json
 * {
 *   "alias": "axenox.BDT.GherkinValidateTool",
 *   "description": "Validate generated Gherkin feature files before returning them."
 * }
 * ```
 */
class GherkinValidateTool extends AbstractAiTool
{
    public const ARG_GHERKIN = 'gherkin';
    public const ARG_STRICT = 'strict';

    /**
     * Returns advisory diagnostics instead of throwing so agents can repair generated Gherkin.
     *
     * @see \axenox\GenAI\Interfaces\AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        [$input, $strictInput] = array_pad($arguments, 2, null);

        try {
            $gherkin = StringDataType::cast($input);
            $strict = $this->parseStrictArgument($strictInput);
            $errors = GherkinDataType::findErrors($gherkin, $strict);
            
            // TODO the facade should be defined because the steps depends on the facade,
            // we now use just UI5 but in the future this can be used as a parameter
            $undefinedSteps = GherkinDataType::findUndefinedSteps($gherkin, [UI5BrowserContext::class]);

            return new AiToolResultString(
                $this,
                $arguments,
                json_encode([
                    'valid' => $errors === [],
                    'strict' => $strict,
                    'errors' => array_map(
                        static fn(string $error): array => [
                            'line' => self::extractLineNo($error),
                            'message' => $error,
                        ],
                        $errors
                    ),
                    'undefinedSteps' => array_map(
                        static fn(string $step): array => [
                            'line' => self::extractLineNo($step),
                            'message' => $step,
                        ],
                        $undefinedSteps
                    ),
                    'message' => $errors === [] ? 'Gherkin is valid.' : GherkinDataType::formatErrors($errors),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                $this->getReturnDataType()
            );
        } catch (\Throwable $e) {
            $exception = new AiToolRuntimeError(
                $this,
                $prompt,
                'Gherkin validation failed: ' . $e->getMessage(),
                null,
                $e
            );
            $agent->getWorkbench()->getLogger()->logException($exception);

            return new AiToolResultString(
                $this,
                $arguments,
                json_encode(['error' => $exception->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                $this->getReturnDataType(),
                [],
                [$exception]
            );
        }
    }

    /**
     * Keeps the tool tolerant of LLM argument serialization while defaulting like GherkinDataType.
     *
     * @param mixed $strictInput
     * @return bool
     */
    private function parseStrictArgument(mixed $strictInput): bool
    {
        if ($strictInput === null || $strictInput === '') {
            return true;
        }

        return BooleanDataType::cast($strictInput);
    }

    /**
     * Extracts line numbers from the shared GherkinDataType error format for structured output.
     *
     * @param string $message
     * @return int|null
     */
    private static function extractLineNo(string $message): ?int
    {
        $matches = [];
        if (preg_match('/^Line (\d+):/', $message, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Defines the minimal prompt-facing contract needed to validate raw feature text.
     *
     * @see \axenox\GenAI\Common\AbstractAiTool::getArgumentsTemplates()
     */
    protected static function getArgumentsTemplates(WorkbenchInterface $workbench): array
    {
        $self = new self($workbench);

        return [
            (new ServiceParameter($self))
                ->setDataType(new UxonObject(['alias' => 'exface.Core.String']))
                ->setName(self::ARG_GHERKIN)
                ->setDescription('Raw Gherkin feature file content to validate.')
                ->setRequired(true),
            (new ServiceParameter($self))
                ->setDataType(new UxonObject(['alias' => 'exface.Core.Boolean']))
                ->setName(self::ARG_STRICT)
                ->setDescription('Optional. Set to false to skip convention checks that the parser tolerates. Defaults to true.')
                ->setRequired(false),
        ];
    }

    /**
     * Returns JSON so agents can consume validity, strict mode and individual diagnostics directly.
     *
     * @see \axenox\GenAI\Interfaces\AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), JsonDataType::class);
    }
}