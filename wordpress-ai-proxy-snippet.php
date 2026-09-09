<?php
/**
 * Sales Process Builder — AI generation proxy
 *
 * Add this to your child theme's functions.php, or paste it into a
 * snippet plugin (e.g. WPCode / Code Snippets). Do NOT add it via a
 * "Custom HTML" block — this is PHP, it needs to run server-side.
 *
 * SETUP:
 * 1. Get an API key from https://console.anthropic.com
 * 2. Add this line to wp-config.php (above "That's all, stop editing!"):
 *      define( 'ANTHROPIC_API_KEY', 'sk-ant-xxxxxxxx' );
 *    This keeps the key out of your database and theme files.
 * 3. Add the code below to your theme/snippet plugin.
 * 4. Edit the SPB_ALLOWED_ORIGIN constant below to match the exact domain
 *    your HTML tool is hosted on (e.g. your GitHub Pages URL).
 * 5. Confirm the widget's AI_ENDPOINT in the HTML matches this route,
 *    using your site's full URL:
 *      https://yoursite.com/wp-json/spb/v1/generate
 */

/**
 * ⚠️ EDIT THIS: the exact origin your tool is hosted on (GitHub Pages, etc.)
 * Must match exactly — scheme + domain, no trailing slash, no path.
 * e.g. 'https://yourusername.github.io'
 */
define( 'SPB_ALLOWED_ORIGIN', 'https://REPLACE-WITH-YOUR-GITHUB-PAGES-DOMAIN.github.io' );

add_action( 'rest_api_init', function () {
	register_rest_route( 'spb/v1', '/generate', array(
		'methods'             => 'POST',
		'callback'            => 'spb_generate_sales_process',
		'permission_callback' => '__return_true', // public endpoint, rate-limited below
	) );

	// Allow cross-origin requests from the configured origin only.
	add_filter( 'rest_pre_serve_request', function ( $served, $result, $request ) {
		if ( strpos( $request->get_route(), '/spb/v1/generate' ) !== false ) {
			header( 'Access-Control-Allow-Origin: ' . SPB_ALLOWED_ORIGIN );
			header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Content-Type' );
		}
		return $served;
	}, 10, 3 );
} );

// Handle the browser's CORS preflight (OPTIONS) request before WP's normal routing.
add_action( 'init', function () {
	if (
		isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === $_SERVER['REQUEST_METHOD']
		&& isset( $_SERVER['REQUEST_URI'] ) && strpos( $_SERVER['REQUEST_URI'], '/wp-json/spb/v1/generate' ) !== false
	) {
		header( 'Access-Control-Allow-Origin: ' . SPB_ALLOWED_ORIGIN );
		header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type' );
		status_header( 204 );
		exit;
	}
} );

function spb_generate_sales_process( WP_REST_Request $request ) {

	// ---- Basic per-IP rate limiting (5 requests / hour) ----
	$ip           = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
	$rate_key     = 'spb_rate_' . md5( $ip );
	$request_count = (int) get_transient( $rate_key );

	if ( $request_count >= 5 ) {
		return new WP_REST_Response(
			array( 'message' => 'Too many requests. Please try again later.' ),
			429
		);
	}
	set_transient( $rate_key, $request_count + 1, HOUR_IN_SECONDS );

	// ---- Validate input ----
	$description = sanitize_textarea_field( $request->get_param( 'description' ) );
	$motion      = sanitize_text_field( $request->get_param( 'motion' ) );
	$step_count  = sanitize_text_field( $request->get_param( 'step_count' ) );

	if ( empty( $description ) ) {
		return new WP_REST_Response( array( 'message' => 'Description is required.' ), 400 );
	}
	if ( strlen( $description ) > 2000 ) {
		return new WP_REST_Response( array( 'message' => 'Description is too long.' ), 400 );
	}

	if ( ! defined( 'ANTHROPIC_API_KEY' ) || ! ANTHROPIC_API_KEY ) {
		return new WP_REST_Response( array( 'message' => 'AI generation is not configured on this server.' ), 500 );
	}

	// ---- Build the prompt ----
	$system_prompt = 'You design sales processes for entrepreneurs and independent sales professionals. '
		. 'A sales process is a sequence of Action Steps, where each Action Step leads to a Call Objective '
		. '(the outcome that must be true before moving on), and that objective connects into the next Action Step. '
		. 'Keep steps concrete, specific to the business described, and ordered from first contact to close. '
		. 'Respond with ONLY raw JSON — no markdown fences, no commentary.';

	$user_message = "Business/context: {$description}\n";
	if ( $motion ) {
		$user_message .= "Sales motion: {$motion}\n";
	}
	$user_message .= $step_count
		? "Target step count: {$step_count}\n"
		: "Target step count: between 3 and 8, whatever best fits this process.\n";
	$user_message .= 'Return a JSON array of step objects, each with exactly these keys: '
		. '"action" (specific action the seller takes), '
		. '"objective" (the call objective / outcome needed before the next step), '
		. '"tag" (one of: Discovery, Qualification, Demo, Proposal, Negotiation, Close, Follow-up, Other).';

	// ---- Call the Anthropic API ----
	$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
		'timeout' => 30,
		'headers' => array(
			'Content-Type'      => 'application/json',
			'x-api-key'         => ANTHROPIC_API_KEY,
			'anthropic-version' => '2023-06-01',
		),
		'body'    => wp_json_encode( array(
			'model'      => 'claude-sonnet-4-6',
			'max_tokens' => 1000,
			'system'     => $system_prompt,
			'messages'   => array(
				array( 'role' => 'user', 'content' => $user_message ),
			),
		) ),
	) );

	if ( is_wp_error( $response ) ) {
		return new WP_REST_Response( array( 'message' => 'Could not reach the AI service.' ), 502 );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( empty( $body['content'] ) || ! is_array( $body['content'] ) ) {
		return new WP_REST_Response( array( 'message' => 'Unexpected response from AI service.' ), 502 );
	}

	// Collect text blocks, strip any accidental markdown fences
	$text = '';
	foreach ( $body['content'] as $block ) {
		if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
			$text .= $block['text'];
		}
	}
	$text = trim( preg_replace( '/```json|```/', '', $text ) );

	$steps = json_decode( $text, true );

	if ( ! is_array( $steps ) || empty( $steps ) ) {
		return new WP_REST_Response( array( 'message' => 'Could not parse a sales process from the AI response.' ), 502 );
	}

	// Cap at 8 steps and return only the fields the widget expects
	$clean = array();
	foreach ( array_slice( $steps, 0, 8 ) as $step ) {
		$clean[] = array(
			'action'    => isset( $step['action'] ) ? sanitize_text_field( $step['action'] ) : '',
			'objective' => isset( $step['objective'] ) ? sanitize_text_field( $step['objective'] ) : '',
			'tag'       => isset( $step['tag'] ) ? sanitize_text_field( $step['tag'] ) : 'Other',
		);
	}

	return new WP_REST_Response( $clean, 200 );
}
