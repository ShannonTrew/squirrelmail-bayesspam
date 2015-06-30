<?php
define('SM_PATH','../../');
include_once(SM_PATH . 'include/validate.php');
include_once(SM_PATH . 'functions/imap.php');
include_once(SM_PATH . 'functions/plugin.php');
include_once(SM_PATH . 'functions/page_header.php');
include_once(SM_PATH . 'functions/html.php');
include_once(SM_PATH . 'plugins/bayesspam/config.php');

$key = $_COOKIE['key'];
$onetimepad = $_SESSION['onetimepad'];
$username = $_SESSION['username'];
$delimiter = $_SESSION['delimiter'];

$imapConnection = sqimap_login($username, $key, $GLOBALS['imapServerAddress'], $GLOBALS['imapPort'], 10, $onetimepad); // the 10 is to hide the output
$boxes = sqimap_mailbox_list($imapConnection);
sqimap_logout($imapConnection);

displayPageHeader($GLOBALS['color'], 'None');

bindtextdomain('bayesspam', SM_PATH . 'plugins/bayesspam/locale');
textdomain('bayesspam');

$version_array = split('\.', $GLOBALS['version']);

if (!isset($_REQUEST)) {
   $_REQUEST = array_merge($HTTP_GET_VARS,$HTTP_POST_VARS);
}   

if (! isset($_REQUEST['action']))
   $_REQUEST['action'] = '';
if ($_REQUEST['action'] == 'links')
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_links_enabled', 1);
elseif ($_REQUEST['action'] == 'nolinks')
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_links_enabled', '');
elseif ($_REQUEST['action'] == 'filter')
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_filtering_enabled', 1);
elseif ($_REQUEST['action'] == 'nofilter')
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_filtering_enabled', '');
elseif ($_REQUEST['action'] == 'save')
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_delete', '');
elseif ($_REQUEST['action'] == 'delete')
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_delete', 1);
elseif ($_REQUEST['action'] == 'inboxonly')
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_inboxonly', 1);
elseif ($_REQUEST['action'] == 'noinboxonly')
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_inboxonly', '');
elseif ($_REQUEST['action'] == 'loginrebuild')
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_rebuild_on_login', 1);
elseif ($_REQUEST['action'] == 'nologinrebuild')
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_rebuild_on_login', '');
elseif ($_REQUEST['action'] == 'refreshrebuild')
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_rebuild_on_refresh', 1);
elseif ($_REQUEST['action'] == 'norefreshrebuild')
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_rebuild_on_refresh', '');
elseif ($_REQUEST['action'] == 'showspambuttons')
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_show_spam_buttons', 1);
elseif ($_REQUEST['action'] == 'noshowspambuttons')
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_show_spam_buttons', '');
elseif ($_REQUEST['action'] == 'showprob')
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_show_prob', 1);
elseif ($_REQUEST['action'] == 'noshowprob')
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_show_prob', '');
elseif ($_REQUEST['action'] == 'dostats')
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_do_user_stats', 1);
elseif ($_REQUEST['action'] == 'nodostats')
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_do_user_stats', '');
elseif ($_REQUEST['action'] == 'douncertain')
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_do_uncertain_filtering', 1);
elseif ($_REQUEST['action'] == 'nodouncertain')
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_do_uncertain_filtering', '');
elseif ($_REQUEST['action'] == 'folder' && isset($_REQUEST['folder']))
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_folder', $_REQUEST['folder']);
elseif ($_REQUEST['action'] == 'uncertainfolder' && isset($_REQUEST['uncertainfolder']))
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_uncertain_folder', $_REQUEST['uncertainfolder']);
elseif ($_REQUEST['action'] == 'set_prune' && isset($_REQUEST['prune_days'])) {
   if ((int) $_REQUEST['prune_days'] > 365) {
      $_REQUEST['prune_days'] = 365;
   }
   if ((int) $_REQUEST['prune_days'] < 0 || $_REQUEST['prune_days'] == '') {
      $_REQUEST['prune_days'] = 0;
   }
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_prune_threshold', $_REQUEST['prune_days']);
} elseif ($_REQUEST['action'] == 'set_scan_size' && isset($_REQUEST['scan_size'])) {
   if ((int) $_REQUEST['scan_size'] > $GLOBALS['bayes_max_size']) {
      $_REQUEST['scan_size'] = $GLOBALS['bayes_max_size'];
   }
   setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_scan_size', (int) $_REQUEST['scan_size']);
} elseif ($_REQUEST['action'] == 'ignore_add' && isset($_REQUEST['ignore_add'])) {
   $GLOBALS['bayesspam_ignore_folders'][] = $_REQUEST['ignore_add'];
   array_unique($GLOBALS['bayesspam_ignore_folders']);
   $temp = array();
   foreach ($GLOBALS['bayesspam_ignore_folders'] as $value) {
      $temp[] = $value;
   }
   $GLOBALS['bayesspam_ignore_folders'] = $temp;

   for ($i=0; $i<count($GLOBALS['bayesspam_ignore_folders']); $i++) {
      setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_ignore_folders_'.$i, $GLOBALS['bayesspam_ignore_folders'][$i]);
   }
} elseif ($_REQUEST['action'] == 'ignore_rem' && isset($_REQUEST['ignore_rem'])) {
   for ($i=0; $i<=count($GLOBALS['bayesspam_ignore_folders']); $i++) {
      removePref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_ignore_folders_'.$i);
   }

   $GLOBALS['bayesspam_ignore_folders'] = array_diff($GLOBALS['bayesspam_ignore_folders'], array($_REQUEST['ignore_rem']));
   
   $temp = array();
   foreach ($GLOBALS['bayesspam_ignore_folders'] as $value) {
      $temp[] = $value;
   }	
   $GLOBALS['bayesspam_ignore_folders'] = $temp;

   array_merge($GLOBALS['bayesspam_ignore_folders'], array());

   for ($i=0; $i<count($GLOBALS['bayesspam_ignore_folders']); $i++) {
      setPref($GLOBALS['data_dir'], $GLOBALS['username'], 'bayesspam_ignore_folders_'.$i, $GLOBALS['bayesspam_ignore_folders'][$i]);
   }
} elseif ($_REQUEST['action'] == 'rebuild') 
   bayesspam_rebuild_corpus();
