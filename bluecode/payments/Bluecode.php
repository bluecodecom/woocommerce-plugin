<?php
define( "BC_BASIC_HEADER", "Basic " );

/**
 * Class Bluecode for payment
 */
class Bluecode extends WC_Payment_Gateway
{

  /** @var string */
  public $clientid;

  /** @var string */
  public $clientsecret;

  /** @var string */
  public $branch;

  /** @var string */
  public $lang;

  /** @var string */
  public $purpose;

  /** @var boolean */
  public $sandbox;

  /** @var boolean */
  public $miniapponly;

  /**
   * Singleton implementation: static Instance
   * @var Bluecode
   */
  static private $instance = NULL;

  /**
   * User agent to be sent on requests
   * @var string
   */
  private $useragent;

  /** @var string[] API URLs for sandbox and live */
  protected $aURLs = array(
    "test" => "https://merchant-api.bluecode.biz/v4/%s",
    "prod" => "https://merchant-api.bluecode.com/v4/%s"
  );
  protected $aTokenURLs = array(
    "test" => "https://merchant-api.bluecode.biz/oauth2/%s",
    "prod" => "https://merchant-api.bluecode.com/oauth2/%s"
  );
  protected $aPortalURLs = array(
    "test" => "https://merchant-portal.bluecode.biz/%s",
    "prod" => "https://merchant-portal.bluecode.com/%s"
  );


  /**
   * Singleton method for call via static function.
   * @return Bluecode|null
   */
  static public function getInstance()
  {
    if (NULL === self::$instance) {
      self::$instance = new self();
    }

    return self::$instance;
  }

