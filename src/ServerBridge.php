<?php
namespace Greenbean\ServerBridge;
class ServerBridge
{

    private $httpClient, $returnAsArray, $customMimeTypes, $standardMimeTypes=[
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
        $body=$slimRequest->getBody();
        if((string) $body && !$contentType=$slimRequest->getMediaType()) {
            json_decode($slimRequest->getBody());
            //Guzzle doesn't appear to like the full content type which includes: charset=utf-8
            $contentType=json_last_error()?'application/x-www-form-urlencoded':'application/json';
        }

        $slimRequest=$slimRequest->withUri($slimRequest->getUri()->withHost($this->getHost(false)));  //Change slim's host to API server!
        try {
            /*
            //If desired, can change body to JSON and change Content-Type
            if (($contentType=$slimRequest->getMediaType()) && $contentType!=='application/json') {
            //Change body to JSON if not currently done
            $slimRequest->getBody()->write(json_encode($slimRequest->getParsedBody()));
            $slimRequest->reparseBody();
            $slimRequest=$slimRequest->withHeader('Content-Type', 'application/json;charset=utf-8');
            $slimRequest->reparseBody();
            syslog(LOG_INFO, 'string2: '.(string) $slimRequest->getBody());
            }
            Or maybe make a new guzzleRequest?
            $guzzleRequest=(new \GuzzleHttp\Psr7\Request($slimRequest->getMethod(), $slimRequest->getUri()->getPath()))->withBody($slimRequest->getBody());
            $headers=array_intersect_key($slimRequest->getHeaders(), array_flip(['CONTENT_LENGTH', 'CONTENT_TYPE']));
            foreach($headers as $name=>$value) {
            $guzzleRequest=$guzzleRequest->withHeader($this->getHeaderName($name), $value);
            }
            syslog(LOG_INFO, 'proxy $guzzleRequest->getHeaders(): '.json_encode($guzzleRequest->getHeaders()));
            $guzzleResponse=$this->httpClient->send($slimRequest);
            */
            $guzzleResponse=$this->httpClient->send(isset($contentType)?$slimRequest->withHeader('Content-Type', $contentType):$slimRequest);
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
                if($this->isJson($guzzleResponse)) {
                    return $slimResponse->withStatus($guzzleResponse->getStatusCode())->withBody($guzzleResponse->getBody());
                }
                else {
                    return $slimResponse->withStatus($guzzleResponse->getStatusCode())->write(json_encode(['message'=>(string)$guzzleResponse->getBody()]));
                }
            }
            else {
                return $slimResponse->withStatus(500)->write(json_encode(['message'=>"RequestException without response: {$this->getExceptionMessage($e)}"]));
            }
        }
    }

    public function callApi(\GuzzleHttp\Psr7\Request $guzzleRequest, array $data=[]):\GuzzleHttp\Psr7\Response {
        //Submits a single Guzzle Request and returns the Guzzle Response.
        if($data) {
            $data=[in_array($guzzleRequest->getMethod(), ['GET','DELETE'])?'query':'json'=>$data];
        }
        try {
            return $this->httpClient->send($guzzleRequest, $data);
        }
        catch (\GuzzleHttp\Exception\RequestException  $e) {
            if ($e->hasResponse()) {
                $guzzleResponse=$e->getResponse();
                if($this->isJson($guzzleResponse)) {
                    return $guzzleResponse;
                }
                else {
                    return $guzzleResponse->withBody(\GuzzleHttp\Psr7\stream_for(json_encode(['message'=>(string)$guzzleResponse->getBody()])));
                }
            }
            else {
                syslog(LOG_ERR, "ServerBridge::callApi() RequestException without response: {$this->getExceptionMessage($e)}");
                return new \GuzzleHttp\Psr7\Response(500, [], json_encode(['message'=>"RequestException without response: {$this->getExceptionMessage($e)}"]));    //Untested
            }
        }
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

    public function debug(\GuzzleHttp\Psr7\Response $response):array {
        return [
            'body'=>(string) $response->getBody(),
            'status'=>$response->getStatusCode(),
            'headers'=>$response->getHeaders()
        ];
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