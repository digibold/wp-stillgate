<?php
/**
 * REST API write protection.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Enforcement;

use Digibold\DeploymentGuard\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks authenticated REST API requests that would change protected
 * content while a freeze is active, without touching read-only requests.
 *
 * Hooked on `rest_request_before_callbacks`, which runs after routing but
 * before the route's own `permission_callback`, for every request
 * including each sub-request of a `/batch/v1` call (the batch envelope
 * itself is exempt; its sub-requests are gated individually as they are
 * dispatched). By this point `$request->get_method()` already reflects
 * any `_method` query argument or `X-HTTP-Method-Override` header, so
 * neither can be used to disguise a write as a safe method.
 *
 * The route's target (a post type, a taxonomy, or "surface" for widgets
 * and sidebars) is resolved from the matched handler's controller
 * *instance*, not from the URL: `get_rest_controller()` on the relevant
 * `WP_Post_Type`/`WP_Taxonomy` object returns the exact same, cached
 * instance WordPress itself registered the route with, so this is a
 * reliable identity match rather than a guess from the route pattern.
 * A route registered by a plugin with a controller of its own (one that
 * is neither a post type's or taxonomy's controller nor an instance of a
 * core content controller class) is not recognized; the
 * `deployment_guard_rest_is_protected` filter exists for that case.
 */
final class RestGate {

	private Guard $guard;

	/** @var array<int, array{post_type: string}|array{taxonomy: string}>|null */
	private ?array $type_map = null;

	public function __construct( Guard $guard ) {
		$this->guard = $guard;
	}

	public function register(): void {
		add_filter( 'rest_request_before_callbacks', array( $this, 'before_callbacks' ), 10, 3 );
	}

	/**
	 * @param mixed            $response
	 * @param array            $handler
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public function before_callbacks( $response, $handler, $request ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! $request instanceof \WP_REST_Request || ! is_array( $handler ) ) {
			return $response;
		}

		$method = strtoupper( (string) $request->get_method() );

		if ( in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return $response;
		}

		$target = $this->resolve_target( $handler, $request );

		if ( null === $target ) {
			return $response;
		}

		/**
		 * Filters whether a matched REST route is protected by the
		 * current freeze.
		 *
		 * @param bool             $protected Whether the route is protected.
		 * @param \WP_REST_Request $request   The request.
		 * @param array            $handler   The matched route handler.
		 */
		$protected = (bool) apply_filters( 'deployment_guard_rest_is_protected', $target['protected'], $request, $handler );

		if ( ! $protected ) {
			return $response;
		}

		$this->guard->report( 'rest', $target['label'] );

