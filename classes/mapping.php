<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

namespace tool_centricmigrate;

defined('MOODLE_INTERNAL') || die();

/**
 * Persistent old-id to new-id mappings for a Workplace site.
 */
class mapping {

    /** @var string */
    protected $siteidentifier;

    /** @var array */
    protected $cache = [];

    /**
     * @param string $siteidentifier
     */
    public function __construct(string $siteidentifier) {
        $this->siteidentifier = $siteidentifier;
    }

    /**
     * @param string $entity
     * @param int $oldid
     * @return int|null
     */
    public function get(string $entity, int $oldid): ?int {
        if ($oldid < 1) {
            return null;
        }
        $key = $entity . ':' . $oldid;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        global $DB;
        $newid = $DB->get_field('tool_centricmigrate_map', 'newid', [
            'siteidentifier' => $this->siteidentifier,
            'entity' => $entity,
            'oldid' => $oldid,
        ]);
        $this->cache[$key] = $newid === false ? null : (int)$newid;
        return $this->cache[$key];
    }

    /**
     * Get a mapped id only if the target record still exists.
     *
     * @param string $entity
     * @param int $oldid
     * @param string $table
     * @param array $extra Extra record conditions (e.g. deleted => 0).
     * @return int|null
     */
    public function get_existing(string $entity, int $oldid, string $table, array $extra = []): ?int {
        global $DB;

        $newid = $this->get($entity, $oldid);
        if (!$newid) {
            return null;
        }
        $conditions = array_merge(['id' => $newid], $extra);
        if (!$DB->record_exists($table, $conditions)) {
            $this->delete($entity, $oldid);
            return null;
        }
        return $newid;
    }

    /**
     * @param string $entity
     * @param int $oldid
     */
    public function delete(string $entity, int $oldid): void {
        global $DB;

        $DB->delete_records('tool_centricmigrate_map', [
            'siteidentifier' => $this->siteidentifier,
            'entity' => $entity,
            'oldid' => $oldid,
        ]);
        unset($this->cache[$entity . ':' . $oldid]);
    }

    /**
     * @param string $entity
     * @param int $oldid
     * @param int $newid
     */
    public function set(string $entity, int $oldid, int $newid): void {
        global $DB;

        $record = $DB->get_record('tool_centricmigrate_map', [
            'siteidentifier' => $this->siteidentifier,
            'entity' => $entity,
            'oldid' => $oldid,
        ]);
        $now = time();
        if ($record) {
            $record->newid = $newid;
            $record->timemodified = $now;
            $DB->update_record('tool_centricmigrate_map', $record);
        } else {
            $DB->insert_record('tool_centricmigrate_map', (object)[
                'siteidentifier' => $this->siteidentifier,
                'entity' => $entity,
                'oldid' => $oldid,
                'newid' => $newid,
                'timemodified' => $now,
            ]);
        }
        $this->cache[$entity . ':' . $oldid] = $newid;
    }
}
