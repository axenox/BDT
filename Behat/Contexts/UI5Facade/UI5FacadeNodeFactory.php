<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade;

use axenox\BDT\Behat\Contexts\UI5Facade\Nodes\GenericHtmlNode;
use axenox\BDT\Interfaces\FacadeNodeInterface;
use Behat\Mink\Element\NodeElement;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Widgets\AbstractWidget;
use Behat\Mink\Session;
use axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5ButtonNode;

/**
 * UI5FacadeNodeFactory
 * 
 * A factory class responsible for creating appropriate node objects 
 * based on the type of UI5 widget or HTML element.
 * 
 * This factory helps in dynamic node creation for different UI components
 * in UI5 applications, allowing flexible and extensible testing.
 */
class UI5FacadeNodeFactory
{
    private static $classesByWidgetType = [];

    /**
     * Creates a node object for a specific widget type
     *
     * This method attempts to find the appropriate node class for a given
     * widget type and creates an instance with the provided node element
     * and session.
     *
     * @param string $widgetType The type of widget to create a node for
     * @param NodeElement $nodeElement The HTML/UI5 element to wrap
     * @param Session $session The current browser session
     * @param UI5Browser $browser
     * @return FacadeNodeInterface The created node object
     * @throws \Exception If node creation fails
     */
    public static function createFromNodeElement(string $widgetType, NodeElement $nodeElement, Session $session, UI5Browser $browser): FacadeNodeInterface
    {
        try {
            // Resolve the appropriate node class for the widget type
            $class = self::getNodeClassForWidgetType($widgetType);
            // FIXME Find the outer node with the .exfw class (ideally matching the $widgetType). For DataTable: <div class="exfw exfw-DataTable".
            // $nodeElement = $class::findWidgetNode($nodeElement);
            // Create and return a new node instance
            return new $class($nodeElement, $session, $browser);
        } catch (\Exception $e) {
            // Detailed Error Info
            echo "Error in createFromNodeElement: " . $e->getMessage() . "\n";
            echo "Widget Type: " . $widgetType . "\n";
            echo "Class Exists: " . (class_exists(UI5ButtonNode::class) ? 'Yes' : 'No') . "\n";
            throw $e;
        }
    }

    /**
     * Determines the appropriate node class for a given widget type
     * 
     * This method uses a sophisticated resolution strategy:
     * 1. Check if a node class is already cached
     * 2. Try to find a direct match for the widget type
     * 3. Fallback to parent widget classes if no direct match exists
     * 4. Use GenericHtmlNode as a last resort
     * 
     * @param string $widgetType The widget type to resolve
     * @return string The fully qualified class name for the node
     */
    protected static function getNodeClassForWidgetType(string $widgetType): string
    {
        // Get the widget class for the given type
        $widgetClass = WidgetFactory::getWidgetClassFromType($widgetType);
        // Check if a node class is already cached
        // $class like `exface\Core\Widgets\DataTable`
        $nodeClass = static::$classesByWidgetType[$widgetType] ?? null;
        if (is_null($nodeClass)) {
            // Try to get the node class for the current widget type

            $nodeClass = static::getNodeClass($widgetType);
            // If no direct match exists, try parent widget classes

            if (!class_exists($nodeClass)) {
                $widgetClass = get_parent_class($widgetClass);
                $nodeClass = self::getNodeClass(AbstractWidget::getWidgetTypeFromClass($widgetClass));
                // Traverse up the widget class hierarchy
                while (!class_exists($nodeClass)) {
                    if ($widgetClass = get_parent_class($widgetClass)) {
                        $nodeClass = self::getNodeClass(AbstractWidget::getWidgetTypeFromClass($widgetClass));
                    } else {
                        break;
                    }
                }

                // Handle special cases for abstract classes
                if (class_exists($nodeClass)) {
                    // Special handling for the AbstractWidget
                    $reflection = new \ReflectionClass($nodeClass);
                    if ($reflection->isAbstract()) {
                        $nodeClass = GenericHtmlNode::class;
                    }
                } else {
                    // Fallback to GenericHtmlNode if no suitable class is found
                    // if the required widget is not found, create an abstract widget instead
                    $nodeClass = GenericHtmlNode::class;
                }
            }
            static::$classesByWidgetType[$widgetType] = $nodeClass;
        }
        return $nodeClass;
    }
    
    /**
     * Generates the expected node class name based on widget type
     * 
     * Follows a naming convention: Namespace\Nodes\UI5{WidgetType}Node
     * 
     * @param string $widgetType The widget type to convert to a class name
     * @return string The generated class name
     */
    private static function getNodeClass(string $widgetType): string
    {
        return __NAMESPACE__ . '\\Nodes\\UI5' . $widgetType . 'Node';
    }
}