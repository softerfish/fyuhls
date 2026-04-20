<?php

namespace App\Service;

/**
 * Lightweight S3 Client (Signature V4)
 * Dependency-free implementation for high performance.
 */
class S3Client {
    private string $accessKey;
    private string $secretKey;
    private string $region;
    private string $bucket;
    private string $endpoint;

    public function __construct(string $accessKey, string $secretKey, string $region, string $bucket, string $endpoint) {
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->region = $region;
        $this->bucket = $bucket;
        $this->endpoint = $endpoint; // e.g., https://s3.us-west-1.wasabisys.com
    }

    public function putFile(string $sourcePath, string $destinationPath, string $mimeType = 'application/octet-stream'): bool {
        if (!file_exists($sourcePath)) return false;

        $content = file_get_contents($sourcePath);
        $uri = "/{$this->bucket}/{$destinationPath}";
        $url = $this->endpoint . $uri;
        
        $headers = [
            'Content-Type' => $mimeType,
            'Content-Length' => strlen($content),
            'x-amz-content-sha256' => hash('sha256', $content),
            'x-amz-date' => gmdate('Ymd\THis\Z'),
            'Host' => parse_url($this->endpoint, PHP_URL_HOST)
        ];

        $headers['Authorization'] = $this->getSignature('PUT', $uri, '', $headers, $content);

        $ch = curl_init($url);
        
        $requestHeaders = [];
        foreach ($headers as $k => $v) {
            $requestHeaders[] = "$k: $v";
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
    }

    public function deleteObject(string $path): bool {
        $uri = "/{$this->bucket}/{$path}";
        $url = $this->endpoint . $uri;

        $headers = [
            'x-amz-content-sha256' => hash('sha256', ''),
            'x-amz-date' => gmdate('Ymd\THis\Z'),
            'Host' => parse_url($this->endpoint, PHP_URL_HOST)
        ];

        $headers['Authorization'] = $this->getSignature('DELETE', $uri, '', $headers, '');

        $ch = curl_init($url);
        
        $requestHeaders = [];
        foreach ($headers as $k => $v) {
            $requestHeaders[] = "$k: $v";
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
    }

    public function getPresignedUrl(string $path, int $expiry = 3600): string {
        $timestamp = time();
        $dateShort = gmdate('Ymd', $timestamp);
        $dateLong = gmdate('Ymd\THis\Z', $timestamp);
        $region = $this->region;
        $service = 's3';

        $scope = "$dateShort/$region/$service/aws4_request";
        $credential = "{$this->accessKey}/$scope";

        $uri = "/{$this->bucket}/{$path}";
        $host = parse_url($this->endpoint, PHP_URL_HOST);

        $queryParams = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $credential,
            'X-Amz-Date' => $dateLong,
            'X-Amz-Expires' => $expiry,
            'X-Amz-SignedHeaders' => 'host',
        ];

        ksort($queryParams);
        $queryString = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);

        $canonicalRequest = "GET\n$uri\n$queryString\nhost:$host\nhost\nUNSIGNED-PAYLOAD";
        $stringToSign = "AWS4-HMAC-SHA256\n$dateLong\n$scope\n" . hash('sha256', $canonicalRequest);
        
        $signature = $this->calculateSignatureKey($dateShort, $region, $service, $stringToSign);
        
        return $this->endpoint . $uri . '?' . $queryString . "&X-Amz-Signature=$signature";
    }

    private function getSignature($method, $uri, $queryString, $headers, $payload) {
        $dateShort = substr($headers['x-amz-date'], 0, 8);
        $region = $this->region;
        $service = 's3';
        $scope = "$dateShort/$region/$service/aws4_request";

        // Canonical Headers
        $canonicalHeaders = "";
        $signedHeaders = [];
        ksort($headers);
        foreach ($headers as $k => $v) {
            $k = strtolower($k);
            $canonicalHeaders .= "$k:$v\n";
            $signedHeaders[] = $k;
        }
        $signedHeadersStr = implode(';', $signedHeaders);
        $payloadHash = hash('sha256', $payload);

        $canonicalRequest = "$method\n$uri\n$queryString\n$canonicalHeaders\n$signedHeadersStr\n$payloadHash";
        $stringToSign = "AWS4-HMAC-SHA256\n{$headers['x-amz-date']}\n$scope\n" . hash('sha256', $canonicalRequest);

        return "AWS4-HMAC-SHA256 Credential={$this->accessKey}/$scope, SignedHeaders=$signedHeadersStr, Signature=" . $this->calculateSignatureKey($dateShort, $region, $service, $stringToSign);
    }

    private function calculateSignatureKey($date, $region, $service, $stringToSign) {
        $kDate = hash_hmac('sha256', $date, "AWS4" . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', "aws4_request", $kService, true);
        return hash_hmac('sha256', $stringToSign, $kSigning);
    }
}