  /**
   * Constructor for the gateway.
   *
   * @return void
   * @package bluecode
   * @copyright Copyright (c) 2021, Blue Code International AG
   * @access public
   * @author Blue Code International AG
   */
  public function __construct()
  {
    global $woocommerce;

    // Set language
    $this->setLanguage();

    // Set core gateway settings
    $this->id = 'bluecode';
    $this->method_title = __('Bluecode', 'bluecode');
    $this->method_description = __('<a href="https://bluecode.com/en/b2b/merchants/" target="_blank">Get started with Bluecode</a>.', 'bluecode');
    $this->icon = plugins_url('img/icon_bluecode.png', dirname(__FILE__));
    $this->title = __('Bluecode', 'bluecode');
    $this->has_fields = FALSE;

    if (!function_exists('get_plugin_data')) {
      require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
    $pluginInfo = get_plugin_data(ABSPATH . "wp-content/plugins/bluecode/bluecode.php");
    $this->useragent = 'Bluecode WooCommerce plugin V. ' . $pluginInfo["Version"] . ', WC V. ' . $woocommerce->version;

    // Load the settings
    $this->init_form_fields();
    $this->init_settings();

    // Define user set variables
    $this->title = $this->get_option('title');
    $this->description = __('The mobile payment solution that rewards you for your loyalty. Download the free Bluecode app now and unlock exclusive <a href="https://bluecode.com/en/b2c/rewards/">rewards</a>.', 'bluecode');
    $this->clientid = $this->get_option('client-id');
    $this->clientsecret = $this->get_option('client-secret');
    $this->branch = $this->get_option('branch');
    $this->purpose = $this->get_option('purpose');
    $this->sandbox = $this->get_option('sandbox');
    $this->miniapponly = $this->get_option('miniapponly');

    // Hooks
    add_action('woocommerce_api_' . $this->id, array($this, 'check_response'));
    add_action('valid_redirect_' . $this->id, array($this, 'do_bc_redirect'));
    add_action('valid_notify_' . $this->id, array($this, 'do_bc_notify'));
    add_action('bc_oauth2_redirect', array($this, 'bc_oauth2_redirect'));
    add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    add_filter('woocommerce_payment_gateways', array($this, 'addGateway'));

    add_action('wp_enqueue_scripts', array($this, 'woocommerce_bluecode_register_scripts'));
    add_action('woocommerce_receipt_bluecode', array($this, 'receipt'));

    add_filter('woocommerce_available_payment_gateways', array($this, 'available_payment_gateways'));

    add_action('admin_enqueue_scripts', array($this, 'add_script_to_admin'));

    $this->supports = array(
      'products',
      'charge',
      'refunds'
    );
  }

  /**
   * AJAX call
   * @return stdClass|void
   */
  public static function load_oauth2_data() {
    $bluecode = self::getInstance();
    $oReturn = new stdClass();

    if( !$bluecode->validateData() ) {
      $oReturn->result = "Form data not saved";
      wp_send_json_success($oReturn);
    }

    // Check for nonce security
    $nonce = sanitize_text_field( $_POST['nonce'] );

    if ( ! wp_verify_nonce( $nonce, 'bluecode-nonce' ) ) {
      $oReturn->result = "Security failure";
      wp_send_json_success($oReturn);
    }

    $urlRedirect = add_query_arg('type', 'bcoauth2', add_query_arg('wc-api', $bluecode->id, home_url('/')));

    $strBaseUrl = $bluecode->getApiUrl("oauth2/authorize", "portal" );
    $state = $bluecode->random_token();
    $client_id = $bluecode->clientid;
    $response_type = "code";
    $scope = "merchant-api";

    // Save redirect URL for token call
    update_option('redirect_uri', $urlRedirect);

    $oReturn->result = "ok";
    $oReturn->getUrl = $strBaseUrl . "?client_id=$client_id&response_type=$response_type&redirect_uri=".
      urlencode($urlRedirect)."&state=$state&scope=$scope";

    //error_log("Return ". print_r($oReturn, true));

    wp_send_json_success($oReturn);
  }

  private function random_token($length = 32) {
    if (!isset($length) || intval($length) <= 8) {
      $length = 32;
    }
    if (function_exists('random_bytes')) {
      return bin2hex(random_bytes($length));
    }
    if (function_exists('mcrypt_create_iv')) {
      return bin2hex(mcrypt_create_iv($length, MCRYPT_DEV_URANDOM));
    }
    if (function_exists('openssl_random_pseudo_bytes')) {
      return bin2hex(openssl_random_pseudo_bytes($length));
    }
  }

  public function add_script_to_admin( $hook )
  {
    if ('woocommerce_page_wc-settings' != $hook) {
      return;
    }

    wp_enqueue_style("jquery-ui-css", "https://code.jquery.com/ui/1.13.1/themes/base/jquery-ui.css");

    //wp_enqueue_script("jquery");
    wp_enqueue_script("jquery-ui", "https://code.jquery.com/ui/1.13.1/jquery-ui.js", ['jquery']);

    wp_enqueue_script('admin_bluecode_js', plugins_url('bluecode/js/admin_functions.js'), [ 'wp-util', 'wp-i18n' ], rand(111, 9999));
    wp_set_script_translations( 'admin_bluecode_js', 'bluecode', WP_PLUGIN_DIR . '/bluecode/languages' );
    wp_localize_script( 'admin_bluecode_js', 'ajax_config', array(
        'url'    => admin_url( 'admin-ajax.php' ),
        'nonce'  => wp_create_nonce( 'bluecode-nonce' ),
        'action' => 'load_oauth2_data'
    ) );
  }

  /**
   * Hook that allows filtering of payment gateways.
   *
   * @param $available_gateways
   * @return mixed
   */
  public function available_payment_gateways( $available_gateways ) {

    // Hide bluecode for currencies != EUR
    if (get_woocommerce_currency() != "EUR") {
      unset($available_gateways[$this->id]);
    }

    // In mini-app mode, only allow Bluecode
    if( $this->isMiniApp() ) {
      foreach ($available_gateways as $name => $info) {
        if ($name !== $this->id) {
          unset($available_gateways[$name]);
        }
      }
    }
    elseif( $this->miniapponly != "no" ) {
      // In mini-app only mode, hide Bluecode when not in mini-app
      unset($available_gateways[$this->id]);
    }

    return $available_gateways;
  }

  /**
   * Set language code for get text functions.
   *
   * Allowed values: de, en
   * default: de
   *
   * @author Blue Code International AG
   * @package bluecode
   * @copyright Copyright (c) 2021, Blue Code International AG
   * @param string $language
   */
  public function setLanguage($language = 'de') {
    $strLang = substr(get_bloginfo("language"), 0, 2);

    if ($strLang == 'de' || $strLang == 'en') {
      $this->lang = $strLang;
    }
    else {
      $this->lang = $language;
    }
  }

  /**
   * Initialize gateway settings form fields.
   *
   * @author Blue Code International AG
   * @package bluecode
   * @copyright Copyright (c) 2021, Blue Code International AG
   * @access public
   * @return void
   */
  public function init_form_fields() {
    $this->form_fields = array(
        'configuration' => array(
            'title' => __('Set-up configuration', 'bluecode'),
            'type' => 'title'
        ),
        'enabled' => array(
            'title' => __('Enable/Disable', 'bluecode'),
            'type' => 'checkbox',
            'label' => __('Enable Bluecode', 'bluecode'),
            'default' => 'no',
        ),
        'sandbox' => array(
            'title' => __('Sandbox mode', 'bluecode'),
            'type' => 'checkbox',
            'label' => __('Enable Bluecode sandbox mode for testing', 'bluecode'),
            'default' => 'no',
        ),
        'miniapponly' => array(
            'title' => __('Use in mini-app only', 'bluecode'),
            'type' => 'checkbox',
            'label' => __('Enable Bluecode only in mini-app mode, not in the regular web shop', 'bluecode'),
            'default' => 'no',
        ),
        'title' => array(
            'title' => __('Title', 'bluecode'),
            'type' => 'text',
            'description' => __('Bluecode works like cash — just on your smartphone .', 'bluecode'),
            'default' => __('Bluecode', 'bluecode'),
            'desc_tip' => true,
        ),
        'branch' => array(
            'title' => __('Branch', 'bluecode'),
            'type' => 'text',
            'description' => __('Branch as provided by Bluecode', 'bluecode'),
            'default' => '',
            'desc_tip' => true,
        ),
        'purpose' => array(
            'title' => __('Slip text', 'bluecode'),
            'type' => 'text',
            'description' => __('Text to print on sales slip. Allowed placeholders:<br/><ol><li>[[ORDERID]]: Order number</li><li>[[CUSTOMERID]]: Customer identifier</li><li>[[SHOPNAME]]: Store name</li></ol>', 'bluecode'),
            'default' => '',
            'desc_tip' => true,
        ),
        'client-id' => array(
            'title' => __('Client ID', 'bluecode'),
            'type' => 'text',
            'description' => __('Get this information during your Bluecode onboarding.', 'bluecode'),
            'default' => '',
            'desc_tip' => true,
        ),
        'client-secret' => array(
            'title' => __('Client Secret', 'bluecode'),
            'type' => 'password',
            'description' => __('Get this information during your Bluecode onboarding.', 'bluecode'),
            'default' => '',
            'desc_tip' => true,
        ),
    );

    $clientid = $this->get_option("client-id");
    $clientsecret = $this->get_option("client-secret");
    if( !empty($clientid) && !empty($clientsecret) ) {
      $access_token = get_option("access_token");
      $refresh_token = get_option("refresh_token");
      $expiry_time = get_option("expiry_time");

      $class = "oauth2_authorize";
      if ((empty($access_token) && empty($refresh_token))
        || (!empty($expiry_time) && (current_time("timestamp") > $expiry_time))) {
        $desc = __('Click to authorize', 'bluecode');
      }
      else {
        $desc = __('Click to re-authorize', 'bluecode');
      }

      $this->form_fields["authorize"] = array(
        'title' => $desc,
        'type' => 'button',
        'description' => __('Click here and then enter your merchant credentials to authorize this shop for Bluecode API access.', 'bluecode'),
        'default' => 'Authorize',
        'class' => $class,
        'desc_tip' => TRUE,
      );
    }
  }

  /**
   * Add the payment gateway to wc
   *
   * @author Blue Code International AG
   * @package bluecode
   * @copyright Copyright (c) 2021, Blue Code International AG
   * @access public
   * @return array
   */
  public function addGateway($methods) {
    if (get_woocommerce_currency() == "EUR") {
      $methods[] = $this->id;
    }
    return $methods;
  }

  /**
   * Admin Panel Options.
   *
   * @author Blue Code International AG
   * @package bluecode
   * @copyright Copyright (c) 2021, Blue Code International AG
   * @access public
   * @return void
   */
  public function admin_options() {
    ?>
    <h3><?php _e('Bluecode', 'bluecode'); ?></h3>
    <p><?php _e('Bluecode payment options', 'bluecode'); ?></p>
    <table class="form-table">
      <?php
      // Generate the HTML for the settings form.
      $this->generate_settings_html();
      ?>
    </table><!--/.form-table-->
    <?php
  }

  /**
   * Build purpose string based on template from configuration and data that replaces placeholders.
   *
   * @param array $p_aData Associative array that contains the data to be filled in to the placeholders.
   *   Fields:
   *   orderid - ID of WooCommerce order
   *   customerid - ID of customer if known (empty otherwise)
   *   shopname - Name of store
   * @return string Finished purpose string
   */
  private function buildPurpose( $p_aData ) {
    $aAllowedPlaceholders = array(
      "[[ORDERID]]",
      "[[SHOPNAME]]",
      "[[CUSTOMERID]]"
    );

    $strPurpose = $this->purpose;
    foreach ( $aAllowedPlaceholders as $strPlaceholder ) {
      switch( $strPlaceholder ) {
        case "[[ORDERID]]":
          $strPurpose = str_replace( $strPlaceholder, $p_aData["orderid"], $strPurpose );
          break;
        case "[[SHOPNAME]]":
          $strPurpose = str_replace( $strPlaceholder, $p_aData["shopname"], $strPurpose );
          break;
        case "[[CUSTOMERID]]":
          if( empty($p_aData["customerid"]) ) {
            $p_aData["customerid"] = __("(new cust)", "bluecode");
          }
          $strPurpose = str_replace( $strPlaceholder, $p_aData["customerid"], $strPurpose );
          break;
        default:
          break;
      }
    }

    return $strPurpose;
  }

  /**
   * Return the URL required to post the request to the API,
   * depending on give test mode setting.
   *
   * @param string $p_strEndpoint Endpoint name to append to URL
   * @param string $p_strType Type of URL to get, default-portal-token
   * @return string URL
   */
  private function getApiUrl( $p_strEndpoint, $p_strType = "default" ) {

    if( $this->sandbox != 'no' ) {
      $strUrlType = "test";
    }
    else {
      $strUrlType = "prod";
    }

    if( $p_strType == 'portal' ) {
      $strUrl = $this->aPortalURLs[$strUrlType];
    }
    elseif( $p_strType == 'token' ) {
      $strUrl = $this->aTokenURLs[$strUrlType];
    }
    else {
      $strUrl = $this->aURLs[$strUrlType];
    }

    $strUrl = sprintf( $strUrl, $p_strEndpoint );

    return $strUrl;
  }

  /**
   * Validate admin form data.
   *
   * @return bool
   */
  private function validateData() {
    if (empty($this->clientid)) {
      wc_add_notice(__('Bluecode error: missing Client-ID', 'bluecode'), 'error');
      return FALSE;
    }
    if (empty($this->clientsecret)) {
      wc_add_notice(__('Bluecode error: missing Client Secret', 'bluecode'), 'error');
      return FALSE;
    }
    if (empty($this->branch)) {
      wc_add_notice(__('Bluecode error: missing branch', 'bluecode'), 'error');
      return FALSE;
    }
    if (empty($this->purpose)) {
      wc_add_notice(__('Bluecode error: missing purpose', 'bluecode'), 'error');
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Calls the cancel endpoint of the Bluecode API.
   *
   * @param $order_id
   * @return bool
   */
  private function callCancel( $order_id ) {
    if( !$this->validateData() ) {
      return FALSE;
    }

    $access_token = $this->getOAuth2Token();

    try {
      $aBody = array(
        "merchant_tx_id" => $order_id
      );

      $args = array(
        'headers' => array(
          'Authorization' => "Bearer " . $access_token,
          'User-Agent' => $this->useragent
        ),
        'body' => $aBody,
        'sslverify' => TRUE,
      );

      $strUrl = $this->getApiUrl('cancel' );
      $response = wp_remote_post( $strUrl, $args );

      $response_body = json_decode($response["body"]);
      $http_code = $response["response"]["code"];

      if( $http_code == '200' && $response_body->result == "OK" ) {
        return TRUE;
      }
      else {
        return FALSE;
      }
    }
    catch (Exception $e) {
      return FALSE;
    }
  }

  /**
   * Obtain status of Bluecode transaction.
   *
   * @param $order_id
   * @return false|mixed
   */
  private function callStatus( $order_id ) {
    if( !$this->validateData() ) {
      return FALSE;
    }

    $access_token = $this->getOAuth2Token();

    try {
      $aBody = array(
        "merchant_tx_id" => $order_id
      );

      $args = array(
        'headers' => array(
          'Authorization' => "Bearer " . $access_token,
          'User-Agent' => $this->useragent
        ),
        'body' => $aBody,
        'sslverify' => TRUE,
      );

      $strUrl = $this->getApiUrl('status' );
      $response = wp_remote_post( $strUrl, $args );

      $response_body = json_decode($response["body"]);
      $http_code = $response["response"]["code"];

      if( $http_code == '200' && $response_body->result == "OK" ) {
        return $response_body;
      }
      else {
        return FALSE;
      }
    }
    catch (Exception $e) {
      return FALSE;
    }
  }

  /**
   * Obtain token of Bluecode OAuth2.
   *
   * @param string $code Code from authorize call
   * @param string $refreshToken Refresh token from previous token call
   * @param string $redirect_uri Redirect URL used in authorize call
   * @param string $client_id Client ID from configuration
   * @param string $client_secret Client secret from configuration
   * @return false|mixed
   */
  private function callToken( $code, $refreshToken, $redirect_uri, $client_id, $client_secret ) {
    if( !$this->validateData() ) {
      //error_log("Token Error ". __LINE__);
      return FALSE;
    }

    try {
      if( empty($refreshToken) ) {  // Code case
        $aBody = array(
          "grant_type" => "authorization_code",
          "code" => $code
        );
      }
      else {  // Token case
        $aBody = array(
          "grant_type" => "refresh_token",
          "refresh_token" => $refreshToken
        );
      }

      $aBody["redirect_uri"] = $redirect_uri;
      $aBody["client_id"] = $client_id;
      $aBody["client_secret"] = $client_secret;

      $args = array(
        'headers' => array(
          'User-Agent' => $this->useragent
        ),
        'body' => $aBody,
        'sslverify' => TRUE,
      );

      $strUrl = $this->getApiUrl('token', "token" );

      //error_log( "URL $strUrl" );
      //error_log( "Request ". print_r($args, true) );

      $response = wp_remote_post( $strUrl, $args );

      //error_log( "Response ". print_r($response, true) );

      if( is_array($response) ) {
        $response_body = json_decode($response["body"]);
        $http_code = $response["response"]["code"];

        if ($http_code == '200') {
          return $response_body;
        }
        else {
          //error_log("Token Error ". __LINE__);
          return FALSE;
        }
      }
      else {
        //error_log("Token Error ". __LINE__);
        return FALSE;
      }
    }
    catch (Exception $e) {
      //error_log("Token Error ". __LINE__);
      return FALSE;
    }
  }

  /**
   * Process the refund triggered in backend.
   *
   * @param integer $order_id
   * @param integer $amount
   * @param string $reason
   * @return bool
   */
  public function process_refund($order_id,  $amount = NULL, $reason = '') {

    $http_code = $this->refundBlueCodePayment($order_id, $amount, $reason, $response_body);

    $order = wc_get_order($order_id);
    if ($http_code == '200' && $response_body->result == "OK") {
      if( isset($response_body->instant_refund) ) {
        $endToEndId = $response_body->instant_refund->end_to_end_id;
        $refundedAmount = $response_body->instant_refund->refundable_amount;
        $refund_message = sprintf( __( 'Refunded %s - Refund ID: %s - Reason: %s', 'bluecode' ), $refundedAmount, $endToEndId, $reason );
      }
      else {
        $refund_message = sprintf( __( 'Refunded %s -  Reason: %s', 'bluecode' ), $amount, $reason );
      }

			$order->add_order_note( $refund_message );
      return TRUE;
    }
    else {
      if( $response_body->error_code == "ISSUER_FAILURE" ) {
        $refund_message = __( 'Refund failed: Issuer does not support automatic refund', 'bluecode' );
  			$order->add_order_note( $refund_message );
        return FALSE;
      }
      else {
        $error = $response_body->result . "(" . $response_body->error_code . ")";
        $refund_message = sprintf( __( 'Refund returned error: %s', 'bluecode' ), $error );
  			$order->add_order_note( $refund_message );
        return FALSE;
      }
    }
  }

  /**
   * Process the payment and return the result.
   *
   * @author Blue Code International AG
   * @package bluecode
   * @copyright Copyright (c) 2021, Blue Code International AG
   * @access public
   * @param int $order_id
   * @return array
   */
  public function process_payment($order_id) {

    if( $this->isMiniApp() ) {
      // Mini-App case
      $order = wc_get_order( $order_id );
      if($order->get_status() === 'completed'){
        //Redirect to payment page
        return array(
          'result'    => 'success',
          'redirect'  => $this->get_return_url( $order )
        );
      }
      else{
        return array(
          'result'    => 'success',
          'redirect'  => $order->get_checkout_payment_url(true)
        );
      }
    }
    else {
      // Web store case
      $http_code = $this->initBlueCodePayment($order_id, $response_body);

      if ($http_code == '200' && $response_body->result == "OK") {
        $strUrlRedirect = $response_body->payment->checkin_code;
      }
      else {
        if( $response_body->result == "PROCESSING" ) {
          // Handle special processing case
          return $this->handleProcessing($response_body);
        }
        // BLUECODE-API didn't accept the data.
        $error = $response_body->result . "(" . $response_body->error_code . ")";
        if( $response_body->error_code == 'MERCHANT_TX_ID_NOT_UNIQUE' ) {
          $error = __( 'Order number already used, please create a new order', 'bluecode' );
        }
        wc_add_notice($error, 'error');
        return;  // return null according to WC docs
      }

      return array(
          'result' => 'success',
          'redirect' => $strUrlRedirect,
      );
    }
  }

  /**
   * Handle special case where Bluecode register returns result "PROCESSING" and we need to
   * retry several times until we have a result.
   *
   * @param stdClass $response_body Body obtained from register call.
   * @param boolean $noredirect Set to TRUE to get a status return instead of a redirect array.
   * @param stdClass $returndata [OUT] When $noredirect is TRUE, the obtained return data is returned here.
   * @return array|void|boolean array to be returned by caller for redirect, or nothing in case of error.
   *   TRUE/FALSE when $noredirect is TRUE.
   */
  public function handleProcessing($response_body, $noredirect = FALSE, &$returndata = null) {
    $oStatus = new stdClass();
    $ttl = $response_body->status->ttl;  // Time to live, until when should we retry?
    $check_in = $response_body->status->check_status_in; // Check in X milliseconds
    $id = $response_body->status->merchant_tx_id;

    $time = $start = round(microtime(TRUE) * 1000); // Get start time in milliseconds
    $maxtime = $start + $ttl * 1000; // Repeat until maxtime
    $bDone = FALSE;
    while( $time <= $maxtime ) {
      usleep( $check_in * 1000 );
      $oStatus = $this->callStatus($id); // Get updated transaction state
      if( $oStatus->result !== "PROCESSING" ) {
        // Result is now final, so exit
        $bDone = TRUE;
        break;
      }
      else {
        $time = round(microtime(TRUE) * 1000);  // Get updated time in milliseconds
      }
    }

    if( $bDone && isset($oStatus->result) && $oStatus->result == "OK" ) {
      if( $oStatus->payment->state == "REGISTERED" || $oStatus->payment->state == "APPROVED" ) {
        // In case of "APPROVED" or "REGISTERED", redirect buyer to Bluecode page
        $strUrlRedirect = $oStatus->payment->checkin_code;
        if( $noredirect ) {
          $returndata = $oStatus;
          return TRUE;
        }
        else {
          return array(
            'result' => 'success',
            'redirect' => $strUrlRedirect,
          );
        }
      }
      else {
        // Return error
        if( $noredirect ) {
          $returndata = $oStatus;
          return FALSE;
        }
        else {
          if( isset($oStatus->error_code) ) {
            $error = $oStatus->result . "(" . $oStatus->error_code . ")";
          }
          else {
            $error = __('Could not initialize payment, try again later','bluecode');
          }
          wc_add_notice($error, 'error');
          return;  // return null according to WC docs
        }
      }
    }
    else {
      // Time has elapsed, so cancel transaction
      $this->callCancel($id);
      if( $noredirect ) {
        $returndata = new stdClass();
        $returndata->result = __('Bluecode connection timed out, try again later.', 'bluecode');
        $returndata->error_code = 9999;
        return FALSE;
      }
      else {
        wc_add_notice(__('Bluecode connection timed out, try again later.', 'bluecode'), 'error');
        return;  // return null according to WC docs
      }
    }
  }

  /**
   * Validate the current OAuth2 authentication data through the expiration time
   * and renews via refresh_token if expired. Then returns the valid access token.
   *
   * @param bool $bForce TRUE if renewal should be done regardless of expiry.
   * @param string $strCode Optional code for first token (requires force).
   * @return string|bool FALSE on error, current or new access token otherwise.
   */
  private function getOAuth2Token( $bForce = FALSE, $strCode = "" ) {
    $access_token = get_option( "access_token" );
    $refresh_token = get_option( "refresh_token" );
    $expiry_time = get_option( "expiry_time" );

    if( empty($expiry_time) ||
        (current_time("timestamp") > $expiry_time - 10) ||
        $bForce ) {  // Check for 10 seconds earlier
      $urlRedirect = get_option('redirect_uri');

      if( empty($strCode) && !empty($refresh_token) ) {
        $oTokenData = $this->callToken(
          "", $refresh_token, $urlRedirect,
          $this->clientid, $this->clientsecret
        );
      }
      else {
        $oTokenData = $this->callToken(
          $strCode, "", $urlRedirect,
          $this->clientid, $this->clientsecret
        );
      }

      //error_log( "Token data: " . print_r($oTokenData, true) );

      if( $oTokenData !== FALSE ) {
        update_option( "access_token", $oTokenData->access_token );
        update_option( "refresh_token", $oTokenData->refresh_token );
        $expiry_time = current_time("timestamp") + $oTokenData->expires_in;
        update_option( "expiry_time", $expiry_time );
        return $oTokenData->access_token;
      }
      else {
        return FALSE;
      }
    }

    return $access_token;
  }

  /**
   * Initialize the Bluecode transaction through a register call.
   *
   * @param integer $order_id ID of order
   * @param string $response_body
   * @return int|mixed
   */
  public function initBlueCodePayment($order_id, &$response_body) {
    if( !$this->validateData() ) {
      return 0;
    }

    $access_token = $this->getOAuth2Token();

    try {
      $order = wc_get_order($order_id);
      $orderID = $order->get_id();
      $amount = $order->get_total();
      $currency = get_woocommerce_currency();

      $orderNumber = $order->get_order_number();
      $urlSuccess = add_query_arg(
        array(
          'type' => 'redirect',
          'state' => 'success',
          'id' => $orderID,
        ), add_query_arg('wc-api', $this->id, home_url('/')));
      $urlCancel = add_query_arg(
        array(
          'type' => 'redirect',
          'state' => 'cancel',
          'id' => $orderID,
        ), add_query_arg('wc-api', $this->id, home_url('/')));
      $urlFail = add_query_arg(
        array(
          'type' => 'redirect',
          'state' => 'fail',
          'id' => $orderID,
        ), add_query_arg('wc-api', $this->id, home_url('/')));
      $urlNotify = add_query_arg('type', 'notify', add_query_arg('wc-api', $this->id, home_url('/')));

      $api_purpose = $this->buildPurpose(array(
          "orderid" => $orderNumber,
          "customerid" => $order->get_customer_id(),
          "shopname" => get_bloginfo('name')
        )
      );

      $slipDateTime = date('Y-m-d\TH:i:sP');
      $aBody = array(
        "merchant_tx_id" => $orderID,
        "scheme" => "blue_code",
        "slip_date_time" => $slipDateTime,
        "currency" => $currency,
        "requested_amount" => round($amount * 100),
        "branch_ext_id" => $this->branch,
        "terminal" => get_site_url(),
        "slip" => $api_purpose,
        "merchant_callback_url" => $urlNotify,
        "return_url_success" => $urlSuccess,
        "return_url_failure" => $urlFail,
        "return_url_cancel" => $urlCancel,
        "source" => "ecommerce",
      );

      $args = array(
        'headers' => array(
          'Authorization' => "Bearer " . $access_token,
          'User-Agent' => $this->useragent
        ),
        'body' => $aBody,
        'sslverify' => TRUE,
      );

      $strUrl = $this->getApiUrl('register');
      $response = wp_remote_post($strUrl, $args);
      $response_body = json_decode($response["body"]);

      $response_body->urls = array(
        "success" => $urlSuccess,
        "fail" => $urlFail,
        "cancel" => $urlCancel
      );

      $http_code = $response["response"]["code"];

      return $http_code;
    }
    catch (Exception $e) {
      wc_add_notice(__('The plugin configuration data is incorrect', 'bluecode'), 'error');
      return 0;
    }

    return 0;
  }

  /**
   * Create Bluecode receipt
   *
   * @param integer $order_id ID of order
   * @return int|mixed
   */
  public function createBluecodeReceipt($order_id) {
    if( !$this->validateData() ) {
      return 0;
    }

    $access_token = $this->getOAuth2Token();

    try {
      $order = wc_get_order($order_id);
      //$orderID = $order->get_id();
      $amount = $order->get_total();
      $currency = get_woocommerce_currency();

      $transaction_id = $order->get_transaction_id();

      $items = array();
      /**
       * @var array of WC_Order_Item $orderItems
       */
      $orderItems = $order->get_items();
      foreach( $orderItems as $orderItem ) {
        $product = $orderItem->get_product();
        $newItem = array();
        $newItem["description"] = $orderItem->get_name();
        $newItem["ean"] = (string)$orderItem->get_product_id();
        $newItem["quantity"] = $orderItem->get_quantity();
        $newItem["single_amount"] = (object)[
          "amount" => $product->get_price() * 100,
          "currency" => $order->get_currency()
        ];
        $newItem["total_amount"] = (object)[
          "amount" => ($orderItem->get_total() + $orderItem->get_total_tax()) * 100,
          "currency" => $order->get_currency()
        ];
        $items[] = $newItem;
      }

      $receipt = (object)[
        "display_configuration" => [
          "show_quantity" => TRUE,
          "show_single_amount" => TRUE,
          "show_tax_category" => FALSE
        ],
        "invoice_number" => $order_id,
        "items" => $items,
        "merchant" => [
          "branch" => [
            "name" => $this->branch,
            "street" => get_option( 'woocommerce_store_address' ),
            "zip" => get_option( 'woocommerce_store_postcode' ),
            "city" => get_option( 'woocommerce_store_city' ),
            "website" => get_site_url(),
          ],
          "name" => get_bloginfo('name'),
        ],
        "notes" => __("Thank you for your purchase!", "bluecode"),
        "payments" => [(object)[
          "acquirer_tx_id" => $transaction_id,
          "paid" => [
            "amount" => $amount * 100,
            "currency" => $currency
          ],
          "type" => "Bluecode"
        ]],
        "signature" => [
          "qr_code_value" => $transaction_id,
//          "url" => "https => //rksv.bluecode.com/12345"
        ],
        "total_amount" => [
          "amount" => $amount * 100,
          "currency" => $currency
        ],
        "transaction_date_and_time" => date('Y-m-d\TH:i:sP')
      ];

      $aBody = array(
        "branch_ext_id" => $this->branch,
        "acquirer_tx_id" => $transaction_id,
        "receipt" => $receipt
      );

      $args = array(
        'headers' => array(
          'Authorization' => "Bearer " . $access_token,
          'Content-type' => "application/json",
          'User-Agent' => $this->useragent
        ),
        'body' => json_encode($aBody),
        'sslverify' => TRUE,
      );

      $strUrl = $this->getApiUrl('receipt' );
      $response = wp_remote_post($strUrl, $args);
      $response_body = json_decode($response["body"]);

      //error_log("Receipt request: " . print_r($args, true));
      //error_log("Receipt response: " . print_r($response_body, true));
      return $response["response"]["code"];
    }
    catch (Exception $e) {
      wc_add_notice(__('The plugin configuration data is incorrect', 'bluecode'), 'error');
      return 0;
    }
  }

  /**
   * @param $order_id
   * @param null $amount
   * @param string $reason
   * @param $response_body
   * @return int|mixed
   */
  public function refundBlueCodePayment($order_id, $amount = null, $reason = '', &$response_body) {
    if( !$this->validateData() ) {
      return 0;
    }

    $access_token = $this->getOAuth2Token();

    try {
      $order = wc_get_order($order_id);
      $transaction_id = $order->get_transaction_id();

      $aBody = array(
        "acquirer_tx_id" => $transaction_id,
        "amount" => round($amount * 100),
        "reason" => $reason
      );

      $args = array(
        'headers' => array(
          'Authorization' => "Bearer " . $access_token,
          'User-Agent' => $this->useragent
        ),
        'body' => $aBody,
        'sslverify' => TRUE,
      );

      $strUrl = $this->getApiUrl('refund');
      $response = wp_remote_post($strUrl, $args);

      $response_body = json_decode($response["body"]);
      $http_code = $response["response"]["code"];

      return $http_code;
    }
    catch (Exception $e) {
      wc_add_notice(__('The plugin configuration data is incorrect', 'bluecode'), 'error');
      return 0;
    }

    return 0;
  }

  /**
   * Check the API response
   *
   * @author Blue Code International AG
   * @package bluecode
   * @copyright Copyright (c) 2021, Blue Code International AG
   * @access public
   */
  public function check_response() {
    @ob_clean();

    if( !empty($_GET) ) {
      switch( $_GET["type"] ) {
        case 'redirect':
          do_action("valid_redirect_" . $this->id);
          break;
        case 'notify':
          do_action("valid_notify_" . $this->id);
          break;
        case 'bcoauth2':
          do_action("bc_oauth2_redirect");
        default:
          // Do nothing.
      }
    }
  }

  /**
   * Callback function for OAuth2 authorization. Retrieves the authorization code from the request data
   * and uses it to obtain an access token, which is then stored for later use.
   *
   * @return void
   */
  public function bc_oauth2_redirect() {
    $code = $_GET['code'];
    if( empty($code) ) {
      echo __("No code obtained, authorization failed", "bluecode");
      wp_die();
    }

    if( $this->getOAuth2Token( TRUE, $code ) !== FALSE ) {
      $this->displayHTMLMessage( __("Token successfully retrieved, authorization succeeded", "bluecode") );
    }
    else {
      $this->displayHTMLMessage( __("Token could not be retrieved, authorization failed", "bluecode"), TRUE );
    }

    wp_die();
  }

  /**
   * Display simple HTML page in popup window informing of authorization result.
   *
   * @param string $p_strText Message to display
   * @param boolean $p_bError TRUE if it is an error message, FALSE (default) for normal message.
   * @return void
   */
  private function displayHTMLMessage( $p_strText, $p_bError = FALSE ) {
    $strTitle = __("OAuth Confirmation", "bluecode");
    $strErrorClass = ($p_bError ? "error" : "");
    $strClose = __("Close window", "bluecode");
    $strImageUrl = plugins_url() . "/bluecode/img/icon_bluecode.png";
    $strHTML = <<<EOD
<!DOCTYPE html>
<html>
<head>
  <title>$strTitle</title>
  <style>
    .main {
      font-family: "Arial";
      margin: 50px;
      padding-top: 20px;
      border: darkgrey 2px solid;
      border-radius: 10px;
    }
    input {
      font-family: "Arial";
      font-size: medium;
    }
    .centered {
      text-align: center;
      margin: 20px;
      padding: 5px;
    }
    .center {
      text-align: center;
    }
    .error {
      color: red;
    }
  </style>
</head>
<body>
  <div class="center"><img src="$strImageUrl"/><br/></div>
  <div class="main centered $strErrorClass">
    $p_strText
    <div class="center"><input type="button" class="centered" value="$strClose" onclick="window.close()"></div>
  </div>
</body>
</html>
EOD;

    echo $strHTML;
  }

  /**
   * Place to forward the customer back to the shop after the payment transaction.
   *
   * @author Blue Code International AG
   * @package bluecode
   * @copyright Copyright (c) 2021, Blue Code International AG
   * @access public
   */
  public function do_bc_redirect() {
    global $woocommerce;

    // Get data from Bluecode
    $data = $_GET;
    $order = new WC_Order($data['id']);

    if( isset($data["state"]) && $data["state"] == 'cancel' ) {
      $this->callCancel( $data['id'] );
      $order->add_order_note( __("Order cancelled by customer", "bluecode") );
      $order->update_status('cancelled');
      wc_add_notice(__("Bluecode payment was cancelled by user", "bluecode"), 'error');
      $urlRedirect = $order->get_cancel_order_url();
    }
    else {
      $status = $this->callStatus($data['id']);

      if (empty($status) || $status->result != "OK") {
        wc_add_notice(__("Bluecode payment failed", "bluecode"), 'error');

        $order = new WC_Order($data['id']);
        $urlRedirect = $order->get_cancel_order_url();

        wp_redirect($urlRedirect);
      }

      $payment_key = $status->payment->merchant_tx_id;
      $acquirer_id = $status->payment->acquirer_tx_id;
      $order = new WC_Order($payment_key);

      $urlRedirect = $this->get_return_url($order);

      //error_log("Redirect Status: ". $status->payment->state);

      switch ($status->payment->state) {

        case "APPROVED":
        case "DECLINED":
        case "CANCELLED":
          if ($status->payment->state == "APPROVED") {
            $order->add_order_note(__("Order successfully paid with Bluecode, Transaction ID $acquirer_id", "bluecode"));
            if( $order->get_status() == "pending" ) {
              $order->payment_complete($acquirer_id);
              // Create receipt
              if( $this->createBluecodeReceipt($data['id']) != 200 ) {
                $order->add_order_note(__("Receipt could not be created, transaction ID $acquirer_id", "bluecode"));
              }
            }
            // Remove cart
            $woocommerce->cart->empty_cart();
          }
          else {
            if ($status->payment->state == "DECLINED") {
              wc_add_notice(__("Order payment via Bluecode declined", "bluecode"), 'error');
              $order->add_order_note(__("Order payment via Bluecode declined, Transaction ID $acquirer_id", "bluecode"));
              $order->update_status('cancelled');
              $urlRedirect = $order->get_cancel_order_url();
            }
            else {
              wc_add_notice(__("Order payment via Bluecode cancelled", "bluecode"), 'error');
              $order->add_order_note(__("Order payment via Bluecode cancelled, Transaction ID $acquirer_id", "bluecode"));
            }
            if( $order->get_status() == "pending" ) {
              $order->update_status('failed');
            }
          }
          break;

        case "ERROR":
        case "FAILURE":
          if (isset($status->payment->code)) {
            $errorcode = $status->payment->code;
          }
          else {
            $errorcode = __("General failure", "bluecode");
          }

          wc_add_notice(__("Order payment via Bluecode failed", "bluecode"), 'error');
          $order->add_order_note(__("Order payment via Bluecode failed, errorcode '$errorcode'", "bluecode"));
          if( $order->get_status() == "pending" ) {
            $order->update_status('failed');
          }
          break;

        default:
          $order->add_order_note(__("Order status is " . $status->payment->state));
      }
    }

    wp_redirect($urlRedirect);
  }

  /**
   * Place to notify to the shop of payment of this Bluecode transfer.
   *
   * @author Blue Code International AG
   * @package bluecode
   * @copyright Copyright (c) 2021, Blue Code International AG
   * @access public
   */
  public function do_bc_notify() {
    // Get data from Bluecode
    $data = file_get_contents('php://input');
    $oJsonData = json_decode($data);

    if( !is_object($oJsonData) || empty($oJsonData) ) {
      return;
    }

    $payment_key = $oJsonData->merchant_tx_id;
    $acquirer_id = $oJsonData->acquirer_tx_id;
    $order = new WC_Order($payment_key);

    if( isset($data["state"]) && $data["state"] == 'cancel' ) {
      $this->callCancel( $data['id'] );
      $order->add_order_note( __("Order cancelled by customer", "bluecode") );
      $order->update_status('cancelled');
      return;
    }
    elseif( isset($data['id']) ) {
      $status = $this->callStatus($data['id']);
      $oJsonData = $status;

      if( isset($oJsonData->state) ) {
        switch ($oJsonData->state) {
          case "APPROVED":
          case "DECLINED":
          case "CANCELLED":
            if ($oJsonData->state == "APPROVED") {
              $order->add_order_note(__("Order successfully paid with Bluecode, Transaction ID $acquirer_id", "bluecode"));
              if ($order->get_status() == "pending") {
                $order->payment_complete($acquirer_id);
                // Create receipt
                if( $this->createBluecodeReceipt($data['id']) != 200 ) {
                  $order->add_order_note(__("Receipt could not be created, transaction ID $acquirer_id", "bluecode"));
                }
              }
            }
            else {
              if ($oJsonData->state == "DECLINED") {
                $order->add_order_note(__("Order payment via Bluecode declined, Transaction ID $acquirer_id", "bluecode"));
              }
              else {
                $order->add_order_note(__("Order payment via Bluecode cancelled, Transaction ID $acquirer_id", "bluecode"));
              }
              if ($order->get_status() == "pending") {
                $order->update_status('failed');
              }
            }
            break;

          case "ERROR":
          case "FAILURE":
            if (isset($oJsonData->code)) {
              $errorcode = $oJsonData->code;
            }
            else {
              $errorcode = "GENERAL FAILURE";
            }

            $order->add_order_note(__("Order payment via Bluecode failed, errorcode '$errorcode'", "bluecode"));
            if ($order->get_status() == "pending") {
              $order->update_status('failed');
            }
            break;
        }
      }
    }
  }

  /**
   * Intermediate step called via AJAX in checkout when we are in bluecode mini app mode.
   * This initializes the payment with Bluecode and returns the relevant data so the
   * authorization can be done via JS.
   */
	static public function init_payment() {
    $aReturn = new stdClass();

    $bluecode = self::getInstance();
		$orderId = $_POST['orderid'];

    $http_code = $bluecode->initBlueCodePayment( $orderId, $response_body );
    if( $http_code == '200' && $response_body->result == "OK" ) {
      $aReturn->state = 1;
      $aReturn->error = '';
      $aReturn->data = array(
        "checkin" => $response_body->payment->checkin_code,
        "success" => $response_body->urls["success"],
        "fail" => $response_body->urls["fail"],
        "cancel" => $response_body->urls["cancel"]
      );
    }
    else {
      if( $response_body->result == "PROCESSING" ) {
        // Handle special processing case
        $returndata = null;
        if( $bluecode->handleProcessing($response_body, TRUE, $returndata) ) {
          $aReturn->state = 1;
          $aReturn->error = '';
          $aReturn->data = array(
            "checkin" => $returndata->payment->checkin_code,
            "success" => $returndata->payment->return_url_success,
            "fail" => $returndata->payment->return_url_failure,
            "cancel" => $returndata->payment->return_url_cancel
          );
          echo json_encode( $aReturn,  JSON_FORCE_OBJECT );
      		wp_die();
        }
        else {
          $aReturn->state = 0;
          $aReturn->error = $returndata->result . " (" . $returndata->error_code . ")";
        }
      }
      else {
        $aReturn->state = 0;
        $aReturn->error = $response_body->result . " (" . $response_body->error_code . ")";
      }
    }

    echo json_encode( $aReturn,  JSON_FORCE_OBJECT );
		wp_die();
	}

  /**
   * Register the JS scripts used by the plugin.
   */
  public function woocommerce_bluecode_register_scripts() {
    wp_register_script( 'bluecode_js_functions', plugins_url('bluecode/js/functions.js'), array(), rand(111, 9999) );
    wp_localize_script( 'bluecode_js_functions', 'bluecode_load', array( 'ajax_url' => admin_url( 'admin-ajax.php' )  ) );
    wp_enqueue_script('bluecode_js_functions');

    if( $this->isMiniApp() ) {
      // Mini-App mode
      wp_register_script('bluecode_js_bluecode', "https://res.bluecode.com/bluecode-js-sdk/release/1.0.4/bluecode-js-sdk.min.js");
      wp_enqueue_script('bluecode_js_bluecode');
    }
  }

  /**
   * Checks if the user-agent header is sent from the Bluecode browser or Huawei Wallet to
   * identify mini app environment.
   * @return bool TRUE if mini-app, FALSE for normal web app.
   */
  private function isMiniApp() {
    if( (stripos( $_SERVER['HTTP_USER_AGENT'], "bluecode" ) !== FALSE) ||   // Bluecode app
        (stripos( $_SERVER['HTTP_USER_AGENT'], "huawei wallet" ) !== FALSE) ) {  // Huawei wallet
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Intermediate checkout page used in mini-app mode to send payment via JS.
   *
   * @param integer $order_id
   */
  public function receipt( $order_id ) {
    $order = new WC_Order($order_id);
    $urlRedirect = $order->get_cancel_order_url();
 ?>
  <script>
    doInitPayment(<?=$order_id?>, '<?=$urlRedirect?>');
  </script>
<?php
  }
}