<?php
namespace axenox\BDT\Widgets;

use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\WorkbenchCache;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\PhpFilePathDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Widgets\InputCustom;

class InputGherkin extends InputCustom
{
    private $stepData = [];

    protected function init()
    {
        $this->setScriptVariables(new UxonObject(['editor' => 'null']));
        $this->setScriptToInit($this->buildJsAce('editor'));
        $this->setScriptToGetValue("[#editor#].getValue()");
        $this->setScriptToSetValue("[#editor#].setValue([#~mValue#]);");
        $this->setScriptToDisable("[#editor#].setReadOnly(true)");
        $this->setScriptToEnable("[#editor#].setReadOnly(false)");
    }

    /**
     * 
     * @return string|NULL
     */
    public function getHtml() : ?string
    {
        return <<<HTML

<pre id="{$this->getId()}" class="{$this->getCssClass()}" style="height: calc(100% - 10px)">

</pre>
        
HTML;
    }

    /**
     * Loads Ace and gives long Gherkin steps enough horizontal space to remain readable.
     *
     * @param bool $addIncludes Whether parent includes should be added
     * @return string[] HTML head tags required by the editor
     */
    public function getHtmlHeadTags(bool $addIncludes = true) : array
    {
        $includes = parent::getHtmlHeadTags();
        $includes[] = '<script src="vendor/npm-asset/ace-builds/src-min/ace.js"></script>';
        $includes[] = '<script src="vendor/npm-asset/ace-builds/src-min/ext-language_tools.js"></script>';
        $includes[] = '<script src="vendor/npm-asset/ace-builds/src-min/ext-searchbox.js"></script>';
        $includes[] = '<style>.ace_autocomplete { width: min(700px, calc(100vw - 32px)) !important; }</style>';
        $includes[] = '<style>.gherkin-var-highlight { position: absolute; background-color: rgba(255, 213, 79, 0.45); border-radius: 2px; }</style>';
        return $includes;
    }

