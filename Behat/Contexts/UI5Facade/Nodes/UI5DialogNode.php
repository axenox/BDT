<?php

namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Interfaces\TestResultInterface;
use Behat\Mink\Element\NodeElement;
use exface\Core\Interfaces\Debug\LogBookInterface;
use PHPUnit\Framework\Assert;

class UI5DialogNode extends UI5ContainerNode
{
    /**
     * Maximum number of close gestures performed on one dialog.
     *
     * WHY TWO: the first attempt closes a dialog without unsaved changes. A dialog with unsaved
     * changes needs a second one, because the first only produces the core's confirmation. A
     * third would mean the dialog does not react to any known gesture at all, which is a defect
     * to report rather than something to retry into a timeout.
     */
    const MAX_CLOSE_ATTEMPTS = 2;

    public function checkWorksAsExpected(LogBookInterface $logbook): TestResultInterface
    {
        $logbook->addLine('Seeing the dialog ' . $this->getCaption());
        $dialog = $this->getNodeElement();

        // Capture id and caption before touching anything. Once UI5 removes the dialog from the
        // DOM, the Mink NodeElement can no longer be queried - the postcondition check and the
        // failure message both have to survive on these two strings alone.
        $dialogId = (string)$dialog->getAttribute('id');
        $dialogCaption = $this->getCaption();

        $result = parent::checkWorksAsExpected($logbook);

        $this->closeDialogAndVerify($dialog, $dialogId, $dialogCaption, $logbook);

        $logbook->addIndent(-1);
        return $result;
    }

    /**
     * Closes this dialog and verifies that it really left the screen.
     *
     * WHY THE VERIFICATION EXISTS: clicking a close button used to be treated as success. It is
     * not. Validating a dialog fills its inputs, so the core considers it dirty and answers the
     * close with an "unsaved changes" confirmation, leaving the original dialog on screen behind
     * a modal overlay. Without a postcondition check the step passed while every widget checked
     * afterwards failed with "Cannot find DOM element" - one bogus row per sibling, with the real
     * cause nowhere in the report.
     *
     * @param NodeElement $dialog
     * @param string $dialogId
     * @param string $dialogCaption
     * @param LogBookInterface $logbook
     * @return void
     */
    private function closeDialogAndVerify(
        NodeElement $dialog,
        string $dialogId,
        string $dialogCaption,
        LogBookInterface $logbook
    ): void
    {
        $attempt = 0;
        while ($attempt < self::MAX_CLOSE_ATTEMPTS) {
            $attempt++;
            $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);
            $this->triggerClose($dialog, $logbook);
            $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);

            if (! $this->isDialogOpen($dialogId)) {
                return;
            }

            // Still there. The most likely reason is the core asking whether the unsaved changes
            // may be dropped. Answering it lets the next loop pass repeat the close gesture.
            $this->confirmDiscardChangesIfPresent($logbook);

