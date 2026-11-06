<?php
namespace SMSIR\Shortcodes;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * SMS.ir Newsletter Shortcode
 */
class SMSIR_Newsletter_Shortcode {
    public function __construct() {
        add_shortcode('smsir_newsletter', [$this, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public function enqueue_scripts() {
        wp_enqueue_script('jquery');
    }

    public function render_shortcode($atts) {
        $atts = shortcode_atts([
            'button_text' => __('Submit', 'sms-ir'),
            'success_message' => __('Thank you! Your phone number has been saved.', 'sms-ir'),
            'input_background_color' => '#ffffff',
            'button_background_color' => '#0073aa',
        ], $atts);

        $widget_id = 'shortcode-' . uniqid();
        
        ob_start();
        ?>
        <div class="smsir-phone-collector" id="smsir-collector-<?php echo esc_attr($widget_id); ?>">
            <form class="smsir-phone-form" onsubmit="return false;">
                <input type="tel" 
                       class="smsir-phone-input" 
                       placeholder="<?php echo esc_attr__('Enter phone number', 'sms-ir'); ?>"
                       pattern="[0-9]{11}"
                       required
                       style="background-color: <?php echo esc_attr($atts['input_background_color']); ?>"
                >
                <button type="submit" class="smsir-submit-btn" style="background-color: <?php echo esc_attr($atts['button_background_color']); ?>">
                    <?php echo esc_html($atts['button_text']); ?>
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
                            $message.html('<?php echo esc_js($atts['success_message']); ?>').addClass('success').removeClass('error').show();
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
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .smsir-submit-btn:hover {
            opacity: 0.9;
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
        return ob_get_clean();
    }
}

// Initialize the shortcode
new SMSIR_Newsletter_Shortcode();