    /**
     * Builds the Gherkin-aware Ace editor, including insertion that replaces an already typed step prefix.
     *
     * WHY CUSTOM INSERTION: Ace only replaces the current word by default. After a user types `I `,
     * its detected prefix is empty, so selecting `I look at table` otherwise produces `I I look at table`.
     *
     * @param string $editorVariable Script variable that stores the Ace editor
     * @return string|null JavaScript used to initialize the editor
     */
    public function buildJsAce(string $editorVariable) : ?string   
    {
        return <<<JS

[#{$editorVariable}#] = (function(){
    // Initialize Ace Editor
    const oEditor = ace.edit("{$this->getId()}");
    const oLangTools = ace.require("ace/ext/language_tools");
    oEditor.setTheme("ace/theme/xcode");
    oEditor.session.setMode("ace/mode/gherkin");

    // Define custom autocompletion phrases
    const aCompletions = {$this->buildJsCompletionsArray()};

    // Turn a step's Behat variable tokens into Ace snippet tab stops so the inserted step highlights
    // every variable (e.g. :caption or the quoted ":value" form) as a box the user can Tab through and
    // overwrite. Everything else is emitted as literal text with the snippet meta-characters escaped,
    // otherwise a stray \$ or } in a step would be swallowed by the snippet parser.
    const fnStepToSnippet = function (text) {
        let tabIndex = 0;
        return text
            .split(/(:[A-Za-z_][A-Za-z0-9_]*)/g)
            .map(function (part) {
                const match = part.match(/^:([A-Za-z_][A-Za-z0-9_]*)\$/);
                if (match) {
                    tabIndex++;
                    // Keep the leading colon in the default text so an unfilled variable stays a loud
                    // :placeholder (never a plausible-looking real value) and is picked up by fnRefreshVarMarkers.
                    return '\${' + tabIndex + ':' + ':' + match[1] + '}';
                }
                return part.replace(/([\\\\\$}])/g, '\\\\\$1');
            })
            .join('');
    };

    const oCompleter = {
      getCompletions: function (oEditor, session, pos, prefix, callback) {
        // Show all phrases containing the typed word (case-insensitive)
                const matches = aCompletions
                    .filter((completion) => completion.caption.toLowerCase().includes(prefix.toLowerCase()))
                    .map((completion) => ({...completion, typedPrefix: prefix, completer: oCompleter}));
        callback(null, matches);
      },
            insertMatch: function (oEditor, completion) {
                const value = completion.value || completion.caption;
                const pos = oEditor.getCursorPosition();
                const textBeforeCursor = oEditor.session.getLine(pos.row).slice(0, pos.column);
                const maxOverlap = Math.min(textBeforeCursor.length, value.length);
                let overlapLength = 0;

                // Replace the full phrase fragment already typed, including spaces Ace does not treat as a prefix.
                for (let length = maxOverlap; length > 0; length--) {
                    if (textBeforeCursor.slice(-length).toLowerCase() === value.slice(0, length).toLowerCase()) {
                        overlapLength = length;
                        break;
                    }
                }

                const replaceLength = overlapLength || completion.typedPrefix.length;
                const Range = ace.require("ace/range").Range;
                const range = new Range(pos.row, pos.column - replaceLength, pos.row, pos.column);

                // Drop the already-typed fragment first, then insert as a snippet so the variable tab stops
                // are computed from the final cursor position rather than the pre-removal one.
                oEditor.session.remove(range);
                oEditor.moveCursorToPosition(range.start);
                const oSnippetManager = ace.require("ace/snippets").snippetManager;
                oSnippetManager.insertSnippet(oEditor, fnStepToSnippet(value));
            },
    };
    oLangTools.setCompleters([oCompleter]);

    // Persistently highlight every still-unfilled :variable. WHY: the snippet tab stops only mark a
    // variable while the cursor sits on it; tabbing past an unedited one drops that mark, leaving a
    // bare :placeholder that reads like a real value. A background marker keeps it visibly pending and
    // vanishes on its own once the token is overwritten. Re-scanning the whole (small) document on every
    // change keeps marker positions correct without tracking anchors across edits.
    let aVarMarkerIds = [];
    const fnRefreshVarMarkers = function () {
        const oSession = oEditor.session;
        aVarMarkerIds.forEach(function (id) { oSession.removeMarker(id); });
        aVarMarkerIds = [];
        const Range = ace.require("ace/range").Range;
        const aLines = oSession.getDocument().getAllLines();
        // Negative lookbehind on : and word chars so @Status::Ready / word:word are not mistaken for variables.
        const oVarRegex = /(?<![:A-Za-z0-9_]):[A-Za-z_][A-Za-z0-9_]*/g;
        for (let iRow = 0; iRow < aLines.length; iRow++) {
            oVarRegex.lastIndex = 0;
            let oMatch;
            while ((oMatch = oVarRegex.exec(aLines[iRow])) !== null) {
                const oRange = new Range(iRow, oMatch.index, iRow, oMatch.index + oMatch[0].length);
                aVarMarkerIds.push(oSession.addMarker(oRange, "gherkin-var-highlight", "text"));
            }
        }
    };
    oEditor.session.on("change", fnRefreshVarMarkers);
    fnRefreshVarMarkers();

    // Set completion options AFTER adding the custom completer in order to avoid
    // local completions - words from the current documents
    oEditor.setOptions({
      enableBasicAutocompletion: true,
      enableSnippets: true,
      enableLiveAutocompletion: true,
    });

    return oEditor;
})();
JS;
    }

    protected function buildJsCompletionsArray() : string
    {
        $steps = $this->findStepsInContexts();
        $aceCompletions = [
            ['caption' => '@Status::Ready', 'value' => '@Status::Ready ', 'meta' => 'Keyword'],
            ['caption' => '@Status::Draft', 'value' => '@Status::Draft ', 'meta' => 'Keyword'],
            ['caption' => '@DevMan::TC', 'value' => '@DevMan::TC.... #Fill here with Testcase Number from DevMan ' , 'meta' => 'Keyword'],
            ['caption' => 'Given', 'value' => 'Given ', 'meta' => 'Keyword'],
            ['caption' => 'When', 'value' => 'When ', 'meta' => 'Keyword'],
            ['caption' => 'Then', 'value' => 'Then ', 'meta' => 'Keyword'],
            ['caption' => 'And', 'value' => 'And ', 'meta' => 'Keyword'],
            ['caption' => 'But', 'value' => 'But ', 'meta' => 'Keyword'],
            [
                'caption' => 'Scenario - a set of steps to be tested',
                'value' => <<<TXT
Scenario: [name]
        # steps here
TXT             ,
                'meta' => 'Template'
            ],
            [
                'caption' => 'Scenario outline - template to run for multiple inputs',
                'value' => <<<TXT
Scenario outline: [name]
        # steps here with "<myVar1>" and "<myVar2>" as placeholders
        Examples:
        | myVar1 | myVar2 |
        | value1 | value2 |
TXT             ,
                'meta' => 'Template'
            ],
            [
                'caption' => 'Feature - a new feature file',
                'value' => <<<TXT
Feature: [name of feature]
    In order to write good automated tests
    As a test manager
    I need to fill out templates provided by this editor

    Scenario: [name of scenario]
        # steps here
TXT,
                'meta' => 'Template'
            ],
            [
                'caption' => 'Background - steps to run before every scenario',
                'value' => <<<TXT
# Do this before every scenario
Background: [name]
        # steps here
TXT             ,
                'meta' => 'Keyword'
            ]
        ];
        foreach ($steps as $phrase) {
            list($key, $step) = explode(' ', $phrase, 2);
            $aceCompletions[] = [
                'caption' => $step,
                'value' => $step,
                'meta' => $key
            ];
        }
        return json_encode($aceCompletions);
    }

    protected function findStepsInContexts() : array
    {
        if (null !== $cache = $this->getCache('steps')) {
            return $cache;
        }
        $steps = [];
        $filesSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.BDT.GHERKIN_CONTEXT');
        $pathCol = $filesSheet->getColumns()->addFromExpression('PATHNAME_RELATIVE');
        $filesSheet->dataRead();
        foreach ($pathCol->getValues() as $path) {
            $stepSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.BDT.GHERKIN_ANNOTATION');
            $stepCol = $stepSheet->getColumns()->addFromExpression('STEP');
            $stepSheet->getFilters()->addConditionFromString('FILE', $path, ComparatorDataType::EQUALS);
            $stepSheet->dataRead();
            $steps = array_merge($steps, $stepCol->getValues());
        }
        $steps = array_unique($steps);
        $this->setCache('steps', $steps);
        return $steps;
    }
    
    protected function getCache(string $key) : ?array
    {
        return $this->getWorkbench()->getCache()->getPool('axenox.BDT')->get($key);
    }
    
    protected function setCache(string $key, $data) : InputGherkin
    {
        $this->getWorkbench()->getCache()->getPool('axenox.BDT')->set($key, $data);
        return $this;
    }
}