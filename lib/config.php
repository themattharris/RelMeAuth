<?php

$providers = array(
  'twitter.com' => array(
    'keys' => array(
      'consumer_key'    => 'YOUR_CONSUMER_KEY', // YOUR_CONSUMER_KEY
      'consumer_secret' => 'YOUR_CONSUMER_SECRET', // YOUR_CONSUMER_SECRET
    ),
    'urls' => array(
      'request'      => 'https://api.twitter.com/oauth/request_token',
      'authenticate' => 'https://api.twitter.com/oauth/authenticate', // auto
      'authorize'    => 'https://api.twitter.com/oauth/authorize', // ask
      'access'       => 'https://api.twitter.com/oauth/access_token',
      'verify'       => 'https://api.twitter.com/1/account/verify_credentials.json',
    ),
    'verify' => array(
      'url'  => 'url',
      'name' => 'url'
    ),
    'rtrimprofile' => '/',
    'ltrimdomain' => 'www.'
  ),
  'identi.ca' => array(
    'keys' => array(
      'consumer_key'    => 'YOUR_CONSUMER_KEY', // YOUR_CONSUMER_KEY
      'consumer_secret' => 'YOUR_CONSUMER_SECRET', // YOUR_CONSUMER_SECRET
    ),
    'urls' => array(
      'request'      => 'https://api.identi.ca/oauth/request_token', // http://identi.ca/api/oauth/request_token
      'authenticate' => 'https://api.identi.ca/oauth/authenticate',
      'authorize'    => 'https://api.identi.ca/oauth/authorize', // http://identi.ca/api/oauth/authorize
      'access'       => 'https://api.identi.ca/oauth/access_token', // http://identi.ca/api/oauth/access_token
      'verify'       => 'https://api.identi.ca/1/account/verify_credentials.json',
    ),
    'verify' => array(
      'url'  => 'url',
      'name' => 'url'
    ),
    'rtrimprofile' => '/',
    'ltrimdomain' => 'www.'
  )
);

?>