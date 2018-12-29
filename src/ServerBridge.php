<?php
namespace Greenbean\ServerBridge;
class ServerBridge
{

    private $httpClient, $returnAsArray;

    public function __construct(\GuzzleHttp\Client $httpClient, bool $returnAsArray=true){
        $this->httpClient=$httpClient;  //Must be configured with default path
        $this->returnAsArray=$returnAsArray;
    }

    public function proxy(\Slim\Http\Request $httpRequest, \Slim\Http\Response $httpResponse, \Closure $callback=null):\Slim\Http\Response {
        //Forwards Slim Request to another server and returns the updated Slim Response.
        $method=$httpRequest->getMethod();
        $bodyParams=in_array($method,['PUT','POST'])?$httpRequest->getParsedBody():[];   //Ignore body for GET and DELETE methods?
        $contentType=$httpRequest->getContentType();
        $options=[];

        if(substr($contentType, 0, 19)==='multipart/form-data'){
            //Support uploading a file.
            $files = $httpRequest->getUploadedFiles();
            $multiparts=[];
            $errors=[];
            foreach($files as $name=>$file) {
                if ($error=$file->getError()) {
                    $errors[]=[
                        'name'=> $name,
                        'filename'=> $file->getClientFilename(),
                        'error' => $this->getFileErrorMessage($error)
                    ];
                }
                else {
                    $multiparts[]=[
                        'name'=> $name,
                        'filename'=> $file->getClientFilename(),
                        'contents' => $file->getStream(),
                        'headers'  => [
                            //'Size' => $file->getSize(),   //Not needed, right?
                            'Content-Type' => $file->getClientMediaType()
                        ]
                    ];
                }
            }
            if($errors) return $httpResponse->withJson(['message'=>implode(', ', $errors)], 422);
            $multiparts[]=[
                'name'=> 'data',
                'contents' => json_encode($bodyParams),
                'headers'  => ['Content-Type' => 'application/json']
            ];
            $options['multipart']=$multiparts;
        }
        elseif($bodyParams) {
            $options['json']=$bodyParams;   //Will be an array
        }

        if($queryParams=$httpRequest->getQueryParams()) {
            $options['query']=$queryParams;
        }

        $path=$httpRequest->getUri()->getPath();
        try {
            $curlResponse = $this->httpClient->request($method, $path, $options);
            $contentType=$curlResponse->getHeader('Content-Type');
            if(count($contentType)>1) {
                syslog(LOG_ERR, 'contentType: '.json_encode($contentType));
                throw new ServerBridgeException("Multiple contentTypes???: ".json_encode($contentType));
            }
            $contentType=explode(';', $contentType[0]);
            $contentEncoding=trim($contentType[1]??''); //Should anything be done with this?
            $contentType=trim($contentType[0]);
            $statusCode=$curlResponse->getStatusCode();
            $body=$curlResponse->getBody();
            switch($contentType) {
                case "application/json":
                    //Application and server error messages will be returned.  Consider hiding server errors.
                    $content=json_decode($body, $this->returnAsArray);
                    if($callback) {
                        $content=$callback($content);
                    }
                    return $httpResponse->withJson($content, $statusCode);
                case 'text/html':
                    if($callback) throw new ServerBridgeException('Callback can only be used with contentType application/json and text/plain');
                case 'text/plain':
                    //Application and server error messages will be returned.  Consider hiding server errors.
                    if($callback) {
                        $body=$callback($body);
                    }
                    $httpResponse = $httpResponse->withStatus($statusCode);
                    return $httpResponse->getBody()->write($body);
                case 'application/octet-stream':
                    if($callback) throw new ServerBridgeException('Callback can only be used with contentType application/json and text/plain');
                    if($statusCode===200) {
                        return $httpResponse
                        ->withHeader('Content-Type', $contentType)                                                      //application/octet-stream
                        ->withHeader('Content-Description', $curlResponse->getHeader('Content-Description'))            //File Transfer
                        ->withHeader('Content-Transfer-Encoding', $curlResponse->getHeader('Content-Transfer-Encoding'))//binary
                        ->withHeader('Content-Disposition', $curlResponse->getHeader('Content-Disposition'))            //"attachment; filename='filename.ext'"
                        ->withHeader('Expires', $curlResponse->getHeader('Expires'))                                    //0
                        ->withHeader('Cache-Control', $curlResponse->getHeader('Cache-Control'))                        //must-revalidate, post-check=0, pre-check=0
                        ->withHeader('Pragma', $curlResponse->getHeader('Pragma'))                                      //public
                        //->withHeader('Content-Type', 'application/force-download')                                    //Confirm not desired
                        //->withHeader('Content-Type', 'application/download')                                          //Confirm not desired
                        //->withHeader('Content-Length', null)                                                          //Confirm not desired
                        //->withHeader('Some-Other-Header', 'foo')                                                      //Confirm no other headers needed
                        ->withBody($body);
                    }
                    else {
                        return $httpResponse->withJson(json_decode($body, false), $statusCode);
                    }
                    break;
                case 'application/xml':
                    throw new ServerBridgeException("$contentType proxy contentType is not yet implemented");
                default: throw new ServerBridgeException("Invalid proxy contentType: $contentType");
            }
        }
        catch (\GuzzleHttp\Exception\RequestException  $e) {
            //Errors only return JSON
            //Networking error which includes ConnectException and TooManyRedirectsException
            syslog(LOG_ERR, 'Proxy error: '.$e->getMessage());
            if ($e->hasResponse()) {
                $curlResponse=$e->getResponse();
                return $httpResponse->withJson(json_decode($curlResponse->getBody(), false), $curlResponse->getStatusCode());
            }
            else {
                return $httpResponse->withJson($e->getMessage(), $e->getMessage());
            }
        }
    }

