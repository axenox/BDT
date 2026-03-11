<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use axenox\BDT\DataTypes\StepStatusDataType;
use Behat\Mink\Element\NodeElement;
use Behat\Mink\Session;
use axenox\BDT\Interfaces\FacadeNodeInterface;
use exface\Core\Actions\GoToPage;
use exface\Core\Interfaces\Actions\iShowDialog;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Interfaces\Widgets\iTriggerAction;
use PHPUnit\Framework\Assert;

class UI5ButtonNode extends UI5AbstractNode implements FacadeNodeInterface
{

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
        $this->getNodeElement()->click();
        $this->getBrowser()->getWaitManager()->waitForPendingOperations();

        // check exf-dialog-close class for action
        if ($this->isDialogCloseButton()) {
            $this->unfocusAfterClose();
        }
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
    public function checkWorksAsExpected(LogBookInterface $logbook) : int
    {
        /* @var $widget \exface\Core\Widgets\Tile */
        $widget = $this->getWidget();
        Assert::assertNotNull($widget, 'Tile widget not found for this node.');
        $action = $widget->getAction();

        $result = StepStatusDataType::PASSED;

        switch (true) {
            case $action instanceof GoToPage:
                $result = $this->checkActionGoToPage($action, $widget, $logbook);
                break;
            case $action instanceof iShowDialog:
                $result = $this->checkActionShowDialog($action, $widget, $logbook);
                break;
            // TODO more action validation here??
        }

        return $result;
    }
    
    protected function checkActionGoToPage(GoToPage $action, iTriggerAction $widget, LogBookInterface $logbook) : int
    {
        $expectedAlias = $action->getPage()->getAliasWithNamespace();

        // Substep should fail if the page cannot be loaded (shows an error) - otherwise the substep for
        // the click is passed, and we go on checking the page
        $this->runAsSubstep(
            function() use ($expectedAlias, $widget) {
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
            },
            'Clicking Tile ' . $this->getCaption(),
            'Pages',
            $logbook
        );

        $logbook->addLine('Clicking Tile [' . $this->getCaption() . '](' . $this->getSession()->getCurrentUrl() . ')');
        $logbook->addIndent(+1);

        try {
            $pageNode = new UI5PageNode($expectedAlias, $this->getSession(), $this->getBrowser());
            $result = $pageNode->checkWorksAsExpected($logbook);
        } catch (\Throwable $e) {
            $result = stepStatusDataType::FAILED;
            $logbook->addLine('**Failed** to check if page `' . $expectedAlias . '` works as expected - skipping to next widget. ' . $e->getMessage());
        }
        $this->getBrowser()->navigateToPreviousPage();
        $logbook->addLine('Pressing browser back button');
        $logbook->addIndent(-1);
        return $result;
    }



    protected function checkActionShowDialog(iShowDialog $action, iTriggerAction $widget, LogBookInterface $logbook) : int
    {
        $expectedId = $this->getElementIdFromWidget($action->getDialogWidget());

        // Substep should fail if the page cannot be loaded (shows an error) - otherwise the substep for
        // the click is passed, and we go on checking the page
        $dialogNode = null;
        $this->runAsSubstep(
            function() use ($expectedId, $widget, $dialogNode) {
                $this->click();
                $this->getBrowser()->getWaitManager()->waitForPendingOperations();
                $dialogNode = $this->getSession()->getPage()->findById($expectedId);
                Assert::assertNotNull(
                    $dialogNode,
                    'Cannot find dialog with id `' . $expectedId . '` after clicking tile `' . $widget->getCaption() . '`.'
                );
            },
            'Clicking ' . $this->getWidgetType() . ' ' . $this->getCaption(),
            'Pages',
            $logbook
        );

        $logbook->addLine('Clicking Tile [' . $this->getCaption() . '](' . $this->getSession()->getCurrentUrl() . ')');
        $logbook->addIndent(+1);

        try {
            if ($dialogNode) {
                $result = $dialogNode->checkWorksAsExpected($logbook);
                $closeBtn = $this->findVisibleButtonByCaption('WIDGET.DIALOG.CLOSE_BUTTON_CAPTION', false);
                $closeBtn->click();
            }
            else {
                $this->closeErrorDialog();
                $result = StepStatusDataType::FAILED;
            }
            $this->getBrowser()->getWaitManager()->waitForPendingOperations();
            $logbook->addLine('Pressing close button of the dialog');
            $logbook->addIndent(-1);
        } catch (\Throwable $e) {
            $result = stepStatusDataType::FAILED;
            $this->closeErrorDialog();
            $logbook->addLine('**Failed** to check if dialog `' . $expectedId . '` works as expected - skipping to next widget. ' . $e->getMessage());
        }
        return $result;
    }

    public function closeErrorDialog(): void
    {
        $this->getSession()->executeScript("
                var dialog = sap.ui.getCore().byId(
              document.querySelector('.sapMDialog').id
            );
            
            dialog.close();
        ");
    }
}