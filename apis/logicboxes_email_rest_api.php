<?php
/**
 * Minimal LogicBoxes product REST client.
 *
 * Kept separate from the legacy HTTP API client used by the domain module:
 * product REST endpoints use /restapi and can require PATCH/DELETE.
 */
class LogicboxesEmailRestApi
{
    private $resellerId;
    private $apiKey;
    private $sandbox;
    private $lastError;

    const LIVE_BASE = 'https://httpapi.com/restapi/';
    const TEST_BASE = 'https://test.httpapi.com/restapi/';

    public function __construct($resellerId, $apiKey, $sandbox = false)
    {
        $this->resellerId = (string)$resellerId;
        $this->apiKey = (string)$apiKey;
        $this->sandbox = (bool)$sandbox;
    }

    public function request($method, $path, array $params = [])
    {
        $method = strtoupper($method);
        $base = $this->sandbox ? self::TEST_BASE : self::LIVE_BASE;
        $url = $base . ltrim($path, '/');
        $params['auth-userid'] = $this->resellerId;
        $params['api-key'] = $this->apiKey;

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        if ($method === 'GET' || $method === 'DELETE') {
            $url .= (strpos($url, '?') === false ? '?' : '&') . $query;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        if (!in_array($method, ['GET', 'DELETE'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        }
        if (Configure::get('Blesta.curl_verify_ssl')) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $errno) {
            $this->lastError = 'Transport error: ' . $error;
            return false;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $this->lastError = 'API returned non-JSON response (HTTP ' . $status . ').';
            return false;
        }

        if ($status < 200 || $status >= 300 || isset($decoded['error'])) {
            $this->lastError = isset($decoded['error'])
                ? (string)$decoded['error']
                : 'API HTTP status ' . $status;
            return false;
        }

        $this->lastError = null;
        return $decoded;
    }

    public function getLastError()
    {
        return $this->lastError;
    }
}
