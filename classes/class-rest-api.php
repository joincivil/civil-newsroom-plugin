<?php
/**
 * Handles all logic related to the REST API.
 *
 * @package Civil_Publisher
 */

namespace Civil_Publisher;

/**
 * The REST_API class.
 */
class REST_API {
	use Singleton;

	/**
	 * Setup the class.
	 */
	public function setup() {
		add_action( 'rest_api_init', array( $this, 'register_endpoint' ) );
	}

	/**
	 * Register endpoints.
	 */
	public function register_endpoint() {
		// Endpoint for returning revision payload.
		register_rest_route(
			REST_API_NAMESPACE,
			'/revisions/(?P<revisionID>\d+)',
			array(
				'methods'  => 'GET',
				'callback' => array( $this, 'get_revision_payload' ),
			)
		);

		// Endpoint for returning latest revision ID for a post. This is needed because our disabling of `wp_save_post_revision_check_for_changes` throws off how Gutenberg detects latest revision.
		register_rest_route(
			REST_API_NAMESPACE, '/posts/(?P<postID>\d+)/last-revision-id', array(
				'methods'  => 'GET',
				'callback' => array( $this, 'get_last_revision_id' ),
			)
		);

		// Endpoint for returning revision content based on hash value.
		register_rest_route(
			REST_API_NAMESPACE,
			'/revisions-content/(?P<hash>\w+)',
			array(
				'methods'  => 'GET',
				'callback' => array( $this, 'get_revision_content_from_hash' ),
			)
		);

		// Endpoint for fetching user by given ETH address.
		register_rest_route(
			REST_API_NAMESPACE,
			'/user-by-eth-address/(?P<address>\w+)',
			array(
				'methods'  => 'GET',
				'callback' => array( $this, 'get_user_by_eth_address' ),
			)
		);

		// Endpoint for setting custom user meta.
		register_rest_route(
			REST_API_NAMESPACE, '/users/(?P<user_id>\w+)', array(
				'methods'  => 'POST',
				'callback' => array( $this, 'set_custom_user_meta' ),
				'permission_callback' => array( $this, 'set_custom_user_meta_check' ),
			)
		);
	}

	/**
	 * Returns revision payload which is used by the smart contract system.
	 *
	 * @param \WP_REST_Request $request The current REST API request.
	 * @return \WP_REST_Response|\WP_Error The REST API response or an error.
	 */
	public function get_revision_payload( \WP_REST_Request $request ) {
		// Get parameters from request.
		$params = $request->get_params();

		// Get the revision ID.
		$revision_id = $params['revisionID'] ?? 0;

		// No revision ID provided.
		if ( empty( $revision_id ) ) {
			return new \WP_Error(
				'no-revision-id-found',
				esc_html__( 'No revision ID provided.' ),
				array(
					'status' => 400,
				)
			);
		}

		// Get the revision.
		$revision = get_post( $revision_id );

		// Unable to find a revision.
		if ( ! ( $revision instanceof \WP_Post ) ) {
			return new \WP_Error(
				'no-post-found',
				esc_html__( 'No post found matching the provided revision ID.' ),
				array(
					'status' => 400,
				)
			);
		}

		// Only return published revisions.
		if ( ! Post_Hashing::instance()->can_save_hash( $revision->post_parent ) ) {
			return new \WP_Error(
				'post-not-published',
				esc_html__( 'This post revision is not publiushed.' ),
				array(
					'status' => 400,
				)
			);
		}

		return rest_ensure_response( $this->clean_item( $revision ) );
	}

	/**
	 * Returns the last revision ID for given post.
	 *
	 * @param \WP_REST_Request $request The current REST API request.
	 * @return \WP_REST_Response|\WP_Error The REST API response with revision ID, or an error.
	 */
	public function get_last_revision_id( \WP_REST_Request $request ) {
		// Get parameters from request.
		$params = $request->get_params();

		// Get the revision ID.
		$post_id = $params['postID'] ?? 0;

		// No post ID provided.
		if ( empty( $post_id ) ) {
			return new \WP_Error(
				'no-post-id-found',
				esc_html__( 'No post ID provided.' ),
				array(
					'status' => 400,
				)
			);
		}

		// Get the revision.
		$revisions = wp_get_post_revisions( $post_id );
		$last_revision = null;
		// Clone & Replace (and possibly other plugins) changes the order of revisions, so we have to actually loop through to find the latest.
		foreach ( $revisions as $revision ) {
			if ( ! $last_revision || ( $revision->ID > $last_revision->ID ) ) {
				$last_revision = $revision;
			}
		}

		// Unable to find a revision.
		if ( ! ( $last_revision instanceof \WP_Post ) ) {
			return new \WP_Error(
				'no-revision-found',
				esc_html__( 'No revision found for the provided post ID.' ),
				array(
					'status' => 400,
				)
			);
		}

		return rest_ensure_response( $last_revision->ID );
	}

