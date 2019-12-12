<?php
/**
 * Credibility Indicators.
 *
 * @package Civil_Publisher
 */

namespace Civil_Publisher;

/**
 * The Credibility Indicators class.
 */
class Credibility_Indicators {
	use Singleton;

	/**
	 * Post types.
	 *
	 * @var array
	 */
	private $post_types = array( 'post', 'landing-page' );

	/**
	 * Credibility indicator values.
	 *
	 * @var array
	 */
	private $indicators = array();

	/**
	 * Credibility indicator learn more button text.
	 *
	 * @var string
	 */
	private $learn_more_text = '';

	/**
	 * Credibility indicator learn more button link.
	 *
	 * @var string
	 */
	private $learn_more_link = '';

	/**
	 * Setup the class.
	 */
	public function setup() {

		$this->setup_defaults();

		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'the_content', array( $this, 'append_indicators' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );
	}

	/**
	 * Setup default credibility values.
	 */
	public function setup_defaults() {

		// Post type.
		$this->post_types = apply_filters( 'civil_credibility_indicator_post_types', $this->post_types );

		// Add defaults for indicators.
		$indicators = array(
			'original_reporting' => array(
				'label'         => __( 'Original Reporting', 'civil' ),
				'default_value' => __( 'This article contains new, firsthand information uncovered by its reporter(s). This includes directly interviewing sources and research / analysis of primary source documents.', 'civil' ),
			),
			'on_the_ground'      => array(
				'label'         => __( 'On the Ground', 'civil' ),
				'default_value' => __( 'Indicates that a Newsmaker/Newsmakers was/were physically present to report the article from some/all of the location(s) it concerns.', 'civil' ),
			),
			'sources_cited'      => array(
				'label'         => __( 'Sources Cited', 'civil' ),
				'default_value' => __( 'As a news piece, this article cites verifiable, third-party sources which have all been thoroughly fact-checked and deemed credible by the Newsroom in accordance with the Civil Constitution.', 'civil' ),
			),
			'subject_specialist' => array(
				'label'         => __( 'Subject Specialist', 'civil' ),
				'default_value' => __( 'This Newsmaker has been deemed by this Newsroom as having a specialized knowledge of the subject covered in this article.', 'civil' ),
			),
		);

		// Filter default credibility indicators.
		$this->indicators = apply_filters( 'civil_default_credibility_indicators', $indicators );

		// Filter default value for learn more label.
		$this->learn_more_text = apply_filters(
			'civil_credibility_indicators_learn_more',
			__( 'Learn more about Civil’s Credibility Indicators', 'civil' )
		);

		// Filter default value for learn more link.
		$this->learn_more_link = apply_filters(
			'civil_credibility_indicators_learn_more_link',
			'https://civil.co/credibility-indicators'
		);
	}

	/**
	 * Append the indicators to the body content.
	 *
	 * @todo  Filters to control functionality.
	 *
	 * @param  string $content Post content.
	 * @return string Modified post content.
	 */
	public function append_indicators( $content ) {

		// Get saved indicators.
		$indicator_statuses = get_post_meta( get_the_ID(), 'civil_credibility_indicators', true );
		if ( empty( $indicator_statuses ) ) {
			return $content;
		}

		// Get learn more button settings.
		$button_text = get_option( 'civl_ci_learn_more_label' );
		if ( empty( $button_text ) ) {
			$button_text = $this->learn_more_text;
		}
		$button_link = get_option( 'civl_ci_learn_more_link' );
		if ( empty( $button_link ) ) {
			$button_link = $this->learn_more_link;
		}

		// Output buffer markup for indicators.
		ob_start();
		?>
			<section>
				<h3><?php esc_html_e( 'Civil: Credibility Indicators', 'civil' ); ?></h3>
				<?php
				foreach ( $indicator_statuses as $status ) :

					// Get the saved description.
					$description = get_option( "civl_ci_{$status}" );

					// Fall back to the hard-coded default.
					if ( empty( $description ) ) {
						$description = $this->indicators[ $status ]['default_value'];
					}
					?>
					<section>
						<h4><?php echo esc_html( $this->indicators[ $status ]['label'] ); ?></h4>
						<p><?php echo esc_html( $description ); ?></p>
					</section>
				<?php endforeach; ?>
				<a href="<?php echo esc_url( $button_link ); ?>"><?php echo esc_html( $button_text ); ?></a>
			</section>
		<?php
		$markup = ob_get_clean();

		return $content . $markup;
	}

	/**
	 * Add meta box.
	 */
	public function add_meta_box() {
		add_meta_box(
			'civil-credibility-indicators',
			__( 'Civil - Credibility Indicators', 'civil' ),
			array( $this, 'meta_box_callback' ),
			$this->post_types,
			'side'
		);
	}

	/**
	 * Output indicators as checkboxes in the metabox.
	 */
	public function meta_box_callback() {
		$indicator_statuses = get_post_meta( get_the_ID(), 'civil_credibility_indicators', true );
		wp_nonce_field( 'civil_credibility_indicators', 'civil_credibility_indicators_nonce' );
		?>
		<div
			style="display: flex; flex-direction: column;"
		>
			<?php
			// Output each indicator as a checkbox toggle.
			foreach ( $this->indicators as $key => $indicator ) {
				$this->credibility_checkbox(
					$indicator['label'],
					$key,
					! empty( $indicator_statuses ) && in_array( $key, $indicator_statuses, true )
				);
			}
			?>
		</div>
		<?php
	}

