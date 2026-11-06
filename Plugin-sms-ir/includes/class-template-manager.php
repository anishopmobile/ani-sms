<?php
namespace SMSIR;

class SMSIR_Template_Manager {
    private $api_key;
    private $static_string = 'SMS.irPlugin:VaKUsW8XZ4!OEoU7jX?a';
    private $base_url = 'https://api.sms.ir/v1';

    public function __construct($api_key) {
        $this->api_key = $api_key;
    }

    private function api_request($method, $endpoint, $data = []) {
        $url = $this->base_url . $endpoint;
        
        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }

        $timestamp = time();
        
        $signature_string = "{$this->api_key}:{$timestamp}:{$this->static_string}";
        
        $signature = base64_encode(hash_hmac('sha256', $signature_string, $this->api_key, true));
        $args = [
            'method'  => $method,
            'timeout' => 30,
           'headers' => [
                'X-API-KEY' => $this->api_key,
                'X-TIMESTAMP' => $timestamp,
                'X-SIGNATURE' => $signature,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
        ];

        if (!empty($data) && $method !== 'GET') {
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            $logger = SMSIR_Error_Logger::get_instance();
            $logger->log(
                sprintf(__('Failed to make template API request: %s', 'sms-ir'), $response->get_error_message()),
                [
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'data' => $data,
                    'error' => $response->get_error_message()
                ]
            );
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || $data['status'] != 1) {
            $logger = SMSIR_Error_Logger::get_instance();
            $logger->log(
                __('Invalid response from template API', 'sms-ir'),
                [
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'data' => $data,
                    'response' => $data
                ]
            );
            return new \WP_Error(
                'smsir_template_error', 
                $data['message'] ?? __('Failed to process template request', 'sms-ir'),
                ['raw_response' => $data]
            );
        }

        return $data['data'];
    }

    public function get_templates($page_number = 1, $page_size = 10) {
        return $this->api_request('GET', '/templates', [
            'pageNumber' => max(1, (int)$page_number),
            'pageSize' => min(100, max(1, (int)$page_size))
        ]);
    }

    public function add_template($title,$template_text) {
        if (empty($title) || empty($template_text)) {
            $logger = SMSIR_Error_Logger::get_instance();
            $logger->log(
                __('Failed to add template: Missing required fields', 'sms-ir'),
                [
                    'title' => $title,
                    'template_text' => $template_text
                ]
            );
            return new \WP_Error('invalid_params', __('Title and template text are required', 'sms-ir'));
        }

        $body = [
            'title' => $title,
            'templateText' => $template_text
        ];

        return $this->api_request('POST', '/templates', $body);
    }

    public function update_template($template_id, $title, $template_text) {
        if (empty($template_id) || empty($title) || empty($template_text)) {
            $logger = SMSIR_Error_Logger::get_instance();
            $logger->log(
                __('Failed to update template: Missing required fields', 'sms-ir'),
                [
                    'template_id' => $template_id,
                    'title' => $title,
                    'template_text' => $template_text
                ]
            );
            return new \WP_Error('invalid_params', __('Template ID, title and template text are required', 'sms-ir'));
        }

        $body = [
            'title' => $title,
            'templateText' => $template_text
        ];
        return $this->api_request('PUT', "/templates/{$template_id}", $body);
    }

    public function delete_template($template_id) {
        if (empty($template_id)) {
            $logger = SMSIR_Error_Logger::get_instance();
            $logger->log(
                __('Failed to delete template: Missing template ID', 'sms-ir'),
                ['template_id' => $template_id]
            );
            return new \WP_Error('invalid_params', __('Template ID is required', 'sms-ir'));
        }

        return $this->api_request('DELETE', "/templates/{$template_id}");
    }

    public function get_template_status($status) {
        $statuses = [
            1 => __('Pending', 'sms-ir'),
            2 => __('Verified', 'sms-ir'),
            3 => __('Unverified', 'sms-ir')
        ];

        return $statuses[$status] ?? __('Unknown', 'sms-ir');
    }
}
