<?php
namespace axenox\BDT\Exceptions;

class FacadeRequestException extends FacadeBrowserException
{

    public function toCliOutput() : string
    {
        $message = parent::toCliOutput();
        if (!empty($error['response'])) {
            $message .= "Response: " . $this->getResponse() . "\n";
        }
        $message .= "URL: " . $this->getUrl() . "\n";
        return $message;
    }

    public function getUrl() : string
    {
        // TODO
        return '';
    }

    public function getResponse() : string
    {
        // TODO
        return '';
    }
}