	/**
	 * Output the markup for a credibility checkbox input field.
	 *
	 * @param string  $label Label string.
	 * @param string  $key   Field key/slug.
	 * @param boolean $checked If input is checked.
	 */
	public function credibility_checkbox( $label, $key, $checked ) {
		?>
		<span
			style="padding: 4px 0;"
		>
			<label for="<?php echo esc_attr( $key ); ?>">
				<input
					type="checkbox"
					id="<?php echo esc_attr( $key ); ?>"
					name="civil_credibility_indicators[<?php echo esc_attr( $key ); ?>]"
					value="<?php echo esc_attr( $key ); ?>"
					<?php checked( $checked ); ?>
				>
				<?php echo esc_html( $label ); ?>
			</label>
		</span>
		<?php
	}

	/**
	 * Save indicators on post save.
	 *
	 * @param  integer $post_id Post ID.
	 * @param  WP_Post $post    Post object.
	 */
	public function save_post( $post_id, $post ) {

		// Check if our nonce is set.
		if ( ! isset( $_POST['civil_credibility_indicators_nonce'] ) ) {
			return;
		}

		// Verify that the nonce is valid.
		if (
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['civil_credibility_indicators_nonce'] ) ),
				'civil_credibility_indicators'
			)
		) {
			return;
		}

		// Does the post type being saved have credibility indicators?
		if ( ! in_array( $post->post_type, $this->post_types, true ) ) {
			return;
		}

		if ( ! isset( $_POST['civil_credibility_indicators'] ) ) {
			update_post_meta( $post_id, 'civil_credibility_indicators', array() );
			return;
		}

		$indicators = array_map( 'sanitize_text_field', wp_unslash( $_POST['civil_credibility_indicators'] ) );

		update_post_meta( $post_id, 'civil_credibility_indicators', array_keys( $indicators ) );
	}

	/**
	 * Register settings page interface.
	 */
	public function register_settings() {

		// Prep sections and field building.
		$sections = array();
		$fields   = array();

		// Build fields.
		foreach ( $this->indicators as $key => $indicator ) {
			$fields[] = array(
				'field'   => 'textarea',
				'label'   => $indicator['label'],
				'slug'    => "civl_ci_{$key}",
				'default' => $indicator['default_value'],
			);
		}

		$fields[] = array(
			'label'   => __( 'Learn More Label', 'civil' ),
			'slug'    => 'civl_ci_learn_more_label',
			'default' => $this->learn_more_text,
		);

		$fields[] = array(
			'label'   => __( 'Learn More Link', 'civil' ),
			'slug'    => 'civl_ci_learn_more_link',
			'default' => $this->learn_more_link,
		);

		$sections[] = array(
			'label'  => __( 'Settings', 'civil' ),
			'slug'   => 'civl_ci_indicators-section',
			'page'   => 'credibility-indicators',
			'fields' => $fields,
		);

		// Parse sections.
		foreach ( $sections as $section ) {
			$section = wp_parse_args(
				$section,
				array(
					'label'  => __( 'Settings', 'civil' ),
					'slug'   => 'settings-section',
					'page'   => 'credibility-indicators',
					'fields' => array(),
				)
			);

			add_settings_section(
				$section['slug'],
				$section['label'],
				null,
				$section['page']
			);

			// Parse fields.
			foreach ( (array) $section['fields'] as $field ) {

				// Setup defaults.
				$field = wp_parse_args(
					$field,
					array(
						'default' => '',
						'field'   => 'textfield',
						'label'   => __( 'Field Label', 'civil' ),
						'slug'    => 'field',
					)
				);

				add_settings_field(
					$field['slug'],
					$field['label'],
					array( $this, $field['field'] ),
					$section['page'],
					$section['slug'],
					array(
						'key'     => $field['slug'],
						'default' => $field['default'],
					)
				);

				register_setting(
					'civil_credibility_indicators',
					$field['slug'],
					function( $value ) {
						return esc_html( $value );
					}
				);
			}
		}
	}

	/**
	 * Helper to output a text field.
	 *
	 * @param array $args Field args.
	 */
	public function textfield( $args ) {
		$value = (string) get_option( $args['key'] );
		if ( empty( $value ) ) {
			$value = $args['default'];
		}
		?>
			<input
				type="text"
				name="<?php echo esc_attr( $args['key'] ); ?>"
				id="<?php echo esc_attr( $args['key'] ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				style="width: 100%; max-width: 520px;"
			/>
		<?php
	}

	/**
	 * Helper to output a textarea.
	 *
	 * @param array $args Field args.
	 */
	public function textarea( $args ) {
		$value = (string) get_option( $args['key'] );
		if ( empty( $value ) ) {
			$value = $args['default'];
		}
		?>
			<textarea
				name="<?php echo esc_attr( $args['key'] ); ?>"
				id="<?php echo esc_attr( $args['key'] ); ?>"
				rows="5"
				cols="70"
			><?php echo esc_html( $value ); ?></textarea>
		<?php
	}
}

add_action(
	'init',
	function() {
		if ( apply_filters( 'civil_enable_credibility_indicators', true ) ) {
			Credibility_Indicators::instance();
		}
	}
);
