<?php
/**
 * PrometheusClient
 *
 * Complete Prometheus integration for metrics querying and alerting.
 */

class PrometheusClient
{
    private string $baseUrl;
    private ?string $authToken;

    public function __construct(string $baseUrl, ?string $authToken = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->authToken = $authToken;
    }

    /**
     * Execute PromQL query
     */
    public function query(string $query, ?int $timestamp = null): array
    {
        $params = ['query' => $query];

        if ($timestamp) {
            $params['time'] = $timestamp;
        }

        return $this->request('GET', '/api/v1/query', $params);
    }

    /**
     * Execute PromQL range query
     */
    public function queryRange(string $query, int $start, int $end, string $step = '15s'): array
    {
        $params = [
            'query' => $query,
            'start' => $start,
            'end' => $end,
            'step' => $step,
        ];

        return $this->request('GET', '/api/v1/query_range', $params);
    }

    /**
     * Get all metric names
     */
    public function getMetrics(): array
    {
        $result = $this->request('GET', '/api/v1/label/__name__/values');
        return $result['data'] ?? [];
    }

    /**
     * Get label values for a metric
     */
    public function getLabelValues(string $label): array
    {
        $result = $this->request('GET', "/api/v1/label/$label/values");
        return $result['data'] ?? [];
    }

    /**
     * Get targets (scraped endpoints)
     */
    public function getTargets(): array
    {
        $result = $this->request('GET', '/api/v1/targets');
        return $result['data'] ?? [];
    }

    /**
     * Get alerts
     */
    public function getAlerts(): array
    {
        $result = $this->request('GET', '/api/v1/alerts');
        return $result['data'] ?? [];
    }

    /**
     * Get alerting rules
     */
    public function getRules(): array
    {
        $result = $this->request('GET', '/api/v1/rules');
        return $result['data'] ?? [];
    }

    /**
     * Get current values for common metrics
     */
    public function getSystemMetrics(string $instance): array
    {
        $metrics = [
            'cpu_usage' => $this->query("100 - (avg by (instance) (irate(node_cpu_seconds_total{mode=\"idle\",instance=\"$instance\"}[5m])) * 100)"),
            'memory_usage' => $this->query("(1 - (node_memory_MemAvailable_bytes{instance=\"$instance\"} / node_memory_MemTotal_bytes{instance=\"$instance\"})) * 100"),
            'disk_usage' => $this->query("(node_filesystem_size_bytes{instance=\"$instance\",mountpoint=\"/\"} - node_filesystem_avail_bytes{instance=\"$instance\",mountpoint=\"/\"}) / node_filesystem_size_bytes{instance=\"$instance\",mountpoint=\"/\"} * 100"),
            'network_rx' => $this->query("irate(node_network_receive_bytes_total{instance=\"$instance\",device!~\"lo\"}[5m])"),
            'network_tx' => $this->query("irate(node_network_transmit_bytes_total{instance=\"$instance\",device!~\"lo\"}[5m])"),
        ];

        $result = [];
        foreach ($metrics as $key => $data) {
            if (isset($data['data']['result'][0]['value'][1])) {
                $result[$key] = floatval($data['data']['result'][0]['value'][1]);
            } else {
                $result[$key] = 0;
            }
        }

        return $result;
    }

    /**
     * Make API request to Prometheus
     */
    private function request(string $method, string $endpoint, array $params = []): array
    {
        $url = $this->baseUrl . $endpoint;

        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $headers = ['Accept: application/json'];

        if ($this->authToken) {
            $headers[] = 'Authorization: Bearer ' . $this->authToken;
        }

        if ($method !== 'GET' && !empty($params)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Prometheus API error: HTTP $httpCode");
        }

        $data = json_decode($response, true);

        if (!$data || $data['status'] !== 'success') {
            throw new Exception('Prometheus API returned error: ' . ($data['error'] ?? 'Unknown error'));
        }

        return $data;
    }
}
