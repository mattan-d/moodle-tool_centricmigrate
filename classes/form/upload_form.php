<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

namespace tool_centricmigrate\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Upload a Workplace export zip.
 */
class upload_form extends \moodleform {

    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('filepicker', 'backupfile', get_string('backupfile', 'tool_centricmigrate'), null, [
            'accepted_types' => ['.zip'],
        ]);
        $mform->addHelpButton('backupfile', 'backupfile', 'tool_centricmigrate');
        $mform->addRule('backupfile', null, 'required');

        $this->add_action_buttons(true, get_string('previewheading', 'tool_centricmigrate'));
    }
}
