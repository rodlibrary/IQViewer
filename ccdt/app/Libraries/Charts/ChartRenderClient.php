<?php

namespace App\Libraries\Charts;

/**
 * Minimal client for the matplotlib render service (charts-service/).
 *
 * Uses PHP's bundled curl extension so the prototype adds no composer
 * dependency. The service is only reachable on the docker network.
 */
class ChartRenderClient
{
    private $baseUrl;
    private $token;
    private $timeout;

    public function __construct($baseUrl, $token = '', $timeout = 30)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
        $this->timeout = $timeout;
    }

    /**
     * @param array $shaped output of ChartQueryBuilder::shape()
     * @return string PNG bytes
     * @throws \RuntimeException when the service is down or rejects the chart
     */
    public function render(array $shaped)
    {
        $payload = [
            'chart'    => $shaped['chart'],
            'title'    => $shaped['title'],
            'x_kind'   => $shaped['x_kind'],
            'bucket'   => $shaped['bucket'],
            'x'        => $shaped['x'],
            'x_label'  => $shaped['x_label'],
            'y_label'  => $shaped['y_label'],
            'series'   => $shaped['series'],
            'footnote' => $shaped['suppressed'] > 0
                ? $shaped['suppressed'] . ' cell(s) with fewer than ' . $shaped['min_cell_size'] . ' records are hidden'
                : '',
        ];

        $headers = ['Content-Type: application/json', 'Accept: image/png'];
        if ($this->token !== '') {
            $headers[] = 'X-Charts-Token: ' . $this->token;
        }

        $ch = curl_init($this->baseUrl . '/render');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $this->timeout,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('Chart service is not reachable: ' . $error);
        }
        if ($status !== 200) {
            $detail = json_decode($body, true);
            $msg = is_array($detail) && isset($detail['detail']) ? (is_string($detail['detail']) ? $detail['detail'] : json_encode($detail['detail'])) : "HTTP {$status}";
            throw new \RuntimeException('Chart service refused the chart: ' . $msg);
        }
        return $body;
    }
}
