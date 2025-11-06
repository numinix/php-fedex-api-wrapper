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
                'TrackReply' => [
                    'HighestSeverity' => 'SUCCESS'
                ]
            ])));

        $request = new Request(null, $httpClient, $tokenProvider);

        $stdClassResponse = $request->getTrackReply($trackRequest, true);

        $this->assertInstanceOf(\stdClass::class, $stdClassResponse);
        $this->assertEquals('SUCCESS', $stdClassResponse->TrackReply->HighestSeverity);
    }
}
