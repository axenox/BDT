<?php
namespace axenox\BDT\AI\Concepts;

use axenox\GenAI\Common\AbstractConcept;
use axenox\GenAI\Exceptions\AiConceptConfigurationError;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\SortingDirectionsDataType;
use exface\Core\Factories\ConditionGroupFactory;
use exface\Core\Factories\DataSheetFactory;

/**
 * Loads the available BDT Gherkin automation step catalog into AI agent instructions.
 *
 * Use this concept for Gherkin-generating agents that must know what BDT can automate
 * and which exact step formulations are allowed. It reads the same `GHERKIN_CONTEXT`
 * and `GHERKIN_ANNOTATION` model objects that `InputGherkin` uses for editor
 * completions, so the generated instructions stay aligned with implemented Behat
 * context annotations.
 *
 * ## Example usage
 *
 * ```json
 * {
 *  "instructions": "You create valid BDT Gherkin scenarios.\n\n[#gherkin_step_catalog#]",
 *  "concepts": {
 *      "gherkin_step_catalog": {
 *          "class": "\\axenox\\BDT\\AI\\Concepts\\GherkinStepCatalogConcept",
 *          "include_contexts": true,
 *          "include_step_descriptions": true,
 *          "max_steps": 200
 *      }
 *  }
 * }
 * ```
 *
 * ## Example output
 *
 * ````markdown
 * ## Existing Automation Step Catalog
 *
 * Use these existing BDT automation steps whenever possible. Prefer exact matches
 * before creating new formulations.
 *
 * ### axenox/bdt/Tests/Behat/Contexts/UI5Facade/UI5BrowserContext.php
 *
 * ```gherkin
 * Given I log in to the page :url
 * Given I visit page :url
 * When I click button :caption
 * Then I should see message :message
 * ```
 * ````
 */
class GherkinStepCatalogConcept extends AbstractConcept
{
    private ?UxonObject $contextFilters = null;

    private bool $includeContexts = false;

    private bool $includeHeading = true;

    private bool $includeStepDescriptions = true;

    private int $headingLevel = 2;

    private ?int $maxSteps = null;

    /**
     * Renders implemented BDT Gherkin step formulations as markdown instructions.
     *
     * @return string
     */
    protected function getOutput(): string
    {
        $catalog = $this->readStepCatalog();
        if ($catalog === []) {
            return $this->renderEmptyCatalog();
        }

        $markdown = [];
        if ($this->includeHeading === true) {
            $markdown[] = $this->buildHeading($this->headingLevel, 'Existing Automation Step Catalog');
        }
        //TODO Optionally remove this instruction if the surrounding instructions already provide a heading.
        $markdown[] = 'Use these existing BDT automation steps whenever possible. Prefer exact matches before creating new formulations.';

        if ($this->includeContexts === true) {
            $markdown[] = $this->renderCatalogByContext($catalog);
        } else {
            $markdown[] = $this->renderFlatCatalog($catalog);
        }

        if (null !== $this->maxSteps && $this->countCatalogSteps($catalog) >= $this->maxSteps) {
            $markdown[] = '_Step catalog limited to the first ' . $this->maxSteps . ' steps. Use `context_filters` to narrow the catalog if more precision is needed._';
        }

        return implode("\n\n", array_values(array_filter($markdown, static function (string $chunk): bool {
            return trim($chunk) !== '';
        })));
    }
/**
     * Condition group to restrict which Behat context files are scanned.
     *
     * @uxon-property context_filters
     * @uxon-type \exface\Core\CommonLogic\Model\ConditionGroup
     * @uxon-template {"operator": "AND", "conditions": [{"expression": "PATHNAME_RELATIVE", "comparator": "IS", "value": "*UI5Facade*"}]}
     *
     * @param UxonObject $uxonConditionGroup
     * @return GherkinStepCatalogConcept
     */
    protected function setContextFilters(UxonObject $uxonConditionGroup): GherkinStepCatalogConcept
    {
        $this->contextFilters = $uxonConditionGroup;
        return $this;
    }

    /**
     * Set to TRUE to group available steps by Behat context file.
     *
     * @uxon-property include_contexts
     * @uxon-type boolean
     * @uxon-default false
     *
     * @param bool $trueOrFalse
     * @return GherkinStepCatalogConcept
     */
    protected function setIncludeContexts(bool $trueOrFalse): GherkinStepCatalogConcept
    {
        $this->includeContexts = $trueOrFalse;
        return $this;
    }

