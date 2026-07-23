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
            // Stop the container check as soon as the browser is no longer on the container's own page.
            // WHY: a tile run navigates away for every child and is expected to return afterwards. If one
            // of those navigations failed (e.g. "Cannot open path ... after 2 attempts"), the container
            // element is stale and every remaining child lookup fails instantly - turning one real
            // navigation error into a bogus "Cannot find DOM element" row per sibling and burying the
            // actual cause. Recording the lost page once keeps the failure attributable to its origin.
            $pageCurrent = $this->getBrowser()->getPageCurrent()->getAliasWithNamespace();
            if ($pageCurrent !== $containerAlias) {
                $this->logSubstep(
                    'Checking children of ' . $this->getWidget()->getWidgetType(),
                    StepStatusDataType::FAILED,
                    'Aborted: expected to be on page "' . $containerAlias . '" but the browser is on "'
                    . $pageCurrent . '". A preceding navigation did not return to the container page.'
                );
                $failed = true;
                break;
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

    protected function checkChildWorksAsExpected(WidgetInterface $childWidget, LogBookInterface $logbook) : TestResultInterface
    {
        $childElementId = $this->getElementIdFromWidget($childWidget);
        $childWidgetElement = $this->getNodeElement()->find('css', '#' . $childElementId);

        // Give an asynchronously rendered child a chance to appear before declaring it missing.
        // WHY: a single find() returns immediately, so a child UI5 has not rendered yet is
        // indistinguishable from one that does not exist at all. These failures were being recorded
        // within hundredths of a millisecond, which is far too fast to be a trustworthy verdict.
        if ($childWidgetElement === null) {
            $this->getBrowser()->getWaitManager()->waitForPendingOperations(false, true, true);
            $childWidgetElement = $this->getNodeElement()->find('css', '#' . $childElementId);
        }

        if ($childWidgetElement === null) {
            $caption = $childWidget->getCaption();
            if (! $caption) {
                $caption = 'with id "' . $childWidget->getId() . '"';
            } else {
                $caption = '"' . $caption . '"';
            }
            // Name the element and the page in the failure message. WHY: "Cannot find DOM element" on
            // its own cannot be acted upon - it does not say what was searched for, where, or whether
            // the surrounding container was still the expected one.
            $resultEvent = $this->logSubstep(
                'Looking at ' . $childWidget->getWidgetType() . ' ' . $caption,
                StepStatusDataType::FAILED,
                'Cannot find DOM element with id "' . $childElementId . '" inside '
                . $this->getWidget()->getWidgetType() . ' on page "'
                . $this->getBrowser()->getPageCurrent()->getAliasWithNamespace() . '"'
            );
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