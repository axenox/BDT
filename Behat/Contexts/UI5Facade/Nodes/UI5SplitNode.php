<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;
use axenox\BDT\Interfaces\TestResultInterface;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Widgets\Split;

/**
 * @method \exface\Core\Widgets\Split getWidget()
 */
class UI5SplitNode extends UI5ContainerNode
{

    public function checkWorksAsExpected(LogBookInterface $logbook) : TestResultInterface
    {
        return $this->checkSplitWorksAsExpected($this->getWidget(), $logbook);
    }
    
    protected function checkSplitWorksAsExpected(Split $widget, LogBookInterface $logbook) : TestResultInterface
    {
        $childWidgets = $widget->getWidgets();
        $failed = false;
        foreach ($childWidgets as $childWidget) {
            if ($childWidget->isHidden()) {
                continue;
            }
            // If it is a nested split, do not check the inner split itself, just check its children. The inner
            // split is not rendered in UI5 - see UI5SplitPanel::buildJsConstructor()
            // Otherwise just check every child normally.
            if ($childWidget->isFilledBySingleWidget() && $childWidget->getFillerWidget() instanceof Split) {
                // Child node id will not be visible in the DOM - see UI5SplitPanel::buildJsConstructor()
                $childResult = $this->checkSplitWorksAsExpected($childWidget->getFillerWidget(), $logbook);
            } else {
                // TODO extract checkChildWorksAsExpectd() into separate Method in UI5ContainerNode
                $childResult = parent::checkChildWorksAsExpected($childWidget, $logbook);
            }
            if ($childResult->isFailed()) {
                $failed = true;
            }
        }
        $result = $failed ? SubstepResult::createFailed(null, $logbook) : SubstepResult::createPassed($logbook);
        return $result;
    }
}