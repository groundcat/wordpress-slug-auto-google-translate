<?php
/**
 * Plugin Name:     Simple Slug Translate
 * Plugin URI:      https://github.com/ko31/simple-slug-translate
 * Description:     Simple Slug Translate can translate the post, page, category and taxonomy slugs to English automatically.
 * Version:         2.7.3
 * Author:          Ko Takagi, modified by groundcat
 * Author URI:      https://go-sign.info
 * License:         GPLv2
 * Text Domain:     simple-slug-translate
 * Domain Path:     /languages
 */
$sst = new simple_slug_translate();
$sst->register();
class simple_slug_translate {
	private $version = '';
	private $text_domain = '';
	private $langs = '';
	private $plugin_slug = '';
	private $option_name = '';
	private $options;
	private $has_mbfunctions = false;
	function __construct() {
		$data                  = get_file_data(
			__FILE__,
			array(
				'ver'         => 'Version',
				'langs'       => 'Domain Path',
				'text_domain' => 'Text Domain'
			)
		);
		$this->version         = $data['ver'];
		$this->text_domain     = $data['text_domain'];
		$this->langs           = $data['langs'];
		$this->plugin_slug     = basename( dirname( __FILE__ ) );
		$this->option_name     = basename( dirname( __FILE__ ) );
		$this->has_mbfunctions = $this->mbfunctions_exist();
	}
	public function register() {
		add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );
		add_action( $this->plugin_slug . '_scheduled_event', array( $this, 'call_scheduled_event' ) );
		register_activation_hook( __FILE__, array( $this, 'register_activation_hook' ) );
		register_deactivation_hook( __FILE__, array( $this, 'register_deactivation_hook' ) );
	}
	public function register_activation_hook() {
		if ( ! $this->has_mbfunctions ) {
			deactivate_plugins( __FILE__ );
			exit( __( 'Sorry, Simple Slug Translate requires <a href="http://www.php.net/manual/en/mbstring.installation.php" target="_blank">mbstring</a> functions.', $this->text_domain ) );
		}
		$options = get_option( $this->option_name );
		if ( empty( $options ) ) {
			add_option( $this->option_name, array(
				'post_types' => array( 'post', 'page' ),
			) );
		}
		if ( ! wp_next_scheduled( $this->plugin_slug . '_scheduled_event' ) ) {
			wp_schedule_event( time(), 'daily', $this->plugin_slug . '_scheduled_event' );
		}
	}
	public function register_deactivation_hook() {
		wp_clear_scheduled_hook( $this->plugin_slug . '_scheduled_event' );
	}
	public function call_scheduled_event() {
		$this->translate( 'test' );
	}
	public function plugins_loaded() {
		$this->options = get_option( $this->option_name );
		load_plugin_textdomain(
			$this->text_domain,
			false,
			dirname( plugin_basename( __FILE__ ) ) . $this->langs
		);
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		
		// Add priority 5 to make it run earlier than the default
		add_filter( 'name_save_pre', array( $this, 'name_save_pre' ), 5 );
		
		// Handle 'save_post' action for translations
		add_action( 'save_post', array( $this, 'save_post_hook' ), 10, 3 );
		
		add_filter( 'wp_insert_term_data', array( $this, 'wp_insert_term_data' ), 10, 3 );
		$this->activate_post_type();
	}
	
	/**
	 * Handle the save_post action to ensure slugs are translated
	 */
	public function save_post_hook( $post_id, $post, $update ) {
		// Skip auto-saves
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		
		// Skip if post is a revision
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		
		// Skip post types that aren't enabled
		if ( ! $this->is_post_type( $post->post_type ) ) {
			return;
		}
		
		// Skip post statuses that aren't enabled
		if ( ! $this->is_post_status( $post->post_status ) ) {
			return;
		}
		
		// Skip empty titles
		if ( empty( $post->post_title ) ) {
			return;
		}
		
		// Skip if we shouldn't overwrite and post already has a slug (unless it's a new post)
		$is_new = !$update;
		if ( empty( $this->options['overwrite'] ) && !empty( $post->post_name ) && !$is_new ) {
			return;
		}
		
		// Translate the title to get a slug
		$post_name = $this->call_translate( $post->post_title );
		
		// Make the slug unique
		$post_name = wp_unique_post_slug( 
			$post_name, 
			$post_id, 
			$post->post_status, 
			$post->post_type, 
			$post->post_parent 
		);
		
		// Only update if the slug is different
		if ( $post_name !== $post->post_name ) {
			// Remove this filter temporarily to prevent infinite loops
			remove_filter( 'name_save_pre', array( $this, 'name_save_pre' ), 5 );
			
			// Update the post with the new slug
			wp_update_post( array(
				'ID' => $post_id,
				'post_name' => $post_name,
			) );
			
			// Re-add the filter
			add_filter( 'name_save_pre', array( $this, 'name_save_pre' ), 5 );
		}
	}
	public function activate_post_type() {
		if ( empty( $this->options['post_types'] ) ) {
			return false;
		}
		foreach ( $this->options['post_types'] as $post_type ) {
			add_filter( 'rest_insert_' . $post_type, array( $this, 'rest_insert_post' ), 10, 2 );
		}
	}
	public function rest_insert_post( $post, $request ) {
		// Add debugging for REST API calls
		error_log('Simple Slug Translate - REST insert post triggered for post ID: ' . $post->ID);
		
		// Always try to translate for new posts, regardless of overwrite setting
		$is_new_post = empty($post->post_name);
		
		if (
			empty( $this->options['overwrite'] )
			&& ! empty( $post->post_name )
			&& ( strtolower( $post->post_name ) !== strtolower( urlencode( $post->post_title ) ) ) 
			&& !$is_new_post /* Except for new posts */
		) {
			error_log('Simple Slug Translate - Skipping translation (overwrite disabled)');
			return;
		}
		
		if ( ! $this->is_post_type( $post->post_type ) ) {
			error_log('Simple Slug Translate - Post type not enabled: ' . $post->post_type);
			return;
		}
		
		if ( ! $this->is_post_status( $post->post_status ) ) {
			error_log('Simple Slug Translate - Post status not enabled: ' . $post->post_status);
			return;
		}
		
		if ( empty( $post->post_title ) ) {
			error_log('Simple Slug Translate - Empty post title');
			return;
		}
		
		error_log('Simple Slug Translate - Translating title: ' . $post->post_title);
		$post_name = $this->call_translate( $post->post_title );
		error_log('Simple Slug Translate - Translated slug: ' . $post_name);
		
		$post_name = wp_unique_post_slug( $post_name, $post->ID, $post->post_status, $post->post_type, $post->post_parent );
		
		error_log('Simple Slug Translate - Final slug: ' . $post_name);
		wp_update_post( array(
			'ID'        => $post->ID,
			'post_name' => $post_name,
		) );
	}
	public function name_save_pre( $post_name ) {
		global $post;
		
		// Add debug logging
		error_log('Simple Slug Translate - name_save_pre triggered with post_name: ' . $post_name);
		
		// Do nothing when API is called - we handle that in rest_insert_post
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			error_log('Simple Slug Translate - Skipping (REST request)');
			return $post_name;
		}
		
		// Check if this is a new post (empty slug) or we should overwrite
		$is_new_post = empty($post_name);
		if ( empty( $this->options['overwrite'] ) && $post_name && !$is_new_post ) {
			error_log('Simple Slug Translate - Skipping (overwrite disabled and existing slug)');
			return $post_name;
		}
		
		if ( empty( $post ) ) {
			error_log('Simple Slug Translate - Skipping (empty post object)');
			return $post_name;
		}
		
		if ( ! $this->is_post_type( $post->post_type ) ) {
			error_log('Simple Slug Translate - Skipping (post type not enabled): ' . $post->post_type);
			return $post_name;
		}
		
		if ( ! $this->is_post_status( $post->post_status ) ) {
			error_log('Simple Slug Translate - Skipping (post status not enabled): ' . $post->post_status);
			return $post_name;
		}
		
		if ( empty( $post->post_title ) ) {
			error_log('Simple Slug Translate - Skipping (empty post title)');
			return $post_name;
		}
		
		error_log('Simple Slug Translate - Translating title: ' . $post->post_title);
		$new_post_name = $this->call_translate( $post->post_title );
		error_log('Simple Slug Translate - Translated slug: ' . $new_post_name);
		
		if (empty($new_post_name)) {
			error_log('Simple Slug Translate - Translation returned empty, using original post_name');
			return $post_name;
		}
		
		$new_post_name = wp_unique_post_slug( $new_post_name, $post->ID, $post->post_status, $post->post_type, $post->post_parent );
		error_log('Simple Slug Translate - Final slug: ' . $new_post_name);
		
		return $new_post_name;
	}
	public function is_post_type( $post_type ) {
		if ( empty( $this->options['post_types'] ) ) {
			return false;
		}
		foreach ( $this->options['post_types'] as $enabled_post_type ) {
			if ( $enabled_post_type == $post_type ) {
				return true;
			}
		}
		return false;
	}
	public function is_taxonomy( $taxonomy ) {
		if ( empty( $this->options['taxonomies'] ) ) {
			return false;
		}
		foreach ( $this->options['taxonomies'] as $enabled_taxonomy ) {
			if ( $enabled_taxonomy == $taxonomy ) {
				return true;
			}
		}
		return false;
	}
	public function is_post_status( $post_status ) {
		/**
		 * Filters the post status to translate.
		 *
		 * @param array $statuses
		 */
		$statuses = apply_filters( 'simple_slug_translate_post_status', array(
			'draft',
			'publish',
		) );
		return in_array( $post_status, $statuses );
	}
	public function wp_insert_term_data( $data, $taxonomy, $args ) {
		if ( ! $this->is_taxonomy( $taxonomy ) ) {
			return $data;
		}
		if ( ! empty( $data ) && empty( $args['slug'] ) ) {
			$slug         = $this->call_translate( $data['name'] );
			$slug         = wp_unique_term_slug( $slug, (object) $args );
			$data['slug'] = $slug;
		}
		return $data;
	}
	public function call_translate( $text ) {
		if ( ! $this->has_mbfunctions ) {
			return $text;
		}
		
		// Always attempt to translate the text, even if it appears to be English
		// This ensures new posts will be translated properly
		$result = $this->translate( $text );
		return ( ! empty( $result['text'] ) ) ? $result['text'] : $text;
	}
	public function translate( $text ) {
		// Google Translate API endpoint
		$endpoint = 'https://translate.googleapis.com/translate_a/single';
		
		// Make sure the text is properly encoded for the URL
		$encoded_text = urlencode($text);
		
		// Prepare parameters for the request
		$params = array(
			'client' => 'gtx',
			'dt' => 't',
			'sl' => 'auto', // Auto-detect source language
			'tl' => 'en',   // Target language is always English
			'q' => $text,   // Text to translate
		);
		
		// Build the URL with query parameters
		$url = add_query_arg($params, $endpoint);
		
		// Make the request
		$response = wp_remote_get($url, array(
			'timeout' => 10,
		));
		
		// Check for errors
		if (is_wp_error($response)) {
			error_log('Simple Slug Translate - Translation error: ' . $response->get_error_message());
			return array(
				'code' => '',
				'text' => $text,
			);
		}
		
		$code = $response['response']['code'];
		$translated_text = $text; // Default to original text
		
		if ($code == 200) {
			// Log the response for debugging
			error_log('Simple Slug Translate - Raw response: ' . $response['body']);
			
			$body = json_decode($response['body']);
			
			// Extract the translated text from the response
			// The translation is in the first element of the first array
			if (is_array($body) && isset($body[0]) && is_array($body[0]) && isset($body[0][0]) && isset($body[0][0][0])) {
				$translated_text = sanitize_title($body[0][0][0]);
				error_log('Simple Slug Translate - Translated text: ' . $translated_text);
			} else {
				error_log('Simple Slug Translate - Could not parse translation response');
			}
		} else {
			error_log('Simple Slug Translate - Translation failed with code: ' . $code);
		}
		
		/**
		 * Filters the translated results
		 *
		 * @param array $results
		 */
		$results = apply_filters('simple_slug_translate_results', array(
			'code' => $code,
			'text' => $translated_text,
			'response' => isset($response['body']) ? $response['body'] : '',
		));
		
		return $results;
	}
	
	/**
	 * Test translation function for the test button
	 */
	public function test_translation() {
		return $this->translate('こんにちは世界');
	}
	
	public function admin_menu() {
		add_options_page(
			__( 'Simple Slug Translate', $this->text_domain ),
			__( 'Simple Slug Translate', $this->text_domain ),
			'manage_options',
			$this->plugin_slug,
			array( $this, 'options_page' )
		);
	}
	public function admin_init() {
		register_setting(
			$this->plugin_slug,
			$this->option_name,
			array( $this, 'sanitize_callback' )
		);
		
		add_settings_section(
			'test_settings',
			__( 'Test Translation', $this->text_domain ),
			array( $this, 'test_section_callback' ),
			$this->plugin_slug
		);
		
		add_settings_field(
			'test_translation',
			__( 'Test Google Translate API', $this->text_domain ),
			array( $this, 'test_translation_callback' ),
			$this->plugin_slug,
			'test_settings'
		);
		
		add_settings_section(
			'permission_settings',
			__( 'Permission settings', $this->text_domain ),
			array( $this, 'permission_section_callback' ),
			$this->plugin_slug
		);
		
		add_settings_field(
			'source',
			__( 'Enabled post types', $this->text_domain ),
			array( $this, 'post_types_callback' ),
			$this->plugin_slug,
			'permission_settings'
		);
		
		add_settings_field(
			'taxonomies',
			__( 'Enabled taxonomies', $this->text_domain ),
			array( $this, 'taxonomies_callback' ),
			$this->plugin_slug,
			'permission_settings'
		);
		
		add_settings_field(
			'overwrite',
			__( 'Overwrite', $this->text_domain ),
			array( $this, 'overwrite_callback' ),
			$this->plugin_slug,
			'permission_settings'
		);
	}
	
	public function sanitize_callback( $input ) {
		if ( ! is_array( $input ) ) {
			$input = (array) $input;
		}
		return $input;
	}
	
	public function test_section_callback() {
		echo '<p>' . __( 'Test the Google Translate API connection to ensure the plugin is working correctly.', $this->text_domain ) . '</p>';
	}
	
	public function permission_section_callback() {
		return;
	}
	
	public function test_translation_callback() {
		// Add a test button that will trigger an AJAX request
		?>
		<button type="button" id="test-translation-button" class="button button-secondary">
			<?php _e('Test Translation', $this->text_domain); ?>
		</button>
		<div id="test-translation-result" class="notice-info notice hidden">
			<p><strong><?php _e('Translation result:', $this->text_domain); ?></strong></p>
			<p id="test-translation-text"></p>
			<p><strong><?php _e('Response code:', $this->text_domain); ?></strong> <span id="test-translation-code"></span></p>
			<p><strong><?php _e('Raw API response:', $this->text_domain); ?></strong></p>
			<pre id="test-translation-raw" style="max-height: 200px; overflow: auto; background: #f8f8f8; padding: 10px; border: 1px solid #ddd;"></pre>
		</div>
		
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('#test-translation-button').on('click', function() {
				var button = $(this);
				button.prop('disabled', true).text('<?php _e('Testing...', $this->text_domain); ?>');
				
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'sst_test_translation',
						nonce: '<?php echo wp_create_nonce('sst_test_translation'); ?>'
					},
					success: function(response) {
						button.prop('disabled', false).text('<?php _e('Test Translation', $this->text_domain); ?>');
						
						if (response.success) {
							var result = response.data;
							$('#test-translation-text').text(result.text);
							$('#test-translation-code').text(result.code);
							$('#test-translation-raw').text(result.response);
							$('#test-translation-result').removeClass('hidden notice-error').addClass('notice-success');
						} else {
							$('#test-translation-text').text(response.data.message);
							$('#test-translation-result').removeClass('hidden notice-success').addClass('notice-error');
						}
					},
					error: function() {
						button.prop('disabled', false).text('<?php _e('Test Translation', $this->text_domain); ?>');
						$('#test-translation-text').text('<?php _e('An error occurred during the test.', $this->text_domain); ?>');
						$('#test-translation-result').removeClass('hidden notice-success').addClass('notice-error');
					}
				});
			});
		});
		</script>
		<?php
	}
	
	public function post_types_callback() {
		$post_types = get_post_types( array(
			'show_ui' => true
		), 'objects' );
		foreach ( $post_types as $post_type ) :
			if ( $post_type->name == 'attachment' || $post_type->name == 'wp_block' ) :
				continue;
			endif;
			?>
            <label>
                <input
                        type="checkbox"
                        name="<?php echo esc_attr( $this->option_name ); ?>[post_types][]"
                        value="<?php echo esc_attr( $post_type->name ); ?>"
					<?php if ( $this->is_post_type( $post_type->name ) ) : ?>
                        checked="checked"
					<?php endif; ?>
                />
				<?php echo esc_html( $post_type->labels->name ); ?>
            </label>
		<?php
		endforeach;
	}
	
	public function taxonomies_callback() {
		$taxonomies = get_taxonomies( array(
			'show_ui' => true
		), 'objects' );
		foreach ( $taxonomies as $taxonomy ) :
			?>
            <label>
                <input
                        type="checkbox"
                        name="<?php echo esc_attr( $this->option_name ); ?>[taxonomies][]"
                        value="<?php echo esc_attr( $taxonomy->name ); ?>"
	                <?php if ( $this->is_taxonomy( $taxonomy->name ) ) : ?>
                        checked="checked"
	                <?php endif; ?>
                />
				<?php echo esc_html( $taxonomy->labels->name ); ?>
            </label>
		<?php
		endforeach;
	}
	
	public function overwrite_callback() {
		$overwrite = isset( $this->options['overwrite'] ) ? $this->options['overwrite'] : '';
		?>
        <label>
            <input
                    type="checkbox"
                    name="<?php echo esc_attr( $this->option_name ); ?>[overwrite]"
                    value="1"
				<?php if ( $overwrite ) : ?>
                    checked="checked"
				<?php endif; ?>
            />
			<?php _e( 'Check if you want to overwrite the slug', $this->text_domain ); ?>
        </label>
		<?php
	}
	
	public function options_page() {
		?>
        <form action='options.php' method='post'>
            <h1><?php echo __( 'Simple Slug Translate', $this->text_domain ); ?></h1>
            <p><?php echo __( 'Automatically translate your post, page, category and taxonomy slugs to English using Google Translate.', $this->text_domain ); ?></p>
			<?php
			settings_fields( $this->plugin_slug );
			do_settings_sections( $this->plugin_slug );
			submit_button();
			?>
        </form>
		<?php
	}
	
	public function mbfunctions_exist() {
		return ( function_exists( 'mb_strlen' ) ) ? true : false;
	}
	
	/**
	 * Add AJAX handler for the test button
	 */
	public function add_ajax_handlers() {
		add_action('wp_ajax_sst_test_translation', array($this, 'ajax_test_translation'));
	}
	
	/**
	 * AJAX callback for testing translation
	 */
	public function ajax_test_translation() {
		check_ajax_referer('sst_test_translation', 'nonce');
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to perform this action.', $this->text_domain)));
		}
		
		$result = $this->test_translation();
		
		if ($result['code'] == 200) {
			wp_send_json_success($result);
		} else {
			wp_send_json_error(array('message' => sprintf(__('Translation failed with code: %s', $this->text_domain), $result['code'])));
		}
	}
} // end class simple_slug_translate

// Initialize AJAX handlers
add_action('init', array(new simple_slug_translate(), 'add_ajax_handlers'));

// EOF
