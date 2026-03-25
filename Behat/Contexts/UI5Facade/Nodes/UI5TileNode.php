<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\DataTypes\StepStatusDataType;
use exface\Core\Actions\GoToPage;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Widgets\Tile;
use PHPUnit\Framework\Assert;

class UI5TileNode extends UI5ButtonNode
{
    
    public function getCaption(): string
    {
        $headerNode = $this->getNodeElement()->find('css', '.sapMGTHdrTxt > .sapMText  > span');
        if ($headerNode) {
            return $headerNode->getText();
        }
        return $this->getNodeElement()->getText();
    }
}