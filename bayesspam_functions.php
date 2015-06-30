<?php 
include_once(SM_PATH . 'plugins/bayesspam/config.php');
include_once(SM_PATH . 'plugins/bayesspam/bayesspam_filter.php');
//include_once(SM_PATH . 'functions/imap.php');
//include_once(SM_PATH . 'functions/mailbox_display.php');

function bayesspam_init() {
   if (isset($_SESSION['username'])) {
      if ($GLOBALS['bayes_granularity'] == 'user') {
         $GLOBALS['bayes_username'] = addslashes(preg_replace('/'.$GLOBALS['bayes_domain_seperator'].'/','!',$_SESSION['username']));
      } elseif ($GLOBALS['bayes_granularity'] == 'domain') {
         $GLOBALS['bayes_username'] = addslashes(preg_replace('/^.+'.$GLOBALS['bayes_domain_seperator'].'/','',$_SESSION['username']));
      } else {
         $GLOBALS['bayes_username'] = 'server';
      }
   }

   $GLOBALS['bayesdbhandle'] = DB::connect($GLOBALS['bayesdbtype'].'://'.$GLOBALS['bayesdbuser'].':'.$GLOBALS['bayesdbpass'].'@'.$GLOBALS['bayesdbhost'].':'.$GLOBALS['bayesdbport'].'/'.$GLOBALS['bayesdbname'],1);

   if (DB::isError($GLOBALS['bayesdbhandle'])) {
      if ($GLOBALS['bayes_show_db_error']) {
         bindtextdomain('bayesspam', SM_PATH . 'plugins/bayesspam/locale');
         textdomain('bayesspam');

         echo $GLOBALS['bayesdbhandle']->getDebugInfo()."<BR>";

         echo _("BayesSpam improperly configured. Check DB Information.");

         bindtextdomain('squirrelmail', SM_PATH . 'locale');
         textdomain('squirrelmail');
      }
      $GLOBALS['bayesdbhandle'] = null;
   } else {
      $GLOBALS['bayesdbhandle']->setFetchMode(DB_FETCHMODE_ASSOC);
   }

   if ($GLOBALS['bayesdbhandle'] == null) {
      return;
   }

   if(!isset($_SESSION['bayesspam_corpus'])) {
      session_register('bayesspam_corpus');
   }
}

function bayesspam_special_folders($args) {
   if ($args == $GLOBALS['bayesspam_folder'] || $args == $GLOBALS['bayesspam_uncertain_folder'])
      return true;
   return false;
}

function bayesspam_button_action ($id) {
   if ($GLOBALS['bayesdbhandle'] == null) {
      return;
   }

   if (!isset($id) || !is_array($id))
      return;

   sqgetGlobalVar('markSpam', $markSpam, SQ_POST);
   sqgetGlobalVar('markHam', $markHam, SQ_POST);

   if (isset($markSpam)) {
      for ($i = 0; $i < count($id); $i++) {
         bayesspam_learn_single($GLOBALS['imapConnection'], $GLOBALS['mailbox'], $id[$i], 'spam');
      }

      if ($GLOBALS['bayesspam_delete']) {
         sqimap_msgs_list_delete($GLOBALS['imapConnection'], $GLOBALS['mailbox'], $id);
         sqimap_mailbox_expunge($GLOBALS['imapConnection'], $GLOBALS['mailbox']);
      } else if (sqimap_mailbox_exists($GLOBALS['imapConnection'], $GLOBALS['bayesspam_folder'])) {
         if ($GLOBALS['mailbox'] == $GLOBALS['bayesspam_folder']) {
            sqimap_msgs_list_move($GLOBALS['imapConnection'], $id, $GLOBALS['trash_folder']);
         } else {
            sqimap_msgs_list_move($GLOBALS['imapConnection'], $id, $GLOBALS['bayesspam_folder']);
         }

         sqimap_mailbox_expunge($GLOBALS['imapConnection'], $GLOBALS['mailbox']);
         foreach($id as $i) {
            for ($a = 0; $a < count($GLOBALS['aMailbox']['UIDSET'][0]); $a++) {
               if ($GLOBALS['aMailbox']['UIDSET'][0][$a] == $i) {
                  unset($GLOBALS['aMailbox']['UIDSET'][0][$a]);
               }
            }
            foreach ($GLOBALS['aMailbox']['MSG_HEADERS'] as $m) {
               if ($m['UID'] == $i) {
                  unset($GLOBALS['aMailbox']['MSG_HEADERS'][$i]);
               }
            }
         }
         // reindex the arrays
         $GLOBALS['aMailbox']['MSG_HEADERS'] = array_values($GLOBALS['aMailbox']['MSG_HEADERS']);
         $GLOBALS['aMailbox']['UIDSET'][0] = array_values($GLOBALS['aMailbox']['UIDSET'][0]);
         $GLOBALS['aMailbox']['EXISTS'] -= count($id);
         // Change the startMessage number if the mailbox was changed
         if (($GLOBALS['aMailbox']['PAGEOFFSET']-1) >= $GLOBALS['aMailbox']['EXISTS']) {
            $GLOBALS['aMailbox']['PAGEOFFSET'] = ($GLOBALS['aMailbox']['PAGEOFFSET'] > $GLOBALS['aMailbox']['LIMIT']) ?
               $GLOBALS['aMailbox']['PAGEOFFSET'] - $GLOBALS['aMailbox']['LIMIT'] : 1;
               $GLOBALS['aMailbox']['OFFSET'] = $GLOBALS['aMailbox']['PAGEOFFSET']-1;
         }
      }
   } else if (isset($markHam)) {
      for ($i = 0; $i < count($id); $i++)
         bayesspam_learn_single($GLOBALS['imapConnection'], $GLOBALS['mailbox'], $id[$i], 'nonspam');
   }
}

function bayesspam_show_buttons() {
   if ($GLOBALS['bayesdbhandle'] == null) {
      return;
   }

   if ($GLOBALS['bayesspam_show_spam_buttons']) {
      bindtextdomain('bayesspam', SM_PATH . 'plugins/bayesspam/locale');
      textdomain('bayesspam');

      echo getButton('SUBMIT', 'markSpam', _("Spam"));
      echo getButton('SUBMIT', 'markHam', _("NonSpam"));

      bindtextdomain('squirrelmail', SM_PATH . 'locale');
      textdomain('squirrelmail');
   }
}

function bayesspam_prune($args) {
   if ($args[0] != 'left_main_before' || !isset($_SESSION['just_logged_in']) || $_SESSION['just_logged_in'] != true) {
      return;
   }

   if ($GLOBALS['bayesdbhandle'] == null) {
      return;
   }

   if ($GLOBALS['bayesspam_prune_threshold'] == 0) {
      return;
   }

   $secsago=(86400 * ($GLOBALS['bayesspam_prune_threshold']-1)); /* Convert days to seconds */
   $cutoffdate=date('d-M-Y',(time() - $secsago));

   if (!sqimap_mailbox_exists($GLOBALS['imapConnection'],$GLOBALS['bayesspam_folder'])) {
      return;
   }

   $notrash = false;

   if (!sqimap_mailbox_exists($GLOBALS['imapConnection'],$GLOBALS['trash_folder'])) {
      $notrash = true;
   } else {
      $trashbox=sqimap_mailbox_select($GLOBALS['imapConnection'],$GLOBALS['trash_folder']);
      if ((strtolower($trashbox['RIGHTS'])) != "read-write") {
         $notrash = true;
      }
   }

   $mbx = sqimap_mailbox_select($GLOBALS['imapConnection'], $GLOBALS['bayesspam_folder']);
   if ((strtolower($mbx['RIGHTS'])) != "read-write") {
      return;
   }

   $query = "SEARCH SENTBEFORE {$cutoffdate}";
   $read = sqimap_run_command($GLOBALS['imapConnection'], $query, TRUE, $response, $message, $GLOBALS['uid_support']);

   $results=str_replace(' ',',',substr($read[0],9));
   $msglist=trim($results); /* get rid of that nasty whitespace */

   if (strlen($msglist) < 1) {
      return;
   }

   $msglist=sqimap_message_list_squisher(explode(' ',$msglist));

   if (!$notrash && $GLOBALS['default_move_to_trash']) {
      sqimap_msgs_list_move($GLOBALS['imapConnection'], $msglist, $GLOBALS['trash_folder']);
   } else {
      sqimap_msgs_list_delete($GLOBALS['imapConnection'], $GLOBALS['bayesspam_folder'], $msglist);
   }
   if ($GLOBALS['auto_expunge']) {
      sqimap_mailbox_expunge($GLOBALS['imapConnection'], $GLOBALS['bayesspam_folder']);
   }
}

