<?php
/**
 * Hanldes all logic related to hasing a post.
 *
 * @package Civil_Publisher
 */

namespace Civil_Publisher;

/**
 * The Post_Hashing class.
 */
class Post_Hashing {
	use Singleton;

	/**
	 * Setup the class.
	 */
	public function setup() {
		add_action( '_wp_put_post_revision', array( $this, 'hash_post_content' ) );

		// Force WordPress to save a new revision for meta data updates.
		add_filter( 'wp_save_post_revision_check_for_changes', '__return_false' );

		// Clean up revisions when a post is published.
		add_action( 'transition_post_status', array( $this, 'purge_revisions' ), 10, 3 );
	}

	/**
	 * Hash a string based on the `Keccak-256` hashing algorithm.
	 *
	 * @param  string $content The content to hash.
	 * @return string          The hashed content.
	 */
	public function hash( string $content ) : string {
		// Include the hashing library.
		require_once dirname( PLUGIN_FILE ) . '/lib/php-keccak/Keccak.php';

		return '0x' . \kornrunner\Keccak::hash( $content, '256' );
	}

	/**
	 * Checks if a post is able to save a hash value of its content.
	 *
	 * @param int $post_id The post ID.
	 * @return bool Whether or not the post can save a hash.
	 */
	public function can_save_hash( $post_id ) : bool {
		$can_hash = true;

		// Only save hashes for supported post types.
		if ( ! in_array( get_post_type( $post_id ), get_civil_post_types(), true ) ) {
			$can_hash = false;
		}

		/**
		 * Filters whether or not a post hash can be saved.
		 *
		 * @param bool $can_hash Can the post has be saved?
		 * @param int  $post_id  The post ID.
		 */
		return apply_filters( 'blockchain_can_save_hash', $can_hash, $post_id );
	}

	/**
	 * Hash the post content.
	 *
	 * @param int $post_id The post ID.
	 */
	public function hash_post_content( $post_id ) {
		$post = get_post( $post_id );

		// No post found.
		if ( ! ( $post instanceof \WP_Post ) ) {
			return;
		}

		// Ensure we can hash this revision.
		if ( ! $this->can_save_hash( $post->post_parent ) ) {
			return;
		}

		// Hash the post content.
		$this->revision_hash = $this->hash( $post->post_content );
		update_metadata( 'post', $post_id, REVISION_HASH_META_KEY, $this->revision_hash );

		// Add images.
		$images       = array();
		$thumbnail_id = get_post_thumbnail_id( $post->post_parent );

		if ( ! empty( $thumbnail_id ) ) {
			$image_src = wp_get_attachment_image_src( $thumbnail_id, 'full' );

			// Ensure we have a proper image.
			if ( ! empty( $image_src ) && is_array( $image_src ) ) {
				$images[] = array(
					'url'  => $image_src[0],
					'hash' => $this->hash_image( $thumbnail_id ),
					'h'    => $image_src[1],
					'w'    => $image_src[2],
				);
			}
		}

		// Add primary category.
		$primary_category    = '';
		$primary_category_id = get_post_meta( $post->post_parent, 'primary_category_id', true );

		if ( ! empty( $primary_category_id ) ) {
			$primary_category_term = get_term_by( 'id', $primary_category_id, 'category' );

			if ( $primary_category_term instanceof \WP_Term ) {
				$primary_category = $primary_category_term->slug;
			}
		}

		// Create revision JSON payload data.
		$json_payload_data = array(
			'contributors'          => $this->get_contributor_data( $post ),
			'images'                => $images,
			'tags'                  => wp_get_post_tags( $post->post_parent, array( 'fields' => 'slugs' ) ),
			'primaryTag'            => $primary_category,
			'credibilityIndicators' => get_post_meta( $post->post_parent, 'credibility_indicators', true ),
		);

		// Save revision JSON payload data.
		update_metadata( 'post', $post_id, REVISION_DATA_META_KEY, $json_payload_data );
	}

