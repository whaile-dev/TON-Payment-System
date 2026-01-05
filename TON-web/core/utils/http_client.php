<?php

class HttpClient {
    private $baseUrl;
    private $defaultOptions;
    
    public function __construct($baseUrl = null) {
        $this->baseUrl = $baseUrl ?? 'https://pay.whaile.ru:3000';
        $this->defaultOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json']
        ];
    }
    
    public function request(string $method, string $endpoint, array $options = []): array {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        
        $curlOptions = $this->defaultOptions;
        $curlOptions[CURLOPT_URL] = $url;
        
        $method = strtoupper($method);
        if ($method === 'POST') {
            $curlOptions[CURLOPT_POST] = true;
        } elseif (in_array($method, ['PUT', 'DELETE', 'PATCH'])) {
            $curlOptions[CURLOPT_CUSTOMREQUEST] = $method;
        }
        
        if (isset($options['body'])) {
            $curlOptions[CURLOPT_POSTFIELDS] = is_array($options['body']) 
                ? json_encode($options['body']) 
                : $options['body'];
            
            if (is_array($options['body']) || (is_string($options['body']) && json_decode($options['body']) !== null)) {
                if (!isset($curlOptions[CURLOPT_HTTPHEADER])) {
                    $curlOptions[CURLOPT_HTTPHEADER] = $this->defaultOptions[CURLOPT_HTTPHEADER];
                }
                $curlOptions[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
            }
        }
        
        if (isset($options['headers']) && is_array($options['headers'])) {
            if (!isset($curlOptions[CURLOPT_HTTPHEADER])) {
                $curlOptions[CURLOPT_HTTPHEADER] = $this->defaultOptions[CURLOPT_HTTPHEADER];
            }
            $curlOptions[CURLOPT_HTTPHEADER] = array_merge(
                $curlOptions[CURLOPT_HTTPHEADER],
                $options['headers']
            );
        }
        
        if (isset($options['curl_options']) && is_array($options['curl_options'])) {
            $curlOptions = array_merge($curlOptions, $options['curl_options']);
        }
        
        curl_setopt_array($ch, $curlOptions);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $curl_errno = curl_errno($ch);
        curl_close($ch);
        
        if ($curl_error && $curl_errno !== 0) {
            error_log("cURL error in HttpClient: [$curl_errno] $curl_error for URL: $url");
        }
        
        return [
            'response' => $response !== false ? $response : '',
            'http_code' => $http_code,
            'error' => $curl_error ?: null,
            'curl_errno' => $curl_errno
        ];
    }
    
    public function get(string $endpoint, array $options = []): array {
        return $this->request('GET', $endpoint, $options);
    }
    
    public function post(string $endpoint, array $body = [], array $options = []): array {
        $options['body'] = $body;
        return $this->request('POST', $endpoint, $options);
    }
    
    public function put(string $endpoint, array $body = [], array $options = []): array {
        $options['body'] = $body;
        return $this->request('PUT', $endpoint, $options);
    }
    
    public function delete(string $endpoint, array $options = []): array {
        return $this->request('DELETE', $endpoint, $options);
    }
    
    public function patch(string $endpoint, array $body = [], array $options = []): array {
        $options['body'] = $body;
        return $this->request('PATCH', $endpoint, $options);
    }
}

function getHttpClient($baseUrl = null): HttpClient {
    return new HttpClient($baseUrl);
}

