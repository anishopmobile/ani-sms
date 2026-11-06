<?php
if (!defined('ABSPATH')) {
    exit;
}

add_filter('gform_field_groups', 'smsir_add_phone_verify_field');
add_filter('gform_field_type_title', 'smsir_phone_verify_field_title', 10, 2);
add_action('gform_editor_js', 'smsir_gf_editor_script');

function smsir_add_phone_verify_field($field_groups) {
    foreach ($field_groups as &$group) {
        if ($group['name'] === 'standard_fields') {
            $group['fields'][] = array(
                'class'     => 'button',
                'value'     => __('Phone Verification', 'sms-ir'),
                'data-type' => 'smsir_phone_verify',
                'onclick'   => "StartAddField('smsir_phone_verify');"
            );
            break;
        }
    }
    return $field_groups;
}

function smsir_phone_verify_field_title($title, $field_type) {
    if ($field_type == 'smsir_phone_verify') {
        return __('Phone Verification', 'sms-ir');
    }
    return $title;
}

function smsir_gf_editor_script() {
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            if (typeof fieldSettings !== 'undefined') {
                fieldSettings['smsir_phone_verify'] = '.label_setting, .description_setting, .rules_setting, .error_message_setting, .css_class_setting, .visibility_setting, .conditional_logic_field_setting';
            }
            
            if (typeof fieldTypes !== 'undefined') {
                fieldTypes['smsir_phone_verify'] = {
                    icon: 'gform-icon--phone',
                    name: '<?php echo esc_js(__('Phone Verification', 'sms-ir')); ?>'
                };
            }
        });
    </script>
    <?php
}