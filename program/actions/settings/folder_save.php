<?php

/**
 +-----------------------------------------------------------------------+
 | This file is part of the Roundcube Webmail client                     |
 |                                                                       |
 | Copyright (C) The Roundcube Dev Team                                  |
 |                                                                       |
 | Licensed under the GNU General Public License version 3 or            |
 | any later version with exceptions for skins & plugins.                |
 | See the README file for a full license statement.                     |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Handler for saving the create/edit folder form                      |
 +-----------------------------------------------------------------------+
 | Author: Aleksander Machniak <alec@alec.pl>                            |
 +-----------------------------------------------------------------------+
*/

class rcmail_action_settings_folder_save extends rcmail_action_settings_folder_edit
{
    /**
     * Request handler.
     *
     * @param array $args Arguments from the previous step(s)
     */
    public function run($args = [])
    {
        // WARNING: folder names in UI are encoded with RCUBE_CHARSET

        $name      = trim(rcube_utils::get_input_value('_name', rcube_utils::INPUT_POST, true));
        $path      = rcube_utils::get_input_value('_parent', rcube_utils::INPUT_POST, true);
        $old_imap  = rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_POST, true);
        $type      = rcube_utils::get_input_value('_type', rcube_utils::INPUT_POST);
        $name_imap = rcube_charset::convert($name, RCUBE_CHARSET, 'UTF7-IMAP');
        // $path is in UTF7-IMAP already

        // init IMAP connection
        $rcmail    = rcmail::get_instance();
        $storage   = $rcmail->get_storage();
        $delimiter = $storage->get_hierarchy_delimiter();
        $options   = strlen($old_imap) ? self::folder_options($old_imap) : [];

        // Folder name checks
        if ($options['protected'] || $options['norename']) {
            // do nothing
        }
        else if (!strlen($name)) {
            $error = $rcmail->gettext('namecannotbeempty');
        }
        else if (mb_strlen($name) > 128) {
            $error = $rcmail->gettext('nametoolong');
        }
        else if ($name[0] == '.' && $rcmail->config->get('imap_skip_hidden_folders')) {
            $error = $rcmail->gettext('namedotforbidden');
        }
        else if (!$storage->folder_validate($name, $char)) {
            $error = $rcmail->gettext('forbiddencharacter') . " ($char)";
        }

        if ($error) {
            $rcmail->output->command('display_message', $error, 'error');
        }
        else {
            if ($options['protected'] || $options['norename']) {
                $name_imap = $old_imap;
            }
            else if (strlen($path)) {
                $name_imap = $path . $delimiter . $name_imap;
            }
            else {
                $name_imap = $storage->mod_folder($name_imap, 'in');
            }
        }

        $dual_use_supported = $storage->get_capability(rcube_storage::DUAL_USE_FOLDERS);
        $acl_supported      = $storage->get_capability('ACL');

        // Check access rights to the parent folder
        if (!$error && $acl_supported && strlen($path) && (!strlen($old_imap) || $old_imap != $name_imap)) {
            $parent_opts = $storage->folder_info($path);
            if ($parent_opts['namespace'] != 'personal'
                && (empty($parent_opts['rights']) || !preg_match('/[ck]/', implode($parent_opts['rights'])))
            ) {
                $error = $rcmail->gettext('parentnotwritable');
            }
        }

        if ($error) {
            $rcmail->output->command('display_message', $error, 'error');
        }
        else {
            $folder['name']     = $name_imap;
            $folder['oldname']  = $old_imap;
            $folder['class']    = '';
            $folder['options']  = $options;
            $folder['settings'] = [
                // List view mode: 0-list, 1-threads
                'view_mode'   => (int) rcube_utils::get_input_value('_viewmode', rcube_utils::INPUT_POST),
                'sort_column' => rcube_utils::get_input_value('_sortcol', rcube_utils::INPUT_POST),
                'sort_order'  => rcube_utils::get_input_value('_sortord', rcube_utils::INPUT_POST),
            ];
        }

