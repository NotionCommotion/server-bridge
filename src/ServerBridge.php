<?php
namespace Greenbean\ServerBridge;
class ServerBridge
{

    protected $httpClient;

    public function __construct(\GuzzleHttp\Client $httpClient){
        $this->httpClient=$httpClient;
    }

    public function proxy(\Slim\Http\Request $request, \Slim\Http\Response $response, \Closure $callback=null):\Slim\Http\Response {
        $method=$request->getMethod();
        $bodyParams=in_array($method,['PUT','POST'])?(array)$request->getParsedBody():[];   //Ignore body for GET and DELETE methods
        $queryParams=$request->getQueryParams();
        $data=array_merge($queryParams, $bodyParams);   ///Would be better to write slim's body to guzzle's body so that get parameters are preserved and not overriden by body parameters.
        $path=$request->getUri()->getPath();
        $contentType=$request->getContentType();
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
            if($errors) return $response->withJson($errors, 422);
            $multiparts[]=[
                'name'=> 'data',
                'contents' => json_encode($data),
                'headers'  => ['Content-Type' => 'application/json']
            ];
            $options=['multipart' => $multiparts];
        }
        else {
            $options = $data?(in_array($method,['PUT','POST'])?['json'=>$data]:['query'=>$data]):[];
        }
        try {
            $curlResponse = $this->httpClient->request($method, $path, $options);
            $contentType=$curlResponse->getHeader('Content-Type');
            $statusCode=$curlResponse->getStatusCode();
            if(count($contentType)!==1) {
                syslog(LOG_ERR, 'contentType: '.json_encode($contentType));
                throw new ServerBridgeException("Multiple contentTypes???: ".json_encode($contentType));
            }
            switch($contentType[0]) {
                case "application/json;charset=utf-8":
                    //Application and server error messages will be returned.  Consider hiding server errors.
                    $content=json_decode($curlResponse->getBody());
                    if($callback) {
                        $content=$callback($content);
                    }
                    return $response->withJson($content, $statusCode);
                case 'text/html': case 'text/plain':   //Change to full name?
                    if($callback) throw new ServerBridgeException('Callback can only be used with contentType application/json');
                    //Application and server error messages will be returned.  Consider hiding server errors.
                    $response = $response->withStatus($statusCode);
                    return $response->getBody()->write($curlResponse->getBody());
                case 'application/octet-stream':
                    if($callback) throw new ServerBridgeException('Callback can only be used with contentType application/json');
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
                        ->withBody($curlResponse->getBody());
                    }
                    else {
                        return $response->withJson(json_decode($curlResponse->getBody()), $statusCode);
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
                return $response->withJson(json_decode($curlResponse->getBody()), $curlResponse->getStatusCode());
            }
            else {
                return $response->withJson($e->getMessage(), $e->getMessage());
            }
        }
    }

    public function callApi(\GuzzleHttp\Psr7\Request $request, array $data=[]):\GuzzleHttp\Psr7\Response {
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
                $response=new \GuzzleHttp\Psr7\Response($e->getCode(), [], $e->getMessage());
            }
        }
        return $response;
    }

    public function getPageContent(array $requests):array {
        $errors=[];
        foreach($requests as $name=>$request) {
            if(is_array($request)) {
                //[\GuzzleHttp\Psr7\Request $request, array $data=[], array $options=[]] where options: int expectedCode, mixed $defaultResults, bool returnAsArray
                $defaultResults=$request[2]['defaultResults']??[];
                $expectedCode=$request[2]['expectedCode']??200;
                $returnAsArray=$request[2]['returnAsArray']??true;
                $data=$request[1]??[];
                $request=$request[0];
            }
            elseif ($request instanceof \GuzzleHttp\Psr7\Request) {
                $defaultResults=[];
                $expectedCode=200;
                $returnAsArray=true;
                $data=[];
            }
            else throw new ServerBridgeException("Invalid request to getPageContent: $name => ".json_encode($request));
            $response=$this->callApi($request, $data);
            $body=json_decode($response->getBody(), $returnAsArray);
            if($response->getStatusCode()===$expectedCode) {
                $requests[$name]=$body;
            }
            else {
                $requests[$name]=$defaultResults;
                $errors[]=$returnAsArray?"$name: $body[message]":"$name: $body->message";
            }
        }
        if($errors) $requests['errors']=$errors;
        return $requests;
    }

    public function getConfig():array {
        return $this->httpClient->getConfig();
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