function bayesspam_nuke_db() {
   if ($GLOBALS['bayesdbhandle'] == null) {
      return;
   }

   $sql0 = 'DELETE FROM '.$GLOBALS['bayesdbprefix'].'Corpus WHERE UserName=\''.$GLOBALS['bayes_username'].'\'';
   $sql1 = 'DELETE FROM '.$GLOBALS['bayesdbprefix'].'nonspamOccurences WHERE UserName=\''.$GLOBALS['bayes_username'].'\'';
   $sql2 = 'DELETE FROM '.$GLOBALS['bayesdbprefix'].'spamOccurences WHERE UserName=\''.$GLOBALS['bayes_username'].'\'';
   $sql3 = 'DELETE FROM '.$GLOBALS['bayesdbprefix'].'Messages WHERE UserName=\''.$GLOBALS['bayes_username'].'\'';
   $sql4 = 'DELETE FROM '.$GLOBALS['bayesdbprefix'].'ScoreCache WHERE UserName=\''.$GLOBALS['bayes_username'].'\'';
   $sql5 = 'UPDATE '.$GLOBALS['bayesdbprefix'].'users SET nonspamCount=0,spamCount=0,LastAdd=NOW(),LastRebuild=NOW() WHERE UserName=\''.$GLOBALS['bayes_username'].'\'';
   $sql6 = 'DELETE FROM '.$GLOBALS['bayesdbprefix'].'stats WHERE UserName=\''.$GLOBALS['bayes_username'].'\'';

   $GLOBALS['bayesdbhandle']->query($sql0);
   $GLOBALS['bayesdbhandle']->query($sql1);
   $GLOBALS['bayesdbhandle']->query($sql2);
   $GLOBALS['bayesdbhandle']->query($sql3);
   $GLOBALS['bayesdbhandle']->query($sql4);
   $GLOBALS['bayesdbhandle']->query($sql5);
   $GLOBALS['bayesdbhandle']->query($sql6);

   unset($_SESSION['bayesspam_corpus']);
}

function bayesspam_add_messageid($type) {
   if ($GLOBALS['bayesdbhandle'] == null) {
      return;
   }

   if ($GLOBALS['bayesdbtype'] != 'mysql') {
      $sql1 = 'DELETE FROM '.$GLOBALS['bayesdbprefix'].'Messages WHERE UserName=\''.$GLOBALS['bayes_message_id'].'\' AND MessageID=\''.$GLOBALS['bayes_message_id'].'\'';
      $sql2 = 'INSERT INTO '.$GLOBALS['bayesdbprefix'].'Messages VALUES(\''.$GLOBALS['bayes_username'].'\',\''.$GLOBALS['bayes_message_id'].'\',\''.$type.'\',NOW())';
      $GLOBALS['bayesdbhandle']->query($sql1);
      $GLOBALS['bayesdbhandle']->query($sql2);
   } else {
      $sql = 'REPLACE INTO '.$GLOBALS['bayesdbprefix'].'Messages VALUES(\''.$GLOBALS['bayes_username'].'\',\''.$GLOBALS['bayes_message_id'].'\',\''.$type.'\',NULL)';
      $GLOBALS['bayesdbhandle']->query($sql);
   }
}

function bayesspam_check_messageid() {
   if ($GLOBALS['bayesdbhandle'] == null) {
      return;
   }

   $sql = 'SELECT * FROM '.$GLOBALS['bayesdbprefix'].'Messages WHERE UserName=\''.$GLOBALS['bayes_username'].'\' AND MessageID=\''.$GLOBALS['bayes_message_id'].'\'';
   $res = $GLOBALS['bayesdbhandle']->query($sql);

   $row = $res->fetchRow();
   
   if ($row && !DB::isError($res)) {
      return $row['Type'];
   } else {
      return FALSE;
   }
}

function bayesspam_do_learn($type,$oldarray,$newarray,$count,$skip_check=0) {
   if ($GLOBALS['bayesdbhandle'] == null) {
      return;
   }

   $check = FALSE;
   
   if ($skip_check || (($check = bayesspam_check_messageid()) != $type)) {
      if (!$skip_check && $check) {
         $old_occurences = bayesspam_get_occurences($check);
         $update = array();
         foreach ($newarray as $token=>$value) {
            if (isset($old_occurences[$token])) {
               if ($check == 'spam') {
                  $update[$token] = $old_occurences[$token] - $value;
               } elseif ($check == 'nonspam') {
                  $update[$token] = $old_occurences[$token] - $value;
               }
            }
         }

         if ($GLOBALS['bayesdbtype'] != 'mysql') {
            foreach ($update as $token=>$value) {
               $sql1 = 'DELETE FROM '.$GLOBALS['bayesdbprefix'].$check.'Occurences WHERE ';
               $sql2 = 'INSERT INTO '.$GLOBALS['bayesdbprefix'].$check.'Occurences VALUES ';
               $sql1 .= '(UserName=\''.$GLOBALS['bayes_username'].'\' AND Token=\''.addslashes(substr($token,5)).'\')';
               $sql2 .= '(\''.$GLOBALS['bayes_username'].'\',\''.addslashes(substr($token,5)).'\','.$value.',NOW())';

               $GLOBALS['bayesdbhandle']->query($sql1);
               $GLOBALS['bayesdbhandle']->query($sql2);
            }
         } else {
            $sql = 'REPLACE INTO '.$GLOBALS['bayesdbprefix'].$check.'Occurences VALUES ';
            $i = 1;
            foreach ($update as $token=>$value) {
               $sql .= '(\''.$GLOBALS['bayes_username'].'\',\''.addslashes(substr($token,5)).'\','.$value.',NULL)';
               if ($i < count($update)) {
                  $sql .= ',';
               }
               $i++;
            }

            $GLOBALS['bayesdbhandle']->query($sql);
         }
      }
   
      foreach ($newarray as $token=>$value) {
         @$newarray[$token] += $oldarray[$token];
      }

      if ($GLOBALS['bayesdbtype'] != 'mysql') {
         foreach ($newarray as $token=>$value) {
            $sql1 = 'DELETE FROM '.$GLOBALS['bayesdbprefix'].$type.'Occurences WHERE ';
            $sql2 = 'INSERT INTO '.$GLOBALS['bayesdbprefix'].$type.'Occurences VALUES ';
            $sql1 .= '(UserName=\''.$GLOBALS['bayes_username'].'\' AND Token=\''.addslashes(substr($token,5)).'\')';
            $sql2 .= '(\''.$GLOBALS['bayes_username'].'\',\''.addslashes(substr($token,5)).'\','.$value.',NOW())';

            $GLOBALS['bayesdbhandle']->query($sql1);
            $GLOBALS['bayesdbhandle']->query($sql2);
         }
      } else {
         $sql = 'REPLACE INTO '.$GLOBALS['bayesdbprefix'].$type.'Occurences VALUES ';
         $i = 1;
         foreach ($newarray as $token=>$value) {
            $sql .= '(\''.$GLOBALS['bayes_username'].'\',\''.addslashes(substr($token,5)).'\','.$value.',NULL)';
            if ($i < count($newarray)) {
               $sql .= ',';
            }
            $i++;
         }
         $GLOBALS['bayesdbhandle']->query($sql);
      }

      $res = $GLOBALS['bayesdbhandle']->query('SELECT UserName FROM '.$GLOBALS['bayesdbprefix'].'users WHERE UserName=\''.$GLOBALS['bayes_username'].'\'');
      if ($GLOBALS['bayes_show_db_error'] && DB::isError($res)) {
         echo $res->getDebugInfo();
      }
      if (!DB::isError($res) && $row = $res->fetchRow()) {
         if ($check !== FALSE) {
            $GLOBALS['bayesdbhandle']->query('UPDATE '.$GLOBALS['bayesdbprefix'].'users SET '.$type.'Count='.$type.'Count+'.$count.','.$check.'Count='.$check.'Count-'.$count.' WHERE UserName=\''.$GLOBALS['bayes_username'].'\'');
         } else {
            $GLOBALS['bayesdbhandle']->query('UPDATE '.$GLOBALS['bayesdbprefix'].'users SET '.$type.'Count='.$type.'Count+'.$count.' WHERE UserName=\''.$GLOBALS['bayes_username'].'\'');
         }
      } else {
         $GLOBALS['bayesdbhandle']->query('INSERT INTO '.$GLOBALS['bayesdbprefix'].'users SET '.$type.'Count='.$count.',UserName=\''.$GLOBALS['bayes_username'].'\'');
      }

      $GLOBALS['bayesdbhandle']->query('UPDATE '.$GLOBALS['bayesdbprefix'].'users SET LastAdd=NOW() WHERE UserName=\''.$GLOBALS['bayes_username'].'\'');
   }
}

function bayesspam_get_occurences($type) {
   if ($GLOBALS['bayesdbhandle'] == null) {
      return;
   }

   $return = array();
   
   $res = $GLOBALS['bayesdbhandle']->query('SELECT Token,Frequency FROM '.$GLOBALS['bayesdbprefix'].$type.'Occurences WHERE UserName=\''.$GLOBALS['bayes_username'].'\'');
   if ($GLOBALS['bayes_show_db_error'] && DB::isError($res)) {
      echo $res->getDebugInfo();
   } else {
      while ($row = $res->fetchRow()) {
         $return['token'.$row['Token']]=$row['Frequency'];
      }
   }	
   return $return;
}

function bayesspam_set_message_id(&$imap_stream,$passed_id) {
   if ($GLOBALS['bayesdbhandle'] == null) {
      return;
   }

   $headers = sqimap_get_small_header_list ($imap_stream, array($passed_id));
   $version_array = split('\.', $GLOBALS['version']);

   $read = sqimap_run_command ($imap_stream, 'FETCH '.$passed_id.':'.$passed_id.' BODY[HEADER]', true, $response, $message, $GLOBALS['uid_support']);

   array_shift($read);
   $text_headers = implode('\n',$read);

   unset($read);

   $GLOBALS['bayes_message_id'] = md5($text_headers);
   unset($text_headers);

   if ($version_array[0] == 1 && $version_array[1] <= 2) {
      if(!$headers[0]['FLAG_SEEN'])
         sqimap_messages_remove_flag($imap_stream, $passed_id, $passed_id, 'Seen');
   } else if ($version_array[0] == 1 && $version_array[1] == 5) {
      if(!isset($headers[$passed_id]['FLAGS']['\seen']))
         sqimap_toggle_flag($imap_stream, $passed_id, '\\Seen', FALSE, TRUE);
   } else {
      if(!$headers[0]['FLAG_SEEN'])
         sqimap_messages_remove_flag($imap_stream, $passed_id, $passed_id, 'Seen', FALSE);
   }
}

