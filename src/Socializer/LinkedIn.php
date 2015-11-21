<?php
namespace Socializer;

/**
 * Provides a basic PHP interface to the LinkedIn API.
 *
 * 1. Initialise a new object with API credentials, and a callback URL that the user will return to
 * $linkedin = new Socializer\LinkedIn(['api_key' => 'xxx', 'api_secret' => 'xxx', 'callback_url' => 'http://xxx']);
 *
 * 2. Provide the login URL to the user and specify the scopes required
 * <a href="<?= $linkedin->getAuthUrl(['scope'=>'r_basicprofile w_share']) ?>">Login to LinkedIn</a>
 *
 * 3. When the user returns via your callback URL, swap the included 'code' parameter for a token
 * $token = $linkedin->exchangeCodeForAccessToken($_GET['code']);
 *
 * 4. Save the token somewhere (eg. DB), it can be used for interacting with the LinkedIn API later:
 * $linkedin->setAccessToken($token);
 *
 * 5. When you have an access token, you can use the API
 * $profile = $linkedin->api('GET', '/people/~');
 * $companies = $linkedin->api('GET', '/companies');
 * $linkedin->api('POST', '/companies/XXXXXXX/shares', $payload);
 *
 * @author Jodie Dunlop <jodiedunlop@gmail.com>
 * @copyright Copyright (C) 2015 i4U - Creative Internet Consultants Pty Ltd.
 */
use InvalidArgumentException;
use RuntimeException;

class LinkedIn
{
    /** Version */
    const VERSION = '1.0';

    /** API base URL to which will be prepended to endpoints */
    public static $API_BASE_URL = 'https://api.linkedin.com/v1';

    /** @var string LinkedIn OAuth URL */
    public static $OAUTH_URL = 'https://www.linkedin.com/uas/oauth2';

    /** Default options for curl extension */
    public static $CURL_OPTS = array(
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_USERAGENT => 'socializer-linkedin',
        CURLOPT_RETURNTRANSFER => true,
    );

    /** @var resource|null  */
    protected $curl = null;

    /** @var array */
    protected $config = array();

    /** @var string|null  */
    protected $accessToken = null;

    /** @var int|null */
    protected $accessTokenExpires = null;

    /** @var string|null */
    protected $authState = null;

    /**
     * @param array $args
     */
    public function __construct(array $args)
    {
        $this->init($args);
    }

    /**
     * @param array $args
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    protected function init(array $args)
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('Required PHP CURL extension is not loaded');
        }

        $args = array_merge(array(
            'api_key' => null,
            'api_secret' => null,
            'callback_url' => false,
        ), $args);

        if (empty($args['api_key'])) {
            throw new InvalidArgumentException('Required parameter: api_key');
        }

        if (empty($args['api_secret'])) {
            throw new InvalidArgumentException('Required parameter: api_secret');
        }

        $this->config = $args;
    }

    /**
     * Get the login url, pass scope to request specific permissions
     *
     * @param array $args
     * @return string $url
     */
    public function getAuthUrl(array $args = array())
    {
        $args = array_merge(array(
            'response_type' => 'code',
            'client_id' => $this->config['api_key'],
            'scope' => 'r_basicprofile w_share',
            'state' => uniqid('', true),
            'redirect_uri' => isset($this->config['callback_url']) ? $this->config['callback_url'] : null,
        ), $args);

        $url = self::$OAUTH_URL . '/authorization?' . http_build_query($args);
        $this->setAuthState($args['state']);

        return $url;

    }

    /**
     * Exchange the authorization code for an access token
     *
     * @param string $code Authorization code to exchange for a token
     * @param array $params Optional parameters for sending to the LinkedIn API
     * @return string Token
     */
    public function exchangeCodeForAccessToken($code, array $params = array())
    {
        $params = array_merge(array(
            'grant_type' => 'authorization_code',
            'client_id' => $this->config['api_key'],
            'client_secret' => $this->config['api_secret'],
            'redirect_uri' => $this->config['callback_url'],
        ), $params);

        if (empty($code)) {
            throw new InvalidArgumentException('Supplied "code" argument is empty');
        }

        $data = array_merge($params, array('code' => $code));
        $endpoint = $this->makeUrl(self::$OAUTH_URL . '/accessToken');
        $response = $this->makeRequest('POST', $endpoint, $data, array('request_format' => 'urlencoded'));

        // Cache token for future requests
        $this->accessToken = $response['access_token'];
        $this->accessTokenExpires = $response['expires_in'];

        return $this->accessToken;
    }

    /**
     * Get token expiration timestamp
     *
     * @return int access token expiration time -
     */
    public function getAccessTokenExpiration()
    {
        return $this->accessTokenExpires;

    }

    /**
     * Returns the current access token (if any).  This can be set either with exchangeCodeForAccessToken() or via the
     * setAccessToken() method.
     *
     * @return string|null
     */
    public function getAccessToken()
    {
        return $this->accessToken;
    }

    /**
     * Whether an access token has been set (either through a code exchange or explicit call to setAccessToken())
     *
     * @return bool
     */
    public function hasAccessToken()
    {
        return !empty($this->accessToken);
    }

