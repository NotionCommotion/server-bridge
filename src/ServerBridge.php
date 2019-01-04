<?php
namespace Greenbean\ServerBridge;
class ServerBridge
{

    private $httpClient, $returnAsArray=true, $debug=false, $debugAsJson=true, $customMimeTypes, $standardMimeTypes=[
        'csv'=>'text/csv',
        'json'=>'application/json'
    ];

    public function __construct(\GuzzleHttp\Client $httpClient, array $customMimeTypes=[], array $options=[]){
        //Options: returnAsArray: true as array, false as stdClass, debug: true/false, debugJson: true as json, false as var_dump
        $this->httpClient=$httpClient;  //Must be configured with default path
        $this->customMimeTypes=$customMimeTypes;
        if($extra=array_diff_key($options, array_flip(['returnAsArray', 'debug', 'debugAsJson']))) {
            throw new ServerBridgeException('Following options are not allowed: '.implode(', ', $extra));
        }
        foreach($options as $name=>$value) {
            $this->$name=$value;
        }
    }

    public function proxy(\Slim\Http\Request $slimRequest, \Slim\Http\Response $slimResponse):\Slim\Http\Response {
        //Forwards Slim Request to another server and returns the updated Slim Response.
        //TBD whether this method should change urlencoded body if provided to JSON and change Content-Type header.
        //TBD whether this method should change not send Slim request to Guzzle, but instead create a new Guzzle request and apply headers as applicable.
        if($this->debug) $this->debugRequest($slimRequest, 'proxy() initial Slim');
        $body=$slimRequest->getBody();
        if((string) $body) {
            //For unknown reasons, Guzzle requires thatt the content type be reapplied
            if(!$contentType=$slimRequest->getContentType()) {
                json_decode($slimRequest->getBody());
                $contentType=json_last_error()?'application/x-www-form-urlencoded;charset=utf-8':'application/json;charset=utf-8';
                if($this->debug) syslog(LOG_INFO, "ServerBridge::proxy() Content-Type not provided and changed to $contentType");
            }
        }
        $slimRequest=empty($contentType)?
        $slimRequest->withUri($slimRequest->getUri()->withHost($this->getHost(false)))  //Change slim's host to API server!
        :$slimRequest->withUri($slimRequest->getUri()->withHost($this->getHost(false)))->withHeader('Content-Type', $contentType);  //And also apply Content-Type
        if($this->debug) $this->debugRequest($slimRequest, 'proxy() final Slim');

        try {
            $guzzleResponse=$this->httpClient->send($slimRequest);
            if($this->debug) $this->debugResponse($guzzleResponse, 'proxy() Guzzle');
            //Blacklist headers which should not be changed.  TBD whether I should whitelist headers instead.
            $excludedHeaders=['Date', 'Server', 'X-Powered-By', 'Access-Control-Allow-Origin', 'Access-Control-Allow-Methods', 'Access-Control-Allow-Headers'];
            $headerArrays=array_diff_key($guzzleResponse->getHeaders(), array_flip($excludedHeaders));
            foreach($headerArrays as $headerName=>$headers) {
                foreach($headers as $headerValue) {
                    $slimResponse=$slimResponse->withHeader($headerName, $headerValue);
                }
            }
            $slimResponse=$slimResponse->withStatus($guzzleResponse->getStatusCode())->withBody($guzzleResponse->getBody());
        }
        catch (\GuzzleHttp\Exception\RequestException  $e) {
            if ($e->hasResponse()) {
                $guzzleResponse=$e->getResponse();
                if($this->isJson($guzzleResponse)) {
                    $slimResponse=$slimResponse->withStatus($guzzleResponse->getStatusCode())->withBody($guzzleResponse->getBody());
                }
                else {
                    $slimResponse=$slimResponse->withStatus($guzzleResponse->getStatusCode())->write(json_encode(['message'=>(string)$guzzleResponse->getBody()]));
                }
            }
            else {
                $slimResponse=$slimResponse->withStatus(500)->write(json_encode(['message'=>"RequestException without response: {$this->getExceptionMessage($e)}"]));
            }
        }
        if($this->debug) $this->debugResponse($slimResponse, 'proxy() Slim');
        return $slimResponse;
    }

    public function callApi(\GuzzleHttp\Psr7\Request $guzzleRequest, array $data=[]):\GuzzleHttp\Psr7\Response {
        //Submits a single Guzzle Request and returns the Guzzle Response.
        if($data) {
            $data=[in_array($guzzleRequest->getMethod(), ['GET','DELETE'])?'query':'json'=>$data];
        }
        try {
            if($this->debug) $this->debugRequest($guzzleRequest, 'callApi() Guzzle', $data);
            $guzzleResponse=$this->httpClient->send($guzzleRequest, $data);
        }
        catch (\GuzzleHttp\Exception\RequestException  $e) {
            if ($e->hasResponse()) {
                $guzzleResponse=$e->getResponse();
                if(!$this->isJson($guzzleResponse)) {
                    $body=$guzzleResponse->getBody();
                    $data=json_decode($body);
                    if(json_last_error()) {
                        if($this->debug) syslog(LOG_INFO, "ServerBridge::callApi() Content-Type not provided and changed to x-www-form-urlencoded");
                        $guzzleResponse=$guzzleResponse
                        ->withBody(\GuzzleHttp\Psr7\stream_for(json_encode(['message'=>(string)$body])))
                        ->withHeader('Content-Type', 'application/x-www-form-urlencoded;charset=utf-8');
                    }
                    else {
                        //Was JSON but just didn't have the header
                        if($this->debug) syslog(LOG_INFO, "ServerBridge::proxy() Content-Type not provided and changed to application/json");
                        $guzzleResponse=$guzzleResponse->withHeader('Content-Type', 'application/json;charset=utf-8');
                    }
                }
            }
            else {
                $excErr=$this->getExceptionMessage($e);
                if($this->debug) syslog(LOG_ERR, "ServerBridge::callApi() RequestException without response: $excErr");
                $guzzleResponse=new \GuzzleHttp\Psr7\Response(500, [], json_encode(['message'=>"RequestException without response: $excErr"]));    //Untested
            }
        }
        if($this->debug) $this->debugResponse($guzzleResponse, 'callApi() Guzzle');
        return $guzzleResponse;
    }