function bayesspam_parse_line($string,$token_type) {
   if ($GLOBALS['bayesdbhandle'] == null) {
      return;
   }

   $return = array();

   # Pull out any email addresses in the line that are marked with <> and have an @ in them
   while (preg_match('/<([a-zA-Z0-9\-_\.]+?@([a-zA-Z0-9\-_\.]+?))>/',$string,$matches)) {
      $string = preg_replace('/<([a-zA-Z0-9\-_\.]+?@([a-zA-Z0-9\-_\.]+?))>/','',$string,1);
      if ($matches[1])
         $return[] = 'EMAIL: '.$matches[1];
      if ($matches[2])
         $return[] = 'DOMAIN: '.$matches[2];
   }

   # Grab domain names
   while (preg_match('/(([a-zA-Z][a-zA-Z0-9\-_]+\.){2,})([a-zA-Z0-9\-_]+)([^a-zA-Z0-9\-_]|$)/',$string,$matches)) {
      $string = preg_replace('/(([a-zA-Z][a-zA-Z0-9\-_]+\.){2,})([a-zA-Z0-9\-_]+)([^a-zA-Z0-9\-_]|$)/',$matches[4],$string,1);
      if ($matches[3])
         $return[] = 'DOMAIN: '.$matches[1].$matches[3];
   }

   # Grab IP addresses
   while (preg_match('/(([12]?\d{1,2}\.){3}[12]?\d{1,2})/',$string,$matches)) {
      $string = preg_replace('/(([12]?\d{1,2}\.){3}[12]?\d{1,2})/','',$string,1);
      if ($matches[1]) {
         $return[] = 'IPADDRESS: '.$matches[1];

         // IP Subnet Tokens
         $sections = explode('.',$matches[1]);
         $return[] = 'IPADDRESS: '.$sections[0].'.'.$sections[1].'.'.$sections[2].'.0/24';
         $return[] = 'IPADDRESS: '.$sections[0].'.'.$sections[1].'.0.0/16';
         $return[] = 'IPADDRESS: '.$sections[0].'.0.0.0/8';
      }
   }

   # Only care about words between 3 and 45 characters since short words like
   # an, or, if are too common and the longest word in English (according to
   # the OED) is pneumonoultramicroscopicsilicovolcanoconiosis
   while (preg_match('/([a-zA-Z][a-zA-Z\-_\']{0,44})[,\."\'\)\?!:;\/&]{0,5}([ \t\n\r]|$)/',$string,$matches)) {
      $string = preg_replace('/([a-zA-Z][a-zA-Z\-_\']{0,44})[,\."\'\)\?!:;\/&]{0,5}([ \t\n\r]|$)/',' ',$string,1);
      if (isset($matches[1]) && $matches[1] && strlen($matches[1]) >= 3)
         $return[] = $token_type.': '.$matches[1];
   }

   return $return;
}

function bayesspam_parse_html_tag($tag, $arg) {
   if ($GLOBALS['bayesdbhandle'] == null) {
      return;
   }

   preg_replace('/[\r\n]/','',$tag);
   preg_replace('/[\r\n]/','',$arg);

   if (preg_match('/^img$/i',$tag) || preg_match('/^frame$/i',$tag)) {
      preg_match('/src[ \t]*=[ \t]*["\']?http:\/(\/(.+:)?)([^ \/">]+)([ \/">]|$)/i',$arg,$matches);
      if (isset($matches[3]) && $matches[3])
         return 'HTML: '.$matches[3];
   }
   if (preg_match('/^a$/i',$tag)) {
      preg_match('/href[ \t]*=[ \t]*["\']?http:\/(\/(.+:)?)([^ \/">]+)([ \/">]|$)/i',$arg,$matches);
      if (isset($matches[3]) && $matches[3])
         return 'HTML: '.$matches[3];
   }
   if (preg_match('/^form$/i',$tag)) {
      preg_match('/action[ \t]*=[ \t]*(["\']?(http:\/\/)?)([^ \/\">]+)([ \/">]|$)/i',$arg,$matches);
      if (isset($matches[3]) && $matches[3])
         return 'HTML: '.$matches[3];
   }
}

function bayesspam_get_tokens(&$imap_stream,$passed_id) {
   if ($GLOBALS['bayesdbhandle'] == null) {
      return;
   }

   $tokens = array();

   $headers = sqimap_get_small_header_list ($imap_stream, array($passed_id));
   $version_array = split('\.', $GLOBALS['version']);

   bayesspam_set_message_id($imap_stream, $passed_id);

   $lines1 = sqimap_run_command($imap_stream, 'FETCH '.$passed_id.':'.$passed_id.' BODY[HEADER]<0.'.$GLOBALS['bayesspam_scan_size'].'>', true,$response, $msg, $GLOBALS['uid_support']);
   $response = array_shift($lines1);

   preg_match('/\{(\d+)\}/',$response,$matches);

   $lines2 = sqimap_run_command($imap_stream, 'FETCH '.$passed_id.':'.$passed_id.' BODY[TEXT]<0.'.($GLOBALS['bayesspam_scan_size'] - $matches[1]).'>', true, $response, $msg, $GLOBALS['uid_support']);

   if ($version_array[0] == 1 && $version_array[1] <= 2) {
      if(!$headers[0]['FLAG_SEEN'])
         sqimap_messages_remove_flag($imap_stream, $passed_id, $passed_id, 'Seen');
   } else if ($version_array[0] == 1 && $version_array[1] == 5) {
      if(!isset($headers[$passed_id]['FLAGS']['\seen']))
         sqimap_toggle_flag($imap_stream, $passed_id, '\\Seen', FALSE, TRUE);
   } else {
      if(!$headers[0]['FLAG_SEEN'])
         sqimap_messages_remove_flag($imap_stream, $passed_id, $passed_id, 'Seen', FALSE);
   }

   array_shift($lines2);

   $lines = array_merge($lines1,$lines2);
   $hdr_lines = count($lines1);
   unset($lines1);
   unset($lines2);

   $mime = '';
   $encoding = '';
   $decoded = '';
   $content_type = '';
   $in_html_tag = 0;

   for($i = 0; $i < count($lines); $i++) {
      $line = $lines[$i];
      if ($line == '') {
         continue;
      }
   
      if (($mime != '') && (preg_match('/'.$mime.'/',$line))) {
         $encoding = '';
         continue;
      }
   
      if (preg_match('/base64/i', $encoding)) {
         $decoded = '';
   
         while ((preg_match('/^([A-Za-z0-9+\/]{4}){1,48}[\n\r]*/',$line)) || (preg_match('/^[A-Za-z0-9+\/]+=+?[\n\r]*/',$line))) {
            $decoded .= base64_decode($line);
            if (preg_match('/[^a-zA-Z\-\.]$/',$decoded)) {
               $temp_tokens = bayesspam_parse_line($decoded,'ENCODED');
               foreach ($temp_tokens as $token) {
                  $tokens[] = $token;
               }
               $decoded = '';
            }
            $i++;
            if ($i < count($lines)) {
               $line = $lines[$i];
            } else {
               break;
            }
         }
         $temp_tokens = bayesspam_parse_line($decoded,'ENCODED');
         foreach ($temp_tokens as $token) {
            $tokens[] = $token;
         }
      }
      if ($i == count($lines)) {
         break;
      }
      if (preg_match('/<html>/i',$line)) {
         $content_type = 'text/html';
      }
      if (preg_match('/quoted\-printable/',$encoding)) {
         $line = preg_replace('/=[\r\n]*$/',"=\n",$line);
         while (preg_match('/=\n$/',$line)) {
            $tokens[] = 'CHEATER: Line Break';
            $i++;
            $line = preg_replace('/=\n$/','',$line);
            $line = $line . $lines[$i]; 
         }
         $line = quoted_printable_decode($line);
      }

      $oldline = $line;
      while ($oldline != ($line = preg_replace('/<!--.*?-->/','',$line)) ) {
         $tokens[] = 'HTML: CHEATER';
         $oldline = $line;
      }
      
      unset($oldline);
   
      if (preg_match('/html/',$content_type)) {
         if ($in_html_tag) {
            if (preg_match('/(.*?)>/',$line,$matches)) {
               $line = preg_replace('/(.*?)>/',' ',$line);
               $html_arg .= $matches[1];
               $in_html_tag = 0;
               if (preg_match('/quoted\-printable/',$line)) {
                  $html_tag = preg_replace('/=\n ?/','',$html_tag);
                  $html_arg = preg_replace('/=\n ?/','',$html_arg);
               } 
               $tokens[] = bayesspam_parse_html_tag($html_tag,$html_arg);
               $tokens[] = 'HTMLTAG: '.$html_tag;
               $html_tag = '';
               $html_arg = '';
            } else {
               $html_arg .= ' ' . $line;
               $line = '';
               continue;
            }
   
         }

         while (preg_match('/<[\/]?([A-Za-z]+)([^>]*?)>/',$line,$matches)) {
            $line = preg_replace('/<[\/]?([A-Za-z]+)([^>]*?)>/','',$line,1);
            $tokens[] = bayesspam_parse_html_tag($matches[1],$matches[2]);
            $tokens[] = 'HTMLTAG: '.$matches[1];
         }

         if (preg_match('/<([^ >]+)([^>]*)$/',$line,$matches)) {
            $line = preg_replace('/<([^ >]+)([^>]*)$/','',$line);
            $html_tag = $matches[1];
            $html_arg = $matches[2];
            $in_html_tag = 1;
         }
      }

      if (preg_match('/^([A-Za-z-]+): ?([^\n\r]*)/',$line,$matches)) {
         $header = $matches[1];
         $arguement = $matches[2];

         $tokens[] = 'HEADERTYPE: '.$header;

         if (preg_match('/(From|To|Cc)/i',$header)) {
            if (preg_match('/From/i',$header)) {
               $encoding = '';
               $content_type = '';
            }
            while (preg_match('/<([a-zA-Z0-9\-_\.]+?@([a-zA-Z0-9\-_\.]+?))>/',$arguement,$matches)) {
               $arguement = preg_replace('/<[a-zA-Z0-9\-_\.]+?@[a-zA-Z0-9\-_\.]+?>/','',$arguement,1);
               if ($matches[1])
                  $tokens[] = 'EMAIL: '.$matches[1];
               if ($matches[2])
                  $tokens[] = 'DOMAIN: '.$matches[2];
            }
            while (preg_match('/([a-zA-Z0-9\-_\.]+?@([a-zA-Z0-9\-_\.]+))/',$arguement,$matches)) {
               $arguement = preg_replace('/([a-zA-Z0-9\-_\.]+?@([a-zA-Z0-9\-_\.]+))/','',$arguement);
               if ($matches[1])
                  $tokens[] = 'EMAIL: '.$matches[1];
               if ($matches[2])
                  $tokens[] = 'DOMAIN: '.$matches[2];
            }
            $temp_tokens = bayesspam_parse_line($arguement, 'HEADER');
            foreach ($temp_tokens as $token) {
               $tokens[] = $token;
            }
            continue;
         }
         if (preg_match('/Subject/i',$header)) {
            $temp_tokens = bayesspam_parse_line($arguement, 'SUBJECT');
            foreach ($temp_tokens as $token) {
               $tokens[] = $token;
            }
            continue;
         }
   
         if (preg_match('/Content-Type/i',$header)) {
            if (preg_match('/multipart\//i',$arguement)) {
               $boundary = $arguement;
               if (preg_match('/boundary="(.*)"/',$arguement)) {
                  $i++;
                  $boundary = $lines[$i];
               }
               if (preg_match('/boundary="(.*)"/',$boundary,$matches)) {
                  $mime = $matches[1];
                  $mime = preg_replace('/(\+|\/|\?|\*|\||\(|\)|\[|\]|\{|\}|\^|\$|\.)/',$matches[1],$mime);
               }
            }
            $content_type = $arguement;
            continue;
         }
         if (preg_match('/Content-Transfer-Encoding/i',$header)) {
            $encoding = $arguement;
            continue;
         }
         if (preg_match('/X-Text-Classification/',$header)) {
            $tokens[] = 'TEXTCLASS: '.$arguement;
            continue;
         }
         if (preg_match('/(Thread-Index|X-UIDL|Message-ID|X-Text-Classification|X-Mime-Key)/i',$header)) {
            continue;
         }
         $temp_tokens = bayesspam_parse_line($arguement,'HEADER');
         foreach ($temp_tokens as $token) {
            $tokens[] = $token;
         }
      } else {
         if ($i < $hdr_lines) {
            $temp_tokens = bayesspam_parse_line($arguement,'HEADER');
         } else {
            $temp_tokens = bayesspam_parse_line($arguement,'BODY');
         }
         foreach ($temp_tokens as $token) {
            $tokens[] = $token;
         }
      }
   }

   return $tokens;
}

