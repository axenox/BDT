<?php
namespace axenox\BDT\AI\Tools;

use axenox\BDT\Facades\MarkdownPrinters\BdtRunMarkdownPrinter;
use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiTaskHandlerInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Reads a BDT test run as Markdown and attaches its screenshots to the tool result.
 */
class GetBdtRunTool extends AbstractAiTool
{
    /**
     * Selects a run by UID or a scenario tag such as "@smoke".
     */
    public const ARG_RUN_SELECTOR = 'run_selector';

    /**
     * Renders the selected run and returns its screenshots as result attachments.
     *
     * @param AiTaskHandlerInterface $agent
     * @param TaskInterface $prompt
     * @param array<array-key,mixed> $arguments
     * @return AiToolResultInterface
     */
    public function invoke(AiTaskHandlerInterface $agent, TaskInterface $prompt, array $arguments) : AiToolResultInterface
    {
        $selector = trim((string) ($arguments[0] ?? ''));
        if ($selector === '') {
            throw new AiToolRuntimeError($this, $prompt, 'A BDT run UID or scenario tag is required.');
        }

        $printer = $this->createPrinter($selector);
        $markdown = $printer->getMarkdown();
        $files = $printer->getFiles();

        return new AiToolResultString(
            $this->getWorkbench(),
            $markdown,
            $this->getReturnDataType(),
            [],
            [],
            $files
        );
    }

    /**
     * Creates the BDT printer. Kept overridable so the tool behavior can be tested without stored runs.
     *
     * @param string $selector
     * @return BdtRunMarkdownPrinter
     */
    protected function createPrinter(string $selector) : BdtRunMarkdownPrinter
    {
        return new BdtRunMarkdownPrinter($this->getWorkbench(), $selector);
    }

    /**
     * {@inheritDoc}
     * @see AbstractAiTool::getArgumentsTemplates()
     */
    protected static function getArgumentsTemplates(WorkbenchInterface $workbench) : array
    {
        $self = new self($workbench);
        return [
            (new ServiceParameter($self))
                ->setName(self::ARG_RUN_SELECTOR)
                ->setDescription('BDT run UID or a single scenario tag such as "@smoke". For a tag, the newest matching run is selected.')
                ->setRequired(true)
        ];
    }

    /**
     * {@inheritDoc}
     * @see \axenox\GenAI\Interfaces\AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType() : DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }
}
