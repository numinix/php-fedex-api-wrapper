<?php

namespace FedEx\Tests;

use FedEx\Http\HttpClient;
use FedEx\Http\HttpResponse;
use FedEx\Http\OAuthTokenProvider;
use FedEx\TrackService\ComplexType;
use FedEx\TrackService\Request;

class TrackServiceTest extends TestCase
{
    public function testTrackServiceRequest()
    {
        $trackRequest = new ComplexType\TrackRequest();

        $credential = new ComplexType\WebAuthenticationCredential();
        $credential->setKey(self::FEDEX_KEY);
        $credential->setPassword(self::FEDEX_PASSWORD);

        $webAuthDetail = new ComplexType\WebAuthenticationDetail();
        $webAuthDetail->setUserCredential($credential);
        $trackRequest->setWebAuthenticationDetail($webAuthDetail);

        $transactionDetail = new ComplexType\TransactionDetail();
        $transactionDetail->setCustomerTransactionId('test-transaction');
        $trackRequest->setTransactionDetail($transactionDetail);

        $packageIdentifier = new ComplexType\TrackPackageIdentifier();
        $packageIdentifier->setType('TRACKING_NUMBER_OR_DOORTAG');
        $packageIdentifier->setValue('123456789012');

        $selectionDetail = new ComplexType\TrackSelectionDetail();
        $selectionDetail->setPackageIdentifier($packageIdentifier);
        $trackRequest->setSelectionDetails([$selectionDetail]);
        $trackRequest->setProcessingOptions(['INCLUDE_DETAILED_SCANS']);

        $httpClient = $this->createMock(HttpClient::class);
        $tokenProvider = $this->createMock(OAuthTokenProvider::class);

        $tokenProvider->expects($this->once())
            ->method('getAccessToken')
            ->willReturn('oauth-token');

        $expectedPayload = [
            'customerTransactionId' => 'test-transaction',
            'processingParameters' => [
                'processingOptions' => ['INCLUDE_DETAILED_SCANS']
            ],
            'includeDetailedScans' => true,
            'trackingInfo' => [
                [
                    'trackingNumberInfo' => [
                        'trackingNumber' => '123456789012',
                        'trackingNumberType' => 'TRACKING_NUMBER_OR_DOORTAG',
                    ],
                ],
            ],
        ];

        $httpClient->expects($this->once())
            ->method('postJson')
            ->with(
                'https://apis-sandbox.fedex.com/track/v1/trackingnumbers',
                $expectedPayload,
                $this->callback(function ($headers) {
                    return isset($headers['Authorization']) && $headers['Authorization'] === 'Bearer oauth-token';
                })
            )
            ->willReturn(new HttpResponse(200, [], json_encode([
                'customerTransactionId' => 'test-transaction',
                'output' => [
                    'alerts' => [
                        [
                            'alertType' => 'SUCCESS',
                            'code' => '0',
                            'message' => 'Request succeeded',
                        ],
                    ],
                    'completeTrackResults' => [
                        [
                            'trackingNumber' => '123456789012',
                            'additionalResultsAvailable' => false,
                            'trackResults' => [
                                [
                                    'trackingNumberInfo' => [
                                        'trackingNumber' => '123456789012',
                                        'trackingNumberUniqueId' => 'UNIQUE-ID-1',
                                    ],
                                    'latestStatusDetail' => [
                                        'statusCode' => 'DL',
                                        'statusByLocale' => 'Delivered',
                                        'scanLocation' => [
                                            'city' => 'Memphis',
                                            'stateOrProvinceCode' => 'TN',
                                            'postalCode' => '38116',
                                            'countryCode' => 'US',
                                        ],
                                    ],
                                    'dateAndTimes' => [
                                        [
                                            'type' => 'ACTUAL_DELIVERY',
                                            'dateTime' => '2024-05-01T12:00:00-05:00',
                                        ],
                                    ],
                                    'scanEvents' => [
                                        [
                                            'eventDateTime' => '2024-05-01T12:00:00-05:00',
                                            'eventType' => 'DL',
                                            'eventDescription' => 'Delivered',
                                            'scanLocation' => [
                                                'city' => 'Memphis',
                                                'stateOrProvinceCode' => 'TN',
                                                'postalCode' => '38116',
                                                'countryCode' => 'US',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ])));

        $request = new Request(null, $httpClient, $tokenProvider);

        $complexResponse = $request->getTrackReply($trackRequest);

        $this->assertInstanceOf(ComplexType\TrackReply::class, $complexResponse);
        $this->assertEquals('SUCCESS', $complexResponse->HighestSeverity);

        $this->assertNotEmpty($complexResponse->CompletedTrackDetails);
        $completedDetail = $complexResponse->CompletedTrackDetails[0];

        $this->assertEquals('SUCCESS', $completedDetail->HighestSeverity);
        $this->assertEquals(1, $completedDetail->TrackDetailsCount);

        $trackDetail = $completedDetail->TrackDetails[0];
        $this->assertEquals('123456789012', $trackDetail->TrackingNumber);
        $this->assertEquals('UNIQUE-ID-1', $trackDetail->TrackingNumberUniqueIdentifier);
        $this->assertEquals('Delivered', $trackDetail->StatusDetail->Description);
        $this->assertEquals('Memphis', $trackDetail->StatusDetail->Location->City);

        $this->assertNotEmpty($trackDetail->Events);
        $this->assertEquals('DL', $trackDetail->Events[0]->EventType);
    }
}
