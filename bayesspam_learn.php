<?php
/* Path for SquirrelMail required files. */
define('SM_PATH','../../');
include_once(SM_PATH . 'include/validate.php');
include_once(SM_PATH . 'functions/imap.php');
include_once(SM_PATH . 'functions/plugin.php');
include_once(SM_PATH . 'functions/page_header.php');
include_once(SM_PATH . 'functions/html.php');

include_once(SM_PATH . 'plugins/bayesspam/config.php');

if (!isset($_REQUEST)) {
   $_REQUEST['bayes_type']=$HTTP_GET_VARS['bayes_type'];
   $_REQUEST['mailbox']=$HTTP_GET_VARS['mailbox'];
   $_REQUEST['passed_id']=$HTTP_GET_VARS['passed_id'];
   $_REQUEST['startMessage']=$HTTP_GET_VARS['startMessage'];
   $_REQUEST['show_more']=$HTTP_GET_VARS['show_more'];
}

if ($_REQUEST['bayes_type'] == 'spam' || $_REQUEST['bayes_type'] == 'nonspam') {
   $key = $_COOKIE['key'];
   $onetimepad = $_SESSION['onetimepad'];
   $username = $_SESSION['username'];
   $delimiter = $_SESSION['delimiter'];

   $imapConnection = sqimap_login($username, $key, $GLOBALS['imapServerAddress'], $GLOBALS['imapPort'], 10, $onetimepad); // the 10 is to hide the output
   sqimap_mailbox_select($imapConnection, $_REQUEST['mailbox']);

   bayesspam_learn_single($imapConnection, $_REQUEST['mailbox'], $_REQUEST['passed_id'], $_REQUEST['bayes_type']);
}

header('Location: ../../src/read_body.php?mailbox='.$_REQUEST['mailbox'].'&passed_id='.$_REQUEST['passed_id'].'&startMessage='.$_REQUEST['startMessage'].'&show_more='.$_REQUEST['show_more']);

?>