function bayesspam_learn_single(&$imap_stream, $mailbox, $passed_id, $type) {
   if ($GLOBALS['bayesdbhandle'] == null) {
      return;
   }

   $new_spam_occurences = array();
   $new_nonspam_occurences = array();
   $spam_occurences = bayesspam_get_occurences('spam');
   $nonspam_occurences = bayesspam_get_occurences('nonspam');

   $tokens = bayesspam_get_tokens($imap_stream,$passed_id);

   $added_spam = 0;
   $added_nonspam = 0;
   foreach ($tokens as $token) {
      if (strlen($token) > 0) {
         if ($type == 'spam') {
            $added_spam = 1;
            @$new_spam_occurences['token'.$token]++;
         } else {
            $added_nonspam = 1;
            @$new_nonspam_occurences['token'.$token]++;
         }
      }
   }

   if ($GLOBALS['bayesspam_do_stats'] && $GLOBALS['bayesspam_do_user_stats']) {
      $old_score = bayesspam_get_old_message_score();

      $res = $GLOBALS['bayesdbhandle']->query('SELECT UserName FROM '.$GLOBALS['bayesdbprefix'].'stats WHERE UserName=\''.$GLOBALS['bayes_username'].'\'');

      if ($GLOBALS['bayes_show_db_error'] && DB::isError($res)) {
         echo $res->getDebugInfo();
      }
      if (!DB::isError($res) && !($row = $res->fetchRow())) {
         $GLOBALS['bayesdbhandle']->query('INSERT INTO '.$GLOBALS['bayesdbprefix'].'stats SET StatsStart=NOW(),UserName=\''.$GLOBALS['bayes_username'].'\'');
      }

      if ($old_score[0] < .1 && $type == 'spam') {
         $GLOBALS['bayesdbhandle']->query('UPDATE '.$GLOBALS['bayesdbprefix'].'stats SET FalseNegatives=FalseNegatives+1 WHERE UserName=\''.$GLOBALS['bayes_username'].'\'');
      }
      if ($old_score[0] > .9 && $type == 'nonspam') {
         $GLOBALS['bayesdbhandle']->query('UPDATE '.$GLOBALS['bayesdbprefix'].'stats SET FalsePositives=FalsePositives+1 WHERE UserName=\''.$GLOBALS['bayes_username'].'\'');
      }
      if ($old_score[0] >= .1 && $old_score[0] <= .9 && $type == 'spam') {
         $GLOBALS['bayesdbhandle']->query('UPDATE '.$GLOBALS['bayesdbprefix'].'stats SET SpamUnsures=SpamUnsures+1 WHERE UserName=\''.$GLOBALS['bayes_username'].'\'');
      }
      if ($old_score[0] >= .1 && $old_score[0] <= .9 && $type == 'nonspam') {
         $GLOBALS['bayesdbhandle']->query('UPDATE '.$GLOBALS['bayesdbprefix'].'stats SET HamUnsures=HamUnsures+1 WHERE UserName=\''.$GLOBALS['bayes_username'].'\'');
      }
      if ($old_score[0] < .1 && $type == 'nonspam') {
         $GLOBALS['bayesdbhandle']->query('UPDATE '.$GLOBALS['bayesdbprefix'].'stats SET HamReinforcement=HamReinforcement+1 WHERE UserName=\''.$GLOBALS['bayes_username'].'\'');
      }
      if ($old_score[0] > .9 && $type == 'spam') {
         $GLOBALS['bayesdbhandle']->query('UPDATE '.$GLOBALS['bayesdbprefix'].'stats SET SpamReinforcement=SpamReinforcement+1 WHERE UserName=\''.$GLOBALS['bayes_username'].'\'');
      }
   }

   if ($added_nonspam) {
      bayesspam_do_learn('nonspam',$nonspam_occurences,$new_nonspam_occurences,1);
      bayesspam_cache_message_score( 0.0, $old_score[1]);
   }
   if ($added_spam) {
      bayesspam_do_learn('spam',$spam_occurences,$new_spam_occurences,1);
      bayesspam_cache_message_score( 1.00, $old_score[1]);
   }

   bayesspam_add_messageid($type);
}

