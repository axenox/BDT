<?php
/**
 * Very simple FileOutputPrinter for BehatFormatter
 * @author David Raison <david@tentwentyfour.lu>
 */

namespace axenox\BDT\Behat\DatabaseFormatter;

use Behat\Testwork\Output\Exception\BadOutputPathException;
use Behat\Testwork\Output\Printer\OutputPrinter as PrinterInterface;


class DummyOutputPrinter implements PrinterInterface 
{
    /**
     * @inheritDoc
     */
    public function setOutputPath($path)
    {
        // TODO: Implement setOutputPath() method.
    }

    /**
     * @inheritDoc
     */
    public function getOutputPath()
    {
        // TODO: Implement getOutputPath() method.
    }

    /**
     * @inheritDoc
     */
    public function setOutputStyles(array $styles)
    {
        // TODO: Implement setOutputStyles() method.
    }

    /**
     * @inheritDoc
     */
    public function getOutputStyles()
    {
        // TODO: Implement getOutputStyles() method.
    }

    /**
     * @inheritDoc
     */
    public function setOutputDecorated($decorated)
    {
        // TODO: Implement setOutputDecorated() method.
    }

    /**
     * @inheritDoc
     */
    public function isOutputDecorated()
    {
        // TODO: Implement isOutputDecorated() method.
    }

    /**
     * @inheritDoc
     */
    public function setOutputVerbosity($level)
    {
        // TODO: Implement setOutputVerbosity() method.
    }

    /**
     * @inheritDoc
     */
    public function getOutputVerbosity()
    {
        // TODO: Implement getOutputVerbosity() method.
    }

    /**
     * @inheritDoc
     */
    public function write($messages)
    {
        // TODO: Implement write() method.
    }

    /**
     * @inheritDoc
     */
    public function writeln($messages = '')
    {
        // TODO: Implement writeln() method.
    }

    /**
     * @inheritDoc
     */
    public function flush()
    {
        // TODO: Implement flush() method.
    }
}