<?php

require_once('lib/anyauth.php');

function pr($obj) {
  echo '<pre style="white-space: pre-wrap; background-color: black; color: white; text-align:left; font-size: 10px">';
  if ( is_object($obj) )
    print_r($obj);
  elseif ( is_array($obj) )
    print_r($obj);
  else
    echo $obj;
  echo '</pre>';
}

if ( isset($_POST['url'] ) ) {

  // save url to session - no db at the moment
  session_start();
  $_SESSION['anyauth']['url'] = strip_tags( stripslashes( $_POST['url'] ) );

  // discover relme on the url
  $mes = anyauth::discover($_SESSION['anyauth']['url']);
  pr($mes);
}

// echo anyauth::real_url('http://twitter.com/themattharris', '/themattharris/followers') . '<br><br>';
// echo anyauth::real_url('http://twitter.com/themattharris/a/b/c/d/e/f', '../../../../../themattharris/followers') . '<br><br>';
// echo anyauth::real_url('http://twitter.com/themattharris/a/b/c/d/e/f', '../../../../../../../../themattharris/followers') . '<br><br>';
// echo anyauth::real_url('http://twitter.com/themattharris/a/b/c/d/e/f', '../../../../../../../../../../../../../../themattharris/followers') . '<br><br>';
// echo anyauth::real_url('http://twitter.com/a/b/c/d/e/f', '../../../../../../../../../../../../../../f/e/d/c/../b/../../a/followers') . '<br><br>';
// echo anyauth::real_url('http://twitter.com/1/2/3/4/5/6', '../../1/../2/../3/../../4/../5/../../f/e/d/c/../b/../../a/followers') . '<br><br>';

?><!DOCTYPE html>
<html lang="en-US">
<head>
  <meta charset="utf-8" />
  <title>@anyauth</title>
  <script src="cassis.js" type="text/javascript" charset="utf-8"></script>
  <style type="text/css" media="all">
    body {
      text-align: center;
      width: 960px;
      margin: 5em auto;
      font-size: 2em;
    }
    input[name="url"] {
      width: 10em;
      font-size: 100%;
    }
    button {
      font-size: 100%;
    }
    div#error {
      color: red;
      margin: 0.5em 0;
    }
  </style>
</head>

<body>
  <form action="" method="POST">
      <label for="url">Sign In:</label>
      <input type="url" required="required" name="url" id="url"
        autofocus="autofocus"
        value="<?php echo $_SESSION['anyauth']['url'] ?>" />
      <button type="submit">Sign In</button>
  </form>
</body>
<script type="text/javascript" charset="utf-8">
  $input = document.getElementById('url');
  $input.onfocus = function() {
    if (this.value.replace(/^\s+|\s+$/g,"") == 'http://yourdomain.com') {
      this.value = '';
    }
  }
  $input.onblur = function() {
    if (this.value.replace(/^\s+|\s+$/g,"") == '') {
      this.value = 'http://yourdomain.com';
    } else {
      this.value = webaddresstouri(this.value, true);
    }
  }
  $input.oninvalid = function() {
    this.value = webaddresstouri(this.value, true);
    if (this.willValidate) {
      this.setCustomValidity('');
      this.parentNode.submit();
      return false;
    } else if (document.getElementById('error')) {
        return;
    } else {
      $html = document.createElement("div");
      $html.id = 'error';
      $html.innerHTML = "Oops! looks like you didn't enter a URL. Try starting with http://";
      this.parentNode.appendChild($html)
    }
  }
</script>
</html>

  