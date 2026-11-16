<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

namespace tool_centricmigrate\local;

defined('MOODLE_INTERNAL') || die();

use tool_centricmigrate\job;
use tool_centricmigrate\mapping;
use tool_centricmigrate\package;

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Create course categories from a Moodle category-report CSV.
 */
class category_importer {

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
     * Create or match every category, parents first.
     */
    public function import_all(): void {
        $rows = [];
        foreach ($this->package->iterate_entities('coursecategory') as $oldid => $data) {
            $segments = $data['segments'] ?? package::split_category_path(
                (string)($data['path'] ?? ''),
                (string)($data['name'] ?? '')
            );
            if ($segments === []) {
                $this->job->bump_count('coursecategory', 'skipped');
                continue;
            }
            $data['segments'] = $segments;
            $rows[] = ['oldid' => $oldid, 'data' => $data, 'depth' => count($segments)];
        }

        usort($rows, static function (array $a, array $b): int {
            if ($a['depth'] === $b['depth']) {
                return $a['oldid'] <=> $b['oldid'];
            }
            return $a['depth'] <=> $b['depth'];
        });

        foreach ($rows as $row) {
            $this->import_category($row['oldid'], $row['data']);
        }
    }

    /**
     * @param int $oldid
     * @param array $data
     */
    protected function import_category(int $oldid, array $data): void {
        $segments = $data['segments'];
        $leafidnumber = trim((string)($data['idnumber'] ?? ''));
        $parentid = 0;
        $category = null;
        $lastindex = count($segments) - 1;

        try {
            foreach ($segments as $index => $name) {
                $idnumber = ($index === $lastindex) ? $leafidnumber : '';
                $category = $this->ensure_category($parentid, $name, $idnumber);
                $parentid = (int)$category->id;
            }
        } catch (\Throwable $e) {
            $this->job->bump_count('coursecategory', 'failed');
            $this->job->log('error', $e->getMessage(), 'coursecategory', $oldid);
            return;
        }

        if (!$category) {
            $this->job->bump_count('coursecategory', 'failed');
            return;
        }

        $this->mapping->set('coursecategory', $oldid, (int)$category->id);
        $result = $category->_centricmigrate_result ?? 'mapped';
        $this->job->bump_count('coursecategory', $result);
        $logkey = $result === 'created' ? 'log:categorycreated' : (
            $result === 'updated' ? 'log:categoryupdated' : 'log:categorymapped'
        );
        $this->job->log('info', get_string($logkey, 'tool_centricmigrate', [
            'name' => $category->name,
            'newid' => $category->id,
        ]), 'coursecategory', $oldid);
    }

    /**
     * Find or create a category under a parent.
     *
     * @param int $parentid
     * @param string $name
     * @param string $idnumber Applied only when this node is the CSV row's leaf.
     * @return \stdClass
     */
    protected function ensure_category(int $parentid, string $name, string $idnumber): \stdClass {
        global $DB;

        $existing = $this->find_child($parentid, $name);
        if (!$existing && $idnumber !== '') {
            $byidnumber = $DB->get_record('course_categories', ['idnumber' => $idnumber]);
            if ($byidnumber && (int)$byidnumber->parent === $parentid) {
                $existing = $byidnumber;
            }
        }

        if ($existing) {
            $existing->_centricmigrate_result = 'mapped';
            if ($idnumber !== '' && (string)$existing->idnumber === '') {
                if (!$DB->record_exists('course_categories', ['idnumber' => $idnumber])) {
                    $existing->idnumber = $idnumber;
                    $DB->set_field('course_categories', 'idnumber', $idnumber, ['id' => $existing->id]);
                    $existing->_centricmigrate_result = 'updated';
                }
            }
            return $existing;
        }

        $data = (object)[
            'name' => $name,
            'parent' => $parentid,
            'idnumber' => $idnumber,
        ];
        try {
            $created = \core_course_category::create($data);
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'categoryidnumbertaken' && $idnumber !== '') {
                $existing = $DB->get_record('course_categories', ['idnumber' => $idnumber], '*', MUST_EXIST);
                $existing->_centricmigrate_result = 'mapped';
                return $existing;
            }
            throw $e;
        }

        $record = $DB->get_record('course_categories', ['id' => $created->id], '*', MUST_EXIST);
        $record->_centricmigrate_result = 'created';
        return $record;
    }

    /**
     * @param int $parentid
     * @param string $name
     * @return \stdClass|null
     */
    protected function find_child(int $parentid, string $name): ?\stdClass {
        global $DB;
        $matches = $DB->get_records('course_categories', ['parent' => $parentid, 'name' => $name], 'id ASC', '*', 0, 1);
        if (!$matches) {
            return null;
        }
        return reset($matches);
    }
}
