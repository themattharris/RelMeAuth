<?php

ob_start(); include 'cassis.js'; ob_end_clean();
include dirname(__FILE__) . '/OAuth.php';
include dirname(__FILE__) . '/config.php';

class relmeauth {
  var $matched_rel = false;

  function __construct() {
    session_start();
  }
  
  function is_loggedin() {
    // TODO: should have a timestamp expiry in here.
    return (isset($_SESSION['relmeauth']['name']));
  }

  function main($user_url) {
    $this->user_url = $user_url;
    
    // get the rel mes from the given site
    $this->source_rels = $this->discover( $this->user_url );

    // see if any of the relmes match back - we check the rels in the order
    // they are listed in the HTML
    $has_match = $this->process_rels();

    if ( $has_match ) {
      return $this->authenticate();
    } else {
      return false;
    }
  }

  /**
   * Wrapper for the OAuth authentication process
   *
   * @return void
   * @author Matt Harris
   */
  function authenticate() {
    global $providers;

    foreach ($this->source_rels as $host => $details) :
      $provider = parse_url($host);
      if ( $config = $providers[ $provider['host'] ] ) {
        // request token
        $ok = $this->oauth_request( array(
            'token'  => $config['keys']['ctoken'],
            'secret' => $config['keys']['csecret']
          ),
          false,
          'GET',
          $config['urls']['request'],
          $params = array(
            'oauth_callback' => $this->here()
          )
        );
        
        if ($ok) {
          // authenticate
          $token = OAuthUtil::parse_parameters($this->response['body']);

          // need these later
          $_SESSION['relmeauth']['provider'] = $provider['host'];
          $_SESSION['relmeauth']['secret'] = $token['oauth_token_secret'];
          $_SESSION['relmeauth']['token'] = $token['oauth_token'];
          $url = $config['urls']['auth'] . "?oauth_token={$token['oauth_token']}";
          $this->redirect($url);
          die;
        }
        return false;
      } else {
        return false;
      }
    endforeach; // source_rels
  }
  
  function complete_oauth( $verifier ) {
    global $providers;
    
    if ( $config = $providers[$_SESSION['relmeauth']['provider']] ) {
      $ok = $this->oauth_request( array(
          'token'  => $config['keys']['ctoken'],
          'secret' => $config['keys']['csecret']
        ),
        array(
          'token'  => $_SESSION['relmeauth']['token'],
          'secret' => $_SESSION['relmeauth']['secret'],
        ),
        'GET',
        $config['urls']['access'],
        $params = array(
          'oauth_verifier' => $verifier
        )
      );
      unset($_SESSION['relmeauth']['token']);
      unset($_SESSION['relmeauth']['secret']);
      $this->error('There was a problem communicating with your provider. Please try later.');
      
      if ($ok) {
        // get the users token and secret
        $_SESSION['relmeauth']['access'] = OAuthUtil::parse_parameters($this->response['body']);
        
        // FIXME: validate this is the user who requested. 
        // At the moment if I use another users URL that rel=me to Twitter for example, it
        // will work for me - because all we do is go 'oh Twitter, sure, login there and you're good to go
        // the rel=me bit doesn't get confirmed it belongs to the user
        $this->verify( $config );
        $this->redirect();
      }
    } else {
      $this->error('None of your providers are supported.');
    }
    return false;
  }
  
  function verify( &$config ) {
    $ok = $this->oauth_request( array(
        'token'  => $config['keys']['ctoken'],
        'secret' => $config['keys']['csecret']
      ),
      array(
        'token'  => $_SESSION['relmeauth']['access']['oauth_token'],
        'secret' => $_SESSION['relmeauth']['access']['oauth_token_secret'],
      ),
      'GET',
      $config['urls']['verify']
    );
    
    $creds = json_decode( $this->response['body'], true );
    if ( $creds[ $config['verify']['url'] ] == $_SESSION['relmeauth']['url'] ) {
      $_SESSION['relmeauth']['name'] = $creds[ $config['verify']['name'] ];
      return true;
    } else {
      // destroy everything
      unset($_SESSION['relmeauth']);
      $this->error('This isn\'t you!');
      return false;
    }
  }
  
  function error($message) {
    if ( ! isset( $_SESSION['relmeauth']['error'] ) ) {
      $_SESSION['relmeauth']['error'] = $message;
    } else {
      $_SESSION['relmeauth']['error'] .= ' ' . $message;
    }
  }
  
  /**
   * Print the last error message if there is one.
   *
   * @return void
   * @author Matt Harris
   */
  function printError() {
    if ( isset( $_SESSION['relmeauth']['error'] ) ) {
      echo '<div id="error">so, ummm, yeah. ' .
        $_SESSION['relmeauth']['error'] . ' - Sorry</div>';
      unset($_SESSION['relmeauth']['error']);
    }
  }

