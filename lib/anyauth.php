<?php

ob_start(); include 'cassis.js'; ob_end_clean();

class anyauth {
  function __construct() {

  }

  /**
   * Does the job of discovering rel="me" urls
   *
   * @return array of rel="me" urls for the given source URL
   * @author Matt Harris
   */
  function discover($user_url) {
    self::curl($user_url, $response);

    $simple_xml_element = self::toXML($response);
    if ( ! $simple_xml_element ) {
      if ( ! self::tidy( $response ) ) {
        echo '<div id="error">so, ummm, yeah. I couldn\'t tidy that up. Sorry</div>';
        return false;
      }
      $simple_xml_element = self::toXML($response);
      if ( ! $simple_xml_element ) {
        echo '<div id="error">so, ummm, yeah. Looks like I can\'t do anything with the webpage you suggested. Sorry</div>';
        return false;
      }
    }

    // extract URLs with rel=me in them
    $xpath = xphasrel('me');
    $relmes = $simple_xml_element->xpath($xpath);
    $base = self::real_url($user_url, self::html_base_href($simple_xml_element));

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
      $urls[ $url ] = $title;
    }
    return $urls;
  }
  
  function html_base_href($simple_xml_element) {
    if ( ! $simple_xml_element) 
      return '';

    $base_elements = $simple_xml_element->xpath('//head//base[@href]');
    return ( $base_elements && ( count($base_elements) > 0 ) ) ?
             $base_elements[0]->getAttribute('href') :
             '';
  }

  function real_url($base, $url) {
    // has a domain already
    if (stripos( $url, 'http' ) === 0) {
      return $url;
    }

    $url_bits = parse_url($base);
    $host = $url_bits['scheme'] . '://' . $url_bits['host'];
    
    // absolute URL
    if ( $url[0] == '/' ) {
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
   * Run tidy on the given string if it is installed.
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
      echo 'no tidy :(';
    }
    return false;
  }

  /**
   * Curl
   *
   * @param string $url the URL to request
   * @param string $response variable to store the response in
   * @param string $method HTTP request method
   * @param string $data post or get data
   * @param string $user username for request if required
   * @param string $pass password for request if required
   * @return int the HTTP status code for the request
   * @author Matt Harris
   */
  function curl($url, &$response, $method='GET', $data=NULL, $user=NULL, $pass=NULL) {
    $c = curl_init();
    curl_setopt($c, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($c, CURLOPT_TIMEOUT, 30);
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
    return $code;
  }
}

?>