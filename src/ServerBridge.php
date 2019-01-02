<?php
namespace Greenbean\ServerBridge;
class ServerBridge
{

    private $httpClient, $returnAsArray, $customMimeTypes, $standardMimeTypes=[
        'csv'=>'text/csv',
        'json'=>'application/json'
    ];

    public function __construct(\GuzzleHttp\Client $httpClient, bool $returnAsArray=true, array $customMimeTypes=[]){
        $this->httpClient=$httpClient;  //Must be configured with default path
        $this->returnAsArray=$returnAsArray;
        $this->customMimeTypes=$customMimeTypes;
    }

    public function proxy(\Slim\Http\Request $slimRequest, \Slim\Http\Response $slimResponse, \Closure $errorHandler=null):\Slim\Http\Response {
        //Forwards Slim Request to another server and returns the updated Slim Response.
        $slimRequest=$slimRequest->withUri($slimRequest->getUri()->withHost($this->getHost(false)));  //Change slim's host to API server!
        try {
            $guzzleResponse=$this->httpClient->send($slimRequest);
            $excludedHeaders=['Date', 'Server', 'X-Powered-By', 'Access-Control-Allow-Origin', 'Access-Control-Allow-Methods', 'Access-Control-Allow-Headers'];
            $headerArrays=array_diff_key($guzzleResponse->getHeaders(), array_flip($excludedHeaders));
            foreach($headerArrays as $headerName=>$headers) {
                foreach($headers as $headerValue) {
                    $slimResponse=$slimResponse->withHeader($headerName, $headerValue);
                }
            }
            return $slimResponse->withStatus($guzzleResponse->getStatusCode())->withBody($guzzleResponse->getBody());
        }
        catch (\GuzzleHttp\Exception\RequestException  $e) {
            if ($e->hasResponse()) {
                $guzzleResponse=$e->getResponse();
                $body=$errorHandler?$errorHandler($guzzleResponse->getBody()):$guzzleResponse->getBody();
                return $slimResponse->withStatus($guzzleResponse->getStatusCode())->withBody($body);
            }
            else {
                return $slimResponse->withStatus(500)->write(json_encode(['message'=>'RequestException without response: '.$e->getMessage()]));
            }
        }
    }

    public function callApi(\GuzzleHttp\Psr7\Request $curlRequest, array $data=[]):\GuzzleHttp\Psr7\Response {
        //Submits a single Guzzle Request and returns the Guzzle Response.
        try {
            if($data) {
                $data=[in_array($curlRequest->getMethod(), ['GET','DELETE'])?'query':'json'=>$data];
            }
            $curlResponse = $this->httpClient->send($curlRequest, $data);
        }
        catch (\GuzzleHttp\Exception\RequestException  $e) {
            $curlResponse=$e->hasResponse()
            ?$e->getResponse()
            :new \GuzzleHttp\Psr7\Response($e->getCode(), [], $e->getMessage());    //Untested
        }
        return $curlResponse;
    }

    public function getPageContent(array $curlRequests):array {
        //Helper function which receives multiple Guzzle Requests which will populate a given webpage.
        //Each element in the array will either be a Guzzle Request or an array with a Guzzle Request and other options.
        //Errors only support having a message in the response unless $errorCallback is provided.
        $errors=[];
        $curlResponses=[];
        foreach($curlRequests as $name=>$curlRequest) {
            if(is_array($curlRequest)) {
                //[\GuzzleHttp\Psr7\Request $curlRequest, array $data=[], array $options=[], \Closure $callback=null, \Closure $errorCallback=null] where options: int expectedCode, mixed $defaultResults, bool returnAsArray
                $errorCallback=$curlRequest[4]??false;
                $callback=$curlRequest[3]??false;
                $defaultResults=$curlRequest[2]['defaultResults']??[];
                $expectedCode=$curlRequest[2]['expectedCode']??200;
                $returnAsArray=$curlRequest[2]['returnAsArray']??$this->returnAsArray;
                $data=$curlRequest[1]??[];
                $curlRequest=$curlRequest[0];
            }
            elseif ($curlRequest instanceof \GuzzleHttp\Psr7\Request) {
                $callback=false;
                $defaultResults=[];
                $expectedCode=200;
                $returnAsArray=$this->returnAsArray;
                $data=[];
            }
            else throw new ServerBridgeException("Invalid request to getPageContent: $name => ".json_encode($curlRequest));
            $curlResponse=$this->callApi($curlRequest, $data);
            $body=json_decode($curlResponse->getBody(), $returnAsArray);
            if($curlResponse->getStatusCode()===$expectedCode) {
                if($callback) {
                    $body=$callback($body);
                }
                $curlResponses[$name]=$body;
            }
            else {
                if($errorCallback) {
                    $body=$errorCallback($body);
                }
                $curlResponses[$name]=$body;
                $curlResponses[$name]=$defaultResults;
                $errors[$name]=$returnAsArray?"$name: $body[message]":"$name: $body->message";
            }
        }
        if($errors) $curlResponses['errors']=$errors;
        return $curlResponses;
    }