	/**
	 * Get contributor (authors, editors, others in the future) data, including signatures, for given post
	 *
	 * @param object $post A WP_Post object.
	 * @return array List of data for each contributor on post.
	 */
	public function get_contributor_data( $post ) {
		$contributors = array();

		$authors = get_post_authors_data( $post->post_parent );

		// $post is a revision, and this meta is stored on post itself, so get from the parent.
		$signatures = get_post_meta( $post->post_parent, SIGNATURES_META_KEY, true );
		$signatures = ! empty( $signatures ) ? json_decode( $signatures, true ) : null;

		if ( ! empty( $authors ) ) {
			foreach ( $authors as $author ) {
				$author_data = array(
					'role' => 'author',
					'name' => $author['display_name'],
				);

				if ( ! empty( $signatures[ $author['ID'] ] ) ) {
					$sig_data = $signatures[ $author['ID'] ];
					if ( $this->sig_valid_for_post( $sig_data ) ) {
						$author_data['address'] = $sig_data['author'];
						$author_data['signature'] = $sig_data['signature'];
					}

					// Whether it's valid or not, remove this signature now we've checked it, so we can see if there are any left over from non-authors (e.g. an editor added a signature).
					unset( $signatures[ $author['ID'] ] );
				}

				$contributors[] = $author_data;
			}
		}

		// Handle non-author signatures. For now we will assume that anybody who is not an author but who signed did so in an editorial capacity.
		if ( ! empty( $signatures ) ) {
			// Since these aren't explicitly authors on the post we can't use `get_coauthors` to get them, we just have WP user IDs, so we need to fetch coauthor (if any) to get the right name.
			global $coauthors_plus;

			foreach ( $signatures as $signer_id => $sig_data ) {
				$signer_display_name = null;
				if ( isset( $coauthors_plus ) ) {
					$coauthor = $coauthors_plus->get_coauthor_by( 'id', $signer_id );
					if ( $coauthor ) {
						$signer_display_name = $coauthor->display_name;
					}
				}
				if ( ! $signer_display_name ) {
					$signer = get_user_by( 'id', $signer_id );
					$signer_display_name = $signer->display_name;
				}

				$signer_data = array(
					'role' => 'editor',
					'name' => $signer_display_name,
				);

				if ( $this->sig_valid_for_post( $sig_data ) ) {
					$signer_data['address'] = $sig_data['author'];
					$signer_data['signature'] = $sig_data['signature'];
				}

				$contributors[] = $signer_data;
			}
		}

		// Get secondary bylines. No signatures for these guys yet.
		$secondary_bylines = get_post_meta( $post->post_parent, 'secondary_bylines', true );
		if ( ! empty( $secondary_bylines ) ) {
			foreach ( $secondary_bylines as $byline ) {
				if ( empty( $byline['role'] ) || ( empty( $byline['custom_name'] ) && empty( $byline['id'] ) ) ) {
					continue;
				}

				$secondary_contributor = array( 'role' => $byline['role'] );

				if ( ! empty( $byline['id'] ) ) {
					$user = get_user_by( 'id', $byline['id'] );
					if ( $user instanceof \WP_User ) {
						$secondary_contributor['name'] = $user->display_name;
					} else if ( function_exists( 'get_coauthors' ) ) {
						$name = (string) get_post_meta( $byline['id'], 'cap-display_name', true );
						if ( empty( $name ) ) {
							continue;
						}
						$secondary_contributor['name'] = $name;
					} else {
						continue;
					}
				} else {
					$secondary_contributor['name'] = $byline['custom_name'];
				}

				$contributors[] = $secondary_contributor;
			}
		}

		return $contributors;
	}

	/**
	 * Check signature still valid for current state of post.
	 *
	 * @param object $sig_data Signature data.
	 * @return boolean Whether it's valid for the current post.
	 */
	public function sig_valid_for_post( $sig_data ) {
		$newsroom_address = get_option( NEWSROOM_ADDRESS_OPTION_KEY );
		return ( $sig_data['newsroomAddress'] === $newsroom_address )
			&& ( $sig_data['contentHash'] === $this->revision_hash );
	}

	/**
	 * Hash an image.
	 *
	 * @param int $image_id The image ID.
	 * @return string The image Hash.
	 */
	public function hash_image( $image_id ) {
		// Get the image data.
		$image_src      = wp_get_attachment_image_src( $image_id, 'full' );
		$image_contents = '';

		// Get the image binary.
		if ( ! empty( $image_src[0] ) ) {
			if ( function_exists( 'wpcom_vip_file_get_contents ' ) ) {
				// This function is cached by VIP.
				$image_contents = wpcom_vip_file_get_contents( $image_src[0] );
			} else {
				// Use transients API for caching instead.
				// Image src may be longer than max 172 characters allowed for transient cache key name, so hash it.
				$cache_key = $this->hash( $image_src[0] );
				$cached_hash = get_transient( $cache_key );
				if ( false !== $cached_hash ) {
					return $cached_hash;
				}

				$image_contents = file_get_contents( $image_src[0] );
				$hash = $this->hash( $image_contents );
				set_transient( $cache_key, $hash, HOUR_IN_SECONDS );
				return $hash;
			}
		}

		return $this->hash( $image_contents );
	}

	/**
	 * Purges all post revisions, except for the latest one, on post publish.
	 *
	 * The smart contracting system we have needs a new post revision for any change
	 * to a post, not just the title, content or excerpt. This means that there can
	 * be a lot of reivions saved for a post prior to it being published. Also,
	 * Gutenberg saves the current post in set intervals from the edit post page,
	 * which also adds to the number of saved revisions.
	 *
	 * @param string   $new_status The new post status.
	 * @param string   $old_status The old post status.
	 * @param \WP_Post $post       The post object.
	 */
	public function purge_revisions( $new_status, $old_status, $post ) {
		// Only purge revisions on post publish.
		if ( 'publish' !== $old_status && 'publish' === $new_status ) {
			// Get all post revisions.
			$revisions = wp_get_post_revisions( $post->ID );

			// Ensure we have revisions to delete.
			if ( ! empty( $revisions ) && is_array( $revisions ) ) {
				foreach ( $revisions as $revision ) {

					// Delete all meta data.
					$metadata = get_post_meta( $revision->ID );

					if ( ! empty( $metadata ) && is_array( $metadata ) ) {
						foreach ( $metadata as $key => $val ) {
							delete_post_meta( $revision->ID, $key );
						}
					}

					// Delete the revision.
					wp_delete_post( $revision->ID, true );
				}
			}
		}
	}
}

Post_Hashing::instance();
