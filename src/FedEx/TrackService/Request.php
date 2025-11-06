<?php
namespace FedEx\TrackService;

use FedEx\AbstractRequest;
use FedEx\Http\HttpClient;
use FedEx\Http\HttpException;
use FedEx\Http\HttpResponse;
use FedEx\Http\OAuthTokenProvider;

class Request extends AbstractRequest
{
    const PRODUCTION_URL = 'https://apis.fedex.com';
    const TESTING_URL = 'https://apis-sandbox.fedex.com';

    protected static $wsdlFileName = 'TrackService_v20.wsdl';
    protected static $usesSoap = false;

    /**
     * @var OAuthTokenProvider|null
     */
    private $tokenProvider;

    public function __construct(?\SoapClient $soapClient = null, ?HttpClient $httpClient = null, ?OAuthTokenProvider $tokenProvider = null)
    {
        parent::__construct($soapClient, $httpClient);
        $this->tokenProvider = $tokenProvider;
    }

    /**
     * Sends the TrackRequest and returns the response using the FedEx REST API.
     *
     * @param ComplexType\TrackRequest $trackRequest
     * @param bool $returnStdClass Return the $stdClass response directly from the HTTP client
     * @return ComplexType\TrackReply|\stdClass
     */
    public function getTrackReply(ComplexType\TrackRequest $trackRequest, $returnStdClass = false)
    {
        $requestPayload = $trackRequest->toArray();
        $credentials = $this->extractCredentials($requestPayload);
        $payload = $this->buildTrackPayload($requestPayload);

        $response = $this->sendJsonRequest('/track/v1/trackingnumbers', $payload, $credentials);

        return $this->buildComplexTypeResponse($response, $returnStdClass, ComplexType\TrackReply::class);
    }

    /**
     * Sends the GetTrackingDocumentsRequest and returns the response.
     *
     * @param ComplexType\GetTrackingDocumentsRequest $getTrackingDocumentsRequest
     * @param bool $returnStdClass Return the $stdClass response directly from the HTTP client
     * @return ComplexType\GetTrackingDocumentsReply|\stdClass
     */
    public function getGetTrackingDocumentsReply(ComplexType\GetTrackingDocumentsRequest $getTrackingDocumentsRequest, $returnStdClass = false)
    {
        $requestPayload = $getTrackingDocumentsRequest->toArray();
        $credentials = $this->extractCredentials($requestPayload);
        $payload = $this->filterNullValues($requestPayload);

        $response = $this->sendJsonRequest('/track/v1/trackingdocuments', $payload, $credentials);

        return $this->buildComplexTypeResponse($response, $returnStdClass, ComplexType\GetTrackingDocumentsReply::class);
    }

    /**
     * Sends the SendNotificationsRequest and returns the response.
     *
     * @param ComplexType\SendNotificationsRequest $sendNotificationsRequest
     * @param bool $returnStdClass Return the $stdClass response directly from the HTTP client
     * @return ComplexType\SendNotificationsReply|\stdClass
     */
    public function getSendNotificationsReply(ComplexType\SendNotificationsRequest $sendNotificationsRequest, $returnStdClass = false)
    {
        $requestPayload = $sendNotificationsRequest->toArray();
        $credentials = $this->extractCredentials($requestPayload);
        $payload = $this->filterNullValues($requestPayload);

        $response = $this->sendJsonRequest('/track/v1/sendnotifications', $payload, $credentials);

        return $this->buildComplexTypeResponse($response, $returnStdClass, ComplexType\SendNotificationsReply::class);
    }

