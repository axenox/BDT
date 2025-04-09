<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade;

use axenox\BDT\Behat\Contexts\UI5Facade\Nodes\GenericHtmlNode;
use axenox\BDT\Interfaces\FacadeNodeInterface;
use Behat\Mink\Element\NodeElement;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Widgets\AbstractWidget;
use Behat\Mink\Session;

class UI5FacadeNodeFactory
{
    private static $classesByWidgetType = [];

    public static function createFromNodeElement(string $widgetType, NodeElement $nodeElement, Session $session) : FacadeNodeInterface
    {
        $class = self::getNodeClassForWidgetType($widgetType);
        return new $class($nodeElement, $session);
    }

    protected static function getNodeClassForWidgetType(string $widgetType) : string
    {
        $widgetClass = WidgetFactory::getWidgetClassFromType($widgetType);
        // $class like `exface\Core\Widgets\DataTable`
        $nodeClass = static::$classesByWidgetType[$widgetType] ?? null;
        if (is_null($nodeClass)) {
            $nodeClass = static::getNodeClass($widgetType);
            if (! class_exists($nodeClass)) {
                $widgetClass = get_parent_class($widgetClass);
                $nodeClass = self::getNodeClass(AbstractWidget::getWidgetTypeFromClass($widgetClass));
                while (! class_exists($nodeClass)) {
                    if ($widgetClass = get_parent_class($widgetClass)) {
                        $nodeClass = self::getNodeClass(AbstractWidget::getWidgetTypeFromClass($widgetClass));
                    } else {
                        break;
                    }
                }
                
                if (class_exists($nodeClass)) {
                    // Special handling for the AbstractWidget
                    $reflection = new \ReflectionClass($nodeClass);
                    if ($reflection->isAbstract()) {
                        $nodeClass = GenericHtmlNode::class;
                    }
                } else {
                    // if the required widget is not found, create an abstract widget instead
                    $nodeClass = GenericHtmlNode::class;
                }
            }
            static::$classesByWidgetType[$widgetType] = $nodeClass;
        }
        return $nodeClass;
    }

    private static function getNodeClass(string $widgetType) : string
    {
        return __NAMESPACE__ . '\\Nodes\\UI5' . $widgetType . 'Node';
    }
}