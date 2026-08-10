<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;
use axenox\BDT\Interfaces\TestResultInterface;
use exface\Core\Interfaces\Debug\LogBookInterface;
use Behat\Mink\Element\NodeElement;
use PHPUnit\Framework\Assert;

class UI5DialogNode extends UI5AbstractNode
{
    public function getCaption() : string
    {
        return strstr($this->getNodeElement()->getAttribute('aria-label'), "\n", true);;
    }

    public function capturesFocus() : bool
    {
        return true;
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
        } else {
            // Some dialogs (e.g. UI5 navigation pages) do not expose a "Close"
            // button on the current page - they are dismissed via the header
            // back button ("Zurück"). Fall back to this dialog's own back button.
            $backBtn = $this->findDialogBackButton($dialog);
            Assert::assertNotNull($backBtn, 'Neither close nor back button of the dialog can be found');
            $backBtn->click();
            $logbook->addLine('Pressing back button of the dialog');
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
}