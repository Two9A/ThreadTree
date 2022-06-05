<?php
/**
 * Error handling controller. Fills out the error view given an exception.
 *
 * @author Imran Nazar <tf@imrannazar.com>
 */

class ErrorController extends bsControllerBase
{
    public function indexAction($exception)
    {
        if ($exception instanceof bsException) {
            header('HTTP/1.1 '.$exception->getCode().' '.$exception->getCodeDesc());
        }

        $this->view->code = $exception->getCode();
        $this->view->message = $exception->getMessage();
        $this->view->add_asset('css', 'error.css');
        return 'error';
    }
}

