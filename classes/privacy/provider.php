<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

namespace tool_centricmigrate\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for Workplace import jobs and id mappings.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider {

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
}