    public function getMimeType($type) {
        syslog(LOG_INFO, json_encode(debug_backtrace()));
        if(empty($type)) throw new ServerBridgeException("Missing Accept value.");
        $a=array_merge($this->standardMimeTypes, $this->customMimeTypes);
        if(!isset($a[$type]))
            throw new ServerBridgeException("Invalid Accept value: $type");
        return $a[$type];
    }

    public function getConfig():array {
        return $this->httpClient->getConfig();
    }

    public function getConfigParam(array $path) {
        //$path=['elem1', 'elem2'] => returns $config[$elem1]['elem2]
        $config=$this->httpClient->getConfig();
        $tmp=$this->config;
        foreach($path as $key) {
            if(!isset($config[$key])) {
                throw new ServerBridgeException('Invalid path: '.implode('=>', $this->httpClient->getConfig()));
            }
            $config=$config[$key];
        }
        return $config;
    }

    public function getHost(bool $includeSchema=true):string {
        $baseUri=$this->httpClient->getConfig()['base_uri'];
        return $includeSchema?$baseUri->getScheme().'://'.$baseUri->getHost():$baseUri->getHost();
    }

    private function getBestSupportedMimeType($mimeTypes = null) {
        //Not used.  What is the purpose?
        // Values will be stored in this array
        $AcceptTypes = [];
        $accept = strtolower(str_replace(' ', '', $_SERVER['HTTP_ACCEPT']));
        $accept = explode(',', $accept);
        foreach ($accept as $a) {
            $q = 1;  // the default quality is 1.
            // check if there is a different quality
            if (strpos($a, ';q=')) {
                // divide "mime/type;q=X" into two parts: "mime/type" i "X"
                list($a, $q) = explode(';q=', $a);
            }
            // mime-type $a is accepted with the quality $q
            // WARNING: $q == 0 means, that mime-type isn’t supported!
            $AcceptTypes[$a] = $q;
        }
        arsort($AcceptTypes);

        // if no parameter was passed, just return parsed data
        if (!$mimeTypes) return $AcceptTypes;

        //If supported mime-type exists, return it, else return null
        $mimeTypes = array_map('strtolower', (array)$mimeTypes);
        foreach ($AcceptTypes as $mime => $q) {
            if ($q && in_array($mime, $mimeTypes)) return $mime;
        }
        return null;
    }

    private function getFileErrorMessage($code){
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
                $message = "The uploaded file exceeds the upload_max_filesize directive in php.ini";
                break;
            case UPLOAD_ERR_FORM_SIZE:
                $message = "The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form";
                break;
            case UPLOAD_ERR_PARTIAL:
                $message = "The uploaded file was only partially uploaded";
                break;
            case UPLOAD_ERR_NO_FILE:
                $message = "No file was uploaded";
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $message = "Missing a temporary folder";
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $message = "Failed to write file to disk";
                break;
            case UPLOAD_ERR_EXTENSION:
                $message = "File upload stopped by extension";
                break;

            default:
                $message = "Unknown upload error";
                break;
        }
        return $message;
    }

    public function logDebug(\GuzzleHttp\Psr7\Response $response):array {
        return [
            'body'=>(string) $response->getBody(),
            'status'=>$response->getStatusCode(),
            'headers'=>$response->getHeaders()
        ];
    }

    private function isSequencialArray($array){
        return (array_values($array) === $array);
    }

    private function isMultiArray($array){
        return count($array) !== count($array, COUNT_RECURSIVE);
        //If needing to detect empty array ['bla'=>[]], use the following
        foreach ($array as $v) {
            if (is_array($v)) return true;
        }
        return false;
    }
}