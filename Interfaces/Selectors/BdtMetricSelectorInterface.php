<?php
namespace axenox\BDT\Interfaces\Selectors;

use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\Core\Interfaces\Selectors\PrototypeSelectorInterface;

/**
 * Interface for BDT metric selectors.
 *
 * A facade can be identified by
 *  - fully qualified alias (with vendor and app prefix)
 *  - file path or qualified class name of the app's PHP class (if there is one)
 *
 * @author Andrej Kabachnik
 *
 */
interface BdtMetricSelectorInterface extends AliasSelectorInterface, PrototypeSelectorInterface
{}