    public function callApi(\GuzzleHttp\Psr7\Request $curlRequest, array $data=[], array $bodyQuery=[]):\GuzzleHttp\Psr7\Response {
        //Submits a single Guzzle Request and returns the Guzzle Response.
        //$bodyQuery only needed for requests with data in both the body and url, and will likely be never used.
        try {
            if($data) {
                if($this->isSequencialArray($data)) {
                    throw new ServerBridgeException('getPageContent(): Invalid data. Must be an associated array');
                }
                if(in_array($curlRequest->getMethod(), ['GET','DELETE'])){
                    //$data=['query'=>$this->isMultiArray($data)?http_build_query($data):$data];
                    $data=['query'=>$data];
                }
                else {
                    //Will not work with files?
                    $data=['json'=>$data];
                    if($bodyQuery) $data['query']=$bodyQuery;
                }
            }
            $curlResponse = $this->httpClient->send($curlRequest, $data);
        }
        catch (\GuzzleHttp\Exception\ClientException $e) {
            $curlResponse=$e->getResponse();
        }
        catch (\GuzzleHttp\Exception\ServerException $e) {
            //Consider not including all information back to client
            $curlResponse=$e->getResponse();
        }
        catch (\GuzzleHttp\Exception\RequestException  $e) {
            //Networking error which includes ConnectException and TooManyRedirectsException
            if ($e->hasResponse()) {
                $curlResponse=$e->getResponse();
            }
            else {
                //Untested
                $curlResponse=new \GuzzleHttp\Psr7\Response($e->getCode(), [], $e->getMessage());
            }
        }
        return $curlResponse;
    }

    public function getPageContent(array $curlRequests):array {
        //Helper function which receives multiple Guzzle Requests which will populate a given webpage.
        $errors=[];
        $curlResponses=[];
        foreach($curlRequests as $name=>$curlRequest) {
            if(is_array($curlRequest)) {
                //[\GuzzleHttp\Psr7\Request $curlRequest, array $data=[], array $options=[], \Closure $callback=null] where options: int expectedCode, mixed $defaultResults, bool returnAsArray
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
                $curlResponses[$name]=$defaultResults;
                $errors[$name]=$returnAsArray?"$name: $body[message]":"$name: $body->message";
            }
        }
        if($errors) $curlResponses['errors']=$errors;
        return $curlResponses;
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