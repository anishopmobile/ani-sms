<?php
namespace SMSIR;

class Account_Stats
{
    private $api_key;
    private $base_urls = [
        'credit'   => 'https://api.sms.ir/v1/credit',
        'lines'    => 'https://api.sms.ir/v1/line',
        'received' => 'https://api.sms.ir/v1/receive/live',
        'sent'     => 'https://api.sms.ir/v1/send/live',
    ];

    public function __construct($api_key)
    {
        $this->api_key = $api_key;
    }

    private function fetch_endpoint($endpoint, $query_params = [])
    {
        $url = $this->base_urls[$endpoint];
        if (! empty($query_params)) {
            $url .= '?' . http_build_query($query_params);
        }

        $args = [
            'method'  => 'GET',
            'timeout' => 30,
            'headers' => [
                'X-API-KEY'    => $this->api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
        ];

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $logger = SMSIR_Error_Logger::get_instance();
            $logger->log(
                'Failed to fetch account stats: ' . $response->get_error_message(),
                [
                    'endpoint' => $endpoint,
                    'query_params' => $query_params,
                    'error' => $response->get_error_message()
                ],
            );
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (! $data || $data['status'] != 1) {
            $logger = SMSIR_Error_Logger::get_instance();
            $logger->log(
                __('Invalid response from account stats API', 'sms-ir'),                [
                    'endpoint' => $endpoint,
                    'query_params' => $query_params,
                    'response' => $data
                ],
            );
            return new \WP_Error(
                'smsir_api_error',
                $data['message'] ?? __('Failed to retrieve data', 'sms-ir'),
                ['raw_response' => $data]
            );
        }

        return $data['data'];
    }

    public function get_account_stats($type = 'all')
    {
        if (!isset($this->base_urls[$type]) && $type !== 'all') {
            $logger = SMSIR_Error_Logger::get_instance();
            $logger->log(
                __('Invalid stats type requested', 'sms-ir'),
                ['type' => $type],
            );
            return new \WP_Error(
                'invalid_type',
                __('Invalid stats type requested', 'sms-ir'),
               ['type' => $type],
            );
        }

        if ($type === 'all') {
            $credit   = $this->fetch_endpoint('credit');
            $lines    = $this->fetch_endpoint('lines');
            $received = $this->fetch_endpoint('received', ['pageSize' => 1]);
            $sent     = $this->fetch_endpoint('sent', ['pageSize' => 1]);

            foreach ([$credit, $lines, $received, $sent] as $result) {
                if (is_wp_error($result)) {
                    return $result;
                }
            }

            return [
                'credit'         => $credit,
                'active_lines'   => is_array($lines) ? count($lines) : 0,
                'today_received' => is_array($received) ? count($received) : 0,
                'today_sent'     => is_array($sent) ? count($sent) : 0,
            ];
        }

        $result = $this->fetch_endpoint($type);
        if (is_wp_error($result)) {
            return $result;
        }

        return [
            $type => $result
        ];
    }
}
