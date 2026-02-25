<?php
namespace axenox\BDT\DataTypes;

use Behat\Testwork\Tester\Result\TestResult;
use exface\Core\CommonLogic\DataTypes\EnumStaticDataTypeTrait;
use exface\Core\DataTypes\IntegerDataType;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;

/**
 * Enumeration of BDT test step status
 * 
 * @author Andrej Kabachnik
 *
 */
class StepStatusDataType extends IntegerDataType implements EnumDataTypeInterface
{
    use EnumStaticDataTypeTrait;
    
    CONST PENDING = 0;
    CONST STARTED = 10;
    CONST UNDEFINED = 30;
    CONST SKIPPED = 99;
    CONST PASSED = 100;
    CONST FAILED = 101;
    CONST PASSED_PREVIOUSLY = 110;
    CONST FAILED_PREVIOUSLY = 111;
    CONST TIMEOUT = 102;

    private $labels = [];
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataTypes\EnumDataTypeInterface::getLabels()
     */
    public function getLabels()
    {
        if (empty($this->labels)) {
            $translator = $this->getWorkbench()->getCoreApp()->getTranslator();
            
            foreach (static::getValuesStatic() as $const => $val) {
                $this->labels[$val] = mb_ucfirst(mb_strtolower(str_replace('_', ' ', $const)));
            }
        }
        
        return $this->labels;
    }
    
    public static function convertFromBehatResultCode(int $behatResultCode) : int
    {
        switch ($behatResultCode) {
            case TestResult::PASSED: $status = self::PASSED; break;
            case TestResult::SKIPPED: $status = self::SKIPPED; break;
            case TestResult::PENDING: $status = self::PENDING; break;
            case TestResult::FAILED: $status = self::FAILED; break;
            case 30: $status = self::UNDEFINED; break; // for `\Behat\Behat\Tester\Result\UndefinedStepResult`
            default:
                $status = self::UNDEFINED;
        }
        return $status;
    }

}