function bayesspam_rebuild_corpus() {
   if ($GLOBALS['bayesdbhandle'] == null) {
      return;
   }

   $res = $GLOBALS['bayesdbhandle']->query('SELECT CASE (LastRebuild<LastAdd) WHEN 1 THEN 1 ELSE 0 END as Test FROM '.$GLOBALS['bayesdbprefix'].'users WHERE UserName=\''.$GLOBALS['bayes_username'].'\'');
   $row = $res->fetchRow();

   $sql_formula = 'CASE ( ( CASE ('.$GLOBALS['bayesdbprefix'].'spamOccurences.Frequency/'.$GLOBALS['bayesdbprefix'].'users.spamCount > 0.9999999999) WHEN 1 THEN 0.9999999999 ELSE ('.$GLOBALS['bayesdbprefix'].'spamOccurences.Frequency/'.$GLOBALS['bayesdbprefix'].'users.spamCount) END )/( ( CASE ('.$GLOBALS['bayesdbprefix'].'nonspamOccurences.Frequency/'.$GLOBALS['bayesdbprefix'].'users.nonspamCount > 0.9999999999) WHEN 1 THEN 0.9999999999 ELSE ('.$GLOBALS['bayesdbprefix'].'nonspamOccurences.Frequency/'.$GLOBALS['bayesdbprefix'].'users.nonspamCount) END ) + ( CASE ('.$GLOBALS['bayesdbprefix'].'spamOccurences.Frequency/'.$GLOBALS['bayesdbprefix'].'users.spamCount > 0.9999999999) WHEN 1 THEN 0.9999999999 ELSE ('.$GLOBALS['bayesdbprefix'].'spamOccurences.Frequency/'.$GLOBALS['bayesdbprefix'].'users.spamCount) END ) ) > 0.9999999999 ) WHEN 1 THEN 0.9999999999 ELSE ( CASE ( ( CASE ('.$GLOBALS['bayesdbprefix'].'spamOccurences.Frequency/'.$GLOBALS['bayesdbprefix'].'users.spamCount > 0.9999999999) WHEN 1 THEN 0.9999999999 ELSE ('.$GLOBALS['bayesdbprefix'].'spamOccurences.Frequency/'.$GLOBALS['bayesdbprefix'].'users.spamCount) END )/( ( CASE ('.$GLOBALS['bayesdbprefix'].'nonspamOccurences.Frequency/'.$GLOBALS['bayesdbprefix'].'users.nonspamCount > 0.9999999999) WHEN 1 THEN 0.9999999999 ELSE ('.$GLOBALS['bayesdbprefix'].'nonspamOccurences.Frequency/'.$GLOBALS['bayesdbprefix'].'users.nonspamCount) END ) + ( CASE ('.$GLOBALS['bayesdbprefix'].'spamOccurences.Frequency/'.$GLOBALS['bayesdbprefix'].'users.spamCount > 0.9999999999) WHEN 1 THEN 0.9999999999 ELSE ('.$GLOBALS['bayesdbprefix'].'spamOccurences.Frequency/'.$GLOBALS['bayesdbprefix'].'users.spamCount) END ) ) < 0.00001 ) WHEN 1 THEN 0.00001 ELSE ( ( CASE ('.$GLOBALS['bayesdbprefix'].'spamOccurences.Frequency/'.$GLOBALS['bayesdbprefix'].'users.spamCount > 0.9999999999) WHEN 1 THEN 0.9999999999 ELSE ('.$GLOBALS['bayesdbprefix'].'spamOccurences.Frequency/'.$GLOBALS['bayesdbprefix'].'users.spamCount) END )/( ( CASE ('.$GLOBALS['bayesdbprefix'].'nonspamOccurences.Frequency/'.$GLOBALS['bayesdbprefix'].'users.nonspamCount > 0.9999999999) WHEN 1 THEN 0.9999999999 ELSE ('.$GLOBALS['bayesdbprefix'].'nonspamOccurences.Frequency/'.$GLOBALS['bayesdbprefix'].'users.nonspamCount) END ) + ( CASE ('.$GLOBALS['bayesdbprefix'].'spamOccurences.Frequency/'.$GLOBALS['bayesdbprefix'].'users.spamCount > 0.9999999999) WHEN 1 THEN 0.9999999999 ELSE ('.$GLOBALS['bayesdbprefix'].'spamOccurences.Frequency/'.$GLOBALS['bayesdbprefix'].'users.spamCount) END ) ) ) END ) END';

   if ($row && $row['Test']) {
      $sql0 = 'DELETE FROM '.$GLOBALS['bayesdbprefix'].'nonspamOccurences WHERE UserName=\''.$GLOBALS['bayes_username'].'\' AND Frequency=0';
      $sql1 = 'DELETE FROM '.$GLOBALS['bayesdbprefix'].'spamOccurences WHERE UserName=\''.$GLOBALS['bayes_username'].'\' AND Frequency=0';
      $sql2 = 'DELETE FROM '.$GLOBALS['bayesdbprefix'].'Corpus WHERE UserName=\''.$GLOBALS['bayes_username'].'\'';
      $sql3 = 'UPDATE '.$GLOBALS['bayesdbprefix'].'users SET LastRebuild=NOW() WHERE UserName=\''.$GLOBALS['bayes_username'].'\'';
      $sql4 = 'DELETE FROM '.$GLOBALS['bayesdbprefix'].'Messages WHERE TO_DAYS(Added) <= (TO_DAYS(NOW()) - '.$GLOBALS['bayes_message_store_days'].')';
      $sql5 = 'DELETE FROM '.$GLOBALS['bayesdbprefix'].'ScoreCache WHERE TO_DAYS(Added) <= (TO_DAYS(NOW()) - '.$GLOBALS['bayes_cache_days'].')';

      $sql6 = 'INSERT INTO '.$GLOBALS['bayesdbprefix'].'Corpus SELECT '.$GLOBALS['bayesdbprefix'].'nonspamOccurences.UserName, '.$GLOBALS['bayesdbprefix'].'nonspamOccurences.Token, 0.00001, '.$GLOBALS['bayesdbprefix'].'nonspamOccurences.Frequency FROM '.$GLOBALS['bayesdbprefix'].'nonspamOccurences LEFT JOIN '.$GLOBALS['bayesdbprefix'].'spamOccurences USING (UserName,Token) WHERE '.$GLOBALS['bayesdbprefix'].'spamOccurences.UserName IS NULL AND '.$GLOBALS['bayesdbprefix'].'nonspamOccurences.Frequency>2 AND '.$GLOBALS['bayesdbprefix'].'nonspamOccurences.UserName=\''.$GLOBALS['bayes_username'].'\'';
      $sql7 = 'INSERT INTO '.$GLOBALS['bayesdbprefix'].'Corpus SELECT '.$GLOBALS['bayesdbprefix'].'spamOccurences.UserName, '.$GLOBALS['bayesdbprefix'].'spamOccurences.Token, 0.99999, '.$GLOBALS['bayesdbprefix'].'spamOccurences.Frequency FROM '.$GLOBALS['bayesdbprefix'].'spamOccurences LEFT JOIN '.$GLOBALS['bayesdbprefix'].'nonspamOccurences USING (UserName,Token) WHERE '.$GLOBALS['bayesdbprefix'].'nonspamOccurences.UserName IS NULL AND '.$GLOBALS['bayesdbprefix'].'spamOccurences.Frequency>2 AND '.$GLOBALS['bayesdbprefix'].'spamOccurences.UserName=\''.$GLOBALS['bayes_username'].'\'';
      $sql8 = 'INSERT INTO '.$GLOBALS['bayesdbprefix'].'Corpus SELECT '.$GLOBALS['bayesdbprefix'].'nonspamOccurences.UserName, '.$GLOBALS['bayesdbprefix'].'spamOccurences.Token, '.$sql_formula.' AS Total, '.$GLOBALS['bayesdbprefix'].'nonspamOccurences.Frequency+'.$GLOBALS['bayesdbprefix'].'spamOccurences.Frequency AS count FROM '.$GLOBALS['bayesdbprefix'].'nonspamOccurences INNER JOIN '.$GLOBALS['bayesdbprefix'].'spamOccurences USING (UserName,Token) INNER JOIN '.$GLOBALS['bayesdbprefix'].'users USING (UserName) WHERE '.$GLOBALS['bayesdbprefix'].'spamOccurences.Frequency > 1 AND '.$GLOBALS['bayesdbprefix'].'nonspamOccurences.Frequency > 1 AND '.$sql_formula.' != .5 AND '.$GLOBALS['bayesdbprefix'].'nonspamOccurences.UserName=\''.$GLOBALS['bayes_username'].'\'';

      $res = $GLOBALS['bayesdbhandle']->query($sql0);
      if ($GLOBALS['bayes_show_db_error'] && DB::isError($res)) {
         echo $res->getDebugInfo();
      }
      $res = $GLOBALS['bayesdbhandle']->query($sql1);
      if ($GLOBALS['bayes_show_db_error'] && DB::isError($res)) {
         echo $res->getDebugInfo();
      }
      $res = $GLOBALS['bayesdbhandle']->query($sql2);
      if ($GLOBALS['bayes_show_db_error'] && DB::isError($res)) {
         echo $res->getDebugInfo();
      }
      $res = $GLOBALS['bayesdbhandle']->query($sql3);
      if ($GLOBALS['bayes_show_db_error'] && DB::isError($res)) {
         echo $res->getDebugInfo();
      }
      $res = $GLOBALS['bayesdbhandle']->query($sql4);
      if ($GLOBALS['bayes_show_db_error'] && DB::isError($res)) {
         echo $res->getDebugInfo();
      }
      $res = $GLOBALS['bayesdbhandle']->query($sql5);
      if ($GLOBALS['bayes_show_db_error'] && DB::isError($res)) {
         echo $res->getDebugInfo();
      }
      $res = $GLOBALS['bayesdbhandle']->query($sql6);
      if ($GLOBALS['bayes_show_db_error'] && DB::isError($res)) {
         echo $res->getDebugInfo();
      }
      $res = $GLOBALS['bayesdbhandle']->query($sql7);
      if ($GLOBALS['bayes_show_db_error'] && DB::isError($res)) {
         echo $res->getDebugInfo();
      }
      $res = $GLOBALS['bayesdbhandle']->query($sql8);
      if ($GLOBALS['bayes_show_db_error'] && DB::isError($res)) {
         echo $res->getDebugInfo();
      }

      $_SESSION['bayesspam_corpus'] = bayesspam_get_corpus();
   }
}

function bayesspam_get_corpus() {
   if ($GLOBALS['bayesdbhandle'] == null) {
      return;
   }

   $sql = 'SELECT Token,Score,count FROM '.$GLOBALS['bayesdbprefix'].'Corpus WHERE UserName=\''.$GLOBALS['bayes_username'].'\'';

   $res = $GLOBALS['bayesdbhandle']->query($sql); 

   if ($GLOBALS['bayes_show_db_error'] && DB::isError($res)) {
      die ($res->getDebugInfo());
   }

   $corpus = array();
   while ($row = $res->fetchRow()) {
      $corpus['token'.$row['Token']] = array((float) $row['Score'],$row['count']);
   }

   return $corpus;
}

