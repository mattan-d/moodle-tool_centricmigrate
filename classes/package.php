<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

namespace tool_centricmigrate;

defined('MOODLE_INTERNAL') || die();

/**
 * Moodle Workplace export package reader.
 */
class package implements \IteratorAggregate {

    /** @var string */
    protected $filepath;

    /** @var \ZipArchive */
    protected $zip;

    /** @var array|null */
    protected $manifest = null;

    /** @var array */
    protected $index = [];

    /**
     * @param string $filepath Absolute path to the zip.
     */
    public function __construct(string $filepath) {
        $this->filepath = $filepath;
        $this->zip = new \ZipArchive();
        if ($this->zip->open($filepath) !== true) {
            throw new \moodle_exception('error:unzip', 'tool_centricmigrate');
        }
        $this->build_index();
    }

    public function __destruct() {
        $this->close();
    }

    /**
     * Close the zip handle.
     */
    public function close(): void {
        if ($this->zip instanceof \ZipArchive) {
            @$this->zip->close();
        }
    }

    /**
     * @return \ArrayIterator
     */
    public function getIterator(): \Traversable {
        return new \ArrayIterator($this->index);
    }

    /**
     * @return array
     */
    public function get_manifest(): array {
        if ($this->manifest !== null) {
            return $this->manifest;
        }
        $raw = $this->read('workplace.xml');
        if ($raw === null) {
            throw new \moodle_exception('invalidpackage', 'tool_centricmigrate');
        }
        $this->manifest = xml::to_array($raw);
        if (empty($this->manifest)) {
            throw new \moodle_exception('invalidpackage', 'tool_centricmigrate');
        }
        return $this->manifest;
    }

    /**
     * @return string
     */
    public function get_siteidentifier(): string {
        return (string)($this->get_manifest()['siteidentifier'] ?? '');
    }

    /**
     * @return string
     */
    public function get_exporter(): string {
        return (string)($this->get_manifest()['exporter'] ?? '');
    }

    /**
     * Entity type names present under data/.
     *
     * @return string[]
     */
    public function get_entity_types(): array {
        return array_keys($this->index['data'] ?? []);
    }

    /**
     * Paths for an entity type, keyed by original id.
     *
     * @param string $entity
     * @param string $section data or mappings
     * @return string[] oldid => zip path
     */
    public function get_entity_files(string $entity, string $section = 'data'): array {
        return $this->index[$section][$entity] ?? [];
    }

    /**
     * @param string $entity
     * @param string $section
     * @return int
     */
    public function count_entities(string $entity, string $section = 'data'): int {
        return count($this->get_entity_files($entity, $section));
    }

    /**
     * Parsed XML document for one exported record.
     *
     * @param string $entity
     * @param int $oldid
     * @param string $section
     * @return array|null
     */
    public function read_entity(string $entity, int $oldid, string $section = 'data'): ?array {
        $files = $this->get_entity_files($entity, $section);
        if (empty($files[$oldid])) {
            return null;
        }
        $raw = $this->read($files[$oldid]);
        if ($raw === null) {
            return null;
        }
        $parsed = xml::to_array($raw);
        $parsed['_oldid'] = $oldid;
        return $parsed;
    }

    /**
     * Iterate parsed records for an entity type.
     *
     * @param string $entity
     * @param string $section
     * @return \Generator
     */
    public function iterate_entities(string $entity, string $section = 'data'): \Generator {
        foreach ($this->get_entity_files($entity, $section) as $oldid => $path) {
            $record = $this->read_entity($entity, (int)$oldid, $section);
            if ($record !== null) {
                yield (int)$oldid => $record;
            }
        }
    }

    /**
     * Read a file from the zip as a string.
     *
     * @param string $path
     * @return string|null
     */
    public function read(string $path): ?string {
        $contents = $this->zip->getFromName($path);
        if ($contents === false) {
            return null;
        }
        return $contents;
    }

    /**
     * Extract a hashed content file to a temp path.
     *
     * @param string $contenthash
     * @return string Absolute path
     */
    public function extract_content(string $contenthash): string {
        $zippath = 'files/' . substr($contenthash, 0, 2) . '/' . substr($contenthash, 2, 2) . '/' . $contenthash;
        $dir = make_temp_directory('centricmigrate/files');
        $target = $dir . '/' . $contenthash;
        if (file_exists($target) && filesize($target) > 0) {
            return $target;
        }
        $stream = $this->zip->getStream($zippath);
        if ($stream === false) {
            throw new \moodle_exception('error:unzip', 'tool_centricmigrate');
        }
        $out = fopen($target, 'wb');
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);
        return $target;
    }

    /**
     * @return array
     */
    public function summarize(): array {
        return [
            'siteidentifier' => $this->get_siteidentifier(),
            'exporter' => $this->get_exporter(),
            'wwwroot' => (string)($this->get_manifest()['wwwroot'] ?? ''),
            'release' => (string)($this->get_manifest()['release'] ?? ''),
            'createdbyname' => (string)($this->get_manifest()['createdbyname'] ?? ''),
            'users' => $this->count_entities('user'),
            'cohorts' => $this->count_entities('cohort'),
            'cohortmembers' => $this->count_entities('cohort_members'),
            'courses' => $this->count_entities('course'),
            'programs' => $this->count_entities('tool_program'),
            'programusers' => $this->count_entities('tool_program_users'),
            'dynamicrules' => $this->count_entities('tool_dynamicrule'),
            'usermappings' => $this->count_entities('user', 'mappings'),
            'cohortmappings' => $this->count_entities('cohort', 'mappings'),
        ];
    }

    /**
     * Index zip entries by section/entity/id.
     */
    protected function build_index(): void {
        $this->index = ['data' => [], 'mappings' => []];
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = $this->zip->getNameIndex($i);
            if (!preg_match('#^(data|mappings)/([^/]+)/(\d+)\.xml$#', $name, $matches)) {
                continue;
            }
            $this->index[$matches[1]][$matches[2]][(int)$matches[3]] = $name;
        }
        foreach (['data', 'mappings'] as $section) {
            foreach ($this->index[$section] as &$files) {
                ksort($files, SORT_NUMERIC);
            }
        }
    }
}
