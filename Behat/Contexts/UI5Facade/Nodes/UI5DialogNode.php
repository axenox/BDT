<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;
use axenox\BDT\Interfaces\TestResultInterface;
use exface\Core\Interfaces\Debug\LogBookInterface;
use Behat\Mink\Element\NodeElement;
use PHPUnit\Framework\Assert;

class UI5DialogNode extends UI5ContainerNode
{
    /**
     * Returns the title text shown in this dialog's header.
     *
     * WHY an own implementation: a sap.m.Dialog does not carry its title in `aria-label` - it points to
     * a separate <h1 id="<dialogId>-title"> via `aria-labelledby`. The inherited aria-label based
     * caption therefore always came back empty for dialogs, which made every log line and every step
     * that identifies a dialog by its title unusable.
     */
    public function getCaption() : string
    {
        $dialogId = $this->getNodeElement()->getAttribute('id');
        if ($dialogId !== null && $dialogId !== '') {
            $title = $this->getNodeElement()->find('css', '#' . $dialogId . '-title');
            if ($title !== null) {
                return trim($title->getText());
            }
        }

        // Fall back to the generic aria-label based caption for dialogs rendered without a title control
        return parent::getCaption();
    }
    
    public function checkWorksAsExpected(LogBookInterface $logbook) : TestResultInterface
    {
        $logbook->addLine('Seeing the dialog ' . $this->getCaption());
        $dialog = $this->getNodeElement();

        // Only look for the close button INSIDE this dialog. Searching the whole
        // page would match stale buttons of other UI5 navigation pages/dialogs
        // (e.g. the many "back" buttons left behind while navigating in and out).
        $attempt = 0;
        $closeBtn = null;
        do {
            $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);
            $closeBtn = $this->findDialogButtonByCaption($dialog, 'ACTION.GENERIC.CLOSE');
            $attempt++;
        } while ($attempt < 2 && $closeBtn === null);

        if ($closeBtn !== null) {
            $closeBtn->click();
            $logbook->addLine('Pressing close button of the dialog');
        } elseif (null !== $backBtn = $this->findDialogBackButton($dialog)) {
            // Some dialogs (e.g. UI5 navigation pages) do not expose a "Close"
            // button on the current page - they are dismissed via the header
            // back button ("Zurück"). Fall back to this dialog's own back button.
            $backBtn->click();
            $logbook->addLine('Pressing back button of the dialog');
        } else {
            // Last resort: neither a close nor a back button could be found.
            // Most UI5 dialogs can still be dismissed by pressing the ESC key,
            // so simulate it on this dialog before giving up.
            $escPressed = $this->pressEscapeOnDialog($dialog);
            Assert::assertTrue($escPressed, 'Cannot close the dialog: neither close nor back button found and ESC key could not be dispatched');
            $logbook->addLine('Pressing ESC to close the dialog');
        }

        $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);
        $logbook->addIndent(-1);
        return SubstepResult::createPassed($logbook);
    }

    /**
     * Finds a visible button with the given caption WITHIN this dialog only.
     *
     * @param NodeElement $dialog
     * @param string $captionKey translation key, e.g. ACTION.GENERIC.CLOSE
     * @return NodeElement|null
     */
    private function findDialogButtonByCaption(NodeElement $dialog, string $captionKey) : ?NodeElement
    {
        $caption = $this->getBrowser()
            ->getWorkbench()
            ->getCoreApp()
            ->getTranslator($this->getBrowser()->getLocale())
            ->translate($captionKey);

        $literal = $this->xpathLiteral($caption);
        $xpath = sprintf(
            ".//button[
                .//bdi[normalize-space(.)=%1\$s]
                or normalize-space(@title)=%1\$s
                or normalize-space(@aria-label)=%1\$s
            ]",
            $literal
        );

        // Prefer the last matching button (the one of the current/top-most page)
        foreach (array_reverse($dialog->findAll('xpath', $xpath)) as $el) {
            if ($this->isElementVisibleInBrowser($el)) {
                return $el;
            }
        }

        return null;
    }

    /**
     * Finds this dialog's own header back/navigation button ("Zurück").
     *
     * The UI5 dialog page renders its back button with the id
     * "<dialogId>-navButton", so we can target exactly this dialog's button
     * and never one of the stale back buttons of other navigation pages.
     *
     * @param NodeElement $dialog
     * @return NodeElement|null
     */
    private function findDialogBackButton(NodeElement $dialog) : ?NodeElement
    {
        $dialogId = $dialog->getAttribute('id');
        if ($dialogId !== null && $dialogId !== '') {
            $navButton = $this->getSession()->getPage()->find(
                'xpath',
                sprintf('.//button[@id=%s]', $this->xpathLiteral($dialogId . '-navButton'))
            );
            if ($navButton !== null && $this->isElementVisibleInBrowser($navButton)) {
                return $navButton;
            }
        }

        // Fall back to a button captioned like the generic "back" action, but
        // still scoped to this dialog so we never grab another page's button.
        return $this->findDialogButtonByCaption($dialog, 'ACTION.GOBACK.NAME');
    }

    /**
     * Dispatches an ESC keydown/keyup on this dialog to make UI5 close it.
     *
     * This is the last-resort fallback used when the dialog exposes neither a
     * close nor a back button. UI5 dialogs (sap.m.Dialog) close on the Escape
     * key, so we simulate a full keydown/keyup sequence on the dialog element.
     *
     * @param NodeElement $dialog
     * @return bool true if the ESC key could be dispatched
     */
    private function pressEscapeOnDialog(NodeElement $dialog) : bool
    {
        $dialogId = $dialog->getAttribute('id');
        if ($dialogId === null || $dialogId === '') {
            return false;
        }

        $idJs = json_encode($dialogId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $script = <<<JS
(function(){
    var el = document.getElementById($idJs);
    if (!el) return false;
    var target = el.querySelector(':focus') || el;
    if (target.focus) target.focus();
    ['keydown','keyup'].forEach(function(type){
        target.dispatchEvent(new KeyboardEvent(type, {
            key: 'Escape', code: 'Escape', keyCode: 27, which: 27, bubbles: true
        }));
    });
    return true;
})();
JS;

        return (bool) $this->getSession()->evaluateScript($script);
    }
}