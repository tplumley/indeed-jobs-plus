<?php

/*
Plugin Name: Indeed Jobs +
Plugin URI: https://www.indeed.com/hire
Description: The Indeed Jobs Plugin enables you to create an automatically synced job section in your WordPress page that displays all your live jobs on Indeed.
Version: 1.0.1
Author: Trevor Plumley
Author URI: http://geontech.com
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: indeed-jobs
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
Online: http://www.gnu.org/licenses/gpl.txt
*/


class Indeed_Jobs {
	private static $TEXT_DOMAIN = 'indeed-jobs';
	private static $ADMIN_PAGE_IDENTIFIER = 'indeed-jobs';
	private static $CAREERPAGE_URL = "https://career-pages.indeed.com";
	private static $EMPLOYER_URL = "https://employers.indeed.com";
	private static $SSL_VERIFY = true;
	private static $SLUG = "indeed-jobs";

	public static $SHORT_CODE = "indeed-jobs";
	private static $OPTION_ACCESS_TOKEN = "indeed-jobs-access-token";
	private static $OPTION_SHORTCODE_COPIED = "indeed-jobs-shortcode-copied";

	private static $ACTION_SHORTCODE_COPIED = "indeed-jobs-shortcode-copied";
	private static $ACTION_OAUTH_REVOKE = "indeed-jobs-oauth-revoke";
	private static $ACTION_OAUTH_REDIRECT = "indeed-jobs-oauth-redirect";

	private static $OAUTH_CLIENT_ID = "careerpages-wp-plugin";
	private static $OAUTH_SCOPE = "read:jobs";
	private static $OAUTH_GRANT_TYPE = "authorization_code";

	private static $ENDPOINT_OAUTH_AUTHORIZATION;
	private static $ENDPOINT_OAUTH_TOKEN = "/oauth/token";
	private static $ENDPOINT_EMPLOYER;
	private static $ENDPOINT_POST_JOB;
	private static $ENDPOINT_CAREER_PAGE = "/api/v1/me?access_token={access_token}&locale={locale}";
	private static $ENDPOINT_JOBS = "/api/v1/me/jobs?access_token={access_token}&locale={locale}";
	private static $ENDPOINT_JOB = "/api/v1/me/jobs/{jobKey}?access_token={access_token}&locale={locale}";

	private static $APPLY_JS = "https://apply.indeed.com/indeedapply/static/scripts/app/bootstrap.js";
	private static $APPLY_API_TOKEN = "aa102235a5ccb18bd3668c0e14aa3ea7e2503cfac2a7a9bf3d6549899e125af4";
	private static $APPLY_CALLBACK = "https://employers.indeed.com/process-indeedapply";
	private $accessToken;

	private $inWidget = false;

	public function __construct() {
		$this->run();
	}

	/**
	 * return all options used by this plugin
	 * @return array
	 */
	public static function getOptions() {
		return array( self::$OPTION_ACCESS_TOKEN, self::$OPTION_SHORTCODE_COPIED );
	}

