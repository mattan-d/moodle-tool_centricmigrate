<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

namespace tool_centricmigrate\local;

defined('MOODLE_INTERNAL') || die();

use tool_centricmigrate\job;
use tool_centricmigrate\mapping;
use tool_centricmigrate\package;
use tool_centricmigrate\xml;

global $CFG;
require_once($CFG->dirroot . '/cohort/lib.php');

/**
 * Import Workplace system cohorts and memberships.
 */
class cohort_importer {

    /** @var package */
    protected $package;

    /** @var mapping */
    protected $mapping;

    /** @var job */
    protected $job;

    /**
     * @param package $package
     * @param mapping $mapping
     * @param job $job
     */
    public function __construct(package $package, mapping $mapping, job $job) {
        $this->package = $package;
        $this->mapping = $mapping;
        $this->job = $job;
    }

    /**
     * Import cohort definitions.
     */
    public function import_cohorts(): void {
        foreach ($this->package->iterate_entities('cohort') as $oldid => $data) {
            $this->import_cohort($oldid, $data);
        }
        foreach ($this->package->iterate_entities('cohort', 'mappings') as $oldid => $data) {
            if ($this->mapping->get('cohort', $oldid)) {
                continue;
            }
            $existing = $this->find_existing($oldid, $data);
            if ($existing) {
                $this->mapping->set('cohort', $oldid, (int)$existing->id);
                $this->job->bump_count('cohort', 'mapped');
            }
        }
    }

    /**
     * Import cohort memberships. Users must already be mapped.
     */
    public function import_members(): void {
        foreach ($this->package->iterate_entities('cohort_members') as $oldid => $data) {
            $this->import_member($oldid, $data);
        }
    }

    /**
     * @param int $oldid
     * @param array $data
     */
    protected function import_cohort(int $oldid, array $data): void {
        $existing = $this->find_existing($oldid, $data);
        if ($existing) {
            $this->mapping->set('cohort', $oldid, (int)$existing->id);
            $this->job->bump_count('cohort', 'mapped');
            $this->job->log('info', get_string('log:cohortmapped', 'tool_centricmigrate', [
                'name' => $existing->name,
                'newid' => $existing->id,
            ]), 'cohort', $oldid);
            return;
        }

        $cohort = (object)[
            'contextid' => \context_system::instance()->id,
            'name' => (string)xml::value($data['name'] ?? ''),
            'idnumber' => (string)xml::value($data['idnumber'] ?? ''),
            'description' => (string)xml::value($data['description'] ?? ''),
            'descriptionformat' => xml::int($data['descriptionformat'] ?? FORMAT_HTML, FORMAT_HTML),
            'visible' => xml::int($data['visible'] ?? 1, 1),
            'component' => '',
        ];
        if ($cohort->name === '') {
            $cohort->name = 'wp-cohort-' . $oldid;
        }
        if ($cohort->idnumber === '') {
            $cohort->idnumber = 'wp-cohort-' . $oldid;
        }

        $newid = cohort_add_cohort($cohort);
        $this->mapping->set('cohort', $oldid, (int)$newid);
        $this->job->bump_count('cohort', 'created');
        $this->job->log('info', get_string('log:cohortcreated', 'tool_centricmigrate', [
            'name' => $cohort->name,
            'newid' => $newid,
        ]), 'cohort', $oldid);
    }

    /**
     * @param int $oldid
     * @param array $data
     */
    protected function import_member(int $oldid, array $data): void {
        $oldcohort = xml::int($data['cohortid'] ?? 0);
        $olduser = xml::int($data['userid'] ?? 0);
        $cohortid = $this->mapping->get('cohort', $oldcohort);
        $userid = $this->resolve_user($olduser);

        if (!$cohortid || !$userid) {
            $this->job->bump_count('cohort_members', 'skipped');
            $this->job->log('warning', get_string('log:memberskipped', 'tool_centricmigrate', $oldid),
                'cohort_members', $oldid);
            return;
        }

        if (cohort_is_member($cohortid, $userid)) {
            $this->job->bump_count('cohort_members', 'mapped');
            return;
        }

        cohort_add_member($cohortid, $userid);
        $this->job->bump_count('cohort_members', 'created');
        $this->job->log('info', get_string('log:memberadded', 'tool_centricmigrate', [
            'userid' => $userid,
            'cohortid' => $cohortid,
        ]), 'cohort_members', $oldid);
    }

    /**
     * @param int $oldid
     * @param array $data
     * @return \stdClass|null
     */
    protected function find_existing(int $oldid, array $data): ?\stdClass {
        global $DB;

        $mapped = $this->mapping->get_existing('cohort', $oldid, 'cohort');
        if ($mapped) {
            $cohort = $DB->get_record('cohort', ['id' => $mapped]);
            if ($cohort) {
                return $cohort;
            }
        }

        $idnumber = trim((string)xml::value($data['idnumber'] ?? ''));
        $contextid = \context_system::instance()->id;
        if ($idnumber !== '') {
            $cohort = $DB->get_record('cohort', ['idnumber' => $idnumber, 'contextid' => $contextid]);
            if ($cohort) {
                return $cohort;
            }
        }

        $stable = 'wp-cohort-' . $oldid;
        $cohort = $DB->get_record('cohort', ['idnumber' => $stable, 'contextid' => $contextid]);
        if ($cohort) {
            return $cohort;
        }

        $name = trim((string)xml::value($data['name'] ?? ''));
        if ($name !== '') {
            $cohorts = $DB->get_records('cohort', ['name' => $name, 'contextid' => $contextid], 'id ASC', '*', 0, 2);
            if (count($cohorts) === 1) {
                return reset($cohorts);
            }
        }

        return null;
    }

    /**
     * @param int $olduserid
     * @return int|null
     */
    protected function resolve_user(int $olduserid): ?int {
        $mapped = $this->mapping->get_existing('user', $olduserid, 'user', ['deleted' => 0]);
        if ($mapped) {
            return $mapped;
        }
        $stub = $this->package->read_entity('user', $olduserid, 'mappings');
        if (!$stub) {
            return null;
        }
        $importer = new user_importer($this->package, $this->mapping, $this->job, []);
        $user = $importer->map_existing_user($olduserid, $stub);
        return $user ? (int)$user->id : null;
    }
}
