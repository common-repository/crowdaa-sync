<?php

use \Firebase\JWT\JWT;

/**
 * This plugin was mostly copied mostly from "WP REST User"
 * after it was disabled by wordpress and from "JWT Authentication for WP-API".
 * Some comments may not be up to date.
 *
 * @link       https://crowdaa.com
 * @since      1.0.0
 *
 * @package    Crowdaa-Sync
 * @subpackage Crowdaa-Sync/admin
 * @author     Crowdaa <contact@crowdaa.com>
 */
class Crowdaa_Sync_Rest_Api
{
  private $plugin_name;
  private $namespace;

  /**
   * Initialize the class and set its properties.
   *
   * @since    1.0.0
   *
   * @param string $plugin_name The name of the plugin.
   * @param string $version     The version of this plugin.
   */
  public function __construct($plugin_name, $version)
  {
    $this->plugin_name = $plugin_name;
    $this->namespace = $this->plugin_name . '/v' . intval($version);
  }

  public function add_api_routes()
  {
    if (get_option('crowdaa_sync_wpapi_register_enabled', 'yes') === 'yes') {
      register_rest_route($this->namespace, 'users/register', array(
        'methods' => 'POST',
        'callback' => array($this, 'register_user'),
        'permission_callback' => array($this, 'permission_allow_all_api'),
      ));
      register_rest_route($this->namespace, 'users/forgotpassword', array(
        'methods' => 'POST',
        'callback' => array($this, 'forgot_password'),
        'permission_callback' => array($this, 'permission_allow_all_api'),
      ));
      register_rest_route($this->namespace, 'session/checks', array(
        'methods' => 'GET',
        'callback' => array($this, 'session_checks'),
        'permission_callback' => array($this, 'permission_allow_auth_api'),
      ));
      register_rest_route($this->namespace, 'sync/badges/users', array(
        'methods' => 'POST',
        'callback' => array($this, 'sync_badges'),
        'permission_callback' => array($this, 'permission_allow_all_api'),
      ));
    }
  }

  public function sync_badges($request)
  {
    $response = array();
    $parameters = $request->get_json_params();
    $userId = $parameters['user_id'];
    $badges = $parameters['badges'];

    $response = ['updated' => false];

    $user = get_user_by('id', $userId);
    if (!$user) {
      Crowdaa_Sync_Logs::log('Crowdaa_Sync_Rest_Api::sync_badges() : User not found', $userId);
      return new WP_REST_Response($response, 200);
    }

    if (!Crowdaa_Sync_Permissions::plugin_get() || !is_array($badges)) {
      return new WP_REST_Response($response, 200);
    }

    Crowdaa_Sync_Permissions::set_user_perms($user->data->ID, $badges);

    $response['updated'] = true;
    return new WP_REST_Response($response, 200);
  }

  public function register_user($request)
  {
    $response = array();
    $parameters = $request->get_json_params();
    $username = sanitize_text_field($parameters['username']);
    $email = sanitize_text_field($parameters['email']);
    $password = sanitize_text_field($parameters['password']);
    $role = sanitize_text_field($parameters['role']);
    $error = new WP_Error();

    if (empty($username)) {
      return (new WP_Error('missing_username', __("Username field 'username' is required.", CROWDAA_SYNC_PLUGIN_NAME), array('status' => 400)));
    }
    if (empty($email)) {
      return (new WP_Error('missing_email', __("Email field 'email' is required.", CROWDAA_SYNC_PLUGIN_NAME), array('status' => 400)));
    }
    if (empty($password)) {
      return (new WP_Error('missing_password', __("Password field 'password' is required.", CROWDAA_SYNC_PLUGIN_NAME), array('status' => 400)));
    }
    if (empty($role)) {
      // WooCommerce specific code
      if (class_exists('WooCommerce')) {
        $role = 'customer';
      } else {
        $role = 'subscriber';
      }
    } else {
      $wpRoles = wp_roles();
      if ($wpRoles->is_role($role)) {
        if ($role === 'administrator' || $role === 'editor' || $role === 'author') {
          return (new WP_Error('forbidden_role', __("Role field 'role' is not a permitted. Only 'contributor', 'subscriber' and your custom roles are allowed.", 'wp_rest_user'), array('status' => 400)));
        }
      } else {
        return (new WP_Error('invalid_role', __("Role field 'role' is not a valid. Check your User Roles from Dashboard.", 'wp_rest_user'), array('status' => 400)));
      }
    }

    $user_id = username_exists($username);
    if (!$user_id && !email_exists($email)) {
      $user_id = wp_create_user($username, $password, $email);
      if (!is_wp_error($user_id)) {
        // Ger User Meta Data (Sensitive, Password included. DO NOT pass to front end.)
        $user = get_user_by('id', $user_id);
        $user->set_role($role);

        // Ger User Data (Non-Sensitive, Pass to front end.)
        $response['code'] = 200;
        $response['id'] = $user_id;
        $response['message'] = __("User '" . $username . "' Registration was Successful", "wp-rest-user");

        self::user_creation_email($user);
      } else {
        return $user_id;
      }
    } else if ($user_id) {
      return (new WP_Error('username_exists', __("Username already exists, please enter another username", CROWDAA_SYNC_PLUGIN_NAME), array('status' => 400)));
    } else {
      return (new WP_Error('email_exists', __("Email already exists, please try 'Reset Password'", CROWDAA_SYNC_PLUGIN_NAME), array('status' => 400)));
    }

    return new WP_REST_Response($response, 200);
  }