            if (! $this->isDialogOpen($dialogId)) {
                return;
            }
        }

        Assert::assertFalse(
            $this->isDialogOpen($dialogId),
            'The dialog "' . $dialogCaption . '" (id "' . $dialogId . '") is still on screen after '
            . self::MAX_CLOSE_ATTEMPTS . ' close attempts. It blocks every widget behind it, so the '
            . 'rest of this page cannot be tested.'
        );
    }

    /**
     * Performs one close gesture on this dialog: close button, then back button, then ESC.
     *
     * WHY THE GESTURE IS SEPARATED FROM THE VERIFICATION: the ladder answers "how do I ask this
     * dialog to close", the caller answers "did it". Mixing the two is what produced the false
     * positive on the ESC path, where a successfully dispatched key event was mistaken for a
     * closed dialog.
     *
     * @param NodeElement $dialog
     * @param LogBookInterface $logbook
     * @return void
     */
    private function triggerClose(NodeElement $dialog, LogBookInterface $logbook): void
    {
        // Only look for the close button INSIDE this dialog. Searching the whole page would match
        // stale buttons of other UI5 navigation pages/dialogs (e.g. the many "back" buttons left
        // behind while navigating in and out).
        $closeBtn = $this->findVisibleButtonByCaption('ACTION.GENERIC.CLOSE', false, $dialog);
        if ($closeBtn !== null) {
            $closeBtn->click();
            $logbook->addLine('Pressing close button of the dialog');
            return;
        }

        // Some dialogs (e.g. UI5 navigation pages) do not expose a "Close" button on the current
        // page - they are dismissed via the header back button ("Zurück").
        $backBtn = $this->findDialogBackButton($dialog);
        if ($backBtn !== null) {
            $backBtn->click();
            $logbook->addLine('Pressing back button of the dialog');
            return;
        }

        // Last resort: most UI5 dialogs still close on ESC.
        $escPressed = $this->pressEscapeOnDialog($dialog);
        Assert::assertTrue(
            $escPressed,
            'Cannot close the dialog: neither close nor back button found and ESC key could not be dispatched'
        );
        $logbook->addLine('Pressing ESC to close the dialog');
    }

    /**
     * Tells whether the dialog with the given id is still open.
     *
     * WHY BY ID AND VIA JAVASCRIPT: after UI5 has removed the dialog, the Mink NodeElement held
     * by the caller points at an element that no longer exists and every method on it throws.
     * Resolving the id in the browser turns "gone" into a plain false instead of an exception.
     * The `sapMDialogOpen` class is checked as well, so a dialog that is still in the DOM but
     * already playing its closing animation counts as closed.
     *
     * @param string $dialogId
     * @return bool
     */
    private function isDialogOpen(string $dialogId): bool
    {
        // A dialog without an id cannot be tracked at all. Reporting it as closed keeps the
        // behaviour identical to the one before the verification was introduced.
        if ($dialogId === '') {
            return false;
        }

        $idJs = json_encode($dialogId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return (bool)$this->getFromJavascript(<<<JS
(function(){
    var el = document.getElementById($idJs);
    if (!el) return false;
    if (!el.classList.contains('sapMDialogOpen')) return false;
    var rect = el.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0;
})()
JS
        );
    }

    /**
     * Finds this dialog's own header back/navigation button ("Zurück").
     *
     * The UI5 dialog page renders its back button with the id "<dialogId>-navButton", so we can
     * target exactly this dialog's button and never one of the stale back buttons of other
     * navigation pages.
     *
     * @param NodeElement $dialog
     * @return NodeElement|null
     */
    private function findDialogBackButton(NodeElement $dialog): ?NodeElement
    {
        $dialogId = $dialog->getAttribute('id');
        if ($dialogId !== null && $dialogId !== '') {
            // Searched page-wide on purpose: the id is unique, so there is nothing to scope, and
            // the button lives in the dialog's header which is not always inside its content root.
            $navButton = $this->getSession()->getPage()->find(
                'xpath',
                sprintf('.//button[@id=%s]', $this->xpathLiteral($dialogId . '-navButton'))
            );
            if ($navButton !== null && $this->isElementVisibleInBrowser($navButton)) {
                return $navButton;
            }
        }

        // Fall back to a button captioned like the generic "back" action, but still scoped to this
        // dialog so we never grab another page's button.
        return $this->findVisibleButtonByCaption('ACTION.GOBACK.NAME', false, $dialog);
    }

    /**
     * Returns the title text shown in this dialog's header.
     *
     * WHY an own implementation: a sap.m.Dialog does not carry its title in `aria-label` - it points to
     * a separate <h1 id="<dialogId>-title"> via `aria-labelledby`. The inherited aria-label based
     * caption therefore always came back empty for dialogs, which made every log line and every step
     * that identifies a dialog by its title unusable.
     */
    public function getCaption(): string
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
    private function pressEscapeOnDialog(NodeElement $dialog): bool
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

        return (bool)$this->getSession()->evaluateScript($script);
    }
}