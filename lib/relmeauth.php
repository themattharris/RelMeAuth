<?php

ob_start(); include dirname(__FILE__) . 'cassis/cassis.js'; ob_end_clean();
require dirname(__FILE__) . '/tmhOAuth/tmhOAuth.php';
require dirname(__FILE__) . '/config.php';

class relmeauth {
  var $matched_rel = false;

  function __construct() {
    session_start();
    $this->tmhOAuth = new tmhOAuth(array());
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
    if ($this->process_rels()) {
      return $this->authenticate();
    } else {
      // error message will have already been set
      return false;
    }
  }

  function request($keys, $method, $url, $params=array(), $useauth=true) {
    $this->tmhOAuth = new tmhOAuth(array());

    $this->tmhOAuth->config['consumer_key']    = $keys['consumer_key'];
    $this->tmhOAuth->config['consumer_secret'] = $keys['consumer_secret'];
    $this->tmhOAuth->config['user_token']      = @$keys['user_token'];
    $this->tmhOAuth->config['user_secret']     = @$keys['user_secret'];
    $code = $this->tmhOAuth->request(
      $method,
      $url,
      $params,
      $useauth
    );

    return ( $code == 200 );
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
      if ( ! array_key_exists($provider['host'], $providers) )
        continue;

      $config = $providers[ $provider['host'] ];
      $ok = $this->request(
        $config['keys'],
        'GET',
        $config['urls']['request'],
        array(
          'oauth_callback' => $this->here()
        )
      );

      if ($ok) {
        // need these later
        $user = $this->tmhOAuth->extract_params($this->tmhOAuth->response['response']);

        $_SESSION['relmeauth']['provider'] = $provider['host'];
        $_SESSION['relmeauth']['secret']   = $user['oauth_token_secret'];
        $_SESSION['relmeauth']['token']    = $user['oauth_token'];
        $url = $config['urls']['auth'] . "?oauth_token={$user['oauth_token']}";
        $this->redirect($url);
      } else {
        $this->error("There was a problem communicating with {$provider['host']}. Error {$this->tmhOAuth->response['code']}. Please try later.");
      }
    endforeach; // source_rels

    $this->error('None of your providers are supported. Tried ' . implode(', ', array_keys($providers)));
    return false;
  }

  function complete_oauth( $verifier ) {
    global $providers;

    if ( ! array_key_exists($_SESSION['relmeauth']['provider'], $providers) ) {
      $this->error('None of your providers are supported.');
      return false;
    }

    $config = $providers[$_SESSION['relmeauth']['provider']];
    $ok = $this->request(
      array_merge(
        $config['keys'],
        array(
          'user_token' => $_SESSION['relmeauth']['token'],
          'user_secret' => $_SESSION['relmeauth']['secret']
        )
      ),
      'GET',
      $config['urls']['access'],
      array(
        'oauth_verifier' => $verifier
      )
    );
    unset($_SESSION['relmeauth']['token']);
    unset($_SESSION['relmeauth']['secret']);

    if ($ok) {
      // get the users token and secret
      $_SESSION['relmeauth']['access'] = $this->tmhOAuth->extract_params($this->tmhOAuth->response['response']);

      // FIXME: validate this is the user who requested.
      // At the moment if I use another users URL that rel=me to Twitter for example, it
      // will work for me - because all we do is go 'oh Twitter, sure, login there and you're good to go
      // the rel=me bit doesn't get confirmed it belongs to the user
      $this->verify( $config );
      $this->redirect();
    }
    $this->error("There was a problem authenticating with {$provider['host']}. Error {$this->tmhOAuth->response['code']}. Please try later.");
    return false;
  }

  function verify( &$config ) {
    global $providers;
    $config = $providers[$_SESSION['relmeauth']['provider']];

    $ok = $this->request(
      array_merge(
        $config['keys'],
        array(
          'user_token' => $_SESSION['relmeauth']['access']['oauth_token'],
          'user_secret' => $_SESSION['relmeauth']['access']['oauth_token_secret']
        )
      ),
      'GET',
      $config['urls']['verify']
    );

    $creds = json_decode($this->tmhOAuth->response['response'], true);

    $given = self::normalise_url($_SESSION['relmeauth']['url']);
    $found = self::normalise_url($creds[ $config['verify']['url'] ]);

    $_SESSION['relmeauth']['debug']['verify']['given'] = $given;
    $_SESSION['relmeauth']['debug']['verify']['found'] = $found;

    if ( $given == $found ) {
      $_SESSION['relmeauth']['name'] = $creds[ $config['verify']['name'] ];
      return true;
    } else {
      // destroy everything
      $provider = $_SESSION['relmeauth']['provider'];
      // unset($_SESSION['relmeauth']);
      $this->error("That isn't you! If it really is you, try signing out of {$provider}");
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
      echo '<div id="error">' .
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
    if ( ! is_array($this->source_rels)) {
      $this->error('No rels found');
      return false;
    }
    foreach ( $this->source_rels as $url => $text ) {
      $othermes = $this->discover( $url, false );
      $_SESSION['relmeauth']['debug']['source_rels'][$url] = $othermes;
      if ( is_array( $othermes ) ) {
        $othermes = array_map(array('relmeauth', 'normalise_url'), $othermes);
        $user_url = self::normalise_url($this->user_url);

        if ( in_array( $user_url, $othermes ) ) {
          $this->matched_rel = $url;
          $_SESSION['relmeauth']['debug']['matched'][] = $url;
          return true;
        }
      }
    }
    $this->error('No rels matched. Tried ' . implode(', ', array_keys($this->source_rels)));
    return false;
  }

  /**
   * Does the job of discovering rel="me" urls
   *
   * @return array of rel="me" urls for the given source URL
   * @author Matt Harris
   */
  function discover($source_url, $titles=true) {
    if (! $this->request(array(), 'GET', $source_url, array(), false))
      return false;

    $simple_xml_element = self::toXML($this->tmhOAuth->response['response']);
    if ( ! $simple_xml_element ) {
      $response = self::tidy($this->tmhOAuth->response['response']);
      if ( ! $response ) {
        $this->error('I couldn\'t tidy that up');
        return false;
      }
      $simple_xml_element = self::toXML($response);
      if ( ! $simple_xml_element ) {
        $this->error('Looks like I can\'t do anything with the webpage you suggested');
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

   function normalise_url($url) {
     $parts = parse_url($url);
     if ( ! isset($parts['path']))
        $url = $url . '/';

     return strtolower($url);
   }
}

?>