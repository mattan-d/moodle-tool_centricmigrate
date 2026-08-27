<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

namespace tool_centricmigrate\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use tool_centricmigrate\local\program_importer;

/**
 * Confirm import options after package preview.
 */
class confirm_form extends \moodleform {

    protected function definition() {
        $mform = $this->_form;
        $summary = $this->_customdata['summary'] ?? [];
        $jobid = $this->_customdata['jobid'] ?? 0;

        $mform->addElement('hidden', 'jobid', $jobid);
        $mform->setType('jobid', PARAM_INT);

        $mform->addElement('header', 'importoptions', get_string('importoptions', 'tool_centricmigrate'));

        if (!empty($summary['users'])) {
            $mform->addElement('advcheckbox', 'importusers', get_string('importusers', 'tool_centricmigrate'));
            $mform->setDefault('importusers', 1);
            $mform->addElement('advcheckbox', 'updateusers', get_string('updateusers', 'tool_centricmigrate'));
            $mform->setDefault('updateusers', 0);
            $mform->addHelpButton('updateusers', 'updateusers', 'tool_centricmigrate');

            $auths = [];
            foreach (get_enabled_auth_plugins() as $plugin) {
                $auths[$plugin] = get_string('pluginname', 'auth_' . $plugin);
            }
            $mform->addElement('select', 'authfallback', get_string('authfallback', 'tool_centricmigrate'), $auths);
            $mform->setDefault('authfallback', 'manual');
        }

        if (!empty($summary['cohorts'])) {
            $mform->addElement('advcheckbox', 'importcohorts', get_string('importcohorts', 'tool_centricmigrate'));
            $mform->setDefault('importcohorts', 1);
        }
        if (!empty($summary['cohortmembers'])) {
            $mform->addElement('advcheckbox', 'importcohortmembers',
                get_string('importcohortmembers', 'tool_centricmigrate'));
            $mform->setDefault('importcohortmembers', 1);
        }

        if (!empty($summary['courses'])) {
            $mform->addElement('advcheckbox', 'importcourses', get_string('importcourses', 'tool_centricmigrate'));
            $mform->setDefault('importcourses', 1);
            $mform->addHelpButton('importcourses', 'importcourses', 'tool_centricmigrate');
            $categories = \core_course_category::make_categories_list();
            $mform->addElement('select', 'coursecategory',
                get_string('coursecategory', 'tool_centricmigrate'), $categories);
            $mform->setDefault('coursecategory', \core_course_category::get_default()->id);
        }

        $hasprograms = !empty($summary['programs']) || !empty($summary['programusers']);
        if ($hasprograms) {
            if (program_importer::is_available()) {
                if (!empty($summary['programs'])) {
                    $mform->addElement('advcheckbox', 'importprograms',
                        get_string('importprograms', 'tool_centricmigrate'));
                    $mform->setDefault('importprograms', 1);
                }
                if (!empty($summary['programusers'])) {
                    $mform->addElement('advcheckbox', 'importprogramusers',
                        get_string('importprogramusers', 'tool_centricmigrate'));
                    $mform->setDefault('importprogramusers', 1);
                    $mform->addElement('advcheckbox', 'enrolprogramusers',
                        get_string('enrolprogramusers', 'tool_centricmigrate'));
                    $mform->setDefault('enrolprogramusers', 1);
                }
            } else {
                $mform->addElement('static', 'localprogrammissing', '',
                    \html_writer::div(get_string('localprogrammissing', 'tool_centricmigrate'), 'alert alert-warning'));
            }
        }

        $this->add_action_buttons(true, get_string('confirminport', 'tool_centricmigrate'));
    }
}