        // create a new mailbox
        if (!$error && !strlen($old_imap)) {
            $folder['subscribe'] = true;

            // Server does not support both sub-folders and messages in a folder
            // For folders that are supposed to contain other folders we will:
            //    - disable subscribtion
            //    - add a separator at the end to make them \NoSelect
            if (!$dual_use_supported && $type == 'folder') {
                $folder['subscribe'] = false;
                $folder['noselect']  = true;
            }

            $plugin = $rcmail->plugins->exec_hook('folder_create', ['record' => $folder]);

            $folder = $plugin['record'];

            if (!$plugin['abort']) {
                $created = $storage->create_folder($folder['name'], $folder['subscribe'], null, $folder['noselect']);
            }
            else {
                $created = $plugin['result'];
            }

            if ($created) {
                // Save folder settings
                if (isset($_POST['_viewmode'])) {
                    $a_threaded = (array) $rcmail->config->get('message_threading', []);

                    $a_threaded[$folder['name']] = (bool) $_POST['_viewmode'];

                    $rcmail->user->save_prefs(['message_threading' => $a_threaded]);
                }

                self::update_folder_row($folder['name'], null, $folder['subscribe'], $folder['class']);

                $rcmail->output->show_message('foldercreated', 'confirmation');
                // reset folder preview frame
                $rcmail->output->command('subscription_select');
                $rcmail->output->send('iframe');
            }
            else {
                // show error message
                if (!empty($plugin['message'])) {
                    $rcmail->output->show_message($plugin['message'], 'error', null, false);
                }
                else {
                    self::display_server_error('errorsaving');
                }
            }
        }
        // update a mailbox
        else if (!$error) {
            $plugin = $rcmail->plugins->exec_hook('folder_update', ['record' => $folder]);

            $folder = $plugin['record'];
            $rename = ($folder['oldname'] != $folder['name']);

            if (!$plugin['abort']) {
                if ($rename) {
                    $updated = $storage->rename_folder($folder['oldname'], $folder['name']);
                }
                else {
                    $updated = true;
                }
            }
            else {
                $updated = $plugin['result'];
            }

            if ($updated) {
                // Update folder settings,
                if (isset($_POST['_viewmode'])) {
                    $a_threaded = (array) $rcmail->config->get('message_threading', []);

                    // In case of name change update names of childrens in settings
                    if ($rename) {
                        $oldprefix  = '/^' . preg_quote($folder['oldname'] . $delimiter, '/') . '/';
                        foreach ($a_threaded as $key => $val) {
                            if ($key == $folder['oldname']) {
                                unset($a_threaded[$key]);
                            }
                            else if (preg_match($oldprefix, $key)) {
                                unset($a_threaded[$key]);
                                $a_threaded[preg_replace($oldprefix, $folder['name'].$delimiter, $key)] = $val;
                            }
                        }
                    }

                    $a_threaded[$folder['name']] = (bool) $_POST['_viewmode'];

                    $rcmail->user->save_prefs(['message_threading' => $a_threaded]);
                }

                $rcmail->output->show_message('folderupdated', 'confirmation');
                $rcmail->output->set_env('folder', $folder['name']);

                if ($rename) {
                    // #1488692: update session
                    if ($_SESSION['mbox'] === $folder['oldname']) {
                        $_SESSION['mbox'] = $folder['name'];
                    }
                    self::update_folder_row($folder['name'], $folder['oldname'], $folder['subscribe'], $folder['class']);
                    $rcmail->output->send('iframe');
                }
                else if (!empty($folder['class'])) {
                    self::update_folder_row($folder['name'], $folder['oldname'], $folder['subscribe'], $folder['class']);
                }
            }
            else {
                // show error message
                if (!empty($plugin['message'])) {
                    $rcmail->output->show_message($plugin['message'], 'error', null, false);
                }
                else {
                    self::display_server_error('errorsaving');
                }
            }
        }

        $rcmail->overwrite_action('edit-folder');
    }
}