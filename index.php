<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/filelib.php');

use tool_centricmigrate\form\confirm_form;
use tool_centricmigrate\form\upload_form;
use tool_centricmigrate\job;
use tool_centricmigrate\local\program_importer;
use tool_centricmigrate\package;
use tool_centricmigrate\processor;

core_php_time_limit::raise(HOURSECS);
raise_memory_limit(MEMORY_HUGE);

admin_externalpage_setup('tool_centricmigrate');

$jobid = optional_param('jobid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$context = context_system::instance();
require_capability('tool/centricmigrate:import', $context);

$PAGE->set_url(new moodle_url('/admin/tool/centricmigrate/index.php', $jobid ? ['jobid' => $jobid] : []));

if ($jobid && $action === 'run') {
    require_sesskey();
    \core\session\manager::write_close();
    $job = job::get($jobid);
    require_user_key_match($job);
    $processor = new processor($job);
    $more = $processor->run_batch(false);
    if ($job->get_status() === job::STATUS_ERROR) {
        redirect(new moodle_url('/admin/tool/centricmigrate/index.php', ['jobid' => $jobid, 'action' => 'results']));
    }
    if ($more) {
        $continueurl = new moodle_url('/admin/tool/centricmigrate/index.php', [
            'jobid' => $jobid,
            'action' => 'run',
            'sesskey' => sesskey(),
        ]);
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('processing', 'tool_centricmigrate'));
        echo $OUTPUT->notification($processor->get_progress_label(), \core\output\notification::NOTIFY_INFO);
        echo html_writer::div(get_string('progress', 'tool_centricmigrate'), 'mb-2');
        echo html_writer::link($continueurl, get_string('continueimport', 'tool_centricmigrate'), [
            'id' => 'tool-centricmigrate-continue',
            'class' => 'btn btn-primary',
        ]);
        echo html_writer::script('setTimeout(function() { window.location = ' .
            json_encode($continueurl->out(false)) . '; }, 400);');
        echo $OUTPUT->footer();
        exit;
    }
    redirect(new moodle_url('/admin/tool/centricmigrate/index.php', ['jobid' => $jobid, 'action' => 'results']));
}

if ($jobid && $action === 'results') {
    $job = job::get($jobid);
    require_user_key_match($job);
    echo $OUTPUT->header();
    if ($job->get_status() === job::STATUS_ERROR) {
        echo $OUTPUT->heading(get_string('importerror', 'tool_centricmigrate'));
        echo $OUTPUT->notification($job->get_record()->errormsg ?: get_string('importerror', 'tool_centricmigrate'),
            \core\output\notification::NOTIFY_ERROR);
    } else {
        echo $OUTPUT->heading(get_string('importcomplete', 'tool_centricmigrate'));
    }
    echo render_job_results($job);
    echo $OUTPUT->single_button(new moodle_url('/admin/tool/centricmigrate/index.php'),
        get_string('newimport', 'tool_centricmigrate'), 'get');
    echo $OUTPUT->footer();
    exit;
}

$uploadform = new upload_form();
if ($uploadform->is_cancelled()) {
    redirect(new moodle_url('/admin/tool/centricmigrate/index.php'));
}

if ($uploaddata = $uploadform->get_data()) {
    $tmp = $uploadform->save_temp_file('backupfile');
    if (!$tmp) {
        throw new moodle_exception('invalidpackage', 'tool_centricmigrate');
    }
    try {
        $package = new package($tmp);
        $summary = $package->summarize();
        $package->close();
    } catch (moodle_exception $e) {
        @unlink($tmp);
        throw $e;
    }

    $filename = $uploadform->get_new_filename('backupfile') ?: 'workplace-export.zip';
    $job = job::create([
        'filename' => $filename,
        'siteidentifier' => $summary['siteidentifier'] ?: 'unknown',
        'exporter' => $summary['exporter'],
    ]);
    $job->store_package_from_path($tmp, $filename);
    @unlink($tmp);
    redirect(new moodle_url('/admin/tool/centricmigrate/index.php', ['jobid' => $job->get_id()]));
}

if ($jobid) {
    $job = job::get($jobid);
    require_user_key_match($job);
    $package = new package($job->get_package_path());
    $summary = $package->summarize();
    $package->close();

    $confirmform = new confirm_form(null, ['jobid' => $jobid, 'summary' => $summary]);
    if ($confirmform->is_cancelled()) {
        redirect(new moodle_url('/admin/tool/centricmigrate/index.php'));
    }
    if ($confirmdata = $confirmform->get_data()) {
        $options = [
            'importusers' => (int)($confirmdata->importusers ?? 0),
            'updateusers' => (int)($confirmdata->updateusers ?? 0),
            'authfallback' => $confirmdata->authfallback ?? 'manual',
            'importcohorts' => (int)($confirmdata->importcohorts ?? 0),
            'importcohortmembers' => (int)($confirmdata->importcohortmembers ?? 0),
            'importcourses' => (int)($confirmdata->importcourses ?? 0),
            'coursecategory' => (int)($confirmdata->coursecategory ?? 0),
            'importprograms' => (int)($confirmdata->importprograms ?? 0),
            'importprogramusers' => (int)($confirmdata->importprogramusers ?? 0),
            'enrolprogramusers' => (int)($confirmdata->enrolprogramusers ?? 0),
        ];
        $job->set_options($options);
        $job->set_progress(job::STEP_PREVIEW, 0);
        redirect(new moodle_url('/admin/tool/centricmigrate/index.php', [
            'jobid' => $jobid,
            'action' => 'run',
            'sesskey' => sesskey(),
        ]));
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('previewheading', 'tool_centricmigrate'));
    echo render_package_preview($summary);
    $confirmform->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('uploadheading', 'tool_centricmigrate'));
