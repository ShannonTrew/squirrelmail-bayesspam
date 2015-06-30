<?php
include_once(SM_PATH.'plugins/bayesspam/config.php');

function bayesspam_filter($args) {
   bayesspam_prune($args);

   if (!isset($GLOBALS['bayesspam_filtering_enabled']) || !$GLOBALS['bayesspam_filtering_enabled'])
      return 0;

   if($args[0] == 'left_main_before') {
      bayesspam_filters($GLOBALS['imapConnection'], 0);
   } else {
      bayesspam_filters($GLOBALS['imapConnection'], 1);
   }
   return 1;
}

function bayesspam_filters($imap_stream, $use_mailbox=0) {
   $spam_stats = 0;
   $ham_stats = 0;
   $unsure_stats = 0;
   $total_messages = 0;
   $run_folders = array();
   $boxes = sqimap_mailbox_list($imap_stream);

   foreach ($boxes as $box) {
      if ($GLOBALS['bayesspam_inboxonly'] != 1 || ($GLOBALS['bayesspam_inboxonly'] == 1 && 'INBOX' ==  $box['unformatted-dm'])) {
         if (($use_mailbox == 1 && $box['unformatted-dm'] == $GLOBALS['mailbox'] && !in_array($box['unformatted-dm'],$GLOBALS['bayesspam_ignore_folders'])) || ($use_mailbox == 0 && !in_array($box['unformatted-dm'], $GLOBALS['bayesspam_ignore_folders']))) {
            if ( (array_search('noselect', $box['flags']) === FALSE || array_search('noselect', $box['flags']) === NULL) && $GLOBALS['sent_folder'] != $box['unformatted-dm'] && sqimap_unseen_messages($imap_stream, $box['unformatted-dm']) > 0) {
               $run_folders[] = $box['unformatted-dm'];
            }
         }
      }
   }

   foreach ($run_folders as $box) {
      $spam_messages = array();
      $uncertain_messages = array();

      $mbxresponse = sqimap_mailbox_select($imap_stream, $box);

      $messages = array();
      $search = "SEARCH UNSEEN UNDELETED";
      if (isset($_SESSION['bayesspam_last_filter'])) {
         $search .= " SINCE ".date('d-M-Y',$_SESSION['bayesspam_last_filter']);
      }
      $_SESSION['bayesspam_last_filter'] = time();      

      $read = sqimap_run_command($imap_stream, $search, TRUE, $response, $message, TRUE);
      if (isset($read[0])) {
         for ($i=0,$iCnt=count($read);$i<$iCnt;++$i) {
            if (preg_match("/^\* SEARCH (.+)$/", $read[$i], $regs)) {
               $messages = preg_split("/ /", trim($regs[1]));
               break;
            }
         }
      }

      foreach ($messages as $passed_id) {
         bayesspam_set_message_id($imap_stream,$passed_id);

         $bayesspam_check_messageid = bayesspam_check_messageid();
         if ($GLOBALS['bayesspam_do_stats'] && $GLOBALS['bayesspam_do_user_stats']) {
            $bayesspam_old_message_score = bayesspam_get_old_message_score();
         }

         $is_spam = bayesspam_get_probability($imap_stream, $passed_id, 1);

         if ($is_spam>.9) {
            $spam_messages[] = $passed_id;
         } elseif ($is_spam >= .1) {
            $uncertain_messages[] = $passed_id;
         }

         if ($GLOBALS['bayesspam_do_stats'] && $GLOBALS['bayesspam_do_user_stats'] && $bayesspam_old_message_score === FALSE) {
            if ($is_spam > .9) {
               $spam_stats++;
               $total_messages++;
            }
            if ($is_spam <= .9 && $is_spam >= .1) {
               $unsure_stats++;
               $total_messages++;
            }
            if ($is_spam < .1) {
               $ham_stats++;
               $total_messages++;
            }
         }
      }

      if ($spam_messages) {
         $message_str = sqimap_message_list_squisher($spam_messages);
         if ($GLOBALS['bayesspam_delete']) {
            sqimap_run_command ($imap_stream, 'STORE '.$message_str.' +FLAGS (\Deleted)', true, $response, $message, $GLOBALS['uid_support']);
         } elseif (sqimap_mailbox_exists($imap_stream, $GLOBALS['bayesspam_folder'])) {
            sqimap_run_command ($imap_stream, 'COPY '.$message_str.' "'.$GLOBALS['bayesspam_folder'].'"', true, $response, $message,  $GLOBALS['uid_support']);
            sqimap_run_command ($imap_stream, 'STORE '.$message_str.' +FLAGS (\Deleted)', true, $response, $message,  $GLOBALS['uid_support']);
         }
         sqimap_mailbox_expunge($imap_stream, $box);
      }
      if ($uncertain_messages && $GLOBALS['bayesspam_do_uncertain_filtering']) {
         $message_str = sqimap_message_list_squisher($uncertain_messages);
         if (sqimap_mailbox_exists($imap_stream, $GLOBALS['bayesspam_uncertain_folder'])) {
            sqimap_run_command ($imap_stream, 'COPY '.$message_str.' "'.$GLOBALS['bayesspam_uncertain_folder'].'"', true, $response, $message,  $GLOBALS['uid_support']);
            sqimap_run_command ($imap_stream, 'STORE '.$message_str.' +FLAGS (\Deleted)', true, $response, $message,  $GLOBALS['uid_support']);
         }
         sqimap_mailbox_expunge($imap_stream, $box);
      }
   }

   if ($GLOBALS['bayesspam_do_stats'] && $GLOBALS['bayesspam_do_user_stats'] && $total_messages) {
      $res = $GLOBALS['bayesdbhandle']->query('SELECT UserName FROM '.$GLOBALS['bayesdbprefix'].'stats WHERE UserName=\''.$GLOBALS['bayes_username'].'\'');

      if (!DB::isError($res) && !($row = $res->fetchRow())) {
         $GLOBALS['bayesdbhandle']->query('INSERT INTO '.$GLOBALS['bayesdbprefix'].'stats SET StatsStart=NOW(),UserName=\''.$GLOBALS['bayes_username'].'\',TotalMessages='.$total_messages.',HamMessages='.$ham_stats.',SpamMessages='.$spam_stats.',UnsureMessages='.$unsure_stats.($GLOBALS['bayesspam_do_timing']?(',TimedMessages='.$GLOBALS['bayes_parsed_messages'].',TotalParseTime='.$GLOBALS['bayes_parse_time']):''));
      } else {
         $GLOBALS['bayesdbhandle']->query('UPDATE '.$GLOBALS['bayesdbprefix'].'stats SET TotalMessages=TotalMessages+'.$total_messages.',HamMessages=HamMessages+'.$ham_stats.',SpamMessages=SpamMessages+'.$spam_stats.', UnsureMessages=UnsureMessages+'.$unsure_stats.($GLOBALS['bayesspam_do_timing']?(',TimedMessages=TimedMessages+'.$GLOBALS['bayes_parsed_messages'].',TotalParseTime=TotalParseTime+'.$GLOBALS['bayes_parse_time']):'').' WHERE UserName=\''.$GLOBALS['bayes_username'].'\'');
      }
   }
}
?>
