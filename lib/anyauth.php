<?php

ob_start(); include 'cassis.js'; ob_end_clean();

class anyauth {
  var $matched_rel = false;

  function __construct($user_url) {
    $this->user_url = $user_url;
    $this->main();
  }

  function main() {
    // get the rel mes from the given site
    $this->source_rels = $this->discover( $this->user_url );

    // see if any of the relmes match back - we check the rels in the order
    // they are listed in the HTML
    $has_match = $this->process_rels();

    if ( $has_match ) {
      $this->authenticate();
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
    $provider = parse_url($this->user_url);
    $provider = 'https://' . $provider['host'];

    $endpoints = array(
      'oauth/request_token',
      'oauth/authorize',
      'oauth/authenticate',
      'oauth/access_token',
    );
  }

  /**
   * Print the last error message if there is one.
   *
   * @return void
   * @author Matt Harris
   */
  function printError() {
    if ( isset($this->errormsg) && ! empty( $this->errormsg ) ) {
      echo '<div id="error">so, ummm, yeah. ' .
        $this->errormsg . '. Sorry</div>';
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
    $this->errormsg = 'No rels matched';
    return false;
  }

  /**
   * Does the job of discovering rel="me" urls
   *
   * @return array of rel="me" urls for the given source URL
   * @author Matt Harris
   */
  function discover($source_url, $titles=true) {
    self::curl($source_url, $response);

    $simple_xml_element = self::toXML($response);
    if ( ! $simple_xml_element ) {
      $response = self::tidy($response);
      if ( ! $response ) {
        $this->errormsg = 'I couldn\'t tidy that up.';
        return false;
      }
      $simple_xml_element = self::toXML($response);
      if ( ! $simple_xml_element ) {
        $this->errormsg =
          'Looks like I can\'t do anything with the webpage you suggested.';
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
      $url = self::real_url($base, $rel);
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
      $xml = new SimpleXMLElement($str);
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
      $this->errormsg = 'no tidy :(';
    }
    return false;
  }

  /**
   * Curl wrapper function
   *
   * @param string $url the URL to request
   * @param string $response variable to store the response in
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
  function curl($url, &$response, $method='GET',
      $data=NULL, $user=NULL, $pass=NULL, $connect_timeout=5,
      $request_timeout=10) {
    $c = curl_init();
    curl_setopt($c, CURLOPT_CONNECTTIMEOUT, $connect_timeout);
    curl_setopt($c, CURLOPT_TIMEOUT, $request_timeout);
    curl_setopt($c, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($c, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($c, CURLOPT_URL, $url);
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
    $response = curl_exec($c);
    $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close ($c);
    unset($c);
    return $code;
  }
}

?>