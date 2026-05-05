<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;
use axenox\BDT\Interfaces\TestResultInterface;
use Behat\Mink\Element\NodeElement;
use Behat\Mink\Session;
use axenox\BDT\Interfaces\FacadeNodeInterface;
use exface\Core\Actions\GoToPage;
use exface\Core\Facades\ConsoleFacade\CliOutputPrinter;
use exface\Core\Interfaces\Actions\iShowDialog;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Interfaces\Widgets\iTriggerAction;
use PHPUnit\Framework\Assert;

class UI5ButtonNode extends UI5AbstractNode implements FacadeNodeInterface
{
    private static array $testedActions = [];
    
    /**
     * Constructor
     *
     * @param NodeElement $nodeElement
     * @param Session $session
     * @param UI5Browser $browser
     */
    public function __construct(NodeElement $nodeElement, Session $session, UI5Browser $browser)
    {
        // Call upper level constructor
        parent::__construct($nodeElement, $session, $browser);
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

    public function getCaption(): string
    {
        // Take Button caption
        return trim($this->getNodeElement()->getText() ?? '');
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

    public function getWidget() : WidgetInterface
    {
        $elementId = $this->getNodeElement()->getAttribute('id');
        return $this->getWidgetFromElementId($elementId);
    }

    /**
     * @param LogBookInterface $logbook
     * @return int
     */
    public function checkWorksAsExpected(LogBookInterface $logbook) : TestResultInterface
    {
        /* @var $widget \exface\Core\Widgets\Tile */
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
                $result = SubstepResult::createSkipped('Action ' . $action->getAliasOfPrototype() . ' not yet supported', $logbook);
                $logbook->addLine('Skipping button ' . $this->getCaption() . ' because action ' . $action->getAliasOfPrototype() . ' not supported yet');
            // TODO more action validation here??
        }
        
        static::$testedActions[$action->getAliasWithNamespace()][$actionKey] = $result;

        return $result;
    }
    
    protected function checkActionGoToPage(GoToPage $action, iTriggerAction $widget, LogBookInterface $logbook) : SubstepResult
    {
        $expectedAlias = $action->getPage()->getAliasWithNamespace();

        $navigated = false;
        // Substep should fail if the page cannot be loaded (shows an error) - otherwise the substep for
        // the click is passed, and we go on checking the page
        $result = $this->runAsSubstep(
            function(SubstepResult $result) use ($expectedAlias, $widget, $logbook, &$navigated) {
                $logbook->addLine('Clicking ' . $this->getWidgetType() . ' [' . $this->getCaption() . '](' . $this->getSession()->getCurrentUrl() . ')');
                $logbook->addIndent(+1);
                
                $this->click();
                $navigated = true;
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
                } catch (\Throwable $e) {
                    $result = substepResult::createFailed($e, $logbook);
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
            function () use (&$navigated) {
                if ($navigated) {
                    $this->getBrowser()->navigateToPreviousPage();
                }
            }
        );
        return $result;
    }

    protected function buildMessageClicking(bool $markdown) : string
    {
        return 'Clicking ' . $this->getWidgetType() . ' "' . $this->getCaption() . '"';
    }

    protected function checkActionShowDialog(iShowDialog $action, iTriggerAction $widget, LogBookInterface $logbook) : SubstepResult
    {
        $expectedId = $this->getElementIdFromWidget($action->getDialogWidget());

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
            $result = $this->runAsSubstep(
                function(SubstepResult $result) use ($logbook, $widget, $dialogNodeElement) {
                    $dialogNode = UI5FacadeNodeFactory::createFromNodeElement($dialogNodeElement, $this->getSession(), $this->getBrowser());
                    return $dialogNode->checkWorksAsExpected($logbook);
                    },
                    'Seeing ' . $this->getBrowser()->getNodeWidgetType($dialogNodeElement),
                    'Dialogs',
                $logbook
            );
        } 
        catch (\Throwable $e) {
            $result = SubstepResult::createFailed($e, $logbook);
            $logbook->addLine('**Failed** to check if dialog `' . $expectedId . '` works as expected - skipping to next widget. ' . CliOutputPrinter::printExceptionMessage($e));
        } 
        finally {
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
        return $this->getNodeElement()->hasAttribute('disabled');
    }
}