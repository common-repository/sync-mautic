<?php

/**
 * Plugin Name: Sync Mautic
 * Plugin URI: https://www.dogbytemarketing.com/contact/
 * Description: Syncs leads passed via webhooks along with syncing order product, categories, and brands to Mautic.
 * Author: Dog Byte Marketing
 * Version: 1.0.3
 * Requires at least: 6.6.2
 * Requires PHP: 7.4
 * Author URI: https://www.dogbytemarketing.com
 * License: GPL3
 */

namespace DogByteMarketing;

use WP_Error;
use WP_REST_Request;

if (!defined('ABSPATH')) exit;

register_deactivation_hook(__FILE__, array(__NAMESPACE__ . '\Sync_Mautic', 'deactivation'));

/**
 * ToDo
 * 
 * Untag orders if refunded / cancelled
 */

class Sync_Mautic
{

	/**
	 * Sync Mautic Settings
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      array    $settings      Sync Mautic Settings
	 */
	private $settings;

	/**
	 * Mautic Base URL
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $base_url     Mautic Base URL
	 */
	private $base_url;

	/**
	 * Mautic Client ID
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $client_id    Mautic API Key
	 */
	private $client_id;

	/**
	 * Mautic Secret Key
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $client_secret   Mautic API Key
	 */
	private $client_secret;

	/**
	 * Mautic Checkout Optin
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $checkout_optin      Mautic Checkout Optin
	 */
	private $checkout_optin;

	/**
	 * Store admin notices
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      array     $admin_notice Store admin notices
	 */
  private $admin_notice;
	
	/**
	 * __construct
	 *
	 * @return void
	 */
	public function __construct() {
		$this->settings       = get_option('dogbytemarketing_sync_mautic_settings');
		$this->base_url       = isset($this->settings['base_url']) ? $this->settings['base_url'] : '';
		$this->client_id      = isset($this->settings['client_id']) ? $this->settings['client_id'] : '';
		$this->client_secret  = isset($this->settings['client_secret']) ? $this->settings['client_secret'] : '';
		$this->checkout_optin = isset($this->settings['checkout_optin']) ? $this->settings['checkout_optin'] : 'disabled';
	}
	
	/**
	 * Init
	 *
	 * @return void
	 */
	public function init() {
		if ($this->base_url && $this->client_id && $this->client_secret) {
			add_action('rest_api_init', array($this, 'add_endpoints'));
			add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
			add_action('wp_loaded', array($this, 'woocommerce_init'));

			// We need to handle compatibility for those previously on the beta
			if ($this->has_used_beta()) {
				add_shortcode('mautic', array($this, 'mautic_form'));
			} else {
				add_shortcode('mautic_form', array($this, 'mautic_form'));
			}

			if (is_admin()) {
				$sync_mautic_start_time = get_option('dogbytemarketing_sync_mautic_start_time');

				if ($sync_mautic_start_time) {
					$total_orders = get_option('dogbytemarketing_sync_mautic_total_orders');
					$order_index  = get_option('dogbytemarketing_last_sync_mautic_order_index');

					if ($total_orders && $order_index) {
						$this->add_notice('Sync Mautic has synced ' . $order_index . ' out of ' . $total_orders . ' orders.', 'info');
					}
				}
			}
		} else {
			if (is_admin()) {
				$this->add_notice('Sync Mautic is not running as it needs to be <a href="options-general.php?page=sync-mautic">configured</a>.');
			}
		}

		add_action('dogbytemarketing_sync_mautic_past_orders', array($this, 'sync_mautic_past_orders'));
		add_filter('cron_schedules', array($this, 'mautic_schedule'));
		add_action('admin_menu', array($this, 'add_admin_menu'));
		add_action('admin_init', array($this, 'settings_init'));
	}
	
	/**
	 * Enqueue scripts
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		wp_enqueue_style('newsletter-signup', plugins_url('/css/public.css', __FILE__), array(), filemtime(plugin_dir_path(dirname(__FILE__)) . dirname(plugin_basename(__FILE__))  . '/css/public.css'));
		wp_enqueue_script('newsletter-signup', plugins_url('/js/public.js', __FILE__), array('jquery'), filemtime(plugin_dir_path(dirname(__FILE__)) . dirname(plugin_basename(__FILE__))  . '/js/public.js'), true);
		wp_localize_script('newsletter-signup', 'newsletter_signup_object',
			array(
				'site_url' => site_url(),
				'nonce'    => wp_create_nonce('newsletter-signup-nonce'),
			)
		);
	}
	
	/**
	 * Check if WooCommerce is enabled and add necessary hooks
	 *
	 * @return void
	 */
	public function woocommerce_init() {
		if (class_exists('WooCommerce')) {
			if ($this->checkout_optin !== 'disabled') {
				add_action('woocommerce_review_order_before_submit', array($this, 'add_checkout_optin'));
				add_action('woocommerce_checkout_order_processed', array($this, 'add_checkout_lead'));
			}

			add_action('woocommerce_order_status_completed', array($this, 'order_tagging'));
		}
	}

