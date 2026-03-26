<?php
namespace axenox\BDT\Common\Installer;

use exface\Core\Interfaces\Selectors\SelectorInterface;
use exface\Core\CommonLogic\AppInstallers\DataInstaller;
use exface\Core\CommonLogic\AppInstallers\MetaModelInstaller;

/**
 * Makes sure test metrics and other BDT configurations are exported with the apps metamodel
 *
 * ```
 * $installer = new BDTConfigInstaller($this->getSelector(), $container);
 * $container->addInstaller($installer);
 *
 * ```
 *
 * @author Andrej Kabachnik
 *
 */
class BDTConfigInstaller extends DataInstaller
{
    /**
     *
     * @param SelectorInterface $selectorToInstall
     */
    public function __construct(SelectorInterface $selectorToInstall)
    {
        parent::__construct($selectorToInstall, MetaModelInstaller::FOLDER_NAME_MODEL . DIRECTORY_SEPARATOR . 'BDT');
        $this->addDataToReplace('axenox.BDT.metric', 'CREATED_ON', 'app');
    }
}