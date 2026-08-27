<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

namespace tool_centricmigrate;

defined('MOODLE_INTERNAL') || die();

use tool_centricmigrate\local\cohort_importer;
use tool_centricmigrate\local\course_importer;
use tool_centricmigrate\local\program_importer;
use tool_centricmigrate\local\user_importer;

/**
 * Runs one batch of a Workplace import job.
 */
class processor {

    /** Max users/cohorts/members per web request. */
    public const BATCH_FAST = 200;

    /** @var job */
    protected $job;

    /** @var package */
    protected $package;

    /** @var mapping */
    protected $mapping;

    /**
     * @param job $job
     */
    public function __construct(job $job) {
        $this->job = $job;
        $this->package = new package($job->get_package_path());
        $this->mapping = new mapping($job->get_siteidentifier());
    }

    public function __destruct() {
        if ($this->package) {
            $this->package->close();
        }
    }

    /**
     * Process until the job is complete. Used by CLI.
     */
    public function run_all(): void {
        while ($this->job->get_step() !== job::STEP_DONE && $this->job->get_status() !== job::STATUS_ERROR) {
            $this->run_batch(true);
        }
    }

    /**
     * Process the next batch. Returns true when more work remains.
     *
     * @param bool $cli
     * @return bool
     */
    public function run_batch(bool $cli = false): bool {
        $options = $this->job->get_options();
        $step = $this->job->get_step();
        if ($step === job::STEP_PREVIEW) {
            $step = $this->next_needed_step(job::STEP_USERS, $options);
            $this->job->set_progress($step, 0);
        }

        try {
            switch ($this->job->get_step()) {
                case job::STEP_USERS:
                    $this->run_users($options);
                    break;
                case job::STEP_COHORTS:
                    $this->run_cohorts($options);
                    break;
                case job::STEP_MEMBERS:
                    $this->run_members($options);
                    break;
                case job::STEP_COURSES:
                    $this->run_courses($options, $cli);
                    break;
                case job::STEP_PROGRAMS:
                    $this->run_programs($options);
                    break;
                case job::STEP_ALLOCATIONS:
                    $this->run_allocations($options);
                    break;
                case job::STEP_DONE:
                    return false;
            }
        } catch (\Throwable $e) {
            $this->job->fail($e->getMessage());
            return false;
        }

        $this->job->save();
        return $this->job->get_step() !== job::STEP_DONE && $this->job->get_status() !== job::STATUS_ERROR;
    }

    /**
     * Human-readable current progress line.
     *
     * @return string
     */
    public function get_progress_label(): string {
        $step = $this->job->get_step();
        $cursor = $this->job->get_cursor();
        $label = get_string('step' . $step, 'tool_centricmigrate');
        if ($step === job::STEP_COURSES) {
            $total = $this->package->count_entities('course');
            return $label . ': ' . min($cursor, $total) . ' / ' . $total;
        }
        return $label;
    }

    /**
     * @param array $options
     */
    protected function run_users(array $options): void {
        if (!empty($options['importusers'])) {
            $importer = new user_importer($this->package, $this->mapping, $this->job, $options);
            $importer->import_all();
        }
        $this->advance(job::STEP_USERS, $options);
    }

    /**
     * @param array $options
     */
    protected function run_cohorts(array $options): void {
        if (!empty($options['importcohorts'])) {
            $importer = new cohort_importer($this->package, $this->mapping, $this->job);
            $importer->import_cohorts();
        }
        $this->advance(job::STEP_COHORTS, $options);
    }

    /**
     * @param array $options
     */
    protected function run_members(array $options): void {
        if (!empty($options['importcohortmembers'])) {
            $importer = new cohort_importer($this->package, $this->mapping, $this->job);
            $importer->import_members();
        }
        $this->advance(job::STEP_MEMBERS, $options);
    }

    /**
     * @param array $options
     * @param bool $cli
     */
    protected function run_courses(array $options, bool $cli): void {
        $ids = array_keys($this->package->get_entity_files('course'));
        $cursor = $this->job->get_cursor();
        $limit = $cli ? count($ids) : 1;
        $importer = new course_importer($this->package, $this->mapping, $this->job, $options);

        $processed = 0;
        while ($cursor < count($ids) && $processed < $limit) {
            $oldid = (int)$ids[$cursor];
            $data = $this->package->read_entity('course', $oldid);
            if ($data) {
                $importer->import_course($oldid, $data);
            }
            $cursor++;
            $processed++;
            $this->job->set_progress(job::STEP_COURSES, $cursor);
        }

        if ($cursor >= count($ids)) {
            $this->advance(job::STEP_COURSES, $options);
        }
    }

    /**
     * @param array $options
     */
    protected function run_programs(array $options): void {
        if (!empty($options['importprograms'])) {
            $importer = new program_importer($this->package, $this->mapping, $this->job, $options);
            $importer->import_programs();
        }
        $this->advance(job::STEP_PROGRAMS, $options);
    }

    /**
     * @param array $options
     */
    protected function run_allocations(array $options): void {
        if (!empty($options['importprogramusers'])) {
            $importer = new program_importer($this->package, $this->mapping, $this->job, $options);
            $importer->import_allocations();
        }
        $this->advance(job::STEP_ALLOCATIONS, $options);
    }

    /**
     * @param string $finished
     * @param array $options
     */
    protected function advance(string $finished, array $options): void {
        $next = $this->next_needed_step($this->step_after($finished), $options);
        $this->job->set_progress($next, 0);
    }

    /**
     * @param string $step
     * @return string
     */
    protected function step_after(string $step): string {
        $order = [
            job::STEP_USERS => job::STEP_COHORTS,
            job::STEP_COHORTS => job::STEP_MEMBERS,
            job::STEP_MEMBERS => job::STEP_COURSES,
            job::STEP_COURSES => job::STEP_PROGRAMS,
            job::STEP_PROGRAMS => job::STEP_ALLOCATIONS,
            job::STEP_ALLOCATIONS => job::STEP_DONE,
        ];
        return $order[$step] ?? job::STEP_DONE;
    }

    /**
     * Skip empty/disabled steps.
     *
     * @param string $step
     * @param array $options
     * @return string
     */
    protected function next_needed_step(string $step, array $options): string {
        $summary = $this->package->summarize();
        $checks = [
            job::STEP_USERS => !empty($options['importusers']) && $summary['users'] > 0,
            job::STEP_COHORTS => !empty($options['importcohorts']) && $summary['cohorts'] > 0,
            job::STEP_MEMBERS => !empty($options['importcohortmembers']) && $summary['cohortmembers'] > 0,
            job::STEP_COURSES => $summary['courses'] > 0,
            job::STEP_PROGRAMS => !empty($options['importprograms']) && $summary['programs'] > 0
                && program_importer::is_available(),
            job::STEP_ALLOCATIONS => !empty($options['importprogramusers']) && $summary['programusers'] > 0
                && program_importer::is_available(),
        ];

        while ($step !== job::STEP_DONE && empty($checks[$step])) {
            $step = $this->step_after($step);
        }
        return $step;
    }
}