    /**
     * Set to FALSE if the surrounding instructions already provide a heading.
     *
     * @uxon-property include_heading
     * @uxon-type boolean
     * @uxon-default true
     *
     * @param bool $trueOrFalse
     * @return GherkinStepCatalogConcept
     */
    protected function setIncludeHeading(bool $trueOrFalse): GherkinStepCatalogConcept
    {
        $this->includeHeading = $trueOrFalse;
        return $this;
    }

    /**
     * Set to FALSE to omit each step's full Markdown description and only print the step itself.
     *
     * @uxon-property include_step_descriptions
     * @uxon-type boolean
     * @uxon-default true
     *
     * @param bool $trueOrFalse
     * @return GherkinStepCatalogConcept
     */
    protected function setIncludeStepDescriptions(bool $trueOrFalse): GherkinStepCatalogConcept
    {
        $this->includeStepDescriptions = $trueOrFalse;
        return $this;
    }

    /**
     * Heading level used for the generated catalog title.
     *
     * @uxon-property heading_level
     * @uxon-type integer
     * @uxon-default 2
     *
     * @param int $level
     * @return GherkinStepCatalogConcept
     */
    protected function setHeadingLevel(int $level): GherkinStepCatalogConcept
    {
        if ($level < 1) {
            throw new AiConceptConfigurationError($this, 'Invalid `heading_level` value for GherkinStepCatalogConcept: heading levels must be greater than 0.');
        }
        $this->headingLevel = $level;
        return $this;
    }

    /**
     * Maximum number of step formulations to include in the prompt.
     *
     * @uxon-property max_steps
     * @uxon-type integer
     *
     * @param int $maxSteps
     * @return GherkinStepCatalogConcept
     */
    protected function setMaxSteps(int $maxSteps): GherkinStepCatalogConcept
    {
        if ($maxSteps < 1) {
            throw new AiConceptConfigurationError($this, 'Invalid `max_steps` value for GherkinStepCatalogConcept: max_steps must be greater than 0.');
        }
        $this->maxSteps = $maxSteps;
        return $this;
    }
/**
     * Reads all available BDT Gherkin steps grouped by context file.
     *
     * @return array<string,array<int,array{step:string,full_description:string}>>
     */
    private function readStepCatalog(): array
    {
        $catalog = [];
        $seenSteps = [];
        $remainingSteps = $this->maxSteps;

        foreach ($this->readContextRows() as $contextRow) {
            if ($remainingSteps !== null && $remainingSteps <= 0) {
                break;
            }

            $filePath = trim((string) ($contextRow['PATHNAME_RELATIVE'] ?? ''));
            if ($filePath === '') {
                continue;
            }

            $contextLabel = $this->buildContextLabel($contextRow, $filePath);
            foreach ($this->readStepsForContext($filePath) as $step) {
                $stepText = $step['step'];
                if (isset($seenSteps[$stepText])) {
                    continue;
                }

                $catalog[$contextLabel][] = $step;
                $seenSteps[$stepText] = true;

                if ($remainingSteps !== null && --$remainingSteps <= 0) {
                    break;
                }
            }
        }

        return $catalog;
    }

    /**
     * Reads Behat context rows from the BDT metamodel object.
     *
     * @return array<int,array<string,mixed>>
     */
    private function readContextRows(): array
    {
        $contextSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.BDT.GHERKIN_CONTEXT');
        $contextSheet->getColumns()->addMultiple([
            'PATHNAME_RELATIVE',
            'FILENAME',
            'CLASS'
        ]);

        if (null !== $this->contextFilters) {
            $contextSheet->setFilters(ConditionGroupFactory::createFromUxon($this->getWorkbench(), $this->contextFilters, $contextSheet->getMetaObject()));
        }

        $contextSheet->getSorters()->addFromString('PATHNAME_RELATIVE', SortingDirectionsDataType::ASC);
        $contextSheet->dataRead();

        return $contextSheet->getRows();
    }

    /**
     * Reads unique step formulations and their full Markdown descriptions.
     *
     * @param string $filePath
     * @return array<int,array{step:string,full_description:string}>
     */
    private function readStepsForContext(string $filePath): array
    {
        $stepSheet = DataSheetFactory::createFromObjectIdOrAlias(
            $this->getWorkbench(),
            'axenox.BDT.GHERKIN_ANNOTATION'
        );
        $stepSheet->getColumns()->addMultiple([
            'STEP',
            'FULL_DESCRIPTION',
            'FILE',
            'CLASS'
        ]);
        $stepSheet->getFilters()->addConditionFromString(
            'FILE',
            $filePath,
            ComparatorDataType::EQUALS
        );
        $stepSheet->getSorters()->addFromString(
            'STEP',
            SortingDirectionsDataType::ASC
        );
        $stepSheet->dataRead();

        $steps = [];
        foreach ($stepSheet->getRows() as $row) {
            $step = trim((string) ($row['STEP'] ?? ''));
            if ($step === '') {
                continue;
            }

            $steps[$step] = [
                'step' => $step,
                'full_description' => trim((string) ($row['FULL_DESCRIPTION'] ?? '')),
            ];
        }

        return array_values($steps);
    }

