<?php

namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use exface\Core\Widgets\Display;

/**
 * Provides read-only access to displayed field text so steps do not depend on UI5 markup.
 * 
 * @method Display getWidget()
 */
class UI5DisplayNode extends UI5AbstractNode
{
    /**
     * Finds a visible display by its model caption within the supplied dialog, including its header.
     *
     * Delegates to the dialog's shared field lookup so Display-only callers retain
     * their API without duplicating caption, visibility or header traversal logic.
     *
     * @param UI5DialogNode $dialog Dialog whose contents may be searched
     * @param string $caption Model caption of the display field
     * @return UI5DisplayNode|null
     */
    public static function findByCaption(UI5DialogNode $dialog, string $caption): ?self
    {
        return $dialog->findFieldByCaption($caption, 'Display');
    }

    /**
     * Reads rendered text rather than an input value because Display controls are read-only UI5 text elements.
     *
     * Header Displays render as ObjectStatus controls with the caption and value in
     * separate spans. Reading only the value span prevents the caption from becoming
     * part of the asserted text. UI5 omits that span when the status text is empty.
     *
     * @return string
     */
    public function getValueVisible(): string
    {
        $element = $this->getNodeElement();
        if ($element->hasClass('sapMObjStatus')) {
            $text = $element->find('css', '.sapMObjStatusText');
            return $text === null ? '' : $text->getText();
        }
        return $element->getText();
    }
}
