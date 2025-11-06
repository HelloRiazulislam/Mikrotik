<?php
// lib/RouterOSRest.php - tiny helper for RouterOS v7 REST API
class RouterOSRest {
    private string $base;
    private string $user;
    private string $pass;
    private int $timeout;
    public function __construct(string $host, string $user, string $pass, int $port = 443, bool $https = true, int $timeout = 10) {
        $scheme = $https ? 'https' : 'http';
        $this->base = sprintf('%s://%s:%d/rest', $scheme, $host, $port);
        $this->user = $user;
        $this->pass = $pass;
        $this->timeout = $timeout;
    }
    private function request(string $method, string $path, array $query = [], $body = null) {
        $url = rtrim($this->base, '/') . '/' . ltrim($path, '/');
        if (!empty($query)) {
            $parts = [];
            foreach ($query as $k=>$v) {
                $parts[] = rawurlencode('='.$k) . '=' . rawurlencode($v);
            }
            $url .= '?' . implode('&', $parts);
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $this->user . ':' . $this->pass);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception("cURL error: $err");
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300) {
            $json = json_decode($resp, true);
            return $json === null ? [] : $json;
        } else {
            throw new Exception("HTTP $code: " . $resp);
        }
    }
    public function get(string $path, array $filter = []) { return $this->request('GET', $path, $filter); }
    public function post(string $path, array $data = []) { return $this->request('POST', $path, [], $data); }
    public function patch(string $path, array $data = []) { return $this->request('PATCH', $path, [], $data); }
    public function delete(string $path, array $filter = []) { return $this->request('DELETE', $path, $filter); }
}