echo html_writer::div(get_string('uploadintro', 'tool_centricmigrate'), 'mb-3');
echo html_writer::div(get_string('clihelp', 'tool_centricmigrate'), 'alert alert-info');
$uploadform->display();
echo $OUTPUT->footer();

/**
 * Ensure the current user owns the job (or is an admin).
 *
 * @param job $job
 */
function require_user_key_match(job $job): void {
    global $USER;
    if ((int)$job->get_record()->userid !== (int)$USER->id && !is_siteadmin()) {
        throw new moodle_exception('jobnotfound', 'tool_centricmigrate');
    }
}

/**
 * @param array $summary
 * @return string
 */
function render_package_preview(array $summary): string {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable tool-centricmigrate-preview';
    $table->data = [
        [get_string('sourceinfo', 'tool_centricmigrate'), s($summary['wwwroot'])],
        [get_string('exporter', 'tool_centricmigrate'), s($summary['exporter'])],
        [get_string('releasedata', 'tool_centricmigrate'), s($summary['release'])],
        [get_string('createdby', 'tool_centricmigrate'), s($summary['createdbyname'])],
        [get_string('countusers', 'tool_centricmigrate'), $summary['users']],
        [get_string('countcohorts', 'tool_centricmigrate'), $summary['cohorts']],
        [get_string('countcohortmembers', 'tool_centricmigrate'), $summary['cohortmembers']],
        [get_string('countcourses', 'tool_centricmigrate'), $summary['courses']],
        [get_string('countprograms', 'tool_centricmigrate'), $summary['programs']],
        [get_string('countprogramusers', 'tool_centricmigrate'), $summary['programusers']],
        [get_string('countdynamicrules', 'tool_centricmigrate'), $summary['dynamicrules']
            ? $summary['dynamicrules'] . ' (' . get_string('skippedunsupported', 'tool_centricmigrate') . ')'
            : 0],
    ];
    $html = html_writer::tag('h3', get_string('contents', 'tool_centricmigrate'));
    $html .= html_writer::table($table);
    if (($summary['programs'] || $summary['programusers']) && !program_importer::is_available()) {
        $html .= html_writer::div(get_string('localprogrammissing', 'tool_centricmigrate'), 'alert alert-warning');
    }
    return $html;
}

/**
 * @param job $job
 * @return string
 */
function render_job_results(job $job): string {
    $counts = $job->get_counts();
    $table = new html_table();
    $table->head = [
        get_string('logentity', 'tool_centricmigrate'),
        get_string('statuscreated', 'tool_centricmigrate'),
        get_string('statusupdated', 'tool_centricmigrate'),
        get_string('statusmapped', 'tool_centricmigrate'),
        get_string('statusskipped', 'tool_centricmigrate'),
        get_string('statusfailed', 'tool_centricmigrate'),
    ];
    $table->attributes['class'] = 'generaltable';
    $entities = array_unique(array_merge(array_keys($counts), []));
    if (empty($entities)) {
        $table->data[] = [get_string('nologs', 'tool_centricmigrate'), 0, 0, 0, 0, 0];
    }
    foreach ($counts as $entity => $results) {
        $table->data[] = [
            s($entity),
            $results['created'] ?? 0,
            $results['updated'] ?? 0,
            $results['mapped'] ?? 0,
            $results['skipped'] ?? 0,
            $results['failed'] ?? 0,
        ];
    }

    $html = html_writer::tag('h3', get_string('summary', 'tool_centricmigrate'));
    $html .= html_writer::table($table);

    $logs = $job->get_logs();
    $logtable = new html_table();
    $logtable->head = [
        get_string('loglevel', 'tool_centricmigrate'),
        get_string('logentity', 'tool_centricmigrate'),
        get_string('logmessage', 'tool_centricmigrate'),
    ];
    $logtable->attributes['class'] = 'generaltable';
    if (empty($logs)) {
        $logtable->data[] = ['', '', get_string('nologs', 'tool_centricmigrate')];
    } else {
        foreach ($logs as $log) {
            $class = $log['level'] === 'error' ? 'text-danger' : ($log['level'] === 'warning' ? 'text-warning' : '');
            $logtable->data[] = [
                s($log['level']),
                s($log['entity'] ?? ''),
                html_writer::span(s($log['message']), $class),
            ];
        }
    }
    $html .= html_writer::table($logtable);
    return $html;
}
