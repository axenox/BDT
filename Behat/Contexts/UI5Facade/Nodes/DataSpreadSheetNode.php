<?php

namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;



class DataSpreadSheetNode extends UI5AbstractNode
{
    public function getCaption(): string
    {
        // TODO: Implement getCaption() method.
        return '';
    }

    public function getLastRowOfColumn(string $columnCaption){
        //TODO
    }
    public function capturesFocus(): bool {
        return false;
    }

}