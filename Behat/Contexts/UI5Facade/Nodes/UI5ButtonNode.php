<?php

namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;
use axenox\BDT\DataTypes\StepStatusDataType;
use axenox\BDT\Interfaces\FacadeNodeInterface;
use axenox\BDT\Interfaces\TestResultInterface;
use exface\Core\Actions\GoToPage;
use exface\Core\Facades\ConsoleFacade\CliOutputPrinter;
use exface\Core\Interfaces\Actions\iShowDialog;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Interfaces\Widgets\iTriggerAction;
use exface\Core\Widgets\Tile;
use PHPUnit\Framework\Assert;
use Throwable;

class UI5ButtonNode extends UI5AbstractNode implements FacadeNodeInterface
{
    /**
     * Validates this button by following its action.
     *
     * WHY THE ACTION CACHE IS GONE: this method used to keep a process-local map of action alias plus
     * exported UXON and replay the stored result on a second encounter. It was never reset and never
     * role-aware, so two scenarios of one feature running under different roles shared its entries
     * and the second replayed the first one's verdict - a silent pass for a role that never opened
     * the dialog. Its discriminating power now lives in the coverage registry, where the action
     * configuration is part of the identity and the role set is too, and where lanes share what they
     * have covered instead of each keeping a private copy.
     */
    public function checkWorksAsExpected(LogBookInterface $logbook): TestResultInterface
    {
        /* @var $widget Tile */
        $widget = $this->getWidget();
        Assert::assertNotNull($widget, 'Tile widget not found for this node.');
        $this->checkCaptionMatchesWidget();

        $action = $widget->getAction();

        switch (true) {
            case $action instanceof GoToPage:
                $result = $this->checkActionGoToPage($action, $widget, $logbook);
                break;
            case $action instanceof iShowDialog:
                $result = $this->checkActionShowDialog($action, $widget, $logbook);
                break;
            case $action === null:
                $result = SubstepResult::createPassed($logbook);
                break;
            default:
                $reason = 'Action ' . $action->getAliasOfPrototype() . ' not yet supported';
                // No substep is logged here. WHY: every caller of this method already wraps it in a
                // substep of its own, and the skipped result returned below carries the reason up to
                // that row. Logging one here as well produced two rows for one button - the caller's
                // "Clicking X" and this one's "Clicking DataButton X" - with identical status and
                // reason. The duplication was masked for years by the action cache, which returned
                // before reaching this branch on every repeat encounter.
                $result = SubstepResult::createSkipped($reason, $logbook);
                $logbook->addLine('Skipping button ' . $this->getCaption() . ' because action ' . $action->getAliasOfPrototype() . ' not supported yet');
        }

        return $result;
    }

    public function getCaption(): string
    {
        // Take Button caption
        return trim($this->getNodeElement()->getText() ?? '');
    }

    /**
     * Validates that this tile navigates to its declared target page, then checks that page.
     *
     * WHY NO COVERAGE IDENTITY HERE: this substep is the click, not the page. UI5PageNode records
     * the target screen itself, keyed on the page root, so the same page reached from several tiles
     * still produces one record. Passing the target's identity here as well made both substeps
     * resolve to the same registry row: the inner insert won, the outer one hit the uniqueness
     * constraint and was discarded, and the navigation assertion's own outcome was lost with it.
     */
    protected function checkActionGoToPage(GoToPage $action, iTriggerAction $widget, LogBookInterface $logbook): SubstepResult
    {
        $expectedAlias = $action->getPage()->getAliasWithNamespace();

        $urlBeforeClick = $this->getSession()->getCurrentUrl();
        // Substep should fail if the page cannot be loaded (shows an error) - otherwise the substep for
        // the click is passed, and we go on checking the page
        $result = self::runNested(function () use ($logbook, $widget, $expectedAlias, $urlBeforeClick) {
            return $this->runAsSubstep(
                function (SubstepResult $result) use ($expectedAlias, $widget, $logbook) {
                    $logbook->addLine('Clicking ' . $this->getWidgetType() . ' [' . $this->getCaption() . '](' . $this->getSession()->getCurrentUrl() . ')');
                    $logbook->addIndent(+1);

                    $this->click();
                    $realAlias = $this->getBrowser()->getPageCurrent()->getAliasWithNamespace();
                    Assert::assertSame(
                        $expectedAlias,
                        $realAlias,
                        sprintf(
                            'Tile "%s" navigated to `%s` but expected `%s`.',
                            $widget->getCaption(),
                            $realAlias,
                            $expectedAlias
                        )
                    );

                    try {
                        $pageNode = new UI5PageNode($expectedAlias, $this->getSession(), $this->getBrowser());
                        $result = $pageNode->checkWorksAsExpected($logbook);
                    } catch (Throwable $e) {
                        $result = SubstepResult::createFailed($e, $logbook);
                        $logbook->addLine('**Failed** to check if page `' . $expectedAlias . '` works as expected - skipping to next widget. ' . CliOutputPrinter::printExceptionMessage($e));
                    }
                    $this->getBrowser()->navigateToPreviousPage();
                    $logbook->addLine('Pressing browser back button');
                    $logbook->addIndent(-1);

                    return $result;
                },
                $this->buildMessageClicking(false),
                static::CATEGORY_BUTTONS,
                $logbook,
                function () use ($urlBeforeClick) {
                    // If the click caused a full page navigation, we must go back.
                    // If only a popup/error dialog appeared (URL unchanged), navigating
                    // back would land on the wrong page — dismiss is already handled
                    // by runAsSubstep's catch block, so nothing extra is needed here.
                    $urlAfterError = $this->getSession()->getCurrentUrl();
                    if ($urlAfterError !== $urlBeforeClick) {
                        $this->getBrowser()->navigateToPreviousPage();
                    }
                }
            );
        });
        return $result;
    }

