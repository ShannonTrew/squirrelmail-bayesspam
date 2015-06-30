<?php
include_once('DB.php');
include_once(SM_PATH . 'plugins/bayesspam/config.php');
//include_once(SM_PATH . 'functions/i18n.php');

/* Initialize the plugin */

function squirrelmail_plugin_init_bayesspam() {
   $GLOBALS['squirrelmail_plugin_hooks']['optpage_register_block']['bayesspam'] =
      'bayesspam_options_hook';
   $GLOBALS['squirrelmail_plugin_hooks']['loading_prefs']['bayesspam'] =
      'bayesspam_load_hook';
   $GLOBALS['squirrelmail_plugin_hooks']['read_body_header']['bayesspam'] =
      'bayesspam_display_hook';
   $GLOBALS['squirrelmail_plugin_hooks']['webmail_bottom']['bayesspam'] =
      'bayesspam_rebuild_corpus_do';
   $GLOBALS['squirrelmail_plugin_hooks']['left_main_after']['bayesspam'] =
      'bayesspam_rebuild_corpus_do';
   $GLOBALS['squirrelmail_plugin_hooks']['left_main_before']['bayesspam'] = 
      'bayesspam_filter_hook';
   $GLOBALS['squirrelmail_plugin_hooks']['right_main_after_header']['bayesspam'] = 
      'bayesspam_filter_hook';
   $GLOBALS['squirrelmail_plugin_hooks']['special_mailbox']['bayesspam'] =
      'bayesspam_special_mailbox';
   $GLOBALS['squirrelmail_plugin_hooks']['mailbox_display_buttons']['bayesspam'] = 
      'bayesspam_buttons';
   $GLOBALS['squirrelmail_plugin_hooks']['move_messages_button_action']['bayesspam'] = 
      'bayesspam_button_action_hook';
   $GLOBALS['squirrelmail_plugin_hooks']['mailbox_display_button_action']['bayesspam'] =
      'bayesspam_button_action_hook';
}

function bayesspam_options_hook () {
   include_once(SM_PATH . 'plugins/bayesspam/bayesspam_functions.php');
   bayesspam_options();
}

function bayesspam_load_hook () {
   include_once(SM_PATH . 'plugins/bayesspam/bayesspam_functions.php');
   bayesspam_load();
}

function bayesspam_display_hook () {
   include_once(SM_PATH . 'plugins/bayesspam/bayesspam_functions.php');
   bayesspam_display();
}

function bayesspam_rebuild_corpus_do () {
   include_once(SM_PATH . 'plugins/bayesspam/bayesspam_functions.php');
   bayesspam_rebuild_corpus();
}

function bayesspam_filter_hook ($args) {
   include_once(SM_PATH . 'plugins/bayesspam/bayesspam_functions.php');
   bayesspam_filter($args);
}

function bayesspam_special_mailbox ($args) {
   include_once(SM_PATH . 'plugins/bayesspam/bayesspam_functions.php');
   return bayesspam_special_folders($args);
}

function bayesspam_buttons () {
   include_once(SM_PATH . 'plugins/bayesspam/bayesspam_functions.php');
   bayesspam_show_buttons();
}

function bayesspam_button_action_hook ($id) {
   include_once(SM_PATH . 'plugins/bayesspam/bayesspam_functions.php');
   bayesspam_button_action ($id);
}

function bayesspam_version() {
   return '3.7.1';
}
?>
