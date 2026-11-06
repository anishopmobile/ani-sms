<?php
/**
 * Template Name: SMSIR Login Page
 */

// Prevent direct access
defined('ABSPATH') || exit("no access");

// Set the page title
$page_title = __('Login', 'sms-ir');
add_filter('pre_get_document_title', function() use ($page_title) {
    return $page_title;
});

get_header(); ?>

<div class="smsir-login-page">
    <?php echo do_shortcode('[smsir_login]'); ?>
</div>

<?php get_footer(); ?> 