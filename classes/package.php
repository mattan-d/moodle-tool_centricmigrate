<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

namespace tool_centricmigrate;

defined('MOODLE_INTERNAL') || die();

/**
 * Moodle Workplace export package reader.
 */
class package implements \IteratorAggregate {

    public const FORMAT_ZIP = 'zip';
    public const FORMAT_CSV = 'csv';

    /** Moodle user-table columns accepted from a user CSV. */
    public const CSV_USER_FIELDS = [
        'username', 'password', 'email', 'firstname', 'lastname', 'idnumber',
        'institution', 'department', 'phone1', 'phone2', 'city', 'country',
        'address', 'lang', 'timezone', 'auth', 'suspended', 'confirmed',
        'middlename', 'alternatename', 'firstnamephonetic', 'lastnamephonetic',
        'description', 'maildisplay', 'mailformat', 'maildigest', 'autosubscribe',
        'trackforums', 'emailstop', 'calendartype',
    ];

    /** @var string */
    protected $filepath;

    /** @var \ZipArchive|null */
    protected $zip = null;

    /** @var string */
    protected $format = self::FORMAT_ZIP;

    /** @var array|null */
    protected $manifest = null;

    /** @var array */
    protected $index = [];

    /** @var array entity => oldid => record */
    protected $csvrecords = [];

