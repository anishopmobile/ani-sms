<?php
namespace SMSIR;

class Message_Reports {
    private $api_key;
    private $base_url = 'https://api.sms.ir/v1';

    public function __construct($api_key) {
        $this->api_key = $api_key;
    }

    private function api_request($endpoint, $params = []) {
        $url = $this->base_url . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $response = wp_remote_get($url, [
            'headers' => [
                'X-API-KEY' => $this->api_key,
                'Accept' => 'application/json'
            ],
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || $data['status'] !== 1) {
            return new \WP_Error(
                'smsir_api_error',
                $data['message'] ?? __('Failed to fetch messages', 'sms-ir'),
                ['response' => $data]
            );
        }

        return $data['data'];
    }

    public function get_sent_messages($page = 1, $page_size = 20) {
        return $this->api_request('/send/live', [
            'pageNumber' => max(1, (int)$page),
            'pageSize' => min(100, max(1, (int)$page_size))
        ]);
    }

    public function get_received_messages($page = 1, $page_size = 20) {
        return $this->api_request('/receive/live', [
            'pageNumber' => max(1, (int)$page),
            'pageSize' => min(100, max(1, (int)$page_size)),
        ]);
    }

    public function get_archived_messages($from_date = null, $to_date = null, $page = 1, $page_size = 20) {
        $params = [
            'pageNumber' => max(1, (int)$page),
            'pageSize' => min(100, max(1, (int)$page_size))
        ];

        if ($from_date) {
            $params['fromDate'] = $from_date;
        }
        if ($to_date) {
            $params['toDate'] = $to_date;
        }

        return $this->api_request('/send/archive', $params);
    }
}
