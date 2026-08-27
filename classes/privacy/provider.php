<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

namespace tool_centricmigrate\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for Workplace import jobs and id mappings.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('tool_centricmigrate_job', [
            'userid' => 'privacy:metadata:job:userid',
            'filename' => 'privacy:metadata:job:filename',
        ], 'privacy:metadata:job');
        $collection->add_database_table('tool_centricmigrate_map', [
            'newid' => 'privacy:metadata:map:newid',
        ], 'privacy:metadata:map');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_system_context();
        return $contextlist;
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        $jobs = $DB->get_records('tool_centricmigrate_job', ['userid' => $userid], 'id ASC', 'id, filename, timecreated');
        if (empty($jobs)) {
            return;
        }

        writer::with_context(\context_system::instance())->export_data(
            [get_string('pluginname', 'tool_centricmigrate')],
            (object)['jobs' => array_values($jobs)]
        );
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }
        $DB->delete_records('tool_centricmigrate_job');
        $DB->delete_records('tool_centricmigrate_map');
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        $DB->delete_records('tool_centricmigrate_job', ['userid' => $userid]);
        $DB->delete_records('tool_centricmigrate_map', ['entity' => 'user', 'newid' => $userid]);
    }

    public static function get_users_in_context(userlist $userlist): void {
        if ($userlist->get_context()->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {tool_centricmigrate_job}', []);
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        if ($userlist->get_context()->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }
        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('tool_centricmigrate_job', "userid {$insql}", $params);
        $DB->delete_records_select('tool_centricmigrate_map', "entity = :entity AND newid {$insql}",
            $params + ['entity' => 'user']);
    }
}
