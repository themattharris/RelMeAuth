<?php

class tmhOAuth {
  function __construct($config) {
    $this->params = array();
    
    $this->config = array_merge(
      array(
        'consumer_key'    => '',
        'consumer_secret' => '',
        'user_token'      => '',
        'user_secret'     => '',
        'host'            => 'https://api.twitter.com',
        'v'               => '1',
        'debug'           => false,
        'force_nonce'     => false,
        'nonce'           => false, // used for checking signatures. leave as false for auto
        'force_timestamp' => false,
        'timestamp'       => false, // used for checking signatures. leave as false for auto
        'oauth_version'   => '1.0'
      ),
      $config
    );
  }

  // OAuth Generators
  function create_nonce($length=12, $include_time=true) {
    if ($this->config['force_nonce'] == false) {
      $sequence = array_merge(range(0,9), range('A','Z'), range('a','z'));
      $length = $length > count($sequence) ? count($sequence) : $length;
      shuffle($sequence);
      $this->config['nonce'] = md5(substr(microtime() . implode($sequence), 0, $length));
    }
  }

  function create_timestamp() {
    $this->config['timestamp'] = ($this->config['force_timestamp'] == false ? time() : $this->config['timestamp']);
  }

  function safe_encode($data) {
    if (is_array($data)) {
      return array_map(array($this, 'safe_encode'), $data);
    } else if (is_scalar($data)) {
      return str_ireplace(
        array('+', '%7E'),
        array(' ', '~'),
        rawurlencode($data)
      );
    } else {
      return '';
    }
  }
  
  function safe_decode($data) {
    if (is_array($data)) {
      return array_map(array($this, 'safe_decode'), $data);
    } else if (is_scalar($data)) {
      return rawurldecode($data);
    } else {
      return '';
    }
  }

  function get_defaults() {
    $defaults = array(
      'oauth_version'          => $this->config['oauth_version'],
      'oauth_nonce'            => $this->config['nonce'],
      'oauth_timestamp'        => $this->config['timestamp'],
      'oauth_consumer_key'     => $this->config['consumer_key'],
      'oauth_signature_method' => 'HMAC-SHA1',
    );

    if ( $this->config['user_token'] )
      $defaults['oauth_token'] = $this->config['user_token'];
    
    foreach ($defaults as $k => $v) {
      $_defaults[$this->safe_encode($k)] = $this->safe_encode($v);
    }

    return $_defaults;
  }

  function extract_params($body) {
    $kvs = explode('&', $body);
    $decoded = array();
    foreach ($kvs as $kv) {
      $kv = explode('=', $kv, 2);
      $kv[0] = $this->safe_decode($kv[0]);
      $kv[1] = $this->safe_decode($kv[1]);
      $decoded[$kv[0]] = $kv[1];
    }
    return $decoded;
  }
  
  /**
   * The HMAC-SHA1 signature method uses the HMAC-SHA1 signature algorithm as defined in [RFC2104]
   * where the Signature Base String is the text and the key is the concatenated values (each first
   * encoded per Parameter Encoding) of the Consumer Secret and Token Secret, separated by an '&'
   * character (ASCII code 38) even if empty.
   *   - Chapter 9.2 ("HMAC-SHA1")
   */
  function prepare_method($method) {
    $this->method = strtoupper($method);
  }

  function prepare_url($url) {
    $parts = parse_url($url);

    $port = @$parts['port'];
    $scheme = $parts['scheme'];
    $host = $parts['host'];
    $path = @$parts['path'];

    $port or $port = ($scheme == 'https') ? '443' : '80';

    if (($scheme == 'https' && $port != '443')
        || ($scheme == 'http' && $port != '80')) {
      $host = "$host:$port";
    }
    $this->url = "$scheme://$host$path";
  }

  function prepare_params($params) {
    $this->signing_params = array_merge($this->get_defaults(), (array)$params);
    
    // Remove oauth_signature if present
    // Ref: Spec: 9.1.1 ("The oauth_signature parameter MUST be excluded.")
    if (isset($this->signing_params['oauth_signature'])) {
      unset($this->signing_params['oauth_signature']);
    }

    // Parameters are sorted by name, using lexicographical byte value ordering.
    // Ref: Spec: 9.1.1 (1)
    uksort($this->signing_params, 'strcmp');

    // encode. Also sort the signed parameters from the POST parameters
    foreach ($this->signing_params as $k => $v) {
      $k = $this->safe_encode($k);
      $v = $this->safe_encode($v);
      $_signing_params[$k] = $v;
      $kv[] = "{$k}={$v}";
    }
    
    $this->auth_params = array_intersect_key($this->get_defaults(), $_signing_params);
    if (isset($_signing_params['oauth_callback'])) {
      $this->auth_params['oauth_callback'] = $_signing_params['oauth_callback'];
      unset($_signing_params['oauth_callback']);
    }
    $this->request_params = array_diff_key($_signing_params, $this->get_defaults());
    $this->signing_params = implode('&', $kv);
  }

