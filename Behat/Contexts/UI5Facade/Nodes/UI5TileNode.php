<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\DataTypes\StepStatusDataType;
use exface\Core\Actions\GoToPage;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Widgets\Tile;
use PHPUnit\Framework\Assert;

class UI5TileNode extends UI5AbstractNode
{
    public function getCaption(): string
    {
        $s = strstr($this->getNodeElement()->getAttribute('aria-label'), "\n", true);

        // Decode HTML entities (&amp;, &quot;, etc.)
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Convert non‑breaking space (\u00A0) to a normal space
        $s = str_replace("\xc2\xa0", ' ', $s);
        // Collapse any sequence of whitespace into a single space
        $s = preg_replace('/\s+/', ' ', $s);
        // Trim leading and trailing whitespace
        return trim($s);
    }

    /**
     * @return Tile
     */
    public function getWidget() : WidgetInterface
    {
        $elementId = $this->getNodeElement()->getAttribute('id');
        return $this->getWidgetFromElementId($elementId);
    }

    /**
     * @param LogBookInterface $logbook
     * @return void
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
                    $pageNode->checkWorksAsExpected($logbook);
                } catch (\Throwable $e) {
                    $result = stepStatusDataType::FAILED;
                    $logbook->addLine('**Failed** to check if page `' . $expectedAlias . '` works as expected - skipping to next widget. ' . $e->getMessage());
                }
                $this->getBrowser()->navigateToPreviousPage();
                $logbook->addLine('Pressing browser back button');
                $logbook->addIndent(-1);
                break;
            // TODO more action validation here??
        }
        
        return $result;
    }

    /**
     * @return void
     */
    public function click() : void
    {
        $this->getNodeElement()->click();
        $this->getBrowser()->getWaitManager()->waitForPendingOperations();
    }
}