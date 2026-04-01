<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\BDT\Exceptions\FacadeNodeException;
use axenox\BDT\Interfaces\FacadeNodeInterface;

/**
 * Represents a UI5 RangeFilter node with two date/value inputs (from and to).
 *
 * @method \exface\Core\Widgets\RangeFilter getWidget()
 */
class UI5RangeFilterNode extends UI5FilterNode
{
    private ?UI5InputNode $fromNode = null;
    private ?UI5InputNode $toNode   = null;

    // -----------------------------------------------------------------------
    // From / To node accessors
    // -----------------------------------------------------------------------

    public function getFromNode(): UI5InputNode
    {
        if ($this->fromNode === null) {
            $this->fromNode = $this->findInputNodeByIndex(0);
        }
        return $this->fromNode;
    }

    public function getToNode(): UI5InputNode
    {
        if ($this->toNode === null) {
            $this->toNode = $this->findInputNodeByIndex(1);
        }
        return $this->toNode;
    }

    private function findInputNodeByIndex(int $index): UI5InputNode
    {
        $id       = $this->getNodeElement()->getAttribute('id');
        // Exclude non-input widgets (Text, Label etc.) — target only input-type exfw children
        $children = $this->getNodeElement()->findAll(
            'css',
            "#{$id} .exfw[class*='exfw-Input']"
        );

        if (!isset($children[$index])) {
            throw new FacadeNodeException(
                $this,
                "RangeFilter '{$this->getCaption()}': cannot find input at index {$index}. " .
                "Found " . count($children) . " input widget(s)."
            );
        }

        $node = UI5FacadeNodeFactory::createFromNodeElement(
            $children[$index],
            $this->getSession(),
            $this->getBrowser()
        );

        if (!$node instanceof UI5InputNode) {
            throw new FacadeNodeException(
                $this,
                "RangeFilter '{$this->getCaption()}': inner widget at index {$index} is not a UI5InputNode."
            );
        }

        return $node;
    }

    // -----------------------------------------------------------------------
    // Overrides
    // -----------------------------------------------------------------------

    /**
     * Returns the visible value of the "from" input.
     */
    public function getValueVisible(): ?string
    {
        return $this->getFromNode()->getValueVisible();
    }

    /**
     * Sets both from and to inputs to the same value.
     * For a proper range use setRangeVisible() instead.
     */
    public function setValueVisible(string $value, bool $validate = true): FacadeNodeInterface
    {
        return $this->setRangeVisible($value, $value, $validate);
    }

    /**
     * Sets the from and to inputs independently.
     * Pass null for either side to leave it untouched.
     */
    public function setRangeVisible(?string $from, ?string $to, bool $validate = true): FacadeNodeInterface
    {
        if ($from !== null) {
            $this->getFromNode()->setValueVisible($from, $validate);
        }
        if ($to !== null) {
            $this->getToNode()->setValueVisible($to, $validate);
        }
        return $this;
    }

    /**
     * Clears both from and to inputs.
     */
    public function setValueEmpty(bool $validate = true): FacadeNodeInterface
    {
        $this->getFromNode()->setValueEmpty($validate);
        $this->getToNode()->setValueEmpty($validate);
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function reset(): FacadeNodeInterface
    {
        return $this->setValueEmpty(false);
    }

    /**
     * Parent getInputNode() is meaningless for a RangeFilter.
     * Use getFromNode() / getToNode() instead.
     */
    public function getInputNode(): UI5InputNode
    {
        return $this->getFromNode();
    }
}