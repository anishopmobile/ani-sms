<?php
namespace SMSIR\Widgets;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * SMS.ir Phone Collector Widget
 */
class  SMSIR_Newsletter_Sign_Widget extends \Elementor\Widget_Base {

    public function get_name() {
        return 'smsir_newsletter_sign';
    }

    public function get_title() {
        return esc_html__('SMS.ir Newsletter', 'sms-ir');
    }

    public function get_icon() {
        return 'eicon-form-horizontal';
    }

    public function get_categories() {
        return ['general'];
    }

    public function get_keywords() {
        return ['sms', 'phone', 'collector', 'sms.ir'];
    }

    protected function register_controls() {
        $this->start_controls_section(
            'content_section',
            [
                'label' => esc_html__('Content', 'sms-ir'),
                'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );


        $this->add_control(
            'button_text',
            [
                'label' => esc_html__('Button Text', 'sms-ir'),
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => esc_html__('Submit', 'sms-ir'),
            ]
        );

        $this->add_control(
            'success_message',
            [
                'label' => esc_html__('Success Message', 'sms-ir'),
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => esc_html__('Thank you! Your phone number has been saved.', 'sms-ir'),
            ]
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'style_section',
            [
                'label' => esc_html__('Style', 'sms-ir'),
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'input_background_color',
            [
                'label' => esc_html__('Input Background Color', 'sms-ir'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .smsir-phone-input' => 'background-color: {{VALUE}}',
                ],
            ]
        );

        $this->add_control(
            'button_background_color',
            [
                'label' => esc_html__('Button Background Color', 'sms-ir'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .smsir-submit-btn' => 'background-color: {{VALUE}}',
                ],
            ]
        );

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        $widget_id = $this->get_id();
        ?>
        <div class="smsir-phone-collector" id="smsir-collector-<?php echo esc_attr($widget_id); ?>">
   
            
            <form class="smsir-phone-form" onsubmit="return false;">
                <input type="tel" 
                       class="smsir-phone-input" 
                       placeholder="<?php echo esc_attr__('Enter phone number', 'sms-ir'); ?>"
                       pattern="[0-9]{11}"
                       required
                >
                <button type="submit" class="smsir-submit-btn">
                    <?php echo esc_html($settings['button_text']); ?>
                </button>
            </form>
            
            <div class="smsir-message" style="display: none;"></div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#smsir-collector-<?php echo esc_js($widget_id); ?> form').on('submit', function(e) {
                e.preventDefault();
                
                var $form = $(this);
                var $message = $form.siblings('.smsir-message');
                var phone = $form.find('.smsir-phone-input').val();
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'sms_ir_save_phone',
                        phone: phone,
                        nonce: '<?php echo wp_create_nonce('sms_ir_save_phone'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $message.html('<?php echo esc_js($settings['success_message']); ?>').addClass('success').removeClass('error').show();
                            $form[0].reset();
                        } else {
                            $message.html(response.data).addClass('error').removeClass('success').show();
                        }
                    },
                    error: function() {
                        $message.html('<?php echo esc_js(__('An error occurred. Please try again.', 'sms-ir')); ?>').addClass('error').removeClass('success').show();
                    }
                });
            });
        });
        </script>

        <style>
        .smsir-phone-collector {
            max-width: 400px;
            margin: 0 auto;
            padding: 20px;
        }
        .smsir-phone-form {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        .smsir-phone-input {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .smsir-submit-btn {
            padding: 8px 20px;
            background-color: #0073aa;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .smsir-submit-btn:hover {
            background-color: #005177;
        }
        .smsir-message {
            margin-top: 15px;
            padding: 10px;
            border-radius: 4px;
        }
        .smsir-message.success {
            background-color: #d4edda;
            color: #155724;
        }
        .smsir-message.error {
            background-color: #f8d7da;
            color: #721c24;
        }
        </style>
        <?php
    }
}