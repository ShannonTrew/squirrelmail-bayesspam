# squirrelmail-bayesspam

SquirrelMail Bayesian SPAM filtering plugin
BayesSpam - Version 3.7.2

Intelligently filters spam.

Author: rorith@kydance.net

Features
========

* Dynamic filtering database - Defined by the individual user
* Can filter into folder of your choice
* Displays the calculated spam probability in the headers of a message
* Ignores folders that you choose, as well as the trash, and the folder
  it filters to.
* Tracks filtering stats to tell you how accurate it is
* Compatible with SM 1.4 & 1.5
* i18n Support

Requirements
============

* SquirrelMail 1.4
* PHP >= 4.3
* PEAR-DB
* Compatible DB (See Docs Link Below)
    Tested with MySQL and Postgres

* Compatible IMAP Servers
    Tested On:
      Courier IMAP
      Cyrus
      Mercury
      UW IMAP (Possible problems?)
    Others may work, but are untested

Description
===========

Uses a Bayesian filter to intelligently filter out spam. The database
used for the filter is built by each user, so it will only filter what
they consider to be spam.


Links
=====

Article the idea was taken from:
	http://www.paulgraham.com/spam.html

PEAR-DB Docs:
	http://vulcanonet.com/soft/?pack=pear_tut


Credits
=======

Much of the code was originally borrowed from the Filters plugin as well
as the SpamCop plugin. Thanks go to Justin Mitchell for sending the link
above to the SM-USERS mailing list and giving me the idea to write this.

Joseph Coffland <joseph@cauldrondevelopment.com> provided a patch to
improve word parsing speed.

George Vilches provided a patch to fix the mysterious 'not filtering' bug.

Justin Mitchell also provided the patch for MD5 message IDs, so that the
plugin no longer has to rely on the Message-ID header.

Ryan <ryan@vendetta.com> provided much useful feedback and many ideas
that were added in version 2.0. Thanks go to him for the performance
boost and many of the new 2.0 features.

Ryan rewrote bayesspam_filter.php to combine the two filtering functions
into one to improve performance. He also provided the code to handle
the 'Only Filter INBOX' option.

The bayesspam_get_tokens() function is translated directly from the
source code for POPFile, and is originally the work of 
John Graham-Cumming.

Jon S. Nelson for many patches and contributions.

Christian Frey for finding the problem with messages not being scored.

Brad J. Donison for the patch to add minimum Corpus requirements.

Thanks are also due to all the users who have downloaded and used this
plugin, sending me bug reports to help make it a better product.


Future Work
===========

* Optional message highlighting mode (Need hook first)
* More IMAP Servers supported (NEED HELP WITH THIS)
* Suggestions?


Installation
============

IMPORTANT!!!
IMPORTANT!!!
IMPORTANT!!!

SquirrelMail 1.4 is REQUIRED for this version.

Untar the plugin into the plugins directory. Inside you will find
'bayesspam.sql'. This file contains the table definitions that
BayesSpam needs added to a database in MySQL. Once you have them
added, go into config.php, and adjust the settings for your system.

Note that the sql script may need to be adjusted slightly for DBs
other than MySQL. This should be done by someone who is familiar
with both, and none of the names should be changed.

While there should not be any security holes in this plugin, I
suggest that you do /not/ use a MySQL user with access to everything.
I suggest you create a user just for BayesSpam, and only give it
access to the Database and/or tables that it needs to use.

Once you have completed the above, proceed with the typical path
of adding the plugin using the configure script, or adding it manually
to the config file.


Updating To 3.7.1
=================

Update SquirreMail to 1.4.

Update config_sample.php with your settings, and replace your old
config.php. This is required, due to code structure changes.

Make sure your DB matches the tables in bayesspam.sql. 2 new columns
have been added in 3.7

Then just enable the plugin in the SM config, if it isn't already.


From Pre-3.0 Versions
=====================

Due to changes in the message ID hashing, you might as well delete the
old Messages table and start with a fresh one, since the old hashes
won't match their messages anyway.

Due to massive changes in Token Parsing, all old tokens are now
invalidated. If you don't want to wipe out your corpus, don't update.
If you do want to update, wipe out the corpus, and start training from
scratch.



Adding Message List Flagging (1.4 ONLY)
=======================================

Edit functions/mailbox_display.php, and add the following lines near
line 725 just before the 'Delete' button
 (look for the group of SUBMIT buttons for marking messages):

    echo html_tag('br');
    echo getButton('SUBMIT', 'markSpam',_("Spam"));
    echo getButton('SUBMIT', 'markHam',_("NonSpam"));

Edit src/move_messages.php, and add the following lines:

Near line 140, add these lines: (Look for the big group of if statements)

sqgetGlobalVar('markSpam',        $markSpam,        SQ_POST); /* Added for BayesSpam */
sqgetGlobalVar('markHam',         $markHam,         SQ_POST); /* Added for BayesSpam */


Then near line 225, in this code:

        if (count($id) && !isset($attache)) {
           if (isset($markRead)) {
              sqimap_toggle_flag($imapConnection, $id, '\\Seen',true,true);
           } else if (isset($markUnread)) {
              sqimap_toggle_flag($imapConnection, $id, '\\Seen',false,true);
           } else  {
              sqimap_msgs_list_delete($imapConnection, $mailbox, $id);
              if ($auto_expunge) {
                 $cnt = sqimap_mailbox_expunge($imapConnection, $mailbox, true);
              }
           }
        }


