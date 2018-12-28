<?php
namespace Greenbean\ServerBridge;
class ServerBridge
{

    private $httpClient, $returnAsArray;

    public function __construct(\GuzzleHttp\Client $httpClient, bool $returnAsArray=false){
        $this->httpClient=$httpClient;
        $this->returnAsArray=$returnAsArray;
    }

    public function proxy(\Slim\Http\Request $request, \Slim\Http\Response $response, \Closure $callback=null):\Slim\Http\Response {
        $method=$request->getMethod();
        $bodyParams=in_array($method,['PUT','POST'])?$request->getParsedBody():[];   //Ignore body for GET and DELETE methods?
        $contentType=$request->getContentType();
        $options=[];

        if(substr($contentType, 0, 19)==='multipart/form-data'){
            //Support uploading a file.
            $files = $request->getUploadedFiles();
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
            if($errors) return $response->withJson(['message'=>implode(', ', $errors)], 422);
            $multiparts[]=[
                'name'=> 'data',
                'contents' => json_encode($bodyParams),
                'headers'  => ['Content-Type' => 'application/json']
            ];
            $options['multipart']=$multiparts;
        }
        elseif($bodyParams) {
            $options['json']=$bodyParams;
        }

        if($queryParams=$request->getQueryParams()) {
            $options['query']=$queryParams;
        }

        $path=$request->getUri()->getPath();
        try {
            $curlResponse = $this->httpClient->request($method, $path, $options);
            $contentType=$curlResponse->getHeader('Content-Type');
            $statusCode=$curlResponse->getStatusCode();
            $body=$curlResponse->getBody();
            if(count($contentType)!==1) {
                syslog(LOG_ERR, 'contentType: '.json_encode($contentType));
                throw new ServerBridgeException("Multiple contentTypes???: ".json_encode($contentType));
            }
            switch($contentType[0]) {
                case "application/json;charset=utf-8":
                    //Application and server error messages will be returned.  Consider hiding server errors.
                    $content=json_decode($body, $this->returnAsArray);
                    if($callback) {
                        $content=$callback($content);
                    }
                    return $response->withJson($content, $statusCode);
                case 'text/html':
                    if($callback) throw new ServerBridgeException('Callback can only be used with contentType application/json and text/plain');
                case 'text/plain':   //Change to full name?
                    //Application and server error messages will be returned.  Consider hiding server errors.
                    if($callback) {
                        $body=$callback($body);
                    }
                    $response = $response->withStatus($statusCode);
                    return $response->getBody()->write($body);
                case 'application/octet-stream':
                    if($callback) throw new ServerBridgeException('Callback can only be used with contentType application/json and text/plain');
                    if($statusCode===200) {
                        return $response
                        ->withHeader('Content-Type', $contentType)                                                      //application/octet-stream
                        ->withHeader('Content-Description', $curlResponse->getHeader('Content-Description'))            //File Transfer
                        ->withHeader('Content-Transfer-Encoding', $curlResponse->getHeader('Content-Transfer-Encoding'))//binary
                        ->withHeader('Content-Disposition', $curlResponse->getHeader('Content-Disposition'))            //"attachment; filename='filename.ext'"
                        ->withHeader('Expires', $curlResponse->getHeader('Expires'))                                    //0
                        ->withHeader('Cache-Control', $curlResponse->getHeader('Cache-Control'))                        //must-revalidate, post-check=0, pre-check=0
                        ->withHeader('Pragma', $curlResponse->getHeader('Pragma'))                                      //public
                        //->withHeader('Content-Type', 'application/force-download')
                        //->withHeader('Content-Type', 'application/download')
                        //->withHeader('Content-Length', null)
                        ->withBody($body);
                    }
                    else {
                        return $response->withJson(json_decode($body, false), $statusCode);
                    }
                    break;
                default: throw new ServerBridgeException("Invalid proxy contentType: $contentType");
            }
        }
        catch (\GuzzleHttp\Exception\RequestException  $e) {
            //Errors only return JSON
            //Networking error which includes ConnectException and TooManyRedirectsException
            syslog(LOG_ERR, 'Proxy error: '.$e->getMessage());
            if ($e->hasResponse()) {
                $curlResponse=$e->getResponse();
                return $response->withJson(json_decode($curlResponse->getBody(), false), $curlResponse->getStatusCode());
            }
            else {
                return $response->withJson($e->getMessage(), $e->getMessage());
            }
        }
    }

    public function callApi(\GuzzleHttp\Psr7\Request $request, array $data=[], array $bodyQuery=[]):\GuzzleHttp\Psr7\Response {
        try {
            if($data) {
                if($this->isSequencialArray($data)) {
                    throw new ServerBridgeException('getPageContent(): Invalid data. Must be an associated array');
                }
                if(in_array($request->getMethod(), ['GET','DELETE'])){
                    //$data=['query'=>$this->isMultiArray($data)?http_build_query($data):$data];
                    $data=['query'=>$data];
                }
                else {
                    //Will not work with files?
                    $data=['json'=>$data];
                    if($bodyQuery) $data['query']=$bodyQuery;
                }
            }
            $response = $this->httpClient->send($request, $data);
        }
        catch (\GuzzleHttp\Exception\ClientException $e) {
            $response=$e->getResponse();
        }
        catch (\GuzzleHttp\Exception\ServerException $e) {
            //Consider not including all information back to client
            $response=$e->getResponse();
        }
        catch (\GuzzleHttp\Exception\RequestException  $e) {
            //Networking error which includes ConnectException and TooManyRedirectsException
            if ($e->hasResponse()) {
                $response=$e->getResponse();
            }
            else {
                //Untested
                $response=new \GuzzleHttp\Psr7\Response($e->getCode(), [], $e->getMessage());
            }
        }
        return $response;
    }

    public function getPageContent(array $requests):array {
        $errors=[];
        foreach($requests as $name=>$request) {
            if(is_array($request)) {
                //[\GuzzleHttp\Psr7\Request $request, array $data=[], array $options=[], \Closure $callback=null] where options: int expectedCode, mixed $defaultResults, bool returnAsArray
                $callback=$request[3]??false;
                $defaultResults=$request[2]['defaultResults']??[];
                $expectedCode=$request[2]['expectedCode']??200;
                $returnAsArray=$request[2]['returnAsArray']??$this->returnAsArray;
                $data=$request[1]??[];
                $request=$request[0];
            }
            elseif ($request instanceof \GuzzleHttp\Psr7\Request) {
                $callback=false;
                $defaultResults=[];
                $expectedCode=200;
                $returnAsArray=$this->returnAsArray;
                $data=[];
            }
            else throw new ServerBridgeException("Invalid request to getPageContent: $name => ".json_encode($request));
            $response=$this->callApi($request, $data);
            $body=json_decode($response->getBody(), $returnAsArray);
            if($response->getStatusCode()===$expectedCode) {
                if($callback) {
                    $body=$callback($body);
                }
                $requests[$name]=$body;
            }
            else {
                $requests[$name]=$defaultResults;
                $errors[$name]=$returnAsArray?"$name: $body[message]":"$name: $body->message";
            }
        }
        if($errors) $requests['errors']=$errors;
        return $requests;
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