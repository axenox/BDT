<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;
use axenox\BDT\Exceptions\FacadeNodeException;
use axenox\BDT\Interfaces\TestResultInterface;
use exface\Core\DataTypes\StringDataType;
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
        // TODO make map node also test filtering the same way as the DataTableNode.
        // Probably need to extract a generic DataNode and extend that to DataTableNode and MapNode
        return $this->runAsSubstep(
            function() use ($logbook) {
                $logbook->addLine( $this->buildMessageLookingAt(true));
                $leafletPane = $this->getSession()->getPage()->find('css', "#{$this->getNodeElement()->getAttribute('id')} .leaflet-pane");
                Assert::assertNotNull($leafletPane, 'Leaflet pane not found in map node!');
            },
            $this->buildMessageLookingAt(false),
            null,
            $logbook
        );
    }

    public function getElementId() : string
    {
        $node = $this->getNodeElement();
        $nodeId = $node->getAttribute('id');
        if (StringDataType::endsWith($nodeId, '_leaflet')) {
            $id = StringDataType::substringBefore($nodeId, '_leaflet');
        } else {
            $id = $nodeId;
        }
        return $id;
    }
}