  /**
   * Go through the rel=me URLs obtained from the users URL and see
   * if any of those sites contain a rel=me which equals this user URL.
   *
   * @return true if a match is found, false otherwise.
   * @author Matt Harris
   */
  function process_rels() {
    foreach ( $this->source_rels as $url => $text ) {
      $othermes = $this->discover( $url, false );
      if ( is_array( $othermes ) && in_array( $this->user_url, $othermes ) ) {
        $this->matched_rel = $url;
        return true;
      }
    }
    $this->error('No rels matched');
    return false;
  }

  /**
   * Does the job of discovering rel="me" urls
   *
   * @return array of rel="me" urls for the given source URL
   * @author Matt Harris
   */
  function discover($source_url, $titles=true) {
    if ( ! self::curlit($source_url) )
      return false;
      
    $simple_xml_element = self::toXML($this->response['body']);
    if ( ! $simple_xml_element ) {
      $response = self::tidy($this->response['body']);
      if ( ! $response ) {
        $this->error('I couldn\'t tidy that up.');
        return false;
      }
      $simple_xml_element = self::toXML($response);
      if ( ! $simple_xml_element ) {
        $this->error('Looks like I can\'t do anything with the webpage you suggested.');
        return false;
      }
    }

    // extract URLs with rel=me in them
    $xpath = xphasrel('me');
    $relmes = $simple_xml_element->xpath($xpath);
    $base = self::real_url(
      self::html_base_href($simple_xml_element), $source_url
    );

    // get anything?
    if ( empty($relmes) ) {
      return false;
    }

    // clean up the relmes
    foreach ($relmes as $rel) {
      $title = (string) $rel->attributes()->title;
      $url = (string) $rel->attributes()->href;
      $url = self::real_url($base, $url);
      if (empty($url))
        continue;
      $title = empty($title) ? $url : $title;
      if ( $titles ) {
        $urls[ $url ] = $title;
      } else {
        $urls[] = $url;
      }
    }
    return $urls;
  }

  /**
   * Works out the base URL for the page for use when calculating relative and
   * absolute URLs. This function looks for the base element in the head of
   * the document and if found uses that as the html base href.
   *
   * @param string $simple_xml_element the SimpleXML representing the obtained HTML
   * @return the new base URL if found or empty string otherwise
   * @author Tantek Çelik
   */
  function html_base_href($simple_xml_element) {
    if ( ! $simple_xml_element)
      return '';

    $base_elements = $simple_xml_element->xpath('//head//base[@href]');
    return ( $base_elements && ( count($base_elements) > 0 ) ) ?
             $base_elements[0]->getAttribute('href') :
             '';
  }

  /**
   * Calculates the normalised URL for a given URL and base href. Absolute and
   * relative URLs are supported as well as full URIs.
   *
   * @param string $base the base href
   * @param string $url the URL to be normalised
   * @return void
   * @author Matt Harris and Tantek Çelik
   */
  function real_url($base, $url) {
    // has a protcol, and therefore assumed domain
    if (stripos( $url, '://' ) !== FALSE) {
      return $url;
    }

    // absolute URL
    if ( $url[0] == '/' ) {
      $url_bits = parse_url($base);
      $host = $url_bits['scheme'] . '://' . $url_bits['host'];
      return $host . $url;
    }

    // inspect base, check we have the directory
    $path = substr($base, 0, strrpos($base, '/')) . '/';
    // relative URL

    // explode the url with relatives in it
    $url = explode('/', $path.$url);

    // remove the domain as we can't go higher than that
    $base = $url[0].'//'.$url[2].'/';
    array_splice($url, 0, 3);

    // process each folder
    // for every .. remove the previous non .. in the array
    $keys = array_keys($url, '..');
    foreach( $keys as $idx => $dir ) {
      // work out the new offset for ..
      $offset = $dir - ($idx * 2 + 1);

      if ($offset < 0 && $url[0] == '..') {
        array_splice($url, 0, 1);
      } elseif ( $offset < 0 ) {
        // need to know where the new .. are
        return self::real_url($base, implode('/', $url));
      } else {
        array_splice($url, $offset, 2);
      }
    }
    $url = implode('/', $url);
    $url = str_replace('./', '', $url);
    return $base . $url;
  }

  /**
   * try and convert the string to SimpleXML
   *
   * @param string $str the HTML
   * @return SimpleXMLElement or false on fail
   * @author Matt Harris
   */
  function toXML($str) {
    $xml = false;

    try {
      $xml = @ new SimpleXMLElement($str);
    } catch (Exception $e) {
      if ( stripos('String could not be parsed as XML', $e->getMessage()) ) {
        return false;
      }
    }
    return $xml;
  }

