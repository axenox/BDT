<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;
use axenox\BDT\Interfaces\TestResultInterface;
use exface\Core\Interfaces\Debug\LogBookInterface;
use PHPUnit\Framework\Assert;

class UI5MapNode extends UI5DataTableNode
{
    public function capturesFocus() : bool
    {
        return true;
    }

    public function checkWorksAsExpected(LogBookInterface $logbook) : TestResultInterface
    {
        $widgetType = $this->getWidgetType();
        $logbook->addLine( 'Looking at `' . $widgetType . '` ' . $this->getCaption());
        $leafletPane = $this->getSession()->getPage()->find('css', "#{$this->getNodeElement()->getAttribute('id')} .leaflet-pane");
        Assert::assertNotNull($leafletPane, 'Leaflet pane not found in map node!');
        return SubstepResult::createPassed($logbook);
    }
}