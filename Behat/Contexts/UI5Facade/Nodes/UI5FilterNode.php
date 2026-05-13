<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\BDT\Interfaces\FacadeNodeInterface;
use Behat\Mink\Element\NodeElement;
use exface\Core\DataTypes\BooleanDataType;

/**
 * Represents a UI5 Filter Node for handling various types of filter inputs
 * 
 * This class provides methods to interact with different types of UI5 filter controls like:
 * - ComboBox
 * - MultiComboBox
 * - Select
 * - Standard Input
 * 
 * It supports finding and setting values for different filter input types 
 * commonly found in SAP UI5 applications.
 * 
 * @method \exface\Core\Widgets\Filter getWidget()
 */
class UI5FilterNode extends UI5AbstractNode
{
    private $inputNode = null;
    
    /**
     * Retrieves the caption (label) of the filter node
     * 
     * Attempts to find the label text within a UI5 filter control
     * using a specific CSS selector for label elements.
     * 
     * @return string Trimmed label text, or empty string if not found
     */
    public function getCaption(): string
    {
        $label = $this->getNodeElement()->find('css', '.sapMLabel bdi');
        return trim($label->getText() ?? '');
    }

    /**
     * @return array|bool|string|null
     */
    public function getValueVisible()
    {
        return $this->getInputNode()->getValueVisible();
    }

    /**
     * Sets the value for a filter input based on its control type
     * 
     * @param string $value
     * @param bool $validate
     * @return FacadeNodeInterface
     */
    public function setValueVisible(string $value, bool $validate = true): FacadeNodeInterface
    {
        $this->getInputNode()->setValueVisible($value, $validate);  
        return $this;
    }

    /**
     * Gets the current page from the session
     * 
     * @return NodeElement The current page element
     */
    public function getPage()
    {
        // Directly get the page with session
        return $this->getSession()->getPage();
    }

    /**
     * @param bool $validate
     * @return FacadeNodeInterface
     */
    public function setValueEmpty(bool $validate = true): FacadeNodeInterface
    {
        $this->getInputNode()->setValueEmpty($validate);
        return $this;
    }

    /**
     * {@inheritDoc}
     * @see UI5AbstractNode::reset()
     */
    public function reset() : FacadeNodeInterface
    {
        return $this->setValueEmpty();
    }
    
    public function getInputNode() : UI5InputNode
    {
        if ($this->inputNode === null) {
            // Find the first widget inside the filter DOM node.
            // Note, just calling `find()` will return the filter node itself because it has the `.exfw` class and
            // also is a widget.
            $inputEl = $this->getNodeElement()->find('css', "#{$this->getNodeElement()->getAttribute('id')} .exfw");
            $this->inputNode = UI5FacadeNodeFactory::createFromNodeElement($inputEl, $this->getSession(), $this->getBrowser());
        }
        return $this->inputNode;
    }

    /**
     * @return bool
     */
    public function shouldBeVisible() : bool
    {
        $filterWidget = $this->getWidget();
        $hiddenAlways = $filterWidget->isHidden();
        if ($hiddenAlways) {
            return false;
        }
        $hiddenIf = $filterWidget->getHiddenIf();
        if ($hiddenIf) {
            // TODO verify, that $this->getWorkbench()->getSecurity()->getAuthenticatedUser() is the test-user.
            // If not, we need another approach!!!
            
            // A filter has no input-data, so we do not need a DataSheet (unlike in case of a Button)
            // This will result in an error though, if the hidden_if depends on a linked widget. Don't know, how to
            // solve that yet... maybe we can get the current value of that widget here somehow???
            $result = $hiddenIf->evaluate();
            return BooleanDataType::cast($result) ?? true;
        }
        
        return true;
    }
}