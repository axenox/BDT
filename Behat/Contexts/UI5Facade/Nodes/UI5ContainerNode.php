<?php

namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;
use axenox\BDT\DataTypes\StepStatusDataType;
use axenox\BDT\Exceptions\ChromeHangException;
use axenox\BDT\Interfaces\TestResultInterface;
use exface\Core\Facades\ConsoleFacade\CliOutputPrinter;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Interfaces\Widgets\iContainOtherWidgets;
use exface\Core\Widgets\Container;

/**
 * @method Container getWidget()
 */
class UI5ContainerNode extends UI5AbstractNode
{
    /**
     * Validates every visible child widget of this container.
     *
     * Iterates the widget model's child list and calls checkChildWorksAsExpected()
     * for each non-hidden child. Hidden widgets are skipped because they cannot
     * be interacted with and their validation would always fail on DOM lookup.
     *
     * Chrome-hang recovery:
     * If checkChildWorksAsExpected() throws a ChromeHangException (Chrome's CDP
     * connection was lost, typically after many GoToPage navigations in a long
     * tile run), the method:
     *   1. Calls UI5Browser::recoverChrome() with the child widget's caption,
     *      which triggers a Chrome restart, re-login, and direct navigation back
     *      to this container page.
     *   2. Retries the same child exactly once.
     *   3. Re-throws if the retry also hangs, stopping the run for this container.
     *
     * Non-ChromeHangException failures from individual children are recorded in
     * the logbook but do not stop iteration — all siblings are still tested.
     *
     * {@inheritDoc}
     */
    public function checkWorksAsExpected(LogBookInterface $logbook): TestResultInterface
    {
        $containerAlias = $this->getWidget()->getPage()->getAliasWithNamespace();
        $childWidgets = $this->getWidget()->getWidgets();
        $failed = false;
        foreach ($childWidgets as $childWidget) {
            if ($childWidget->isHidden()) {
                continue;
            }
            // Stop the container check as soon as the browser is no longer on the container's own page.
            // WHY: a tile run navigates away for every child and is expected to return afterwards. If one
            // of those navigations failed (e.g. "Cannot open path ... after 2 attempts"), the container
            // element is stale and every remaining child lookup fails instantly - turning one real
            // navigation error into a bogus "Cannot find DOM element" row per sibling and burying the
            // actual cause. Recording the lost page once keeps the failure attributable to its origin.
            $pageCurrent = $this->getBrowser()->getPageCurrent()->getAliasWithNamespace();
            if ($pageCurrent !== $containerAlias) {
                $this->logSubstep(
                    'Checking children of ' . $this->getWidget()->getWidgetType(),
                    StepStatusDataType::FAILED,
                    'Aborted: expected to be on page "' . $containerAlias . '" but the browser is on "'
                    . $pageCurrent . '". A preceding navigation did not return to the container page.'
                );
                $failed = true;
                break;
            }
            $attempt = 0;
            while ($attempt < 2) {
                try {
                    $childResult = $this->checkChildWorksAsExpected($childWidget, $logbook);
                    if ($childResult->isFailed()) {
                        $failed = true;
                    }
                    break; // child validated — move to the next sibling

                } catch (ChromeHangException $e) {
                    $attempt++;
                    if ($attempt >= 2) {
                        // Chrome hung even after a fresh restart on this child.
                        throw $e;
                    }
                    $caption = $childWidget->getCaption() ?: $childWidget->getId();
                    $logbook->addLine('Chrome hang on child "' . $caption . '" — attempting recovery (attempt ' . $attempt . ')');
                    // Restart Chrome, re-login, and navigate directly back to
                    // this container page so the retry starts from a clean state.
                    $this->getBrowser()->recoverChrome($containerAlias);
                }
                 catch (\Throwable $e) {
                    // Record the failure on the child and keep testing its siblings.
                    // WHY: a single widget that cannot even be looked at - e.g. a `Markdown`, whose
                    // facade element needs a controller that does not exist outside a rendering
                    // request - used to abort the whole container. One broken widget then hid every
                    // other widget of the same dialog from the report. The error is not swallowed:
                    // it is written to the logbook and shown as a FAILED substep of its own.
                    $caption = $childWidget->getCaption() ?: $childWidget->getId();
                    $this->logSubstep(
                        'Looking at ' . $childWidget->getWidgetType() . ' "' . $caption . '"',
                        StepStatusDataType::FAILED,
                        CliOutputPrinter::printExceptionMessage($e)
                    );
                    $logbook->addLine('**Failed** to check ' . $childWidget->getWidgetType() . ' `' . $caption . '`: ' . CliOutputPrinter::printExceptionMessage($e));
                    $failed = true;
                    break; // this child is done - move on to the next sibling
                }
            }
        }
        return $failed ? SubstepResult::createFailed(null, $logbook) : SubstepResult::createPassed($logbook);
    }