    /**
     * @param string $filepath Absolute path to a Workplace zip or user CSV.
     */
    public function __construct(string $filepath) {
        $this->filepath = $filepath;
        if (!is_readable($filepath)) {
            throw new \moodle_exception('error:unzip', 'tool_centricmigrate');
        }
        if (self::file_is_zip($filepath)) {
            $this->format = self::FORMAT_ZIP;
            $this->zip = new \ZipArchive();
            if ($this->zip->open($filepath) !== true) {
                throw new \moodle_exception('error:unzip', 'tool_centricmigrate');
            }
            $this->build_index();
            return;
        }
        $this->format = self::FORMAT_CSV;
        $this->load_csv();
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
            $this->zip = null;
        }
    }

    /**
     * @return string zip|csv
     */
    public function get_format(): string {
        return $this->format;
    }

    /**
     * @return bool
     */
    public function is_csv(): bool {
        return $this->format === self::FORMAT_CSV;
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
        if ($this->format === self::FORMAT_CSV) {
            $this->manifest = [
                'exporter' => 'csv_users',
                'wwwroot' => '',
                'siteidentifier' => 'csv:' . substr(sha1_file($this->filepath), 0, 32),
                'release' => '',
                'createdbyname' => '',
            ];
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
        if ($this->format === self::FORMAT_CSV) {
            $record = $this->csvrecords[$section][$entity][$oldid] ?? null;
            return $record;
        }
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
        if (!$this->zip instanceof \ZipArchive) {
            return null;
        }
        $contents = $this->zip->getFromName($path);
        if ($contents === false) {
            return null;
        }
        return $contents;
    }

    /**
     * Workplace file-pool path inside the zip (Moodle filedir layout).
     *
     * @param string $contenthash
     * @return string
     */
    public function content_zip_path(string $contenthash): string {
        return 'files/' . substr($contenthash, 0, 2) . '/' . substr($contenthash, 2, 2) . '/' . $contenthash;
    }

    /**
     * Whether the zip contains a hashed content blob.
     *
     * @param string $contenthash
     * @return bool
     */
    public function has_content(string $contenthash): bool {
        if ($contenthash === '' || !$this->zip instanceof \ZipArchive) {
            return false;
        }
        return $this->zip->locateName($this->content_zip_path($contenthash)) !== false;
    }

    /**
     * Extract a hashed content file to a temp path.
     *
     * @param string $contenthash
     * @return string Absolute path
     */
    public function extract_content(string $contenthash): string {
        $dir = make_temp_directory('centricmigrate/files');
        $target = $dir . '/' . $contenthash;
        if (file_exists($target) && filesize($target) > 0) {
            return $target;
        }
        $this->extract_content_to($contenthash, $target);
        return $target;
    }

    /**
     * Extract a hashed content file to an explicit destination path.
     *
     * @param string $contenthash
     * @param string $target Absolute path
     */
    public function extract_content_to(string $contenthash, string $target): void {
        $stream = $this->zip->getStream($this->content_zip_path($contenthash));
        if ($stream === false) {
            throw new \moodle_exception('error:unzip', 'tool_centricmigrate');
        }
        $dir = dirname($target);
        if (!is_dir($dir) && !check_dir_exists($dir, true, true)) {
            fclose($stream);
            throw new \moodle_exception('error:unzip', 'tool_centricmigrate');
        }
        $out = fopen($target, 'wb');
        if ($out === false) {
            fclose($stream);
            throw new \moodle_exception('error:unzip', 'tool_centricmigrate');
        }
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);
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
            'format' => $this->format,
        ];
    }

    /**
     * Whether the file starts with a ZIP local-file header.
     *
     * @param string $filepath
     * @return bool
     */
    public static function file_is_zip(string $filepath): bool {
        $handle = fopen($filepath, 'rb');
        if ($handle === false) {
            return false;
        }
        $magic = fread($handle, 4);
        fclose($handle);
        return is_string($magic) && str_starts_with($magic, 'PK');
    }

    /**
     * Load a Moodle user-export CSV (id, username, email, firstname, ...).
     */
    protected function load_csv(): void {
        $handle = fopen($this->filepath, 'rb');
        if ($handle === false) {
            throw new \moodle_exception('error:invalidcsv', 'tool_centricmigrate');
        }

        $first = fgets($handle);
        if ($first === false) {
            fclose($handle);
            throw new \moodle_exception('error:invalidcsv', 'tool_centricmigrate');
        }
        if (str_starts_with($first, "\xEF\xBB\xBF")) {
            $first = substr($first, 3);
        }
        $delimiter = self::detect_csv_delimiter($first);
        $header = str_getcsv($first, $delimiter);
        $header = array_map(static function ($name) {
            return strtolower(trim((string)$name));
        }, $header);

        $usernamecol = array_search('username', $header, true);
        if ($usernamecol === false) {
            fclose($handle);
            throw new \moodle_exception('error:invalidcsv', 'tool_centricmigrate');
        }

        $this->index = ['data' => ['user' => []], 'mappings' => []];
        $this->csvrecords = ['data' => ['user' => []], 'mappings' => []];
        $linenumber = 1;
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $linenumber++;
            if ($row === [null] || $row === false) {
                continue;
            }
            if ($this->csv_row_empty($row)) {
                continue;
            }
            $assoc = [];
            foreach ($header as $i => $name) {
                if ($name === '') {
                    continue;
                }
                $assoc[$name] = isset($row[$i]) ? trim((string)$row[$i]) : '';
            }
            $username = \core_text::strtolower(trim($assoc['username'] ?? ''));
            if ($username === '') {
                continue;
            }
            $assoc['username'] = $username;
            $oldid = (int)($assoc['id'] ?? 0);
            if ($oldid < 1) {
                $oldid = 1000000 + $linenumber;
            }
            if (isset($this->csvrecords['data']['user'][$oldid])) {
                continue;
            }
            $assoc['_oldid'] = $oldid;
            $this->csvrecords['data']['user'][$oldid] = $assoc;
            $this->index['data']['user'][$oldid] = 'csv:user:' . $oldid;
        }
        fclose($handle);

        if (empty($this->index['data']['user'])) {
            throw new \moodle_exception('error:invalidcsv', 'tool_centricmigrate');
        }
        ksort($this->index['data']['user'], SORT_NUMERIC);
    }

    /**
     * @param string $headerline
     * @return string
     */
    protected static function detect_csv_delimiter(string $headerline): string {
        $counts = [
            ',' => substr_count($headerline, ','),
            ';' => substr_count($headerline, ';'),
            "\t" => substr_count($headerline, "\t"),
        ];
        arsort($counts);
        $delimiter = array_key_first($counts);
        return $counts[$delimiter] > 0 ? $delimiter : ',';
    }

    /**
     * @param array $row
     * @return bool
     */
    protected function csv_row_empty(array $row): bool {
        foreach ($row as $value) {
            if (trim((string)$value) !== '') {
                return false;
            }
        }
        return true;
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
