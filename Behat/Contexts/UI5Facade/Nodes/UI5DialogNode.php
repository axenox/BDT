<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;
use axenox\BDT\Interfaces\TestResultInterface;
use exface\Core\Interfaces\Debug\LogBookInterface;

class UI5DialogNode extends UI5AbstractNode
{
    public function getCaption() : string
    {
        return strstr($this->getNodeElement()->getAttribute('aria-label'), "\n", true);;
    }

    public function capturesFocus() : bool
    {
        return true;
    }
    
    public function checkWorksAsExpected(LogBookInterface $logbook) : TestResultInterface
    {
        $closeBtn = $this->findVisibleButtonByCaption('ACTION.GENERIC.CLOSE', false);
        $closeBtn->click();
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);
        $logbook->addLine('Pressing close button of the dialog');
        $logbook->addIndent(-1);
        return SubstepResult::createPassed($logbook);
    }
}