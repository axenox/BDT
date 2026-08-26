<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

/**
 * Node for the `WidgetGroup` widget.
 *
 * WHY this node exists: the facade renders a `WidgetGroup` as a sap.ui.layout.form.FormContainer,
 * and UI5 gives that container a generated id ("__container5---Panel") instead of the id the facade
 * built for the widget. The group therefore cannot be found by its own id, while all of its children
 * carry theirs as usual. Handing the surrounding element to the inherited container walk as a search
 * scope is all that is needed.
 *
 * @method \exface\Core\Widgets\WidgetGroup getWidget()
 */
class UI5WidgetGroupNode extends UI5ContainerNode
{
    /**
     * {@inheritDoc}
     * @see FacadeNodeInterface::usesOwnDomElement()
     */
    public function usesOwnDomElement() : bool
    {
        return false;
    }
}