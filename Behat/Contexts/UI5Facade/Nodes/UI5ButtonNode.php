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
    private static array $testedActions = [];

    /**
     * @param LogBookInterface $logbook
     * @return int
     */
    public function checkWorksAsExpected(LogBookInterface $logbook): TestResultInterface
    {
        /* @var $widget Tile */
        $widget = $this->getWidget();
        Assert::assertNotNull($widget, 'Tile widget not found for this node.');
        $this->checkCaptionMatchesWidget();

        $action = $widget->getAction();

        // Check if the very same action was already tested
        if ($action !== null) {
            // TODO also check if the input data is based on the same object
            $actionKey = $action->exportUxonObject()->toJson();
            $testedVariants = static::$testedActions[$action->getAliasWithNamespace()] ?? null;
            if (is_array($testedVariants) && null !== ($result = $testedVariants[$actionKey] ?? null)) {
                $logbook->addLine('Skipping ' . $this->getWidgetType() . ' `' . $this->getCaption() . '` because action `' . $action->getAliasOfPrototype() . '` with the same input data was already tested.');
                return SubstepResult::createFromPrevious($result);
            }
        }

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
                // Make the skip visible in the report, not just in the logbook. WHY: a button whose
                // action we do not follow is not a passed button - without a row of its own the
                // report silently suggests full coverage of the toolbar.
                $this->logSubstep(
                    'Clicking ' . $this->getWidgetType() . ' "' . $this->getCaption() . '"',
                    StepStatusDataType::SKIPPED,
                    $reason,
                    self::CATEGORY_BUTTONS
                );
                $result = SubstepResult::createSkipped($reason, $logbook);
                $logbook->addLine('Skipping button ' . $this->getCaption() . ' because action ' . $action->getAliasOfPrototype() . ' not supported yet');
            // TODO more action validation here??
        }

        if ($action !== null) {
            static::$testedActions[$action->getAliasWithNamespace()][$actionKey] = $result;
        }

        return $result;
    }

    public function getCaption(): string
    {
        // Take Button caption
        return trim($this->getNodeElement()->getText() ?? '');
    }

    protected function checkActionGoToPage(GoToPage $action, iTriggerAction $widget, LogBookInterface $logbook): SubstepResult
    {
        $expectedAlias = $action->getPage()->getAliasWithNamespace();

        $urlBeforeClick = $this->getSession()->getCurrentUrl();
        // Substep should fail if the page cannot be loaded (shows an error) - otherwise the substep for
        // the click is passed, and we go on checking the page
        $result = self::runNested(function () use ($logbook, $widget, $expectedAlias, $urlBeforeClick) {
            return $this->runAsSubstep(
                function (SubstepResult $result) use ($expectedAlias, $widget, $logbook, &$navigated) {
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
                'Pages',
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

    protected function checkActionShowDialog(iShowDialog $action, iTriggerAction $widget, LogBookInterface $logbook): SubstepResult
    {
        // Do not follow this action any deeper. WHY before the click: opening the dialog and only then
        // refusing to check it would leave a dialog on screen that nobody closes, and the next widget
        // of the surrounding container would be searched inside a stale DOM.
        if (self::isNestingLimitReached()) {
            $logbook->addLine('Skipping dialog of button `' . $this->getCaption() . '` - nesting limit of ' . self::MAX_NESTING_DEPTH . ' reached');
            return SubstepResult::createSkipped('Nesting limit of ' . self::MAX_NESTING_DEPTH . ' reached', $logbook);
        }

        $expectedId = $this->getBrowser()->getElementIdFromWidget($action->getDialogWidget());

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
            $result = self::runNested(function () use ($logbook, $widget, $dialogNodeElement) {
                return $this->runAsSubstep(
                    function (SubstepResult $result) use ($logbook, $widget, $dialogNodeElement) {
                        $dialogNode = UI5FacadeNodeFactory::createFromNodeElement($dialogNodeElement, $this->getSession(), $this->getBrowser());
                        return $dialogNode->checkWorksAsExpected($logbook);
                    },
                    'Seeing ' . $this->getBrowser()->getNodeWidgetType($dialogNodeElement),
                    'Dialogs',
                    $logbook
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