		return $this->guard->rest_error();
	}

	/**
	 * @param array $handler
	 * @return array{protected: bool, label: string}|null Null when this
	 *                                                     route is not
	 *                                                     something the
	 *                                                     freeze governs.
	 */
	private function resolve_target( array $handler, \WP_REST_Request $request ): ?array {
		$callback = $handler['callback'] ?? null;

		if ( ! is_array( $callback ) || ! isset( $callback[0] ) || ! is_object( $callback[0] ) ) {
			return null;
		}

		$controller = $callback[0];

		if ( $this->is_exempt( $controller ) ) {
			return null;
		}

		if ( ( class_exists( '\WP_REST_Widgets_Controller' ) && $controller instanceof \WP_REST_Widgets_Controller )
			|| ( class_exists( '\WP_REST_Sidebars_Controller' ) && $controller instanceof \WP_REST_Sidebars_Controller )
		) {
			return array(
				'protected' => $this->guard->applies_to_surface(),
				'label'     => 'surface:widgets',
			);
		}

		if ( null === $this->type_map ) {
			$this->type_map = $this->build_type_map();
		}

		$object_id = spl_object_id( $controller );

		if ( isset( $this->type_map[ $object_id ]['post_type'] ) ) {
			$post_type = $this->type_map[ $object_id ]['post_type'];

			return array(
				'protected' => $this->guard->applies_to_post_type( $post_type ),
				'label'     => 'post_type:' . $post_type,
			);
		}

		if ( isset( $this->type_map[ $object_id ]['taxonomy'] ) ) {
			$taxonomy = $this->type_map[ $object_id ]['taxonomy'];

			return array(
				'protected' => $this->guard->applies_to_taxonomy( $taxonomy, $this->taxonomy_mode( $taxonomy, $request ) ),
				'label'     => 'taxonomy:' . $taxonomy,
			);
		}

		return $this->resolve_fallback( $controller );
	}

	/**
	 * Which taxonomy capability a write through a terms route actually
	 * needs: core lets a user who can merely *assign* a non-hierarchical
	 * taxonomy's terms create new ones through the collection route
	 * (WP_REST_Terms_Controller::create_item_permissions_check()); every
	 * other term write needs the taxonomy's edit capability.
	 */
	private function taxonomy_mode( string $taxonomy, \WP_REST_Request $request ): string {
		$url_params = $request->get_url_params();

		if ( 'POST' === strtoupper( (string) $request->get_method() )
			&& ! isset( $url_params['id'] )
			&& ! is_taxonomy_hierarchical( $taxonomy )
		) {
			return 'assign';
		}

		return 'edit';
	}

	/**
	 * A conservative fallback for a controller this class did not map by
	 * identity above: any instance of a core content controller base
	 * class is treated as protected, checked against the same generic
	 * `edit_posts` primitive AdminGate falls back to for an unresolvable
	 * object. This only ever widens protection, never narrows it, so a
	 * registration difference on a WordPress version this plugin has not
	 * been tested against fails safe.
	 *
	 * @return array{protected: bool, label: string}|null
	 */
	private function resolve_fallback( object $controller ): ?array {
		$bases = array( '\WP_REST_Posts_Controller', '\WP_REST_Terms_Controller', '\WP_REST_Revisions_Controller' );

		foreach ( array( 'WP_REST_Templates_Controller', 'WP_REST_Global_Styles_Controller', 'WP_REST_Font_Families_Controller', 'WP_REST_Font_Faces_Controller' ) as $class_name ) {
			if ( class_exists( $class_name ) ) {
				$bases[] = '\\' . $class_name;
			}
		}

		foreach ( $bases as $base ) {
			if ( $controller instanceof $base ) {
				return array(
					'protected' => $this->guard->applies_generic( 'edit_posts' ),
					'label'     => 'fallback:' . get_class( $controller ),
				);
			}
		}

		return null;
	}

	private function is_exempt( object $controller ): bool {
		if ( $controller instanceof \WP_REST_Server ) {
			// The /batch/v1 envelope itself; its sub-requests are matched
			// and gated individually as they are dispatched.
			return true;
		}

		if ( class_exists( '\WP_REST_Block_Renderer_Controller' ) && $controller instanceof \WP_REST_Block_Renderer_Controller ) {
			return true; // Server-side block rendering: read-only in effect.
		}

		if ( class_exists( '\WP_REST_Widget_Types_Controller' ) && $controller instanceof \WP_REST_Widget_Types_Controller ) {
			return true; // Encoding/rendering a widget preview: read-only in effect.
		}

		if ( $controller instanceof \WP_REST_Comments_Controller ) {
			return true; // Comments and Notes are out of scope; see docs/hooks.md.
		}

		return false;
	}

	/**
	 * @return array<int, array{post_type: string}|array{taxonomy: string}>
	 */
	private function build_type_map(): array {
		$map = array();

		foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $post_type ) {
			$controller = $post_type->get_rest_controller();

			if ( $controller ) {
				$map[ spl_object_id( $controller ) ] = array( 'post_type' => $post_type->name );
			}

			if ( is_callable( array( $post_type, 'get_revisions_rest_controller' ) ) ) {
				$revisions = $post_type->get_revisions_rest_controller();

				if ( $revisions ) {
					$map[ spl_object_id( $revisions ) ] = array( 'post_type' => $post_type->name );
				}
			}

			if ( is_callable( array( $post_type, 'get_autosave_rest_controller' ) ) ) {
				$autosaves = $post_type->get_autosave_rest_controller();

				if ( $autosaves ) {
					$map[ spl_object_id( $autosaves ) ] = array( 'post_type' => $post_type->name );
				}
			}
		}

		foreach ( get_taxonomies( array( 'show_in_rest' => true ), 'objects' ) as $taxonomy ) {
			$controller = $taxonomy->get_rest_controller();

			if ( $controller ) {
				$map[ spl_object_id( $controller ) ] = array( 'taxonomy' => $taxonomy->name );
			}
		}

		return $map;
	}
}