    /**
     * @param array<string,array<int,array{step:string,full_description:string}>> $catalog
     * @return string
     */
    private function renderFlatCatalog(array $catalog): string
    {
        $entries = [];
        foreach ($catalog as $contextSteps) {
            foreach ($contextSteps as $step) {
                $entries[] = $this->renderStepEntry($step);
            }
        }

        return implode("\n\n", $entries);
    }

    /**
     * Renders steps grouped by their Behat context.
     *
     * @param array<string,array<int,array{step:string,full_description:string}>> $catalog
     * @return string
     */
    private function renderCatalogByContext(array $catalog): string
    {
        $chunks = [];
        foreach ($catalog as $contextLabel => $steps) {
            if ($steps === []) {
                continue;
            }

            $entries = [];
            foreach ($steps as $step) {
                $entries[] = $this->renderStepEntry($step);
            }

            $chunks[] = $this->buildHeading($this->headingLevel + 1, $contextLabel) . "\n\n" . implode("\n\n", $entries);
        }

        return implode("\n\n", $chunks);
    }

    /**
     * Renders a step and its full Markdown description.
     *
     * @param array{step:string,full_description:string} $step
     * @return string
     */
    private function renderStepEntry(array $step): string
    {
        $markdown = [
            $this->renderGherkinCodeBlock([$step['step']]),
        ];
        if ($this->includeStepDescriptions === true && $step['full_description'] !== '') {
            $markdown[] = $step['full_description'];
        }

        return implode("\n\n", $markdown);
    }

    /**
     * Renders a Gherkin code block for a list of step formulations.
     *
     * @param array<int,string> $steps
     * @return string
     */
    private function renderGherkinCodeBlock(array $steps): string
    {
        $content = implode("\n", $steps);
        $fence = $this->buildCodeFence($content);

        return $fence . 'gherkin' . "\n" . $content . "\n" . $fence;
    }

    /**
     * Renders a short fallback when no implemented steps can be found.
     *
     * @return string
     */
    private function renderEmptyCatalog(): string
    {
        $markdown = [];
        if ($this->includeHeading === true) {
            $markdown[] = $this->buildHeading($this->headingLevel, 'Existing Automation Step Catalog');
        }
        $markdown[] = '_No implemented BDT Gherkin steps were found. Create new formulations only when no reusable step exists._';

        return implode("\n\n", $markdown);
    }

    /**
     * Builds a readable context label, preferring the path used by InputGherkin.
     *
     * @param array<string,mixed> $contextRow
     * @param string $filePath
     * @return string
     */
    private function buildContextLabel(array $contextRow, string $filePath): string
    {
        if ($filePath !== '') {
            return $filePath;
        }

        $class = trim((string) ($contextRow['CLASS'] ?? ''));
        if ($class !== '') {
            return $class;
        }

        return $filePath;
    }

    /**
     * Builds a markdown heading with the requested level.
     *
     * @param int $level
     * @param string $caption
     * @return string
     */
    private function buildHeading(int $level, string $caption): string
    {
        return str_repeat('#', max(1, $level)) . ' ' . $caption;
    }

    /**
     * Chooses a markdown fence that cannot be closed by the generated catalog.
     *
     * @param string $content
     * @return string
     */
    private function buildCodeFence(string $content): string
    {
        preg_match_all('/`+/', $content, $matches);
        $longestBacktickSequence = 2;
        foreach ($matches[0] as $sequence) {
            $longestBacktickSequence = max($longestBacktickSequence, strlen($sequence));
        }

        return str_repeat('`', $longestBacktickSequence + 1);
    }

    /**
     * Counts rendered catalog steps.
     *
     * @param array<string,array<int,array{step:string,full_description:string}>> $catalog
     * @return int
     */
    private function countCatalogSteps(array $catalog): int
    {
        $count = 0;
        foreach ($catalog as $steps) {
            $count += count($steps);
        }

        return $count;
    }
}
 