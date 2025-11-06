<?php
namespace FedEx\Http;

class HttpClient
{
    /**
     * Sends an HTTP request.
     *
     * @param string $method
     * @param string $url
     * @param array<string, string> $headers
     * @param string|null $body
     *
     * @throws HttpException
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new HttpException('Unable to initialize cURL.');
        }

        $normalizedHeaders = [];
        foreach ($headers as $name => $value) {
            $normalizedHeaders[] = $name . ': ' . $value;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $normalizedHeaders);

        $upperMethod = strtoupper($method);
        if ($upperMethod === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $upperMethod);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        $rawResponse = curl_exec($ch);
        if ($rawResponse === false) {
            $error = curl_error($ch);
            $errorNo = curl_errno($ch);
            curl_close($ch);
            throw new HttpException('cURL error: ' . $error, $errorNo ?: null);
        }

        $statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($statusCode === 0) {
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headerString = substr($rawResponse, 0, $headerSize);
        $bodyString = substr($rawResponse, $headerSize);

        $headersArray = $this->parseHeaders($headerString);

        return new HttpResponse((int) $statusCode, $headersArray, $bodyString === false ? '' : $bodyString);
    }

    /**
     * Sends a JSON encoded POST request.
     *
     * @param string $url
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function postJson(string $url, array $payload, array $headers = []): HttpResponse
    {
        $encoded = json_encode($payload);
        if ($encoded === false) {
            throw new HttpException('Unable to encode request payload as JSON: ' . json_last_error_msg());
        }

        $headers = array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ], $headers);

        return $this->request('POST', $url, $headers, $encoded);
    }

    /**
     * @param string $headerString
     * @return array<string, string>
     */
    private function parseHeaders(string $headerString): array
    {
        $headers = [];
        if ($headerString === '') {
            return $headers;
        }

        $segments = preg_split("/\r?\n\r?\n/", trim($headerString));
        $headerLines = $segments ? preg_split("/\r?\n/", end($segments)) : [];

        if (!$headerLines) {
            return $headers;
        }

        foreach ($headerLines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[trim($name)] = trim($value);
        }

        return $headers;
    }
}