    public function getPageContent(array $pageItems):array {
        //Helper function which receives multiple Guzzle Requests which will populate a given webpage.
        //Each element in the array will either be a Guzzle Request or an array with a Guzzle Request and other options.
        $errors=[];
        foreach($pageItems as $name=>$guzzleRequest) {
            if(is_array($guzzleRequest)) {
                //[\GuzzleHttp\Psr7\Request $guzzleRequest, array $data=[], array $options=[], \Closure $callback=null, \Closure $errorCallback=null] where options: int expectedCode, mixed $defaultResults, bool returnAsArray
                $callback=$guzzleRequest[3]??false;
                $defaultResults=$guzzleRequest[2]['defaultResults']??[];
                $expectedCode=$guzzleRequest[2]['expectedCode']??200;
                $returnAsArray=$guzzleRequest[2]['returnAsArray']??$this->returnAsArray;
                $data=$guzzleRequest[1]??[];
                $guzzleRequest=$guzzleRequest[0];
            }
            elseif ($guzzleRequest instanceof \GuzzleHttp\Psr7\Request) {
                $callback=false;
                $defaultResults=[];
                $expectedCode=200;
                $returnAsArray=$this->returnAsArray;
                $data=[];
            }
            else {
                throw new ServerBridgeException("Invalid request to getPageContent: $name => ".json_encode($guzzleRequest));
            }

            $guzzleResponse=$this->callApi($guzzleRequest, $data);
            if($guzzleResponse->getStatusCode()===$expectedCode) {
                $data=json_decode($guzzleResponse->getBody(), $returnAsArray);
                if($callback) {
                    $data=$callback($data);
                }
                $pageItems[$name]=$data;
            }
            else {
                $data=json_decode($guzzleResponse->getBody());
                $pageItems[$name]=$defaultResults;
                $errors[$name]=$data->message;
            }
        }
        if($errors) {
            foreach($errors as $name=>$error) {
                $pageItems['errors'][]="$name: $error";
            }
        }
        return $pageItems;
    }

    public function getMimeType(string $type):string {
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

    private function debugRequest($psr7Request, string $label=null, array $data=[]) {
        $label='ServerBridge Debug'.($label?" $label ":' ').'Request';
        $arr=[
            'method'=>$psr7Request->getMethod(),
            'uri'=>(string) $psr7Request->getUri(),
            'path'=>$psr7Request->getUri()->getPath(),
            'query'=>$psr7Request->getUri()->getQuery(),
            'headers'=>$psr7Request->getHeaders(),
            'body'=>(string) $psr7Request->getBody(),
            'data'=>$data
        ];
        $this->debugLog($arr, $label);
    }
    private function debugResponse($psr7Response, string $label=null) {
        $label='ServerBridge Debug'.($label?" $label ":' ').'Response';
        $arr=[
            'statusCode'=>$psr7Response->getStatusCode(),
            'getReasonPhrase'=>$psr7Response->getReasonPhrase(),
            'statusCode'=>$psr7Response->getStatusCode(),
            'getProtocolVersion'=>$psr7Response->getProtocolVersion(),
            'headers'=>$psr7Response->getHeaders(),
            'body'=>(string) $psr7Response->getBody(),
        ];
        $this->debugLog($arr, $label);
    }
    private function debugLog(array $a, string $label) {
        if($this->debugAsJson) {
            if($a['body']) {
                $body=json_decode($a['body']);
                if(json_last_error()===0) {
                    $a['body']=$body;
                    $a['contentType']='application/json';
                }
                else {
                    $a['body']=urldecode($a['body']);
                    $a['contentType']='application/x-www-form-urlencoded';
                }
            }
            else {
                $a['contentType']=null;
            }
            $results=json_encode($a);
            $results=str_replace('\\/','/',$results);
            //$results=str_replace('\\"','"',$results);
        }
        else {
            ob_start();
            var_dump($a);
            $results=ob_get_clean();
        }
        $results=$label.': '.$results;
        syslog(LOG_INFO, $results);
        return $results;
    }

    private function getHeaderName(string $name):string {
        //Not currently used.
        $parts=explode('_', $name);
        foreach($parts as &$part) $part=ucfirst(strtolower($part));
        return implode('-',$parts);
    }

    private function isJson(\GuzzleHttp\Psr7\Response $guzzleResponse):bool {
        $contentType=$guzzleResponse->getHeader('Content-Type');
        return in_array('application/json;charset=utf-8', $contentType)||in_array('application/json', $contentType);
    }

    private function getExceptionMessage(\Exception $e) : string {
        return $e->getMessage().' ('.$e->getCode().')';
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

    private function getFileErrorMessage(int $code):string{
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

    private function isSequencialArray(array $array):bool{
        return (array_values($array) === $array);
    }

    private function isMultiArray(array $array):bool{
        return count($array) !== count($array, COUNT_RECURSIVE);
        //If needing to detect empty array ['bla'=>[]], use the following
        foreach ($array as $v) {
            if (is_array($v)) return true;
        }
        return false;
    }
}