    protected function checkChildWorksAsExpected(WidgetInterface $childWidget, LogBookInterface $logbook): TestResultInterface
    {
        $childElementId = $this->getBrowser()->getElementIdFromWidget($childWidget);
        // Respect UI5's runtime visibility decision before doing anything else.
        // WHY: a child widget can be visible in the server-side model (so isHidden()
        // returns false and the caller does not skip it) yet be hidden by UI5 at render
        // time because it had no data to show - e.g. empty tile groups like "Lieferscheine"
        // or "Materialien". UI5 does not render such a control with its normal id; it emits
        // an invisible placeholder <span id="sap-ui-invisible-{controlId}"
        // class="sapUiHiddenPlaceholder">. The normal "#{controlId}" lookup then finds
        // nothing and a genuinely-hidden control is misreported as a missing DOM element.
        // Detecting the placeholder lets us skip it exactly like a model-hidden child.
        if ($this->isHiddenByUI5Placeholder($childElementId)) {
            $logbook->addLine('Skipping ' . $childWidget->getWidgetType()
                . ' with id "' . $childElementId . '" — hidden by UI5 at runtime (invisible placeholder present)');
            return SubstepResult::createPassed($logbook);
        }
        $childWidgetElement = $this->getNodeElement()->find('css', '#' . $childElementId);

        if ($childWidgetElement === null) {
            // Separate "rendered nothing" from "not there at all" before blaming the widget.
            if ($this->isRenderedEmptyByUI5($childElementId)) {
                // A container is never skipped just because it has no element of its own.
                // WHY: UI5 gives some containers a DOM root under a derived id - a
                // sap.ui.layout.form.FormContainer renders as "<controlId>---Panel" - so getDomRef()
                // is null although the container and all of its children are on screen. Skipping it
                // would silently drop every widget inside, which is exactly the coverage this check
                // exists to provide. The children carry their own ids, so searching them in this
                // node's scope finds them.
                if ($childWidget instanceof iContainOtherWidgets) {
                    // Only descend if at least one child is actually on screen.
                    // WHY: a container without an element of its own can mean two very different
                    // things. Either UI5 gave it a derived DOM id (a FormContainer renders as
                    // "<controlId>---Panel") and everything inside is visible - then descending is the
                    // whole point. Or the container is not rendered at all because the current data
                    // does not show it, and then every child is missing too. Descending in the second
                    // case turns one honest "not on screen" into a row per child and buries the report.
                    $anyChildRendered = false;
                    foreach ($childWidget->getWidgets() as $grandChild) {
                        if ($grandChild->isHidden()) {
                            continue;
                        }
                        $grandChildId = $this->getBrowser()->getElementIdFromWidget($grandChild);
                        if ($grandChildId !== '' && $this->getNodeElement()->find('css', '#' . $grandChildId) !== null) {
                            $anyChildRendered = true;
                            break;
                        }
                    }
                    if ($anyChildRendered === true) {
                        $logbook->addLine($childWidget->getWidgetType() . ' `' . $childWidget->getId()
                            . '` has no DOM element of its own - checking its children in the scope of '
                            . $this->getWidget()->getWidgetType());
                        $node = UI5FacadeNodeFactory::createFromWidgetType(
                            $childWidget->getWidgetType(),
                            $this->getNodeElement(),
                            $this->getSession(),
                            $this->getBrowser(),
                            $childWidget
                        );
                        return $node->checkWorksAsExpected($logbook);
                    }
                }
                $logbook->addLine('Skipping ' . $childWidget->getWidgetType() . ' with id "' . $childElementId
                    . '" — not on screen: UI5 created the control but rendered nothing (e.g. a message without text)');
                return $this->logSubstep(
                    'Looking at ' . $childWidget->getWidgetType() . ' "' . ($childWidget->getCaption() ?: $childWidget->getId()) . '"',
                    StepStatusDataType::SKIPPED,
                    'Not on screen: UI5 created the control but rendered nothing - it or its parent is not shown for the current data',
                    null
                )->getResult();
            }
            
            // Ask the node itself whether it expects an element of its own before waiting for one.
            // WHY here and not earlier: for the vast majority of widgets the first find() succeeds and
            // nothing extra is needed. Only when it fails is it worth asking, and asking before the
            // wait avoids spending a full wait cycle on a widget that will never have an element -
            // e.g. a `Tabs` inside a maximized `Dialog`, which the facade renders as sections of the
            // dialog's ObjectPageLayout without any control for the `Tabs` widget itself.
            $node = UI5FacadeNodeFactory::createFromWidgetType(
                $childWidget->getWidgetType(),
                $this->getNodeElement(),
                $this->getSession(),
                $this->getBrowser(),
                $childWidget
            );
            if ($node->usesOwnDomElement() === false) {
                return $node->checkWorksAsExpected($logbook);
            }

            // Give an asynchronously rendered child a chance to appear before declaring it missing.
            // WHY: a single find() returns immediately, so a child UI5 has not rendered yet is
            // indistinguishable from one that does not exist at all. These failures were being recorded
            // within hundredths of a millisecond, which is far too fast to be a trustworthy verdict.
            $this->getBrowser()->getWaitManager()->waitForPendingOperations(false, true, true);
            $childWidgetElement = $this->getNodeElement()->find('css', '#' . $childElementId);
        }

        if ($childWidgetElement === null) {
            $caption = $childWidget->getCaption();
            if (!$caption) {
                $caption = 'with id "' . $childWidget->getId() . '"';
            } else {
                $caption = '"' . $caption . '"';
            }
            // Name the element and the page in the failure message. WHY: "Cannot find DOM element" on
            // its own cannot be acted upon - it does not say what was searched for, where, or whether
            // the surrounding container was still the expected one.
            $resultEvent = $this->logSubstep(
                'Looking at ' . $childWidget->getWidgetType() . ' ' . $caption,
                StepStatusDataType::FAILED,
                'Cannot find DOM element with id "' . $childElementId . '" inside '
                . $this->getWidget()->getWidgetType() . ' on page "'
                . $this->getBrowser()->getPageCurrent()->getAliasWithNamespace() . '"'
            );
            $childResult = $resultEvent->getResult();
        } else {
            $node = UI5FacadeNodeFactory::createFromWidgetType($childWidget->getWidgetType(), $childWidgetElement, $this->getSession(), $this->getBrowser(), $childWidget);
            $childResult = $node->checkWorksAsExpected($logbook);
        }
        return $childResult;
    }