Add these lines: (just before the '} else {' line)

           } else if (isset($markSpam)) {
               for ($i = 0; $i < count($id); $i++) {
                   bayesspam_learn_single($imapConnection, $mailbox, $id[$i], 'spam');
               }
               if ($GLOBALS['bayesspam_delete']) {
                   sqimap_msgs_list_delete($imapConnection, $mailbox, $id);
               } elseif (sqimap_mailbox_exists($imapConnection, $GLOBALS['bayesspam_folder'])) {
                   sqimap_msgs_list_copy($imapConnection, $id, $GLOBALS['bayesspam_folder']);
                   sqimap_msgs_list_delete($imapConnection, $mailbox, $id);
               }
           } else if (isset($markHam)) {
               for ($i = 0; $i < count($id); $i++) {
                   bayesspam_learn_single($imapConnection, $mailbox, $id[$i], 'nonspam');
               }


Changes
=======

3.7.2 - Update
    Changes:
        Improved word parsing speed.

3.7.1 - Update
	New Features:
		Consolidated Tokens display for a message.
		Added ability for admin to specify how many repeats of a token to allow.
	Changes:
		Moved initialization code to it's own function.
		Moved all logic out of setup.php
		Enables local locale data only when necessary

3.7 - Update
	New Features:
		Messages now display a margin of error based on token frequency.
		MOE is cached with the message score.
	Changes:
		Moved all logic out of config.php into setup.php
		Re-wrote spam purging to improve performance and reliability
		Improved i18n support. Still need some translations.
		Corrections now get stored in the statistics properly.
		After initial filtering pass, only filter messages from today

3.62 - Update
	Changes:
		Fixed DB Error Handling so it won't kill SM
		Added a couple missed DB prefix locations

3.6 - Update
	New Features:
		Automatic Purging of Old Spam
		SM 1.5 support
	Changes:
		Better i18n support
		Code structure changes to improve load times in SM.

3.41 - Update
	Changes:
		Removed a few extra lines of code, hopefully providing minor performace
			increases.
		Fixed a bug found in the logic for creating the Corpus.
		Various other improvements that I've forgotten in the months since I last
			updated.

3.4 - Update
	New Features:
		i18n Support
	Changes:
		Updated all SQL queries to work with Postgres
		Moved most of the code in setup.php into bayesspam_functions.php
		Fixed a few E_ALL errors

3.3 - Update
	New Features:
		Configurable minimum corpus requirements for some features
		Added IP Subnet Tokens
		Added HTML Tag Tokens
		Added Header-Type Tokens
	Changes:
		Removed Autolearn
		Switched to persistant DB connections to save a little time
		Fixed 0% Probability Bug
		Actually scores 3 character tokens now
		Corpus nuke resets your stats as well

3.2 - Update
	New Features:
		Added stats tracking
	Changes:
		Ported to SquirrelMail 1.4. No longer compatible with the SM 1.2 versions.

3.1.1 - Bugfix
	Changes:
		Fixed 'Spam Deletion' option to actually work, for the risktakers out there.

3.1 - Bugfix
	Changes:
		Fixed mysterious 'Not Filtering' bug.

3.0 - Major Version Update
	New Features:
		Completely new token parsing engine. (Thanks POPFile!)
		Message List Patch for flagging messages
	Changes:	
		Lots of small tweaks to the scoring code

2.2.1 - Bugfix Release
	Bug Fixes:
		Really does work with SquirrelMail 1.2.9 and rg=0 now.

2.2 - Maintenance Release
	New Features:
		Admin option to limit auto-rebuild frequency
		Admin option to set default scan size
		User option to only filter inbox (instead of setting up an ignore list)
	Bug Fixes/Changes:
		Added missing code to delete ScoreCache when user nukes DB
		Merged filtering functions into one to ease maintenance
		Autolearn optimizations
		Plugin is now rg=0 compliant
		The user's corpus is now stored in the session data for speed

2.1 - Development Version

2.0 - Major Overhaul
	New Features:
		MD5 message ID only uses Headers
		User option to control message size to scan
		Admin option to control max message size allowed
		Caching of scores to improve filter speed on messages it
			knows.
		Admin option to cleanup messages older than a set number
			of days.
		Link to recalculate score of a message

1.6 - Maintenance Release
	Small fix for missing $_REQUEST on old PHP versions

1.5 - Maintenance Release
	IMAP Command improvements
	SquirrelMail Version Checking
	SQL Connection Error Check

1.4 - More New Features
	Autolearn Improvements
	General Efficiency Improvements
	Customizable list of folders to ignore

1.3 - Several New Features
	Tracks what messages are added to the DB
	Moved links to be below the probability display
	Added ability to nuke your DB
	Added setting to affect the granularity of the DB

1.2 - Fixed bug in percentage calculation

1.1 - Many bugfixes, speed enhancments, code cleanup;
	Major Updates:
		Across the board speed enhancements
		Auto Learn speed improvement (I hope)
		Moved most code into functions to ease maintenance
		Added column to table specs to support a future feature

1.0 - Initial version