function bayesspam_get_interesting_tokens(&$tokens, &$corpus) {
   if ($GLOBALS['bayesdbhandle'] == null) {
      return;
   }

   $tokencount = 0;
   $interestingtokens = array();
   $used_tokens = array();
   foreach ($tokens as $value) {
      if (strlen($value) > 0) {
         if (isset($corpus['token'.$value]) && abs(0.5-$corpus['token'.$value][0]) > 0) {
            @$used_tokens[$value] += 1;
            if ($GLOBALS['bayes_token_repeats'] == 0 || $used_tokens[$value] <= $GLOBALS['bayes_token_repeats']) 
               $interestingtokens[]=array($tokencount,abs(0.5-$corpus['token'.$value][0]),$corpus['token'.$value][1]);
         }
      }
      $tokencount++;
   }
   unset($tokencount);
   unset($used_tokens);

   if (!function_exists('bayesspam_sort')) {
      function bayesspam_sort ($a,$b) {
         if ($a[1] == $b[1])
            return 0;
         if ($a[1] < $b[1])
            return 1;
         return -1;
      }
   }

   usort($interestingtokens,'bayesspam_sort');

   if ($GLOBALS['bayes_interesting_tokens']) {
      $most_interesting = array_slice($interestingtokens,0,$GLOBALS['bayesspam_interesting_tokens']);
   } else {
      $most_interesting = $interestingtokens;
   }
   unset($interestingtokens);

   if (isset($GLOBALS['bayes_in_display']) && $GLOBALS['bayes_in_display']) {
      $display_interesting = array();
      for ($i = 0; $i < count($most_interesting); $i++) {
         $display_interesting[] = array($tokens[$most_interesting[$i][0]],$corpus['token'.$tokens[$most_interesting[$i][0]]][0],$corpus['token'.$tokens[$most_interesting[$i][0]]][1]);
      }

      if (!function_exists('bayesspam_display_sort')) {
         function bayesspam_display_sort ($a,$b) {
            list($a_sort, $temp) = split(':',$a[0]);
            list($b_sort, $temp) = split(':',$b[0]);
            if ($a_sort == $b_sort) {
               if ($a[1] == $b[1]) {
                  if ($a[0] == $b[0])
                     return 0;
                  if ($a[0] < $b[0])
                     return -1;
                  return 1;
               }
               if ($a[1] < $b[1])
                  return 1;
               return -1;
            }
            if ($a_sort < $b_sort)
               return -1;
            return 1;
         }
      }

      usort($display_interesting, 'bayesspam_display_sort');

      $GLOBALS['token_display_string'] = '';
      $GLOBALS['token_html_display_string'] = '';

      $last_token = array();
      $last_token_count = 0;

      for($i = 0; $i < count($display_interesting); $i++) {
         if ($last_token && $last_token != $display_interesting[$i]) {
            if ($i > 0)
               $GLOBALS['token_display_string'] .= "\n";

            $GLOBALS['token_display_string'] .= htmlentities(trim($last_token[0])).' ('.$last_token_count.') : '.number_format(($last_token[1] * 100),2).'%';
   
            $text_color = "";

            if ($last_token[1] <= .10) {
               $text_color = ' color=#0000ff';
            } elseif ($last_token[1] <= .20) {
               $text_color = ' color=#3300ff';
            } elseif ($last_token[1] <= .30) {
               $text_color = ' color=#6600ff';
            } elseif ($last_token[1] <= .40) {
               $text_color = ' color=#9900ff';
            } elseif ($last_token[1] <= .50) {
               $text_color = ' color=#cc00ff';
            } elseif ($last_token[1] <= .60) {
               $text_color = ' color=#ff00cc';
            } elseif ($last_token[1] <= .70) {
               $text_color = ' color=#ff0099';
            } elseif ($last_token[1] <= .80) {
               $text_color = ' color=#ff0066';
            } elseif ($last_token[1] <= .90) {
               $text_color = ' color=#ff0033';
            } elseif ($last_token[1] <= 1.00) {
               $text_color = ' color=#ff0000';
            }
            list($type,$which_token) = split(':',$last_token[0]);
            $which_token = trim($which_token);
            $original_token = htmlentities($which_token);
            $which_token = (strlen($which_token)>20?(substr($which_token,0,17).'...'):($which_token));
            $GLOBALS['token_html_display_string'] .= '<tr><td><font'.$text_color.'>'.$type.'</font></td>';
            $GLOBALS['token_html_display_string'] .= '<td><span title=\"'.$original_token.'\"><font'.$text_color.'>'.htmlentities($which_token).'</font></span></td>';
            $GLOBALS['token_html_display_string'] .= '<td><font'.$text_color.'>'.$last_token_count.'</font></td>';
            $GLOBALS['token_html_display_string'] .= '<td align=right><font'.$text_color.'>'.number_format(($last_token[1] * 100),2).'%</font></td></tr>';

            $last_token = $display_interesting[$i];
            $last_token_count = 1;
         } else {
            if (!$last_token) {
               $last_token = $display_interesting[$i];
            }
            $last_token_count++;
         }
      }
   }

   return $most_interesting;
}

function bayesspam_get_probability(&$imapConnection, $message_id, $no_cache=0, $internal_use=1) {
   if ($GLOBALS['bayesdbhandle'] == null) {
      return;
   }

   bayesspam_set_message_id($imapConnection,$message_id);
   
   $GLOBALS['bayes_was_cached'] = 1;

   if ( ( ( $is_spam = bayesspam_get_old_message_score() ) === FALSE && $internal_use ) || $no_cache || (isset($_REQUEST['bayes_recache']) && $_REQUEST['bayes_recache'] == 1)) {
      if ($GLOBALS['bayesspam_do_timing']) {
         if (!isset($GLOBALS['bayes_parse_time']) || !$GLOBALS['bayes_parse_time']) {
            $GLOBALS['bayes_parse_time'] = 0;
            $GLOBALS['bayes_parsed_messages'] = 0;
         }
         list($fractions, $seconds) = split(" ",microtime());
         $start_time = $fractions + $seconds;
      }

      if (!isset($GLOBALS['bayesspam_tokens']) || !$GLOBALS['bayesspam_tokens'] || $GLOBALS['bayesspam_tokens_message'] != $message_id) {
         $GLOBALS['bayesspam_tokens'] = bayesspam_get_tokens($imapConnection,$message_id);
         $GLOBALS['bayesspam_tokens_message'] = $message_id;
      }
      
      if (!isset($_SESSION['bayesspam_corpus']) || !$_SESSION['bayesspam_corpus']) {
         $_SESSION['bayesspam_corpus'] = bayesspam_get_corpus();
      }

      $most_interesting = bayesspam_get_interesting_tokens($GLOBALS['bayesspam_tokens'], $_SESSION['bayesspam_corpus']);

      $prod = 1;
      $minus_one_prod = 1;
   
      $GLOBALS['bayes_scoring_tokens'] = 0;
      foreach ($most_interesting as $value) {
         $GLOBALS['bayes_scoring_tokens'] += $_SESSION['bayesspam_corpus']['token'.$GLOBALS['bayesspam_tokens'][$value[0]]][1];
         $prod *= $_SESSION['bayesspam_corpus']['token'.$GLOBALS['bayesspam_tokens'][$value[0]]][0];
         $minus_one_prod *= (1.0 - $_SESSION['bayesspam_corpus']['token'.$GLOBALS['bayesspam_tokens'][$value[0]]][0]);
      }

      unset($most_interesting);
   
      $is_spam = $prod / ($prod + $minus_one_prod);

      bayesspam_cache_message_score($is_spam,$GLOBALS['bayes_scoring_tokens']);
      $GLOBALS['bayes_was_cached'] = 0;

      if ($GLOBALS['bayesspam_do_timing']) {
         list($fractions, $seconds) = split(" ",microtime());
         $GLOBALS['bayes_parse_time'] += (($fractions + $seconds)-$start_time);
         $GLOBALS['bayes_parsed_messages']++;
      }
      return $is_spam;
   }
   $GLOBALS['bayes_scoring_tokens'] = $is_spam[1];
   return $is_spam[0];
}

function bayesspam_get_old_message_score() {
   if ($GLOBALS['bayesdbhandle'] == null) {
      return;
   }

   $sql = 'SELECT * FROM '.$GLOBALS['bayesdbprefix'].'ScoreCache WHERE UserName=\''.$GLOBALS['bayes_username'].'\' AND MessageID=\''.$GLOBALS['bayes_message_id'].'\'';
   $res = $GLOBALS['bayesdbhandle']->query($sql);

   $row = $res->fetchRow();
   
   if ($row) {
      return array($row['Score'],$row['Tokens']);
   } else {
      return FALSE;
   }
}