    /**
     * Tells whether UI5 knows a control with the given id although it has no DOM element.
     *
     * WHY this is needed next to isHiddenByUI5Placeholder(): UI5 marks a control with visible=false by
     * replacing it with <span id="sap-ui-invisible-{controlId}">, which the placeholder check finds. A
     * control whose renderer decides to write nothing at all - a sap.m.MessageStrip without text is
     * the known case - leaves no placeholder and no element either. From the DOM alone that is
     * indistinguishable from a control that was never created, i.e. from a real defect. The UI5
     * control registry can tell the two apart: it still holds the control in the first case and knows
     * nothing in the second.
     *
     * @param string $childElementId
     * @return bool
     */
    private function isRenderedEmptyByUI5(string $childElementId) : bool
    {
        $idJs = json_encode($childElementId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return (bool) $this->getFromJavascript(<<<JS
(function(sId){
    var oCtrl = sap.ui.getCore().byId(sId);
    return oCtrl !== undefined && oCtrl !== null && oCtrl.getDomRef() === null;
})($idJs)
JS
        );
    }

    /**
     * Tells whether UI5 has rendered the given control as an invisible placeholder.
     *
     * WHY: UI5 hides a control with visible=false not by styling its real DOM but by
     * replacing it with <span id="sap-ui-invisible-{controlId}"
     * class="sapUiHiddenPlaceholder">. Such controls are intentionally off screen and
     * must be treated as hidden even when the server-side widget model still reports
     * them as visible (data-driven visibility). Without this the framework searches for
     * the real id, finds nothing, and raises a false "Cannot find DOM element" failure.
     *
     * @param string $childElementId
     * @return bool
     */
    private function isHiddenByUI5Placeholder(string $childElementId): bool
    {
        $placeholder = $this->getNodeElement()->find('css', '#sap-ui-invisible-' . $childElementId);
        if ($placeholder === null) {
            return false;
        }
        return $placeholder->hasClass('sapUiHiddenPlaceholder');
    }
}