<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use exface\Core\Interfaces\Model\UiPageInterface;
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
     * @param UiPageInterface $page
     * @return void
     */
    public function itWorksAsExpected(UiPageInterface $page) :void
    {
        $widget = $this->getWidget();
        Assert::isInstanceOf(Tile::class , $widget, 'Tile widget not found for this node.');
        if ($widget->getAction()->getAlias() === 'GoToPage') {
            $expectedAlias = $widget->getActionUxon()->getProperty('page_alias')->toString();
            //click on the tile
            $this->getNodeElement()->click();
            $directedAlias = $this->getBrowser()->syncAfterUiNavigation();

            Assert::assertSame(
                $expectedAlias,
                $directedAlias,
                sprintf(
                    'Tile "%s" navigated to "%s" but expected "%s".',
                    $widget->getCaption(),
                    $directedAlias,
                    $expectedAlias
                )
            );
            $this->getBrowser()->verifyCurrentPageWorksAsExpected();
            $this->getBrowser()->navigateToPreviousPage();
        }
    }    
}