function bayesspam_cache_message_score($score,$tokens) {
   if ($GLOBALS['bayesdbhandle'] == null) {
      return;
   }

   if ($GLOBALS['bayesdbtype'] != 'mysql') {
      $sql1 = 'DELETE FROM '.$GLOBALS['bayesdbprefix'].'ScoreCache WHERE UserName=\''.$GLOBALS['bayes_username'].'\' AND MessageID=\''.$GLOBALS['bayes_message_id'].'\'';
      $sql2 = 'INSERT INTO '.$GLOBALS['bayesdbprefix'].'ScoreCache VALUES(\''.$GLOBALS['bayes_username'].'\',\''.$GLOBALS['bayes_message_id'].'\',\''.$score.'\',\''.$tokens.'\',NOW())';
      $res = $GLOBALS['bayesdbhandle']->query($sql1);
      $res = $GLOBALS['bayesdbhandle']->query($sql2);
   } else {
      $sql = 'REPLACE INTO '.$GLOBALS['bayesdbprefix'].'ScoreCache VALUES(\''.$GLOBALS['bayes_username'].'\',\''.$GLOBALS['bayes_message_id'].'\',\''.$score.'\',\''.$tokens.'\',NOW())';
      $res = $GLOBALS['bayesdbhandle']->query($sql);
   }
}

function bayesspam_rebuild_corpus_hook($args) {
   if ($GLOBALS['bayesdbhandle'] == null) {
      return;
   }

   $res = $GLOBALS['bayesdbhandle']->query('SELECT CASE (LastRebuild<(NOW() - INTERVAL '.$GLOBALS['bayes_autorebuild_timeout'].' MINUTE)) WHEN 1 THEN 1 ELSE 0 END as Rebuild FROM '.$GLOBALS['bayesdbprefix'].'users WHERE UserName=\''.$GLOBALS['bayes_username'].'\'');
   $row = $res->fetchRow();

   if ($row['Rebuild']) {
      if($args[0] == 'webmail_bottom' && $GLOBALS['bayesspam_rebuild_on_login']) {
         bayesspam_rebuild_corpus();
      }
   
      if($args[0] == 'left_main_after' && $GLOBALS['bayesspam_rebuild_on_refresh']) {
         bayesspam_rebuild_corpus();
      }
   }
}

// Load the settings
// Validate some of it (make '' into 'default', etc.)
function bayesspam_load() {
   $GLOBALS['bayesspam_links_enabled'] = getPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_links_enabled');
   $GLOBALS['bayesspam_filtering_enabled'] = getPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_filtering_enabled');
   $GLOBALS['bayesspam_delete'] = getPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_delete');
   $GLOBALS['bayesspam_folder'] = getPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_folder');
   $GLOBALS['bayesspam_rebuild_on_login'] = getPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_rebuild_on_login');
   $GLOBALS['bayesspam_rebuild_on_refresh'] = getPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_rebuild_on_refresh');
   $GLOBALS['bayesspam_show_prob'] = getPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_show_prob');
   $GLOBALS['bayesspam_scan_size'] = getPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_scan_size');
   $GLOBALS['bayesspam_inboxonly'] = getPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_inboxonly');
   $GLOBALS['bayesspam_do_user_stats'] = getPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_do_user_stats');
   $GLOBALS['bayesspam_do_uncertain_filtering'] = getPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_do_uncertain_filtering');
   $GLOBALS['bayesspam_uncertain_folder'] = getPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_uncertain_folder');
   $GLOBALS['bayesspam_show_spam_buttons'] = getPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_show_spam_buttons');
   $GLOBALS['bayesspam_prune_threshold'] = getPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_prune_threshold');

   if ($GLOBALS['bayesspam_prune_threshold'] == '') {
      setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_prune_threshold', 0);
   }
   if ($GLOBALS['bayesspam_scan_size'] == '') {
      $GLOBALS['bayesspam_scan_size'] = $GLOBALS['bayes_default_scan_size'];
      setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_scan_size', $GLOBALS['bayesspam_scan_size']);
   }
   $GLOBALS['bayesspam_ignore_folders'] = array();
   for ($i=0; $load = getPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_ignore_folders_'.$i); $i++) {
      $GLOBALS['bayesspam_ignore_folders'][$i] = $load;
   }

   if ($GLOBALS['bayesspam_folder'] == '') {
      $GLOBALS['bayesspam_folder'] = $GLOBALS['trash_folder'];
      setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_folder', $GLOBALS['bayesspam_folder']);
   }

   if(!$GLOBALS['bayesspam_ignore_folders']) {
      $GLOBALS['bayesspam_ignore_folders'][] = $GLOBALS['trash_folder'];
      $GLOBALS['bayesspam_ignore_folders'][] = $GLOBALS['bayesspam_folder'];

      if ($GLOBALS['bayesspam_uncertain_folder'])
         $GLOBALS['bayesspam_ignore_folders'][] = $GLOBALS['bayesspam_folder'];

      array_unique($GLOBALS['bayesspam_ignore_folders']);
      $temp = array();
      foreach ($GLOBALS['bayesspam_ignore_folders'] as $value) {
         $temp[] = $value;
      }	
      $GLOBALS['bayesspam_ignore_folders'] = $temp;

      for ($i=0; $i<count($GLOBALS['bayesspam_ignore_folders']); $i++) {
         setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_ignore_folders_'.$i, $GLOBALS['bayesspam_ignore_folders'][$i]);
      }
   } else {
      if (!in_array($GLOBALS['trash_folder'], $GLOBALS['bayesspam_ignore_folders'])) {
         $GLOBALS['bayesspam_ignore_folders'][] = $GLOBALS['trash_folder'];
         for ($i=0; $i<count($GLOBALS['bayesspam_ignore_folders']); $i++) {
            setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_ignore_folders_'.$i, $GLOBALS['bayesspam_ignore_folders'][$i]);
         }
      }
      if (!in_array($GLOBALS['bayesspam_folder'], $GLOBALS['bayesspam_ignore_folders'])) {
         $GLOBALS['bayesspam_ignore_folders'][] = $GLOBALS['bayesspam_folder'];
         for ($i=0; $i<count($GLOBALS['bayesspam_ignore_folders']); $i++) {
            setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_ignore_folders_'.$i, $GLOBALS['bayesspam_ignore_folders'][$i]);
         }
      }
      if ($GLOBALS['bayesspam_uncertain_folder'] && !in_array($GLOBALS['bayesspam_uncertain_folder'], $GLOBALS['bayesspam_ignore_folders'])) {
         $GLOBALS['bayesspam_ignore_folders'][] = $GLOBALS['bayesspam_uncertain_folder'];
         for ($i=0; $i<count($GLOBALS['bayesspam_ignore_folders']); $i++) {
            setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_ignore_folders_'.$i, $GLOBALS['bayesspam_ignore_folders'][$i]);
         }
      }
   }

   if ($GLOBALS['bayesspam_filtering_enabled'] || $GLOBALS['bayesspam_do_uncertain_filtering'] || $GLOBALS['bayesspam_delete']) {
      $res = $GLOBALS['bayesdbhandle']->query('SELECT nonspamCount FROM '.$GLOBALS['bayesdbprefix'].'users WHERE UserName=\''.$GLOBALS['bayes_username'].'\'');
      if (!DB::isError($res)) {
         $row = $res->fetchRow();
         $nonspamCount = $row['nonspamCount'];
      } else {
         echo $res->getDebugInfo();
      }

      $res = $GLOBALS['bayesdbhandle']->query('SELECT spamCount FROM '.$GLOBALS['bayesdbprefix'].'users WHERE UserName=\''.$GLOBALS['bayes_username'].'\'');
      if (!DB::isError($res)) {
         $row = $res->fetchRow();
         $spamCount = $row['spamCount'];
      } else {
         echo $res->getDebugInfo();
      }
   }

   if ($GLOBALS['bayesspam_filtering_enabled'] && $spamCount < $GLOBALS['bayesspam_min_spam_filter'] && $nonspamCount < $GLOBALS['bayesspam_min_nonspam_filter']) {
      $GLOBALS['bayesspam_filtering_enabled'] = FALSE;
      setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_filtering_enabled', '');
   }

   if ($GLOBALS['bayesspam_do_uncertain_filtering'] && $spamCount < $GLOBALS['bayesspam_min_spam_uncertain'] && $nonspamCount < $GLOBALS['bayesspam_min_nonspam_uncertain']) {
      $GLOBALS['bayesspam_do_uncertain_filtering'] = FALSE;
      setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_do_uncertain_filtering', '');
   }

   if ($GLOBALS['bayesspam_delete'] && $spamCount < $GLOBALS['bayesspam_min_spam_delete'] && $nonspamCount < $GLOBALS['bayesspam_min_nonspam_delete']) {
      $GLOBALS['bayesspam_delete'] = FALSE;
      setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_delete', '');
   }
}

