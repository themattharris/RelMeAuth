<?php

require_once( dirname(__FILE__) . '/lib/relmeauth.php');
$relmeauth = new relmeauth();
$error = false;

if ( isset($_GET['logout']) ) {
  session_destroy();
  $relmeauth->redirect();
}
elseif ( isset($_REQUEST['oauth_verifier'] ) ) {
  $ok = $relmeauth->complete_oauth( $_REQUEST['oauth_verifier'] );
  // error message on false!
} elseif ( isset($_POST['url'] ) ) {
  $user_url = strip_tags( stripslashes( $_POST['url'] ) );
  $_SESSION['relmeauth']['url'] = $user_url;

  // discover relme on the url
  $relmeauth->main( $user_url );
}

?><!DOCTYPE html>
<html lang="en-US">
<head>
  <meta charset="utf-8" />
  <title>@relmeauth</title>
  <script src="cassis.js" type="text/javascript" charset="utf-8"></script>
  <style type="text/css" media="all">
    body {
      width: 960px;
      margin: 5em auto;
      font-size: 2em;
    }
    form {
      text-align: center;
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
    p.intro {
      font-size: 0.8em;
    }
    pre {
      font-size: 0.5em;
    }
  </style>
</head>

<body>
  <h1>RelMeAuth (alpha)</h1>
<?php if ($relmeauth->is_loggedin()) : ?>
  <p>Yay! you are logged in as <?php echo $_SESSION['relmeauth']['name'] ?> using <?php echo $_SESSION['relmeauth']['provider']?>. <a href="?logout=1">logout?</a></p>
<?php else: ?>
<?php   $relmeauth->printError(); ?>
  <p class='intro'>This is an alpha demo of RelMeAuth. It is likely there are still errors and any issues should be reported on the 
  <a href="http://github.com/themattharris/RelMeAuth">GitHub Project Page</a>. This code is written by 
  @<a href="http://twitter.com/themattharris" rel="me">themattharris</a> and @<a href="http://twitter.com/t">t</a>. It
  uses a modified OAuth PHP library.</p>

  <form action="" method="POST">
      <label for="url">Your domain:</label>
      <input type="url" required="required" name="url" id="url"
        autofocus="autofocus"
        value="<?php echo @$_SESSION['relmeauth']['url'] ?>" />
      <button type="submit">Sign In</button>
  </form>
<?php endif; ?>

</body>
<script type="text/javascript" charset="utf-8">
  $input = document.getElementById('url');
  $input.onfocus = function() {
    if (this.value.replace(/^\s+|\s+$/g,"") == 'http://yourdomain.com') {
      this.value = '';
    }
  }
  $input.onclick = function() {
    this.focus();
    this.select();
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

  