elseif ($_REQUEST['action'] == 'empty')
   bayesspam_nuke_db();

bayesspam_load();

$res = $GLOBALS['bayesdbhandle']->query("SELECT nonspamCount FROM ".$GLOBALS['bayesdbprefix']."users WHERE UserName='".$GLOBALS['bayes_username']."'");
if (!DB::isError($res)) {
   $row = $res->fetchRow();
   $nonspamCount = $row['nonspamCount'];
} else {
   echo $res->getDebugInfo();
}

$res = $GLOBALS['bayesdbhandle']->query("SELECT spamCount FROM ".$GLOBALS['bayesdbprefix']."users WHERE UserName='".$GLOBALS['bayes_username']."'");
if (!DB::isError($res)) {
   $row = $res->fetchRow();
   $spamCount = $row['spamCount'];
} else {
   echo $res->getDebugInfo();
}

?>
<br>
<table width=95% align=center border=0 cellpadding=2 cellspacing=0>
   <tr>
      <td>
         <center><b><img src="bayesspam_small.png"></center>
      </td>
   </tr>
   <tr>
      <td bgcolor="<?php echo $GLOBALS['color'][0] ?>">
         <center><b><?php echo _("Options"); ?> - <?php echo _("BayesSpam Filtering"); ?></b></center>
      </td>
   </tr>
