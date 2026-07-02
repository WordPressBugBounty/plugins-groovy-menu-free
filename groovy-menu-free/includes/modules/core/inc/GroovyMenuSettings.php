<?php defined( 'ABSPATH' ) || die( 'This script cannot be accessed directly.' );

if ( ! class_exists( 'GroovyMenuSettings' ) ) {

	/**
	 * Class GroovyMenuSettings
	 */
	class GroovyMenuSettings {
		/**
		 * @var GroovyMenuStyle
		 */
		protected $settings;

		protected $lver = false;
		protected $remote_child_themes_url = 'https://updates.grooni.com/theme-demos/gm-child-themes/config/';
		protected $remote_get_msg_url = '';

		public function __construct() {

			if ( class_exists( 'GroovyMenuRoleCapabilities' ) ) {
				GroovyMenuRoleCapabilities::check_capabilities();
			}

			if ( defined( 'GROOVY_MENU_LVER' ) && '2' === GROOVY_MENU_LVER ) {
				$this->lver = true;
			}

			$style = new GroovyMenuStyle();

			add_action( 'wp_ajax_gm_save', array( $this, 'saveSettings' ) );

			add_action( 'wp_ajax_gm_save_styles', array( $this, 'saveStyles' ) );

			add_action( 'wp_ajax_gm_save_auto_integration', array( $this, 'saveAutoIntegration' ) );

			add_action( 'wp_ajax_gm_save_single_location_integration', array(
				$this,
				'saveSingleLocationIntegration'
			) );

			if ( ! $this->lver ) {
				add_action( 'wp_ajax_gm_check_current_license', array( $this, 'checkCurrentLicense' ) );
				add_action( 'wp_ajax_gm_reload_current_license', array( $this, 'reloadCurrentLicense' ) );
			}

			add_action( 'wp_ajax_gm_get_setting', array( $this, 'getSettings' ) );
			add_action( 'wp_ajax_nopriv_gm_get_setting', array( $this, 'getSettings' ) );

			add_action( 'admin_init', array( $this, 'start_ob' ) );

			add_action( 'wp_ajax_gm_get_google_fonts', array( $this, 'getGoogleFonts' ) );

			add_image_size(
				'menu-thumb',
				$style->get( 'general', 'preview_width' ),
				$style->get( 'general', 'preview_height' ),
				true
			);

			if ( ! is_admin() ) {
				add_action( 'admin_bar_menu', array( $this, 'addToolbarLink' ), 100001 );
			} else {
				add_action( 'admin_head', array( $this, 'dismiss_notice_msg' ), 7 );
				add_action( 'admin_head', array( $this, 'late_start' ), 8 );
				if ( ! $this->lver ) {
					add_action( 'admin_head', array( $this, 'grooni_msg' ), 9 );
				}
			}

			if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
				//call our function when initiated from JavaScript.
				add_action( 'wp_ajax_gm_admin_walker_priority_change', array(
					$this,
					'gm_admin_walker_priority_change'
				) );
			}

			if ( $this->lver ) {
				add_action( 'admin_menu', array( $this, 'addThemesPage_free' ) );
			} else {
				add_action( 'admin_menu', array( $this, 'addThemesPage_full' ) );
				add_action( 'gm_after_main_header', array( $this, 'checkMenuBlockRequirements' ), 1000 );
			}

			add_filter( 'pre_get_posts', array( $this, 'search_filter' ) );

			GroovyMenuUtils::groovy_wpml_register_single_string( $style );

		}

		/**
		 * Dismiss notice message
		 */
		public function dismiss_notice_msg() {
			if (
				isset( $_GET['gm_nonce'] ) &&
				wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['gm_nonce'] ) ), 'gm_nonce_dismiss_notice' ) &&
				isset( $_GET['gm-upgrade-theme'] ) &&
				'yes' === sanitize_text_field( wp_unslash( $_GET['gm-upgrade-theme'] ) )
			) {
				update_user_meta( get_current_user_id(), 'gm-upgrade-theme', true );
			}

			if (
				isset( $_GET['gm_nonce'] ) &&
				wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['gm_nonce'] ) ), 'gm_nonce_dismiss_msg' ) &&
				isset( $_GET['gm-dismiss-msg'] ) &&
				'yes' === sanitize_text_field( wp_unslash( $_GET['gm-dismiss-msg'] ) ) &&
				! empty( $_GET['gm-msg-id'] )
			) {
				update_user_meta( get_current_user_id(), 'gm-dismiss-msg-id--' . sanitize_key( wp_unslash( $_GET['gm-msg-id'] ) ), true );
			}
		}

		public function late_start() {
			if ( ! is_admin() ) {
				return;
			}

			global $gm_supported_module;

			if ( 'crane' === $gm_supported_module['theme'] && defined( 'CRANE_THEME_VERSION' ) ) {

				$need_upgrade    = false;
				$minimum_version = '1.4';

				if ( version_compare( $minimum_version, CRANE_THEME_VERSION, '>' ) ) {
					$need_upgrade = true;
				}

				$is_upgrade_dismissed = get_user_meta( get_current_user_id(), 'gm-upgrade-theme', true );

				if ( ! $is_upgrade_dismissed && $need_upgrade ) {
					add_action( 'admin_notices', array( $this, 'show_gm_need_upgrade' ), 9 );
				}
			}


			$screen = get_current_screen();

			if ( ! empty( $screen->id ) && 'nav-menus' === $screen->id ) {
				global $wp_filter;
				if ( isset( $wp_filter['wp_edit_nav_menu_walker'] ) ) {

					$other_priorities      = false;
					$admin_walker_priority = false;

					if ( is_object( $wp_filter['wp_edit_nav_menu_walker'] ) && isset( $wp_filter['wp_edit_nav_menu_walker']->callbacks ) ) {

						foreach ( $wp_filter['wp_edit_nav_menu_walker']->callbacks as $priority => $callbacks ) {
							foreach ( $callbacks as $callback => $data ) {
								if ( '\GroovyMenu\AdminWalker::get_edit_walker' === $callback ) {
									$other_priorities = false;
								} else {
									$admin_walker_priority = $priority;
									$other_priorities      = true;
								}
							}
						}
					}

					if ( $other_priorities && $admin_walker_priority ) {
						add_action( 'admin_notices', array( $this, 'show_gm_admin_walker_priority_add' ), 9 );
					} elseif ( ! $other_priorities && $admin_walker_priority ) {
						add_action( 'admin_notices', array( $this, 'show_gm_admin_walker_priority_remove' ), 9 );
					}
				}
			}

			if ( ! $this->lver ) {
				$lic_opt = get_option( GROOVY_MENU_DB_VER_OPTION . '__lic' );
				if ( ! $lic_opt && 'toplevel_page_groovy_menu_welcome' !== $screen->id ) {
					add_action( 'admin_notices', array( $this, 'show_gm_admin_need_license' ), 4 );
				}
			}

		}


		public function grooni_msg() {
			if ( ! is_admin() ) {
				return;
			}

			global $gm_supported_module;

			if ( ! get_transient( GROOVY_MENU_DB_VER_OPTION . '__msg_delay' ) ) {

				$current_theme = wp_get_theme()->get_template();
				$msg_proposal  = null;
				$who           = $this->lver ? '-free' : '';
				$who           = 'groovy-menu' . $who;

				global $wp_version;

				// Remote get data.
				$msg_search_data = wp_remote_get(
					add_query_arg(
						array(
							'theme'      => $current_theme,
							'theme-type' => is_child_theme() ? 'child' : 'parent',
							'who'        => $who,
						),
						$this->remote_get_msg_url
					),
					array(
						'timeout'     => 7,
						'httpversion' => '1.1',
						'user-agent'  =>
							'WordPress/' . $wp_version . ';' . $current_theme . ';' . ( is_child_theme() ? 'child' : 'parent' ) . ';',
					)
				);

				// Check if returned answer is OK.
				if ( ! is_wp_error( $msg_search_data ) && wp_remote_retrieve_response_code( $msg_search_data ) === 200 ) {
					$msg_proposal = json_decode( wp_remote_retrieve_body( $msg_search_data ), true );
					if ( ! empty( $msg_proposal['error'] ) || ! is_array( $msg_proposal ) ) {
						$msg_proposal = null;
					}

					update_option( GROOVY_MENU_DB_VER_OPTION . '__msg_data', $msg_proposal );
				}

				$transition_timer = 2 * HOUR_IN_SECONDS;

				// Set delay for next queue.
				set_transient( GROOVY_MENU_DB_VER_OPTION . '__msg_delay', true, $transition_timer );
			}

			// Get current messages data.
			$msgs = get_option( GROOVY_MENU_DB_VER_OPTION . '__msg_data' );

			if ( empty( $msgs ) || ! is_array( $msgs ) ) {
				return;
			}

			$msg_to_show = array();

			foreach ( $msgs as $index => $msg ) {
				// array format check.
				if ( in_array( $index, array( 'error', 'ext', 'prop', 'page' ), true ) ) {
					continue;
				}
				// data integrity check.
				if ( empty( $msg['priority'] ) || empty( $msg['min-show-date'] ) || empty( $msg['max-show-date'] ) || empty( $msg['msg-body'] ) ) {
					continue;
				}

				// Check time condition.
				$min_date  = date( 'U', $msg['min-show-date'] );
				$max_date  = date( 'U', $msg['max-show-date'] );
				$curr_date = date( 'U', time() );
				if ( $curr_date > $max_date || $curr_date < $min_date ) {
					continue;
				}

				if ( ! get_user_meta( get_current_user_id(), 'gm-dismiss-msg-id--' . esc_attr( $index ), true ) ) {
					$msg_to_show[ $index ] = array(
						'priority' => $msg['priority'],
						'msg-body' => $msg['msg-body'],
					);
				}
			}

			$screen          = get_current_screen();
			$allowed_screens = array(
				'dashboard',
				'nav-menus',
				'nav-menus.php',
				'plugins',
				'plugins.php',
				'edit-gm_menu_block',
				'toplevel_page_groovy_menu_settings',
				'groovy-menu_page_groovy_menu_welcome',
				'toplevel_page_groovy_menu_welcome',
				'groovy-menu_page_groovy_menu_integration',
				'toplevel_page_groovy_menu_integration',
				'groovy-menu_page_groovy_menu_settings',
				'groovy_menu_integration',
				'groovy_menu_welcome',
				'tools_page_groovy_menu_debug_page',
			);

			if ( ! empty( $msg_to_show ) && ! empty( $screen->id ) && in_array( $screen->id, $allowed_screens, true ) ) {
				$gm_supported_module['msg_to_show'] = $msg_to_show;

				add_action( 'admin_notices', array( $this, 'show_gm_msg' ), 12 );
			}

		}

		public function show_gm_msg() {

			global $gm_supported_module;

			if ( empty( $gm_supported_module['msg_to_show'] ) || ! is_array( $gm_supported_module['msg_to_show'] ) ) {
				return;
			}

			foreach ( $gm_supported_module['msg_to_show'] as $index => $msg_data ) {
				$priority = empty( $msg_data['priority'] ) ? 'notice' : $msg_data['priority'];
				$msg_body = empty( $msg_data['msg-body'] ) ? '' : $msg_data['msg-body'];
				if ( empty( $msg_body ) ) {
					continue;
				}

				?>

				<div id="gm-msg-id--<?php echo esc_attr( $index ); ?>"
					class="gm-msg-box <?php echo esc_attr( $priority ); ?> is-dismissible">
					<div class="gm-msg-body-block"><?php echo trim( $msg_body ); ?></div>

					<div class="gm-msg-buttons-block">
						<a class="gm-msg-dismiss-url" href="<?php echo esc_url( add_query_arg(
							array(
								'gm-dismiss-msg' => 'yes',
								'gm-msg-id'      => esc_attr( $index ),
								'gm_nonce'       => wp_create_nonce( 'gm_nonce_dismiss_msg' ),
							)
						) ); ?>"><?php esc_html_e( 'Dismiss this notice', 'groovy-menu' ); ?></a>
					</div>
				</div>

				<?php
			}
		}


		public function show_gm_admin_need_license() {
			return;
		}


		public function show_gm_need_upgrade() {
			?>

			<div id="gm-upgrade-notice" class="notice-error settings-error notice is-dismissible">
				<p class="gm-install-addons-text-block"><?php echo wp_kses_post( sprintf(
						__( 'You need to update %s. There are major improvements related to Groovy Menu settings.', 'groovy-menu' ), '<a href="' . esc_url( get_admin_url( null, 'themes.php', 'relative' ) ) . '">' . esc_html__( 'Crane theme', 'groovy-menu' ) . '</a> ' ) ); ?>
					<br>
				</p>

				<p class="crane-install-addons-buttons-block">
					<a href="<?php echo esc_url( add_query_arg( array(
						'gm-upgrade-theme' => 'yes',
						'gm_nonce'         => wp_create_nonce( 'gm_nonce_dismiss_notice' )
					) ) ); ?>"><?php esc_html_e( 'Dismiss this notice', 'groovy-menu' ); ?></a>
				</p>

			</div>

			<?php
		}


		public function show_gm_admin_walker_priority_add() {
			?>

			<div id="gm-upgrade-notice" class="notice-warning settings-warning notice is-dismissible">
				<p class="gm-install-addons-text-block"><?php echo esc_html__( 'The theme or another plugin overrides the visibility of the Groovy menu settings. To display the Groovy menus settings, please click on the button', 'groovy-menu' ); ?>
					<button class="button gm-admin-walker-priority--button" data-do="add"
						data-gm_nonce="<?php echo esc_attr( wp_create_nonce( 'gm_nonce_priority_change' ) ); ?>">
						<?php echo esc_html__( 'Show Groovy Menu settings', 'groovy-menu' ); ?>
					</button>
				</p>
			</div>

			<?php
		}

		public function show_gm_admin_walker_priority_remove() {
			?>

			<div id="gm-upgrade-notice" class="notice-warning settings-warning notice is-dismissible">
				<p class="gm-install-addons-text-block"><?php echo esc_html__( 'Groovy menu settings are currently displayed. To display the settings from a theme or another plugin, please click on the button', 'groovy-menu' ); ?>
					<button class="button gm-admin-walker-priority--button" data-do="remove"
						data-gm_nonce="<?php echo esc_attr( wp_create_nonce( 'gm_nonce_priority_change' ) ); ?>">
						<?php echo esc_html__( 'Show Theme/plugin settings', 'groovy-menu' ); ?>
					</button>
				</p>
			</div>

			<?php
		}

		public function gm_admin_walker_priority_change() {

			if ( ! isset( $_POST['gm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gm_nonce'] ) ), 'gm_nonce_priority_change' ) ) {
				wp_die( wp_json_encode( array(
					'code'    => 0,
					'message' => esc_html__( 'Error. Nonce field outdated. Try reload page.', 'groovy-menu' ),
				) ) );
			}

			$priority_action = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
			if (
				! $priority_action ||
				! in_array( $priority_action, [ 'add', 'remove' ], true )
			) {
				wp_die( wp_json_encode( array(
					'code'    => 0,
					'message' => esc_html__( 'Error. Undefined post param "do"', 'groovy-menu' ),
				) ) );
			}

			$style           = new GroovyMenuStyle();
			$global_settings = get_option( GroovyMenuStyle::OPTION_NAME );

			if ( 'add' === $priority_action ) {
				$global_settings['tools']['admin_walker_priority'] = '1';
			} else {
				$global_settings['tools']['admin_walker_priority'] = '';
			}

			// Update settings.
			$style->updateGlobal( $global_settings );

			$output = array(
				'message' => '<p><strong>' .
				             esc_html__( 'Setting changed', 'groovy-menu' ) . '</strong> ' .
				             '</p>',
				'code'    => 1,
			);
			wp_die( wp_json_encode( $output ) );
		}

		public function start_ob() {
			$request_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
			if ( isset( $_GET['export'] ) && 'groovy_menu_settings' === $request_page ) {
				$this->export();
			}

		}

		protected function cleanOutputBuffer() {
			if ( ob_get_level() > 0 ) {
				ob_clean();
			}
		}

		/**
		 * Return array with translatable phrases.
		 *
		 * @param bool $for_admin for dashboard or front-end.
		 *
		 * @return array
		 */
		public function l10n( $for_admin = true ) {
			$groovyMenuL10n = array();

			if ( $for_admin ) {
				$groovyMenuL10n['save_alert'] = esc_html__( 'The changes you made will be lost if you navigate away from this page.', 'groovy-menu' );
			}

			return $groovyMenuL10n;
		}


		/**
		 * Add GM link to admin toolbar.
		 *
		 * @param WP_Admin_Bar $wp_admin_bar
		 */
		public function addToolbarLink( WP_Admin_Bar $wp_admin_bar ) {

			$show_admin_bar_link = apply_filters( 'groovy_menu_show_admin_bar_link', true );
			if ( ! $show_admin_bar_link ) {
				return;
			}

			if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && current_user_can( 'groovy_menu_edit_preset' ) ) {

				global $groovyMenuSettings;

				$lic_opt = get_option( GROOVY_MENU_DB_VER_OPTION . '__lic' );

				$args = array(
					'id'    => 'groovy-menu-options',
					'title' => '<span class="ab-icon groovy-icon"></span> ' . esc_html__( 'Groovy Menu', 'groovy-menu' ),
					'href'  => get_admin_url() . 'admin.php?page=groovy_menu_settings',
				);

				if ( ! $this->lver && ! $lic_opt ) {
					$args['href'] = get_admin_url() . 'admin.php?page=groovy_menu_welcome';
				}

				$wp_admin_bar->add_node( $args );

				if ( isset( $groovyMenuSettings['preset'] ) ) {
					$preset = $groovyMenuSettings['preset'];
				} else {
					$preset = $this->getCurrentPreset();
				}

				$sub = array(
					'id'     => 'menu-preset',
					'title'  => $preset['name'],
					'href'   => get_admin_url() . 'admin.php?page=groovy_menu_settings&action=edit&id=' . $preset['id'],
					'parent' => 'groovy-menu-options',
				);

				if ( ! $this->lver && ! $lic_opt ) {
					$sub['href'] = get_admin_url() . 'admin.php?page=groovy_menu_welcome';
				}

				$wp_admin_bar->add_node( $sub );


				// Next adds sub-links for used Groovy Menu Blocks.

				if ( ! empty( $groovyMenuSettings['nav_menu_data']['id'] ) ) {
					$current_menu_id = $groovyMenuSettings['nav_menu_data']['id'];
				}

				if ( ! empty( $current_menu_id ) && ! empty( $groovyMenuSettings['nav_menu_data']['data'][ $current_menu_id ] ) ) {
					$nav_menu_items = $groovyMenuSettings['nav_menu_data']['data'][ $current_menu_id ];
				}

				if ( empty( $nav_menu_items ) ) {
					return;
				}


				$menu_blocks = array();

				foreach ( $nav_menu_items as $nav_menu_item ) {
					if ( empty( $nav_menu_item->object ) || 'gm_menu_block' !== $nav_menu_item->object ) {
						continue;
					}

					// the array key eliminates duplicates.
					$menu_blocks[ $nav_menu_item->object_id ] = true;

				}

				$searchForm = isset( $groovyMenuSettings['searchForm'] ) ? $groovyMenuSettings['searchForm'] : 'fullscreen';
				if ( 'custom' === $searchForm ) {
					$searchFormCustomId = isset( $groovyMenuSettings['searchFormCustomId'] ) ? intval( $groovyMenuSettings['searchFormCustomId'] ) : 0;
					if ( $searchFormCustomId ) {
						$menu_blocks[ $searchFormCustomId ] = true;
					}
				}

				if ( empty( $menu_blocks ) ) {
					return;
				}

				// Add title for Groovy Menu Blocks.
				$sub = array(
					'id'     => 'groovy-menu-blocks',
					'title'  => esc_html__( 'Menu Blocks', 'groovy-menu' ),
					'href'   => get_admin_url() . 'edit.php?post_type=gm_menu_block',
					'parent' => 'groovy-menu-options',
				);

				if ( ! $this->lver && ! $lic_opt ) {
					$sub['href'] = get_admin_url() . 'admin.php?page=groovy_menu_welcome';
				}

				$wp_admin_bar->add_node( $sub );

				// Add links to edit pages of Groovy Menu Block.
				foreach ( $menu_blocks as $block_id => $flag ) {
					$sub_block = array(
						'id'     => 'gm_menu_blocks__' . $block_id,
						'title'  => get_the_title( $block_id ),
						'href'   => get_edit_post_link( $block_id, '' ),
						'parent' => 'groovy-menu-blocks',
					);

					if ( ! $this->lver && ! $lic_opt ) {
						$sub_block['href'] = get_admin_url() . 'admin.php?page=groovy_menu_welcome';
					}

					$wp_admin_bar->add_node( $sub_block );
				}

			}

		}


		/**
		 * Get current preset name and id
		 *
		 * @return array
		 */
		public function getCurrentPreset() {
			$preset_id = GroovyMenuUtils::getMasterPreset();

			$post_type = get_post_type();
			if ( ! empty( $post_type ) && $post_type ) {
				$def_val = GroovyMenuUtils::getTaxonomiesPresetByPostType( $post_type );
			}

			if ( ! empty( $def_val['preset'] ) ) {
				$preset_id = $def_val['preset'];
			}
			$current_preset_id = GroovyMenuSingleMetaPreset::get_preset_id_from_meta();
			if ( $current_preset_id ) {
				$preset_id = $current_preset_id;
			}

			$preset_id =
				( empty( $preset_id ) || 'default' === $preset_id )
					?
					GroovyMenuUtils::getMasterPreset()
					:
					$preset_id;


			$category_options = gm_get_current_category_options();
			if ( $category_options && isset( $category_options['custom_options'] ) && '1' === $category_options['custom_options'] ) {
				if ( GroovyMenuCategoryPreset::getCurrentPreset() ) {
					$preset_id = GroovyMenuCategoryPreset::getCurrentPreset();
				}
			}

			if ( 'default' === $preset_id ) {
				$preset_id = null;
			}

			$styles = new GroovyMenuStyle( $preset_id );

			$preset = array(
				'id'   => $styles->getPreset()->getId(),
				'name' => $styles->getPreset()->getName(),
			);


			return $preset;
		}

		/**
		 * Return settings of the current preset
		 *
		 * @param null|integer $menu_id specific preset id.
		 *
		 * @return GroovyMenuStyle
		 */
		public function settings( $menu_id = null ) {
			if ( is_null( $menu_id ) && isset( $_GET['id'] ) ) {
				$menu_id = absint( wp_unslash( $_GET['id'] ) );
			}
			if ( is_null( $this->settings ) ) {
				$this->settings = new GroovyMenuStyle( $menu_id );
			}

			return $this->settings;
		}

		public function addThemesPage_free() {

			$show_integration = true;

			global $gm_supported_module;
			if ( isset( $gm_supported_module['GroovyMenuShowIntegration'] ) && ! $gm_supported_module['GroovyMenuShowIntegration'] ) {
				$show_integration = false;
			}

			$main_slug = 'groovy_menu_welcome';

			add_menu_page(
				__( 'Groovy menu', 'groovy-menu' ),
				__( 'Groovy menu', 'groovy-menu' ),
				GroovyMenuRoleCapabilities::presetRead(),
				$main_slug,
				'',
				'',
				91
			);

			add_submenu_page(
				$main_slug,
				__( 'Welcome', 'groovy-menu' ),
				__( 'Welcome', 'groovy-menu' ),
				GroovyMenuRoleCapabilities::presetRead(),
				'groovy_menu_welcome',
				array( $this, 'welcome_free' )
			);
			add_submenu_page(
				$main_slug,
				__( 'Dashboard', 'groovy-menu' ),
				__( 'Dashboard', 'groovy-menu' ),
				GroovyMenuRoleCapabilities::presetRead(),
				'groovy_menu_settings',
				array( $this, 'render' )
			);


			if ( $show_integration ) {
				add_submenu_page(
					$main_slug,
					__( 'Integration', 'groovy-menu' ),
					__( 'Integration', 'groovy-menu' ),
					GroovyMenuRoleCapabilities::globalOptions(),
					'groovy_menu_integration',
					array( $this, 'integrationDashboard' )
				);

			}

			add_submenu_page(
				$main_slug,
				__( 'Menus', 'groovy-menu' ),
				__( 'Menus', 'groovy-menu' ),
				GroovyMenuRoleCapabilities::presetRead(),
				'groovy_menu_menus',
				array( $this, 'menus' )
			);
			add_submenu_page(
				$main_slug,
				__( 'Premium', 'groovy-menu' ),
				__( 'Premium', 'groovy-menu' ),
				GroovyMenuRoleCapabilities::presetRead(),
				'groovy_menu_premium',
				array( $this, 'premium' )
			);

		}

		public function addThemesPage_full() {

			$show_integration = true;

			global $gm_supported_module;
			if ( isset( $gm_supported_module['GroovyMenuShowIntegration'] ) && ! $gm_supported_module['GroovyMenuShowIntegration'] ) {
				$show_integration = false;
			}

			$main_slug    = 'groovy_menu_welcome';
			$lic_opt      = get_option( GROOVY_MENU_DB_VER_OPTION . '__lic' );
			$lic_type     = GroovyMenuUtils::get_paramlic( 'type' );
			$welcome_page = 'welcome_full';
			if ( 'extended' === $lic_type ) {
				$welcome_page = 'welcome_ext';
			}

			add_menu_page(
				__( 'Groovy menu', 'groovy-menu' ),
				__( 'Groovy menu', 'groovy-menu' ),
				'edit_theme_options',
				'groovy_menu_welcome',
				'',
				'',
				91
			);

			add_submenu_page(
				$main_slug,
				__( 'Welcome', 'groovy-menu' ),
				__( 'Welcome', 'groovy-menu' ),
				'edit_theme_options',
				'groovy_menu_welcome',
				array( $this, $welcome_page )
			);


			if ( $lic_opt ) {

				add_submenu_page(
					$main_slug,
					__( 'Dashboard', 'groovy-menu' ),
					__( 'Dashboard', 'groovy-menu' ),
					'edit_theme_options',
					'groovy_menu_settings',
					array( $this, 'render' )
				);

				if ( $show_integration ) {
					add_submenu_page(
						$main_slug,
						__( 'Integration', 'groovy-menu' ),
						__( 'Integration', 'groovy-menu' ),
						'edit_theme_options',
						'groovy_menu_integration',
						array( $this, 'integrationDashboard' )
					);
				}

				add_submenu_page(
					$main_slug,
					__( 'Menus', 'groovy-menu' ),
					__( 'Menus', 'groovy-menu' ),
					GroovyMenuRoleCapabilities::presetRead(),
					'groovy_menu_menus',
					array( $this, 'menus' )
				);

			}

		}


		public function menus() {
			?>
			<script>window.location.href = '<?php echo esc_js( admin_url( 'nav-menus.php' ) ); ?>';</script>
			<?php
			exit;
		}

		public function premium() {
			?>
			<script>window.location.href = 'https://groovymenu.grooni.com/upgrade/';</script>
			<?php
			exit;
		}

		public function render() {
			$actions = array(
				'edit',
				'create',
				'delete',
				'saveDashboardSettings',
				'rename',
				'defaultSet',
				'preview',
				'deleteFont',
				'setThumb',
				'unsetThumb',
				'import',
				'importPreset',
				'importFromLibrary',
				'duplicate',
			);

			$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : null;
			if ( in_array( $action, $actions, true ) ) {
				$this->$action();
			} else {
				$this->dashboard();
			}
		}

		public function create() {
			if ( ! isset( $_GET['gm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['gm_nonce'] ) ), 'gm_nonce_editor' ) ) {
				return;
			}

			if ( GroovyMenuRoleCapabilities::presetCreate( true ) ) {
				$id = GroovyMenuPreset::create( 'new' );
				GroovyMenuPreset::rename( $id, 'New #' . $id );

				$edit_url = add_query_arg(
					array(
						'page'   => 'groovy_menu_settings',
						'action' => 'edit',
						'id'     => $id,
					),
					admin_url( 'admin.php' )
				);
				GroovyMenuUtils::safe_redirect( $edit_url );
			}
			exit;
		}

		public function setThumb() {
			if ( ! isset( $_GET['gm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['gm_nonce'] ) ), 'gm_nonce_editor' ) ) {
				return;
			}

			if ( GroovyMenuRoleCapabilities::presetEdit( true ) ) {
				$id    = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
				$image = isset( $_GET['image'] ) ? esc_url_raw( wp_unslash( $_GET['image'] ) ) : '';

				GroovyMenuPreset::setThumb( $id, $image );
			}
		}

		public function unsetThumb() {
			if ( ! isset( $_GET['gm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['gm_nonce'] ) ), 'gm_nonce_editor' ) ) {
				return;
			}

			if ( GroovyMenuRoleCapabilities::presetEdit( true ) ) {
				$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
				GroovyMenuPreset::setThumb( $id, null );
			}
		}

		public function rename() {
			if ( ! isset( $_GET['gm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['gm_nonce'] ) ), 'gm_nonce_editor' ) ) {
				return;
			}

			if ( GroovyMenuRoleCapabilities::presetEdit( true ) ) {
				$id   = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
				$name = isset( $_GET['name'] ) ? sanitize_text_field( wp_unslash( $_GET['name'] ) ) : '';
				GroovyMenuPreset::rename( $id, $name );
			}

			$this->cleanOutputBuffer();
			exit;
		}


		public function preview() {
			$this->cleanOutputBuffer();

			wp_enqueue_style( 'groovy-style', get_stylesheet_directory_uri() . '/assets/style/frontend.css', [], GROOVY_MENU_VERSION );
			wp_style_add_data( 'groovy-style', 'rtl', 'replace' );

			include_once GROOVY_MENU_DIR . 'template' . DIRECTORY_SEPARATOR . 'Preview.php';
			exit;
		}


		public function savePreviewImage() {
			$this->cleanOutputBuffer();
			if ( isset( $_POST ) && isset( $_POST['image'] ) ) {
				if (
					! GroovyMenuRoleCapabilities::presetEdit( true ) ||
					! isset( $_POST['gm_nonce'] ) ||
					! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gm_nonce'] ) ), 'gm_nonce_preset_save' )
				) {
					wp_die( esc_html__( 'Security check failed.', 'groovy-menu' ) );
				}

				global $gm_supported_module;
				global $wp_filesystem;
				if ( empty( $wp_filesystem ) ) {
					$file_path = str_replace( array(
						'\\',
						'/'
					), DIRECTORY_SEPARATOR, ABSPATH . '/wp-admin/includes/file.php' );

					if ( file_exists( $file_path ) ) {
						require_once $file_path;
						WP_Filesystem();
					}
				}

				$id    = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
				$image = isset( $_POST['image'] ) && is_string( $_POST['image'] ) ? trim( wp_unslash( $_POST['image'] ) ) : '';
				if ( empty( $id ) || ! preg_match( '#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $image ) ) {
					wp_die( esc_html__( 'Error. Bad preview image data.', 'groovy-menu' ) );
				}
				$data = base64_decode( preg_replace( '#^data:image/png;base64,#i', '', $image ), true );
				if ( false === $data ) {
					wp_die( esc_html__( 'Error. Bad preview image data.', 'groovy-menu' ) );
				}

				if ( empty( $wp_filesystem ) ) {

					update_post_meta( intval( $id ), 'gm_preset_screenshot', $image );

				} else {

					$upload_dir      = GroovyMenuUtils::getUploadDir();
					$upload_uri      = GroovyMenuUtils::getUploadUri();
					$upload_filename = 'preset_' . $id . '.png';

					$wp_filesystem->put_contents( $upload_dir . $upload_filename, $data, FS_CHMOD_FILE );

					update_post_meta( intval( $id ), 'gm_preset_screenshot', esc_url_raw( $upload_uri . $upload_filename ) );

				}

				exit;
			}

		}


		public function defaultSet() {
			if ( ! isset( $_GET['gm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['gm_nonce'] ) ), 'gm_nonce_editor' ) ) {
				return;
			}

			if ( GroovyMenuRoleCapabilities::globalOptions( true ) ) {
				$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
				GroovyMenuPreset::setDefaultPreset( $id );
				$redirect_url = add_query_arg(
					array( 'page' => 'groovy_menu_settings' ),
					admin_url( 'admin.php' )
				);

				GroovyMenuUtils::safe_redirect( $redirect_url );
			}
			exit;
		}

		public function saveDashboardSettings() {

			// Security check.
			$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
			if ( 'saveDashboardSettings' === $action && ( ! isset( $_GET['gm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['gm_nonce'] ) ), 'gm_nonce_saveDashboardSettings' ) ) ) {
				echo esc_html__( 'Fail. Nonce field outdated. Try reload page.', 'groovy-menu' );
				exit;
			}

			if ( ! GroovyMenuRoleCapabilities::globalOptions( true ) ) {
				echo esc_html__( 'Fail. Need more Capabilities.', 'groovy-menu' );
				exit;
			}

			if ( ! empty( $_POST ) && ! empty( $_POST['menu'] ) ) {
				if ( ! empty( $_POST['icons'] ) ) {
					if ( class_exists( 'ZipArchive' ) ) {

						if ( ! defined( 'FS_METHOD' ) ) {
							define( 'FS_METHOD', 'direct' );
						}

						global $wp_filesystem;
						if ( empty( $wp_filesystem ) ) {
							$file_path = str_replace( array(
								'\\',
								'/'
							), DIRECTORY_SEPARATOR, ABSPATH . '/wp-admin/includes/file.php' );

							if ( file_exists( $file_path ) ) {
								require_once $file_path;
								WP_Filesystem();
							}
						}
						if ( empty( $wp_filesystem ) ) {
							$this->cleanOutputBuffer();
							echo esc_html__( 'Cannot start $wp_filesystem.', 'groovy-menu' );
							exit;
						}

							$icons_attachment_id = empty( $_POST['icons'] ) ? 0 : absint( wp_unslash( $_POST['icons'] ) );
							$filename            = get_attached_file( $icons_attachment_id );
							$zip                 = new ZipArchive();
							if ( $filename && $zip->open( $filename ) ) {
								$fonts = \GroovyMenu\FieldIcons::getFonts();

								$selection     = $zip->getFromName( 'selection.json' );
								$selectionData = json_decode( $selection, true );
								$name          = empty( $_POST['gm-replace-field-name'] ) ? 'groovy-' . time() : sanitize_file_name( wp_unslash( $_POST['gm-replace-field-name'] ) );

								\GroovyMenuUtils::delete_icon_pack_files( $name );

								$fontFiles['woff'] = $zip->getFromName( 'fonts/' . $selectionData['metadata']['name'] . '.woff' );
								$fontFiles['ttf']  = $zip->getFromName( 'fonts/' . $selectionData['metadata']['name'] . '.ttf' );
								$fontFiles['svg']  = $zip->getFromName( 'fonts/' . $selectionData['metadata']['name'] . '.svg' );
								$fontFiles['eot']  = $zip->getFromName( 'fonts/' . $selectionData['metadata']['name'] . '.eot' );

								$dir = GroovyMenuUtils::getFontsDir();

								foreach ( $fontFiles as $font_type => $font_file ) {
									if ( false !== $font_file ) {
										$type         = esc_attr( $font_type );
										$put_contents = $wp_filesystem->put_contents( $dir . $name . '.' . $type, $fontFiles[ $type ], FS_CHMOD_FILE );
									}
								}

								$generated_font_css = GroovyMenuUtils::generate_fonts_css( $name, $selectionData, $fontFiles );

								$put_contents = $wp_filesystem->put_contents( $dir . $name . '.css', $generated_font_css['css'], FS_CHMOD_FILE );

								$icons = array();
								foreach ( $generated_font_css['data']['icons'] as $icon ) {
									$icon_name = isset( $icon['gm-name'] ) ? $icon['gm-name'] : $icon['icon']['tags'][0];

									$icons[] = array(
										'name' => $icon_name,
										'code' => $icon['properties']['code']
									);
								}
								$fonts[ $name ] = array( 'icons' => $icons, 'name' => $selectionData['metadata']['name'] );
								\GroovyMenu\FieldIcons::setFonts( $fonts );
							}
						} else {
							die( esc_html__( "Wasn't able to work with Zip Archive. Missing php-zip extension.", 'groovy-menu' ) );
						}
					}
					$menu_settings = ( isset( $_POST['menu'] ) && is_array( $_POST['menu'] ) ) ? wp_unslash( $_POST['menu'] ) : array();
					$menu_settings = map_deep( $menu_settings, 'sanitize_textarea_field' );
					$this->settings()->updateGlobal( $menu_settings );

				if ( function_exists( 'groovy_menu_check_gfonts_params' ) ) {
					groovy_menu_check_gfonts_params();
				}

				echo esc_html__( 'Saved', 'groovy-menu' );
			}

			echo $this->hardRedirectToDashboard();
		}

		public function deleteFont() {
			if ( ! isset( $_GET['gm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['gm_nonce'] ) ), 'gm_nonce_editor' ) ) {
				return;
			}

			if ( GroovyMenuRoleCapabilities::globalOptions( true ) ) {
				$font_name = isset( $_GET['name'] ) ? sanitize_file_name( wp_unslash( $_GET['name'] ) ) : '';
				$fonts = \GroovyMenu\FieldIcons::getFonts();
				unset( $fonts[ $font_name ] );
				\GroovyMenu\FieldIcons::setFonts( $fonts );
				\GroovyMenuUtils::delete_icon_pack_files( $font_name );
			}
			exit;
		}

		public function duplicate() {
			if ( ! isset( $_GET['gm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['gm_nonce'] ) ), 'gm_nonce_editor' ) ) {
				return;
			}

			if ( GroovyMenuRoleCapabilities::presetCreate( true ) ) {
				$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

				$preset    = GroovyMenuPreset::getById( $id );
				$newId     = GroovyMenuPreset::create( $preset->name . ' duplicated' );
				$newPreset = new GroovyMenuPreset( $newId );
				$styles    = new GroovyMenuStyle( $preset->id );
				$styles->setPreset( $newPreset );
				$styles->update();
				$redirect_url = add_query_arg(
					array( 'page' => 'groovy_menu_settings' ),
					admin_url( 'admin.php' )
				);

				GroovyMenuUtils::safe_redirect( $redirect_url );
			}
			exit;
		}


		/**
		 * Import preset.
		 *
		 * @return null
		 */
		public function import( $exist_preset = 0 ) {
			if ( ! GroovyMenuRoleCapabilities::canImport( true ) ) {
				return;
			}

			$import_tmp_name = isset( $_FILES['import'] ) && isset( $_FILES['import']['tmp_name'] ) && is_string( $_FILES['import']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['import']['tmp_name'] ) ) : '';

			if ( '' !== $import_tmp_name ) {
				global $wp_filesystem;
				if ( empty( $wp_filesystem ) ) {
					$file_path = str_replace( array(
						'\\',
						'/'
					), DIRECTORY_SEPARATOR, ABSPATH . '/wp-admin/includes/file.php' );

					if ( file_exists( $file_path ) ) {
						require_once $file_path;
						WP_Filesystem();
					}
				}
				if ( empty( $wp_filesystem ) ) {
					if ( function_exists( 'file_get_contents' ) ) {
						$data = json_decode( file_get_contents( $import_tmp_name ), true );
					}
				} else {
					$data = json_decode( $wp_filesystem->get_contents( $import_tmp_name ), true );
				}
			}

			// Stop import, if has error.
			if ( empty( $data ) || ! is_array( $data ) ) {
				wp_die( esc_html__( 'Error. When get uploaded file. Or wrong file format.', 'groovy-menu' ) );
			}
			if ( $exist_preset > 0 ) {
				$presetId = $exist_preset;
			} else {
				$presetId = GroovyMenuPreset::create( $data['name'] );
			}

			// Disable cache.
			GroovyMenuPreset::getAll( true, true );

			// Get new preset.
			$style = new GroovyMenuStyle( $presetId );

			foreach ( $data['settings'] as $field => $value ) {
				if ( is_array( $value ) && isset( $value['type'] ) && $value['type'] === 'media' ) {
					$uploadDir  = wp_upload_dir();
					$filename   = $uploadDir['path'] . '/' . $field . '_' . $presetId . '.png';
					$tmpFile    = file_put_contents( $filename, base64_decode( $value['data'] ) );
					$attachment = array(
						'guid'           => $uploadDir['url'] . '/' . basename( $filename ),
						'post_mime_type' => $value['post_mime_type'],
						'post_title'     => basename( $filename ),
						'post_content'   => '',
						'post_status'    => 'inherit'
					);

					$value = wp_insert_attachment( $attachment, $filename );

					$file_path = str_replace( array(
						'\\',
						'/'
					), DIRECTORY_SEPARATOR, ABSPATH . '/wp-admin/includes/image.php' );

					require_once $file_path;

					$attachData = wp_generate_attachment_metadata( $value, $tmpFile );
					wp_update_attachment_metadata( $value, $attachData );

				}
				$style->set( $field, $value );
			}

			$style->update();
			$style = new GroovyMenuStyle( $presetId );

			if ( ! empty( $data['img'] ) ) {
				GroovyMenuPreset::setPreviewById( $presetId, $data['img'] );
			}

			if ( function_exists( 'groovy_menu_check_gfonts_params' ) ) {
				groovy_menu_check_gfonts_params();
			}

			$redirect_url = add_query_arg(
				array( 'page' => 'groovy_menu_settings' ),
				admin_url( 'admin.php' )
			);

			GroovyMenuUtils::safe_redirect( $redirect_url );
		}


		/**
		 * Import preset (update).
		 *
		 * @return null
		 */
		public function importPreset() {
			if ( ! isset( $_GET['gm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['gm_nonce'] ) ), 'gm_nonce_editor' ) ) {
				return;
			}

			if ( ! GroovyMenuRoleCapabilities::canImport( true ) ) {
				return;
			}

			$presetId = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
			if ( empty( $presetId ) ) {
				return;
			}

			if ( ! empty( $presetId ) ) {
				self::import( $presetId );
			}

			return;
		}

		/**
		 * @param $id
		 */
		public function importFromLibraryById( $id ) {
			if ( ! GroovyMenuRoleCapabilities::canImport( true ) ) {
				return;
			}

			$preset = $this->getPresetsFromApiById( $id );
			$data   = $this->getDataFromApi( $preset['url'] );

			if ( empty( $data ) ) {
				return;
			}

			$presetId = GroovyMenuPreset::create( $preset['name'] );
			$style    = new GroovyMenuStyle( $presetId );

			foreach ( $data['settings'] as $field => $value ) {
				$style->set( $field, $value );
			}
			$style->update();
			$style = new GroovyMenuStyle( $presetId );
			GroovyMenuPreset::setPreviewById( $presetId, $data['img'] );
		}

		public function importFromLibrary() {
			if ( ! isset( $_GET['gm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['gm_nonce'] ) ), 'gm_nonce_editor' ) ) {
				return;
			}

			if ( ! GroovyMenuRoleCapabilities::canImport( true ) ) {
				return;
			}

			$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

			$preset = $this->getPresetsFromApiById( $id );
			$data   = $this->getDataFromApi( $preset['url'] );

			if ( empty( $data ) ) {
				return;
			}

			\GroovyMenu\StyleStorage::getInstance()->set_disable_storage();

			$presetId   = GroovyMenuPreset::create( $preset['name'] );
			$preset_obj = new GroovyMenuPreset( $presetId );
			$style      = new GroovyMenuStyle( $presetId );
			$style->setPreset( $preset_obj );

			foreach ( $data['settings'] as $field => $value ) {
				$style->set( $field, $value );
			}

			$style->update();
			$style = new GroovyMenuStyle( $presetId );
			GroovyMenuPreset::setPreviewById( $presetId, $data['img'] );

			if ( function_exists( 'groovy_menu_check_gfonts_params' ) ) {
				groovy_menu_check_gfonts_params();
			}

			$redirect_url = add_query_arg(
				array( 'page' => 'groovy_menu_settings' ),
				admin_url( 'admin.php' )
			);

			GroovyMenuUtils::safe_redirect( $redirect_url );
		}

		public function delete() {
			if ( ! isset( $_GET['gm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['gm_nonce'] ) ), 'gm_nonce_editor' ) ) {
				return;
			}

			if ( GroovyMenuRoleCapabilities::presetDelete( true ) ) {
				$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

				GroovyMenuPreset::deleteById( $id, true );

				$redirect_url = add_query_arg(
					array( 'page' => 'groovy_menu_settings' ),
					admin_url( 'admin.php' )
				);

				GroovyMenuUtils::safe_redirect( $redirect_url );
			}
			exit;
		}


		public function hardRedirectToDashboard() {
			$redirect_url = add_query_arg(
				array( 'page' => 'groovy_menu_settings' ),
				admin_url( 'admin.php' )
			);
			$html         = '';
			$tag_type     = array(
				'name' => 'script',
				'type' => 'text/javascript'
			);
			$html         .= '<' . $tag_type['name'] . ' type="' . $tag_type['type'] . '">';
			$html         .= 'window.location.replace("' . $redirect_url . '");';
			$html         .= '</' . $tag_type['name'] . '>';

			return $html;
		}


		public function showDashboardHeader() {
			?>
			<div class="gm-dashboard-header">
				<div class="gm-dashboard-header__logo">
					<a
						href="?page=groovy_menu_settings">
						<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/groovy_doc_white.svg" alt="">
					</a>
				</div>
				<div class="gm-dashboard-header__btn-group">
					<?php if ( GroovyMenuRoleCapabilities::globalOptions( true ) ) : ?>
						<button class="gm-dashboard__global-settings-btn">
							<span class="gm-gui-icon gm-icon-tools"></span>
							<span class="global-settings-btn__txt-group">
		                    <span
			                    class="global-settings-btn-title"><?php esc_html_e( 'Global settings', 'groovy-menu' ); ?></span>
		                    <span
			                    class="global-settings-btn-subtitle"><?php esc_html_e( 'Upload logo here', 'groovy-menu' ); ?></span>
					</span>
						</button>
					<?php endif; ?>
					<a target="_blank" href="https://grooni.com/docs/groovy-menu/" class="gm-dashboard-header__help-link">
						<span class="gm-gui-icon gm-icon-help"></span>
						<span
							class="gm-dashboard-header__help-link__txt"><?php esc_html_e( 'Need help?', 'groovy-menu' ); ?></span>
					</a>
				</div>
			</div>
			<?php
		}


		public function welcome_full() {
			return $this->welcome_free();
		}


		public function welcome_ext() {
			return $this->welcome_free();
		}


		public function welcome_free() {
			/**
			 * Fires before the groovy menu welcome page output.
			 *
			 * @since 1.9.0
			 */
			do_action( 'gm_before_welcome_output' );

			?>

			<div class="gm-welcome-container">
				<div class="gm-welcome-body">
					<div class="gm-welcome-header">
                    <span class="gm-welcome-header__logo">
                      <img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/groovy-menu-repsonsive-logo.svg" alt="">
                      <span><?php esc_html_e( 'free version', 'groovy-menu' ); ?></span>
                    </span>
						<h1 class="gm-welcome-header__title">
							<span><?php esc_html_e( 'Enjoying GROOVY?', 'groovy-menu' ); ?></span>
							<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/5-stars.svg" alt=""><br>
							<?php esc_html_e( 'Why not leave a review on WordPress.org? We\'d really appreciate it.', 'groovy-menu' ); ?>
						</h1>
							<span class="gm-welcome-header__version"><?php echo esc_html( GROOVY_MENU_VERSION ); ?></span>
					</div>
					<div class="gm-welcome-top-block">
						<div class="gm-welcome-top-block__txt">
							<h2><?php esc_html_e( 'Groovy Mega Menu!', 'groovy-menu' ); ?></h2>
							<p><?php echo wp_kses_post( __( 'Thank you for choosing our plugin! Add an awesome mega menu on your site. Is an easy to customize, just need to upload your <br> logo and fit your own colors, fonts and sizes.', 'groovy-menu' ) ); ?></p>
						</div>
						<div class="gm-welcome-top-block__img">
							<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/laptop-with-bg.png" alt="">
						</div>
					</div>
					<div class="gm-welcome-tiles">
						<div class="gm-welcome-tile">
							<h2 class="gm-welcome-tile__title"><?php esc_html_e( 'First Steps', 'groovy-menu' ); ?></h2>
							<p class="gm-welcome-tile__txt"><?php esc_html_e( 'To display the menu on the site, you need to add', 'groovy-menu' ); ?>
								<a href="<?php echo esc_url( admin_url( 'nav-menus.php' ) ); ?>"><?php esc_html_e( 'menu items', 'groovy-menu' ); ?></a>,
								<?php esc_html_e( 'do the', 'groovy-menu' ); ?> <a
									href="<?php echo esc_url( admin_url( 'admin.php?page=groovy_menu_integration' ) ); ?>"><?php esc_html_e( 'integration', 'groovy-menu' ); ?></a>, <?php esc_html_e( 'and', 'groovy-menu' ); ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=groovy_menu_settings' ) ); ?>"><?php esc_html_e( 'upload the logo', 'groovy-menu' ); ?></a>. <?php esc_html_e( 'And', 'groovy-menu' ); ?>
								<a href="<?php echo esc_url( admin_url( 'customize.php' ) ); ?>"><?php esc_html_e( 'customize', 'groovy-menu' );
									?></a> <?php esc_html_e( 'the menu design for your taste', 'groovy-menu' ); ?> .</p>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=groovy_menu_settings' ) ); ?>"
								class="gm-welcome-tile__link"><?php esc_html_e( 'dashboard', 'groovy-menu' ); ?></a>
						</div>
						<div class="gm-welcome-tile">
							<h2><?php esc_html_e( 'Integration', 'groovy-menu' ); ?></h2>
							<p><?php esc_html_e( 'The automatic integration option is the easiest and in most cases the working way to implement Groovy Menu on your website...', 'groovy-menu' ); ?></p>
							<a href="https://grooni.com/docs/groovy-menu/integration/" class="gm-welcome-tile__link"
								target="_blank"><?php esc_html_e( 'READ MORE', 'groovy-menu' ); ?></a>
						</div>
						<div class="gm-welcome-tile">
							<h2 class="gm-welcome-tile__title"><?php esc_html_e( 'Need help?', 'groovy-menu' ); ?></h2>
							<p class="gm-welcome-tile__txt">
								<?php esc_html_e( 'Our online', 'groovy-menu' ); ?>
								<a target="_blank"
									href="http://grooni.com/docs/groovy-menu/"><?php esc_html_e( 'documentation', 'groovy-menu' ); ?></a>
								<?php esc_html_e( 'and', 'groovy-menu' ); ?>
								<a target="_blank"
									href="https://www.youtube.com/channel/UCpbGGAUnqSLwCAoNgm5uAKg"><?php esc_html_e( 'video tutorials', 'groovy-menu' ); ?></a>
								<?php esc_html_e( 'consist of a lot of the most important information about the plugin settings.', 'groovy-menu' ); ?>
							</p>
							<div class="gm-welcome-tile__link-group">
								<a href="https://grooni.com/docs/groovy-menu/"
									class="gm-welcome-tile__link"><?php esc_html_e( 'MANUAL', 'groovy-menu' ); ?></a>
								<a href="https://www.youtube.com/channel/UCpbGGAUnqSLwCAoNgm5uAKg"
									class="gm-welcome-tile__link gm-welcome-tile__link--secondary-color"><?php esc_html_e( 'VIDEO', 'groovy-menu' );
									?></a>
							</div>
						</div>
					</div>
					<div class="gm-tuts">
						<h2 class="gm-welcome-title"><?php esc_html_e( 'Video', 'groovy-menu' ); ?>
							<span><?php esc_html_e( 'Tutorials', 'groovy-menu' ); ?></span></h2>
						<div class="gm-tuts-grid">
							<a target="_blank" href="https://www.youtube.com/watch?v=w1SIBwMdfn8&t=7s" class="gm-tuts-grid-item
              gm-tuts-grid-item--xl">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/gmfree-howtoinstall.jpg" alt=""
									class="gm-tuts-grid-item__img">
							</a>
							<a target="_blank" href="https://www.youtube.com/watch?v=_f-11Ujp410"
								class="gm-tuts-grid-item">
								<img
									src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/youtube-gmfree-how-to-create-mega-menu.jpg"
									alt=""
									class="gm-tuts-grid-item__img">
							</a>
							<a target="_blank" href="https://www.youtube.com/watch?v=LKSRL5TZkIU"
								class="gm-tuts-grid-item">
								<img
									src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/youtube-gmfree-hover-appearance-effects.jpg"
									alt=""
									class="gm-tuts-grid-item__img">
							</a>
							<a target="_blank" href="https://www.youtube.com/watch?v=V5MaXJ0CMx4"
								class="gm-tuts-grid-item">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/youtube-gmfree-fullwidth-menu.jpg"
									alt=""
									class="gm-tuts-grid-item__img">
							</a>
							<a target="_blank" href="https://www.youtube.com/watch?v=jl34DRTw-9k"
								class="gm-tuts-grid-item">
								<img
									src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/youtube-gmfree-how-to-font-size-style.jpg"
									alt=""
									class="gm-tuts-grid-item__img">
							</a>
							<a target="_blank" href="https://www.youtube.com/watch?v=AKzqxE9OTY0"
								class="gm-tuts-grid-item">
								<img
									src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/youtube-gmfree-how-to-change-colors.jpg"
									alt=""
									class="gm-tuts-grid-item__img">
							</a>
							<a target="_blank" href="https://www.youtube.com/watch?v=hIZ3uHaMZGA"
								class="gm-tuts-grid-item">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/Layer_881.jpg" alt=""
									class="gm-tuts-grid-item__img">
							</a>
						</div>
						<a href="https://www.youtube.com/channel/UCpbGGAUnqSLwCAoNgm5uAKg"
							class="bg-welcome-btn gm-tuts__btn"><?php esc_html_e( 'View all tutorials', 'groovy-menu' );
							?></a>
					</div>
					<div class="gm-welcome-comparision">
						<h2 class="gm-welcome-title"><?php esc_html_e( 'Free version vs', 'groovy-menu' ); ?>
							<span><?php esc_html_e( 'PREMIUM', 'groovy-menu' ); ?></span></h2>
						<p><?php esc_html_e( 'Comparision table', 'groovy-menu' ); ?></p>
						<div class="gm-welcome-comparision-grid">
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__title
                      gm-welcome-comparision-grid__feature"><?php esc_html_e( 'FEATURE LIST', 'groovy-menu' ); ?></div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__title
                      gm-welcome-comparision-grid__free"><?php esc_html_e( 'FREE PLUGIN', 'groovy-menu' ); ?></div>
							<div
								class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__title gm-welcome-comparision-grid__premium">
								<span
									class="gm-gui-icon gm-icon-crown gm-welcome-align-center"></span><?php esc_html_e( 'PREMIUM', 'groovy-menu' ); ?>
							</div>

							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__feature">
								<?php esc_html_e( 'Mega menu', 'groovy-menu' ); ?>
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__free">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__premium">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>

							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__feature">
								<?php esc_html_e( 'Mega menu blocks', 'groovy-menu' ); ?>
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__free">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/no.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__premium">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__feature">
								<?php esc_html_e( 'Sticky menu', 'groovy-menu' ); ?>
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__free">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/no.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__premium">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__feature">
								<?php esc_html_e( 'Vertical menu', 'groovy-menu' ); ?>
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__free">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/no.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__premium">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__feature">
								<?php esc_html_e( 'Icon menu', 'groovy-menu' ); ?>
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__free">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/no.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__premium">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__feature">
								<?php esc_html_e( 'Minimal menu', 'groovy-menu' ); ?>
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__free">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/no.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__premium">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__feature">
								<?php esc_html_e( 'Hovers', 'groovy-menu' ); ?>
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__free">
								<span><?php esc_html_e( 'Only 2 hover types', 'groovy-menu' ); ?></span>
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__premium">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__feature">
								<?php esc_html_e( 'Logotypes', 'groovy-menu' ); ?>
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__free">
								<span><?php esc_html_e( 'Only 1 logo + mobile logo', 'groovy-menu' ); ?></span>
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__premium">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__feature">
								<?php esc_html_e( 'Online presets library', 'groovy-menu' ); ?>
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__free">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/no.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__premium">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__feature">
								<?php esc_html_e( 'Different menus types on the one site', 'groovy-menu' ); ?>
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__free">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/no.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__premium">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__feature">
								<?php esc_html_e( 'Export/import of settings', 'groovy-menu' ); ?>
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__free">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/no.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__premium">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__feature">
								<?php esc_html_e( 'Badges', 'groovy-menu' ); ?>
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__free">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/no.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__premium">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__feature">
								<?php esc_html_e( 'WooCommerce integration', 'groovy-menu' ); ?>
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__free">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__premium">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__feature">
								<?php esc_html_e( 'Google fonts', 'groovy-menu' ); ?>
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__free">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__premium">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__feature">
								<?php esc_html_e( 'Search feature', 'groovy-menu' ); ?>
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__free">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__premium">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__feature">
								<?php esc_html_e( 'Custom icons', 'groovy-menu' ); ?>
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__free">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__premium">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__feature">
								<?php esc_html_e( 'Premium support', 'groovy-menu' ); ?>
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__free">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/no.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__premium">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__feature">
								<?php esc_html_e( 'Automatic integration', 'groovy-menu' ); ?>
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__free">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__premium">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__feature">
								<?php esc_html_e( 'Manual integration', 'groovy-menu' ); ?>
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__free">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__premium">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__feature">
								<?php esc_html_e( 'Extended license', 'groovy-menu' ); ?>
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__free">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/no.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__premium">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__feature">
								<?php esc_html_e( 'Set specific menu for the taxonomies', 'groovy-menu' ); ?>
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__free">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/no.svg" alt="">
							</div>
							<div class="gm-welcome-comparision-grid__item gm-welcome-comparision-grid__premium">
								<img src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/yes.svg" alt="">
							</div>
						</div>
					</div>
					<a href="https://1.envato.market/regular"
						class="bg-welcome-btn"><?php esc_html_e( 'UPGRADE TO PRO', 'groovy-menu' ); ?></a>
				</div>
			</div>


			<?php

			/**
			 * Fires after the groovy menu welcome page output.
			 *
			 * @since 1.9.0
			 */
			do_action( 'gm_after_welcome_output' );

		}


		public function integrationDashboard() {
			/**
			 * Fires before the groovy menu dashboard output.
			 *
			 * @since 1.4
			 */
			do_action( 'gm_before_integration_dashboard_output' );

			$current_theme  = wp_get_theme()->get_template();
			$child_proposal = null;

			global $wp_version;

			$child_search_data = wp_remote_get(
				add_query_arg(
					array( 'theme' => $current_theme ),
					$this->remote_child_themes_url
				),
				array(
					'timeout'     => 60,
					'httpversion' => '1.1',
					'user-agent'  =>
						'WordPress/' . $wp_version . ';' . $current_theme . ';' . ( is_child_theme() ? 'child' : 'parent' ) . ';' . get_bloginfo( 'url' )
				)
			);


			// Check if returned answer is OK
			if ( ! is_wp_error( $child_search_data ) && wp_remote_retrieve_response_code( $child_search_data ) === 200 ) {
				$child_proposal = json_decode( wp_remote_retrieve_body( $child_search_data ), true );
				if ( ! empty( $child_proposal['error'] ) ) {
					$child_proposal = null;
				}
			}

			$saved_auto_integration     = GroovyMenuUtils::getAutoIntegration();
			$saved_location_integration = GroovyMenuUtils::getSingleLocationIntegration();

			$admin_nav_menu_page = '<a href="' . esc_url( admin_url( 'nav-menus.php?action=locations' ) ) . '">' . esc_html__( 'Manage Locations', 'groovy-menu' ) . '</a>';

			//$pages_list = GroovyMenuUtils::getPagesList(); // TODO one page TEST-DEV mode

			?>

			<div class="gm-dashboard-container gm-dashboard__integration">
				<?php $this->showDashboardHeader(); ?>
				<div class="gm-dashboard-body">
					<div class="gm-dashboard-body_inner">

						<div class="gm-dashboard-body-title">
							<h2><?php esc_html_e( 'Please select one of integration method below', 'groovy-menu' ); ?></h2>
							<p><?php echo sprintf(
									esc_html__( 'You can get more info from the %s', 'groovy-menu' ),
									sprintf(
										'<a href="%s" target="_blank" class="gm-integration-link gm-integration-link--video"><img class="gm-gui-picture gm-gui-picture__play-video" src="%sassets/images/play-video.svg" alt="">' . esc_html__( 'video tutorial', 'groovy-menu' ) . '</a>',
										esc_url( 'https://youtu.be/7QjpDT8NUWI' ),
										GROOVY_MENU_URL
									)
								); ?></p>
						</div>

						<?php
						if ( class_exists( 'DiviGrooniGroovyMenu_Init' ) ) { ?>
							<div class="gm-dashboard-body-section gm-dashboard-body-section--divi">
								<div class="gm-dashboard-body-block--left">
									<img class="gm-gui-picture gm-gui-picture__integration"
										src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/integration.svg" alt="">
								</div>
								<div class="gm-dashboard-body-block--right">
									<h3><?php esc_html_e( 'Integration for DIVI Theme', 'groovy-menu' ); ?></h3>
									<p><?php esc_html_e( 'We automatically recognized the current theme as the DIVI, For the DIVI theme we have module already integrated into code of the plugin.', 'groovy-menu' ); ?></p>
									<p><?php esc_html_e( 'So you don\'t need to do something for integration!', 'groovy-menu' ); ?></p>
									<p><?php echo sprintf(
											esc_html__( 'How To Create A DIVI Mega Menu with Groovy Menu %s', 'groovy-menu' ),
											sprintf(
												'<a href="%s" target="_blank" class="gm-integration-link gm-integration-link--video"><img class="gm-gui-picture gm-gui-picture__play-video" src="%sassets/images/play-video.svg" alt="">' . esc_html__( 'video tutorial', 'groovy-menu' ) . '</a>',
												esc_url( 'https://youtu.be/ZiGtqayLllk' ),
												GROOVY_MENU_URL
											)
										); ?></p>
								</div>
							</div>
						<?php } ?>


						<?php
						// Child Theme exists in base
						if ( ! empty( $child_proposal ) && is_array( $child_proposal ) ) { ?>
							<div class="gm-dashboard-body-section gm-dashboard-body-section--child">
								<div class="gm-dashboard-body-block--left">
									<img class="gm-gui-picture gm-gui-picture__integration"
										src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/integration.svg" alt="">
								</div>
								<div class="gm-dashboard-body-block--right">
									<h3><?php
										if ( 'plugin' === $child_proposal['integration_type'] ) {
											esc_html_e( 'Integration through additional Plugin', 'groovy-menu' );
										} else {
											esc_html_e( 'Integration through Child Theme', 'groovy-menu' );
										}
										?></h3>
									<?php if ( isset( $child_proposal['auto_integration'] ) && $child_proposal['auto_integration'] ) { ?>
										<p><?php esc_html_e( 'According to the information from the Groovy Menu integration database, your current theme fully supports integration of Groovy Menu.', 'groovy-menu' ) ?></p>
									<?php } ?>

									<?php if ( isset( $child_proposal['zip_url'] ) && $child_proposal['zip_url'] ) { ?>
										<p><?php echo sprintf( esc_html__( 'Your activated %s theme is already in our database. That is mean you don\'t need to use auto or manual integration.', 'groovy-menu' ), $current_theme ) ?></p>
										<p><?php
											if ( 'plugin' === $child_proposal['integration_type'] ) {
												esc_html_e( 'We already prepared additional Plugin as the solution for your integration. Please download and activate', 'groovy-menu' );
											} else {
												esc_html_e( 'We already prepared Child theme as the solution for your integration. Please download and activate', 'groovy-menu' );
											} ?>:
										</p>
										<p>
											<a href="<?php echo esc_attr( $child_proposal['zip_url'] ); ?>"
												class="gm-welcome-big-button gm-welcome-big-button--blue"><?php echo esc_html( $child_proposal['child_name'] ); ?></a>
										</p>
									<?php } ?>

									<?php if ( isset( $child_proposal['integration_type'] ) && in_array( $child_proposal['integration_type'], array(
											'function',
											'header'
										) )
									) { ?>
										<div class="gm-dashboard-body-block--slider">
											<div class="gm-dashboard-body-block--slider_title">
												<h4><?php esc_html_e( 'For advanced users', 'groovy-menu' ); ?></h4>
											</div>
											<div class="gm-dashboard-body-block--slider_content">
												<p><?php
													if ( 'function' === $child_proposal['integration_type'] ) {
														esc_html_e( 'Create a Child theme or add to existing one the following code to functions.php.', 'groovy-menu' );
													}
													if ( 'header' === $child_proposal['integration_type'] ) {
														esc_html_e( 'For the correct operation of the plugin, you should create a Child theme with header.php and add the support code to it. Following code just example. We suggest download our child theme below.', 'groovy-menu' );
													}
													?></p>
												<pre><?php echo( empty( $child_proposal['function_code'] ) ? '' : $child_proposal['function_code'] ); ?></pre>
											</div>
										</div>
									<?php } ?>
								</div>
							</div>
						<?php } ?>


						<div class="gm-dashboard-body-section">
							<div class="gm-dashboard-body-block--left">
								<img class="gm-gui-picture gm-gui-picture__auto-integration"
									src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/auto-integration.svg" alt="">
							</div>
							<div class="gm-dashboard-body-block--right">
								<h3><?php esc_html_e( 'Automatic integration', 'groovy-menu' ); ?></h3>
								<label>
									<input class="gm-auto-integration-switcher" type="checkbox" class="switch"
										value="1"<?php if ( $saved_auto_integration ) {
										echo ' checked';
									} ?>>
									<?php esc_html_e( 'Enable automatic integration', 'groovy-menu' ); ?>
								</label>
								<button type="button" class="btn gm-integration-button gm-auto-integration-save">
									<?php esc_html_e( 'Save changes', 'groovy-menu' ); ?>
								</button>
								<input type="hidden" id="gm-nonce-auto-integration-field" name="gm_nonce"
									value="<?php echo esc_attr( wp_create_nonce( 'gm_nonce_auto_integration' ) ); ?>">
								<p><?php esc_html_e( 'If enabled, the Groovy Menu markup will be placed after &lt;body&gt; html tag.', 'groovy-menu' ); ?></p>
							</div>
						</div>


						<div class="gm-dashboard-body-section">
							<div class="gm-dashboard-body-block--left">
								<img class="gm-gui-picture gm-gui-picture__integration-location"
									src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/integration-location.svg" alt="">
							</div>
							<div class="gm-dashboard-body-block--right">
								<h3><?php esc_html_e( 'Choose the location for the integration menu into pre-defined areas in your theme.', 'groovy-menu' ); ?></h3>
								<p><?php esc_html_e( 'If chosen then the Groovy Menu will display its own markup instead of the standard code from the function wp_nav_menu().', 'groovy-menu' ); ?></p>
								<p>
									<label for="gm-integration-location">
										<?php esc_html_e( 'Theme Locations', 'groovy-menu' ); ?><br/>
										<select class="gm-integration-location"
											id="gm-integration-location"
											name="gm-integration-location">
											<option
												value="" <?php echo ( empty( $saved_location_integration ) ) ? ' selected' : '' ?>>
												--- <?php esc_html_e( 'Select a Location', 'groovy-menu' ); ?> ---
											</option>
											<?php
											foreach ( GroovyMenuUtils::getNavMenuLocations( false, true ) as $location => $name ) {
												// Prevent select Groovy Menu virtual location.
												if ( 'gm_primary' === $location ) {
													continue;
												}

												?>
												<option
													value="<?php echo esc_attr( $location ); ?>"<?php echo ( strval( $saved_location_integration ) === strval( $location ) ) ? ' selected' : '' ?>><?php echo esc_attr( $name ); ?></option>
												<?php
											}
											?>
										</select>
									</label>
								</p>
								<p><?php esc_html_e( 'Note:', 'groovy-menu' ); ?> <?php esc_html_e( 'Make sure the menu is assigned on the', 'groovy-menu' ); ?> <?php echo wp_kses_post( $admin_nav_menu_page ); ?> <?php esc_html_e( 'page. Otherwise, the location selection list will be empty.', 'groovy-menu' ); ?> <?php esc_html_e( 'Groovy menu Primary location will be ignored.', 'groovy-menu' ); ?> <?php esc_html_e( 'The Groovy Menu Primary area will be ignored.', 'groovy-menu' ); ?></p>
								<p>
									<button type="button"
										class="btn gm-integration-button gm-integration-location-save">
										<?php esc_html_e( 'Save changes', 'groovy-menu' ); ?>
									</button>
							</div>
						</div>


						<div class="gm-dashboard-body-section">
							<div class="gm-dashboard-body-block--left">
								<img class="gm-gui-picture gm-gui-picture__manual-integration"
									src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/manual-integration.svg" alt="">
							</div>
							<div class="gm-dashboard-body-block--right">
								<h3><?php esc_html_e( 'Manual integration', 'groovy-menu' ); ?></h3>
								<p><?php esc_html_e( 'Attention! We strongly recommend you insert the following code into a Child theme. Therefore, the changes you make will not affect the files of the parent theme.', 'groovy-menu' ); ?></p>
								<p><?php esc_html_e( 'You can display the Groovy menu  by adding the following code directly to the template:', 'groovy-menu' ); ?></p>
								<p>
									<code
										class="gm-integrate-php-sample">&lt;?php if ( function_exists( 'groovy_menu'
										) )
										{ groovy_menu(); } ?&gt;</code>
								</p>
								<p><?php esc_html_e( 'The place where the code should be inserted depends on the theme. The most common place is the', 'groovy-menu' ); ?>
									<code>header.php</code> <?php
									echo sprintf( esc_html__( 'after %s tag', 'groovy-menu' ),
										'<code>&lt;BODY&gt;</code>'
									); ?>.
								</p>
							</div>
						</div>


						<?php if ( ! $this->lver ) { ?>
							<div class="gm-dashboard-body-section">
								<div class="gm-dashboard-body-block--left">
									<img class="gm-gui-picture gm-gui-picture__need-integration"
										src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/need-integration.svg" alt="">
								</div>
								<div class="gm-dashboard-body-block--right">
									<h3><?php esc_html_e( 'Need integration for your theme?', 'groovy-menu' ); ?></h3>
									<p><?php esc_html_e( 'if none of the above methods of automatic integration doesn\'t work properly, and for manual integration, you do not have enough time and experience.', 'groovy-menu' ); ?></p>
									<p><?php esc_html_e( 'You can order to the manual integration service from our team.', 'groovy-menu' ); ?></p>
									<p><?php esc_html_e( 'We do it in the shortest time!', 'groovy-menu' ); ?></p>
									<p><a class="gm-welcome-big-button gm-welcome-big-button--green" href="https://gum.co/groovy-integration"
											target="_blank"><?php esc_html_e( 'Manual integration', 'groovy-menu' ); ?> $35</a></p>
								</div>
							</div>
						<?php } ?>


						<div class="gm-dashboard-body-section">
							<div class="gm-dashboard-body-block--left">
								<img class="gm-gui-picture gm-gui-picture__shortcode-integration"
									src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/shortcode-integration.svg" alt="">
							</div>
							<div class="gm-dashboard-body-block--right">
								<h3><?php esc_html_e( 'Shortcode integration', 'groovy-menu' ); ?></h3>
								<p><?php esc_html_e( 'You can use this shortcode  [groovy_menu]  to output the Groovy menu.', 'groovy-menu' ); ?></p>
								<p>
									<code class="gm-integrate-php-sample gm-integrate-shortcode">[groovy_menu]</code>
								</p>
								<p><?php esc_html_e( 'For that insert, this shortcode in a place where you would like to place the menu.', 'groovy-menu' ); ?></p>
								<?php
								//$this->shortcode_constructor_box();
								?>

							</div>
						</div>


						<?php if ( ! $this->lver ) { ?>
							<div class="gm-dashboard-body-subsection">
								<p><?php esc_html_e( 'Get stucked?', 'groovy-menu' ); ?>
									<img
										class="gm-gui-picture gm-gui-picture__need-help"
										src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/need-help.svg"
										alt="">
									<?php
									echo sprintf( esc_html__( 'Ask the %s team', 'groovy-menu' ),
										sprintf( '<a href="https://grooni.ticksy.com/" target="_blank">%s</a>', esc_html__( 'support', 'groovy-menu' ) )
									); ?></p>
							</div>
						<?php } ?>

					</div>
				</div>
			</div>

			<?php
			if ( GroovyMenuRoleCapabilities::globalOptions( true ) ) {

				$this->renderGlobalSettingModal();

				echo GroovyMenuRenderIconsModal();

			}
			?>

			<?php
			/**
			 * Fires after the groovy menu dashboard output.
			 *
			 * @since 1.4
			 */
			do_action( 'gm_after_integration_dashboard_output' );

		}

		/**
		 * Shortcode Constructor..
		 */
		public function shortcode_constructor_box() {

			$presets = GroovyMenuPreset::getAll();
			$menus   = get_terms( 'nav_menu', array( 'hide_empty' => false ) );

			?>
			<div class="groovy-meta-box-wrapper">
				<?php if ( ! $this->lver ) { ?>
					<div class="groovy-meta-box-item">
						<div class="groovy-meta-box-item--label">
							<label for="groovy-preset"><?php esc_html_e( 'Menu preset', 'groovy-menu' ); ?></label>
						</div>
						<div class="groovy-meta-box-item--select">
							<select id="groovy-preset" class="groovy-select-maxwidth">
								<option value=""><?php esc_html_e( 'Default', 'groovy-menu' ); ?></option>
								<?php foreach ( $presets as $preset ) { ?>
									<option value="<?php echo esc_attr( $preset->id ); ?>"><?php echo esc_html( $preset->name ); ?></option>
								<?php } ?>
							</select>
						</div>
					</div>
				<?php } ?>
				<div class="groovy-meta-box-item">
					<div class="groovy-meta-box-item--label">
						<label
							for="groovy-menu-name"><?php esc_html_e( 'Navigation menu (from Appearance > Menus)', 'groovy-menu' ); ?></label>
					</div>
					<div class="groovy-meta-box-item--select">
						<select id="groovy-menu-name" class="groovy-select-maxwidth">
							<option value=""><?php esc_html_e( 'Default', 'groovy-menu' ); ?></option>
							<?php foreach ( $menus as $menu ) { ?>
								<option value="<?php echo esc_attr( $menu->slug ); ?>"><?php echo esc_html( $menu->name ); ?></option>
							<?php } ?>
						</select>
					</div>
				</div>
			</div>
			<?php
		}

		public function edit() {
			$this->export();

			?>
			<div class="gm-gui-container">
				<?php
				$this->renderTabs();
				$this->renderPanes();
				?>
			</div>
			<?php
			echo GroovyMenuPreviewModal();
		}


		public function renderTabs() {
			?>
			<div class="gm-gui-nav-wrapper">

				<div class="gm-gui__brand-wrapper">
					<a href="?page=groovy_menu_settings">
						<img
							class="gm-gui__brand-logo"
							src="<?php echo esc_url( GROOVY_MENU_URL ); ?>assets/images/groovy_doc_white.svg"
							alt="">
					</a>
				</div>
				<ul class="gm-gui__nav-tabs">
					<?php
					$first = true;
					foreach ( $this->settings()->getSettings() as $categoryName => $category ) {
						$this->renderTab( $categoryName, $category, $first );
						$first = false;
					}
					?>
					<button class="gm-gui__restore-btn">
						<span
								class="gm-gui__nav-tabs__item__txt"><?php echo wp_kses_post( __( 'Restore <br>Defaults', 'groovy-menu' ) ); ?></span>
					</button>
				</ul>
			</div>
			<?php
		}


		public function renderGlobalSettingModal() {

			$form_action_url = add_query_arg(
				array(
					'page'     => 'groovy_menu_settings',
					'action'   => 'saveDashboardSettings',
					'gm_nonce' => wp_create_nonce( 'gm_nonce_saveDashboardSettings' ),
				),
				admin_url( 'admin.php' )
			);

			?>
			<div class="gm-modal gm-hidden" id="global-settings-modal">
				<form method="post" action="<?php echo esc_url( $form_action_url ); ?>" enctype="multipart/form-data"
					id="global-settings-form">
					<div class="gm-modal-body">
						<?php
						$this->renderTabsGlobal( $this->settings()->getSettingsGlobal() );
						$first = true;
						foreach ( $this->settings()->getSettingsGlobal() as $categoryName => $category ) {
							$this->renderTabGlobal( $category, $categoryName, $first );
							$first = false;
						}
						?>
					</div>
					<div class="gm-modal-footer">
						<div class="btn-group">
							<button type="submit"
								class="btn modal-btn"><?php esc_html_e( 'Save changes', 'groovy-menu' ); ?></button>
							<button type="button"
								class="btn modal-btn gm-modal-close"><?php esc_html_e( 'Close', 'groovy-menu' ); ?></button>
						</div>
					</div>
				</form>
			</div>
			<?php
		}


		/**
		 * @param $categoryName
		 * @param $category
		 * @param $isActive
		 */
		public function renderTab( $categoryName, $category, $isActive ) {
			?>
			<li
				class="gm-gui__nav-tabs__item <?php echo ( $isActive ) ? 'active' : ''; ?>"
				data-tab="<?php echo esc_attr( $categoryName ); ?>"
			>
				<span class="gm-gui__nav-tabs__item__anchor">
					<span class="gm-gui-icon <?php echo esc_attr( $category['icon'] ); ?>"></span>
					<span class="gm-gui__nav-tabs__item__txt"><?php echo esc_html( $category['title'] ); ?></span>
				</span>
				<?php
				$this->renderTabSublevel( $categoryName );
				?>
			</li>
			<?php
		}


		/**
		 * @param $categoryName
		 */
		public function renderTabSubLevel( $categoryName ) {
			?>
			<ul class="gm-gui__nav-tabs__sublevel">
				<?php foreach ( $this->settings()->getGroups( $categoryName ) as $sublevelKey => $sublevel ) { ?>
					<li class="gm-gui__nav-tabs__sublevel__item"
						data-sublevel="<?php echo esc_attr( $sublevelKey ); ?>"
						<?php echo ( isset( $sublevel['condition'] ) ) ? ' data-condition=\'' . esc_attr( wp_json_encode( $sublevel['condition'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) ) . '\'' : ''; ?>
						<?php echo ( isset( $sublevel['condition_type'] ) ) ? ' data-condition_type="' . esc_attr( $sublevel['condition_type'] ) . '" ' : ''; ?>
					>
						<span
							class="gm-gui__nav-tabs__sublevel__item__anchor"><?php echo esc_html( $sublevel['title'] ); ?></span>
					</li>
				<?php } ?>
			</ul>
			<?php
		}

		public function renderPanes() {
			$title = $this->settings()->getPreset()->getName();
			?>
			<div class="gm-gui__tab-panes gm-clearfix">
				<form
					name="preset"
					action=""
					method="post"
					class="gm-form"
					autocomplete="off"
					enctype="multipart/form-data"
					data-id="<?php echo esc_attr( $this->settings()->getPreset()->getId() ); ?>"
					data-version="<?php echo esc_attr( GROOVY_MENU_VERSION ); ?>">
					<div class="gm-gui__preset-name"><?php echo esc_html( $title ); ?></div>
					<input type="hidden" name="groovy_menu_save_theme" value="save"/>
					<input type="hidden" name="gm_nonce" id="gm-nonce-save-preset-action"
						value="<?php echo esc_attr( wp_create_nonce( 'gm_nonce_preset_save' ) ); ?>"/>
					<?php
					wp_nonce_field( 'groovy_menu_save_theme' );
					$first = true;
					foreach ( $this->settings()->getSettings() as $categoryName => $category ) {
						$this->renderPane( $categoryName, $category, $first );
						$first = false;
					}
					?>
					<div class="gm-gui-btn-group">
						<button
							class="gm-gui-btn gm-gui-preview-btn"
							type="button">
							<i class="fa fa-search"></i>
							<?php esc_html_e( 'Preview', 'groovy-menu' ); ?>
						</button>
						<?php if ( GroovyMenuRoleCapabilities::presetEdit( true ) ) : ?>
							<button
								class="gm-gui-btn gm-gui-restore-section-btn"
								type="submit">
								<i class="fa fa-undo"></i>
								<?php esc_html_e( 'Restore', 'groovy-menu' ); ?>
							</button>
							<button
								class="gm-gui-btn gm-gui-save-btn"
								type="submit">
								<i class="fa fa-floppy-o"></i><?php esc_html_e( 'Save', 'groovy-menu' ); ?>
							</button>
						<?php endif; ?>
					</div>
				</form>
			</div>
			<?php
		}

		/**
		 * @param $categoryName
		 * @param $category
		 * @param $isActive
		 */
		public function renderPane( $categoryName, $category, $isActive ) {
			?>
			<div
				class="tab-pane <?php echo ( $isActive ) ? 'active' : ''; ?>"
				id="<?php echo esc_attr( $categoryName ); ?>">
				<span class="tab-pane__header"><?php echo esc_html( $category['title'] ); ?></span>
				<div>
					<?php
					foreach ( $category['fields'] as $name => $field ) {
						$this->renderField( $categoryName, $name, $field );
					}
					?>
				</div>
			</div>
			<?php
		}

		/**
		 * @param $categoryName
		 * @param $name
		 * @param $field
		 */
		public function renderField( $categoryName, $name, $field ) {
			$this->settings()->getField( $categoryName, $name )->render();
		}

		function getSettings() {
			$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
			if ( defined( 'DOING_AJAX' ) && DOING_AJAX && ! empty( $_POST ) && 'gm_get_setting' === $action ) {

				$preset_id = empty( $_POST['preset_id'] ) ? 0 : absint( wp_unslash( $_POST['preset_id'] ) );

				if ( empty( $preset_id ) ) {
					// Send a JSON response back to an AJAX request, and die().
					wp_send_json_error( esc_html__( 'Error. Missing id of the current menu', 'groovy-menu' ) );
				}

				$styles = new GroovyMenuStyle( $preset_id );

				$groovyMenuSettings           = $styles->serialize();
				$groovyMenuSettings['preset'] = array(
					'id'   => $styles->getPreset()->getId(),
					'name' => $styles->getPreset()->getName()
				);

				wp_send_json_success( $groovyMenuSettings );
			}
		}

		function saveSettings() {

			if ( ! isset( $_POST['gm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gm_nonce'] ) ), 'gm_nonce_preset_save' ) ) {
				// Send a JSON response back to an AJAX request, and die().
				wp_send_json_error( esc_html__( 'Fail. Nonce field outdated. Try reload page.', 'groovy-menu' ) );
			}

			$cap_can = GroovyMenuRoleCapabilities::presetEdit( true );

			$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
			if ( $cap_can && defined( 'DOING_AJAX' ) && DOING_AJAX && ! empty( $_POST ) && 'gm_save' === $action ) {

				$ajax_data = [];

				$ajax_data_raw = isset( $_POST['data'] ) && is_string( $_POST['data'] ) ? json_decode( wp_unslash( $_POST['data'] ), true ) : array();
				if ( ! is_array( $ajax_data_raw ) ) {
					$ajax_data_raw = array();
				}
				foreach ( $ajax_data_raw as $index => $item ) {
					preg_match( '#^menu\[(\w+)\]\[(\w+)\]#', $index, $matches );
					if ( empty( $matches ) || empty( $matches[1] ) || empty( $matches[2] ) ) {
						$ajax_data[ $index ] = $item;
					} else {
						$ajax_data['menu'][ $matches[1] ][ $matches[2] ] = $item;
					}
				}

				if ( empty( $ajax_data ) ) {
					// Send a JSON response back to an AJAX request, and die().
					wp_send_json_error( esc_html__( 'Error. Bad form data', 'groovy-menu' ) );
				}

				$referer_raw   = isset( $ajax_data['_wp_http_referer'] ) && is_string( $ajax_data['_wp_http_referer'] ) ? $ajax_data['_wp_http_referer'] : '';
				$referer_parts = $referer_raw ? wp_parse_url( $referer_raw ) : array();
				$referer_query = isset( $referer_parts['query'] ) ? $referer_parts['query'] : '';
				$referer_url   = array();
				parse_str( $referer_query, $referer_url );
				$preset_id     = isset( $referer_url['id'] ) ? absint( $referer_url['id'] ) : 0;

				if ( ! $preset_id ) {
					// Send a JSON response back to an AJAX request, and die().
					wp_send_json_error( esc_html__( 'Error. Missing id of the current menu', 'groovy-menu' ) );
				}

				if ( isset( $ajax_data['groovy_menu_save_theme'] ) && $ajax_data['groovy_menu_save_theme'] === 'save' ) {

					update_post_meta( $preset_id, 'gm_preset_screenshot', '' );

					if ( ! empty( $ajax_data['menu'] ) && is_array( $ajax_data['menu'] ) ) {
						$preset_settings = $this->settings( $preset_id )->getSettings();

						foreach ( $ajax_data['menu'] as $group => $group_data ) {
							foreach ( $group_data as $option => $value ) {

								$option_data = $preset_settings[ $group ]['fields'][ $option ];

								if ( isset( $option_data['type'] ) && 'number' === $option_data['type'] ) {
									$ajax_data['menu'][ $group ][ $option ] = intval( $value );
								} elseif ( isset( $option_data['type'] ) && 'checkbox' === $option_data['type'] ) {
									if ( 'false' === $value || '0' === $value ) {
										$value = '';
									}
									$ajax_data['menu'][ $group ][ $option ] = empty( $value ) ? false : true;
								}
							}
						}
					}

					$this->settings( $preset_id )->update( $ajax_data['menu'] );

					// Answer by default.
					$respond = esc_html__( 'Save', 'groovy-menu' );
					$sub_action = isset( $_POST['sub_action'] ) ? sanitize_key( wp_unslash( $_POST['sub_action'] ) ) : '';
					if ( ! empty( $sub_action ) ) {
						switch ( $sub_action ) {
							case 'save':
								$respond = esc_html__( 'Save', 'groovy-menu' );
								break;
							case 'restore':
								$respond = esc_html__( 'Restore', 'groovy-menu' );
								break;
						}
					}

					if ( function_exists( 'groovy_menu_check_gfonts_params' ) ) {
						groovy_menu_check_gfonts_params();
					}

					// Send a JSON response back to an AJAX request, and die().
					wp_send_json_success( $respond );

				} else {
					// Send a JSON response back to an AJAX request, and die().
					wp_send_json_error( esc_html__( 'Error. Wrong form data for save', 'groovy-menu' ) );
				}
			}
		}


		/**
		 * Save style of preset
		 *
		 * @param bool $check_ver
		 */
		function saveStyles( $check_ver = false ) {
			$cap_can = true;

			if ( ! isset( $_POST['gm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gm_nonce'] ) ), 'gm_nonce_preset_save' ) ) {
				$cap_can = false;
			}
			if ( ! GroovyMenuRoleCapabilities::presetEdit( true ) ) {
				$cap_can = false;
			}

			$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
			if ( $cap_can && defined( 'DOING_AJAX' ) && DOING_AJAX && ! empty( $_POST ) && 'gm_save_styles' === $action ) {

				$ajax_data  = ( isset( $_POST['data'] ) && is_string( $_POST['data'] ) ) ? sanitize_textarea_field( wp_unslash( $_POST['data'] ) ) : '';
				$direction  = ( isset( $_POST['direction'] ) && is_string( $_POST['direction'] ) ) ? sanitize_key( wp_unslash( $_POST['direction'] ) ) : '';
				$preset_id  = empty( $_POST['preset_id'] ) ? 0 : absint( wp_unslash( $_POST['preset_id'] ) );
				$gm_version = ( isset( $_POST['gm_version'] ) && is_string( $_POST['gm_version'] ) ) ? sanitize_text_field( wp_unslash( $_POST['gm_version'] ) ) : '';

				$direction_postfix = ( 'rtl' === $direction ) ? '_rtl' : '';

				if ( empty( $ajax_data ) ) {
					// Send a JSON response back to an AJAX request, and die().
					wp_send_json_error( esc_html__( 'Error. Bad style data', 'groovy-menu' ) );
				}

				if ( empty( $direction ) ) {
					// Send a JSON response back to an AJAX request, and die().
					wp_send_json_error( esc_html__( 'Error. Missing direction of the current menu', 'groovy-menu' ) );
				}

				if ( empty( $preset_id ) ) {
					// Send a JSON response back to an AJAX request, and die().
					wp_send_json_error( esc_html__( 'Error. Missing id of the current menu', 'groovy-menu' ) );
				}

				if ( empty( $gm_version ) ) {
					// Send a JSON response back to an AJAX request, and die().
					wp_send_json_error( esc_html__( 'Error. Missing Groovy Menu version parameter', 'groovy-menu' ) );
				}

				if ( $gm_version !== GROOVY_MENU_VERSION ) {
					// Send a JSON response back to an AJAX request, and die().
					wp_send_json_error( esc_html__( 'Error. Outdated Groovy Menu version parameter', 'groovy-menu' ) );
				}

				if ( $check_ver ) {
					$saved_gm_version      = get_post_meta( intval( $preset_id ), 'gm_version' . $direction_postfix, true );
					$saved_gm_compiled_css = get_post_meta( intval( $preset_id ), 'gm_compiled_css' . $direction_postfix, true );

					if ( $saved_gm_version === GROOVY_MENU_VERSION && ! empty( $saved_gm_compiled_css ) ) {
						// Send a JSON response back to an AJAX request, and die().
						wp_send_json_error( esc_html__( 'Error. Groovy Menu preset style already saved', 'groovy-menu' ) );
					}
				}

				$preset_key = md5( rand() . uniqid() . time() );

				update_post_meta( intval( $preset_id ), 'gm_compiled_css' . $direction_postfix, $ajax_data );
				update_post_meta( intval( $preset_id ), 'gm_preset_key', $preset_key );
				update_post_meta( intval( $preset_id ), 'gm_version' . $direction_postfix, GROOVY_MENU_VERSION );

				// Save compiled_css to file
				$this->save_compiled_css( $preset_id, $ajax_data, $direction );

				$respond = esc_html__( 'Save', 'groovy-menu' );
				$sub_action = isset( $_POST['sub_action'] ) ? sanitize_key( wp_unslash( $_POST['sub_action'] ) ) : '';
				if ( ! empty( $sub_action ) ) {
					switch ( $sub_action ) {
						case 'save':
							$respond = esc_html__( 'Saved', 'groovy-menu' );
							break;
						case 'restore':
							$respond = esc_html__( 'Current section restored to default', 'groovy-menu' );
							break;
						case 'restore_all':
							$respond = esc_html__( 'All Settings restored to default', 'groovy-menu' );
							break;
					}
				}

				// Send a JSON response back to an AJAX request, and die().
				wp_send_json_success( $respond );

			}
		}


		function saveStylesNoPriv() {
			$this->saveStyles( true );
		}


		/**
		 * Save preset compiled style to the file
		 *
		 * @param string|integer $preset_id    preset id.
		 * @param string         $compiled_css styles.
		 * @param string         $direction    wait 'rtl'
		 *
		 * @return bool
		 */
		private function save_compiled_css( $preset_id, $compiled_css = '', $direction = '' ) {


			$preset_id = intval( $preset_id );

			if ( empty( $preset_id ) ) {
				// if err.
				return false;
			}

			if ( empty( $compiled_css ) ) {
				$compiled_css = '';
			}

			if ( 'rtl' === $direction ) {
				$direction = '_rtl';
			} else {
				$direction = '';
			}

			if ( ! defined( 'FS_METHOD' ) ) {
				define( 'FS_METHOD', 'direct' );
			}

			global $wp_filesystem;
			if ( empty( $wp_filesystem ) ) {
				$file_path = str_replace( array(
					'\\',
					'/'
				), DIRECTORY_SEPARATOR, ABSPATH . '/wp-admin/includes/file.php' );

				if ( file_exists( $file_path ) ) {
					require_once $file_path;
					WP_Filesystem();
				}
			}
			if ( empty( $wp_filesystem ) ) {
				// if err.
				return false;
			}

			$styles_dir = GroovyMenuUtils::getUploadDir();
			if ( ! is_dir( $styles_dir ) ) {
				@mkdir( $styles_dir, 0755 );
			}

			$css_filename = 'preset_' . esc_attr( strval( $preset_id ) ) . $direction . '.css';

			$handled_compiled_css = trim( stripcslashes( $compiled_css ) );

			$wp_filesystem->put_contents( $styles_dir . $css_filename, $handled_compiled_css, FS_CHMOD_FILE );
		}


		public function saveAutoIntegration() {

			if ( ! isset( $_POST['gm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gm_nonce'] ) ), 'gm_nonce_auto_integration' ) ) {
				$respond = esc_html__( 'Fail. Nonce field outdated. Try reload page.', 'groovy-menu' );
				// Send a JSON response back to an AJAX request, and die().
				wp_send_json_error( $respond );
			}

			if ( ! GroovyMenuRoleCapabilities::globalOptions( true ) ) {
				return;
			}

			$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
			if ( defined( 'DOING_AJAX' ) && DOING_AJAX && ! empty( $_POST ) && 'gm_save_auto_integration' === $action ) {

				$posted_data = ( isset( $_POST['data'] ) && is_scalar( $_POST['data'] ) ) ? sanitize_text_field( wp_unslash( (string) $_POST['data'] ) ) : '';
				$ajax_data   = ( '' === $posted_data || 'false' === $posted_data ) ? false : true;

				global $gm_supported_module;
				$theme_name = empty( $gm_supported_module['theme'] ) ? wp_get_theme()->get_template() : $gm_supported_module['theme'];

				// Save automatic integrations settings
				update_option( GroovyMenuUtils::getAutoIntegrationOptionName() . $theme_name, $ajax_data, true );

				$respond = esc_html__( 'Save', 'groovy-menu' );

				// Send a JSON response back to an AJAX request, and die().
				wp_send_json_success( $respond );

			}
		}

		public function saveSingleLocationIntegration() {
			if ( ! isset( $_POST['gm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gm_nonce'] ) ), 'gm_nonce_auto_integration' ) ) {
				$respond = esc_html__( 'Fail. Nonce field outdated. Try reload page.', 'groovy-menu' );
				wp_send_json_error( $respond );
			}

			if ( ! GroovyMenuRoleCapabilities::globalOptions( true ) ) {
				return;
			}

			$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
			if ( defined( 'DOING_AJAX' ) && DOING_AJAX && ! empty( $_POST ) && 'gm_save_single_location_integration' === $action ) {

				$ajax_data = ( isset( $_POST['data'] ) && is_string( $_POST['data'] ) ) ? sanitize_key( wp_unslash( $_POST['data'] ) ) : '';

				global $gm_supported_module;
				$theme_name = empty( $gm_supported_module['theme'] ) ? wp_get_theme()->get_template() : $gm_supported_module['theme'];

				$saved_config = get_option( GroovyMenuUtils::getIntegrationConfigOptionName() . $theme_name );

				if ( empty( $saved_config ) || ! is_array( $saved_config ) ) {
					$saved_config = array(
						'single_location' => '',
					);
				}

				$saved_config['single_location'] = esc_sql( $ajax_data );

				// Save automatic integrations settings
				$saved = update_option( GroovyMenuUtils::getIntegrationConfigOptionName() . $theme_name, $saved_config, true );

				if ( $saved ) {
					$respond = esc_html__( 'Save', 'groovy-menu' );
				} else {
					$respond = esc_html__( 'Save Error', 'groovy-menu' );
				}

				// Send a JSON response back to an AJAX request, and die().
				wp_send_json_success( $respond );

			}
		}

		public function checkCurrentLicense() {

			// By default.
			$respond = 'none';

			$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
			if ( defined( 'DOING_AJAX' ) && DOING_AJAX && ! empty( $_POST ) && 'gm_check_current_license' === $action ) {

				$lic_opt = GroovyMenuUtils::check_lic( true );

				if ( $lic_opt ) {
					$respond = 'true';
				} else {
					$respond = 'false';
				}

			}

			// Send a JSON response back to an AJAX request, and die().
			wp_send_json_success( $respond );
		}

		public function reloadCurrentLicense() {

			// By default.
			$respond = 'none';

			$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
			if ( defined( 'DOING_AJAX' ) && DOING_AJAX && ! empty( $_POST ) && 'gm_reload_current_license' === $action ) {

				$lic_opt = GroovyMenuUtils::check_lic( true, true );

				if ( $lic_opt ) {
					$respond = 'true';
				} else {
					$respond = 'false';
				}

			}

			// Send a JSON response back to an AJAX request, and die().
			wp_send_json_success( $respond );
		}

		/**
		 * Function return Google fonts
		 *
		 * @return void
		 */
		public function getGoogleFonts() {

			$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
			if ( defined( 'DOING_AJAX' ) && DOING_AJAX && ! empty( $_POST ) && 'gm_get_google_fonts' === $action ) {

				$googleFonts = include GROOVY_MENU_DIR . 'includes' . DIRECTORY_SEPARATOR . 'fonts-google.php';

				wp_send_json_success( $googleFonts );

			}

		}

		public function export() {
			$export_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
			$export_requested = isset( $_GET['export'] ) ? sanitize_key( wp_unslash( $_GET['export'] ) ) : '';
			if ( ! isset( $_GET['gm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['gm_nonce'] ) ), 'gm_nonce_editor' ) ) {
				return;
			}

			if ( GroovyMenuRoleCapabilities::canExport( true ) && ! empty( $export_requested ) && ! empty( $export_id ) ) {

				$export = array();

				$export['settings'] = $this->settings( $export_id )->getSettingsArray( true );
				$export['name']     = $this->settings( $export_id )->getPreset()->getName();
				//$export['img']    = GroovyMenuPreset::getPreviewById( $this->settings()->getPreset()->getId() );
				$export['name'] = empty( $export['name'] ) ? 'groovy menu preset' : $export['name'];
				$preset_name    = str_replace( ' ', '-', $export['name'] );
				$filename       = 'groovy-menu-preset-[' . $preset_name . '].json';

				if ( function_exists( 'mb_ereg_replace' ) ) {
					if ( function_exists( 'mb_internal_encoding' ) ) {
						mb_internal_encoding( "UTF-8" );
					}
					if ( function_exists( 'mb_regex_encoding' ) ) {
						mb_regex_encoding( "UTF-8" );
					}

					// Remove anything which isn't a word, whitespace, number and some symbols
					$filename = mb_ereg_replace( "([^\w\s\d\-_\#~,;\[\]\(\).])", '', $filename );
				} else {
					// Remove anything which isn't a word, whitespace, number
					$filename = preg_replace( "#([^\w\s\d\-_\#~,;\[\]\(\).])#", '', $filename );
					// Remove any runs of periods
					$filename = preg_replace( "#([\.]{2,})#", '', $filename );
				}

				if ( ! headers_sent() ) {
					$this->cleanOutputBuffer();

					header( 'Content-Type: text/json' );
					header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

					echo wp_json_encode( $export, JSON_PRETTY_PRINT );
					exit;
				} else { // Fallback
					echo '<h1>';
					echo esc_html__( 'The error of creating an export file!', 'groovy-menu' );
					echo '</h1><h2>';
					echo esc_html__( 'Below is the contents of the text from the file. Copy the code and save it as a text file.', 'groovy-menu' );
					echo '</h2>';
					echo '<p>' . esc_html__( 'Suggested file name', 'groovy-menu' ) . ': <code>' . esc_html( $filename ) . '</code></p>';
					echo '<textarea cols="80" rows="24" autofocus>';
					echo esc_textarea( wp_json_encode( $export, JSON_PRETTY_PRINT ) );
					echo '</textarea>';
					exit;
				}
			}
		}

		/**
		 * @param $settings
		 */
		protected function renderTabsGlobal( $settings ) {
			$first = true;
			echo '<div class="groovy-tabs">';
			foreach ( $settings as $categoryName => $category ) {
				$category_title = isset( $category['title'] ) ? $category['title'] : $categoryName;
				echo '<a href="#" data-tab="' . esc_attr( $categoryName ) . '" class="groovy-tab ' . ( $first ? 'groovy-tab-active' : '' ) . '">' . esc_html( $category_title ) . '</a>';
				$first = false;
			}
			echo '</div>';
		}

		/**
		 * @param $category
		 * @param $categoryName
		 * @param $active
		 */
		protected function renderTabGlobal( $category, $categoryName, $active ) {
			echo '<div class="groovy-tab-pane ' . ( $active ? 'groovy-tab-pane-active' : '' ) . '" id="groovy-tab-' . esc_attr( $categoryName ) . '">';
			foreach ( $category['fields'] as $name => $field ) {
				$this->renderField( $categoryName, $name, $field );
			}
			echo '</div>';
		}

		/**
		 * @return array
		 */
		protected function getPresetsFromApi() {
			$styles        = new GroovyMenuStyle();
			$allow_library = $styles->getGlobal( 'tools', 'allow_import_online_library' ) ? : false;
			if ( ! $allow_library ) {
				return [];
			}

			$transient_name     = 'groovy_menu_presets_from_api';
			$saved_api_response = get_transient( $transient_name );
			if ( false !== $saved_api_response && is_array( $saved_api_response ) ) {

				return $saved_api_response;
			}

			$remote_content_obj = wp_remote_get( 'https://api.groovy.grooni.com/preset/list.json', array( 'timeout' => 6 ) );
			if ( wp_remote_retrieve_response_code( $remote_content_obj ) === 200 ) {
				$response = json_decode( wp_remote_retrieve_body( $remote_content_obj ), true );
			}

			if ( empty( $response ) || empty( $response['presets'] ) || ! is_array( $response['presets'] ) ) {
				return [];
			}

			set_transient( $transient_name, $response['presets'], DAY_IN_SECONDS );

			return $response['presets'];
		}


		/**
		 * @param $id
		 *
		 * @return array|mixed
		 */
		protected function getPresetsFromApiById( $id ) {
			$presets = $this->getPresetsFromApi();
			$preset  = array();

			$id = $id ? intval( $id ) : false;

			foreach ( $presets as $_preset ) {
				if ( intval( $_preset['id'] ) === intval( $id ) ) {
					$preset = $_preset;
				}
			}

			return $preset;
		}

		/**
		 * @param $url
		 *
		 * @return array
		 */
		protected function getDataFromApi( $url ) {

			$remote_content_obj = wp_remote_get( $url, array( 'timeout' => 8 ) );

			if ( wp_remote_retrieve_response_code( $remote_content_obj ) === 200 ) {
				$response = wp_remote_retrieve_body( $remote_content_obj );
			}

			if ( ! empty( $response ) ) {
				$response = json_decode( $response, true );
			}

			if ( ! empty( $response ) && is_array( $response ) ) {
				return $response;
			}

			return array();
		}


		public function getPresetDataFromApiById( $id ) {
			$preset = $this->getPresetsFromApiById( $id );
			if ( ! empty( $preset['url'] ) ) {
				$data = $this->getDataFromApi( $preset['url'] );
			}
			if ( ! empty( $data ) && is_array( $data ) ) {
				return $data;
			}

			return array();
		}

		public function search_filter( $query ) {
			if ( $query->is_search && ! is_admin() ) {
				if ( ! empty( $_GET['post_type'] ) ) {

					$all_post_types = GroovyMenuUtils::getPostTypes( true );
					$post_type      = trim( esc_attr( sanitize_text_field( wp_unslash( $_GET['post_type'] ) ) ) );

					switch ( $post_type ) {

						case 'post':
							$query->set( 'post_type', array( 'post' ) );
							break;

						case 'page':
							$query->set( 'post_type', array( 'page' ) );
							break;

						case 'shop':
						case 'product':
							if ( isset( $all_post_types['product'] ) ) {
								$query->set( 'post_type', array( 'product' ) );
							}
							break;

						case '':
							break;

						default:
							if ( isset( $all_post_types[ $post_type ] ) ) {
								$query->set( 'post_type', array( $post_type ) );
							}
							break;

					}
				}
			}

			return $query;
		}

		public function checkMenuBlockRequirements() {

			if ( defined( 'VCV_VERSION' ) && defined( 'VCV_PREFIX' ) ) {
				if ( function_exists( 'groovy_menu_visual_composer_builder_support' ) ) {
					groovy_menu_visual_composer_builder_support();
				}
			}

		}

	}

}
