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