    /**
     * Set the access token manually
     *
     * @param string $token
     * @param int|null $expiration
     * @return string
     */
    public function setAccessToken($token, $expiration = null)
    {
        error_log('setAccessToken: ' . $token);
        $token = trim($token);
        if (empty($token)) {
            throw new InvalidArgumentException('Invalid access token');
        }

        $this->accessToken = $token;
        if ($expiration !== null) {
            $this->accessTokenExpires = $expiration;
        }

        return $this->accessToken;
    }

    /**
     * Send a request to the LinkedIn API
     *
     * @param string $method HTTP Request Method Verb (GET|POST|DELETE|PUT)
     * @param string $path
     * @param mixed $data
     * @param array $args
     * @return array
     */
    public function api($method, $path, $data = null, array $args = array())
    {
        // Build query to be appended to endpoint
        $params = array();
        if ($this->accessToken) {
            $params['oauth2_access_token'] = $this->accessToken;
        }
        $url = $this->makeUrl(self::$API_BASE_URL . $path, $params);

        return $this->makeRequest($method, $url, $data, $args);
    }

    /**
     * Make an HTTP request and return the response. Used by the api() method internally.
     *
     * @param $method
     * @param $url
     * @param null $data
     * @param array $args
     * @return mixed|\SimpleXmlElement
     */
    protected function makeRequest($method, $url, $data = null, array $args = array())
    {
        $args = array_merge(array(
            'request_format' => 'json',
            'response_format' => 'json',
        ), $args);

        $headers = array();

        // Set appropriate headers depending on format
        switch (strtolower($args['request_format'])) {
            case 'urlencoded':
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                $data = is_array($data) ? http_build_query($data) : $data;
                break;
            case 'json':
                $headers[] = 'Content-Type: application/json';
                if (!empty($data) && (is_object($data) || is_array($data))) {
                    $data = json_encode($data);
                }
                break;
            case 'xml':
                $headers[] = 'Content-Type: text/xml';
                if (!empty($data) && is_object($data) && $data instanceOf \SimpleXmlElement) {
                    $data = $data->asXML();
                }
                break;
            default:
                if (!empty($data) && is_array($data)) {
                    //$data = http_build_query($data, null, '&');
                }
                break;
        }

        // Set appropriate headers depending on format
        switch (strtolower($args['response_format'])) {
            case 'json':
                $headers[] = 'x-li-format: json';
                break;
            case 'xml':
                $headers[] = 'x-li-format: xml';
                break;
            default:
                break;
        }

        $curl_opts = self::$CURL_OPTS;
        $curl_opts[ CURLOPT_CUSTOMREQUEST ] = strtoupper($method);
        $curl_opts[ CURLOPT_RETURNTRANSFER ] = true;
        $curl_opts[ CURLOPT_URL ] = $url;
        $curl_opts[ CURLOPT_SSL_VERIFYPEER ] = false;

        if (!empty($headers)) {
            $curl_opts[ CURLOPT_HTTPHEADER ] = $headers;
        }

        if (!empty($data)) {
            $curl_opts[ CURLOPT_POST ] = true;
            $curl_opts[ CURLOPT_POSTFIELDS ] = $data;
            if (is_scalar($data)) {
                $headers[] = 'Content-Length: ' . strlen($data);
            }
        }

        error_log('REQUEST URL: ' . $curl_opts[ CURLOPT_URL ]);
        error_log('REQUEST: ' . print_r($curl_opts[ CURLOPT_POSTFIELDS ], true));
        $ch = curl_init();
        curl_setopt_array($ch, $curl_opts);
        $response = curl_exec($ch);
        error_log('RESPONSE: ' . print_r($response, true));

        if ($response === false) {
            $errno = curl_errno($ch);
            $message = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException(sprintf('CURL request error [%d]: %s', $errno, $message));
        }

        curl_close($ch);

        switch (strtolower($args['response_format'])) {
            case 'json':
                $response = json_decode($response, true);
                if (isset($response['status']) && ($response['status'] < 200 || $response['status'] > 300)) {
                    throw new RuntimeException('Request Error: ' . $response['message'] . '. Raw Response: ' . print_r($response, true));
                }
                break;
            case 'xml':
                $response = new \SimpleXmlElement($response);
                // TODO: Check response status
                // TODO: Convert to a standard object with json_decode(json_encode($xml)) ?
                break;
            default:
                // Do nothing to response
                break;
        }


        return $response;
    }

    /**
     * Set the unique state identifier
     * @param string $state
     */
    protected function setAuthState($state)
    {
        $this->authState = $state;
    }


    /**
     * Get the current auth state identifier
     * @return string|null
     */
    protected function getAuthState()
    {
        return $this->authState;
    }

    /**
     * Utility function for building a URL from a base URL (which may include a query) and an array of optional
     * parameters.
     *
     * @param string $url
     * @param array $params
     * @return string
     */
    protected function makeUrl($url, array $params = array())
    {
        if (is_array($params) && count($params) > 0) {
            $url .= strpos($url, '?') === false ? '?' : '&';
            //$url .= http_build_query($params, null, '&', PHP_QUERY_RFC3986);
            $url .= http_build_query($params);
        }

        return $url;
    }
}
