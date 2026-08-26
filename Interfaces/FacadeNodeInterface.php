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
     * @return WidgetInterface
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
     * @return TestResultInterface
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
    
    public function checkDisabled(): bool;

    /**
     * Tells whether the facade renders this kind of widget as a control of its own.
     *
     * WHY this exists: not every widget of the model becomes a DOM element. A `Tabs` widget inside a
     * maximized `Dialog` is the clearest case - the facade folds every `Tab` directly into the
     * dialog's ObjectPageLayout and never creates a control for the `Tabs` widget itself. A node
     * returning FALSE is handed the surrounding container's element as a search scope and locates its
     * parts by itself. Everything else keeps the normal "find my element by id" behaviour.
     *
     * @return bool
     */
    public function usesOwnDomElement() : bool;
}