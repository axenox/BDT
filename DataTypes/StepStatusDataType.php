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
    CONST PASSED_PREVIOUSLY = 90;
    CONST FAILED_PREVIOUSLY = 91;
    CONST SKIPPED = 98;
    CONST PASSED = 100;
    CONST FAILED = 101;
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
            
            foreach (static::getValuesOfConstants() as $const => $val) {
                $this->labels[$val] = mb_ucfirst(mb_strtolower(str_replace('_', ' ', $const)));
            }
        }
        
        return $this->labels;
    }
    
    public static function convertFromBehatResultCode(int $behatResultCode) : int
    {
        switch ($behatResultCode) {
            case TestResult::PASSED:
                $status = self::PASSED; break;
            case TestResult::SKIPPED:
                $status = self::SKIPPED; break;
            case TestResult::PENDING: 
                $status = self::PENDING; break;
            case TestResult::FAILED: 
                $status = self::FAILED; break;
            case 30: 
                $status = self::UNDEFINED; break; // for `\Behat\Behat\Tester\Result\UndefinedStepResult`
            default:
                $status = $behatResultCode;
        }
        return $status;
    }

    /**
     * Tells whether a recorded outcome is good enough to skip doing the work again.
     *
     * WHY ONLY PASSED: a failure is a conclusive answer in plain language, but not a useful one to
     * stand on. A screen that failed is a screen whose verdict nobody trusts yet - it may have failed
     * on timing, on data state, or on a defect somebody has since fixed - and the point of testing it
     * again is to find out. Skipped and timed-out outcomes are open for the same reason: they describe
     * a condition under which the work could not be judged, not a judgement.
     *
     * WHY THE NAME IS NOT isConclusive() ANY MORE: it used to accept FAILED, and reading it as "is
     * this a definite answer" made that look right. The question the registry actually asks is
     * narrower, and naming it precisely is what keeps the two from drifting apart again.
     */
    public static function suppressesRetest(int $status): bool
    {
        return $status === self::PASSED;
    }

    /**
     * Ranks recorded outcomes so a later write can only ever improve what the registry already holds.
     *
     * WHY A TOTAL ORDER: several lanes can finish the same identity in any order, and a rule phrased
     * as "the newest write wins" would make the stored verdict depend on scheduling. Ranking the
     * outcomes instead makes the result the same whatever the order: a claim yields to any real
     * verdict, a failure yields to a pass, and a pass yields to nothing. No row ever moves backwards.
     *
     * WHY A PASS OUTRANKS A FAILURE: only a pass suppresses re-testing, so a failure has to stay
     * replaceable - otherwise a screen that failed once would be re-swept on every encounter for the
     * rest of the run even after it started passing. The failure itself is not lost: it was already
     * written to the step report when it happened.
     *
     * @return int Higher wins. Unknown statuses rank lowest so they can never overwrite a verdict.
     */
    public static function getCoverageRank(int $status): int
    {
        switch ($status) {
            case self::PASSED: return 40;
            case self::FAILED: return 30;
            case self::SKIPPED:
            case self::TIMEOUT: return 20;
            case self::STARTED: return 10;
            default: return 0;
        }
    }
}