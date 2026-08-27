<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

namespace tool_centricmigrate\local;

defined('MOODLE_INTERNAL') || die();

use local_program\assignment;
use local_program\manager\assignment_manager;
use local_program\program;
use local_program\program_constants;
use local_program\program_content;
use tool_centricmigrate\job;
use tool_centricmigrate\mapping;
use tool_centricmigrate\package;
use tool_centricmigrate\xml;

/**
 * Import Workplace programs into local_program.
 */
class program_importer {

    public const WP_DATE_NOTSET = 0;
    public const WP_DATE_ABSOLUTE = 1;
    public const WP_DATE_RELATIVE = 2;

    public const WP_COMPLETION_ALL_ANY = 0;
    public const WP_COMPLETION_ALL_ORDER = 1;
    public const WP_COMPLETION_AT_LEAST = 2;

    public const WP_ALLOCATION_MANUAL = 0;
    public const WP_ALLOCATION_DYNAMIC = 1;

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
     * @return bool
     */
    public static function is_available(): bool {
        return class_exists(program::class);
    }

    /**
     * Import program definitions and content.
     */
    public function import_programs(): void {
        if (!self::is_available()) {
            $this->job->log('warning', get_string('localprogrammissing', 'tool_centricmigrate'), 'tool_program');
            return;
        }
        foreach ($this->package->iterate_entities('tool_program') as $oldid => $data) {
            $this->import_program($oldid, $data);
        }
        foreach ($this->package->iterate_entities('tool_dynamicrule') as $oldid => $data) {
            $name = (string)xml::value($data['name'] ?? $oldid);
            $this->job->bump_count('tool_dynamicrule', 'skipped');
            $this->job->log('info', get_string('log:dynamicruleskipped', 'tool_centricmigrate', $name),
                'tool_dynamicrule', $oldid);
        }
    }

    /**
     * Import program user allocations.
     */
    public function import_allocations(): void {
        if (!self::is_available()) {
            return;
        }
        foreach ($this->package->iterate_entities('tool_program_users') as $oldid => $data) {
            $this->import_allocation($oldid, $data);
        }
    }

    /**
     * Map Workplace date fields onto local_program schedule columns.
     *
     * @param int $type
     * @param mixed $absolute
     * @param mixed $relative
     * @return array{type:string,date:int,offset:int,unit:string}
     */
    public static function map_date(int $type, $absolute, $relative): array {
        if ($type === self::WP_DATE_ABSOLUTE) {
            return [
                'type' => program_constants::DATE_ABSOLUTE,
                'date' => xml::int($absolute),
                'offset' => 0,
                'unit' => program_constants::UNIT_DAYS,
            ];
        }
        if ($type === self::WP_DATE_RELATIVE) {
            [$offset, $unit] = self::parse_relative($relative);
            return [
                'type' => program_constants::DATE_AFTER_ALLOCATION,
                'date' => 0,
                'offset' => $offset,
                'unit' => $unit,
            ];
        }
        return [
            'type' => program_constants::DATE_NOT_SET,
            'date' => 0,
            'offset' => 0,
            'unit' => program_constants::UNIT_DAYS,
        ];
    }

    /**
     * @param mixed $relative
     * @return array{0:int,1:string}
     */
    public static function parse_relative($relative): array {
        $relative = trim((string)xml::value($relative));
        if ($relative === '') {
            return [0, program_constants::UNIT_DAYS];
        }
        if (preg_match('/^(\d+)\s*(hour|day|week|month|year)s?$/i', $relative, $matches)) {
            $units = [
                'hour' => program_constants::UNIT_HOURS,
                'day' => program_constants::UNIT_DAYS,
                'week' => program_constants::UNIT_WEEKS,
                'month' => program_constants::UNIT_MONTHS,
                'year' => program_constants::UNIT_YEARS,
            ];
            return [(int)$matches[1], $units[strtolower($matches[2])]];
        }
        return [0, program_constants::UNIT_DAYS];
    }