	public function run() {
		if ( defined( "INDEED_CAREERPAGE_URL" ) ) {
			self::$CAREERPAGE_URL = INDEED_CAREERPAGE_URL;
			self::$SSL_VERIFY     = false;
		}
		if ( defined( "INDEED_EMPLOYER_URL" ) ) {
			self::$EMPLOYER_URL = INDEED_EMPLOYER_URL;
		}
		if ( defined( "INDEED_APPLY_JS" ) ) {
			self::$APPLY_JS = INDEED_APPLY_JS;
		}
		if ( defined( "INDEED_APPLY_API_TOKEN" ) ) {
			self::$APPLY_API_TOKEN = INDEED_APPLY_API_TOKEN;
		}
		if ( defined( "INDEED_APPLY_CALLBACK" ) ) {
			self::$APPLY_CALLBACK = INDEED_APPLY_CALLBACK;
		}

		self::$ENDPOINT_OAUTH_AUTHORIZATION = "/oauth/authorize?" . self::getSidKw( "{kw}" ) . "&client_id={client_id}&redirect_uri={redirect_uri}&scope={scope}&response_type=code&state={state}";
		self::$ENDPOINT_EMPLOYER            = "?" . self::getSidKw( "employerLink" );
		self::$ENDPOINT_POST_JOB            = "/j?" . self::getSidKw( "postJobBtn" ) . "#post-job";

		$this->accessToken = get_option( self::$OPTION_ACCESS_TOKEN );

		add_shortcode( self::$SHORT_CODE, array( $this, 'shortcode' ) );

		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_notices', array( $this, 'unconfigured_error' ) );
		add_action( 'init', array( $this, 'init' ) );
		add_action( "wp_ajax_" . self::$ACTION_OAUTH_REDIRECT, array( $this, 'oauth_redirect' ) );
		add_action( "wp_ajax_" . self::$ACTION_SHORTCODE_COPIED, array( $this, 'shortcode_copied' ) );
		add_action( "wp_ajax_" . self::$ACTION_OAUTH_REVOKE, array( $this, 'oauth_revoke' ) );
		add_action( 'plugins_loaded', array( $this, 'load_plugin_textdomain' ) );
		add_filter( 'the_posts', array( $this, 'the_posts' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
		add_filter( 'widget_posts_args', array( $this, 'widget_posts_args' ) );
	}

	/**
	 * This add option the to the admin menu
	 */
	public function admin_menu() {
		add_options_page( __( 'Indeed Jobs', self::$TEXT_DOMAIN ), __( 'Indeed Jobs', self::$TEXT_DOMAIN ), 'manage_options', self::$ADMIN_PAGE_IDENTIFIER, array(
			$this,
			'setting_page'
		) );
	}

	public function plugin_action_links( $links ) {
		return array_merge( array(
			'<a  href="' . admin_url( '/options-general.php?page=' . self::$ADMIN_PAGE_IDENTIFIER ) . '">' . __( 'Settings' ) . '</a>'
		), $links );
	}

	private static function getSidKw( $kw ) {
		return "sid=wordpress_jobs&kw=" . $kw;
	}

	/**
	 * register new options
	 */
	public function admin_init() {
		add_option( self::$OPTION_ACCESS_TOKEN, "" );
		add_option( self::$OPTION_SHORTCODE_COPIED, false );
		wp_register_style( self::$ADMIN_PAGE_IDENTIFIER, plugins_url( 'admin.css', __FILE__ ) );
	}

	/**
	 * register virtual pages' rewrite rules
	 */
	public function init() {
		add_rewrite_rule( self::$SLUG . '/(.*)?', 'index.php?pagename=' . self::$SLUG . '%2F$matches[1]', 'top' );
		add_rewrite_rule( self::$SLUG, 'index.php?pagename=' . self::$SLUG, 'top' );
	}

	public function load_plugin_textdomain() {
		load_plugin_textdomain( self::$TEXT_DOMAIN, false, basename( dirname( __FILE__ ) ) . '/languages/' );
	}

	protected function getGetStartedStep() {
		if ( empty( $this->accessToken ) ) {
			return 1;
		}

		if ( get_option( self::$OPTION_SHORTCODE_COPIED, false ) ) {
			return 3;
		} else {
			return 2;
		}
	}

	private function getOAuthState() {
		return wp_create_nonce( self::$OAUTH_CLIENT_ID );
	}

	private function getEmployerUrl() {
		return self::$EMPLOYER_URL . self::$ENDPOINT_EMPLOYER;
	}

	private function getPostJobUrl() {
		return self::$EMPLOYER_URL . self::$ENDPOINT_POST_JOB;
	}

	private function getOAuthRedirectUrl() {
		return get_site_url() . "/wp-admin/admin-ajax.php?action=" . self::$ACTION_OAUTH_REDIRECT;
	}

	private function getOAuthAuthorizationUrl( $kw, $state = null ) {
		return str_replace(
			array( "{kw}", "{client_id}", "{redirect_uri}", "{scope}", "{state}" ),
			array( $kw, self::$OAUTH_CLIENT_ID, urlencode( $this->getOAuthRedirectUrl() ), self::$OAUTH_SCOPE, $state ),
			self::$CAREERPAGE_URL . self::$ENDPOINT_OAUTH_AUTHORIZATION
		);
	}


	/**
	 * OAuth redirect endpoint
	 */
	public function oauth_redirect() {
		if ( isset( $_GET['state'] ) && isset( $_GET['code'] ) && wp_verify_nonce( $_GET['state'], self::$OAUTH_CLIENT_ID ) ) {
			$response = wp_remote_post( self::$CAREERPAGE_URL . self::$ENDPOINT_OAUTH_TOKEN, array(
				'method'    => 'POST',
				'sslverify' => self::$SSL_VERIFY,
				'body'      => array(
					'client_id'    => self::$OAUTH_CLIENT_ID,
					'redirect_uri' => $this->getOAuthRedirectUrl(),
					'grant_type'   => self::$OAUTH_GRANT_TYPE,
					'code'         => $_GET['code']
				)
			) );

			$error = "";
			if ( is_wp_error( $response ) ) {
				$error = '&error=wp-error&error_description=' . urlencode( $response->get_error_message() );
			} else {

				$data = json_decode( $response['body'], true );

				if ( isset( $data['access_token'] ) ) {
					update_option( self::$OPTION_ACCESS_TOKEN, $data['access_token'] );
					flush_rewrite_rules();
				} else if ( ( isset( $data['error'] ) ) ) {
					$error = '&error=' . $data['error'];
					if ( isset( $data['error_description'] ) ) {
						$error .= '&error_description=' . urlencode( $data['error_description'] );
					}
				}
			}

			wp_redirect( admin_url( '/options-general.php?page=' . self::$ADMIN_PAGE_IDENTIFIER . $error ), 302 );
		} else {
			wp_redirect( admin_url( '/options-general.php?page=' . self::$ADMIN_PAGE_IDENTIFIER ), 302 );
		}
		exit;
	}

	public function oauth_revoke() {
		if ( isset( $_POST['_wpnonce'] ) && ! empty( $this->accessToken ) && wp_verify_nonce( $_POST['_wpnonce'], 'oauthRevoke' ) ) {
			update_option( self::$OPTION_ACCESS_TOKEN, "" );
			update_option( self::$OPTION_SHORTCODE_COPIED, false );
			$this->accessToken = "";
			exit( true );
		}
		exit( false );
	}

	/**
	 * get Career Page profile
	 * @return array|null
	 */
	private function getCareerPageProfile() {
		$endpoint = str_replace(
			array( "{access_token}", "{locale}" ),
			array( $this->accessToken, get_locale() ),
			self::$CAREERPAGE_URL . self::$ENDPOINT_CAREER_PAGE
		);
		$response = wp_remote_get( $endpoint, array( 'sslverify' => self::$SSL_VERIFY ) );

		if ( is_array( $response ) && ! is_wp_error( $response ) ) {
			if ( $response['response']['code'] == 200 ) {
				return json_decode( $response['body'], true );
			} else if ( $response['response']['code'] == 403 ) {
				$res = json_decode( $response['body'], true );

				return $res['message'] === "An employer account is required to query information about the current user.";
			}
		}

		return null;
	}

	/**
	 * ajax endpoint for saving shortcode copied state
	 */
	public function shortcode_copied() {
		update_option( self::$OPTION_SHORTCODE_COPIED, true );
	}

	/**
	 * render setting page
	 */
	public function setting_page() {
		wp_enqueue_style( self::$ADMIN_PAGE_IDENTIFIER );
		add_thickbox();
		$indeedEmployersText = _x( 'Indeed for Employers', 'Indeed employer product name', self::$TEXT_DOMAIN );

		$profile = ! empty( $this->accessToken ) ? $this->getCareerPageProfile() : null;
		if ( ! $profile ) {
			$this->accessToken = null;
		}
		?>
		<div class="wrap" style="width: 768px">
			<h1><?php _ex( 'Indeed Jobs Settings', 'setting page title', self::$TEXT_DOMAIN ); ?></h1>
			<h2><?php _ex( 'About Indeed Jobs', 'setting page', self::$TEXT_DOMAIN ); ?></h2>
			<p><?php _ex( "As the world's #1 job site,* with over 200 million unique visitors every month from over 60 different countries, Indeed has become the catalyst for putting the world to work.", 'setting page', self::$TEXT_DOMAIN ); ?></p>
			<p><?php $link = '<a  href="' . $this->getEmployerUrl() . '" target="_blank">' . $indeedEmployersText . '</a>';
				printf( _x( "%1s allows you to post jobs and search resumes for free.**", 'setting page, %1 is the link to Indeed for Employers', self::$TEXT_DOMAIN ), $link ); ?></p>
			<p><?php _ex( 'This plugin will sync all the jobs you have posted on Indeed to your WordPress website. The job list and job ad will adopt your WordPress theme.', 'setting page', self::$TEXT_DOMAIN ); ?></p>
			<h2><?php _ex( 'Let’s get started!', 'setting page', self::$TEXT_DOMAIN ); ?></h2>
			<div class="indeed-jobs-getstarted-steps">
				<div class="indeed-jobs-getstarted-step <?php echo $this->getGetStartedStep() == 1 ? " active" : "" ?>">
					<div>
						<h3><?php _ex( 'Step 1: Get your unique token', 'setting page, step 1', self::$TEXT_DOMAIN ); ?></h3>
						<?php
						if ( $profile ) {
							?>
							<p><?php _ex( 'You have configured your account. If you would like to change the token, please use the button below.', 'setting page, step 1, authorized state ', self::$TEXT_DOMAIN ); ?></p><?php
						} else {
							?>
							<p><?php _ex( 'Click the button below to get a unique token for your account. If you do not have an account, sign up in just a few minutes!', 'setting page step 1, not authorized state', self::$TEXT_DOMAIN ); ?></p><?php } ?>
					</div>
					<div class="indeed-jobs-getstarted-action">
						<?php
						if ( $profile ) {
							?><a
							href="<?php echo $this->getOAuthAuthorizationUrl( "changeTokenBtn", $this->getOAuthState() ) ?>"
							class="button"><?php _ex( 'Change Token', 'button to change or unset access token to Indeed data', self::$TEXT_DOMAIN ); ?></a><?php
						} else {
							?><a
							href="<?php echo $this->getOAuthAuthorizationUrl( "getTokenBtn", $this->getOAuthState() ) ?>"
							class="button <?php echo $this->getGetStartedStep() == 1 ? " button-primary" : ""; ?>"><?php _ex( 'Get my token', 'button to get access token to Indeed data', self::$TEXT_DOMAIN ); ?></a>
						<?php } ?>
					</div>
				</div>
				<div class="indeed-jobs-getstarted-step <?php echo $this->getGetStartedStep() == 2 ? " active" : "" ?>"
					id="indeed-jobs-step2">
					<div>
						<h3><?php _ex( 'Step 2: Copy this shortcode', 'setting page step 2', self::$TEXT_DOMAIN ); ?></h3>
						<p><?php _ex( 'You can use this shortcode in any page on your WordPress website.', 'setting page step 2', self::$TEXT_DOMAIN ); ?></p>
					</div>
					<div class="indeed-jobs-getstarted-action">
						<div class="code-wrapper">
							<input class="code ltr" type="text" value="[<?php echo self::$SHORT_CODE; ?>]" readonly
							       title="[<?php echo self::$SHORT_CODE; ?>]"/>
						</div>
						<button class="button<?php echo $this->getGetStartedStep() == 2 ? " button-primary" : ""; ?>"><?php _ex( 'Copy', 'copy shortcode button', self::$TEXT_DOMAIN ); ?></button>
					</div>
					<div id="indeed-jobs-step2-modal" style="display:none;">
						<div class="indeed-jobs-modal-body">
							<h3><?php _ex( 'Your shortcode is copied.', 'setting page step 2 modal title', self::$TEXT_DOMAIN ); ?></h3>
							<p><?php {
									$shortcode = '<code>[' . self::$SHORT_CODE . ']</code>';
									printf( _x( 'Simply paste %1s into any page on your website and a job list will be displayed.', 'setting page step 2 modal, %1s is the shortcode', self::$TEXT_DOMAIN ), $shortcode );
								} ?></p>
							<p><?php {
									$link = '<a  href="' . admin_url( '/edit.php?post_type=page' ) . '">' . __( 'Pages' ) . '</a>';
									printf( _x( "Go to %1s and select the pages on which you would like the job list to appear. You can also add a new page dedicated to your job openings.", 'setting page step 2 modal, %1s is the link to WordPress Pages edit page', self::$TEXT_DOMAIN ), $link );
								} ?></p>
							<div class="indeed-jobs-modal-steps">
								<div>
									<img src="<?php echo plugins_url( 'images/getstarted-step2-1.jpg', __FILE__ ); ?>">
									<span><?php _ex( 'Go to Pages, select an existing page or add a new page.', 'setting page step 2 modal %1s is WordPress Pages string.', self::$TEXT_DOMAIN ); ?></span>
								</div>
								<div>
									<img src="<?php echo plugins_url( 'images/getstarted-step2-2.jpg', __FILE__ ); ?>">
									<span><?php {
											$shortcode = '[' . self::$SHORT_CODE . ']';
											printf( _x( 'Insert the shortcode (%1s) into the content area.', 'setting page step 2 modal %1s is the shortcode', self::$TEXT_DOMAIN ), $shortcode );
										} ?></span>
								</div>
								<div>
									<img src="<?php echo plugins_url( 'images/getstarted-step2-3.jpg', __FILE__ ); ?>">
									<span><?php _ex( 'Your jobs will appear on your website.', 'setting page step 2 modal', self::$TEXT_DOMAIN ); ?></span>
								</div>
							</div>
							<div class="indeed-jobs-modal-action">
								<a class="button button-primary"
								   onclick="tb_remove();"><?php _ex( 'Got it', 'close modal', self::$TEXT_DOMAIN ); ?></a>
							</div>
						</div>
					</div>
				</div>
				<div
					class="indeed-jobs-getstarted-step <?php echo $this->getGetStartedStep() == 3 ? " active" : "" ?>"
					id="indeed-jobs-step3">
					<div>
						<h3><?php _ex( 'Step 3: Post a job', 'setting page step 3', self::$TEXT_DOMAIN ); ?></h3>
						<p><?php $link = '<a  href="' . $this->getEmployerUrl() . '" target="_blank">' . $indeedEmployersText . '</a>';
							printf( _x( "All your jobs posted on %1s will be automatically synced with your website.", 'setting page step 3, %1 is the link to Indeed for Employers', self::$TEXT_DOMAIN ), $link ); ?></p>
					</div>
					<div class="indeed-jobs-getstarted-action">
						<a class="button<?php echo $this->getGetStartedStep() == 3 ? " button-primary" : ""; ?>"
						   href="<?php echo $this->getPostJobUrl(); ?>"
						   target="_blank"><?php _e( 'Post a job', self::$TEXT_DOMAIN ); ?></a>
					</div>
				</div>
			</div>

			<div class="indeed-jobs-footer">
				<p><?php _ex('*comScore, Total Visits, March 2017', 'footer disclaimer', self::$TEXT_DOMAIN) ?></p>
				<p><?php
					$legalLink = _x( "https://www.indeed.com/legal", "footer legal link", self::$TEXT_DOMAIN );
					$legalLink .= (strpos( $legalLink, "?" ) ? "&" : "?") . self::getSidKw( "termsLink" );
					printf( _x( '**Free job posting offer does not apply to job sites or certain other types of jobs at Indeed\'s discretion. <a %ls>Terms, conditions, quality standards and usage limits apply.</a> Countries with resume search are listed <a %1s>here</a>.', 'footer disclaimer', self::$TEXT_DOMAIN ), 'href="' . $legalLink . '" target="_blank"', 'href="https://www.indeed.com/resumes?' . self::getSidKw( "resumesLink" ) . '" target="_blank"' ) ?>
                </p>
                <form action="<?php echo admin_url( '/options-general.php?page=' . self::$ADMIN_PAGE_IDENTIFIER ); ?>" method="post">
                    <?php
                    $footerTexts = array(
                        '<span style="display:inline-block">' . _x( '© 2017 Indeed', 'footer', self::$TEXT_DOMAIN ) .'</span>',
						'<a href="' . $legalLink . '" target="_blank">' . _x( 'Cookies, Privacy and Terms', 'footer links', self::$TEXT_DOMAIN ) . '</a>',
                        '<a href="https://ads.indeed.com/contact_indeed?' . self::getSidKw( "termsLink" ) . '" target="_blank">' . _x( 'Contact', 'footer links', self::$TEXT_DOMAIN ) . '</a>',
                        ! empty( $this->accessToken ) ? '<a id="indeed-jobs-disconnect" href="javascript:void(0)">' . _x( 'Disconnect Indeed Jobs', 'button to unset access token to Indeed data', self::$TEXT_DOMAIN ) . '</a>' : null,
                        in_array( get_locale(), array(
                            "de_DE",
                            "de_DE_formal"
                        ) ) ? '<a href="https://de.indeed.com/about/citations" target="_blank">*Alle Quellenangaben finden Sie hier</a>' : null
                    );
                    echo implode( array_filter( $footerTexts ), "&nbsp;&nbsp;|&nbsp;&nbsp;" );
                    ?>
                </form>
            </div>
            <script type="text/javascript">
				jQuery('#indeed-jobs-step2')
					.find('.indeed-jobs-getstarted-action > button').on('click', function () {
					var step2 = jQuery('#indeed-jobs-step2');
					var step3 = jQuery('#indeed-jobs-step3');
					if (document.execCommand) {
						window.getSelection().removeAllRanges();
						var input = document.querySelector('#indeed-jobs-step2 input');
						input.focus();
						input.setSelectionRange(0, input.value.length);
						input.select();
						document.execCommand('copy');
					}
					tb_show('', '#TB_inline?width=600&height=380&inlineId=indeed-jobs-step2-modal');
					<?php if ( $this->getGetStartedStep() > 1 ) { ?>
					jQuery.post(ajaxurl, {action: '<?php echo self::$ACTION_SHORTCODE_COPIED;?>'});
					step2.removeClass('active').find('.indeed-jobs-getstarted-action > button').removeClass('button-primary');
					step3.addClass('active').find('a.button').addClass('button-primary');
					<?php } ?>
				});

				jQuery("#indeed-jobs-disconnect").click(function (e) {
					e.preventDefault();
					if (window.confirm("<?php _e( "Do you really want to disconnect your site from Indeed.com?", self::$TEXT_DOMAIN )?>")) {
						jQuery.post(ajaxurl, {
								action: '<?php echo self::$ACTION_OAUTH_REVOKE?>',
								'_wpnonce': '<?php echo wp_create_nonce( "oauthRevoke" )?>'
							},
							function () {
								window.location.reload();
							});
					}
				});
			</script>
		</div>
		<?php
	}

	/**
	 * This returns the url to a particular Job page.
	 *
	 * @param $jobKey
	 * @param $jobTitle
	 *
	 * @return false|string
	 */
	private static function getJobUrl( $jobKey, $jobTitle ) {
		return get_permalink( self::getPostMeta( $jobKey, $jobTitle ) );
	}

	private static function getUrlFriendlyString( $string ) {
		return sanitize_title( $string );
	}

	/**
	 * This returns the url to the All job page..
	 * @return false|string
	 */
	private static function getJobListUrl() {
		return get_permalink( self::getPostMeta() );
	}

	public function shortcode() {
		list( $title, $content ) = $this->getAllJobPage();

		return $content;
	}


	public function getAllJobPage() {
		if ( ! empty( $this->accessToken ) ) {
			$endpoint = str_replace(
				array( "{access_token}", "{locale}" ),
				array( $this->accessToken, get_locale() ),
				self::$CAREERPAGE_URL . self::$ENDPOINT_JOBS
			);
			$response = wp_remote_get( $endpoint, array( 'sslverify' => self::$SSL_VERIFY ) );

			if ( is_array( $response ) && ! is_wp_error( $response ) ) {
				if ( $response['response']['code'] == 200 ) {
					return self::getAllJobPageView( json_decode( $response['body'], true ) );
				}
			}
		}

		return self::getAllJobPageView( null );
	}

	private static function getJobDetails($job) {
		$details = array();
		if ( ! empty( $job['location'] ) ) {
			$details[] = self::escape( $job['location'] );
		}
		if ( ! empty( $job['type'] ) ) {
			$details[] = self::escape( $job['type'] );
		}
		if ( ! empty( $job['salary'] ) ) {
			$details[] = self::escape( $job['salary'] );
		}
		return $details;
	}

	private static function getAllJobPageView( $jobs ) {
		$title = _x( "Career", "All jobs page, page title", self::$TEXT_DOMAIN );
		if ( is_array( $jobs ) && count( $jobs ) > 0 ) {
			$content = "";
			$content .= '<ul id="job-listing">';
			foreach ( $jobs as $job ) {
				$details = self::getJobDetails($job);
				$excerpt = $job['descriptionHtml'];
				$url = self::getJobUrl( $job['key'], $job['title'] );
				$title = self::escape( $job['title'] );
				if (strlen($excerpt) > 200) {
			  	$excerpt = substr($excerpt, 0, 230) . '...';
				}
				$content .= '<li class="job-item">';
				$content .= '<a href="' . $url . '" title="' . $title . '">';
				$content .= '<div><p class="title"><strong>' . $title . '</strong></p>';
				$content .= '<p class="location">' . implode( "&nbsp;&nbsp;|&nbsp;&nbsp;", $details ) . '</p>';
				$content .= '<p>' . $excerpt . '</p>';
				$content .= '<p><a href="' . $url . '" class="button more-button" style="margin: 20px 0;">Learn More</a></p>';
				$content .= '</div></a>';
				$content .= '</li>';
			}
			$content .= '</ul>';

			return array( $title, $content );
		} else {
			return array(
				$title,
				"<p>" . _x( "We do not have any job openings at the moment. Please come back again later.", "All jobs page, when there's no job retrieved from remote service.", self::$TEXT_DOMAIN) . "</p>"
			);
		}
	}

	public function getJobPage( $jobKey ) {
		if ( ! empty( $this->accessToken ) ) {
			$endpoint = str_replace(
				array( "{access_token}", "{locale}", "{jobKey}" ),
				array( $this->accessToken, get_locale(), $jobKey ),
				self::$CAREERPAGE_URL . self::$ENDPOINT_JOB
			);
			$response = wp_remote_get( $endpoint, array( 'sslverify' => self::$SSL_VERIFY ) );
			if ( is_array( $response ) && ! is_wp_error( $response ) ) {
				if ( $response['response']['code'] == 200 ) {
					add_action( 'wp_footer', array( $this, 'add_ia_script' ) );

					return self::getJobPageView( json_decode( $response['body'], true ) );
				} else if ( $response['response']['code'] == 404 ) {
					return self::getJobPageView( null );
				}
			}
		}

		return array( null, null );
	}

	private static function getJobPageView( $job ) {
		$content = "";
		if ( $job ) {
			$content .= '<p>' . implode('<br>', self::getJobDetails($job)) . '</p>';
			$content .= '<hr>';
			$content .= '<p>' . $job['descriptionHtml'] . '</p>';

			if ($job['isIaAvailable']) {
				$applyAttr = array(
					'apiToken'	   => self::$APPLY_API_TOKEN,
					'jobId'		  => $job['key'],
					'jobLocation'	=> self::escape( $job['location'] ),
					'jobCompanyName' => self::escape( $job['companyName'] ),
					'jobTitle'	   => self::escape( $job['title'] ),
					'jobUrl'		 => self::getJobUrl( $job['key'], $job['title'] ),
					'locale'		 => get_locale(),
					'postUrl'		=> self::$APPLY_CALLBACK,
					'continueUrl'	=> self::getJobUrl( $job['key'], $job['title'] ),
					'jobMeta'		=> self::$OAUTH_CLIENT_ID,
					'resume'		 => self::escape( $job['resumeRequired'] )
				);

				if ( ! empty( $job['nigmaUrl'] ) ) {
					$applyAttr['questions'] = $job['nigmaUrl'];
				}

				$content .= '<span class="indeed-apply-widget" ';
				foreach ( array_filter( $applyAttr ) as $key => $value ) {
					$content .= ' data-indeed-apply-' . $key . '="' . $value . '"';
				}
				$content .= '></span>';
			}
		}
		// $content .= '<p><a href="' . self::getJobListUrl() . '">' . _x( 'Go to all jobs', 'job detail page', self::$TEXT_DOMAIN ) . '</a></p>';

		return array(
			$job != null ? self::escape( $job['title'] ) : _x( 'Job not found', 'job detail page' , self::$TEXT_DOMAIN),
			$content
		);
	}

	/**
	 * Filter that modify posts data if the requesting pagename equals to our slug
	 *
	 * @param $posts
	 *
	 * @return array
	 */
	public function the_posts( $posts ) {
		global $wp, $wp_query;

		if ( $this->inWidget ) {
			return $posts;
		}

		$paths = array();
		if ( isset( $wp->query_vars['pagename'] ) ) {
			$paths = explode( "/", $wp->query_vars['pagename'] );
		} else if ( isset( $wp->query_vars['page_id'] ) ) {
			$paths = explode( "/", $wp->query_vars['page_id'] );
		}
		$pagename = isset( $paths[0] ) ? $paths[0] : null;
		$jobKey   = isset( $paths[1] ) ? $paths[1] : null;
		$jobTitle = isset( $paths[2] ) ? $paths[2] : null;

		if ( $pagename == self::$SLUG ) {
			$post = $this->getPostMeta( $jobKey, $jobTitle );
			list( $post->post_title, $post->post_content ) = empty( $jobKey ) ?
				$this->getAllJobPage() :
				$this->getJobPage( $jobKey );

			if ( ! empty( $post->post_content ) ) {
				add_action( 'wp_head', 'wp_no_robots' );
				add_filter( 'edit_post_link', array( $this, 'noop' ) );                //remove edit post link
				add_filter( 'the_comments', '__return_empty_array' );
				$wp_query->is_page     = true;
				$wp_query->is_singular = true;
				$wp_query->is_home     = false;
				$wp_query->is_archive  = false;
				$wp_query->is_category = false;
				$wp_query->is_404      = false;

				return array( new WP_Post( $post ) );
			}
		}

		return $posts;
	}


	/**
	 * Indeed Apply Button Initialization Script
	 *
	 */
	public function add_ia_script() {
		?>
		<script type="text/javascript">
			(function (d, s, id) {
				var js, iajs = d.getElementsByTagName(s)[0];
				if (d.getElementById(id)) {
					return;
				}
				js = d.createElement(s);
				js.id = id;
				js.setAttribute('data-indeed-apply-qs', '<?php echo self::$OAUTH_CLIENT_ID;?>');
				js.async = true;
				js.src = '<?php echo self::$APPLY_JS;?>?hl=<?php echo get_locale();?>';
				iajs.parentNode.insertBefore(js, iajs);
			}(document, 'script', 'indeed-apply-js'));
		</script>
		<?php
	}

	private static function getPostMeta( $jobKey = null, $jobTitle = null ) {
		$id                   = self::$SLUG . ( ! empty( $jobKey ) ? '/' . $jobKey . '/' . self::getUrlFriendlyString( $jobTitle ) : '' );
		$post                 = new stdClass;
		$post->ID             = $id;
		$post->post_excerpt   = '';
		$post->post_status    = 'publish';
		$post->comment_status = 'closed';
		$post->ping_status    = 'closed';
		$post->post_type      = 'page';
		$post->filter         = "raw";
		$post->post_name      = $id;
		$post->guid           = get_home_url( '/' . $id );

		return new WP_Post( $post );
	}

	public function unconfigured_error() {
		global $pagenow;

		// don't show on the options-general menu
		if ( $pagenow != 'options-general.php' && empty( $this->accessToken ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				$link = '<a  href="' . admin_url( '/options-general.php?page=' . self::$ADMIN_PAGE_IDENTIFIER ) . '">' . _x( 'Indeed Jobs Settings', 'setting page title', self::$TEXT_DOMAIN ) . '</a>';
				?>
				<div class="error">
					<p><?php printf( _x( 'Indeed Jobs for WordPress is not configured, visit %1s to complete setup.', 'error message', self::$TEXT_DOMAIN ), $link ) ?></p>
				</div>
				<?php
			} else if ( current_user_can( 'edit_posts' ) ) {
				?>
				<div class="error">
					<p><?php _ex( 'Indeed Jobs for WordPress is not configured, please contact your site admin to configure the plugin.', 'error message', self::$TEXT_DOMAIN ); ?></p>
				</div>
				<?php
			}
		}
	}

	private static function escape( $str ) {
		return htmlspecialchars( $str, ENT_QUOTES );
	}

	public function widget_posts_args( $params ) {
		add_action( 'loop_end', array( $this, 'widget_posts_args_clean_up' ) );
		$this->inWidget = true;

		return $params;
	}

	public function widget_posts_args_clean_up( WP_Query $q ) {
		remove_filter( current_filter(), __FUNCTION__ );
		$this->inWidget = false;
	}

	public function noop() {
		//noop
	}
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	$indeed_jobs = new Indeed_Jobs();
}