  /**
   * Run tidy on the given string if it is installed. This function configures
   * tidy to support HTML5.
   *
   * @param string $html the html to run through tidy.
   * @return the tidied html or false if tidy is not installed.
   * @author Matt Harris
   */
  function tidy($html) {
    if ( class_exists('tidy') ) {
      $tidy = new tidy();
    	$config = array(
    	  'bare'            => TRUE,
    	  'clean'           => TRUE,
    	  'indent'          => TRUE,
        'output-xhtml'    => TRUE,
        'wrap'            => 200,
        'hide-comments'   => TRUE,
        'new-blocklevel-tags' => implode(' ', array(
          'header', 'footer', 'article', 'section', 'aside', 'nav', 'figure',
        )),
        'new-inline-tags' => implode(' ', array(
          'mark', 'time', 'meter', 'progress',
        )),
      );
    	$tidy->parseString( $html, $config, 'utf8' );
    	$tidy->cleanRepair();
    	$html = str_ireplace( '<wbr />','&shy;', (string)$tidy );
      unset($tidy);
      return $html;
    } else {
      $this->error('no tidy :(');
      // need some other way to clean here. html5lib?
      return $html;
    }
    return false;
  }

  function oauth_request($consumer, $user, $method, $url, $params=array()) {
    $consumer = new OAuthConsumer($consumer['token'], $consumer['secret']);
    if ($user !== false) {
      $user = new OAuthConsumer($user['token'], $user['secret']);
    }
    $enc = new OAuthSignatureMethod_HMAC_SHA1();
    $req = OAuthRequest::from_consumer_and_token($consumer, $user, $method, $url, $params);
    $req->sign_request($enc, $consumer, $user);
    
    return $this->curlit( $req->to_url() );
  }

  function curlHeader($ch, $header) {
    $i = strpos($header, ':');
    if (!empty($i)) {
      $key = str_replace('-', '_', strtolower(substr($header, 0, $i)));
      $value = trim(substr($header, $i + 2));
      $this->response['headers'][$key] = $value;
    }
    return strlen($header);
  }
  
  /**
   * Curl wrapper function
   *
   * @param string $url the URL to request
   * @param string $method HTTP request method
   * @param string $data post or get data
   * @param string $user username for request if required
   * @param string $pass password for request if required
   * @param int $connect_timeout the time allowed for Curl to connect to a URL
   *    in seconds
   * @param int $request_timeout the time allowed for Curl to receive a
   *    response from a URL, in seconds.
   * @return int the HTTP status code for the request
   * @author Matt Harris
   */
   function curlit($url, $method='GET', $data=NULL, $user=NULL, $pass=NULL, $connect_timeout=5,
      $request_timeout=10) {
        
     unset($this->response);
     
     $c = curl_init();
     curl_setopt($c, CURLOPT_USERAGENT, 'themattharris');
     curl_setopt($c, CURLOPT_CONNECTTIMEOUT, $connect_timeout);
     curl_setopt($c, CURLOPT_TIMEOUT, $request_timeout);
     curl_setopt($c, CURLOPT_RETURNTRANSFER, TRUE);
     curl_setopt($c, CURLOPT_SSL_VERIFYPEER, FALSE);
     curl_setopt($c, CURLOPT_URL, $url);
     curl_setopt($c, CURLOPT_HEADERFUNCTION, array($this, 'curlHeader'));
     curl_setopt($c, CURLOPT_HEADER, FALSE);
     switch ($method) {
       case 'GET':
         break;
       case 'POST':
         curl_setopt($c, CURLOPT_POST, TRUE);
         break;
       default:
         curl_setopt($c, CURLOPT_CUSTOMREQUEST, $method);
     }
     if ( ! empty($user) AND ! empty($pass) ) {
       curl_setopt($c, CURLOPT_USERPWD, $user . ":" . $pass);
     }
     if ( ! empty($data)) {
       curl_setopt($c, CURLOPT_POSTFIELDS, $data);
     }
     $this->response['body'] = curl_exec($c);
     $this->response['code'] = curl_getinfo($c, CURLINFO_HTTP_CODE);
     $this->response['info'] = curl_getinfo($c);
     curl_close ($c);
     
     return $this->response['code'] == 200;
   }

   function redirect($url=false) {
     $url = ! $url ? $this->here() : $url;
     header( "Location: $url" );
     die;
   }
   
   function here($withqs=false) {
     $url = sprintf('%s://%s%s',
       $_SERVER['SERVER_PORT'] == 80 ? 'http' : 'https',
       $_SERVER['SERVER_NAME'],
       $_SERVER['REQUEST_URI']
     );
     $parts = parse_url($url);
     $url = sprintf('%s://%s%s',
       $parts['scheme'],
       $parts['host'],
       $parts['path']
     );
     if ($withqs) {
       $url .= '?' . $url['query'];
     }
     return $url;
   }
}

?>