    /**
     * @param array<string, mixed> $requestPayload
     * @return array{key: string, password: string}
     */
    private function extractCredentials(array &$requestPayload): array
    {
        $credentials = $requestPayload['WebAuthenticationDetail']['UserCredential'] ?? null;
        if (!is_array($credentials) || empty($credentials['Key']) || empty($credentials['Password'])) {
            throw new \InvalidArgumentException('FedEx WebAuthenticationDetail credentials are required.');
        }

        unset($requestPayload['WebAuthenticationDetail']);

        return [
            'key' => (string) $credentials['Key'],
            'password' => (string) $credentials['Password'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildTrackPayload(array $payload): array
    {
        $trackPayload = [];

        if (!empty($payload['TransactionDetail']['CustomerTransactionId'])) {
            $trackPayload['customerTransactionId'] = (string) $payload['TransactionDetail']['CustomerTransactionId'];
        }

        if (!empty($payload['ProcessingOptions']) && is_array($payload['ProcessingOptions'])) {
            $trackPayload['processingParameters']['processingOptions'] = array_map('strval', $payload['ProcessingOptions']);
            if (in_array('INCLUDE_DETAILED_SCANS', $payload['ProcessingOptions'], true)) {
                $trackPayload['includeDetailedScans'] = true;
            }
        }

        if (isset($payload['TransactionTimeOutValueInMilliseconds'])) {
            $trackPayload['transactionTimeOutValueInMilliseconds'] = (int) $payload['TransactionTimeOutValueInMilliseconds'];
        }

        if (!empty($payload['SelectionDetails']) && is_array($payload['SelectionDetails'])) {
            foreach ($payload['SelectionDetails'] as $selection) {
                if (!is_array($selection)) {
                    continue;
                }
                $trackPayload['trackingInfo'][] = $this->convertSelectionDetail($selection);
            }
        }

        if (empty($trackPayload['trackingInfo'])) {
            throw new \InvalidArgumentException('At least one TrackSelectionDetail with a PackageIdentifier is required.');
        }

        return $this->filterNullValues($trackPayload);
    }

    /**
     * @param array<string, mixed> $selection
     * @return array<string, mixed>
     */
    private function convertSelectionDetail(array $selection): array
    {
        $packageIdentifier = $selection['PackageIdentifier'] ?? [];
        $trackingNumber = is_array($packageIdentifier) ? ($packageIdentifier['Value'] ?? null) : null;
        if ($trackingNumber === null) {
            throw new \InvalidArgumentException('TrackSelectionDetail requires a PackageIdentifier with a Value.');
        }

        $trackingInfo = [
            'trackingNumberInfo' => [
                'trackingNumber' => (string) $trackingNumber,
            ],
        ];

        if (is_array($packageIdentifier) && !empty($packageIdentifier['Type'])) {
            $trackingInfo['trackingNumberInfo']['trackingNumberType'] = (string) $packageIdentifier['Type'];
        }

        if (!empty($selection['TrackingNumberUniqueIdentifier'])) {
            $trackingInfo['trackingNumberInfo']['trackingNumberUniqueId'] = (string) $selection['TrackingNumberUniqueIdentifier'];
        }

        if (!empty($selection['CarrierCode'])) {
            $trackingInfo['carrierCode'] = (string) $selection['CarrierCode'];
        }

        if (!empty($selection['ShipDateRangeBegin']) || !empty($selection['ShipDateRangeEnd'])) {
            if (!empty($selection['ShipDateRangeBegin'])) {
                $trackingInfo['shipDateBegin'] = (string) $selection['ShipDateRangeBegin'];
            }
            if (!empty($selection['ShipDateRangeEnd'])) {
                $trackingInfo['shipDateEnd'] = (string) $selection['ShipDateRangeEnd'];
            }
        }

        if (!empty($selection['ShipmentAccountNumber'])) {
            $trackingInfo['shipmentAccountNumber'] = (string) $selection['ShipmentAccountNumber'];
        }

        if (!empty($selection['Destination']) && is_array($selection['Destination'])) {
            $trackingInfo['destination'] = $this->filterNullValues($selection['Destination']);
        }

        return $this->filterNullValues($trackingInfo);
    }

    /**
     * @param string $path
     * @param array<string, mixed> $payload
     * @param array{key: string, password: string} $credentials
     */
    private function sendJsonRequest(string $path, array $payload, array $credentials): HttpResponse
    {
        $tokenProvider = $this->resolveTokenProvider($credentials);
        $headers = [
            'Authorization' => 'Bearer ' . $tokenProvider->getAccessToken(),
        ];

        $response = $this->getHttpClient()->postJson($this->resolveEndpoint($path), $payload, $headers);

        if ($response->getStatusCode() >= 400) {
            throw new HttpException('FedEx API request failed with status ' . $response->getStatusCode() . '.', $response->getStatusCode());
        }

        return $response;
    }

    /**
     * @param HttpResponse $response
     * @param bool $returnStdClass
     * @param string $complexTypeClass
     * @return \stdClass|object
     */
    private function buildComplexTypeResponse(HttpResponse $response, bool $returnStdClass, string $complexTypeClass)
    {
        $decoded = json_decode($response->getBody());
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new HttpException('Unable to decode FedEx API response: ' . json_last_error_msg(), $response->getStatusCode());
        }

        if ($returnStdClass) {
            return $decoded;
        }

        $complexType = new $complexTypeClass();
        if ($decoded instanceof \stdClass) {
            $complexType->populateFromStdClass($decoded);
        } elseif (is_array($decoded)) {
            $decodedObject = json_decode(json_encode($decoded));
            if ($decodedObject instanceof \stdClass) {
                $complexType->populateFromStdClass($decodedObject);
            }
        }

        return $complexType;
    }

    /**
     * @param array{key: string, password: string} $credentials
     */
    private function resolveTokenProvider(array $credentials): OAuthTokenProvider
    {
        if ($this->tokenProvider instanceof OAuthTokenProvider) {
            return $this->tokenProvider;
        }

        if ($credentials['key'] === '' || $credentials['password'] === '') {
            throw new \InvalidArgumentException('FedEx credentials are required to request an OAuth token.');
        }

        $this->tokenProvider = new OAuthTokenProvider(
            $this->resolveEndpoint('/oauth/token'),
            $credentials['key'],
            $credentials['password'],
            $this->getHttpClient()
        );

        return $this->tokenProvider;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string|int, mixed>
     */
    private function filterNullValues(array $data): array
    {
        $filtered = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = $this->filterNullValues($value);
                if ($value === []) {
                    continue;
                }
                $filtered[$key] = $value;
            } elseif ($value === null || $value === '') {
                continue;
            } else {
                $filtered[$key] = $value;
            }
        }

        if ($filtered === []) {
            return [];
        }

        $keys = array_keys($filtered);
        $isSequential = $keys === range(0, count($filtered) - 1);

        return $isSequential ? array_values($filtered) : $filtered;
    }
}
