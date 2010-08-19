<?php

$providers = array(
  'twitter.com' => array(
    'keys' => array(
      'consumer_key'    => '',
      'consumer_secret' => '',
    ),
    'urls' => array(
      'request' => 'https://api.twitter.com/oauth/request_token',
      'auth'    => 'https://api.twitter.com/oauth/authenticate',
      'access'  => 'https://api.twitter.com/oauth/access_token',
      'verify'  => 'https://api.twitter.com/1/account/verify_credentials.json',
    ),
    'verify' => array(
      'url'  => 'url',
      'name' => 'url'
    )
  )

);

?>