  public function forgot_password($request)
  {
    $response = array();
    $parameters = $request->get_json_params();
    $email = sanitize_text_field($parameters['email']);

    if (empty($email)) {
      return (new WP_Error('missing_email', __("The field 'user_email' is required.", CROWDAA_SYNC_PLUGIN_NAME), array('status' => 400)));
    } else {
      $user = get_user_by('email', $email);
      if ($user === false) {
        return (new WP_Error('email_not_found', __("User '" . $email . "' not found.", CROWDAA_SYNC_PLUGIN_NAME), array('status' => 401)));
      }
    }

    $email_ok = false;
    if (version_compare(get_bloginfo('version'), '5.7', '>=')) {
      $email_ok = retrieve_password($user->user_login);
    }

    if ($email_ok !== true) {
      $email_ok = self::password_reset_email($user);
    }

    if ($email_ok === true) {
      $response['code'] = 200;
      $response['message'] = __("Reset Password link has been sent to your email.", "wp-rest-user");
    } else {
      return (new WP_Error('email_send_error', __("Failed to send Reset Password email. Check your WordPress Hosting Email Settings.", CROWDAA_SYNC_PLUGIN_NAME), array('status' => 402)));
    }

    return new WP_REST_Response($response, 200);
  }

  public function session_checks($request)
  {
    $response = ['success' => true];

    $tokenData = self::decode_user_jwt_session_token();

    if (!$tokenData) {
      return (new WP_Error('invalid_token', __("Token is invalid or not found.", CROWDAA_SYNC_PLUGIN_NAME), array('status' => 400)));
    }

    $response['token'] = $tokenData->token;

    if (time() - $tokenData->iat > DAY_IN_SECONDS / 2) {
      $newToken = self::generate_jwt_session_token_for(get_current_user_id());
      $response['token'] = $newToken;
    }

    $user = wp_get_current_user();

    if (Crowdaa_Sync_Permissions::plugin_get()) {
      $user_memberships_ids = Crowdaa_Sync_Permissions::get_user_perms($user->data->ID);

      if (count($user_memberships_ids) > 0) {
        $sync_db = Crowdaa_Sync_Permissions::sync_db();
        $synced = $sync_db->get_entries_with_wp_ids($user_memberships_ids, 'api_id');
        $api_ids = Crowdaa_Sync_Utils::object_array_extract_field('api_id', $synced);
        $response['user_badges'] = $api_ids;
      } else {
        $response['user_badges'] = [];
      }
    }

    $response['user_id'] = $user->data->ID;

    $response = apply_filters('crowdaa_sync_api_session_checks', $response, $user);

    return new WP_REST_Response($response, 200);
  }

