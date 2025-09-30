<?php
namespace axenox\BDT\DataTypes;

use Behat\Testwork\Tester\Result\TestResult;
use exface\Core\CommonLogic\DataTypes\EnumStaticDataTypeTrait;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;

/**
 * Enumeration of BDT scenario status (string-based)
 *
 * Backed by the view column `scenario_status` which yields strings:
 * paused, failed, skipped, passed, started, pending, unknown
 * 
 * @author Gizem Bicer
 */
class ScenarioStatusDataType extends StringDataType implements EnumDataTypeInterface
{
    use EnumStaticDataTypeTrait;

    public const PAUSED  = 'paused';
    
    public const FAILED  = 'failed';
    
    public const SKIPPED = 'skipped';
    
    public const PASSED  = 'passed';
    
    public const STARTED = 'started';
    
    public const PENDING = 'pending';

    public const UNKNOWN = 'unknown';
    public const NOT_READY = 'not ready';

    private $labels = [];

    /**
     * {@inheritDoc}
     */
    public function getLabels(): array
    {
        if (empty($this->labels)) {
            $translator = $this->getWorkbench()->getCoreApp()->getTranslator();

            foreach (static::getValuesStatic() as $const => $val) {
                $lower = mb_strtolower($val);
                $this->labels[$val] = mb_strtoupper(mb_substr($lower, 0, 1)) . mb_substr($lower, 1);
            }
        }
        return $this->labels;
    }
}