<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

defined('MOODLE_INTERNAL') || die();

/**
 * Serve stored files. Import packages are not downloadable.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool
 */
function tool_centricmigrate_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    send_file_not_found();
}