	/**
	 * Returns the post revision content, given a hash value.
	 *
	 * @param \WP_REST_Request $request The current REST API request.
	 * @return \WP_REST_Response|\WP_Error The REST API response or an error.
	 */
	public function get_revision_content_from_hash( \WP_REST_Request $request ) {
		// Get parameters from request.
		$params = $request->get_params();

		// Get the hash.
		$hash = $params['hash'];

		// No hash provided.
		if ( empty( $hash ) ) {
			return new \WP_Error(
				'no-hash-found',
				esc_html__( 'No hash provided.' ),
				array(
					'status' => 400,
				)
			);
		}

		// Get a the post with the hash.
		$posts = new \WP_Query(
			array(
				'post_type'        => array( 'revision' ),
				'post_status'      => 'closed',
				'posts_per_page'   => 1,
				'ignore_sticky'    => true,
				'suppress_filters' => false,
				'meta_query'       => array( // WPCS: slow query ok.
					array(
						'key'   => REVISION_HASH_META_KEY,
						'value' => $hash,
					),
				),
			)
		);

		// No post found.
		if ( empty( $posts->posts[0] ) || ! ( $posts->posts[0] instanceof \WP_Post ) ) {
			return new \WP_Error(
				'no-post-found',
				esc_html__( 'No post found matching the provided hash.' ),
				array(
					'status' => 400,
				)
			);
		}

		return rest_ensure_response( $posts->posts[0]->post_content );
	}

	/**
	 * Returns user data (just ID, login, name) by given ETH wallet address
	 *
	 * @param \WP_REST_Request $request The current REST API request.
	 * @return \WP_REST_Response|\WP_Error The REST API response or an error.
	 */
	public function get_user_by_eth_address( \WP_REST_Request $request ) {
		$params = $request->get_params();
		$address = $params['address'];

		if ( empty( $address ) ) {
			return new \WP_Error(
				'no-address-found',
				esc_html__( 'No ETH address provided.' ),
				array(
					'status' => 400,
				)
			);
		}

		$users = get_users(
			array(
				'meta_key' => USER_ETH_ADDRESS_META_KEY,
				'meta_value' => $address,
			)
		);

		if ( empty( $users[0] ) || ! ( $users[0] instanceof \WP_User ) ) {
			return new \WP_Error(
				'no-user-found',
				esc_html__( 'No user found with given address.' ),
				array(
					'status' => 400,
				)
			);
		}

		$display_name = $users[0]->data->display_name;
		$avatar_url = get_avatar_url( $users[0]->data->ID );
		$bio = get_user_meta( $users[0]->data->ID, 'description', true );

		global $coauthors_plus;
		if ( isset( $coauthors_plus ) ) {
			$coauthor = $coauthors_plus->get_coauthor_by( 'id', $users[0]->data->ID );

			if ( $coauthor->display_name ) {
				$display_name = $coauthor->display_name;
			}

			$avatar_attachment_id = get_post_thumbnail_id( $coauthor->ID );
			if ( $avatar_attachment_id ) {
				$avatar_url = wp_get_attachment_url( $avatar_attachment_id );
			}

			$coauthor_bio = get_post_meta( $coauthor->ID, 'biography', true );
			if ( $coauthor_bio ) {
				$bio = $coauthor_bio;
			}
		}

		return rest_ensure_response(
			array(
				'ID' => $users[0]->data->ID,
				'user_login' => $users[0]->data->user_login,
				'display_name' => $display_name,
				'avatar_url' => $avatar_url,
				'bio' => strip_tags( $bio ),
			)
		);
	}

