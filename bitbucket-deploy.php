<?php

	/**
	 * Plugin Name: Bitbucket Deploy
	 * Description: Creates a connection between WordPress and Bitbucket to allow streamlined deployment for plugins and themes being managed by private .git repositories.
	 * Version: 1.0.0
	 * Author: MLK.DEV
	 * Author URI: https://mlk.dev/
	 */

	// Deny direct access...
	if( !defined( 'ABSPATH' ) ) exit;

	// Prevent redefinition...
	if( !class_exists( 'BitbucketDeploy' ) ) {

		class BitbucketDeploy {

			private static $instance = null;

			private $username;
			private $password;

			public $plugin_registry;
			public $theme_registry;

			private function __construct() {

				add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );

				add_filter( 'http_request_args',         [ $this, 'http_request_args' ],         10, 2 );
				add_filter( 'upgrader_source_selection', [ $this, 'upgrader_source_selection' ], 10, 4 );
				add_filter( 'upgrader_post_install',     [ $this, 'upgrader_post_install' ],     10, 3 );
				add_filter( 'plugins_api',               [ $this, 'plugins_api' ],               10, 3 );

				add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'pre_set_site_transient_update_plugins' ], 11, 1 );
				add_filter( 'pre_set_site_transient_update_themes',  [ $this, 'pre_set_site_transient_update_themes'  ], 11, 1 );

				// Fix the WordPress flush/singletons race-case...
				remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );
				add_action( 'shutdown', function() {
					while ( @ob_end_flush() );
				} );

			}

			private function refresh_credentials() {

				$options = get_option( 'BitbucketDeploy_Options' );

				if( !empty( $options[ 'username' ] ) ) {
					$this->username = $options[ 'username' ];
				}

				if( !empty( $options[ 'password' ] ) ) {
					$this->password = $options[ 'password' ];
				}

			}

			private function refresh_plugin_registry() {

				// Are basic auth values missing?
				$this->refresh_credentials();
				if( empty( $this->username ) || empty( $this->password ) ) return;

				// Do we have a hydrated registry?
				if( empty( $this->plugin_registry ) ) return;

				// Loop through plugins...
				foreach( $this->plugin_registry as $i => $plugin ) {

					// Generate the plugin's name and metadata...
					if( !empty( $plugin[ 'file' ] ) ) {
						$this->plugin_registry[ $i ][ 'name' ] = implode( '/', array_slice( explode( '/', $plugin[ 'file' ] ), -2 ) );
						$this->plugin_registry[ $i ][ 'metadata' ] = get_file_data( $plugin[ 'file' ], [
							'Plugin Name' => 'Plugin Name',
							'Version'     => 'Version',
							'Description' => 'Description',
						] );
					}

					// Retrieve tags from Bitbucket API...
					if( empty( $plugin[ 'latest_version' ] ) ) {
						$response = json_decode( wp_remote_retrieve_body( wp_remote_get(
							'https://api.bitbucket.org/2.0/repositories/'.$plugin[ 'repository' ].'/refs/tags?sort=-name',
							[ 'headers' => [ 'Authorization' => 'Basic '.base64_encode( $this->username.':'.$this->password ) ] ]
						) ), true );
						if( !empty( $response[ 'values' ][ 0 ][ 'name' ] ) ) {
							$this->plugin_registry[ $i ][ 'latest_version' ] = $response[ 'values' ][ 0 ][ 'name' ];
						}
						if( !empty( $response[ 'values' ][ 0 ][ 'date' ] ) ) {
							$this->plugin_registry[ $i ][ 'latest_date' ] = $response[ 'values' ][ 0 ][ 'date' ];
						}
						if( !empty( $response[ 'values' ][ 0 ][ 'message' ] ) ) {
							$this->plugin_registry[ $i ][ 'latest_message' ] = $response[ 'values' ][ 0 ][ 'message' ];
						}
					}

					// Generate the package URL from Bitbucket...
					if( !empty( $plugin[ 'latest_version' ] ) ) {
						$this->plugin_registry[ $i ][ 'latest_package' ] = 'https://bitbucket.org/'.$plugin[ 'repository' ].'/get/'.$plugin[ 'latest_version' ].'.zip';
					}

				}

			}

			private function refresh_theme_registry() {

				// Are basic auth values missing?
				$this->refresh_credentials();
				if( empty( $this->username ) || empty( $this->password ) ) return;

				// Loop through themes...
				if( !empty( $this->theme_registry ) ) {
					foreach( $this->theme_registry as &$theme ) {

						// Generate the theme's name and metadata...
						if( !empty( $theme[ 'file' ] ) ) {
							$theme[ 'name' ] = current( array_slice( explode( '/', $theme[ 'file' ] ), -2 ) );
							$theme[ 'metadata' ] = get_file_data( $theme[ 'file' ], [
								'Theme Name' => 'Theme Name',
								'Version'     => 'Version',
								'Description' => 'Description',
							] );
						}

						// Retrieve tags from Bitbucket API...
						if( empty( $theme[ 'latest_version' ] ) ) {
							$response = json_decode( wp_remote_retrieve_body( wp_remote_get(
								'https://api.bitbucket.org/2.0/repositories/'.$theme[ 'repository' ].'/refs/tags?sort=-name',
								[ 'headers' => [ 'Authorization' => 'Basic '.base64_encode( $this->username.':'.$this->password ) ] ]
							) ), true );
							if( !empty( $response[ 'values' ][ 0 ][ 'name' ] ) ) {
								$theme[ 'latest_version' ] = $response[ 'values' ][ 0 ][ 'name' ];
							}
							if( !empty( $response[ 'values' ][ 0 ][ 'date' ] ) ) {
								$theme[ 'latest_date' ] = $response[ 'values' ][ 0 ][ 'date' ];
							}
							if( !empty( $response[ 'values' ][ 0 ][ 'message' ] ) ) {
								$theme[ 'latest_message' ] = $response[ 'values' ][ 0 ][ 'message' ];
							}
						}

						// Generate the package URL from Bitbucket...
						if( !empty( $theme[ 'latest_version' ] ) ) {
							$theme[ 'latest_package' ] = 'https://bitbucket.org/'.$theme[ 'repository' ].'/get/'.$theme[ 'latest_version' ].'.zip';
						}

					}
				}

			}

			private function refresh_registry() {

				$this->refresh_plugin_registry();
				$this->refresh_theme_registry();

			}

			private function get_commit_digest() {

				// Start empty...
				$html = [];

				// Retrieve the commits from the current tag...
				$response = wp_remote_get( 'https://api.bitbucket.org/2.0/repositories/'.$this->repository.'/commits/'.$this->response[ 'values' ][ 0 ][ 'name' ], [
					'headers' => [ 'Authorization' => 'Basic '.base64_encode( $this->username.':'.$this->password ) ]
				] );
				$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

				// Parse each value as a commit...
				if( !empty( $decoded[ 'values' ] ) ) {
					foreach( $decoded[ 'values' ] as $i => $commit ) {

						// Initialize a day to chunk commits by...
						$date_key = date( 'Y-m-d', strtotime( $commit[ 'date' ] ) );
						if( empty( $html[ $date_key ] ) ) $html[ $date_key ] = null;

						// Add the commit...
						$html[ $date_key ] .= null
						.'<li>'
							.'<a href="https://bitbucket.org/'.$this->repository.'/commits/'.$commit[ 'hash' ].'" target="_blank" rel="noopener">'
								.'<code>'.substr( $commit[ 'hash' ], 0, 7 ).'</code>'
							.'</a>'
							.'<span>&nbsp;&rarr;&nbsp;</span>'.strip_tags( $commit[ 'rendered' ][ 'message' ][ 'html' ] )
						.'</li>';

					}
				}

				// Format the chunks...
				if( !empty( $html ) ) {
					foreach( $html as $date_key => $chunk ) {
						$html[ $date_key ] = null
						.'<div>'
							.'<h1>'.$date_key.'</h1>'
							.'<ul>'.$chunk.'</ul>'
						.'</div>';
					}
				}

				// Return filled...
				return implode( '', $html );

			}

			public function rest_api_init() {

				// Register the REST API route and endpoint:
				// Example: my-domain.com/wp-json/bitbucket-deploy/v1/registry

				register_rest_route( 'bitbucket-deploy/v1', 'registry', [
					'methods'  => WP_REST_Server::READABLE,
					'callback' => function() {

						// Return the current registry state...
						return rest_ensure_response( [
							'themes'  => $this->theme_registry,
							'plugins' => $this->plugin_registry,
						] );

					},
					'permission_callback' => function() {

						// Allow-list approach...
						if( current_user_can( 'manage_options' ) ) return true;

						// Deny all else...
						return new WP_Error( 'rest_forbidden', 'Access denied.', [ 'status' => 401 ] );

					}
				] );

			}

			public function register_plugin( $file, $repository ) {

				if( !is_array( $this->plugin_registry ) ) {
					$this->plugin_registry = [];
				}
				array_push( $this->plugin_registry, [
					'file'       => $file,
					'repository' => $repository,
				] );
				$this->refresh_plugin_registry();

			}

			public function register_theme( $file, $repository ) {

				if( !is_array( $this->theme_registry ) ) {
					$this->theme_registry = [];
				}
				array_push( $this->theme_registry, [
					'file'       => $file,
					'repository' => $repository,
				] );
				$this->refresh_theme_registry();

			}

			public function pre_set_site_transient_update_plugins( $transient ) {

				// Check if transient has a checked property...
				if( !property_exists( $transient, 'checked') ) return $transient;

				// Check each plugin...
				if( !empty( $this->plugin_registry ) ) {
					foreach( $this->plugin_registry as $plugin ) {

						// Skip if we don't know the name yet...
						if( empty( $plugin[ 'name' ] ) ) continue;

						// Skip if we don't know the latest version...
						if( empty( $plugin[ 'latest_version' ] ) ) continue;
						if( empty( $transient->checked[ $plugin[ 'name' ] ] ) ) continue;

						// Skip if the latest version isn't new...
						if( !version_compare( $plugin[ 'latest_version' ], $transient->checked[ $plugin[ 'name' ] ], 'gt' ) ) continue;

						// Update the transient response...
						$transient->response[ $plugin[ 'name' ] ] = (object)[
							'id'          => 'bitbucket.org/'.$plugin[ 'repository' ],
							'slug'        => current( explode( '/', $plugin[ 'name' ] ) ),
							'plugin'      => $plugin[ 'name' ],
							'new_version' => $plugin[ 'latest_version' ],
							'url'         => 'https//bitbucket.org/'.$plugin[ 'repository' ],
							'package'     => $plugin[ 'latest_package' ],
						];

					}
				}

				return $transient;

			}

			public function pre_set_site_transient_update_themes( $transient ) {

				// Check if transient has a checked property...
				if( !property_exists( $transient, 'checked') ) return $transient;

				// Check each theme...
				if( !empty( $this->theme_registry ) ) {
					foreach( $this->theme_registry as $theme ) {

						// Skip if we don't know the name yet...
						if( empty( $theme[ 'name' ] ) ) continue;

						// Skip if we don't know the latest version...
						if( empty( $theme[ 'latest_version' ] ) ) continue;
						if( empty( $transient->checked[ $theme[ 'name' ] ] ) ) continue;

						// Skip if the latest version isn't new...
						if( !version_compare( $theme[ 'latest_version' ], $transient->checked[ $theme[ 'name' ] ], 'gt' ) ) continue;

						$data = wp_get_theme( $theme[ 'name' ] );

						// Update the transient response...
						$transient->response[ $theme[ 'name' ] ] = [
							'theme'        => $theme[ 'name' ],
							'new_version'  => $theme[ 'latest_version' ],
							'url'          => $data->get( 'ThemeURI' ),
							'package'      => $theme[ 'latest_package' ],
							'requires'     => $data->get( 'RequiresWP' ),
							'requires_php' => $data->get( 'RequiresPHP' ),
						];

					}
				}

				return $transient;

			}

			public function plugins_api( $result, $action, $args ) {

				// Only affect the 'plugin_information' action...
				if( empty( $action ) || $action != 'plugin_information' ) return $result;

				// Check for the presence of a slug...
				if( empty( $args->slug ) ) return $result;

				// Check each plugin...
				$this->refresh_plugin_registry();
				if( !empty( $this->plugin_registry ) ) {
					foreach( $this->plugin_registry as $plugin ) {

						// Not the right plugin, skip ahead...
						$plugin_slug = current( explode( '/', $plugin[ 'name' ] ) );
						if( $plugin_slug != $args->slug ) continue;

						// Retrieve all the plugin information...
						$plugin_data = get_plugin_data( $plugin[ 'file' ] );

						return (object)[
							'slug'              => $plugin_slug,
							'name'              => $plugin_data[ 'Name' ],
							'author'            => $plugin_data[ 'AuthorName' ],
							'author_profile'    => $plugin_data[ 'AuthorURI' ],
							'homepage'          => $plugin_data[ 'PluginURI' ],
							'short_description' => $plugin_data[ 'Description' ],
							'download_link'     => $plugin[ 'latest_package' ],
							'version'           => $plugin[ 'latest_version' ],
							'last_updated'      => $plugin[ 'latest_date' ],
							'sections'          => [
								'Description' => $plugin_data[ 'Description' ],
								'Updates'     => nl2br( !empty( $plugin[ 'latest_message' ] ) ? $plugin[ 'latest_message' ] : '' ),
							],
						];

					}
				}

				return $result;

			}

			public function upgrader_source_selection( $source, $remote_source, $upgrader, $hook_extra = [] ) {

				global $wp_filesystem;

				// Is all the required info available?
				if( !property_exists( $upgrader, 'skin' ) ) return $source;

				// Is the source selecting a plugin?
				if( property_exists( $upgrader->skin, 'plugin_info' ) ) {

					// If we don't have any plugins in the registry...
					if( empty( $this->plugin_registry ) ) return $source;

					// Check the registry...
					foreach( $this->plugin_registry as $i => $plugin ) {

						// Skip ahead if the plugin isn't in the registry...
						if( $plugin[ 'metadata' ][ 'Plugin Name' ] != $upgrader->skin->plugin_info[ 'Name' ] ) continue;

						// Determine what the new source directory should be named...
						$new_source = $remote_source.'/'.current( explode( '/', $plugin[ 'name' ] ) ).'/';

						// Move the files...
						if( $wp_filesystem->move( $source, $new_source, true ) ) return $new_source;

						// Error out...
						return new WP_Error(
							'bitbucket-deploy-source-rename-failure',
							'Could not move upgrade package contents into destination directory.'
						);

					}

				}

				// Is the source selecting a theme?
				if( property_exists( $upgrader->skin, 'theme_info' ) ) {

					// If we don't have any themes in the registry...
					if( empty( $this->theme_registry ) ) return $source;

					// Check the registry...
					foreach( $this->theme_registry as $i => $theme ) {

						// Skip ahead if the theme isn't in the registry...
						if( $theme[ 'name' ] != $upgrader->skin->theme_info->get_stylesheet() ) continue;

						// Determine what the new source directory should be named...
						$new_source = $remote_source.'/'.$theme[ 'name' ].'/';

						// Move the files...
						if( $wp_filesystem->move( $source, $new_source, true ) ) return $new_source;

						// Error out...
						return new WP_Error(
							'bitbucket-deploy-source-rename-failure',
							'Could not move upgrade package contents into destination directory.'
						);

					}

				}

				return $source;

			}

			public function upgrader_post_install( $response, $hook_extra, $result ) {

				// Get the WordPress filesystem global...
				global $wp_filesystem;

				// Should we check plugins?
				if( !empty( $hook_extra[ 'plugin' ] ) && !empty( $this->plugin_registry ) ) {
					foreach( $this->plugin_registry as $plugin ) {

						// Skip it if its not in our registry...
						if( $hook_extra[ 'plugin' ] != $plugin[ 'name' ] ) continue;

						// Retrieve the plugin name...
						$plugin_name = current( explode( '/', $plugin[ 'name' ] ) );

						// Determine where the target directory resides...
						$target_dir = WP_CONTENT_DIR.'/plugins/'.$plugin_name;

						// Move the new files into place...
						$wp_filesystem->move( $result[ 'destination' ], $target_dir );
						$result[ 'destination' ] = $target_dir;

						// Activate the plugin...
						activate_plugin( $plugin[ 'file' ] );

					}
				}

				// Should we check themes?
				if( !empty( $hook_extra[ 'theme' ] ) && !empty( $this->theme_registry ) ) {
					foreach( $this->theme_registry as $theme ) {

						// Skip it if its not in our registry...
						if( $hook_extra[ 'theme' ] != $theme[ 'name' ] ) continue;

						// Retrieve the plugin name...
						$theme_name = $theme[ 'name' ];
						$theme_temp = $result[ 'destination_name' ];

						// Determine where the target directory resides...
						$target_dir = WP_CONTENT_DIR.'/themes/'.$theme_name.'/';

						// Move the new files into place...
						$wp_filesystem->move( $result[ 'destination' ], $target_dir, true );
						$result[ 'destination' ] = $target_dir;
						$result[ 'destination_name' ] = $theme_name;
						// $result[ 'remote_destination' ] = WP_CONTENT_DIR.'/themes/'.$theme_name.'/';

						// Rename the installed directory...
						rename( $result[ 'destination' ], $target_dir );

						// Activate the theme...
						switch_theme( $hook_extra[ 'theme' ] );

					}

				}

				return $response;

			}

			public function http_request_args( $r, $url ) {

				// Gather together all the latest package URLs in the registry...
				$latest_packages = [];
				if( !empty( $this->plugin_registry ) ) {
					foreach( $this->plugin_registry as $plugin ) {
						if( empty( $plugin[ 'latest_package' ] ) ) continue;
						$latest_packages[] = $plugin[ 'latest_package' ];
					}
				}
				if( !empty( $this->theme_registry ) ) {
					foreach( $this->theme_registry as $theme ) {
						if( empty( $theme[ 'latest_package' ] ) ) continue;
						$latest_packages[] = $theme[ 'latest_package' ];
					}
				}

				// Check if the requested URL is for one of the latest packages...
				if( in_array( $url, $latest_packages ) ) {

					$this->refresh_credentials();
					$r[ 'headers' ] = [ 'Authorization' => 'Basic '.base64_encode( $this->username.':'.$this->password ) ];

				}

				return $r;

			}

			public function flush_transients() {

				delete_site_transient( 'update_plugins' );
				delete_site_transient( 'update_themes' );

			}

			public static function instance() {

				if( null === self::$instance ) {
					self::$instance = new self();
				}
				return self::$instance;

			}

		}

	}

	function BitbucketDeploy_AdminInit() {

		// Register a group of settings...
		register_setting( 'bitbucket-deploy', 'BitbucketDeploy_Options' );

		// Create a section...
		add_settings_section(
			'BitbucketDeploy_BitbucketCredentials',
			'Bitbucket Credentials',
			function() {

				echo '<p>Please configure your Bitbucket application credentials here.</p>';

			},
			'bitbucket-deploy'
		);

		// Output the form field, prefilled with current value...
		add_settings_field(
			'BitbucketDeploy_BitbucketCredentials_Username',
			'Account Username',
			function( $args ) {

				$options = get_option( 'BitbucketDeploy_Options' );

				$attributes = [
					'name'  => 'BitbucketDeploy_Options[username]',
					'type'  => 'text',
					'value' => esc_attr( empty( $options[ 'username' ] ) ? null : $options[ 'username' ] ),
				];
				array_walk( $attributes, function( &$item, $key ) {
					$item = $key.'="'.$item.'"';
				} );

				echo '<input '.implode( ' ', $attributes ).' />';

			},
			'bitbucket-deploy',
			'BitbucketDeploy_BitbucketCredentials'
		);

		// Output the form field, prefilled with current value...
		add_settings_field(
			'BitbucketDeploy_BitbucketCredentials_Password',
			'Application Password',
			function( $args ) {

				$options = get_option( 'BitbucketDeploy_Options' );

				$attributes = [
					'name'  => 'BitbucketDeploy_Options[password]',
					'type'  => 'text',
					'value' => esc_attr( empty( $options[ 'password' ] ) ? null : $options[ 'password' ] ),
				];
				array_walk( $attributes, function( &$item, $key ) {
					$item = $key.'="'.$item.'"';
				} );

				echo '<input '.implode( ' ', $attributes ).' />';

			},
			'bitbucket-deploy',
			'BitbucketDeploy_BitbucketCredentials'
		);

		// Create a section...
		add_settings_section(
			'BitbucketDeploy_Plugins',
			'Plugins',
			function() {

				echo '<p>Please configure your Plugins here. Any fields left blank will be excluded from the Bitbucket Deploy service routines.</p>';

			},
			'bitbucket-deploy'
		);

		// Plugin Config Fields...
		add_settings_field(
			'BitbucketDeploy_ThemesJSON',
			'Config',
			function( $args ) {

				$options = get_option( 'BitbucketDeploy_Options' );
				foreach( get_plugins() as $key => $plugin ) {

					$option_key = 'plugin_'.md5( $key );
					$attributes = [
						'name'  => 'BitbucketDeploy_Options['.$option_key.']',
						'type'  => 'text',
						'value' => esc_attr( empty( $options[ $option_key ] ) ? null : $options[ $option_key ] ),
					];
					array_walk( $attributes, function( &$item, $key ) {
						$item = $key.'="'.$item.'"';
					} );

					echo '<p style="padding:0 0 16px 0;">';
					echo '<strong>'.$plugin[ 'Name' ].'</strong>';
					if( !empty( $_GET[ 'debug' ] ) && $_GET[ 'debug' ] == '1' ) {
						echo '<br/><code style="margin:0;font-size:10px;">'.$key.'</code>';
					}
					echo '<br/><input '.implode( ' ', $attributes ).' />';
					echo '</p>';

				}

			},
			'bitbucket-deploy',
			'BitbucketDeploy_Plugins'
		);

		// Create a section...
		add_settings_section(
			'BitbucketDeploy_Themes',
			'Themes',
			function() {

				echo '<p>Please configure your Themes here. Any fields left blank will be excluded from the Bitbucket Deploy service routines.</p>';

			},
			'bitbucket-deploy'
		);

		// Theme Config Fields...
		add_settings_field(
			'BitbucketDeploy_ThemesConfig',
			'Config',
			function( $args ) {

				$options = get_option( 'BitbucketDeploy_Options' );
				foreach( wp_get_themes() as $key => $theme ) {

					$option_key = 'theme_'.md5( $key );
					$attributes = [
						'name'  => 'BitbucketDeploy_Options['.$option_key.']',
						'type'  => 'text',
						'value' => esc_attr( empty( $options[ $option_key ] ) ? null : $options[ $option_key ] ),
					];
					array_walk( $attributes, function( &$item, $key ) {
						$item = $key.'="'.$item.'"';
					} );

					echo '<p style="padding:0 0 16px 0;">';
					echo '<strong>'.$theme->display( 'Name' ).'</strong>';
					if( !empty( $_GET[ 'debug' ] ) && $_GET[ 'debug' ] == '1' ) {
						echo '<br/><code style="margin:0;font-size:10px;">'.$key.'</code>';
					}
					echo '<br/><input '.implode( ' ', $attributes ).' />';
					echo '</p>';

				}

			},
			'bitbucket-deploy',
			'BitbucketDeploy_Themes'
		);

		// Output a debug field...
		if( !empty( $_GET[ 'debug' ] ) && $_GET[ 'debug' ] == '1' ) {

			// Create a section...
			add_settings_section(
				'BitbucketDeploy_Debug',
				'Debug',
				function() {

					echo '<p>Please configure your Plugins here.</p>';

				},
				'bitbucket-deploy'
			);

			add_settings_field(
				'BitbucketDeploy_Debug_Data',
				'Data',
				function( $args ) {

					// Retrieve the singleton instance...
					$BitbucketDeploy = BitbucketDeploy::instance();

					echo '<textarea disabled style="width: 800px; height: 400px; resize: none;">';
					echo print_r( [
						'plugin_registry'      => $BitbucketDeploy->plugin_registry,
						'theme_registry'       => $BitbucketDeploy->theme_registry,
						'BitbucketDeploy_Options' => get_option( 'BitbucketDeploy_Options' ),
					], 1 );
					echo '</textarea>';

				},
				'bitbucket-deploy',
				'BitbucketDeploy_Debug'
			);

		}

		// Retrieve the singleton instance...
		$BitbucketDeploy = BitbucketDeploy::instance();

		// Hydrate the service...
		$options = get_option( 'BitbucketDeploy_Options' );
		foreach( get_plugins() as $key => $plugin ) {

			$file = WP_PLUGIN_DIR.'/'.$key;
			$repository = $options[ 'plugin_'.md5( $key ) ];
			if( empty( $repository ) ) continue;

			$BitbucketDeploy->register_plugin( $file, $repository );

		}
		foreach( wp_get_themes() as $key => $theme ) {

			$file = get_theme_root().'/'.$key.'/style.css';
			$repository = $options[ 'theme_'.md5( $key ) ];
			if( empty( $repository ) ) continue;

			$BitbucketDeploy->register_theme( $file, $repository );

		}

		// Flush transients on activation and deactivation...
		register_activation_hook( __FILE__, [ $BitbucketDeploy, 'flush_transients' ] );
		register_deactivation_hook( __FILE__, [ $BitbucketDeploy, 'flush_transients' ] );

	}
	add_action( 'admin_init', 'BitbucketDeploy_AdminInit' );

	function BitbucketDeploy_AdminMenu() {

		add_submenu_page(
			'options-general.php',
			'Bitbucket Deploy',
			'Bitbucket Deploy',
			'administrator',
			'bitbucket-deploy',
			function() {

				// Render the interface...
				echo '<div class="wrap">';
				echo '<h1>'.esc_html( get_admin_page_title() ).'</h1>';
				echo '<form action="options.php" method="POST">';
				settings_fields( 'bitbucket-deploy' );
				do_settings_sections( 'bitbucket-deploy' );
				submit_button( 'Save Settings' );
				echo '</form>';
				echo '</div>';

			}
		);

	}
	add_action( 'admin_menu', 'BitbucketDeploy_AdminMenu' );
