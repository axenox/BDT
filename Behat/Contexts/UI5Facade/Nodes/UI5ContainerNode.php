<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;
use axenox\BDT\DataTypes\StepStatusDataType;
use axenox\BDT\Exceptions\ChromeHangException;
use axenox\BDT\Interfaces\TestResultInterface;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\WidgetInterface;

/**
 * @method \exface\Core\Widgets\Container getWidget()
 */
class UI5ContainerNode extends UI5AbstractNode
{
    public function getCaption(): string
    {
        // TODO
        return '';
    }

    /**
     * Validates every visible child widget of this container.
     *
     * Iterates the widget model's child list and calls checkChildWorksAsExpected()
     * for each non-hidden child. Hidden widgets are skipped because they cannot
     * be interacted with and their validation would always fail on DOM lookup.
     *
     * Chrome-hang recovery:
     * If checkChildWorksAsExpected() throws a ChromeHangException (Chrome's CDP
     * connection was lost, typically after many GoToPage navigations in a long
     * tile run), the method:
     *   1. Calls UI5Browser::recoverChrome() with the child widget's caption,
     *      which triggers a Chrome restart, re-login, and direct navigation back
     *      to this container page.
     *   2. Retries the same child exactly once.
     *   3. Re-throws if the retry also hangs, stopping the run for this container.
     *
     * Non-ChromeHangException failures from individual children are recorded in
     * the logbook but do not stop iteration — all siblings are still tested.
     *
     * {@inheritDoc}
     */
    public function checkWorksAsExpected(LogBookInterface $logbook) : TestResultInterface
    {
        $containerAlias = $this->getWidget()->getPage()->getAliasWithNamespace();
        $childWidgets = $this->getWidget()->getWidgets();
        $failed = false;
        foreach ($childWidgets as $childWidget) {
            if ($childWidget->isHidden()) {
                continue;
            }
            $attempt = 0;
            while ($attempt < 2) {
                try {
                    $childResult = $this->checkChildWorksAsExpected($childWidget, $logbook);
                    if ($childResult->isFailed()) {
                        $failed = true;
                    }
                    break; // child validated — move to the next sibling

                } catch (ChromeHangException $e) {
                    $attempt++;
                    if ($attempt >= 2) {
                        // Chrome hung even after a fresh restart on this child.
                        throw $e;
                    }
                    $caption = $childWidget->getCaption() ?: $childWidget->getId();
                    $logbook->addLine('Chrome hang on child "' . $caption . '" — attempting recovery (attempt ' . $attempt . ')');
                    // Restart Chrome, re-login, and navigate directly back to
                    // this container page so the retry starts from a clean state.
                    $this->getBrowser()->recoverChrome($containerAlias);
                }
            }
        }
        return $failed ? SubstepResult::createFailed(null, $logbook) : SubstepResult::createPassed($logbook);
    }
    
    protected function checkChildWorksAsExpected(WidgetInterface $childWidget, logBookInterface $logbook) : TestResultInterface
    {
        $childWidgetElement = $this->getNodeElement()->find('css', '#' . $this->getElementIdFromWidget($childWidget));
        if ($childWidgetElement === null) {
            $caption = $childWidget->getCaption();
            if (! $caption) {
                $caption = 'with id "' . $childWidget->getId() . '"';
            } else {
                $caption = '"' . $caption . '"';
            }
            $resultEvent = $this->logSubstep('Looking at ' . $childWidget->getWidgetType() . ' ' . $caption, StepStatusDataType::FAILED, 'Cannot find DOM element');
            $childResult = $resultEvent->getResult();
        } else {
            $node = UI5FacadeNodeFactory::createFromWidgetType($childWidget->getWidgetType(), $childWidgetElement, $this->getSession(), $this->getBrowser());
            $childResult = $node->checkWorksAsExpected($logbook);
        }
        return $childResult;
    }

    /**
     * Determines whether the given node is nested inside another widget.
     *  * This check is crucial to prevent redundant testing of widgets that are already
     *  managed by a parent widget (e.g., filters within a DataTable). It traverses
     *  up the DOM tree from the current node:
     *  - If it encounters another element with the '.exfw' class before reaching
     *  this container, the node is considered "nested" and should be skipped.
     *  - This ensures that each widget's 'itWorksAsExpected' is only triggered
     *  once by its immediate logical parent.
     * 
     * @param $childNode
     * @return bool
     */
    private function isNodeInsideAnotherWidget($childNode): bool
    {
        $parent = $childNode->getParent();
        while ($parent !== null && $parent->getAttribute('id') !== $this->getNodeElement()->getAttribute('id')) {
            if ($parent->hasClass('exfw')) {
                return true;
            }
            $parent = $parent->getParent();
        }
        return false;
    }
}