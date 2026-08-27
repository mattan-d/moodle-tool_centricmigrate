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
        $existing = $this->find_existing($oldid, $data);
        if ($existing) {
            $this->mapping->set('course', $oldid, (int)$existing->id);
            $filesadded = 0;
            if (!empty($this->options['importcourses'])) {
                $hash = $this->get_backup_hash($data);
                if ($hash) {
                    try {
                        $filesadded = $this->restore_missing_files($existing, $hash, $oldid);
                    } catch (\Throwable $e) {
                        $this->job->log('warning', get_string('error:restorefailed', 'tool_centricmigrate', $e->getMessage()),
                            'course', $oldid);
                    }
                }
            }
            if ($filesadded > 0) {
                $this->job->bump_count('course', 'updated');
                $this->job->log('info', get_string('log:coursefilesrestored', 'tool_centricmigrate', [
                    'shortname' => $existing->shortname,
                    'newid' => $existing->id,
                    'count' => $filesadded,
                ]), 'course', $oldid);
            } else {
                $this->job->bump_count('course', 'mapped');
                $this->job->log('info', get_string('log:coursemapped', 'tool_centricmigrate', [
                    'shortname' => $existing->shortname,
                    'newid' => $existing->id,
                ]), 'course', $oldid);
            }
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
            $newid = $this->restore_from_hash($hash, $data, $oldid);
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
     * @param int $oldid
     * @param array $data
     * @return \stdClass|null
     */
    protected function find_existing(int $oldid, array $data): ?\stdClass {
        global $DB;

        $mapped = $this->mapping->get_existing('course', $oldid, 'course');
        if ($mapped && $mapped > 1) {
            $course = $DB->get_record('course', ['id' => $mapped]);
            if ($course) {
                return $course;
            }
        }

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

        $stable = 'wp-c-' . $oldid;
        $course = $DB->get_record('course', ['shortname' => $stable]);
        if ($course) {
            return $course;
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
     * @param int $oldid
     * @return int
     */
    protected function restore_from_hash(string $hash, array $data, int $oldid): int {
        global $USER, $DB;

        $mbz = $this->package->extract_content($hash);
        $folder = 'cm' . time() . '_' . random_int(1000, 9999);
        $tempdir = make_backup_temp_directory($folder);
        $packer = get_file_packer('application/vnd.moodle.backup');
        $extracted = $packer->extract_to_pathname($mbz, $tempdir);
        if (!$extracted) {
            throw new \moodle_exception('error:restorefailed', 'tool_centricmigrate', '', 'extract');
        }
        $this->inject_package_files($tempdir, $oldid);

        $fullname = (string)xml::value($data['fullname'] ?? 'Imported course');
        $shortname = $this->unique_shortname((string)xml::value($data['shortname'] ?? ''), $oldid);
        $categoryid = (int)($this->options['coursecategory'] ?? 0);
        if ($categoryid < 1 || !$DB->record_exists('course_categories', ['id' => $categoryid])) {
            $categoryid = \core_course_category::get_default()->id;
        }

        $courseid = \restore_dbops::create_new_course($fullname, $shortname, $categoryid);

        $rc = null;
        try {
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
            $this->disable_restore_users($plan);

            $precheck = $rc->execute_precheck();
            if ($precheck === false) {
                $results = $rc->get_precheck_results();
                $detail = !empty($results['errors']) ? implode('; ', (array)$results['errors']) : 'precheck';
                throw new \moodle_exception('error:restorefailed', 'tool_centricmigrate', '', $detail);
            }
            $rc->execute_plan();
        } catch (\Throwable $e) {
            if (!empty($courseid)) {
                delete_course($courseid, false);
            }
            throw $e;
        } finally {
            if ($rc) {
                $rc->destroy();
            }
            fulldelete($tempdir);
            @unlink($mbz);
        }

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $course->visible = xml::int($data['visible'] ?? 1, 1);
        $course->fullname = $fullname;
        $DB->update_record('course', $course);
        rebuild_course_cache($courseid, true);

        return $courseid;
    }

    /**
     * Skip restoring enrolled users from the course backup when the setting allows it.
     *
     * Restore setting objects do not implement is_locked(); never call that method.
     *
     * @param \restore_plan $plan
     */
    protected function disable_restore_users(\restore_plan $plan): void {
        if ($plan->setting_exists('users')) {
            try {
                $plan->get_setting('users')->set_value(false);
            } catch (\Throwable $e) {
                // Locked or unsupported; continue restore with the default value.
            }
        }
        $this->enable_restore_files($plan);
    }

    /**
     * Keep activity files on when the setting exists.
     *
     * @param \restore_plan $plan
     */
    protected function enable_restore_files(\restore_plan $plan): void {
        foreach (['files', 'legacyfiles'] as $name) {
            if (!$plan->setting_exists($name)) {
                continue;
            }
            try {
                $plan->get_setting($name)->set_value(true);
            } catch (\Throwable $e) {
                // Locked or unsupported.
            }
        }
    }

    /**
     * Workplace keeps course file blobs in the outer zip, not inside the tiny .mbz.
     * Copy referenced hashes into the Moodle backup files pool so restore can find them.
     *
     * @param string $tempdir Extracted backup directory
     * @param int $oldid
     * @return int Number of files copied
     */
    public function inject_package_files(string $tempdir, int $oldid = 0): int {
        $copied = 0;
        $missing = 0;
        foreach ($this->backup_content_hashes($tempdir) as $hash) {
            if ($this->is_empty_contenthash($hash)) {
                continue;
            }
            $dest = $this->backup_pool_path($tempdir, $hash);
            if (file_exists($dest) && filesize($dest) > 0) {
                continue;
            }
            if (!$this->package->has_content($hash)) {
                $missing++;
                continue;
            }
            $this->package->extract_content_to($hash, $dest);
            $copied++;
        }
        if ($missing > 0) {
            $this->job->log('warning', get_string('log:coursefilesmissing', 'tool_centricmigrate', $missing),
                'course', $oldid ?: null);
        }
        return $copied;
    }

    /**
     * Add missing files from the Workplace package into an already-restored course.
     *
     * @param \stdClass $course
     * @param string $hash
     * @param int $oldid
     * @return int Number of files created
     */
    protected function restore_missing_files(\stdClass $course, string $hash, int $oldid): int {
        $mbz = $this->package->extract_content($hash);
        $folder = 'cmfiles' . time() . '_' . random_int(1000, 9999);
        $tempdir = make_backup_temp_directory($folder);
        try {
            $packer = get_file_packer('application/vnd.moodle.backup');
            $extracted = $packer->extract_to_pathname($mbz, $tempdir);
            if (!$extracted) {
                return 0;
            }
            $this->inject_package_files($tempdir, $oldid);
            return $this->copy_backup_files_into_course($course, $tempdir);
        } finally {
            fulldelete($tempdir);
            @unlink($mbz);
        }
    }

    /**
     * Create stored files that the original restore skipped because the .mbz had no binaries.
     *
     * @param \stdClass $course
     * @param string $tempdir
     * @return int
     */
    public function copy_backup_files_into_course(\stdClass $course, string $tempdir): int {
        $filesxml = $tempdir . '/files.xml';
        if (!is_readable($filesxml)) {
            return 0;
        }
        $previous = libxml_use_internal_errors(true);
        $root = simplexml_load_file($filesxml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($root === false) {
            return 0;
        }

        $activitymap = $this->activity_context_map($tempdir);
        $sectionmap = $this->section_number_map($tempdir);
        $coursecontext = \context_course::instance($course->id);
        $modinfo = get_fast_modinfo($course);
        $fs = get_file_storage();
        $created = 0;

        foreach ($root->file as $node) {
            $filename = (string)$node->filename;
            $contenthash = (string)$node->contenthash;
            if ($filename === '' || $filename === '.' || $this->is_empty_contenthash($contenthash)) {
                continue;
            }
            $component = (string)$node->component;
            $filearea = (string)$node->filearea;
            if ($this->should_skip_file_component($component)) {
                continue;
            }

            $source = $this->backup_pool_path($tempdir, $contenthash);
            if (!file_exists($source) || filesize($source) < 1) {
                continue;
            }

            $oldcontextid = (int)$node->contextid;
            $itemid = (int)$node->itemid;
            $filepath = (string)$node->filepath;
            if ($filepath === '') {
                $filepath = '/';
            }

            $newcontextid = $this->resolve_file_contextid(
                $component,
                $filearea,
                $oldcontextid,
                $activitymap,
                $modinfo,
                $coursecontext->id
            );
            if (!$newcontextid) {
                continue;
            }

            if ($component === 'course' && $filearea === 'section') {
                $itemid = $this->resolve_section_itemid($itemid, $sectionmap, $modinfo);
                if ($itemid < 1) {
                    continue;
                }
            } else if (in_array($filearea, ['intro', 'content', 'overviewfiles', 'summary', 'overview'], true)) {
                $itemid = 0;
            } else if ($itemid !== 0) {
                continue;
            }

            if ($fs->file_exists($newcontextid, $component, $filearea, $itemid, $filepath, $filename)) {
                continue;
            }

            $record = [
                'contextid' => $newcontextid,
                'component' => $component,
                'filearea' => $filearea,
                'itemid' => $itemid,
                'filepath' => $filepath,
                'filename' => $filename,
                'userid' => (int)($GLOBALS['USER']->id ?? 0),
                'mimetype' => $this->nullable_xml_string($node->mimetype),
                'author' => $this->nullable_xml_string($node->author),
                'license' => $this->nullable_xml_string($node->license) ?: ($GLOBALS['CFG']->sitedefaultlicense ?? 'unknown'),
                'timecreated' => (int)$node->timecreated ?: time(),
                'timemodified' => (int)$node->timemodified ?: time(),
                'sortorder' => (int)$node->sortorder,
            ];
            $fs->create_file_from_pathname($record, $source);
            $created++;
        }

        if ($created > 0) {
            rebuild_course_cache($course->id, true);
        }
        return $created;
    }

    /**
     * @param string $tempdir
     * @return string[] Unique content hashes from files.xml
     */
    protected function backup_content_hashes(string $tempdir): array {
        $filesxml = $tempdir . '/files.xml';
        if (!is_readable($filesxml)) {
            return [];
        }
        $raw = file_get_contents($filesxml);
        if ($raw === false || $raw === '') {
            return [];
        }
        if (!preg_match_all('/<contenthash>([0-9a-f]{40})<\/contenthash>/i', $raw, $matches)) {
            return [];
        }
        return array_values(array_unique($matches[1]));
    }

    /**
     * Moodle backup file-pool path (one hash prefix directory, not filedir's two).
     *
     * @param string $tempdir
     * @param string $contenthash
     * @return string
     */
    protected function backup_pool_path(string $tempdir, string $contenthash): string {
        return $tempdir . '/files/' . \backup_file_manager::get_backup_content_file_location($contenthash);
    }

    /**
     * @param string $hash
     * @return bool
     */
    protected function is_empty_contenthash(string $hash): bool {
        return $hash === '' || $hash === 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
    }

    /**
     * @param string $component
     * @return bool
     */
    protected function should_skip_file_component(string $component): bool {
        if ($component === 'user' || $component === 'backup' || $component === 'question') {
            return true;
        }
        return strpos($component, 'qtype_') === 0 || strpos($component, 'grade') === 0;
    }

    /**
     * Map backup activity context ids to modulename + name.
     *
     * @param string $tempdir
     * @return array oldcontextid => ['modulename' => string, 'name' => string]
     */
    protected function activity_context_map(string $tempdir): array {
        $map = [];
        $dirs = glob($tempdir . '/activities/*', GLOB_ONLYDIR) ?: [];
        foreach ($dirs as $dir) {
            $base = basename($dir);
            if (!preg_match('/^([a-z][a-z0-9_]*)_(\d+)$/', $base, $matches)) {
                continue;
            }
            $modulename = $matches[1];
            $activityfile = $dir . '/' . $modulename . '.xml';
            if (!is_readable($activityfile)) {
                continue;
            }
            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_file($activityfile, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if ($xml === false) {
                continue;
            }
            $contextid = (int)($xml['contextid'] ?? 0);
            $name = trim((string)($xml->{$modulename}->name ?? ''));
            if ($contextid < 1) {
                continue;
            }
            $map[$contextid] = [
                'modulename' => $modulename,
                'name' => $name,
            ];
        }
        return $map;
    }

    /**
     * Map backup section record ids to section numbers.
     *
     * @param string $tempdir
     * @return array oldsectionid => sectionnumber
     */
    protected function section_number_map(string $tempdir): array {
        $map = [];
        $files = glob($tempdir . '/sections/section_*/section.xml') ?: [];
        foreach ($files as $file) {
            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_file($file, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if ($xml === false) {
                continue;
            }
            $oldid = (int)($xml['id'] ?? 0);
            $number = (int)($xml->number ?? -1);
            if ($oldid > 0 && $number >= 0) {
                $map[$oldid] = $number;
            }
        }
        return $map;
    }

    /**
     * @param string $component
     * @param string $filearea
     * @param int $oldcontextid
     * @param array $activitymap
     * @param \course_modinfo $modinfo
     * @param int $coursecontextid
     * @return int
     */
    protected function resolve_file_contextid(
        string $component,
        string $filearea,
        int $oldcontextid,
        array $activitymap,
        \course_modinfo $modinfo,
        int $coursecontextid
    ): int {
        if ($component === 'course') {
            return $coursecontextid;
        }
        if (strpos($component, 'mod_') !== 0) {
            return 0;
        }
        $info = $activitymap[$oldcontextid] ?? null;
        if (!$info) {
            $modulename = substr($component, 4);
            $info = ['modulename' => $modulename, 'name' => ''];
        }
        $cm = $this->find_course_module($modinfo, $info['modulename'], $info['name'], $component, $filearea);
        return $cm ? (int)\context_module::instance($cm->id)->id : 0;
    }

    /**
     * @param \course_modinfo $modinfo
     * @param string $modulename
     * @param string $name
     * @param string $component
     * @param string $filearea
     * @return \cm_info|null
     */
    protected function find_course_module(
        \course_modinfo $modinfo,
        string $modulename,
        string $name,
        string $component,
        string $filearea
    ): ?\cm_info {
        try {
            $instances = $modinfo->get_instances_of($modulename);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$instances) {
            return null;
        }

        $named = [];
        if ($name !== '') {
            foreach ($instances as $cm) {
                if ($cm->name === $name) {
                    $named[] = $cm;
                }
            }
            if (!$named) {
                return null;
            }
            $candidates = $named;
        } else if (count($instances) === 1) {
            $candidates = [reset($instances)];
        } else {
            return null;
        }
        foreach ($candidates as $cm) {
            $ctx = \context_module::instance($cm->id);
            $fs = get_file_storage();
            $existing = $fs->get_area_files($ctx->id, $component, $filearea, false, 'id', false);
            if (!$existing) {
                return $cm;
            }
        }
        return $candidates[0] ?? null;
    }

    /**
     * @param int $olditemid
     * @param array $sectionmap
     * @param \course_modinfo $modinfo
     * @return int
     */
    protected function resolve_section_itemid(
        int $olditemid,
        array $sectionmap,
        \course_modinfo $modinfo
    ): int {
        $number = $sectionmap[$olditemid] ?? null;
        if ($number === null) {
            return 0;
        }
        $section = $modinfo->get_section_info($number);
        return $section ? (int)$section->id : 0;
    }

    /**
     * @param \SimpleXMLElement|null $node
     * @return string|null
     */
    protected function nullable_xml_string($node): ?string {
        $value = xml::value($node !== null ? (string)$node : null);
        return $value === null || $value === '' ? null : (string)$value;
    }

    /**
     * @param string $shortname
     * @param int $oldid
     * @return string
     */
    protected function unique_shortname(string $shortname, int $oldid): string {
        global $DB;

        if ($shortname === '') {
            $shortname = 'wp-c-' . $oldid;
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
