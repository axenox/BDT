<?php

namespace axenox\BDT\Behat\Events;

use exface\Core\Exceptions\LogicException;
use exface\Core\Interfaces\Model\UiPageInterface;
use exface\Core\Interfaces\Model\UiScreenInterface;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\Widgets\Dialog;
use exface\Core\Widgets\Popup;
use exface\Core\Widgets\AbstractWidget;

/**
 * Immutable identity of one works-as-expected operation at dispatch time.
 *
 * WHY A VALUE OBJECT: substeps may close dialogs or replace DOM nodes before their result event is
 * handled. Capturing model-derived scalar values up front keeps the finished event answerable without
 * retaining a mutable widget, node, or ambient current-node stack.
 */
final class SubstepCoverageIdentity
{
    /**
     * Reserved identity value for work that covers a screen rather than one of its widgets.
     *
     * WHY A TOKEN: registry identity columns are non-nullable, while a whole-screen operation has
     * neither a host widget nor an element. The double-underscore form follows the existing
     * `__no_roles__` sentinel convention and keeps this synthetic value distinct from model ids.
     */
    public const WHOLE_SCREEN_TOKEN = '__whole_screen__';

    private string $screenSlug;
    private string $screenKind;
    private string $widgetId;
    private string $objectUid;
    private ?array $roles;
    private string $element;
    private string $actionFingerprint;
    private string $startedOn;

    /**
     * Captures all lookup fields while the originating model objects are still valid.
     *
     * WHY THE FINGERPRINT IS CREATED HERE: callers provide the canonical action UXON, while this
     * class owns its stable storage representation. Non-action work deliberately hashes the empty
     * string so every identity still has the registry's required 64-character value.
     *
     * @param WidgetInterface $widget Covered widget that owns the filter or button.
     * @param string $element Stable id of the filter or button within the covered widget.
     * @param string|null $actionUxon Canonical exported action UXON, or null for non-action work.
     * @param string[]|null $roles Roles reported by the browser, or null before login established them.
     * @param bool $wholeScreen Whether this identity covers the screen itself instead of a widget element.
     */
    public function __construct(
        WidgetInterface $widget,
        string $element,
        ?string $actionUxon,
        ?array $roles,
        bool $wholeScreen = false
    )
    {
        if (! $widget instanceof AbstractWidget) {
            throw new LogicException('Coverage identity requires a model-backed widget.');
        }
        // findUiContainer() starts at the parent, so a dialog or popup must identify itself here.
        $screen = $widget instanceof UiScreenInterface ? $widget : $widget->findUiContainer();
        if ($screen instanceof UiPageInterface) {
            $screenKind = 'page';
        } elseif ($screen instanceof Dialog) {
            $screenKind = 'dialog';
        } elseif ($screen instanceof Popup) {
            $screenKind = 'popup';
        } else {
            throw new LogicException('Unsupported UI screen type "' . get_class($screen) . '" for coverage identity.');
        }

        $this->screenSlug = $screen->getSlug();
        $this->screenKind = $screenKind;
        $this->widgetId = $wholeScreen ? self::WHOLE_SCREEN_TOKEN : $widget->getIdWithinUiContainer();
        $this->objectUid = $widget->getMetaObject()->getId();
        $this->roles = $roles;
        $this->element = $element;
        $this->actionFingerprint = hash('sha256', $actionUxon ?? '');
        $this->startedOn = DateTimeDataType::now();
    }

    /**
     * Captures one transferable identity for validating an entire page, dialog, or popup.
     *
     * WHY A FACTORY: callers must not accidentally retain a host widget id or action fingerprint
     * when the operation belongs to the screen itself. Both absent identity fields are filled with
     * the same reserved token and non-action work receives the canonical empty fingerprint.
     *
     * @param WidgetInterface $screenWidget Dialog/popup widget or the root widget of a page.
     * @param string[]|null $roles Roles reported by the browser, or null before login established them.
     */
    public static function forWholeScreen(WidgetInterface $screenWidget, ?array $roles): self
    {
        return new self(
            $screenWidget,
            self::WHOLE_SCREEN_TOKEN,
            null,
            $roles,
            true
        );
    }

    /** Exists so listeners can read the captured slug without retaining the mutable screen model. */
    public function getScreenSlug(): string
    {
        return $this->screenSlug;
    }

    /** Exists so listeners share the captured screen classification instead of re-deriving it. */
    public function getScreenKind(): string
    {
        return $this->screenKind;
    }

    /** Exists so listeners can identify the covered widget after its UI container has closed. */
    public function getWidgetId(): string
    {
        return $this->widgetId;
    }

    /** Exists so registry identity remains tied to the widget's model object without retaining it. */
    public function getObjectUid(): string
    {
        return $this->objectUid;
    }

    /** Preserves null so listeners can reject coverage captured before login established roles. */
    public function getRoles(): ?array
    {
        return $this->roles;
    }

    /** Exists so one widget's individual filters and buttons cannot collide in the registry. */
    public function getElement(): string
    {
        return $this->element;
    }

    /** Exists so changed action configuration invalidates prior coverage of the same button. */
    public function getActionFingerprint(): string
    {
        return $this->actionFingerprint;
    }

    /** Preserves dispatch time because completion listeners run only after the work has finished. */
    public function getStartedOn(): string
    {
        return $this->startedOn;
    }
}