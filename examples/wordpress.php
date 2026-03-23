<?php
/**
 * Xident PHP SDK — WordPress Integration Example
 *
 * Add this to your theme's functions.php or a custom plugin.
 */

// Load SDK (if not using Composer)
// require_once __DIR__ . '/xident-php/autoload.php';

use Xident\SDK\Client;
use Xident\SDK\Exceptions\XidentException;

/**
 * Register shortcode: [xident_verify min_age="18"]
 */
add_shortcode('xident_verify', function (array $atts): string {
    $atts = shortcode_atts([
        'min_age' => 18,
        'text'    => 'Verify Your Age',
    ], $atts);

    $url = esc_url(add_query_arg('action', 'xident_start', admin_url('admin-ajax.php')));
    $text = esc_html($atts['text']);

    return "<a href=\"{$url}\" class=\"xident-verify-btn\">{$text}</a>";
});

/**
 * AJAX handler: start verification.
 */
add_action('wp_ajax_xident_start', function (): void {
    $apiKey = get_option('xident_secret_key', '');
    if ($apiKey === '') {
        wp_die('Xident not configured');
    }

    $xident = new Client(apiKey: $apiKey);

    try {
        $session = $xident->verification()->init([
            'callback_url' => home_url('/xident-callback/'),
            'min_age'      => (int) get_option('xident_min_age', 18),
            'success_url'  => home_url('/age-verified/'),
            'failed_url'   => home_url('/verification-failed/'),
            'user_id'      => (string) get_current_user_id(),
        ]);

        // Redirect is to a known Xident domain — safe
        if (str_starts_with($session->verifyUrl, 'https://verify.xident.io')) {
            wp_redirect($session->verifyUrl);
            exit;
        }

        wp_die('Invalid verify URL');
    } catch (XidentException $e) {
        wp_die('Verification error: ' . esc_html($e->getMessage()));
    }
});

/**
 * Handle callback: verify result and store in user meta.
 */
add_action('template_redirect', function (): void {
    if (!is_page('xident-callback')) {
        return;
    }

    $sessionId = filter_input(INPUT_GET, 'session_id', FILTER_SANITIZE_SPECIAL_CHARS);
    if (!$sessionId) {
        wp_redirect(home_url('/verification-failed/'));
        exit;
    }

    $apiKey = get_option('xident_secret_key', '');
    $xident = new Client(apiKey: $apiKey);

    try {
        $result = $xident->verification()->getResult($sessionId);

        if ($result->isVerified()) {
            update_user_meta(get_current_user_id(), 'age_verified', true);
            update_user_meta(get_current_user_id(), 'age_bracket', $result->ageBracket());
            wp_redirect(home_url('/age-verified/'));
        } else {
            wp_redirect(home_url('/verification-failed/'));
        }
    } catch (XidentException $e) {
        wp_redirect(home_url('/verification-failed/'));
    }

    exit;
});
