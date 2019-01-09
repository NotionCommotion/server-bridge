<?php
namespace Greenbean\ServerBridge;
class ServerBridgeException extends \Exception
{
    //code is http status code.  code and previous exception are required
    private $json=false;
    public function __construct($body, int $code, \Exception $previous) {
        if(!is_string($message)) {
            $this->json=$message;
            $message='JSON error message';
        }
        parent::__construct($message, $code, $previous);
    }
    public function getMessage() {
        return $this->json?$this->json:parent::getMessage();
    }
}
