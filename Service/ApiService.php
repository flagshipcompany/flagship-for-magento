<?php

declare(strict_types=1);

namespace Flagship\Shipping\Service;

use GuzzleHttp\Client;
use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ResponseFactory;
use Magento\Framework\Webapi\Rest\Request;
use Flagship\Shipping\Model\Configuration;

class ApiService
{
    protected $client;
    protected $response;
    const API_URL = 'https://api.smartship.io';
    const TEST_API_URL = 'https://test-api.smartship.io';
    
    public function __construct(
        protected ClientFactory $clientFactory,
        protected ResponseFactory $responseFactory,
        protected Request $request,
        protected Configuration $configuration
    ) {
        
    }

    public function sendRequest($endpoint, $token, $method, $payload = [], $testEnv = 0, string $baseUri = ''): array
    {
        $headers = [
            'X-Smartship-Token' => $token,
            'Content-Type' => 'application/json',
          ];
        $testEnv = $this->configuration->getEnvironment();
        $baseUri = empty($baseUri) ? ($testEnv == 1 ? self::TEST_API_URL : self::API_URL) : $baseUri;
        $response = $this->doRequest($baseUri.$endpoint, $headers, $method ,$payload);
        
        return [
            'status' => $response->getStatusCode(), 
            'response' => json_decode($response->getBody()->getContents(), true)
        ];
    }

    private function doRequest(
        string $uriEndpoint,
        array $headers,
        string $method,
        array $payload
    ): Response {
        
        $client = $this->clientFactory->create(['config' => [
            'base_uri' => $uriEndpoint,
        ]]);

        try {
            $response = $client->request(
                $method,
                $uriEndpoint,
                [ 'json' => $payload, 'headers' => $headers ]  
            );
        } catch (GuzzleException $exception) {
            error_log($exception->getMessage());
            $response = $this->responseFactory->create([
                'status' => $exception->getCode(),
                'reason' => $exception->getMessage()
            ]);
        }

        return $response;
    }
}