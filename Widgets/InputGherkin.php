<?php
namespace axenox\BDT\Widgets;

use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\WorkbenchCache;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\PhpFilePathDataType;
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

    public function getHtmlHeadTags(bool $addIncludes = true) : array
    {
        $includes = parent::getHtmlHeadTags();
        $includes[] = '<script src="vendor/npm-asset/ace-builds/src-min/ace.js"></script>';
        $includes[] = '<script src="vendor/npm-asset/ace-builds/src-min/ext-language_tools.js"></script>';
        $includes[] = '<script src="vendor/npm-asset/ace-builds/src-min/ext-searchbox.js"></script>';
        return $includes;
    }

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
    const oCompleter = {
      getCompletions: function (oEditor, session, pos, prefix, callback) {
        // Show all phrases containing the typed word (case-insensitive)
        const matches = aCompletions.filter((completion) =>
          completion.caption.toLowerCase().includes(prefix.toLowerCase())
        );
        callback(null, matches);
      },
    };
    oLangTools.setCompleters([oCompleter]);

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
        $aceCompletions = [];
        foreach ($steps as $phrase) {
            $aceCompletions[] = [
                'caption' => $phrase,
                'value' => $phrase,
                'meta' => 'steps'
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