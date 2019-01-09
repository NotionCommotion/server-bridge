<?php
namespace Greenbean\ServerBridge;
class ServerBridge
{

    private $httpClient, $returnAsArray=true, $customMimeTypes, $standardMimeTypes=[
        'csv'=>'text/csv',
        'json'=>'application/json'
    ];

    public function __construct(\GuzzleHttp\Client $httpClient, array $customMimeTypes=[], bool $returnAsArray=true){
        $this->httpClient=$httpClient;  //Must be configured with default path
        $this->customMimeTypes=$customMimeTypes;
        $this->returnAsArray=$returnAsArray;
    }

    public function proxy(\Slim\Http\Request $slimRequest, \Slim\Http\Response $slimResponse):\Slim\Http\Response {
        //Forwards Slim Request to another server and returns the updated Slim Response.
        //TBD whether this method should change urlencoded body if provided to JSON and change Content-Type header.
        //TBD whether this method should change not send Slim request to Guzzle, but instead create a new Guzzle request and apply headers as applicable.
        $body=$slimRequest->getBody();
        if((string) $body) {
            //For unknown reasons, Guzzle requires thatt the content type be reapplied
            if(!$contentType=$slimRequest->getContentType()) {
                json_decode($slimRequest->getBody());
                $contentType=json_last_error()?'application/x-www-form-urlencoded;charset=utf-8':'application/json;charset=utf-8';
                assert(syslog(LOG_INFO, "ServerBridge::proxy() Content-Type not provided and changed to $contentType"));
            }
        }
        $slimRequest=empty($contentType)?
        $slimRequest->withUri($slimRequest->getUri()->withHost($this->getHost(false)))  //Change slim's host to API server!
        :$slimRequest->withUri($slimRequest->getUri()->withHost($this->getHost(false)))->withHeader('Content-Type', $contentType);  //And also apply Content-Type

        try {
            $guzzleResponse=$this->httpClient->send($slimRequest);
            //Blacklist headers which should not be changed.  TBD whether I should whitelist headers instead.
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
                return $slimResponse->withStatus($guzzleResponse->getStatusCode())->withBody($guzzleResponse->getBody());
            }
            else {
                return $slimResponse->withStatus(500)->write($this->getNonResponseErrorJson($e));
            }
        }
    }

    public function proxyRequest(string $method, string $path, array $data=[]):\GuzzleHttp\Psr7\Response {
        //Submits a single Guzzle Request and returns the Guzzle Response.
        if($data) {
            $data=[in_array(strtoupper($method), ['GET','DELETE'])?'query':'json'=>$data];
        }
        try {
            return $this->httpClient->send(new \GuzzleHttp\Psr7\Request($method, $path), $data);
        }
        catch (\GuzzleHttp\Exception\RequestException  $e) {
            return $e->hasResponse()
            ?$e->getResponse():
            new \GuzzleHttp\Psr7\Response(500, [], $this->getNonResponseErrorJson($e)); //untested
        }
    }

    public function callApi(string $method, string $path, array $data=[]) {
        //Submits a single Guzzle Request and returns the data, and throws an exception if not 2xx.
        //Will return stdObject or sequential array.
        if($data) {
            $data=[in_array(strtoupper($method), ['GET','DELETE'])?'query':'json'=>$data];
        }
        try {
            $body=$this->httpClient->send(new \GuzzleHttp\Psr7\Request($method, $path), $data)->getBody();
            $rs=json_decode($body);
            return json_last_error()?(string)$body:$rs;
        }
        catch (\GuzzleHttp\Exception\RequestException  $e) {
            if ($e->hasResponse()) {
                $guzzleResponse=$e->getResponse();
                $message=$this->getMessage($guzzleResponse->getBody());
                throw new ServerBridgeException($message, $guzzleResponse->getStatusCode(), $e);
            }
            else {
                throw new ServerBridgeException($e->getMessage(), 500, $e);
            }
        }
    }

    private function getNonResponseErrorJson(\Exception $e):string {
        return json_encode(['message'=>'RequestException without response: '.$this->getExceptionMessage($e)]);
    }

    private function getMessage(\GuzzleHttp\Psr7\Stream $body, $asArray=true) {
        //Returns message as a string if possible, else as an array (or JSON string if $asArray is false)
        $data=json_decode($body, true);
        if(json_last_error()) {
            return (string)$body;
        }
        elseif(count($data)===1 && ($error=array_intersect_key($data,array_flip(['message','error','errors']))) && is_string($msg = reset($data)) ) {
            return $msg;
        }
        return $asArray?$data:json_encode($data);   //return array or json string
    }

    public function getPageContent(array $requests, string $pathPrefix=''):array {
        /* Helper function which only supports GET requests and receives [ label => requestData, ...]
        where requestData will either be a string which is interpreted as a GET path
        or an array with the following structure: [string path, array data=[], mixed defaultResults=>[], bool returnAsArray=true]]
        and returns an array with the labels populated with the applicable data as well as potentially an error index.
        $pathPrefix will be applied to all paths.
        */
        $errors=[];
        foreach($requests as $label=>$request) {
            if(is_array($request)) {
                $returnAsArray=$request[3]??$this->returnAsArray;
                $defaultResults=$request[2]??[];
                $data=empty($request[1])?[]:['query'=>$request[1]];
                $request=new \GuzzleHttp\Psr7\Request('get', $pathPrefix.$request[0]);
            }
            else {
                $returnAsArray=$this->returnAsArray;
                $defaultResults=[];
                $data=[];
                $request=new \GuzzleHttp\Psr7\Request('get', $pathPrefix.$request);
            }

            try {
                $response=$this->httpClient->send($request, $data);
                $requests[$label]=json_decode($response->getBody(), $returnAsArray);
            }
            catch (\GuzzleHttp\Exception\RequestException  $e) {
                $requests[$label]=$defaultResults;
                $requests['errors'][$label]=$e->hasResponse()
                ?$this->getMessage($e->getResponse()->getBody(), false)
                :$e->getMessage();
            }
        }
        return $requests;
    }

    public function getMimeType(string $type):string {
        if(empty($type)) throw new ServerBridgeUncatchableException("Missing Accept value.");
        $a=array_merge($this->standardMimeTypes, $this->customMimeTypes);
        if(!isset($a[$type]))
            throw new ServerBridgeUncatchableException("Invalid Accept value: $type");
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
                throw new ServerBridgeUncatchableException('Invalid path: '.implode('=>', $this->httpClient->getConfig()));
            }
            $config=$config[$key];
        }
        return $config;
    }

    public function getHost(bool $includeSchema=true):string {
        $baseUri=$this->httpClient->getConfig()['base_uri'];
        return $includeSchema?$baseUri->getScheme().'://'.$baseUri->getHost():$baseUri->getHost();
    }

    private function processGuzzleException(\GuzzleHttp\Exception\RequestException $e):\GuzzleHttp\Psr7\Response {
        //Not used
        if ($e->hasResponse()) {
            $guzzleResponse=$e->getResponse();
            if(!$this->isJson($guzzleResponse)) {
                $body=$guzzleResponse->getBody();
                $data=json_decode($body);
                if(json_last_error()) {
                    assert(syslog(LOG_INFO, "ServerBridge::processGuzzleException() Content-Type not provided and changed to x-www-form-urlencoded"));
                    $guzzleResponse=$guzzleResponse
                    ->withBody(\GuzzleHttp\Psr7\stream_for(json_encode(['message'=>(string)$body])))
                    ->withHeader('Content-Type', 'application/x-www-form-urlencoded;charset=utf-8');
                }
                else {
                    //Was JSON but just didn't have the header
                    assert(syslog(LOG_INFO, "ServerBridge::proxy() Content-Type not provided and changed to application/json"));
                    $guzzleResponse=$guzzleResponse->withHeader('Content-Type', 'application/json;charset=utf-8');
                }
            }
        }
        else {
            $excErr=$this->getExceptionMessage($e);
            assert(syslog(LOG_ERR, "ServerBridge::processGuzzleException() RequestException without response: $excErr"));
            $guzzleResponse=new \GuzzleHttp\Psr7\Response(500, [], json_encode(['message'=>"RequestException without response: $excErr"]));    //Untested
        }
        return $guzzleResponse;
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