  function prepare_signing_key() {
    $this->signing_key = $this->safe_encode($this->config['consumer_secret']) . '&' . $this->safe_encode($this->config['user_secret']);
  }

  function prepare_base_string() {
    $base = array(
      $this->method,
      $this->url,
      $this->signing_params
    );
    $this->base_string = implode('&', $this->safe_encode($base));
  }

  function prepare_auth_header() {
    $this->headers = array();
    uksort($this->auth_params, 'strcmp');
    foreach ($this->auth_params as $k => $v) {
      $kv[] = "{$k}=\"{$v}\"";
    }
    $this->auth_header = 'Authorization: OAuth ' . implode(', ', $kv); 
    $this->headers[] = $this->auth_header;
  }

  function sign($method, $url, $params, $useauth) {
    $this->prepare_method($method);
    $this->prepare_url($url);
    $this->prepare_params($params);
    
    if ($useauth) {
      $this->prepare_base_string();
      $this->prepare_signing_key();

      $this->auth_params['oauth_signature'] = $this->safe_encode(
        base64_encode(
          hash_hmac(
            'sha1', $this->base_string, $this->signing_key, true
      )));

      $this->prepare_auth_header();
    }
  }

  function request($method, $url, $params=array(), $useauth=true) {
    $this->create_nonce();
    $this->create_timestamp();
    
    $this->sign($method, $url, $params, $useauth);
    $this->curlit();
  }
  
  function url($request, $format='json') {
    $format = strlen($format) > 0 ? ".$format" : '';
    return implode('/', array(
      $this->config['host'],
      $this->config['v'],
      $request . $format
    ));
  }

  function curlHeader($ch, $header) {
    $i = strpos($header, ':');
    if ( ! empty($i) ) {
      $key = str_replace('-', '_', strtolower(substr($header, 0, $i)));
      $value = trim(substr($header, $i + 2));
      $this->response['headers'][$key] = $value;
    }
    return strlen($header);
  }

  function curlit() {
    switch ($this->method) {
      case 'GET':
        if ( ! empty($this->request_params)) {
          foreach ($this->request_params as $k => $v) {
            $params[] = $this->safe_encode($k) . '=' . $this->safe_encode($v);
          }
          $qs = implode('&', $params);
          $this->url = strlen($qs) > 0 ? $this->url . '?' . $qs : $this->url;
          $this->request_params = array();
        }
        break;
    }

    $c = curl_init();
    curl_setopt($c, CURLOPT_USERAGENT, "themattharris' HTTP Client");
    curl_setopt($c, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($c, CURLOPT_TIMEOUT, 30);
    curl_setopt($c, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($c, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($c, CURLOPT_URL, $this->url);
    curl_setopt($c, CURLOPT_HEADERFUNCTION, array($this, 'curlHeader'));
    curl_setopt($c, CURLOPT_HEADER, FALSE);
    curl_setopt($c, CURLINFO_HEADER_OUT, true);
    switch ($this->method) {
      case 'GET':
        break;
      case 'POST':
        curl_setopt($c, CURLOPT_POST, TRUE);
        break;
      default:
        curl_setopt($c, CURLOPT_CUSTOMREQUEST, $this->method);
    }
    if ( ! empty($this->request_params)) {
      foreach ($this->request_params as $k => $v) {
        $ps[] = "{$k}={$v}"; 
      }
      $this->request_params = implode('&', $ps);
      curl_setopt($c, CURLOPT_POSTFIELDS, $this->request_params);
    } else {
      // CURL will set length to -1 when there is no data, which breaks Twitter
      $this->headers[] = 'Content-Type:';
      $this->headers[] = 'Content-Length:';
    }
    // CURL will set this to Expect: 100-Continue which Twitter rejects
    $this->headers[] = 'Expect:';
    if ( ! empty($this->headers)) {
      curl_setopt($c, CURLOPT_HTTPHEADER, $this->headers);
    }
    $response = curl_exec($c);
    $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
    $info = curl_getinfo($c);
    curl_close ($c);

    $this->response['code'] = $code;
    $this->response['response'] = $response;
    $this->response['info'] = $info;
  }
  
  function pr($obj) {
    echo '<pre style="word-wrap: break-word">';
    if ( is_object($obj) )
      print_r($obj);
    elseif ( is_array($obj) )
      print_r($obj);
    else
      echo $obj;
    echo '</pre>';
  }
}

?>