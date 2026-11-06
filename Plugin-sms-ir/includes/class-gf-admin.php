<?php
namespace SMSIR;


class GravityAdmin {
    private static $instance = null;


    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('wp_ajax_sms_ir_get_forms', [$this, 'ajax_get_forms']);
        add_action('wp_ajax_sms_ir_get_form_fields', [$this, 'ajax_get_form_fields']);
        add_action('wp_ajax_sms_ir_save_form_notification_rows', [$this, 'ajax_save_form_notification_rows']);
        add_action('wp_ajax_sms_ir_get_form_notification_rows', [$this, 'ajax_get_form_notification_rows']);
    }
    

    public function ajax_get_gravity_forms() {
   
        if (!class_exists('GFAPI')) {
            wp_send_json_error('gravity_forms_inactive');
            return;
        }
        
        $forms = \GFAPI::get_forms();
        
        $response = array_map(function($form) {
            return [
                'id' => $form['id'],
                'title' => $form['title'],
                'description' => isset($form['description']) ? $form['description'] : ''
            ];
        }, $forms);
        
        wp_send_json_success($response);
    }
    

    public function ajax_get_gravity_form_fields() {
        
        
        if (!isset($_POST['form_id'])) {
            wp_send_json_error('Form ID is required');
            return;
        }
        
        $form_id = intval($_POST['form_id']);
        
        if (!class_exists('GFAPI')) {
            wp_send_json_error('gravity_forms_inactive');
            return;
        }
        
        $form = \GFAPI::get_form($form_id);
        
        if (!$form) {
            wp_send_json_error('Form not found');
            return;
        }
        
        $fields = array_map(function($field) {
            return [
                'id' => $field['id'],
                'label' => isset($field['label']) ? $field['label'] : '',
                'type' => $field['type']
            ];
        }, $form['fields']);
        
        wp_send_json_success($fields);
    }
    

    public function ajax_save_form_notification_rows() {
        if (!isset($_POST['rows'])) {
            wp_send_json_error('Notification rows data is required');
            return;
        }
        
        update_option('sms_ir_form_notification_rows', sanitize_text_field($_POST['rows']));
        
        wp_send_json_success('Settings saved successfully');
    }


    public function ajax_get_form_notification_rows() {
        // Check if at least one form plugin is active
        $has_active_plugin = false;
        
        if (class_exists('GFAPI')) {
            $has_active_plugin = true;
        }
        
        if (class_exists('WPCF7')) {
            $has_active_plugin = true;
        }
        
        if (class_exists('WPForms')) {
            $has_active_plugin = true;
        }
        
        if (!$has_active_plugin) {
            wp_send_json_error('no_form_plugins_active');
            return;
        }
        
        $rows = get_option('sms_ir_form_notification_rows', '[]');
            
        $rows_array = json_decode(stripslashes($rows), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_success([]);
            return;
        }
        
        wp_send_json_success($rows_array);
    }

    public function ajax_get_forms() {
        if (!isset($_POST['form_type'])) {
            wp_send_json_error('Form type is required');
            return;
        }

        $form_type = sanitize_text_field($_POST['form_type']);
        
        // Special case for checking plugin status
        if ($form_type === 'check_plugins') {
            $inactive_plugins = [];
            
            if (!class_exists('GFAPI')) {
                $inactive_plugins[] = 'gravity_forms_inactive';
            }
            
            if (!class_exists('WPCF7')) {
                $inactive_plugins[] = 'contact_form_7_inactive';
            }

            if (!class_exists('WPForms')) {
                $inactive_plugins[] = 'wpforms_inactive';
            }
            
            wp_send_json_success($inactive_plugins);
            return;
        }

        $forms = [];

        switch ($form_type) {
            case 'gravity_forms':
                if (!class_exists('GFAPI')) {
                    wp_send_json_error('gravity_forms_inactive');
                    return;
                }
                
                $forms = \GFAPI::get_forms();
                if (empty($forms)) {
                    wp_send_json_error('no_forms_found');
                    return;
                }
                $forms = array_map(function($form) {
                    return [
                        'id' => $form['id'],
                        'title' => $form['title'],
                        'description' => isset($form['description']) ? $form['description'] : ''
                    ];
                }, $forms);
                break;

            case 'contact_form_7':
                if (!class_exists('WPCF7')) {
                    wp_send_json_error('contact_form_7_inactive');
                    return;
                }
                
                $forms = \WPCF7_ContactForm::find();
                if (empty($forms)) {
                    wp_send_json_error('no_forms_found');
                    return;
                }
                $forms = array_map(function($form) {
                    return [
                        'id' => $form->id(),
                        'title' => $form->title(),
                        'description' => $form->prop('description') ?? ''
                    ];
                }, $forms);
                break;

            case 'wpforms':
                if (!class_exists('WPForms')) {
                    wp_send_json_error('wpforms_inactive');
                    return;
                }
                
                $wpforms = wpforms()->form->get();
                if (empty($wpforms)) {
                    wp_send_json_error('no_forms_found');
                    return;
                }
                $forms = array_map(function($form) {
                    return [
                        'id' => $form->ID,
                        'title' => $form->post_title,
                        'description' => $form->post_excerpt
                    ];
                }, $wpforms);
                break;

            default:
                wp_send_json_error('Invalid form type');
                return;
        }

        wp_send_json_success($forms);
    }

    public function ajax_get_form_fields() {
        if (!isset($_POST['form_id'])) {
            wp_send_json_error('Form ID is required');
            return;
        }
        
        $form_id = intval($_POST['form_id']);
        $form_type = isset($_POST['form_type']) ? sanitize_text_field($_POST['form_type']) : 'gravity_forms';
        
        $fields = [];

        switch ($form_type) {
            case 'gravity_forms':
                if (!class_exists('GFAPI')) {
                    wp_send_json_error('gravity_forms_inactive');
                    return;
                }
                
                $form = \GFAPI::get_form($form_id);
                if (!$form) {
                    wp_send_json_error('Form not found');
                    return;
                }
                
                $fields = array_map(function($field) {
                    return [
                        'id' => $field['id'],
                        'label' => isset($field['label']) ? $field['label'] : '',
                        'type' => $field['type'],
                        'placeholder' => '#' . $field['type'] . $field['id'] . '#',
                        'required' => isset($field['isRequired']) ? $field['isRequired'] : false,
                        'options' => isset($field['choices']) ? $field['choices'] : []
                    ];
                }, $form['fields']);
                break;
                
            case 'contact_form_7':
                if (!class_exists('WPCF7')) {
                    wp_send_json_error('contact_form_7_inactive');
                    return;
                }
                $form = \WPCF7_ContactForm::get_instance($form_id);
                if (!$form) {
                    wp_send_json_error('Form not found');
                    return;
                }
                
                $form_tags = $form->scan_form_tags();
                foreach ($form_tags as $tag) {
                    if (empty($tag->name)) {
                        continue;
                    }
                    
                    $fields[] = [
                        'id' => $tag->name,
                        'label' => $tag->label,
                        'type' => $tag->type,
                        'placeholder' => '#' . $tag->name . '#',
                        'required' => $tag->is_required(),
                        'options' => in_array($tag->type, ['select', 'radio', 'checkbox']) ? $tag->values : []
                    ];
                }
                break;
                
            case 'wpforms':
                if (!class_exists('WPForms')) {
                    wp_send_json_error('wpforms_inactive');
                    return;
                }
                
                $form = wpforms()->form->get($form_id);
                if (!$form) {
                    wp_send_json_error('Form not found');
                    return;
                }
                
                $form_data = json_decode($form->post_content, true);
                if (isset($form_data['fields'])) {
                    foreach ($form_data['fields'] as $field) {
                        $fields[] = [
                            'id' => $field['id'],
                            'label' => isset($field['label']) ? $field['label'] : '',
                            'type' => $field['type'],
                            'placeholder' => '#' . $field['type'] . $field['id'] . '#',
                            'required' => isset($field['required']) ? $field['required'] : false,
                            'options' => isset($field['choices']) ? $field['choices'] : []
                        ];
                    }
                }
                break;
                
            default:
                wp_send_json_error('Invalid form type');
                return;
        }
        
        wp_send_json_success($fields);
    }
}