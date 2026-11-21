<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\DatabaseFormatter\SubstepResult;
use axenox\BDT\Exceptions\FacadeNodeException;
use axenox\BDT\Interfaces\TestResultInterface;
use exface\Core\Interfaces\Debug\LogBookInterface;

/**
 * Checks Markian viewer output rather than merely finding the native UI5 host.
 *
 * A host can exist while rendering has failed or a Mermaid fence remains unrendered.
 * These checks make those failures visible in BDT results.
 */
class UI5MarkdownNode extends UI5AbstractNode
{
    /**
     * Returns a human-readable label for logs and failure messages.
     *
     * Why this override exists:
     * The Markdown host does not necessarily carry an `aria-label`, so the
     * inherited getCaption() would call strstr() on null and break its own string
     * return type. The Markdown widget itself usually has no caption either - what the
     * user sees as the title is the caption of the surrounding container (e.g. the
     * Panel header "Prozessdiagramm"). The nearest captioned ancestor is therefore used
     * as a fallback, limited to a few levels so that a distant page-level title does not
     * end up labelling the widget. The widget id is the last resort, because an empty
     * label makes failure messages unattributable.
     *
     * {@inheritDoc}
     * @see UI5AbstractNode::getCaption()
     */
    public function getCaption() : string
    {
        $widget = $this->getWidget();
        $caption = $widget->getCaption();
        if ($caption !== null && trim($caption) !== '') {
            return $caption;
        }
        $parent = $widget;
        for ($level = 0; $level < 3; $level++) {
            if (! $parent->hasParent()) {
                break;
            }
            $parent = $parent->getParent();
            $caption = $parent->getCaption();
            if ($caption !== null && trim($caption) !== '') {
                return $caption;
            }
        }
        return $widget->getId();
    }

    /**
     * Verifies that the markdown viewer rendered visible content.
     *
     * Why these particular assertions:
     * Merely finding the container proves nothing - the node element IS that
     * container, so such a check can never fail. What can actually go wrong is:
     * (a) Markian never built its contents area, (b) the block is parked in UI5's
     * preserve area during a rerender and is therefore invisible to the user, and
     * (c) a Mermaid diagram failed to render and Mermaid substituted its own error
     * SVG - the DOM then looks perfectly healthy while the user sees "Syntax error
     * in text". The run is wrapped in runAsSubstep() so a failure is recorded as a
     * substep with a screenshot, like in every other node.
     *
     * {@inheritDoc}
     * @see UI5AbstractNode::checkWorksAsExpected()
     */
    public function checkWorksAsExpected(LogBookInterface $logbook) : TestResultInterface
    {
        $title = 'Looking at Markdown "' . $this->getCaption() . '"';
        $logbook->addLine($title);

        return $this->runAsSubstep(
            function (SubstepResult $result) {
                $lb = $result->getLogbook();
                $node = $this->getNodeElement();

                $failure = $node->getAttribute('data-markian-error') ?? $node->find('css', '[data-markian-error]')?->getAttribute('data-markian-error');
                if ($failure !== null) {
                    throw new FacadeNodeException($this, 'Markdown rendering failed: ' . $failure);
                }
                $contents = $node->hasClass('markian-document')
                    ? $node
                    : $node->find('css', '.markian-viewer .markian-document');
                if ($contents === null) {
                    throw new FacadeNodeException($this, 'Markdown viewer did not render: no `.markian-document` inside "' . $node->getXpath() . '"');
                }

                // UI5 moves preserved HTML content into the hidden #sap-ui-preserve area
                // while the control is being rerendered. The element still exists by id,
                // but nothing is visible - warn instead of failing, because this is a
                // timing state rather than a defect.
                if ($node->find('xpath', 'ancestor::*[@id="sap-ui-preserve"]') !== null) {
                    $lb->addLine('**WARNING:** Markdown content is currently parked in UI5\'s preserve area and not visible on screen');
                }

                if (trim($contents->getText()) === '' && stripos($contents->getHtml(), '<svg') === false && $contents->find('css', 'img, table') === null) {
                    $lb->addLine('**WARNING:** Markdown viewer rendered, but its content is empty');
                }

                $this->checkMermaidDiagrams($contents, $lb);

                $lb->addLine('Markdown "' . $this->getCaption() . '" rendered');
                return $result;
            },
            $title,
            null,
            $logbook
        );
    }

    /**
     * Fails if a Mermaid code block did not turn into a diagram or produced an error diagram.
     *
     * Why this exists:
     * Mermaid does not throw when a diagram cannot be parsed - it replaces the diagram
     * with an SVG carrying aria-roledescription="error" and a red "Syntax error in text"
     * label. Without this check such a page counts as passed although its main content
     * is broken.
     *
     * Why the SVG is detected in the HTML instead of via find('css', 'svg'):
     * Mink translates CSS selectors into XPath and element-name matching in XPath is
     * namespace-sensitive. SVG elements live in the SVG namespace, so an unprefixed
     * `svg` step never matches them in an HTML document and every rendered diagram was
     * reported as missing. The error state is still detected via DOM queries, because
     * Mermaid always injects a <style> block containing `.error-icon`/`.error-text`
     * rules - searching for those strings in the HTML would match on healthy diagrams too.
     *
     * @param \Behat\Mink\Element\NodeElement $contents
     * @param LogBookInterface $logbook
     * @return void
     */
    protected function checkMermaidDiagrams(\Behat\Mink\Element\NodeElement $contents, LogBookInterface $logbook) : void
    {
        // Take the <pre> wrappers only - the nested <code> would double-count every diagram.
        $blocks = $contents->findAll('css', '.markian-rendered-fence[data-language="mermaid"], pre > code.language-mermaid');
        if (empty($blocks)) {
            return;
        }
        foreach ($blocks as $idx => $block) {
            if (stripos($block->getHtml(), '<svg') === false) {
                throw new FacadeNodeException($this, 'Mermaid diagram #' . ($idx + 1) . ' in Markdown "' . $this->getCaption() . '" was not rendered - the code block contains no SVG');
            }
            if ($block->find('css', '[aria-roledescription="error"], .error-text') !== null) {
                throw new FacadeNodeException($this, 'Mermaid diagram #' . ($idx + 1) . ' in Markdown "' . $this->getCaption() . '" rendered as an error diagram (syntax error in the diagram source)');
            }
        }
        $logbook->addLine(count($blocks) . ' Mermaid diagram(s) rendered without errors');
    }
}