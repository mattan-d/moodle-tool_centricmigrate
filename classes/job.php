<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

namespace tool_centricmigrate;

defined('MOODLE_INTERNAL') || die();

/**
 * Import job record helper.
 */
class job {
    public const STATUS_PREVIEW = 0;
    public const STATUS_RUNNING = 1;
    public const STATUS_COMPLETE = 2;
    public const STATUS_ERROR = 3;

    public const STEP_PREVIEW = 'preview';
    public const STEP_USERS = 'users';
    public const STEP_COHORTS = 'cohorts';
    public const STEP_MEMBERS = 'members';
    public const STEP_COURSES = 'courses';
    public const STEP_PROGRAMS = 'programs';
    public const STEP_ALLOCATIONS = 'allocations';
    public const STEP_DONE = 'done';

    /** @var \stdClass */
    protected $record;

    /**
     * @param \stdClass $record
     */
    public function __construct(\stdClass $record) {
        $this->record = $record;
    }

    /**
     * @param int $id
     * @return self
     */
    public static function get(int $id): self {
        global $DB;
        $record = $DB->get_record('tool_centricmigrate_job', ['id' => $id], '*', IGNORE_MISSING);
        if (!$record) {
            throw new \moodle_exception('jobnotfound', 'tool_centricmigrate');
        }
        return new self($record);
    }

    /**
     * @param array $data
     * @return self
     */
    public static function create(array $data): self {
        global $DB, $USER;

        $now = time();
        $id = $DB->insert_record('tool_centricmigrate_job', (object)[
            'userid' => $data['userid'] ?? $USER->id,
            'filename' => $data['filename'],
            'sourcepath' => $data['sourcepath'] ?? null,
            'siteidentifier' => $data['siteidentifier'],
            'exporter' => $data['exporter'] ?? '',
            'options' => json_encode($data['options'] ?? []),
            'step' => self::STEP_PREVIEW,
            'cursorpos' => 0,
            'status' => self::STATUS_PREVIEW,
            'counts' => json_encode([]),
            'logs' => json_encode([]),
            'errormsg' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        return self::get((int)$id);
    }

    /**
     * @return int
     */
    public function get_id(): int {
        return (int)$this->record->id;
    }

    /**
     * @return \stdClass
     */
    public function get_record(): \stdClass {
        return $this->record;
    }

    /**
     * @return string
     */
    public function get_step(): string {
        return $this->record->step;
    }

    /**
     * @return int
     */
    public function get_status(): int {
        return (int)$this->record->status;
    }

    /**
     * @return int
     */
    public function get_cursor(): int {
        return (int)$this->record->cursorpos;
    }

    /**
     * @return string
     */
    public function get_siteidentifier(): string {
        return $this->record->siteidentifier;
    }

    /**
     * @return array
     */
    public function get_options(): array {
        $options = json_decode($this->record->options ?? '{}', true);
        return is_array($options) ? $options : [];
    }

    /**
     * @return array
     */
    public function get_counts(): array {
        $counts = json_decode($this->record->counts ?? '{}', true);
        return is_array($counts) ? $counts : [];
    }

    /**
     * @return array
     */
    public function get_logs(): array {
        $logs = json_decode($this->record->logs ?? '[]', true);
        return is_array($logs) ? $logs : [];
    }

    /**
     * Absolute path to the zip for this job.
     *
     * @return string
     */
    public function get_package_path(): string {
        if (!empty($this->record->sourcepath) && file_exists($this->record->sourcepath)) {
            return $this->record->sourcepath;
        }

        $fs = get_file_storage();
        $files = $fs->get_area_files(
            \context_system::instance()->id,
            'tool_centricmigrate',
            'package',
            $this->get_id(),
            'id',
            false
        );
        $file = reset($files);
        if (!$file) {
            throw new \moodle_exception('packagenotfound', 'tool_centricmigrate');
        }

        $dir = make_temp_directory('centricmigrate/jobs/' . $this->get_id());
        $path = $dir . '/' . $file->get_filename();
        if (!file_exists($path) || filesize($path) !== (int)$file->get_filesize()) {
            $file->copy_content_to($path);
        }
        return $path;
    }

    /**
     * @param string $step
     * @param int $cursor
     */
    public function set_progress(string $step, int $cursor): void {
        $this->record->step = $step;
        $this->record->cursorpos = $cursor;
        $this->record->status = $step === self::STEP_DONE ? self::STATUS_COMPLETE : self::STATUS_RUNNING;
        $this->save();
    }

    /**
     * @param array $options
     */
    public function set_options(array $options): void {
        $this->record->options = json_encode($options);
        $this->save();
    }

    /**
     * @param string $entity
     * @param string $result created|updated|mapped|skipped|failed
     * @param int $amount
     */
    public function bump_count(string $entity, string $result, int $amount = 1): void {
        $counts = $this->get_counts();
        if (!isset($counts[$entity][$result])) {
            $counts[$entity][$result] = 0;
        }
        $counts[$entity][$result] += $amount;
        $this->record->counts = json_encode($counts);
    }

    /**
     * @param string $level
     * @param string $message
     * @param string $entity
     * @param int|null $oldid
     */
    public function log(string $level, string $message, string $entity = '', ?int $oldid = null): void {
        $logs = $this->get_logs();
        $logs[] = [
            'level' => $level,
            'message' => $message,
            'entity' => $entity,
            'oldid' => $oldid,
            'time' => time(),
        ];
        if (count($logs) > 2000) {
            $logs = array_slice($logs, -2000);
        }
        $this->record->logs = json_encode($logs);
    }

    /**
     * Persist counts and logs without changing step.
     */
    public function save(): void {
        global $DB;
        $this->record->timemodified = time();
        $DB->update_record('tool_centricmigrate_job', $this->record);
    }

    /**
     * @param string $message
     */
    public function fail(string $message): void {
        $this->record->status = self::STATUS_ERROR;
        $this->record->errormsg = $message;
        $this->log('error', $message);
        $this->save();
    }

    /**
     * Store the uploaded zip against this job.
     *
     * @param \stored_file $file
     */
    public function store_package(\stored_file $file): void {
        $fs = get_file_storage();
        $fs->create_file_from_storedfile([
            'contextid' => \context_system::instance()->id,
            'component' => 'tool_centricmigrate',
            'filearea' => 'package',
            'itemid' => $this->get_id(),
            'filepath' => '/',
            'filename' => $file->get_filename(),
        ], $file);
    }

    /**
     * Store a zip from a filesystem path.
     *
     * @param string $path
     * @param string $filename
     */
    public function store_package_from_path(string $path, string $filename): void {
        $fs = get_file_storage();
        $fs->create_file_from_pathname([
            'contextid' => \context_system::instance()->id,
            'component' => 'tool_centricmigrate',
            'filearea' => 'package',
            'itemid' => $this->get_id(),
            'filepath' => '/',
            'filename' => $filename,
        ], $path);
    }
}
