<?php
namespace FedEx\Http;

class OAuthTokenProvider
{
    /**
     * @var string
     */
    private $authUrl;

    /**
     * @var string
     */
    private $clientId;

    /**
     * @var string
     */
    private $clientSecret;

    /**
     * @var HttpClient
     */
    private $httpClient;

    /**
     * @var string|null
     */
    private $accessToken;

    /**
     * @var int
     */
    private $expiresAt = 0;

    public function __construct(string $authUrl, string $clientId, string $clientSecret, ?HttpClient $httpClient = null)
    {
        $this->authUrl = $authUrl;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->httpClient = $httpClient ?: new HttpClient();
    }

    /**
     * @throws HttpException
     */
    public function getAccessToken(): string
    {
        if ($this->accessToken === null || time() >= $this->expiresAt) {
            $this->refreshToken();
        }

        return $this->accessToken;
    }

    /**
     * @throws HttpException
     */
    private function refreshToken(): void
    {
        $body = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        $response = $this->httpClient->request('POST', $this->authUrl, [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], $body);

        if ($response->getStatusCode() >= 400) {
            throw new HttpException('Unable to retrieve OAuth token.', $response->getStatusCode());
        }

        $data = json_decode($response->getBody(), true);
        if (!is_array($data) || !isset($data['access_token'])) {
            throw new HttpException('Invalid OAuth token response.');
        }

        $this->accessToken = (string) $data['access_token'];
        $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;
        $this->expiresAt = time() + max($expiresIn - 60, 0);
    }
}
