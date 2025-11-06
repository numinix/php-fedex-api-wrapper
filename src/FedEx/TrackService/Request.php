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
        $decoded = json_decode($response->getBody(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new HttpException('Unable to decode FedEx API response: ' . json_last_error_msg(), $response->getStatusCode());
        }

        $normalized = $this->normalizeTrackResponse($decoded);

        if ($returnStdClass) {
            return json_decode(json_encode($normalized));
        }

        $complexType = new $complexTypeClass();
        $decodedObject = json_decode(json_encode($normalized));
        if ($decodedObject instanceof \stdClass) {
            $complexType->populateFromStdClass($decodedObject);
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

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function normalizeTrackResponse(array $response): array
    {
        if (isset($response['TrackReply'])) {
            return $response;
        }

        if (!isset($response['output']) || !is_array($response['output'])) {
            return $response;
        }

        $output = $response['output'];

        $trackReply = [
            'HighestSeverity' => $this->determineHighestSeverity($output['alerts'] ?? []),
        ];

        $notifications = $this->mapAlertsToNotifications($output['alerts'] ?? []);
        if ($notifications !== []) {
            $trackReply['Notifications'] = $notifications;
        }

        if (!empty($response['customerTransactionId'])) {
            $trackReply['TransactionDetail'] = [
                'CustomerTransactionId' => (string) $response['customerTransactionId'],
            ];
        }

        $completedTrackDetails = [];
        foreach ($output['completeTrackResults'] ?? [] as $completeResult) {
            if (!is_array($completeResult)) {
                continue;
            }

            $mapped = $this->mapCompletedTrackResult($completeResult);
            if ($mapped !== []) {
                $completedTrackDetails[] = $mapped;
            }
        }

        if ($completedTrackDetails !== []) {
            $trackReply['CompletedTrackDetails'] = $completedTrackDetails;
        }

        return ['TrackReply' => $this->filterNullValues($trackReply)];
    }

    /**
     * @param array<int, array<string, mixed>> $alerts
     */
    private function determineHighestSeverity(array $alerts): string
    {
        foreach ($alerts as $alert) {
            if (is_array($alert) && !empty($alert['alertType'])) {
                return (string) $alert['alertType'];
            }
        }

        return 'SUCCESS';
    }

    /**
     * @param array<int, array<string, mixed>> $alerts
     * @return array<int, array<string, mixed>>
     */
    private function mapAlertsToNotifications(array $alerts): array
    {
        $notifications = [];
        foreach ($alerts as $alert) {
            if (!is_array($alert)) {
                continue;
            }

            $notification = $this->mapAlertToNotification($alert);
            if ($notification !== []) {
                $notifications[] = $notification;
            }
        }

        return $notifications;
    }

    /**
     * @param array<string, mixed> $alert
     * @return array<string, mixed>
     */
    private function mapAlertToNotification(array $alert): array
    {
        $notification = [
            'Severity' => $alert['alertType'] ?? null,
            'Code' => $alert['code'] ?? null,
            'Message' => $alert['message'] ?? null,
            'LocalizedMessage' => $alert['localizedMessage'] ?? null,
        ];

        if (!empty($alert['source'])) {
            $notification['Source'] = (string) $alert['source'];
        }

        if (!empty($alert['parameters']) && is_array($alert['parameters'])) {
            $parameters = [];
            foreach ($alert['parameters'] as $parameter) {
                if (!is_array($parameter)) {
                    continue;
                }

                $parameters[] = $this->filterNullValues([
                    'Id' => $parameter['id'] ?? null,
                    'Value' => $parameter['value'] ?? null,
                ]);
            }

            if ($parameters !== []) {
                $notification['MessageParameters'] = $parameters;
            }
        }

        return $this->filterNullValues($notification);
    }

    /**
     * @param array<string, mixed> $completeResult
     * @return array<string, mixed>
     */
    private function mapCompletedTrackResult(array $completeResult): array
    {
        $mapped = [
            'HighestSeverity' => $this->determineHighestSeverity($completeResult['alerts'] ?? []),
        ];

        $notifications = $this->mapAlertsToNotifications($completeResult['alerts'] ?? []);
        if ($notifications !== []) {
            $mapped['Notifications'] = $notifications;
        }

        if (array_key_exists('duplicateWaybill', $completeResult)) {
            $mapped['DuplicateWaybill'] = (bool) $completeResult['duplicateWaybill'];
        }

        if (array_key_exists('additionalResultsAvailable', $completeResult)) {
            $mapped['MoreData'] = (bool) $completeResult['additionalResultsAvailable'];
        }

        if (!empty($completeResult['pagingToken'])) {
            $mapped['PagingToken'] = (string) $completeResult['pagingToken'];
        }

        $trackDetails = [];
        foreach ($completeResult['trackResults'] ?? [] as $trackResult) {
            if (!is_array($trackResult)) {
                continue;
            }

            $detail = $this->mapTrackResult($trackResult, $completeResult);
            if ($detail !== []) {
                $trackDetails[] = $detail;
            }
        }

        if ($trackDetails !== []) {
            $mapped['TrackDetails'] = $trackDetails;
            $mapped['TrackDetailsCount'] = count($trackDetails);
        }

        return $this->filterNullValues($mapped);
    }

    /**
     * @param array<string, mixed> $trackResult
     * @param array<string, mixed> $completeResult
     * @return array<string, mixed>
     */
    private function mapTrackResult(array $trackResult, array $completeResult): array
    {
        $trackingInfo = $trackResult['trackingNumberInfo'] ?? [];

        $detail = [
            'TrackingNumber' => $trackingInfo['trackingNumber'] ?? ($completeResult['trackingNumber'] ?? null),
            'TrackingNumberUniqueIdentifier' => $trackingInfo['trackingNumberUniqueId'] ?? ($completeResult['trackingNumberUniqueIdentifier'] ?? null),
        ];

        if (!empty($trackResult['latestStatusDetail']) && is_array($trackResult['latestStatusDetail'])) {
            $statusDetail = $this->mapStatusDetail($trackResult['latestStatusDetail']);
            if ($statusDetail !== []) {
                $detail['StatusDetail'] = $statusDetail;
            }
        }

        if (!empty($trackResult['dateAndTimes']) && is_array($trackResult['dateAndTimes'])) {
            $dates = [];
            foreach ($trackResult['dateAndTimes'] as $date) {
                if (!is_array($date)) {
                    continue;
                }

                $mappedDate = $this->mapDateOrTime($date);
                if ($mappedDate !== null) {
                    $dates[] = $mappedDate;
                }
            }

            if ($dates !== []) {
                $detail['DatesOrTimes'] = $dates;
            }
        }

        if (!empty($trackResult['scanEvents']) && is_array($trackResult['scanEvents'])) {
            $events = [];
            foreach ($trackResult['scanEvents'] as $event) {
                if (!is_array($event)) {
                    continue;
                }

                $mappedEvent = $this->mapScanEvent($event);
                if ($mappedEvent !== []) {
                    $events[] = $mappedEvent;
                }
            }

            if ($events !== []) {
                $detail['Events'] = $events;
            }
        }

        return $this->filterNullValues($detail);
    }

    /**
     * @param array<string, mixed> $statusDetail
     * @return array<string, mixed>
     */
    private function mapStatusDetail(array $statusDetail): array
    {
        $mapped = [
            'Code' => $statusDetail['statusCode'] ?? ($statusDetail['code'] ?? null),
            'Description' => $statusDetail['statusByLocale'] ?? ($statusDetail['status'] ?? ($statusDetail['description'] ?? null)),
        ];

        if (!empty($statusDetail['creationTime'])) {
            $mapped['CreationTime'] = (string) $statusDetail['creationTime'];
        }

        if (!empty($statusDetail['scanLocation']) && is_array($statusDetail['scanLocation'])) {
            $location = $this->mapLocation($statusDetail['scanLocation']);
            if ($location !== []) {
                $mapped['Location'] = $location;
            }
        }

        if (!empty($statusDetail['ancillaryDetails']) && is_array($statusDetail['ancillaryDetails'])) {
            $ancillary = [];
            foreach ($statusDetail['ancillaryDetails'] as $ancillaryDetail) {
                if (!is_array($ancillaryDetail)) {
                    continue;
                }

                $ancillary[] = $this->filterNullValues([
                    'Reason' => $ancillaryDetail['reason'] ?? null,
                    'ReasonDescription' => $ancillaryDetail['reasonDescription'] ?? null,
                    'Action' => $ancillaryDetail['action'] ?? null,
                    'ActionDescription' => $ancillaryDetail['actionDescription'] ?? null,
                ]);
            }

            if ($ancillary !== []) {
                $mapped['AncillaryDetails'] = $ancillary;
            }
        }

        return $this->filterNullValues($mapped);
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function mapScanEvent(array $event): array
    {
        $mapped = [
            'Timestamp' => $event['eventDateTime'] ?? ($event['date'] ?? ($event['dateTime'] ?? null)),
            'EventType' => $event['eventType'] ?? null,
            'EventDescription' => $event['eventDescription'] ?? ($event['derivedStatus'] ?? null),
            'StatusExceptionCode' => $event['exceptionCode'] ?? ($event['statusExceptionCode'] ?? null),
            'StatusExceptionDescription' => $event['exceptionDescription'] ?? ($event['statusExceptionDescription'] ?? null),
        ];

        if (!empty($event['scanLocation']) && is_array($event['scanLocation'])) {
            $location = $this->mapLocation($event['scanLocation']);
            if ($location !== []) {
                $mapped['Address'] = $location;
            }
        }

        if (!empty($event['arrivalLocation'])) {
            $mapped['ArrivalLocation'] = $event['arrivalLocation'];
        }

        if (!empty($event['stationId'])) {
            $mapped['StationId'] = (string) $event['stationId'];
        }

        return $this->filterNullValues($mapped);
    }

    /**
     * @param array<string, mixed> $date
     * @return array<string, mixed>|null
     */
    private function mapDateOrTime(array $date): ?array
    {
        $type = $date['type'] ?? ($date['dateOrTimestampType'] ?? null);
        $value = $date['dateTime'] ?? ($date['value'] ?? ($date['dateOrTimestamp'] ?? null));

        if ($type === null && $value === null) {
            return null;
        }

        return $this->filterNullValues([
            'Type' => $type,
            'DateOrTimestamp' => $value,
        ]);
    }

    /**
     * @param array<string, mixed> $location
     * @return array<string, mixed>
     */
    private function mapLocation(array $location): array
    {
        $streetLines = null;
        if (isset($location['streetLines'])) {
            $streetLines = is_array($location['streetLines']) ? $location['streetLines'] : [$location['streetLines']];
        } elseif (isset($location['streetLinesString'])) {
            $streetLines = is_array($location['streetLinesString']) ? $location['streetLinesString'] : [$location['streetLinesString']];
        }

        $mapped = [
            'StreetLines' => $streetLines,
            'City' => $location['city'] ?? null,
            'StateOrProvinceCode' => $location['stateOrProvinceCode'] ?? null,
            'PostalCode' => $location['postalCode'] ?? null,
            'CountryCode' => $location['countryCode'] ?? null,
            'CountryName' => $location['countryName'] ?? null,
        ];

        if (array_key_exists('residential', $location)) {
            $mapped['Residential'] = (bool) $location['residential'];
        }

        return $this->filterNullValues($mapped);
    }
}
