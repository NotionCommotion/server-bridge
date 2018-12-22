<?php
namespace Greenbean\ServerBridge;
class ServerBridge
{

    protected $httpClient, $contentType;

    public function __construct(\GuzzleHttp\Client $httpClient, string $contentType='application/json')
    {
        $this->httpClient=$httpClient;
        $this->contentType=$contentType;
    }

    public function proxy(\Slim\Http\Request $request, \Slim\Http\Response $response, string $contentType=null, \Closure $callback=null):\Slim\Http\Response {
        $contentType=$contentType??$this->contentType;
        if($contentType!=='application/json' && $callback) {
            throw new ServerBridgeException('Callback can only be used with contentType application/json');
        }
        $method=$request->getMethod();
        $bodyParams=in_array($method,['PUT','POST'])?(array)$request->getParsedBody():[];   //Ignore body for GET and DELETE methods
        $queryParams=$request->getQueryParams();
        $data=array_merge($queryParams, $bodyParams);   ///Would be better to write slim's body to guzzle's body so that get parameters are preserved and not overriden by body parameters.
        $path=$request->getUri()->getPath();
        $contentTypeHeader=$request->getContentType();
        if(substr($contentTypeHeader, 0, 19)==='multipart/form-data'){
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
                            //Not needed, right? 'Size' => $file->getSize(),
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
            $options = in_array($method,['PUT','POST'])?['json'=>$data]:['query'=>$data];
        }
        try {
            $curlResponse = $this->httpClient->request($method, $path, $options);
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

        $statusCode=$curlResponse->getStatusCode();
        switch($contentType) {
            case 'application/json':
                //Application and server error messages will be returned.  Consider hiding server errors.
                $content=json_decode($curlResponse->getBody());
                if($callback) {
                    $content=$callback($content, $statusCode);
                }
                return $response->withJson($content, $statusCode);
            case 'text/html':
            case 'text/plain':
                //Application and server error messages will be returned.  Consider hiding server errors.
                $response = $response->withStatus($statusCode);
                return $response->getBody()->write($curlResponse->getBody());
            case 'text/csv':
                if($statusCode===200) {
                    return $response->withHeader('Content-Type', 'application/force-download')
                    ->withHeader('Content-Type', 'application/octet-stream')
                    ->withHeader('Content-Type', 'application/download')
                    ->withHeader('Content-Description', 'File Transfer')
                    ->withHeader('Content-Transfer-Encoding', 'binary')
                    ->withHeader('Content-Disposition', 'attachment; filename="data.csv"')
                    ->withHeader('Expires', '0')
                    ->withHeader('Cache-Control', 'must-revalidate, post-check=0, pre-check=0')
                    ->withHeader('Pragma', 'public')
                    ->withBody($curlResponse->getBody());
                }
                else {
                    return $response->withJson(json_decode($curlResponse->getBody()), $statusCode);
                }
                break;
            default: throw new ServerBridgeException("Invalid proxy contentType: $contentType");
        }
    }

    public function callApi(\GuzzleHttp\Psr7\Request $request, array $data=[]):\GuzzleHttp\Psr7\Response {
        try {
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
                //[\GuzzleHttp\Psr7\Request $request, array $data, int $expectedCode=200, $defaultResults]
                $defaultResults=$request[3]??[];
                $expectedCode=$request[2]??200;
                $data=$request[1]??[];
                $request=$request[0];
            }
            else {
                $defaultResults=[];
                $expectedCode=200;
                $data=[];
            }
            $response=$this->callApi($request, $data);
            $body=json_decode($response->getBody());
            if($response->getStatusCode()===$expectedCode) {
                $requests[$name]=$body;
            }
            else {
                $requests[$name]=$defaultResults;
                $errors[]="$name: $body->message";
            }
        }
        if($errors) $requests['errors']=$errors;
        return $requests;
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

}