	/**
	 * Add a new cron schedule for every minute
	 *
	 * @param  mixed $schedules
	 * @return void
	 */
	public function mautic_schedule($schedules) {
		$schedules['every_minute'] = array(
			'interval'  => 60,
			'display'   => __('Every Minute', 'sync-mautic')
		);
		
		return $schedules;
	}
	
	/**
	 * Cron job to sync past orders.
	 *
	 * @return void
	 */
	public function sync_mautic_past_orders() {
		$sync_complete = get_option('dogbytemarketing_sync_mautic_past_orders_complete');

		if (!$sync_complete) {
			$order_tagging = isset($this->settings['order_tagging']) ? $this->settings['order_tagging'] : array();

			// Exit if order tagging is disabled
			if (!$order_tagging) {
				self::debug('Order Sync Disabled');
				return;
			}

			self::debug('Sync Started');

			if (class_exists('WooCommerce')) {
				if ($this->base_url && $this->client_id && $this->client_secret) {
					$last_sync_mautic_order_index = get_option('dogbytemarketing_last_sync_mautic_order_index', 0);

					self::debug('Last synced index ' . $last_sync_mautic_order_index);
					
					$sync_mautic_start_time = get_option('dogbytemarketing_sync_mautic_start_time');

					if (!$sync_mautic_start_time) {
						$sync_mautic_start_time = time();
						update_option('dogbytemarketing_sync_mautic_start_time', time());

						self::debug('Added start time');
					}

					$sync_mautic_total_orders = get_option('dogbytemarketing_sync_mautic_total_orders');

					if (!$sync_mautic_total_orders) {
						self::debug('Total synced orders not found, adding...');

						$sync_mautic_total_orders = update_option('dogbytemarketing_sync_mautic_total_orders', wc_orders_count('wc-completed'));
					}

					if ($sync_mautic_total_orders > 0) {
						self::debug('Has total sync orders');

						$order_args = array(
							'order'          => 'ASC',
							'date_created'   => '<' . ($sync_mautic_start_time),
							'type'           => 'shop_order',
							'offset'         => $last_sync_mautic_order_index,
							'limit'          => 50,
							'status'         => array('wc-completed'),
							'return'         => 'ids',
						);

						self::debug('Order args: ' . print_r($order_args, true));

						$orders = wc_get_orders($order_args);

						self::debug('Order ids: ' . print_r($orders, true));

						if ($orders) {
							foreach ($orders as $order_id) {
								self::debug('Tagging order #' . $order_id);

								$this->order_tagging($order_id);
							}
					
							$last_sync_mautic_order_index += count($orders);
							update_option('dogbytemarketing_last_sync_mautic_order_index', $last_sync_mautic_order_index);

							self::debug('New order sync index ' . $last_sync_mautic_order_index);
						} else {
							update_option('dogbytemarketing_sync_mautic_past_orders_complete', true);
    					wp_clear_scheduled_hook('dogbytemarketing_sync_mautic_past_orders');

							delete_option('dogbytemarketing_sync_mautic_start_time');
							delete_option('dogbytemarketing_sync_mautic_total_orders');
							delete_option('dogbytemarketing_last_sync_mautic_order_index');

							self::debug('Order sync complete');
						}
					} else {
						self::error('Failed to sync past orders as the plugin has not detected any completed orders.');
					}
				} else {
					self::error('Failed to sync past orders as the plugin has not been configured.');
				}
			} else {
				self::debug('WooCommerce not enabled, syncing canceled.');
			}
		}
	}
	