    public function click(): void
    {
        // check exf-dialog-close class for action
        if ($this->isDialogCloseButton()) {
            $this->unfocusAfterClose();
        }

        $this->getNodeElement()->click();
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);
    }

    /**
     * Check if it has dialog close button class
     *
     * @return bool
     */
    public function isDialogCloseButton(): bool
    {
        return $this->getNodeElement()->hasClass('exf-dialog-close');
    }

    private function unfocusAfterClose(): void
    {
        // Call unfocus method on Browser
        $this->getSession()->evaluateScript('
            if (window.unfocusDialog) {
                window.unfocusDialog();
            }
        ');
    }

    protected function buildMessageClicking(bool $markdown): string
    {
        return 'Clicking ' . $this->getWidgetType() . ' "' . $this->getCaption() . '"';
    }

    /**
     * Opens and validates a dialog as one screen-level operation.
     *
     * WHY THE DIALOG OWNS COVERAGE: its slug is derived from the opening action and stays the same
     * across host pages, so the same dialog is recorded once instead of once per trigger location.
     *
     * WHY THE SCREEN CATEGORY: this substep validates the dialog as a whole and carries a
     * whole-screen identity, which is a different piece of work from pressing the buttons inside it.
     * Recording it under the button category would put two unrelated kinds of work in one bucket and
     * make the registry unable to tell them apart.
     */
    protected function checkActionShowDialog(iShowDialog $action, iTriggerAction $widget, LogBookInterface $logbook): SubstepResult
    {
        // Do not follow this action any deeper. WHY before the click: opening the dialog and only then
        // refusing to check it would leave a dialog on screen that nobody closes, and the next widget
        // of the surrounding container would be searched inside a stale DOM.
        if (self::isNestingLimitReached()) {
            $logbook->addLine('Skipping dialog of button `' . $this->getCaption() . '` - nesting limit of ' . self::MAX_NESTING_DEPTH . ' reached');
            return SubstepResult::createSkipped('Nesting limit of ' . self::MAX_NESTING_DEPTH . ' reached', $logbook);
        }

        $dialogWidget = $action->getDialogWidget();
        $expectedId = $this->getBrowser()->getElementIdFromWidget($dialogWidget);
        $coverageIdentity = $this->buildWholeScreenSubstepCoverageIdentity($dialogWidget);

        // Substep should fail if the page cannot be loaded (shows an error) - otherwise the substep for
        // the click is passed, and we go on checking the page

        $attempt = 0;
        $logbook->addLine('Clicking Button [' . $this->getCaption() . '](' . $this->getSession()->getCurrentUrl() . ')');
        do {
            $this->click();
            $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);
            $dialogNodeElement = $this->getSession()->getPage()->findById($expectedId);
            $attempt++;
        } while ($attempt < 3 && $dialogNodeElement === null);

        Assert::assertNotNull(
            $dialogNodeElement,
            'Cannot find dialog with id `' . $expectedId . '` after clicking button `' . $widget->getCaption() . '`.'
        );

        $logbook->addIndent(+1);

        try {
            $result = self::runNested(function () use ($logbook, $widget, $dialogNodeElement, $coverageIdentity) {
                return $this->runAsSubstep(
                    function (SubstepResult $result) use ($logbook, $widget, $dialogNodeElement) {
                        $dialogNode = UI5FacadeNodeFactory::createFromNodeElement($dialogNodeElement, $this->getSession(), $this->getBrowser());
                        return $dialogNode->checkWorksAsExpected($logbook);
                    },
                    'Seeing ' . $this->getBrowser()->getNodeWidgetType($dialogNodeElement),
                    static::CATEGORY_SCREENS,
                    $logbook,
                    null,
                    $coverageIdentity
                );
            });
        } catch (Throwable $e) {
            $result = SubstepResult::createFailed($e, $logbook);
            $logbook->addLine('**Failed** to check if dialog `' . $expectedId . '` works as expected - skipping to next widget. ' . CliOutputPrinter::printExceptionMessage($e));
        } finally {
            $this->closeErrorDialog();
        }
        return $result;
    }

    public function closeErrorDialog(): void
    {
        $this->getSession()->executeScript("
            var dialogEl = document.querySelector('.sapMDialog');
            if (dialogEl) {
                var dialog = sap.ui.getCore().byId(dialogEl.id);
                if (dialog) {
                    dialog.close();
                }
            }
        ");
    }

    public function checkDisabled(): bool
    {
        return $this->getNodeElement()->hasAttribute('disabled')
            || $this->getNodeElement()->getAttribute('aria-disabled') === 'true' 
            || $this->getNodeElement()->hasClass('sapMBtnDisabled');
    }
}