<?php
namespace axenox\BDT\Exceptions;

use exface\Core\Exceptions\RuntimeException;

/**
* Thrown when Chrome's CDP connection is lost and the browser process must be restarted.
* Caught by UI5ContainerNode to trigger recovery instead of marking the test as failed.
*/
class ChromeHangException extends RuntimeException {
}