	/**
	 * Tag orders with product categories and/or brands.
	 *
	 * @param  int $order_id
	 * @return void
	 */
	public function order_tagging($order_id) {
		$order_tagging = isset($this->settings['order_tagging']) ? $this->settings['order_tagging'] : array();

		// Exit if order tagging is disabled
		if (!$order_tagging) {
			self::debug('Order tagging disabled, exiting...');

			return;
		}

		$order = wc_get_order($order_id);

		// Exit if no order data
		if (!$order) {
			self::error('Failed to tag order #' . $order_id . ' - Unable to retrieve order.');

			return;
		}

		self::debug('Attempting to tag order #' . $order_id);

		$user_id    = $order->get_user_id();
		$email      = $order->get_billing_email();
		$first_name = $order->get_billing_first_name();
		$last_name  = $order->get_billing_last_name();
		$is_tagged  = $order->get_meta('_is_mautic_tagged', true);
		$lead_id    = $this->get_lead_id($email, $user_id);
    $tags       = array();

		if ($lead_id) {
			self::debug('Found lead id for order #' . $order_id);

			// Only need to tag contacts from their order once
			if (!$is_tagged) {
				self::debug('Lead ID ' . $lead_id . ' has not been tagged yet, getting tags...');

				$items = $order->get_items();

				if (in_array('categories', $order_tagging)) {
					self::debug('Getting categories...');

					$tags = array_merge($tags, $this->get_product_terms($items, 'product_cat'));

					if (!$tags) {
						self::debug('No product categories detected on order #' . $order_id);

						return;
					}
				}

				if (in_array('brands', $order_tagging)) {
					self::debug('Getting brands...');

					$tags = array_merge($tags, $this->get_product_terms($items, 'brand'));

					if (!$tags) {
						self::debug('No product brands detected on order #' . $order_id);

						return;
					}
				}

				if (in_array('products', $order_tagging)) {
					self::debug('Getting products...');

					$tags = array_merge($tags, $this->get_product_names($items));

					if (!$tags) {
						self::debug('No products detected on order #' . $order_id);

						return;
					}
				}

				self::debug('Updating lead tags');
				$update_lead_tags = $this->update_lead($lead_id, $tags, $first_name, $last_name);
				
				if (is_wp_error($update_lead_tags)) {
					if ($update_lead_tags->get_error_code() == 404) {
						self::debug('Cached user ' . $user_id . ' lead id ' . $lead_id . ' is no longer valid, attempting to get updated user.');

						$new_lead_id = $this->get_lead_id($email, $user_id, false);

						if ($new_lead_id) {
							self::debug('Updated lead id from ' . $lead_id . ' to ' . $new_lead_id);
						} else {
							self::error('Failed to get new lead id for user ' . $user_id . '. They may no longer be a contact.');
						}
					}
				} else if ($update_lead_tags) {
					self::debug('Lead tags updated successfully.');

					$order->update_meta_data('_is_mautic_tagged', true);
					$order->save();
				}
			} else {
				self::debug('Order #' . $order_id . ' was already tagged.');
			}
		} else {
			self::debug('Unable to get lead id for order #' . $order_id . '. '. $email . ' may not be subscribed.');
		}
	}
	
	/**
	 * Get the lead id from email and add it to their profile
	 *
	 * @param  string $email
	 * @param  int    $user_id
	 * @param  bool   $cache_enabled
	 * @return void
	 */
	private function get_lead_id($email, $user_id, $cache_enabled = true) {
		$lead_id = $user_id > 0 ? get_user_meta($user_id, '_mautic_lead_id', true) : '';

		if ($lead_id && $cache_enabled) {
			self::debug('Cached lead ID ' . $lead_id . ' found.');
			return $lead_id;
		}

		self::debug('No cached lead ID found, fetching lead id...');

		$args = array(
			'method' => 'GET',
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->get_token(),
			),
		);

		$request = wp_remote_request($this->base_url . "/api/contacts?search=" . urlencode($email), $args);