	/**
	 * Permissions check for if user can edit user meta.
	 *
	 * @param \WP_REST_Request $request The current REST API request.
	 * @return Boolean|\WP_Error True if can access, or an error.
	 */
	public function set_custom_user_meta_check( \WP_REST_Request $request ) {
		$params = $request->get_params();
		$user_id = $params['user_id'];

		if ( is_user_logged_in() && ( 'me' === $user_id || get_current_user_id() == $user_id ) ) {
			return true;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest-forbidden',
				esc_html__( 'Insufficient permissions.' ),
				array(
					'status' => 401,
				)
			);
		}

		return true;
	}

	/**
	 * Set custom user meta for given user.
	 *
	 * @param \WP_REST_Request $request The current REST API request.
	 * @return \WP_REST_Response|\WP_Error The REST API response or an error.
	 */
	public function set_custom_user_meta( \WP_REST_Request $request ) {
		$params = $request->get_params();
		$user_id = $params['user_id'];

		$has_addr = isset( $params[ USER_ETH_ADDRESS_META_KEY ] );
		$has_newsroom_role = isset( $params[ USER_NEWSROOM_ROLE_META_KEY ] );

		if ( empty( $user_id ) ) {
			return new \WP_Error(
				'no-id-found',
				esc_html__( 'No user ID provided.' ),
				array(
					'status' => 400,
				)
			);
		}

		if ( 'me' == $user_id ) {
			$user_id = get_current_user_id();
		} else {
			$user = get_user_by( 'id', $user_id );
			if ( empty( $user ) || ! ( $user instanceof \WP_User ) ) {
				return new \WP_Error(
					'no-user-found',
					esc_html__( 'No user found with given id.' ),
					array(
						'status' => 400,
					)
				);
			}
		}

		if ( ! $has_addr && ! $has_newsroom_role ) {
			return new \WP_Error(
				'no-meta-found',
				esc_html__( 'No meta provided.' ),
				array(
					'status' => 400,
				)
			);
		}

		if ( $has_addr ) {
			$addr = $params[ USER_ETH_ADDRESS_META_KEY ];

			if ( ! empty( $addr ) && ! is_valid_eth_address( $addr ) ) {
				return new \WP_Error(
					'invalid-eth-address',
					esc_html__( 'Invalid ETH address provided.' ),
					array(
						'status' => 400,
					)
				);
			}

			update_user_meta( $user_id, USER_ETH_ADDRESS_META_KEY, $addr );
		}

		if ( $has_newsroom_role ) {
			$newsroom_role = $params[ USER_NEWSROOM_ROLE_META_KEY ];
			update_user_meta( $user_id, USER_NEWSROOM_ROLE_META_KEY, $newsroom_role );
		}

		return rest_ensure_response( 'success' );
	}

	/**
	 * Cleans a post object to be sent back to the blockchain hash endpoint.
	 *
	 * @param \WP_Post|int $post The post object.
	 * @return array The cleaned post object.
	 */
	public function clean_item( $post ) {
		$post = get_post( $post );

		// No post found.
		if ( ! ( $post instanceof \WP_Post ) ) {
			return array();
		}

		// Get the parent post.
		$parent_post = get_post( $post->post_parent );

		// No parent post found.
		if ( ! ( $parent_post instanceof \WP_Post ) ) {
			return array();
		}

		// Get the JSON payload data.
		$json_payload_data = get_post_meta( $post->ID, REVISION_DATA_META_KEY, true );
		$revision_hash     = get_post_meta( $post->ID, REVISION_HASH_META_KEY, true );

		// Format the revision data.
		return array(
			'title'                 => $post->post_title,
			'revisionContentHash'   => $revision_hash,
			'revisionContentUrl'    => home_url( '/wp-json/' . REST_API_NAMESPACE . '/revisions-content/' . $revision_hash . '/' ),
			'canonicalUrl'          => get_permalink( $parent_post->ID ),
			'slug'                  => $parent_post->post_name,
			'description'           => $post->post_excerpt,
			'contributors'          => $json_payload_data['contributors'] ?? array(),
			'images'                => $json_payload_data['images'] ?? array(),
			'tags'                  => $json_payload_data['tags'] ?? array(),
			'primaryTag'            => $json_payload_data['primaryTag'] ?? '',
			'revisionDate'          => $post->post_date_gmt,
			'originalPublishDate'   => $parent_post->post_date_gmt,
			'credibilityIndicators' => $json_payload_data['credibilityIndicators'] ?? array(),
			'opinion'               => false,
			'civilSchemaVersion'    => SCHEMA_VERSION,
		);
	}
}

REST_API::instance();
