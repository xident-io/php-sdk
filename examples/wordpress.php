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
 * The minimum age this site requires: the `xident_min_age` option (12 to 25,
 * rounded up to 12, 15, 18, 21 or 25). It is read on the server for both the
 * start and the callback; never take it from the request.
 */
function xident_required_min_age(): int
{
    return (int) get_option('xident_min_age', 18);
}

/**
 * Register shortcode: [xident_verify text="Verify Your Age"]
 */
add_shortcode('xident_verify', function (array $atts): string {
    $atts = shortcode_atts([
        'text' => 'Verify Your Age',
    ], $atts);

    $url = esc_url(add_query_arg('action', 'xident_start', admin_url('admin-ajax.php')));
    $text = esc_html($atts['text']);

    return "<a href=\"{$url}\" class=\"xident-verify-btn\">{$text}</a>";
});

/**
 * AJAX handler: start verification.
 * wp_ajax_ (without nopriv) runs for logged-in users only, so the user id is set.
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
            'user_id'      => (string) get_current_user_id(),           // required
            'min_age'      => xident_required_min_age(),
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
 * Handle callback: verify the result and store it in user meta.
 */
add_action('template_redirect', function (): void {
    if (!is_page('xident-callback')) {
        return;
    }

    // The result must belong to the signed-in user.
    $userId = get_current_user_id();
    $token = filter_input(INPUT_GET, 'token', FILTER_SANITIZE_SPECIAL_CHARS);
    if ($userId === 0 || !$token) {
        wp_redirect(home_url('/verification-failed/'));
        exit;
    }

    $apiKey = get_option('xident_secret_key', '');
    $xident = new Client(apiKey: $apiKey);

    try {
        $result = $xident->verification()->getResult($token);

        // Grant only when both hold:
        // 1. The result belongs to this user. Compare with the id this server
        //    sent as user_id, never with the user_id in the callback URL: a
        //    result token copied from someone else's callback is a real
        //    success, for somebody else.
        // 2. It proves the age this site requires. An 18+ result does not
        //    open a 21+ page, and an ID-only result proves no age.
        if ($result->externalUserId === (string) $userId
            && $result->provesAge(xident_required_min_age())
        ) {
            update_user_meta($userId, 'age_verified', true);
            update_user_meta($userId, 'age_bracket', $result->ageBracket());
            wp_redirect(home_url('/age-verified/'));
        } else {
            wp_redirect(home_url('/verification-failed/'));
        }
    } catch (XidentException $e) {
        wp_redirect(home_url('/verification-failed/'));
    }

    exit;
});