</table>
<br>
<table align=center>
   <?php if ($version_array[0] == 1 && $version_array[1] == 5) { ?>
   <tr>
      <td align=right>
         <?php echo _("Spam/Nonspam Message List Buttons are"); ?>:
      </td>
      <td>
         <?php if ($GLOBALS['bayesspam_show_spam_buttons']) { ?>
            <font color=darkgreen><?php echo _("Enabled"); ?></font> (<a href="options.php?action=noshowspambuttons"><?php echo _("Disable it"); ?></a>)
         <?php } else { ?>
            <font color=red><?php echo _("Disabled"); ?></font> (<a href="options.php?action=showspambuttons"><?php echo _("Enable it"); ?></a>)
         <?php } ?>
      </td>
   </tr>
   <?php } ?>
   <tr>
      <td align=right>
         <?php echo _("BayesSpam Probability Display is"); ?>:
      </td>
      <td>
         <?php if ($GLOBALS['bayesspam_show_prob']) { ?>
            <font color=darkgreen><?php echo _("Enabled"); ?></font> (<a href="options.php?action=noshowprob"><?php echo _("Disable it"); ?></a>)
         <?php } else { ?>
            <font color=red><?php echo _("Disabled"); ?></font> (<a href="options.php?action=showprob"><?php echo _("Enable it"); ?></a>)
         <?php } ?>
      </td>
   </tr>
   <tr>
      <td align=right>
         <?php echo _("BayesSpam Links are"); ?>:
      </td>
      <td>
         <?php if ($GLOBALS['bayesspam_links_enabled']) { ?>
            <font color=darkgreen><?php echo _("Enabled"); ?></font> (<a href="options.php?action=nolinks"><?php echo _("Disable it"); ?></a>)
         <?php } else { ?>
            <font color=red><?php echo _("Disabled"); ?></font> (<a href="options.php?action=links"><?php echo _("Enable it"); ?></a>)
         <?php } ?>
      </td>
   </tr>
   <tr>
      <td align=right>
         <?php echo _("BayesSpam Filtering is"); ?>:
      </td>
      <td>
         <?php if ($GLOBALS['bayesspam_filtering_enabled']) { ?>
            <font color=darkgreen><?php echo _("Enabled"); ?></font> (<a href="options.php?action=nofilter"><?php echo _("Disable it"); ?></a>)
         <?php } else {
            if (($nonspamCount >= $GLOBALS['bayesspam_min_nonspam_filter']) && ($spamCount >= $GLOBALS['bayesspam_min_spam_filter'])) { ?>
               <font color=red><?php echo _("Disabled"); ?></font> (<a href="options.php?action=filter"><?php echo _("Enable it"); ?></a>)
            <?php } else { ?>
               <font color=red><?php echo _("Disabled"); ?></font> (corpus not large enough to enable)
            <?php }
         } ?>
      </td>
   </tr>
   <?php if ($GLOBALS['bayesspam_filtering_enabled']) { ?>
      <tr>
         <td align=right>
            <?php echo _("Filtering Uncertain Messages is"); ?>:
         </td>
         <td>
            <?php if ($GLOBALS['bayesspam_do_uncertain_filtering']) { ?>
               <font color=darkgreen><?php echo _("Enabled"); ?></font> (<a href="options.php?action=nodouncertain"><?php echo _("Disable it"); ?></a>)
            <?php } else {
               if (($nonspamCount >= $GLOBALS['bayesspam_min_nonspam_uncertain']) && ($spamCount >= $GLOBALS['bayesspam_min_spam_uncertain'])) { ?>
                  <font color=red><?php echo _("Disabled"); ?></font> (<a href="options.php?action=douncertain"><?php echo _("Enable it"); ?></a>)
               <?php } else { ?>
                  <font color=red><?php echo _("Disabled"); ?></font> (corpus not large enough to enable)
               <?php }
            } ?>
         </td>
      </tr>
      <tr>
         <td align=right valign=top>
            <?php echo _("Spam Deletion is"); ?>:
         </td>
         <td valign=top>
            <?php if ($GLOBALS['bayesspam_delete']) { ?>
               <font color=darkgreen><?php echo _("Enabled"); ?></font> (<a href="options.php?action=save"><?php echo _("Disable it"); ?></a>)
            <?php } else {
               if (($nonspamCount >= $GLOBALS['bayesspam_min_nonspam_delete']) && ($spamCount >= $GLOBALS['bayesspam_min_spam_delete'])) { ?>
                  <font color=red><?php echo _("Disabled"); ?></font> (<a href="options.php?action=delete"><?php echo _("Enable it"); ?></a>)
               <?php } else { ?>
                  <font color=red><?php echo _("Disabled"); ?></font> (corpus not large enough to enable)
               <?php } 
            } ?>
         </td>
      </tr>
   <?php } ?>
   <tr>
      <td align=right valign=top>
         <?php echo _("Rebuild On Folder Refresh is"); ?>:
      </td>
      <td valign=top>
         <?php if ($GLOBALS['bayesspam_rebuild_on_refresh']) { ?>
            <font color=darkgreen><?php echo _("Enabled"); ?></font> (<a href="options.php?action=norefreshrebuild"><?php echo _("Disable it"); ?></a>)
         <?php } else { ?>
            <font color=red><?php echo _("Disabled"); ?></font> (<a href="options.php?action=refreshrebuild"><?php echo _("Enable it"); ?></a>)
         <?php } ?>
      </td>
   </tr>
   <tr>
      <td align=right valign=top>
         <?php echo _("Rebuild On Login is"); ?>:
      </td>
      <td valign=top>
         <?php if ($GLOBALS['bayesspam_rebuild_on_login']) { ?>
            <font color=darkgreen><?php echo _("Enabled"); ?></font> (<a href="options.php?action=nologinrebuild"><?php echo _("Disable it"); ?></a>)
         <?php } else { ?>
            <font color=red><?php echo _("Disabled"); ?></font> (<a href="options.php?action=loginrebuild"><?php echo _("Enable it"); ?></a>)
         <?php } ?>
      </td>
   </tr>
   <tr>
      <td align=right valign=top>
         <?php echo _("Stats Tracking is"); ?>:
      </td>
      <td valign=top>
         <?php if ($GLOBALS['bayesspam_do_user_stats']) { ?>
            <font color=darkgreen><?php echo _("Enabled"); ?></font> (<a href="options.php?action=nodostats"><?php echo _("Disable it"); ?></a>)
         <?php } else { ?>
            <font color=red><?php echo _("Disabled"); ?></font> (<a href="options.php?action=dostats"><?php echo _("Enable it"); ?></a>)
         <?php } ?>
      </td>
   </tr>
   <tr><td>&nbsp;</td></tr>
   <?php $bl = strlen($GLOBALS['bayes_max_size']); ?>
   <?php if ($GLOBALS['bayes_user_scan_size'] == TRUE) { ?>
   <tr>
      <td align=right>
         <?php echo _("Bytes of message to scan"); ?>:<br>
         <?php echo _("Maximum"); ?>: <?php echo $GLOBALS['bayes_max_size']; ?>
      </td>
      <form method=post action=options.php>
         <td>
            <input type=text name=scan_size size=<?php echo $bl+2; ?> maxlength=<?php echo $bl; ?> value="<?php echo $GLOBALS['bayesspam_scan_size']; ?>">
            <input type=hidden name=action value="set_scan_size"></td><td>
            <input type=submit value="<?php echo _("Save"); ?>">
         </td>
      </form>
   </tr>
   <?php } ?>
   <tr><td>&nbsp;</td></tr>
   <tr>
      <td align=right>
         <?php echo _("Delete Spam older than X Days"); ?>:<br>
         (<?php echo _("0 to disable"); ?>)
      </td>
      <form method=post action=options.php>
         <td>
            <input type=text name=prune_days size=<?php echo $bl+2; ?> maxlength=3 value="<?php echo $GLOBALS['bayesspam_prune_threshold']; ?>">
            <input type=hidden name=action value="set_prune"></td><td>
            <input type=submit value="<?php echo _("Save"); ?>">
         </td>
      </form>
   </tr>
   <tr><td>&nbsp;</td></tr>
   <tr>
      <td align=right>
         <?php echo _("Folder To Filter Into"); ?>:
      </td>
      <form method=post action=options.php>
         <td>
            <select name=folder>
               <?php
                  for ($i = 0; $i < count($boxes); $i++) {
                     if (! in_array('noselect', $boxes[$i]['flags'])) {
                        $box = $boxes[$i]['unformatted'];
                        $box2 = str_replace(' ', '&nbsp;', $boxes[$i]['formatted']);
                        if (isset($GLOBALS['bayesspam_folder']) && $GLOBALS['bayesspam_folder'] == $box)
                           echo "<OPTION VALUE=\"$box\" SELECTED>$box2</option>";
                        else
                           echo "<OPTION VALUE=\"$box\">$box2</option>";
                     }
                  }
               ?>
            </select>
         </td>
         <td>
            <input type=hidden name=action value=folder>
            <input type=submit value="<?php echo _("Save"); ?>">
         </td>
      </form>
   </tr>
   <tr><td>&nbsp;</td></tr>
   <?php if ($GLOBALS['bayesspam_do_uncertain_filtering']) { ?>
      <tr>
         <td align=right>
            <?php echo _("Folder To Filter Uncertain Messages Into"); ?>:
         </td>
         <form method=post action=options.php>
            <td>
               <select name=uncertainfolder>
                  <?php
                     for ($i = 0; $i < count($boxes); $i++) {
                        if (! in_array('noselect', $boxes[$i]['flags'])) {
                           $box = $boxes[$i]['unformatted'];
                           $box2 = str_replace(' ', '&nbsp;', $boxes[$i]['formatted']);
                           if (isset($GLOBALS['bayesspam_uncertain_folder']) && $GLOBALS['bayesspam_uncertain_folder'] == $box)
                              echo "<OPTION VALUE=\"$box\" SELECTED>$box2</option>";
                           else
                              echo "<OPTION VALUE=\"$box\">$box2</option>";
                        }
                     }
                  ?>
               </select>
            </td>
            <td>
               <input type=hidden name=action value=uncertainfolder>
               <input type=submit value="<?php echo _("Save"); ?>">
            </td>
         </form>
      </tr>
      <tr><td>&nbsp;</td></tr>
   <?php } ?>
   <tr>
      <td align=right valign=top>
         <?php echo _("Check Inbox Only"); ?>:
      </td>
      <td valign=top>
         <?php if ($GLOBALS['bayesspam_inboxonly']) { ?>
            <font color=darkgreen><?php echo _("Enabled"); ?></font> (<a href="options.php?action=noinboxonly"><?php echo _("Disable it"); ?></a>)
         <?php } else { ?>
            <font color=red><?php echo _("Disabled"); ?></font> (<a href="options.php?action=inboxonly"><?php echo _("Enable it"); ?></a>)
         <?php } ?>
      </td>
   </tr>
   <?php if (!$GLOBALS['bayesspam_inboxonly']) { ?>
      <tr>
         <td align=right>
            <?php echo _("Add Folder To Ignore List");?>:
         </td>
         <form method=post action=options.php>
            <td>
               <select name=ignore_add>
                  <?php
                     for ($i = 0; $i < count($boxes); $i++) {
                        if (!(in_array('noselect', $boxes[$i]['flags'])) && $boxes[$i]['unformatted'] != $GLOBALS['trash_folder'] && $boxes[$i]['unformatted'] != $GLOBALS['bayesspam_folder'] && !(in_array($boxes[$i]['unformatted'],$GLOBALS['bayesspam_ignore_folders']))) {
                           $box = $boxes[$i]['unformatted'];
                           $box2 = str_replace(' ', '&nbsp;', $boxes[$i]['formatted']);
                           echo "<OPTION VALUE=\"$box\">$box2</option>";
                        }
                     }
                  ?>
               </select>
            </td>
            <td>
               <input type=hidden name=action value=ignore_add>
               <input type=submit value="<?php echo _("Save"); ?>">
            </td>
         </form>
      </tr>
      <tr>
         <td align=right>
            <?php echo _("Remove Folder From Ignore List"); ?>:
         </td>
         <form method=post action=options.php>
            <td>
               <select name=ignore_rem>
                  <?php
                     for ($i = 0; $i < count($GLOBALS['bayesspam_ignore_folders']); $i++) {
                        if ($GLOBALS['bayesspam_ignore_folders'][$i] != $GLOBALS['trash_folder'] && $GLOBALS['bayesspam_ignore_folders'][$i] != $GLOBALS['bayesspam_folder']) {
                           $box = $GLOBALS['bayesspam_ignore_folders'][$i];
                           $box2 = str_replace(' ', '&nbsp;', $GLOBALS['bayesspam_ignore_folders'][$i]);
                           echo "<OPTION VALUE=\"$box\">$box2</option>";
                        }
                     }
                  ?>
               </select>
            </td>
            <td>
               <input type=hidden name=action value=ignore_rem>
               <input type=submit value="<?php echo _("Save"); ?>">
            </td>
         </form>
      </tr>
   <?php } ?>
   <tr>
      <td align=center colspan=2>
         &nbsp;<p>
         <b><?php echo _("Spam Database Stats"); ?>:</b><br>
         <table>
            <?php if ($GLOBALS['bayesspam_filtering_enabled']) { ?>
               <tr>
                  <td align=right>
                     <?php echo _("Total Mail Filtered"); ?>:
                  </td>
                  <td align=right>
                     <?php
                        $res = $GLOBALS['bayesdbhandle']->query("SELECT TotalMessages FROM ".$GLOBALS['bayesdbprefix']."stats WHERE UserName='".$GLOBALS['bayes_username']."'");
                        if (!DB::isError($res)) {
                           $row = $res->fetchRow();
                           echo $row['TotalMessages'];
                        } else {
                           echo $res->getDebugInfo();
                        }
                     ?>
                  </td>
               </tr>
               <tr>
                  <td align=right>
                     <?php echo _("Total Filtered as Spam"); ?>:
                  </td>
                  <td align=right>
                     <?php
                        $res = $GLOBALS['bayesdbhandle']->query("SELECT SpamMessages FROM ".$GLOBALS['bayesdbprefix']."stats WHERE UserName='".$GLOBALS['bayes_username']."'");
                        if (!DB::isError($res)) {
                           $row = $res->fetchRow();
                           echo $row['SpamMessages'];
                        } else {
                           echo $res->getDebugInfo();
                        }
                     ?>
                  </td>
               </tr>
               <tr>
                  <td align=right>
                     <?php echo _("Total Filtered as NonSpam"); ?>:
                  </td>
                  <td align=right>
                     <?php
                        $res = $GLOBALS['bayesdbhandle']->query("SELECT HamMessages FROM ".$GLOBALS['bayesdbprefix']."stats WHERE UserName='".$GLOBALS['bayes_username']."'");
                        if (!DB::isError($res)) {
                           $row = $res->fetchRow();
                           echo $row['HamMessages'];
                        } else {
                           echo $res->getDebugInfo();
                        }
                     ?>
                  </td>
               </tr>
               <tr>
                  <td align=right>
                     <?php echo _("Filtered As Unsure"); ?>:
                  </td>
                  <td align=right>
                     <?php
                        $res = $GLOBALS['bayesdbhandle']->query("SELECT UnsureMessages FROM ".$GLOBALS['bayesdbprefix']."stats WHERE UserName='".$GLOBALS['bayes_username']."'");
                        if (!DB::isError($res)) {
                           $row = $res->fetchRow();
                           echo $row['UnsureMessages'];
                        } else {
                           echo $res->getDebugInfo();
                        }
                     ?>
                  </td>
               </tr>
               <tr>
                  <td align=right>
                     <?php echo _("False Positives"); ?>:
                  </td>
                  <td align=right>
                     <?php
                        $res = $GLOBALS['bayesdbhandle']->query("SELECT FalsePositives FROM ".$GLOBALS['bayesdbprefix']."stats WHERE UserName='".$GLOBALS['bayes_username']."'");
                        if (!DB::isError($res)) {
                           $row = $res->fetchRow();
                           echo $row['FalsePositives'];
                        } else {
                           echo $res->getDebugInfo();
                        }
                     ?>
                  </td>
               </tr>
               <tr>
                  <td align=right>
                     <?php echo _("False Negatives"); ?>:
                  </td>
                  <td align=right>
                     <?php
                        $res = $GLOBALS['bayesdbhandle']->query("SELECT FalseNegatives FROM ".$GLOBALS['bayesdbprefix']."stats WHERE UserName='".$GLOBALS['bayes_username']."'");
                        if (!DB::isError($res)) {
                           $row = $res->fetchRow();
                           echo $row['FalseNegatives'];
                        } else {
                           echo $res->getDebugInfo();
                        }
                     ?>
                  </td>
               </tr>
               <tr>
                  <td align=right>
                     <?php echo _("Overall Accuracy (Based on Corrections)"); ?>:
                  </td>
                  <td align=right>
                     <?php
                        $res = $GLOBALS['bayesdbhandle']->query("SELECT ( ( ( TotalMessages - FalseNegatives - FalsePositives - UnsureMessages ) / (TotalMessages - UnsureMessages) ) * 100 ) AS Accuracy FROM ".$GLOBALS['bayesdbprefix']."stats WHERE UserName='".$GLOBALS['bayes_username']."'");
                        if (!DB::isError($res)) {
                           $row = $res->fetchRow();
                           echo $row['Accuracy']."%";
                        } else {
                           echo $res->getDebugInfo();
                        }
                     ?>
                  </td>
               </tr>
               <?php if ($GLOBALS['bayesspam_do_timing']) { ?>
                  <tr>
                     <td align=right>
                        <?php echo _("Average Parse Time / Message"); ?>:
                     </td>
                     <td align=right>
                        <?php
                           $res = $GLOBALS['bayesdbhandle']->query("SELECT ( TotalParseTime/TimedMessages ) AS Time FROM ".$GLOBALS['bayesdbprefix']."stats WHERE UserName='".$GLOBALS['bayes_username']."'");
                           if (!DB::isError($res)) {
                              $row = $res->fetchRow();
                              echo number_format($row['Time'],4).' sec/mess';
                           } else {
                              echo $res->getDebugInfo();
                           }
                        ?>
                     </td>
                  </tr>
               <?php } ?>
            <?php } ?>
            <tr>
               <td align=right>
                  <?php echo _("NonSpam Emails in Corpus"); ?>:
               </td>
               <td align=right>
                  <?php echo $nonspamCount; ?>
               </td>
            </tr>
            <tr>
               <td align=right>
                  <?php echo _("Spam Emails in Corpus"); ?>:
               </td>
               <td align=right>
                  <?php echo $spamCount; ?>
               </td>
            </tr>
            <tr>
               <td align=right>
                  <?php echo _("Last Database Rebuild"); ?>:
               </td>
               <td align=right>
                  <?php
                     $res = $GLOBALS['bayesdbhandle']->query("SELECT LastRebuild FROM ".$GLOBALS['bayesdbprefix']."users WHERE UserName='".$GLOBALS['bayes_username']."'");
                     if (!DB::isError($res)) {
                        $row = $res->fetchRow();
                        echo $row['LastRebuild'];
                     } else {
                        echo $res->getDebugInfo();
                     }
                  ?>
               </td>
            </tr>
            <tr>
               <td align=right>
                  <?php echo _("Last Mail Added"); ?>:
               </td>
               <td align=right>
                  <?php
                     $res = $GLOBALS['bayesdbhandle']->query("SELECT LastAdd FROM ".$GLOBALS['bayesdbprefix']."users WHERE UserName='".$GLOBALS['bayes_username']."'");
                     if (!DB::isError($res)) {
                        $row = $res->fetchRow();
                        echo $row['LastAdd'];
                     } else {
                        echo $res->getDebugInfo();
                     }
                  ?>
               </td>
            </tr>
         </table>
      </td>
   </tr>
   <tr><td>&nbsp;</td></tr>
   <?php
   $res = $GLOBALS['bayesdbhandle']->query("SELECT IF((LastRebuild < LastAdd),1,0) AS CheckUpdate FROM ".$GLOBALS['bayesdbprefix']."users WHERE UserName='".$GLOBALS['bayes_username']."'");
   $row = $res->fetchRow();
   if ($row['CheckUpdate']) { ?>
      <tr>
         <td align=center colspan=2>
            <b><a href="options.php?action=rebuild"><?php echo _("Rebuild Filtering Database Now"); ?></a></b>
         </td>
      </tr>
   <?php }
   if ($GLOBALS['bayes_allow_db_nuke']) { ?>
      <tr>
         <td align=center colspan=2>
            <b><a href="options.php?action=empty"><?php echo _("Empty Database Now"); ?></a></b>
         </td>
      </tr>
   <?php } ?>
