# phpMyAdmin MySQL-Dump
# version 2.5.0
# http://www.phpmyadmin.net/ (download page)
#
# Host: localhost
# Generation Time: May 21, 2003 at 03:22 PM
# Server version: 4.0.12
# PHP Version: 4.3.1
# Database : `spamCorpus`
# --------------------------------------------------------

#
# Table structure for table `Corpus`
#
# Creation: May 12, 2003 at 04:05 PM
# Last update: May 21, 2003 at 03:10 PM
# Last check: May 21, 2003 at 11:55 AM
#

CREATE TABLE IF NOT EXISTS `Corpus` (
  `UserName` varchar(175) NOT NULL default '',
  `Token` varchar(128) binary NOT NULL default '',
  `Score` float unsigned NOT NULL default '0',
  `count` bigint(20) unsigned NOT NULL default '0',
  PRIMARY KEY  (`UserName`,`Token`)
) TYPE=MyISAM PACK_KEYS=1;
# --------------------------------------------------------

#
# Table structure for table `Messages`
#
# Creation: May 12, 2003 at 03:55 PM
# Last update: May 21, 2003 at 03:08 PM
# Last check: May 21, 2003 at 11:55 AM
#

CREATE TABLE `Messages` (
  `UserName` varchar(175) NOT NULL default '',
  `MessageID` varchar(32) NOT NULL default '',
  `Type` enum('spam','nonspam') NOT NULL default 'spam',
  `Added` timestamp(14) NOT NULL,
  PRIMARY KEY  (`UserName`,`MessageID`)
) TYPE=MyISAM;
# --------------------------------------------------------

#
# Table structure for table `ScoreCache`
#
# Creation: May 21, 2003 at 01:37 PM
# Last update: May 21, 2003 at 03:21 PM
#

CREATE TABLE IF NOT EXISTS `ScoreCache` (
  `UserName` varchar(175) NOT NULL default '',
  `MessageID` varchar(32) NOT NULL default '',
  `Score` float unsigned NOT NULL default '0',
  `Tokens` bigint(20) unsigned NOT NULL default '0',
  `Added` timestamp(14) NOT NULL,
  PRIMARY KEY  (`UserName`,`MessageID`)
) TYPE=MyISAM;
# --------------------------------------------------------

#
# Table structure for table `nonspamOccurences`
#
# Creation: Sep 25, 2002 at 05:04 PM
# Last update: May 21, 2003 at 03:08 PM
# Last check: May 21, 2003 at 11:55 AM
#

CREATE TABLE `nonspamOccurences` (
  `UserName` varchar(175) NOT NULL default '',
  `Token` varchar(128) binary NOT NULL default '',
  `Frequency` bigint(20) unsigned NOT NULL default '0',
  `LastUpdate` timestamp(14) NOT NULL,
  PRIMARY KEY  (`UserName`,`Token`)
) TYPE=MyISAM;
# --------------------------------------------------------

#
# Table structure for table `spamOccurences`
#
# Creation: Sep 25, 2002 at 05:04 PM
# Last update: May 21, 2003 at 11:57 AM
# Last check: May 21, 2003 at 11:55 AM
#

CREATE TABLE `spamOccurences` (
  `UserName` varchar(175) NOT NULL default '',
  `Token` varchar(128) binary NOT NULL default '',
  `Frequency` bigint(20) unsigned NOT NULL default '0',
  `LastUpdate` timestamp(14) NOT NULL,
  PRIMARY KEY  (`UserName`,`Token`)
) TYPE=MyISAM;
# --------------------------------------------------------

#
# Table structure for table `stats`
#
# Creation: May 21, 2003 at 03:10 PM
# Last update: May 21, 2003 at 03:21 PM
#

CREATE TABLE `stats` (
  `UserName` varchar(175) NOT NULL default '',
  `StatsStart` datetime NOT NULL default '0000-00-00 00:00:00',
  `TotalMessages` bigint(20) unsigned NOT NULL default '0',
  `HamMessages` bigint(20) unsigned NOT NULL default '0',
  `SpamMessages` bigint(20) unsigned NOT NULL default '0',
  `UnsureMessages` bigint(20) unsigned NOT NULL default '0',
  `FalsePositives` bigint(20) unsigned NOT NULL default '0',
  `FalseNegatives` bigint(20) unsigned NOT NULL default '0',
  `SpamUnsures` bigint(20) unsigned NOT NULL default '0',
  `HamUnsures` bigint(20) unsigned NOT NULL default '0',
  `HamReinforcement` bigint(20) unsigned NOT NULL default '0',
  `SpamReinforcement` bigint(20) unsigned NOT NULL default '0',
  `TimedMessages` bigint(20) unsigned NOT NULL default '0',
  `TotalParseTime` double unsigned NOT NULL default '0',
  PRIMARY KEY  (`UserName`)
) TYPE=MyISAM COMMENT='Stats tracking for BayesSpam Squirrelmail Plugin';

# --------------------------------------------------------

#
# Table structure for table `users`
#
# Creation: Aug 28, 2002 at 09:30 PM
# Last update: May 21, 2003 at 03:10 PM
# Last check: May 21, 2003 at 11:55 AM
#

CREATE TABLE `users` (
  `UserName` varchar(175) NOT NULL default '',
  `nonspamCount` bigint(20) unsigned NOT NULL default '0',
  `spamCount` bigint(20) unsigned NOT NULL default '0',
  `LastRebuild` datetime NOT NULL default '0000-00-00 00:00:00',
  `LastAdd` datetime NOT NULL default '0000-00-00 00:00:00',
  PRIMARY KEY  (`UserName`)
) TYPE=MyISAM;
