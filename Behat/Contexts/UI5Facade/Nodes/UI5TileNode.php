<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

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

}
