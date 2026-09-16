<?php

namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use Behat\Mink\Element\NodeElement;

/**
 * Node for the `Tab` widget.
 *
 * WHY this node exists: a tab is addressed by two different elements. The container check (UI5TabsNode)
 * creates it for the element that carries the tab's own id, with the widget from the model at hand. The
 * tab focus (UI5Browser::focusTab()) creates it for the header the user clicks and then again for the
 * element that holds the tab's widgets - which for an IconTabBar is the bar's shared content area, an
 * element that has no `exfw` class of its own and belongs to no single widget. Everything else -
 * especially the scoped child search - is plain container behaviour.
 *
 * @method \exface\Core\Widgets\Tab getWidget()
 */
class UI5TabNode extends UI5ContainerNode
{
    /**
     * Reports `Tab` when the node was created for an element that is not a widget root.
     *
     * WHY: the inherited DOM fallback reads the type from the first `exfw` element inside the node. For a
     * tab's content area that is the first widget IN the tab - so every failure message would name an
     * "Input" instead of the tab - and for an empty tab it throws, turning "it has 0 widget of type ..."
     * into a FacadeNodeException instead of a passing count.
     *
     * {@inheritDoc}
     */
    public function getWidgetType(): ?string
    {
        if ($this->widget !== null) {
            return parent::getWidgetType();
        }
        return 'Tab';
    }

    /**
     * Resolves the element that holds this tab's widgets, given that this node stands for its header.
     *
     * WHY THIS LIVES ON THE NODE: it is knowledge about a tab element and nothing else - which is why it
     * can also serve the container check, where the same question ("where are this tab's children?")
     * comes up.
     *
     * WHY via the UI5 control tree and not via DOM structure: in neither rendering do the widgets sit
     * inside the header the user clicks. A sap.m.IconTabBar renders the selected tab's content into one
     * shared "<barId>-content" element; a sap.uxap.ObjectPageLayout (maximized dialog) renders every tab
     * as a section and only links it from an anchor bar item. Only the control tree knows which content
     * belongs to which header.
     *
     * @return NodeElement|null Null when the content area cannot be identified - never a guessed wider
     *         scope, because a wider scope would let a scoped count include widgets of other tabs
     */
    public function findContentElement(): ?NodeElement
    {
        $elementIdJs = json_encode((string) $this->getNodeElement()->getAttribute('id'));
        $xpathJs = json_encode($this->getNodeElement()->getXpath());
        $contentId = $this->getFromJavascript(<<<JS
(function(elementId, xpath) {
    var el = elementId ? document.getElementById(elementId) : null;
    if (!el && xpath) {
        el = document.evaluate(xpath, document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue;
    }
    var oCore = sap.ui.getCore();
    // The header element may be an inner part of a control - climb to the closest control root.
    var oCtrl = null;
    for (var n = el; n && !oCtrl; n = n.parentElement) {
        if (n.id && n.hasAttribute('data-sap-ui')) {
            oCtrl = oCore.byId(n.id) || null;
        }
    }
    for (var c = oCtrl; c; c = c.getParent()) {
        // IconTabBar: the selected tab's content lives in the bar's shared content area.
        if (c.isA('sap.m.IconTabBar')) {
            var sContentId = c.getId() + '-content';
            return document.getElementById(sContentId) ? sContentId : null;
        }
        // ObjectPage: the anchor bar item knows the section it scrolls to.
        var sSectionId = (typeof c.data === 'function' ? c.data('sectionId') : null)
            || (typeof c.getKey === 'function' ? c.getKey() : null);
        var oSection = sSectionId ? oCore.byId(sSectionId) : null;
        if (oSection && oSection.isA('sap.uxap.ObjectPageSectionBase')) {
            return oSection.getDomRef() ? oSection.getId() : null;
        }
        // Never climb past the ObjectPage - an IconTabBar further up would hand back an outer tab's content.
        if (c.isA('sap.uxap.ObjectPageLayout')) {
            return null;
        }
    }
    return null;
})({$elementIdJs}, {$xpathJs});
JS
        );
        if (! is_string($contentId) || $contentId === '') {
            return null;
        }
        return $this->getBrowser()->getPage()->findById($contentId);
    }
}