    /**
     * @param int $criteria
     * @return array{completiontype:string,sequencing:int,completionrule:string,completionvalue:int}
     */
    public static function map_completion(int $criteria, int $atleast = 1): array {
        if ($criteria === self::WP_COMPLETION_ALL_ORDER) {
            return [
                'completiontype' => program_content::COMPLETION_ALL_ORDER,
                'sequencing' => program_constants::SEQUENCING_SEQUENTIAL,
                'completionrule' => program_constants::RULE_ALL,
                'completionvalue' => 0,
            ];
        }
        if ($criteria === self::WP_COMPLETION_AT_LEAST) {
            return [
                'completiontype' => program_content::COMPLETION_AT_LEAST,
                'sequencing' => program_constants::SEQUENCING_PARALLEL,
                'completionrule' => program_constants::RULE_COUNT,
                'completionvalue' => max(1, $atleast),
            ];
        }
        return [
            'completiontype' => program_content::COMPLETION_ALL_ANY,
            'sequencing' => program_constants::SEQUENCING_PARALLEL,
            'completionrule' => program_constants::RULE_ALL,
            'completionvalue' => 0,
        ];
    }

    /**
     * @param int $oldid
     * @param array $data
     */
    protected function import_program(int $oldid, array $data): void {
        $existing = $this->find_existing($oldid, $data);
        if ($existing) {
            $this->mapping->set('program', $oldid, (int)$existing->get('id'));
            $this->job->bump_count('program', 'mapped');
            $this->job->log('info', get_string('log:programmapped', 'tool_centricmigrate', [
                'name' => $existing->get('name'),
                'newid' => $existing->get('id'),
            ]), 'tool_program', $oldid);
            return;
        }

        $sets = xml::items($data, 'tool_program_sets', 'tool_program_set');
        $rootset = $this->get_root_set($sets);
        $completion = self::map_completion(
            xml::int($rootset['completioncriteria'] ?? 0),
            xml::int($rootset['completionatleast'] ?? 1, 1)
        );

        $startdate = self::map_date(
            xml::int($data['startdatetype'] ?? 0),
            $data['startdateabsolute'] ?? 0,
            $data['startdaterelative'] ?? null
        );
        $duedate = self::map_date(
            xml::int($data['duedatetype'] ?? 0),
            $data['duedateabsolute'] ?? 0,
            $data['duedaterelative'] ?? null
        );
        $enddate = self::map_date(
            xml::int($data['enddatetype'] ?? 0),
            $data['enddateabsolute'] ?? 0,
            $data['enddaterelative'] ?? null
        );
        $allocstart = self::map_date(
            xml::int($data['allocationstartdatetype'] ?? 0),
            $data['allocationstartdateabsolute'] ?? 0,
            null
        );
        $allocend = self::map_date(
            xml::int($data['allocationenddatetype'] ?? 0),
            $data['allocationenddateabsolute'] ?? 0,
            $data['allocationenddaterelative'] ?? null
        );

        $now = time();
        $name = (string)xml::value($data['fullname'] ?? '');
        if ($name === '') {
            $name = 'Workplace program ' . $oldid;
        }
        $shortname = $this->unique_shortname((string)xml::value($data['idnumber'] ?? ''), $oldid);
        $archived = xml::int($data['archived'] ?? 0);

        $program = new program(0, (object)[
            'name' => $name,
            'shortname' => $shortname,
            'description' => (string)xml::value($data['description'] ?? ''),
            'descriptionformat' => xml::int($data['descriptionformat'] ?? FORMAT_HTML, FORMAT_HTML),
            'status' => $archived ? program_constants::STATUS_DRAFT : program_constants::STATUS_ACTIVE,
            'startdate' => $startdate['date'],
            'enddate' => $enddate['date'],
            'availstarttype' => $startdate['type'],
            'availstartdate' => $startdate['date'],
            'availstartoffset' => $startdate['offset'],
            'availstartoffsetunit' => $startdate['unit'],
            'availduetype' => $duedate['type'],
            'availduedate' => $duedate['date'],
            'availdueoffset' => $duedate['offset'],
            'availdueoffsetunit' => $duedate['unit'],
            'availendtype' => $enddate['type'],
            'availenddate' => $enddate['date'],
            'availendoffset' => $enddate['offset'],
            'availendoffsetunit' => $enddate['unit'],
            'allocstarttype' => $allocstart['type'],
            'allocstartdate' => $allocstart['date'],
            'allocendtype' => $allocend['type'],
            'allocenddate' => $allocend['date'],
            'allocendoffset' => $allocend['offset'],
            'allocendoffsetunit' => $allocend['unit'],
            'visibility' => xml::int($data['visible'] ?? 1, 1),
            'allowdirectallocation' => xml::int($data['allowdirectallocation'] ?? 1, 1),
            'addtocoursegroups' => xml::int($data['autocreategroups'] ?? 0),
            'sequencing' => $completion['sequencing'],
            'completionrule' => $completion['completionrule'],
            'completionrulevalue' => $completion['completionvalue'],
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $program->create();
        $programid = (int)$program->get('id');

        $this->import_content($programid, $sets, xml::items($data, 'tool_program_courses', 'tool_program_course'), $completion);
        $this->import_program_files($programid, $data);

        $this->mapping->set('program', $oldid, $programid);
        $this->job->bump_count('program', 'created');
        $this->job->log('info', get_string('log:programcreated', 'tool_centricmigrate', [
            'name' => $name,
            'newid' => $programid,
        ]), 'tool_program', $oldid);
    }

    /**
     * @param int $programid
     * @param array $sets
     * @param array $courses
     * @param array $rootcompletion
     */
    protected function import_content(int $programid, array $sets, array $courses, array $rootcompletion): void {
        $setmap = [];
        usort($sets, static function ($a, $b) {
            return xml::int($a['parent'] ?? 0) <=> xml::int($b['parent'] ?? 0);
        });

        foreach ($sets as $set) {
            $oldsetid = xml::int($set['id'] ?? 0);
            $parentold = xml::int($set['parent'] ?? 0);
            $name = trim((string)xml::value($set['name'] ?? ''));
            $isroot = $parentold === 0;
            $flatten = $isroot && $name === '';

            if ($flatten) {
                $setmap[$oldsetid] = 0;
                continue;
            }

            $parentid = $isroot ? 0 : ($setmap[$parentold] ?? 0);
            $completion = self::map_completion(
                xml::int($set['completioncriteria'] ?? 0),
                xml::int($set['completionatleast'] ?? 1, 1)
            );
            if ($name === '') {
                $name = get_string('setofcourses', 'local_program');
            }
            $now = time();
            $item = new program_content(0, (object)[
                'programid' => $programid,
                'parentid' => $parentid,
                'itemtype' => program_content::TYPE_SET,
                'name' => $name,
                'courseid' => null,
                'completiontype' => $completion['completiontype'],
                'completionvalue' => $completion['completionvalue'],
                'sortorder' => xml::int($set['sortorder'] ?? 0),
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $item->create();
            $setmap[$oldsetid] = (int)$item->get('id');
        }

        foreach ($courses as $course) {
            $oldcourseid = xml::int($course['courseid'] ?? 0);
            $newcourseid = $this->mapping->get('course', $oldcourseid);
            if (!$newcourseid) {
                $newcourseid = $this->match_course_from_package($oldcourseid);
            }
            if (!$newcourseid) {
                $this->job->log('warning', get_string('log:courseunlinked', 'tool_centricmigrate', $oldcourseid),
                    'tool_program');
                continue;
            }
            $oldsetid = xml::int($course['setid'] ?? 0);
            $now = time();
            $item = new program_content(0, (object)[
                'programid' => $programid,
                'parentid' => $setmap[$oldsetid] ?? 0,
                'itemtype' => program_content::TYPE_COURSE,
                'name' => '',
                'courseid' => $newcourseid,
                'completiontype' => $rootcompletion['completiontype'],
                'completionvalue' => 0,
                'sortorder' => xml::int($course['sortorder'] ?? 0),
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $item->create();
        }
    }

    /**
     * @param int $oldid
     * @param array $data
     */
    protected function import_allocation(int $oldid, array $data): void {
        global $USER;

        $oldprogramid = xml::int($data['programid'] ?? 0);
        $olduserid = xml::int($data['userid'] ?? 0);
        $programid = $this->mapping->get('program', $oldprogramid);
        $userid = $this->resolve_user($olduserid);

        if (!$programid || !$userid) {
            $this->job->bump_count('program_users', 'skipped');
            $this->job->log('warning', get_string('log:allocationskipped', 'tool_centricmigrate', $oldid),
                'tool_program_users', $oldid);
            return;
        }

        $program = program::get_record(['id' => $programid], IGNORE_MISSING);
        if (!$program) {
            $this->job->bump_count('program_users', 'skipped');
            return;
        }

        if (assignment::get_record([
            'programid' => $programid,
            'userid' => $userid,
            'assignmenttype' => program_constants::ASSIGNMENT_MANUAL,
        ])) {
            $this->job->bump_count('program_users', 'mapped');
            return;
        }

        $now = time();
        $status = xml::int($data['status'] ?? 1, 1) ? 1 : 0;
        $assignment = new assignment(0, (object)[
            'programid' => $programid,
            'userid' => $userid,
            'cohortid' => null,
            'assignmenttype' => program_constants::ASSIGNMENT_MANUAL,
            'status' => $status,
            'timeassigned' => xml::int($data['startdate'] ?? 0) ?: $now,
            'timeexpires' => xml::int($data['enddate'] ?? 0),
            'assignedby' => $USER->id ?? 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $assignment->create();

        if ($status && !empty($this->options['enrolprogramusers'])) {
            assignment_manager::assign_user($programid, $userid, (int)($USER->id ?? 0), true);
        }

        $this->job->bump_count('program_users', 'created');
        $this->job->log('info', get_string('log:allocationcreated', 'tool_centricmigrate', [
            'userid' => $userid,
            'programid' => $programid,
        ]), 'tool_program_users', $oldid);
    }

    /**
     * @param int $oldid
     * @param array $data
     * @return program|null
     */
    protected function find_existing(int $oldid, array $data): ?program {
        $mapped = $this->mapping->get('program', $oldid);
        if ($mapped) {
            $program = program::get_record(['id' => $mapped], IGNORE_MISSING);
            if ($program) {
                return $program;
            }
        }

        $idnumber = trim((string)xml::value($data['idnumber'] ?? ''));
        if ($idnumber !== '') {
            $program = program::get_record(['shortname' => $idnumber], IGNORE_MISSING);
            if ($program) {
                return $program;
            }
        }

        $name = trim((string)xml::value($data['fullname'] ?? ''));
        if ($name !== '') {
            $matches = program::get_records(['name' => $name]);
            if (count($matches) === 1) {
                return reset($matches);
            }
        }

        return null;
    }

    /**
     * @param array $sets
     * @return array
     */
    protected function get_root_set(array $sets): array {
        foreach ($sets as $set) {
            if (xml::int($set['parent'] ?? 0) === 0) {
                return $set;
            }
        }
        return $sets[0] ?? [];
    }

    /**
     * @param string $idnumber
     * @param int $oldid
     * @return string
     */
    protected function unique_shortname(string $idnumber, int $oldid): string {
        global $DB;

        $shortname = trim($idnumber);
        if ($shortname === '') {
            $shortname = 'wp-' . $oldid;
        }
        $base = $shortname;
        $i = 2;
        while ($DB->record_exists('local_program', ['shortname' => $shortname])) {
            $shortname = $base . '_' . $i;
            $i++;
        }
        return $shortname;
    }

    /**
     * @param int $oldcourseid
     * @return int|null
     */
    protected function match_course_from_package(int $oldcourseid): ?int {
        $data = $this->package->read_entity('course', $oldcourseid);
        if (!$data) {
            return null;
        }
        $importer = new course_importer($this->package, $this->mapping, $this->job, ['importcourses' => false]);
        $importer->import_course($oldcourseid, $data);
        return $this->mapping->get('course', $oldcourseid);
    }

    /**
     * @param int $olduserid
     * @return int|null
     */
    protected function resolve_user(int $olduserid): ?int {
        $mapped = $this->mapping->get('user', $olduserid);
        if ($mapped) {
            return $mapped;
        }
        $stub = $this->package->read_entity('user', $olduserid, 'mappings');
        if (!$stub) {
            $stub = $this->package->read_entity('user', $olduserid);
        }
        if (!$stub) {
            return null;
        }
        $importer = new user_importer($this->package, $this->mapping, $this->job, $this->options);
        $user = $importer->map_existing_user($olduserid, $stub);
        return $user ? (int)$user->id : null;
    }

    /**
     * Copy Workplace program images into local_program file areas when present.
     *
     * @param int $programid
     * @param array $data
     */
    protected function import_program_files(int $programid, array $data): void {
        $files = xml::files($data);
        if (empty($files)) {
            return;
        }
        $fs = get_file_storage();
        $context = \context_system::instance();
        foreach ($files as $file) {
            $hash = (string)xml::value($file['contenthash'] ?? '');
            $filename = (string)xml::value($file['filename'] ?? '');
            $filearea = (string)xml::value($file['filearea'] ?? '');
            if ($hash === '' || $filename === '') {
                continue;
            }
            $targetarea = in_array($filearea, ['programimage', 'overview', 'image'], true)
                ? 'programimage'
                : 'programdescription';
            try {
                $source = $this->package->extract_content($hash);
            } catch (\Throwable $e) {
                continue;
            }
            $fs->create_file_from_pathname([
                'contextid' => $context->id,
                'component' => 'local_program',
                'filearea' => $targetarea,
                'itemid' => $programid,
                'filepath' => (string)(xml::value($file['filepath'] ?? '/') ?: '/'),
                'filename' => $filename,
            ], $source);
        }
    }
}
