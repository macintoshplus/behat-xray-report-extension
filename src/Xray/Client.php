<?php

declare(strict_types=1);

namespace BehatXrayReport\Xray;

use BehatXrayReport\Exception\XrayReportException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Client
{
    private string $apiUrl;
    private string $clientId;
    private string $clientSecret;

    private HttpClientInterface $clientHttp;

    private ?int $expireAt = null;
    private ?string $actualToken = null;
    private string $projectKey;

    public function __construct(string $apiUrl, string $clientId, string $clientSecret, string $projectKey)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->clientHttp = HttpClient::create();
        $this->projectKey = $projectKey;
    }

    public function uploadFeatureFile(string $path)
    {
        $token = $this->authenticate();

        $formData = new FormDataPart(['file' => DataPart::fromPath($path)]);

        $response = $this->clientHttp->request('POST', $this->apiUrl.'/import/feature', [
            'auth_bearer' => $token,
            'headers' => $formData->getPreparedHeaders()->toArray(),
            'query' => ['projectKey' => $this->projectKey],
            'body' => $formData->bodyToIterable(),
        ]);
        if ($response->getStatusCode() !== 200) {
            var_dump($response->getContent(false));
            throw new XrayReportException('Upload Feature file fail ('.$response->getStatusCode().'): File: '.$path);
        }
    }

    public function uploadResultJson(string $resultPath)
    {
        $token = $this->authenticate();

        $response = $this->clientHttp->request('POST', $this->apiUrl.'/import/execution/cucumber', [
            'auth_bearer' => $token,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => file_get_contents($resultPath),
        ]);

        if ($response->getStatusCode() !== 200) {
            var_dump($response->getContent(false));
            throw new XrayReportException('Upload result file fail ('.$response->getStatusCode().'): File: '.$resultPath);
        }
    }

    /**
     * Return the Token need to call API.
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    private function authenticate(): string
    {
        if ($this->actualToken !== null && $this->expireAt >= time()) {
            return $this->actualToken;
        }

        $response = $this->clientHttp->request('POST', $this->apiUrl.'/authenticate', [
            'headers' => [
                'Accept' => 'application/json',
            ],
            'json' => [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            var_dump($response->getContent(false));
            throw new XrayReportException('Authentication fail ('.$response->getStatusCode().')');
        }

        $token = (string) json_decode($response->getContent(false), true);

        $part = explode('.', $token);
        if (\count($part) !== 3) {
            throw new XrayReportException('Invalid JWT Token from XRay');
        }

        $json = base64_decode($part[1]);
        $data = json_decode($json, true);
        if (\is_array($data) === false) {
            throw new XrayReportException('Invalid PAYLOAD JSON from Xray Json token');
        }

        $this->expireAt = $data['exp'] ?? time();
        $this->actualToken = $token;

        return $token;
    }
}