function bayesspam_display() {
   if ($GLOBALS['bayesdbhandle'] == null) {
      return;
   }

   $GLOBALS['bayes_in_display'] = TRUE;
   $version_array = split('\.', $GLOBALS['version']);

   if ($GLOBALS['bayesspam_show_prob']) {
      bindtextdomain('bayesspam', SM_PATH . 'plugins/bayesspam/locale');
      textdomain('bayesspam');

      $is_spam = bayesspam_get_probability($GLOBALS['imapConnection'], $GLOBALS['passed_id'], 0, 0);
      if ($GLOBALS['bayesspam_do_timing'] && isset($GLOBALS['token_display_string'])) {
         $res = $GLOBALS['bayesdbhandle']->query('SELECT UserName FROM '.$GLOBALS['bayesdbprefix'].'stats WHERE UserName=\''.$GLOBALS['bayes_username'].'\'');
   
         if ($GLOBALS['bayes_show_db_error'] && DB::isError($res)) {
            echo $res->getDebugInfo();
         }
         if (!DB::isError($res) && !($row = $res->fetchRow())) {
            $GLOBALS['bayesdbhandle']->query('INSERT INTO '.$GLOBALS['bayesdbprefix'].'stats SET StatsStart=NOW(),UserName=\''.$GLOBALS['bayes_username'].'\'');
         }
   
         $GLOBALS['bayesdbhandle']->query('UPDATE '.$GLOBALS['bayesdbprefix'].'stats SET TimedMessages=TimedMessages+'.$GLOBALS['bayes_parsed_messages'].',TotalParseTime=TotalParseTime+'.$GLOBALS['bayes_parse_time'].' WHERE UserName=\''.$GLOBALS['bayes_username'].'\'');
      }

      $do_popup_link = 1;

      if (!isset($GLOBALS['token_display_string'])) {
         $GLOBALS['token_display_string'] = _("Recalculate score to view interesting tokens.");
         $do_popup_link = 0;
      }

      $s = '';

      if ($do_popup_link) {
         $s =<<<EOL
<script type="text/javascript">
<!--
function do_bayesspam_popup() {
   bayesspamWindow = window.open("","bayesspamWindow","scrollbars=yes,resizable=yes,width=450,height=725"); 
   bayesspamWindow.document.write("<HTML><HEAD><TITLE>BayesSpam Token Info</TITLE>
EOL;

   if ( !isset( $GLOBALS['custom_css'] ) || $GLOBALS['custom_css'] == 'none' ) {
      if ($GLOBALS['theme_css'] != '') {
         $s .= '<link rel=\"stylesheet\" type=\"text/css\" href=\"'.$GLOBALS['theme_css'].'\" />';
      }
   } else {
      $s .= '<link rel=\"stylesheet\" type=\"text/css\" href=\"'.$GLOBALS['base_uri'].'themes/css/'.$GLOBALS['custom_css'].'\" />';
   }

   $s .= '</HEAD><BODY bgcolor=#ffffff>");';

   $s .=<<<EOL
   bayesspamWindow.document.write("<table border=0 cellspacing=1 cellpadding=1 width=100%>");
   bayesspamWindow.document.write("{$GLOBALS['token_html_display_string']}");
   bayesspamWindow.document.write("</table>");
   bayesspamWindow.document.write("</BODY></HTML>");
   bayesspamWindow.document.close();
}
//-->
</script>
EOL;
      }

      $s .= '<TR BGCOLOR="'.$GLOBALS['color'][0].'">';

      if ($version_array[0] == 1 && $version_array[1] <= 2) {
         $s .=   '<TD align=right valign=top width=20%>'._("BayesSpam Probability").':</TD>'."\n";
         $s .=   '<TD align=left valign=top width=80% colspan=2>';
         if ($do_popup_link) {
            $s .= '<a href="javascript:do_bayesspam_popup()">';
         }
         $s .=   '<b title="'.$GLOBALS['token_display_string'].'">'.number_format((((float) $is_spam) * 100.0),2).'%';
         if ($GLOBALS['bayes_was_cached']) {
            if ($GLOBALS['bayes_scoring_tokens'])
               $s .= ' &plusmn;'.number_format((1/sqrt($GLOBALS['bayes_scoring_tokens']))*50,2).'%';
            $s .= ' (';
            $s .= _("Cached");
            $s .= ')';
         } else {
            if ($GLOBALS['bayes_scoring_tokens'])
               $s .= ' &plusmn;'.number_format( (1/sqrt($GLOBALS['bayes_scoring_tokens']))*100 ,2).'%';
            $s .= ' (';
            $s .= _("Calculated");
            $s .= ')';
         }
         $s .=   '</b></a></td>' . "\n";
      } else {
         $s .=   '<TD align=right valign=top width=20%><b>BayesSpam Probability:&nbsp;&nbsp;</b></TD>'."\n";
         $s .=   '<TD align=left valign=top width=80% colspan=2>';
         if ($do_popup_link) {
            $s .= '<a href="javascript:do_bayesspam_popup()">';
         }
         $s .= '<span title="'.$GLOBALS['token_display_string'].'">'.number_format((((float) $is_spam) * 100.0),2).'%';
         if ($GLOBALS['bayes_was_cached']) {
            if ($GLOBALS['bayes_scoring_tokens'])
               $s .= ' &plusmn;'.number_format((1/sqrt($GLOBALS['bayes_scoring_tokens']))*50,2).'%';
            $s .= ' (';
            $s .= _("Cached");
            $s .= ')';
         } else {
            if ($GLOBALS['bayes_scoring_tokens'])
               $s .= ' &plusmn;'.number_format((1/sqrt($GLOBALS['bayes_scoring_tokens']))*50,2).'%';
            $s .= ' (';
            $s .= _("Calculated");
            $s .= ')';
         }
         $s .=   '</span></a></td>' . "\n";
      }
      $s .= '</TR>';
   
      echo $s;

      bindtextdomain('squirrelmail', SM_PATH . 'locale');
      textdomain('squirrelmail');
   }
   
   if (!$GLOBALS['bayesspam_links_enabled']) {
      return;
   }

   bindtextdomain('bayesspam', SM_PATH . 'plugins/bayesspam/locale');
   textdomain('bayesspam');

   $s = '';

   $s .= '<TR BGCOLOR="'.$GLOBALS['color'][0].'">';
   if ($version_array[0] == 1 && $version_array[1] <= 2) {
      $s .=   '<TD align=right valign=top width=20%>'._("BayesSpam Links").':</TD>'."\n";
      $s .=   '<TD align=left valign=top width=80% colspan=2><b>';
   } else {
      $s .=   '<TD align=right valign=top width=20%><b>'._("BayesSpam Links").':&nbsp;&nbsp;</b></TD>'."\n";
      $s .=   '<TD align=left valign=top width=80% colspan=2>';	
   }
   
   if (!$GLOBALS['bayesspam_show_prob']) {
      $tokens = bayesspam_get_tokens($GLOBALS['imapConnection'],$GLOBALS['passed_id']);
   }

   $check = bayesspam_check_messageid();
   
   if ($check == 'spam') {
      $s .= _("Known As Spam").'</b> -- <small><a href="../plugins/bayesspam/bayesspam_learn.php?bayes_type=nonspam&passed_id='.urlencode($GLOBALS['passed_id']);
      $s .= '&mailbox='.urlencode($GLOBALS['mailbox']);
      $s .= '&startMessage='.urlencode($GLOBALS['startMessage']);
      $s .= '&show_more='.urlencode($GLOBALS['show_more']);
      $s .= '">'._("Move to NonSpam").'</a> | ';
   } elseif ($check == 'nonspam') {
      $s .= _("Known As NonSpam").'</b> -- <small><a href="../plugins/bayesspam/bayesspam_learn.php?bayes_type=spam&passed_id='.urlencode($GLOBALS['passed_id']);
      $s .= '&mailbox='.urlencode($GLOBALS['mailbox']);
      $s .= '&startMessage='.urlencode($GLOBALS['startMessage']);
      $s .= '&show_more='.urlencode($GLOBALS['show_more']);
      $s .= '">'._("Move to Spam").'</a> | ';	
   } else {
      $s .= _("Not In DB").'</b> -- <small><a href="../plugins/bayesspam/bayesspam_learn.php?bayes_type=spam&passed_id='.urlencode($GLOBALS['passed_id']);
      $s .= '&mailbox='.urlencode($GLOBALS['mailbox']);
      $s .= '&startMessage='.urlencode($GLOBALS['startMessage']);
      $s .= '&show_more='.urlencode($GLOBALS['show_more']);
      $s .= '">'._("Spam").'</a> | ';
      $s .= '<a href="../plugins/bayesspam/bayesspam_learn.php?bayes_type=nonspam&passed_id='.urlencode($GLOBALS['passed_id']);
      $s .= '&mailbox='.urlencode($GLOBALS['mailbox']);
      $s .= '&startMessage='.urlencode($GLOBALS['startMessage']);
      $s .= '&show_more='.urlencode($GLOBALS['show_more']);
      $s .= '">'._("NonSpam").'</a> | ';
   }
   $s .= '<a href="../src/read_body.php?passed_id='.urlencode($GLOBALS['passed_id']);
   $s .= '&mailbox='.urlencode($GLOBALS['mailbox']);
   $s .= '&startMessage='.urlencode($GLOBALS['startMessage']);
   $s .= '&show_more='.urlencode($GLOBALS['show_more']);
   $s .= '&bayes_recache=1">'._("Recalculate Score").'</a>';
   $s .= '</small></td>' . "\n";
   $s .= '</TR>';
   echo $s;

   bindtextdomain('squirrelmail', SM_PATH . 'locale');
   textdomain('squirrelmail');
}

// Show the link to our own custom options page
function bayesspam_options() {
   if ($GLOBALS['bayesdbhandle'] == null) {
      return;
   }

   bindtextdomain('bayesspam', SM_PATH . 'plugins/bayesspam/locale');
   textdomain('bayesspam');

   $GLOBALS['optpage_blocks'][] = array(
      'name' => _("BayesSpam - Intelligent Spam Filtering"),
      'url' => '../plugins/bayesspam/options.php',
      'desc' => _("An intelligent mail filter that actually learns what you consider to be spam. Uses Bayesian filtering to very accurately filter out your spam."),
      'js' => false
   );

   bindtextdomain('squirrelmail', SM_PATH . 'locale');
   textdomain('squirrelmail');
}

bayesspam_init();
?>
