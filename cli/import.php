<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_centricmigrate\job;
use tool_centricmigrate\local\program_importer;
use tool_centricmigrate\package;
use tool_centricmigrate\processor;

list($options, $unrecognized) = cli_get_params([
    'help' => false,
    'file' => '',
    'userid' => 0,
    'importusers' => true,
    'updateusers' => false,
    'authfallback' => 'manual',
    'importcohorts' => true,
    'importcohortmembers' => true,
    'importcourses' => true,
    'coursecategory' => 0,
    'importprograms' => true,
    'importprogramusers' => true,
    'enrolprogramusers' => true,
], [
    'h' => 'help',
    'f' => 'file',
    'u' => 'userid',
]);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help'] || empty($options['file'])) {
    $help = <<<EOT
Import a Moodle Workplace migration zip.

Options:
-h, --help                  Print this help
-f, --file=PATH             Path to the Workplace export zip (required)
-u, --userid=ID             User id to run the import as (default: first site admin)
--importusers=0|1           Import user records (default 1)
--updateusers=0|1           Update existing users (default 0)
--authfallback=PLUGIN       Auth plugin if the original one is missing (default manual)
--importcohorts=0|1         Import cohorts (default 1)
--importcohortmembers=0|1   Import cohort members (default 1)
--importcourses=0|1         Restore missing courses from embedded backups (default 1)
--coursecategory=ID         Category id for restored courses (default site default)
--importprograms=0|1        Import programs into local_program (default 1)
--importprogramusers=0|1    Import program allocations (default 1)
--enrolprogramusers=0|1     Enrol allocated users into program courses (default 1)

Example:
\$ php admin/tool/centricmigrate/cli/import.php --file=/path/to/programs-export.zip

EOT;
    echo $help;
    exit(empty($options['file']) ? 1 : 0);
}

$filepath = $options['file'];
if (!is_readable($filepath)) {
    cli_error('File is not readable: ' . $filepath);
}

$userid = (int)$options['userid'];
if ($userid < 1) {
    $admins = array_keys(get_admins());
    $userid = (int)reset($admins);
}
$user = \core_user::get_user($userid, '*', MUST_EXIST);
\core\session\manager::set_user($user);
\core\cron::setup_user($user);

core_php_time_limit::raise();
raise_memory_limit(MEMORY_HUGE);

$package = new package($filepath);
$summary = $package->summarize();
$package->close();

cli_writeln('Source: ' . $summary['wwwroot']);
cli_writeln('Exporter: ' . $summary['exporter']);
cli_writeln('Users: ' . $summary['users'] . ', cohorts: ' . $summary['cohorts'] .
    ', courses: ' . $summary['courses'] . ', programs: ' . $summary['programs']);

if (($summary['programs'] || $summary['programusers']) && !program_importer::is_available()) {
    cli_writeln(get_string('localprogrammissing', 'tool_centricmigrate'));
}

$job = job::create([
    'userid' => $userid,
    'filename' => basename($filepath),
    'sourcepath' => realpath($filepath),
    'siteidentifier' => $summary['siteidentifier'] ?: 'unknown',
    'exporter' => $summary['exporter'],
    'options' => [
        'importusers' => cli_flag_enabled($options['importusers'], true),
        'updateusers' => cli_flag_enabled($options['updateusers'], false),
        'authfallback' => (string)$options['authfallback'],
        'importcohorts' => cli_flag_enabled($options['importcohorts'], true),
        'importcohortmembers' => cli_flag_enabled($options['importcohortmembers'], true),
        'importcourses' => cli_flag_enabled($options['importcourses'], true),
        'coursecategory' => (int)$options['coursecategory'],
        'importprograms' => cli_flag_enabled($options['importprograms'], true) && program_importer::is_available(),
        'importprogramusers' => cli_flag_enabled($options['importprogramusers'], true) && program_importer::is_available(),
        'enrolprogramusers' => cli_flag_enabled($options['enrolprogramusers'], true),
    ],
]);

$processor = new processor($job);
while ($job->get_step() !== job::STEP_DONE && $job->get_status() !== job::STATUS_ERROR) {
    cli_writeln($processor->get_progress_label());
    $processor->run_batch(true);
}

if ($job->get_status() === job::STATUS_ERROR) {
    cli_error($job->get_record()->errormsg ?: 'Import failed');
}

cli_writeln(get_string('importcomplete', 'tool_centricmigrate'));
foreach ($job->get_counts() as $entity => $results) {
    cli_writeln($entity . ': ' . json_encode($results));
}

exit(0);

/**
 * Normalise a CLI 0/1 flag.
 *
 * @param mixed $value
 * @param bool $default
 * @return int
 */
function cli_flag_enabled($value, bool $default): int {
    if ($value === true) {
        return 1;
    }
    if ($value === false) {
        return 0;
    }
    if (is_string($value) && in_array(strtolower($value), ['0', 'false', 'no', 'off'], true)) {
        return 0;
    }
    if (is_string($value) && in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true)) {
        return 1;
    }
    if ($value === 0 || $value === 1) {
        return (int)$value;
    }
    return (int)$default;
}
