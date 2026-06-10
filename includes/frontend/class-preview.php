<?php
/**
 * Admin-only frontend preview of a single form.
 *
 * Renders the form in a minimal HTML document that still runs wp_head() /
 * wp_footer(), so the active theme's CSS applies — the editor iframes this
 * to show exactly what visitors will see.
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms\Frontend;

use Rapid_Ai_Forms\Forms\Form_Renderer;
use Rapid_Ai_Forms\Forms\Form_Repository;

defined( 'ABSPATH' ) || exit;

class Preview {

	const QUERY_VAR = 'rapid_ai_form_preview';

	public function register() {
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
	}

	public function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public function maybe_render() {
		$form_id = (int) get_query_var( self::QUERY_VAR );
		if ( ! $form_id ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			status_header( 403 );
			exit;
		}

		$form = ( new Form_Repository() )->get( $form_id );
		if ( ! $form ) {
			status_header( 404 );
			exit;
		}

		nocache_headers();
		header( 'X-Frame-Options: SAMEORIGIN' );

		wp_enqueue_script( 'rapid-ai-forms-frontend' );
		wp_enqueue_style( 'rapid-ai-forms-frontend' );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Form_Renderer escapes internally; language_attributes/body_class are core template tags.
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
		<?php wp_head(); ?>
	<style>
		/* Neutral canvas around the form; theme styles still apply to the form itself. */
		.raif-preview-frame { padding: 24px; }
	</style>
</head>
<body <?php body_class( 'raif-preview' ); ?>>
	<div class="raif-preview-frame">
		<?php echo ( new Form_Renderer() )->render( $form ); ?>
	</div>
		<?php wp_footer(); ?>
</body>
</html>
		<?php
		// phpcs:enable
		exit;
	}
}
