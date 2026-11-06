<?php
namespace SMSIR;

class SMSClient {
    private $api_key;
    private $api_url = 'https://api.sms.ir/v1/send/verify';
    private $template_id;
    private $line_number;

    public function __construct() {
        $settings = get_option('smsir_settings', []);
        $this->api_key = $settings['settings']['api_key'] ?? '';
        $this->template_id = $settings['settings']['template_id'] ?? null;
        $this->line_number = $settings['settings']['line_number'] ?? null;
    }

    public function send_verification_code($phone, $params= [], $template_id = null) {
        if ($template_id) {
            $this->template_id = $template_id;
        }

        try {
            if (empty($this->api_key)) {
                $logger = SMSIR_Error_Logger::get_instance();
                $logger->log(
                    __('Failed to send verification code: API key not configured', 'sms-ir'),
                    [
                        'endpoint' => 'verify',
                        'phone' => $phone
                    ],
                );
                throw new \Exception(__('API key is not configured', 'sms-ir'));
            }

            $api_params = [];
            foreach ($params as $name => $value) {
                $api_params[] = [
                    'name' => $name,
                    'value' => $value
                ];
            }

            $response = wp_remote_post($this->api_url, [
                'headers' => [
                    'X-API-KEY' => $this->api_key,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'body' => json_encode([
                    'mobile' => $phone,
                    'templateId' => $this->template_id,
                    'parameters' => $api_params
                ])
            ]);

            if (is_wp_error($response)) {
                $logger = SMSIR_Error_Logger::get_instance();
                $logger->log(
                    sprintf(__('Failed to send verification code: %s', 'sms-ir'), $response->get_error_message()),
                    [
                        'endpoint' => 'verify',
                        'phone' => $phone,
                        'template_id' => $this->template_id,
                        'params' => $params,
                        'error' => $response->get_error_message()
                    ],
                );
                return [
                    'success' => false,
                    'message' => $response->get_error_message()
                ];
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (isset($body['status']) && $body['status'] === 1) {
            
                return [
                    'success' => true,
                    'message' => $body['message'] ?? '',
                    'response' => $body
                ];
            } else {
                $logger = SMSIR_Error_Logger::get_instance();
                $logger->log(
                    sprintf(__('Failed to send verification code: %s', 'sms-ir'), $body['message'] ?? __('Unknown error', 'sms-ir')),
                    [
                        'endpoint' => 'verify',
                        'phone' => $phone,
                        'template_id' => $this->template_id,
                        'params' => $params,
                        'response' => $body
                    ],
                );
                return [
                    'success' => false,
                    'message' => $body['message'] ?? __('Unknown error occurred', 'sms-ir')
                ];
            }
        } catch (\Exception $e) {
            $logger = SMSIR_Error_Logger::get_instance();
            $logger->log(
                sprintf(__('Exception while sending verification code: %s', 'sms-ir'), $e->getMessage()),
                [
                    'endpoint' => 'verify',
                    'phone' => $phone,
                    'template_id' => $this->template_id,
                    'params' => $params,
                    'error' => $e->getMessage()
                ],
            );
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function send_bulk_sms($phones, $message, $send_time = null) {
        try {
            if (empty($this->api_key)) {
                $logger = SMSIR_Error_Logger::get_instance();
                $logger->log(
                    __('Failed to send bulk SMS: API key not configured', 'sms-ir'),
                    ['phones' => $phones],
                );
                throw new \Exception(__('API key is not configured', 'sms-ir'));
            }

            if (empty($this->line_number)) {
                $logger = SMSIR_Error_Logger::get_instance();
                $logger->log(
                    __('Failed to send bulk SMS: Line number not configured', 'sms-ir'),
                    ['phones' => $phones],
                );
                throw new \Exception(__('Line number is not configured', 'sms-ir'));
            }

            if (empty($phones) || !is_array($phones)) {
                $logger = SMSIR_Error_Logger::get_instance();
                $logger->log(
                    __('Failed to send bulk SMS: Invalid phone numbers array', 'sms-ir'),
                    ['phones' => $phones],
                );
                throw new \Exception(__('Phone numbers array is required', 'sms-ir'));
            }

            if (count($phones) > 100) {
                $logger = SMSIR_Error_Logger::get_instance();
                $logger->log(
                    __('Failed to send bulk SMS: Too many phone numbers', 'sms-ir'),
                    ['phones' => $phones],
                );
                throw new \Exception(__('Maximum 100 phone numbers allowed', 'sms-ir'));
            }

            if (empty($message)) {
                $logger = SMSIR_Error_Logger::get_instance();
                $logger->log(
                    __('Failed to send bulk SMS: Message text is required', 'sms-ir'),
                    ['phones' => $phones],
                );
                throw new \Exception(__('Message text is required', 'sms-ir'));
            }

            // Validate phone numbers
            foreach ($phones as $phone) {
                if (!preg_match('/^09[0-9]{9}$/', $phone)) {
                    throw new \Exception(sprintf(__('Invalid phone number format: %s', 'sms-ir'), $phone));
                }
            }

            if ($send_time !== null) {
                $current_time = time();
                $max_future_time = $current_time + (365 * 24 * 60 * 60); 
                
                if ($send_time < $current_time) {
                    $logger = SMSIR_Error_Logger::get_instance();
                    $logger->log(
                        __('Failed to send bulk SMS: Send time in past', 'sms-ir'),
                        ['send_time' => $send_time],
                    );
                    throw new \Exception(__('Send time cannot be in the past', 'sms-ir'));
                }
                
                if ($send_time > $max_future_time) {
                    $logger = SMSIR_Error_Logger::get_instance();
                    $logger->log(
                        __('Failed to send bulk SMS: Send time too far in future', 'sms-ir'),
                        ['send_time' => $send_time],
                    );
                    throw new \Exception(__('Send time cannot be more than 365 days in the future', 'sms-ir'));
                }
            }

            $response = wp_remote_post('https://api.sms.ir/v1/send/bulk', [
                'headers' => [
                    'X-API-KEY' => $this->api_key,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'body' => json_encode([
                    'lineNumber' => $this->line_number,
                    'messageText' => $message,
                    'mobiles' => $phones,
                    'sendDateTime' => $send_time
                ])
            ]);
         
            if (is_wp_error($response)) {
                $logger = SMSIR_Error_Logger::get_instance();
                $logger->log(
                    __('Failed to send bulk SMS', 'sms-ir'),
                    [
                        'phones' => $phones,
                        'message' => $message,
                        'send_time' => $send_time,
                        'error' => $response
                    ],
                );
                return [
                    'success' => false,
                    'message' => $response->get_error_message()
                ];
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (isset($body['status']) && $body['status'] === 1) {
            
                return [
                    'success' => true,
                    'message' => $body['message'] ?? __('Success', 'sms-ir'),
                    'data' => $body['data'] ?? null
                ];
            } else {
                $logger = SMSIR_Error_Logger::get_instance();
                $logger->log(
                    __('Failed to send bulk SMS', 'sms-ir'),
                    [
                        'phones' => $phones,
                        'message' => $message,
                        'send_time' => $send_time,
                        'response' => $body
                    ],
                );
                return [
                    'success' => false,
                    'message' => $body['message'] ?? __('Unknown error occurred', 'sms-ir')
                ];
            }
        } catch (\Exception $e) {
            $logger = SMSIR_Error_Logger::get_instance();
            $logger->log(
                sprintf(__('Exception while sending bulk SMS: %s', 'sms-ir'), $e->getMessage()),
                [
                    'phones' => $phones,
                    'message' => $message,
                    'send_time' => $send_time,
                    'error' => $e->getMessage()
                ],
            );
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
} 