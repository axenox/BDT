<?php

namespace axenox\BDT\Behat\Events;

use Behat\Testwork\Event\Event;

class BeforeSubstep extends Event
{
    private string $stepName;
    private ?string $category = null;
    private ?SubstepCoverageIdentity $coverageIdentity;
    
    /**
     * Carries the optional eager coverage identity through both halves of a substep event pair.
     *
     * WHY OPTIONAL: many diagnostic substeps are not works-as-expected coverage records. Keeping the
     * identity nullable preserves those callers and makes missing identity fail toward re-testing.
     */
    public function __construct(
        string $stepName,
        ?string $category = null,
        ?SubstepCoverageIdentity $coverageIdentity = null
    )
    {
        $this->stepName = $stepName;
        $this->category = $category;
        $this->coverageIdentity = $coverageIdentity;
    }

    /**
     * @return string
     */
    public function getSubstepName() : string
    {
        return $this->stepName;
    }

    /**
     * @return string|null
     */
    public function getCategory() : ?string
    {
        return $this->category;
    }

    /** Preserves the eager identity across nested execution without ambient mutable state. */
    public function getCoverageIdentity(): ?SubstepCoverageIdentity
    {
        return $this->coverageIdentity;
    }
}