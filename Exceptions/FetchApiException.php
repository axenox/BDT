<?php
namespace axenox\BDT\Exceptions;

use exface\Core\Exceptions\RuntimeException;
use exface\Core\Widgets\DebugMessage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\RequestInterface;
use exface\Core\CommonLogic\Debugger\HttpMessageDebugger;

/**
 * Exception thrown if a request via the browser Fetch API fails while testing.
 *
 * @author Andrej Kabachnik
 *
 */
class FetchApiException extends RuntimeException
{
    private ResponseInterface $response;
    private RequestInterface $request;

    public function __construct(RequestInterface $request, ResponseInterface $response, $message, $alias = null, $previous = null)
    {
        $this->response = $response;
        $this->request = $request;
        parent::__construct($message, $alias, $previous);
    }

    /**
     * @return ResponseInterface
     */
    public function getResponse() : ResponseInterface
    {
        return $this->response;
    }

    /**
     * @return RequestInterface
     */
    public function getRequest() : RequestInterface
    {
        return $this->request;
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\iCanGenerateDebugWidgets::createDebugWidget()
     */
    public function createDebugWidget(DebugMessage $debugWidget)
    {
        $debugWidget = parent::createDebugWidget($debugWidget);
        $debugRenderer = new HttpMessageDebugger($this->getRequest(), $this->getResponse(), 'AJAX request', 'AJAX response');
        return $debugRenderer->createDebugWidget($debugWidget);
    }
}