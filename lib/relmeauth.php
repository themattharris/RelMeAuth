<?php

// from http://us.php.net/manual/en/security.magicquotes.disabling.php
if (get_magic_quotes_gpc()) {
  $process = array(&$_GET, &$_POST, &$_COOKIE, &$_REQUEST);
  while (list($key, $val) = each($process)) {
    foreach ($val as $k => $v) {
      unset($process[$key][$k]);
      if (is_array($v)) {
        $process[$key][stripslashes($k)] = $v;
        $process[] = &$process[$key][stripslashes($k)];
      } else {
        $process[$key][stripslashes($k)] = stripslashes($v);
      }
    }
  }
  unset($process);
}

ob_start(); require_once __DIR__.DIRECTORY_SEPARATOR.'cassis'.DIRECTORY_SEPARATOR.'cassis.js'; ob_end_clean();

// use composers autoload if it exists, or require directly if not
if (file_exists(dirname(__DIR__).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php')) {
  require dirname(__DIR__).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
} elseif (file_exists(__DIR__.DIRECTORY_SEPARATOR.'tmhOAuth'.DIRECTORY_SEPARATOR.'tmhOAuth.php')) {
  require __DIR__.DIRECTORY_SEPARATOR.'tmhOAuth'.DIRECTORY_SEPARATOR.'tmhOAuth.php';
} else {
  throw "tmhOAuth.php could not be found. have you tried installing with composer?";
}
require __DIR__.DIRECTORY_SEPARATOR.'config.php';

class relmeauth {
  function __construct() {
    session_start();
    $this->tmhOAuth = new tmhOAuth(array());
  }

  function is_loggedin() {
    // TODO: should have a timestamp expiry in here.
    return (isset($_SESSION['relmeauth']['name']));
  }

  function create_from_session() {
    global $providers;

    $config = $providers[$_SESSION['relmeauth']['provider']];

    // create tmhOAuth from session info
    $this->tmhOAuth = new tmhOAuth(array(
    'consumer_key' => $config['keys']['consumer_key'],
    'consumer_secret' => $config['keys']['consumer_secret'],
    'token' => $_SESSION['relmeauth']['access']['oauth_token'],
    'secret' => $_SESSION['relmeauth']['access']['oauth_token_secret']
    ));
  }

  function main($user_url, $askwrite) {
    // first try to authenticate directly with the URL given
    if ($this->is_provider($user_url)) {
      $_SESSION['relmeauth']['direct'] = true;
      if ($this->authenticate_url($user_url, $askwrite)) {
        return true; // bail once something claims to authenticate
      }
      unset($_SESSION['relmeauth']['direct']);
    }

    // get the rel-me URLs from the given site
    $source_rels = $this->discover($user_url);

    if ($source_rels==false || count($source_rels) == 0) {
      return false; // no rel-me links found, bail
    }

    // separate them into external and same domain
    $external_rels = array();
    $local_rels = array();
    $user_site = parse_url($user_url);

    foreach ($source_rels as $source_rel => $details) :
      $provider = parse_url($source_rel);
      if ($provider['host'] == $user_site['host']) {
        $local_rels[$source_rel] = $details;
      } else {
        $external_rels[$source_rel] = $details;
      }
    endforeach; // source_rels

    // see if any of the external rel-me URLs reciprocate - check rels in order
    // and then try authing it. needs to maintain more session state to resume.
    foreach ($external_rels as $external_rel => $details):
      // only bother to confirm rel-me etc. if we know how to auth the dest.
      if ($this->is_provider($external_rel) &&
          $this->confirm_rel($user_url, $external_rel)) {
        // We could keep this as a URL we actually try to auth, for debugging
        if ($this->authenticate_url($external_rel, $askwrite)) {
          return true; // bail once something claims to authenticate
        }
      }
    endforeach; // external_rels

    $source_rels = array_merge($local_rels, $external_rels);
    $source2_tried = array();

    // no external_rels, or none of them reciprocated or authed. try next level.
    foreach ($source_rels as $source_rel => $details) :
     // try rel-me-authing $source_rel,
     // and test its respective external $source2_urls
     // to match against $source_rel OR $user_url.

      $source_rel_confirmed =
          strpos($source_rel, $user_url)===0 ||
          $this->confirm_rel($user_url, $source_rel);
      // if $source_rel is a confirmed rel-me itself,
      // then we'll allow for 2nd level to confirm to it

      // then check its external_rels
      $source2_rels = $this->discover($source_rel);
      if ($source2_rels!=false) {
        foreach ($source2_rels as $source2_rel => $details) :
          $provider = parse_url($source2_rel);
          if ($provider['host'] != $user_site['host'] &&
              $this->is_provider($source2_rel))
          {
            $source2_tried[$source2_rel] = $details;
            if ((!$source_rel_confirmed &&
                 $this->confirm_rel($user_url, $source2_rel)) ||
                ($source_rel_confirmed &&
                 $this->confirms_rel($user_url, $source_rel, $source2_rel)))
            {
            // could keep this as a URL we actually try to auth, for debugging
              if ($source_rel_confirmed) {
                $_SESSION['relmeauth']['url2'] = $source_rel;
              }
              if ($this->authenticate_url($source2_rel, $askwrite)) {
                // this exits if it succeeds. next statement unnecessary.
                return true; // bail once something claims to authenticate
              }
              $_SESSION['relmeauth']['url2'] = '';
            }
          }
        endforeach; // source_rels
      }

    // if successful, should have returned true, which can be returned
    endforeach; // source_rels

/*
    //debugging
    $debugurls = $this->discover('http://twitter.com/kevinmarks/');

    //end debugging
*/

    // otherwise, no URLs worked.
    $source_rels = implode(', ', array_keys($source_rels)) .
     ($source2_tried && count($source2_tried)>=0 ? ', ' .
                           implode(', ', array_keys($source2_tried)) : '')
/*
     .
     ($debugurls && count($debugurls)>=0 ? '. debug: ' .
                           implode(', ', array_keys($debugurls)) : '')
*/
                           ;

    $this->error('None of your providers are supported. Tried ' . $source_rels . '.');

    return false;

/*
// old code that first confirmed all rel-me links, and then tried as a batch
    // see if any of the relmes match back - we check the rels in the order
    // they are listed in the HTML
    $confirmed_rels = $this->confirm_rels($user_url, $source_rels);
    if ($confirmed_rels != false) {
      return $this->authenticate($confirmed_rels);
    } else {
      // error message will have already been set
      return false;
    }
*/
  }

  function request($keys, $method, $url, $params=array(), $useauth=true) {
    $this->tmhOAuth = new tmhOAuth(array());

    $this->tmhOAuth->config['consumer_key']    = $keys['consumer_key'];
    $this->tmhOAuth->config['consumer_secret'] = $keys['consumer_secret'];
    $this->tmhOAuth->config['token']           = @$keys['user_token'];
    $this->tmhOAuth->config['secret']          = @$keys['user_secret'];
    $code = $this->tmhOAuth->request(
      $method,
      $url,
      $params,
      $useauth
    );

    return ( $code == 200 );
  }


  /**
   * check to see if we know how to OAuth a URL
   *
   * @return whether or not it's a provider we know how to deal with
   * @author Tantek Çelik
   */
  function is_provider($confirmed_rel) {
    global $providers;

    $provider = parse_url($confirmed_rel);
    if (array_key_exists($provider['host'], $providers)) {
       return true;
    }
    if (strpos($provider['host'], 'www.')===0) {
      $provider['host'] = substr($provider['host'],4);
      if (array_key_exists($provider['host'], $providers) &&
          $providers[$provider['host']]['ltrimdomain'] == 'www.')
      {
        return true;
      }
    }
    return false;
  }

  /**
   * Wrapper for the OAuth authentication process for a URL
   *
   * @return false if authentication failed
   * @author Matt Harris and Tantek Çelik
   */
  function authenticate_url($confirmed_rel, $askwrite) {
    global $providers;

    if (!$this->is_provider($confirmed_rel))
      return false;

    $provider = parse_url($confirmed_rel);
      $config = $providers[ $provider['host'] ];
      $ok = $this->request(
        $config['keys'],
        'GET',
        $config['urls']['request'],
        array(
          'oauth_callback' => $this->here(),
          'x_auth_access_type' => ($askwrite ? 'write' : 'read'), // http://dev.twitter.com/doc/post/oauth/request_token
        )
      );

      if (!$ok) {
        $this->error("There was a problem communicating with {$provider['host']}. Error {$this->tmhOAuth->response['code']}. Please try later.");
        return false;
      }

      // need these later
      $relpath = $provider['path'];
      $user = $this->tmhOAuth->extract_params($this->tmhOAuth->response['response']);
      $_SESSION['relmeauth']['provider'] = $provider['host'];
      $_SESSION['relmeauth']['secret']   = $user['oauth_token_secret'];
      $_SESSION['relmeauth']['token']    = $user['oauth_token'];
      $url = ($askwrite ? $config['urls']['authorize']
                        : $config['urls']['authenticate']) . '?'
             . "oauth_token={$user['oauth_token']}";
        $this->redirect($url);
      return true;
      } else {

      }


    return false;
  }

  /**
   * Wrapper for the OAuth authentication process
   *
   * @return false upon failure
   * @author Matt Harris and Tantek Çelik
   */
  function authenticate($confirmed_rels) {
    global $providers;

    foreach ($confirmed_rels as $host => $details) :
      if (authenticate_url($host))
        return true;
    endforeach; // confirmed_rels

    $this->error('None of your providers are supported. Tried ' . implode(', ', array_keys($confirmed_rels)) . '.');
    return false;
  }

  function complete_oauth( $verifier ) {
    global $providers;

    if ( ! array_key_exists($_SESSION['relmeauth']['provider'], $providers) ) {
      $this->error('None of your providers are supported, or you might have cookies disabled.  Make sure your browser preferences are set to accept cookies and try again.');
      return false;
    }

    if ($_REQUEST['oauth_token'] !== $_SESSION['relmeauth']['token']) {
      $this->error("The oauth token you started with is different to the one returned. try closing the tabs and making the requests again.");
      return false;
    }

    $config = $providers[$_SESSION['relmeauth']['provider']];
    $ok = $this->request(
      array_merge(
        $config['keys'],
        array(
          'token' => $_SESSION['relmeauth']['token'],
          'secret' => $_SESSION['relmeauth']['secret']
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
          'token' => $_SESSION['relmeauth']['access']['oauth_token'],
          'secret' => $_SESSION['relmeauth']['access']['oauth_token_secret']
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

    if ( $given != $found &&
         array_key_exists('url2', $_SESSION['relmeauth']))
    {
       $given = self::normalise_url($_SESSION['relmeauth']['url2']);
    }

    if ( $given == $found ||
        ($this->is_provider($given) && $_SESSION['relmeauth']['direct']))
    {
      $_SESSION['relmeauth']['name'] = $creds[ $config['verify']['name'] ];
      return true;
    } else {
      // destroy everything
      $provider = $_SESSION['relmeauth']['provider'];
      // unset($_SESSION['relmeauth']);
      $this->error("That isn't you! If it really is you, try signing out of {$provider}. Entered $given (". @$_SESSION['relmeauth']['url2'] . "), found $found.");
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
        $_SESSION['relmeauth']['error'] . '</div>';
      unset($_SESSION['relmeauth']['error']);
    }
  }

  /**
   * Check one rel=me URLs obtained from the users URL and see
   * if it contains a rel=me which equals this user URL.
   *
   * @return true if URL rel-me reciprocation confirmed else false
   * @author Matt Harris and Tantek Çelik
   */
  function confirm_rel($user_url, $source_rel) {
    $othermes = $this->discover($source_rel, false);
    $_SESSION['relmeauth']['debug']['source_rels'][$source_rel] = $othermes;
    if (is_array( $othermes)) {
      $othermes = array_map(array('relmeauth', 'normalise_url'), $othermes);
      $user_url = self::normalise_url($user_url);

      if (in_array($user_url, $othermes)) {
        $_SESSION['relmeauth']['debug']['matched'][] = $source_rel;
        return true;
      }
    }
      return false;
    }

  /**
   * Check one rel=me URLs obtained from the users URL and see
   * if it contains a rel=me which equals this user URL.
   *
   * @return true if URL rel-me reciprocation confirmed else false
   * @author Matt Harris and Tantek Çelik
   * Should really abstract confirms_rel() confirm_rel() and replace both
   */
  function confirms_rel($user_url, $local_url, $source_rel) {
    $othermes = $this->discover( $source_rel, false );
    $_SESSION['relmeauth']['debug']['source_rels'][$source_rel] = $othermes;
      if ( is_array( $othermes ) ) {
        $othermes = array_map(array('relmeauth', 'normalise_url'), $othermes);
      $user_url = self::normalise_url($user_url);
      $local_url = self::normalise_url($local_url);

      if (in_array($user_url, $othermes) ||
          in_array($local_url, $othermes)) {
        $_SESSION['relmeauth']['debug']['matched'][] = $source_rel;
          return true;
        }
      }
    return false;
  }


  /**
   * Go through the rel=me URLs obtained from the users URL and see
   * if any of those sites contain a rel=me which equals this user URL.
   *
   * @return URLs that have confirmed rel-me links back to user_url or false
   * @author Matt Harris and Tantek Çelik
   */
  function confirm_rels($user_url, $source_rels) {
    if (!is_array($source_rels)) {
      $this->error('No rels found.');
      return false;
    }

    $confirmed_rels = array();
    foreach ( $source_rels as $url => $text ) {
      if (confirm_rel($user_url, $url)) {
        $confirmed_rels[$url] = $text;
      }
    }
    if (count($confirmed_rels)>0) {
      return $confirmed_rels;
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
    global $providers;

    $this->tmhOAuth->request('GET', $source_url, array(), false);
    if ($this->tmhOAuth->response['code'] != 200) {
      $this->error('Was expecting a 200 and instead got a '
                   . $this->tmhOAuth->response['code']);
      return false;
    }
    $simple_xml_element = self::toXML($this->tmhOAuth->response['response']);
    if ( ! $simple_xml_element ) {
      $response = self::tidy($this->tmhOAuth->response['response']);
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

      // trim extra trailing stuff from external profile URLs
      // workaround for providers failing to properly 301 to the shortest URL
      $provider = parse_url($url);
      if (array_key_exists($provider['host'], $providers))
      {
        $config = $providers[ $provider['host']];
        if (array_key_exists('rtrimprofile', $config)) {
          $url = rtrim($url,$config['rtrimprofile']);
        }
      }

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
             $base_elements[0]->attributes('href') :
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
    if (preg_matches('/^[\w-]+:/', $url)) {
/*
      $parsed = parse_url($url);
      if ($parsed['path']==='') { // fix-up domain only URLs with a path
        $url .= '/';
      }
*/
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
        'output-xml'      => TRUE, // 'output-xhtml'      => TRUE,
    // must be -xml to cleanup named entities that are ok in XHTML but not XML
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
