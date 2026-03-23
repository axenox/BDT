<?php
namespace axenox\BDT\Interfaces;

use Behat\Mink\Element\NodeElement;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Interfaces\WorkbenchDependantInterface;

interface FacadeNodeInterface extends WorkbenchDependantInterface
{
    /**
     * Retrieves the underlying Mink NodeElement for the current UI node
     * @return NodeElement
     */
    public function getNodeElement(): NodeElement;

    /**
     * Extracts and returns the caption of the UI node
     * @return string
     */
    public function getCaption(): string;

    /**
     * Determines the specific type of widget or UI component
     * @return string|null
     */
    public function getWidgetType(): ?string;

    /**
     * @return WidgetInterface#
     */
    public function getWidget() : WidgetInterface;

    /**
     * Checks if the UI node can capture  focus
     * @return bool
     */
    public function capturesFocus(): bool;

    /**
     * checks the functionality of the node
     * @param LogBookInterface $logbook
     * @return int
     */
    public function checkWorksAsExpected(LogBookInterface $logbook) : TestResultInterface;

    /**
     * Returns the (outer) DOM node, that contains the entire widget, searching from the given inner node upwards
     * 
     * @param NodeElement $innerDomNode
     * @return NodeElement
     */
    public static function findWidgetNode(NodeElement $innerDomNode) : NodeElement;

    /**
     * @return FacadeNodeInterface
     */
    public function reset() : FacadeNodeInterface;
}