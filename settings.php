<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('root', new admin_externalpage(
        'tool_centricmigrate',
        get_string('pluginname', 'tool_centricmigrate'),
        new moodle_url('/admin/tool/centricmigrate/index.php'),
        'tool/centricmigrate:import'
    ));
}