		if (!is_wp_error($request)) {
			$response_code = wp_remote_retrieve_response_code($request);

			if ($response_code == 200 || $response_code == 201) {
				$body = isset($request['body']) ? json_decode($request['body'], true) : '';

				if ($body) {
					$total_contacts = isset($body['total']) ? $body['total'] : '';

					if ($total_contacts > 0) {
						$contacts = isset($body['contacts']) ? $body['contacts'] : '';

						if ($contacts) {
							self::debug('Fetching first lead id from the following data ' . print_r($contacts, true));

							$lead_id = array_key_first($contacts);

							self::debug('Adding lead ID ' . $lead_id . ' to cache.');

							update_user_meta($user_id, '_mautic_lead_id', (int) $lead_id);

							return $lead_id;
						}
					} else {
						self::debug('No contact found for ' . $email . ', they may not be subscribed.');
					}
				} else {
					self::error('There was no body in the response from the Mautic search endpoint.');
				}
			} else {
				self::error('Failed to get lead id. Either the request failed or you did not configure your Mautic Base URL and API Key settings.');
			}
		} else {
			$error_message = $request->get_error_message() ? $request->get_error_message() : 'WordPress encountered an error when attempting to get the lead id for: ' . $email;
			
			self::error($error_message);
		}
	}

	/**
	 * Update the lead
	 *
	 * @param  int    $lead_id
	 * @param  array  $tags
	 * @param  string $first_name
	 * @param  string $last_name
	 */
	private function update_lead($lead_id, $tags, $first_name = '', $last_name = '') {
		$args = array(
			'method' => 'PATCH',
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->get_token(),
			),
			'body' => array(
				'tags' => $tags,
				'firstname' => $first_name ? $first_name : sanitize_text_field($first_name),
				'lastname' => $last_name ? $last_name : sanitize_text_field($last_name),
			)
		);

		self::debug('Updating lead ID ' . $lead_id . ' to have the following tags ' . print_r($tags, true));

		$request = wp_remote_request($this->base_url . "/api/contacts/" . $lead_id . "/edit", $args);

		if (!is_wp_error($request)) {
			$response_code = wp_remote_retrieve_response_code($request);

			if ($response_code == 200 || $response_code == 201) {
				self::debug('Updated tags for lead ID ' . $lead_id);

				return true;
			} elseif ($response_code == 404) {
				// Lead not found
				return new WP_Error(404, 'Lead not found.');
			} else {
				self::error('Lead update failed. Either the lead does not exist or you have not configured the Mautic Base URL and API Key settings.');
			}
		} else {
			$error_message = $request->get_error_message() ? $request->get_error_message() : 'WordPress encountered an error when attempting to update the lead id: ' . $lead_id;
			
			self::error($error_message);
		}
	}

	/**
	 * Get the product terms
	 *
	 * @param  object $items
	 * @param  string $taxonomy
	 * @return array  $product_terms
	 */
	private function get_product_terms($items, $taxonomy) {
		$product_terms = array();

		self::debug('Fetching ' . $taxonomy . ' terms');

		foreach ($items as $item_id => $item) {
			$product = $item->get_product();
			
			if ($product) {
				$terms = get_the_terms($product->get_id(), $taxonomy);

				if (!is_wp_error($terms) && $terms) {
					foreach ($terms as $term) {
						$product_terms[] = $term->name;

						self::debug('Added ' . $term->name . ' to tags.');
					}
				} else {
					self::error('Failed to fetch term for item ' . print_r($item_id, true));
				}
			} else {
				self::error('Product term not found for item ' . print_r($item_id, true));
			}
		}

		return $product_terms;
	}

	/**
	 * Get the product names
	 *
	 * @param  object $items
	 * @return array  $product_names
	 */
	private function get_product_names($items) {
		$product_names = array();

		self::debug('Fetching products');

		foreach ($items as $item_id => $item) {
			$product = $item->get_product();
			
			if ($product) {
        $product_names[] = $product->get_name();
        
        self::debug('Added ' . $product->get_name() . ' to tags.');
			} else {
				self::error('Product not found for item ' . print_r($item_id, true));
			}
		}

		return $product_names;
	}
	
	/**
	 * Add endpoint for webhooks to connect to
	 *
	 * @return void
	 */
	public function add_endpoints() {
		register_rest_route(
			'sync-mautic/v1',
			'/optinmonster/',
			array(
				'methods' => 'POST',
				'callback' => array($this, 'get_optinmonster_request'),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'sync-mautic/v1',
			'/add-lead/',
			array(
				'methods' => 'POST',
				'callback' => array($this, 'get_lead_request'),
				'permission_callback' => '__return_true',
			)
		);

		// We need to handle compatibility for those previously on the beta
		if ($this->has_used_beta()) {
			register_rest_route(
				'mautic/v1',
				'/add-lead/',
				array(
					'methods' => 'POST',
					'callback' => array($this, 'get_optionmonster_request'),
					'permission_callback' => '__return_true',
				)
			);
			register_rest_route(
				'newsletter/v1',
				'/add-lead/',
				array(
					'methods' => 'POST',
					'callback' => array($this, 'get_lead_request'),
					'permission_callback' => '__return_true',
				)
			);
		}
	}
	
	/**
	 * Add checkout optin
	 *
	 * @return void
	 */
	public function add_checkout_optin() {
	?>
		<p class="form-row input-checkbox" id="newsletter_signup_field" data-priority="">
			<span class="woocommerce-input-wrapper">
				<label class="checkbox ">
				<?php if ($this->checkout_optin === 'checked') : ?>
					<input type="checkbox" class="input-checkbox" name="newsletter_signup" id="newsletter_signup" value="1" checked="checked">
				<?php elseif ($this->checkout_optin === 'unchecked') : ?>
					<input type="checkbox" class="input-checkbox" name="newsletter_signup" id="newsletter_signup" value="1">
				<?php endif; ?>
				Subscribe To Our Newsletter
				</label>
			</span>
		</p>
	<?php
	}
	
	/**
	 * Add lead from checkout optin if checked
	 *
	 * @param  mixed $order_id
	 * @return void
	 */
	public function add_checkout_lead($order_id) {
		$newsletter_signup = isset($_POST['newsletter_signup']) ? sanitize_text_field(wp_unslash($_POST['newsletter_signup'])) : '';

		if ($newsletter_signup) {
			self::debug('Adding lead from checkout for order #' . $order_id);

			$order = wc_get_order($order_id);
			$email = $order->get_billing_email();
			$tags  = array("WooCommerce");

			$args = array(
				'method' => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->get_token(),
				),
				'body' => array(
					'email'      => sanitize_email($email),
					'lastActive' => current_time('Y-m-d H:i:s', true),
				)
			);
	
			if ($tags) {
				$args['body']['tags'] = array_map('sanitize_text_field', $tags);
			}
	
			$request = wp_remote_request($this->base_url . '/api/contacts/new', $args);

			if (!is_wp_error($request)) {
				self::debug('Added Lead: ' . $email);
			} else {
				$error_message = $request->get_error_message() ? $request->get_error_message() : 'WordPress encountered an error when attempting to add the lead: ' . $email;

				self::error($error_message);
			}
		}
	}
	
	/**
	 * Get request data
	 *
	 * @param  mixed $request
	 * @return void
	 */
	public function get_optinmonster_request(WP_REST_Request $request) {
		$lead_data = $request->get_json_params();

		$this->optinmonster($lead_data);
	}
	
	/**
	 * Process OptinMonster lead data
	 *
	 * @param  mixed $lead_data
	 * @return void
	 */
	private function optinmonster($lead_data) {
		$email = $lead_data['lead']['email'] ? sanitize_email($lead_data['lead']['email']) : '';

		if ($email) {
			$tags  = isset($lead_data['lead_options']['tags']) ? array_map('sanitize_text_field', $lead_data['lead_options']['tags']) : '';

			$this->add_lead($email, $tags);
		}
	}
	
	/**
	 * Get request custom form data
	 *
	 * @param  mixed $request
	 * @return void
	 */
	public function get_lead_request(WP_REST_Request $request) {
		$lead_data = $request->get_json_params();

		$this->custom_form($lead_data);
	}

	/**
	 * Process custom form lead data
	 *
	 * @param  mixed $lead_data
	 * @return void
	 */
	public function custom_form($lead_data) {
		$email = isset($lead_data['email']) ? sanitize_email($lead_data['email']) : '';
		$tags  = isset($lead_data['tag']) ? array(sanitize_text_field($lead_data['tag'])) : '';
		
		if (!$email) {
			wp_send_json_error("You must enter a valid email.", 400);

			return;
		}

		$this->add_lead($email, $tags);
	}
	
	/**
	 * Add lead into Mautic
	 *
	 * @param  string $email The email lead.
	 * @param  array  $tags  The lead tags. Optional.
	 * @return void
	 */
	private function add_lead($email, $tags = array()) {
		$args = array(
			'method' => 'POST',
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->get_token(),
			),
			'body' => array(
				'email'      => sanitize_email($email),
				'lastActive' => current_time('Y-m-d H:i:s', true),
			)
		);

		if ($tags) {
			$args['body']['tags'] = array_map('sanitize_text_field', $tags);
		}

		$request = wp_remote_request($this->base_url . '/api/contacts/new', $args);

		if (!is_wp_error($request)) {
			$response_code = wp_remote_retrieve_response_code($request);

			if ($response_code == 200 || $response_code == 201) {
				wp_send_json_success("Added lead", $response_code);
			} else {
				wp_send_json_error("Failed to add lead", $response_code);
			}
		} else {
			wp_send_json_error("Bad Request", 400);
		}
	}
	
	/**
	 * Shortcode to add Mautic form
	 *
	 * @return void
	 */
	public function mautic_form($atts) {
		$atts = shortcode_atts(
			array(
				'tag'         => '',
				'template'    => 'stacked',
				'placeholder' => 'Enter your email here...',
			),
			$atts,
			'mautic_form'
		);

		ob_start();
		
		if ($atts['template'] === 'stacked') {
			$this->stacked_template($atts);
		} else {
			$this->column_template($atts);
		}

		$output = ob_get_clean();

    return $output;
	}
	
	/**
	 * Display stacked template
	 * 
	 * @param  array $attr
	 * @return void
	 */
	public function stacked_template($atts) {
		?>
		<div class="newsletter-signup stacked-template">
			<input type="email" name="email" class="required email newsletter-signup-email" placeholder="<?php echo esc_attr($atts['placeholder']); ?>" required="" value="">
			<input type="hidden" name="tag" class="tag newsletter-signup-tag" value="<?php echo esc_attr($atts['tag']); ?>">
			<input type="submit" name="subscribe" class="btn btn-primary align-self-end newsletter-signup-subscribe" value="Subscribe">
			<div class="newsletter-signup-responses">
				<p class="loading"></p>
				<p class="error"></p>
				<p class="success"></p>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Display column template
	 * 
	 * @param  array $attr
	 * @return void
	 */
	public function column_template($atts) {
		?>
		<div class="row newsletter-signup column-template">
			<div class="col-12 col-sm-12 col-md-12 col-lg-12 col-xl-9">
				<input type="email" name="email" class="required email newsletter-signup-email" placeholder="<?php echo esc_attr($atts['placeholder']); ?>" required="" value="">
				<input type="hidden" name="tag" class="tag newsletter-signup-tag" value="<?php echo esc_attr($atts['tag']); ?>">
				<div class="newsletter-signup-responses">
					<p class="loading"></p>
					<p class="error"></p>
					<p class="success"></p>
				</div>
			</div>
			<div class="col-12 col-sm-12 col-md-12 col-lg-12 col-xl-3 pt-3 pt-sm-3 pt-md-3 pt-lg-3 pt-xl-0">
				<input type="submit" name="subscribe" class="btn btn-primary align-self-end newsletter-signup-subscribe" value="Subscribe">
			</div>
		</div>
		<?php
	}
	
	/**
	 * Add admin menu to backend
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		add_submenu_page('options-general.php', 'Sync Mautic Settings', 'Sync Mautic', 'manage_options', 'sync-mautic', array($this, 'options_page'));
	}
	
	/**
	 * Initialize Settings
	 *
	 * @return void
	 */
	public function settings_init() {
		register_setting(
			'dogbytemarketing_sync_mautic',
			'dogbytemarketing_sync_mautic_settings', 
			array($this, 'sanitize')
		);

		add_settings_section(
			'dogbytemarketing_sync_mautic_section',
			'',
			'',
			'dogbytemarketing_sync_mautic'
		);

		add_settings_field(
			'base_url',
			__('Mautic Base URL', 'sync-mautic'),
			array($this, 'base_url_render'),
			'dogbytemarketing_sync_mautic',
			'dogbytemarketing_sync_mautic_section'
		);

		add_settings_field(
			'client_id',
			__('Client ID', 'sync-mautic'),
			array($this, 'client_id_render'),
			'dogbytemarketing_sync_mautic',
			'dogbytemarketing_sync_mautic_section'
		);

		add_settings_field(
			'client_secret',
			__('Client Secret', 'sync-mautic'),
			array($this, 'client_secret_render'),
			'dogbytemarketing_sync_mautic',
			'dogbytemarketing_sync_mautic_section'
		);

		if (class_exists('WooCommerce')) {
			add_settings_field(
				'order_tagging',
				__('Order Tagging', 'sync-mautic'),
				array($this, 'order_tagging_render'),
				'dogbytemarketing_sync_mautic',
				'dogbytemarketing_sync_mautic_section'
			);

			add_settings_field(
				'checkout_optin',
				__('Checkout Optin', 'sync-mautic'),
				array($this, 'checkout_optin_render'),
				'dogbytemarketing_sync_mautic',
				'dogbytemarketing_sync_mautic_section'
			);

			add_settings_field(
				'resync_past_orders',
				__('Resync Past Orders', 'sync-mautic'),
				array($this, 'resync_past_orders_render'),
				'dogbytemarketing_sync_mautic',
				'dogbytemarketing_sync_mautic_section'
			);

			add_settings_field(
				'debug_mode',
				__('Debug Mode', 'sync-mautic'),
				array($this, 'debug_mode_render'),
				'dogbytemarketing_sync_mautic',
				'dogbytemarketing_sync_mautic_section'
			);
		}
	}

	/**
	 * Render Base URL field
	 *
	 * @return void
	 */
	public function base_url_render() {
	?>
		<input type='text' name='dogbytemarketing_sync_mautic_settings[base_url]' placeholder="https://mautic.domain.com" value='<?php echo esc_attr($this->base_url); ?>' style="width: 400px;">
		<p>The base url of your Mautic instance.<br />
		Example: https://mautic.domain.com</p>
	<?php
	}
	
	/**
	 * Render API Key Field
	 *
	 * @return void
	 */
	public function client_id_render() {
	?>
		<input type='text' name='dogbytemarketing_sync_mautic_settings[client_id]' placeholder="XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX" value='<?php echo esc_attr($this->client_id); ?>' style="width: 400px;">
		<p>The Client ID or Public Key from Mautic Settings -> API Creditials.</p>
	<?php
	}
	
	/**
	 * Render API Key Field
	 *
	 * @return void
	 */
	public function client_secret_render() {
	?>
		<input type='password' name='dogbytemarketing_sync_mautic_settings[client_secret]' placeholder="XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX" value='<?php echo esc_attr($this->client_secret); ?>' style="width: 400px;">
		<p>The Client Secret or Secret Key from Mautic Settings -> API Creditials.</p>
	<?php
	}
	
	/**
	 * Render Order Tagging Field
	 *
	 * @return void
	 */
	public function order_tagging_render() {
		$order_tagging = isset($this->settings['order_tagging']) ? $this->settings['order_tagging'] : array();
    ?>
    <input type="checkbox" name="dogbytemarketing_sync_mautic_settings[order_tagging][]" value="categories"<?php echo in_array('categories', $order_tagging) ? ' checked' : ''; ?>>
    <label for="categories">Categories</label><br />
    <input type="checkbox" name="dogbytemarketing_sync_mautic_settings[order_tagging][]" value="brands"<?php echo in_array('brands', $order_tagging) ? ' checked' : ''; ?>>
    <label for="brands">Brands</label><br />
    <input type="checkbox" name="dogbytemarketing_sync_mautic_settings[order_tagging][]" value="products"<?php echo in_array('products', $order_tagging) ? ' checked' : ''; ?>>
    <label for="products">Products</label><br />
		<p>
			This will add tags to existing contacts for the categories and/or brands of products they have purchased that have <strong>completed</strong> statuses. This will sync past orders.<br />
			<strong>This will tie Mautic IDs to User IDs. Before enabling, make sure you are in compliance with data protection regulations.<strong>
		</p>
		<?php
	}
	
	/**
	 * Render Checkout Optin Field
	 *
	 * @return void
	 */
	public function checkout_optin_render() {
		$checkout_optin = isset($this->settings['checkout_optin']) ? $this->settings['checkout_optin'] : 'disabled';
    ?>
    <select name="dogbytemarketing_sync_mautic_settings[checkout_optin]" id="checkout_checkbox" style="width: 400px;">
			<option value="disabled" <?php selected($checkout_optin, 'disabled'); ?>>Disabled</option>
			<option value="unchecked" <?php selected($checkout_optin, 'unchecked'); ?>>Unchecked</option>
			<option value="checked" <?php selected($checkout_optin, 'checked'); ?>>Checked</option>
    </select>
		<p><strong>You should use "Unchecked" for data protection regulation compliance.</strong></p>
		<?php
	}
	
	/**
	 * Resync Past Orders Field
	 *
	 * @return void
	 */
	public function resync_past_orders_render() {
    $resync_past_orders = isset($this->settings['resync_past_orders']) ? $this->settings['resync_past_orders'] : '';
	?>
    <input type="checkbox" name="dogbytemarketing_sync_mautic_settings[resync_past_orders]" id="resync_past_orders" <?php checked(1, $resync_past_orders, true); ?> /> Yes
		<p><strong>If you make changes to order tagging and want to resync past orders to reflect those changes, check this box.</strong></p>
	<?php
	}
	
	/**
	 * Render Debug Field
	 *
	 * @return void
	 */
	public function debug_mode_render() {
    $debug_mode = isset($this->settings['debug_mode']) ? $this->settings['debug_mode'] : '';
	?>
    <input type="checkbox" name="dogbytemarketing_sync_mautic_settings[debug_mode]" id="debug_mode" <?php checked(1, $debug_mode, true); ?> /> Enable
	<?php
	}
	
	/**
	 * Render options page
	 *
	 * @return void
	 */
	public function options_page() {
	?>
		<form action='options.php' method='post'>
			<h2>Sync Mautic Settings</h2>

			<?php
			settings_fields('dogbytemarketing_sync_mautic');
			do_settings_sections('dogbytemarketing_sync_mautic');
			?>
			<p style="background-color: #00A32A; text-align: center; padding: 20px; color: #fff; width: 62%;">
				If you need assistance with your Email Marketing efforts, we at <a href="https://www.dogbytemarketing.com" style="text-decoration: none; color: #fff; font-weight: 700;" target="_blank">Dog Byte Marketing</a> are here to help! We offer a wide array of services. Feel free to give us a call at <a href="tel:4237248922" style="text-decoration: none; color: #fff; font-weight: 700;" target="_blank">(423) 724 - 8922</a>.<br />
				We're based out of Tennessee in the US.</p>
			<?php
			submit_button();
			?>

		</form>
	<?php
	}
  
  /**
   * Sanitize Options
   *
   * @param  array $input Array of option inputs
   * @return array $sanitary_values Array of sanitized options
   */
  public function sanitize($input) {
		$sanitary_values = array();
    
		if (isset($input['client_id']) && $input['client_id']) {
			$sanitary_values['client_id'] = sanitize_text_field($input['client_id']);
		}
    
		if (isset($input['client_secret']) && $input['client_secret']) {
			$sanitary_values['client_secret'] = sanitize_text_field($input['client_secret']);
		}
    
		if (isset($input['base_url']) && $input['base_url']) {
			if (filter_var($input['base_url'], FILTER_VALIDATE_URL)) {
				$sanitary_values['base_url'] = esc_url_raw($input['base_url']);
			} else {
				add_settings_error('base_url', 'base_url', "Invalid URL");
				
				// Revert back to old base url
				$sanitary_values['base_url'] = isset($this->settings['base_url']) ? $this->settings['base_url'] : '';
			}
		}
    
		if (isset($input['checkout_optin']) && $input['checkout_optin']) {
			$sanitary_values['checkout_optin'] = sanitize_text_field($input['checkout_optin']);
		} else {
			$sanitary_values['checkout_optin'] = 'disabled';
		}
    
		if (isset($input['order_tagging']) && $input['order_tagging']) {
			$sanitary_values['order_tagging'] = array_map('sanitize_text_field', $input['order_tagging']);
		} else {
			$sanitary_values['order_tagging'] = array();
		}

		if ($sanitary_values['order_tagging']) {
			if (!get_option('dogbytemarketing_sync_mautic_past_orders_complete')) {
				if (!wp_next_scheduled('dogbytemarketing_sync_mautic_past_orders')) {
					wp_schedule_event(time(), 'every_minute', 'dogbytemarketing_sync_mautic_past_orders');
				}
			}
		}

    if (isset($input['resync_past_orders']) && $input['resync_past_orders']) {
      if ($input['resync_past_orders'] === 'on') {
        delete_option('dogbytemarketing_sync_mautic_past_orders_complete');

				if (!wp_next_scheduled('dogbytemarketing_sync_mautic_past_orders')) {
					wp_schedule_event(time(), 'every_minute', 'dogbytemarketing_sync_mautic_past_orders');
				}
      }
    }

		if (isset($input['debug_mode']) && $input['debug_mode']) {
      $sanitary_values['debug_mode'] = $input['debug_mode'] === 'on' ? true : false;
    } else {
      $sanitary_values['debug_mode'] = false;
    }

    return $sanitary_values;
  }

	/**
   * Display the notice on the admin backend
   *
   * @return void
   */
  public function display_notice() {
    ?>
    <div class="notice notice-<?php echo esc_attr($this->admin_notice['type']); ?>">
      <p>
				<?php
				echo wp_kses(
					$this->admin_notice['message'],
						array(
							'a' => array(
								'href' => array(),
								'title' => array(),
							),
						)
					);
				?>
			</p>
    </div>
    <?php
  }
	
	/**
	 * Gets the Mautic access token.
	 *
	 * @return string $token
	 */
	private function get_token() {
		$token = get_transient('dogbytemarketing_mautic_access_token');

		if (!$token) {
			$args = array(
				'method' => 'POST',
				'body' => array(
					'client_id'     => $this->client_id,
					'client_secret' => $this->client_secret,
					'grant_type'    => 'client_credentials',
				)
			);
	
			$request = wp_remote_request($this->base_url . '/oauth/v2/token', $args);

			if (!is_wp_error($request)) {
				$response_code = wp_remote_retrieve_response_code($request);

				if ($response_code == 200 || $response_code == 201) {
					$body = isset($request['body']) ? json_decode($request['body'], true) : '';

					if ($body) {
						if (isset($body['access_token']) && isset($body['expires_in'])) {
							$access_token = $body['access_token'] ? sanitize_text_field($body['access_token']) : '';
							$expires_in   = is_numeric($body['expires_in']) ? (int) ($body['expires_in']) : '';

							set_transient('dogbytemarketing_mautic_access_token', $access_token, $expires_in);
						} else {
							self::error('Access token or expiration not provided from "/oauth/v2/token" call.');
						}
					} else {
						self::error('Failed to get Mautic token: Malformed body.');
					}
				} else {
					self::error('Attempt to get Mautic token failed, response code: ' . $response_code);
				}
			} else {
				$error_message = $request->get_error_message() ? $request->get_error_message() : 'WordPress encountered an error attempting to fetch Mautic Token.';

				self::error($error_message);
			}
		}

		return $token;
	}

	/**
   * Enqueue the admin notice
   *
   * @param  string $message The message being displayed in admin
   * @param  string $type Optional. The type of message displayed. Default error.
   * @return void
   */
  private function add_notice($message, $type = 'error') {
    $this->admin_notice = array(
      'message' => $message,
      'type'   => $type
		);

    add_action('admin_notices', array($this, 'display_notice'));
  }
  
  /**
   * Deactivation
   *
   * @return void
   */
  public static function deactivation() {
    wp_clear_scheduled_hook('dogbytemarketing_sync_mautic_past_orders');
  }
	
	/**
	 * Check if previously used beta
	 *
	 * @return mixed $has_used_beta
	 */
	private function has_used_beta() {
		$has_used_beta = get_option('mautic_sync_settings');

		return $has_used_beta;
	}
	
	/**
	 * WooCommerce logging
	 *
	 * @param  mixed  $log
	 * @param  string $type
	 * @param  string $context
	 * @return void
	 */
	private static function log($log, $type = 'debug', $context = 'sync-mautic') {
		if (class_exists('WooCommerce')) {
			$logger         = wc_get_logger();
			$logger_context = array('source' => $context);

			$logger->$type($log, $logger_context);
		}
	}
	
	/**
	 * WooCommerce debug logging
	 *
	 * @param  mixed  $log
	 * @param  string $context
	 * @return void
	 */
	private static function debug($log, $context = 'sync-mautic') {
		$settings   = get_option('dogbytemarketing_sync_mautic_settings');
		$debug_mode = isset($settings['debug_mode']) ? $settings['debug_mode'] : false;

		// Don't log debug messages if it's not enabled. Errors are still logged.
		if ($debug_mode) {
			self::log($log, 'debug', $context);
		}
	}
	
	/**
	 * WooCommerce error logging
	 *
	 * @param  mixed  $log
	 * @param  string $context
	 * @return void
	 */
	private static function error($log, $context = 'sync-mautic') {
		self::log($log, 'error', $context);
	}
}

$sync_mautic = new Sync_Mautic;
$sync_mautic->init();
