<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

namespace tool_centricmigrate\local;

defined('MOODLE_INTERNAL') || die();

use tool_centricmigrate\job;
use tool_centricmigrate\mapping;
use tool_centricmigrate\package;
use tool_centricmigrate\xml;

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Match or restore Workplace-exported courses.
 */
class course_importer {

    /** @var package */
    protected $package;

    /** @var mapping */
    protected $mapping;

    /** @var job */
    protected $job;

    /** @var array */
    protected $options;

    /**
     * @param package $package
     * @param mapping $mapping
     * @param job $job
     * @param array $options
     */
    public function __construct(package $package, mapping $mapping, job $job, array $options) {
        $this->package = $package;
        $this->mapping = $mapping;
        $this->job = $job;
        $this->options = $options;
    }

    /**
     * Process one exported course. Returns true when a course was handled.
     *
     * @param int $oldid
     * @param array $data
     * @return bool
     */
    public function import_course(int $oldid, array $data): bool {
        $existing = $this->find_existing($data);
        if ($existing) {
            $this->mapping->set('course', $oldid, (int)$existing->id);
            $this->job->bump_count('course', 'mapped');
            $this->job->log('info', get_string('log:coursemapped', 'tool_centricmigrate', [
                'shortname' => $existing->shortname,
                'newid' => $existing->id,
            ]), 'course', $oldid);
            return true;
        }

        if (empty($this->options['importcourses'])) {
            $this->job->bump_count('course', 'skipped');
            $this->job->log('warning', get_string('log:courseskipped', 'tool_centricmigrate',
                (string)xml::value($data['shortname'] ?? $oldid)), 'course', $oldid);
            return true;
        }

        $hash = $this->get_backup_hash($data);
        if (!$hash) {
            $this->job->bump_count('course', 'failed');
            $this->job->log('error', get_string('log:courseskipped', 'tool_centricmigrate',
                (string)xml::value($data['shortname'] ?? $oldid)), 'course', $oldid);
            return true;
        }

        try {
            $newid = $this->restore_from_hash($hash, $data);
        } catch (\Throwable $e) {
            $this->job->bump_count('course', 'failed');
            $this->job->log('error', get_string('error:restorefailed', 'tool_centricmigrate', $e->getMessage()),
                'course', $oldid);
            return true;
        }

        $this->mapping->set('course', $oldid, $newid);
        $this->job->bump_count('course', 'created');
        $this->job->log('info', get_string('log:courserestored', 'tool_centricmigrate', [
            'shortname' => (string)xml::value($data['shortname'] ?? ''),
            'newid' => $newid,
        ]), 'course', $oldid);
        return true;
    }

    /**
     * @param array $data
     * @return \stdClass|null
     */
    protected function find_existing(array $data): ?\stdClass {
        global $DB;

        $idnumber = trim((string)xml::value($data['idnumber'] ?? ''));
        if ($idnumber !== '') {
            $course = $DB->get_record('course', ['idnumber' => $idnumber]);
            if ($course) {
                return $course;
            }
        }

        $shortname = trim((string)xml::value($data['shortname'] ?? ''));
        if ($shortname !== '') {
            $course = $DB->get_record('course', ['shortname' => $shortname]);
            if ($course) {
                return $course;
            }
        }

        return null;
    }

    /**
     * @param array $data
     * @return string|null
     */
    protected function get_backup_hash(array $data): ?string {
        foreach (xml::files($data) as $file) {
            $filename = (string)xml::value($file['filename'] ?? '');
            $filearea = (string)xml::value($file['filearea'] ?? '');
            if ($filename === 'backup.mbz' || $filearea === 'coursebackup') {
                $hash = (string)xml::value($file['contenthash'] ?? '');
                if ($hash !== '') {
                    return $hash;
                }
            }
        }
        return null;
    }

    /**
     * @param string $hash
     * @param array $data
     * @return int
     */
    protected function restore_from_hash(string $hash, array $data): int {
        global $USER, $CFG, $DB;

        $mbz = $this->package->extract_content($hash);
        $folder = 'cm' . time() . '_' . random_int(1000, 9999);
        $tempdir = make_backup_temp_directory($folder, false);
        $packer = get_file_packer('application/vnd.moodle.backup');
        $extracted = $packer->extract_to_pathname($mbz, $tempdir);
        if (!$extracted) {
            throw new \moodle_exception('error:restorefailed', 'tool_centricmigrate', '', 'extract');
        }

        $fullname = (string)xml::value($data['fullname'] ?? 'Imported course');
        $shortname = $this->unique_shortname((string)xml::value($data['shortname'] ?? 'wp-course'));
        $categoryid = (int)($this->options['coursecategory'] ?? 0);
        if ($categoryid < 1 || !$DB->record_exists('course_categories', ['id' => $categoryid])) {
            $categoryid = \core_course_category::get_default()->id;
        }

        $courseid = \restore_dbops::create_new_course($fullname, $shortname, $categoryid);

        $rc = new \restore_controller(
            $folder,
            $courseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );

        if ($rc->get_status() == \backup::STATUS_REQUIRE_CONV) {
            $rc->convert();
        }

        $plan = $rc->get_plan();
        if ($plan->setting_exists('users')) {
            $plan->get_setting('users')->set_value(false);
        }

        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        fulldelete($tempdir);
        @unlink($mbz);

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $course->visible = xml::int($data['visible'] ?? 1, 1);
        $course->fullname = $fullname;
        $DB->update_record('course', $course);
        rebuild_course_cache($courseid, true);

        return $courseid;
    }

    /**
     * @param string $shortname
     * @return string
     */
    protected function unique_shortname(string $shortname): string {
        global $DB;

        if ($shortname === '') {
            $shortname = 'wp-course';
        }
        $base = $shortname;
        $i = 2;
        while ($DB->record_exists('course', ['shortname' => $shortname])) {
            $shortname = \core_text::substr($base, 0, 200) . '_' . $i;
            $i++;
        }
        return $shortname;
    }
}
