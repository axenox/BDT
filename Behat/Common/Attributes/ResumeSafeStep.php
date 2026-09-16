<?php
namespace axenox\BDT\Behat\Common\Attributes;


use Attribute;

/**
 * Marks a Behat step definition as safe to resume after a Chrome recovery.
 *
 * WHY THIS EXISTS: after Chrome is restarted, the scenario can only continue if the browser state it had
 * built can be rebuilt. The URL brings back the page and an open dialog, and the recovery rebuilds the
 * focused widget. Nothing else survives: typed values, selected rows, opened tabs, expanded nodes, opened
 * menus. A step that changed any of those must stop the scenario; a step that only read the page or only
 * moved the focus must not.
 *
 * WHAT QUALIFIES: a step whose only lasting effect is nothing at all (a pure assertion) or which widget is
 * focused. Anything that types, selects, clicks, opens, expands or sends data to the server does NOT qualify.
 *
 * WHY OPT-IN: an unmarked step keeps blocking a resume. A forgotten attribute therefore costs a visibly
 * stopped scenario after a Chrome recovery - never a green result on a state the scenario did not build.
 *
 * WHY AN ATTRIBUTE AND NOT A DOCBLOCK TAG: step docblocks are parsed by Behat for annotations, and a new tag
 * there risks being read as something it is not. A PHP attribute is invisible to that parser.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ResumeSafeStep
{
}