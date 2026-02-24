<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

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
    public function itWorksAsExpected(LogBookInterface $logbook) :void
    {
        /* @var $widget \exface\Core\Widgets\Tile */
        $widget = $this->getWidget();
        Assert::assertNotNull($widget, 'Tile widget not found for this node.');
        $action = $widget->getAction();

        switch (true) {
            case $action instanceof GoToPage:
                $expectedAlias = $action->getPage()->getAliasWithNamespace();
                // click on the tile
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
                $logbook->addLine('Clicking Tile [' . $this->getCaption() . '](' . $this->getSession()->getCurrentUrl() . ')');
                $logbook->addIndent(+1);
                
                try {
                    $this->getBrowser()->verifyCurrentPageWorksAsExpected($logbook);
                } catch (\Throwable $e) {
                    $logbook->addLine('**Failed** to check if page `' . $realAlias . '` works as expected - aborting and continuing. ' . $e->getMessage());
                }
                $this->getBrowser()->navigateToPreviousPage();
                $logbook->addLine('Pressing browser back button');
                $logbook->addIndent(-1);
                break;
            // TODO more action validation here??
        }
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