  /** Mostly copied from Jwt_Auth_Public::validate_token() on the "JWT authentication for wp" plugin */
  private static function decode_user_jwt_session_token()
  {
    $auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : false;
    if (!$auth) {
      $auth = isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : false;
    }

    if (!$auth) {
      Crowdaa_Sync_Logs::log('decode_user_jwt_session_token() : no HTTP Authorization header found');
      return (false);
    }

    /*
      * The HTTP_AUTHORIZATION is present verify the format
      * if the format is wrong return the user.
      */
    list($token) = sscanf($auth, 'Bearer %s');
    if (!$token) {
      Crowdaa_Sync_Logs::log('decode_user_jwt_session_token() : bad HTTP Authorization header');
      return (false);
    }

    /** Get the Secret Key */
    $secret_key = defined('JWT_AUTH_SECRET_KEY') ? JWT_AUTH_SECRET_KEY : false;
    if (!$secret_key) {
      Crowdaa_Sync_Logs::log('decode_user_jwt_session_token() : missing JWT_AUTH_SECRET_KEY define');
      return (false);
    }

    /** Try to decode the token */
    try {
      $tokenData = JWT::decode($token, $secret_key, array('HS256'));

      $tokenData->token = $token;
      return ($tokenData);
    } catch (\Throwable $e) {
      Crowdaa_Sync_Logs::log('decode_user_jwt_session_token() : Error decoding token', '' . $e);
    }

    return (false);
  }

  /** Mostly copied from Jwt_Auth_Public::generate_token() on the "JWT authentication for wp" plugin */
  public static function generate_jwt_session_token_for($userId)
  {
    $secret_key = defined('JWT_AUTH_SECRET_KEY') ? JWT_AUTH_SECRET_KEY : false;

    $user = get_user_by('id', $userId);

    /** Valid credentials, the user exists create the according Token */
    $issuedAt = time();
    $notBefore = $issuedAt;
    $expire = apply_filters('jwt_auth_expire', $issuedAt + (DAY_IN_SECONDS * 365), $issuedAt);

    $token = array(
      'iss' => get_bloginfo('url'),
      'iat' => $issuedAt,
      'nbf' => $notBefore,
      'exp' => $expire,
      'data' => array(
        'user' => array(
          'id' => $user->data->ID,
        ),
      ),
    );

    /** Let the user modify the token data before the sign. */
    $token = JWT::encode($token, $secret_key);

    /** Let the user modify the data before send it back */
    return ($token);
  }

  private static function user_creation_email($user)
  {
    // Redefining user_login ensures we return the right case in the email.
    $user_login = $user->user_login;
    $user_email = $user->user_email;

    if (is_multisite()) {
      $site_name = get_network()->site_name;
    } else {
      /*
      * The blogname option is escaped with esc_html on the way into the database
      * in sanitize_option we want to reverse this for the plain text arena of emails.
      */
      $site_name = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
    }

    $message = sprintf(__('Nous vous confirmons la création du compte %s sur le site %s.', CROWDAA_SYNC_PLUGIN_NAME), $user_login, $site_name) . "\r\n\r\n";
    $title = sprintf(__('[%s] confirmation de la création du compte'), $site_name);

    return (wp_mail($user_email, $title, $message));
  }

  private static function password_reset_email($user)
  {
    // Redefining user_login ensures we return the right case in the email.
    $user_login = $user->user_login;
    $user_email = $user->user_email;
    $key        = get_password_reset_key($user);

    if (is_wp_error($key)) {
      return (new WP_Error('password_reset_key_gen_error', __("Failed to generate a password reset key. Check the WordPress Hosting Email Settings.", CROWDAA_SYNC_PLUGIN_NAME), array('status' => 500)));
    }

    if (is_multisite()) {
      $site_name = get_network()->site_name;
    } else {
      /*
      * The blogname option is escaped with esc_html on the way into the database
      * in sanitize_option we want to reverse this for the plain text arena of emails.
      */
      $site_name = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
    }

    $reset_link = network_site_url("wp-login.php?action=rp&key=$key&login=" . rawurlencode($user_login), 'login');
    $message = sprintf(__('Une demande de réinitialisation du mot de passe a été effectuée pour le compte: %s', CROWDAA_SYNC_PLUGIN_NAME), $user_login) . "\r\n\r\n";
    $message .= __('Si vous n\'êtes pas à l\'origine de cette demande, vous pouvez ignorer cet email.', CROWDAA_SYNC_PLUGIN_NAME) . "\r\n\r\n";
    $message .= sprintf(__('Pour réinitialiser votre mot de passe, rendez-vous à l\'adresse suivante: %s', CROWDAA_SYNC_PLUGIN_NAME), $reset_link) . "\r\n\r\n";

    $title = sprintf(__('[%s] Password Reset'), $site_name);

    return (wp_mail($user_email, $title, $message));
  }

  public function permission_allow_all_api()
  {
    return (Crowdaa_Sync_Utils::is_crowdaa_api_request());
  }

  public function permission_allow_auth_api()
  {
    return (is_user_logged_in() && Crowdaa_Sync_Utils::is_crowdaa_api_request());
  }
}