</table><p>
<?php print _("To start using BayesSpam Intelligent Filtering, you must first build up a database of what you consider to be spam, and what you consider to not be spam.") . ' ';
print _("To do this, enable the links. When you read an email, there will be two new links to the upper right, to let you mark the mail as Spam or NonSpam.") . ' ';
print _("Use them a few dozen times on both spam and nonspam emails before going any further. The more times you use them, the more accurate your filtering will become.");
print '<p>' . _("Once you have a few dozen emails in your database (Come here to check your DB stats) you can enable the filtering itself.") . '<p>';

print '<b>' . _("Spam Deletion") . '</b>: ' . _("This option is somewhat dangerous, since if you get a false positive, it would delete the message without ever telling you. False positives should not happen, but you use this option at your own risk. This option requires Filtering to be turned on as well.") . '<p>';
print '<b>' . _("Ignore List") . '</b>: ' . _("Folders to not run the filtering on. This is not 100% reliable, since BayesSpam may load before the main Filters plugin, thus giving it a chance to run on the messages in the inbox before they've been moved to their own folders.") . '<p>';

if ($GLOBALS['bayes_allow_db_nuke']) {
print '<b>' . _("Empty Database") . '</b>: ' . _("Do this if you for some reason want to wipe out your current spam DB and start all over.") . '<p>';
} ?>
</body></html>
