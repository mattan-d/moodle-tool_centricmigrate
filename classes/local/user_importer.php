<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

namespace tool_centricmigrate\local;

defined('MOODLE_INTERNAL') || die();

use tool_centricmigrate\job;
use tool_centricmigrate\mapping;
use tool_centricmigrate\package;
use tool_centricmigrate\xml;

global $CFG;
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/authlib.php');

/**
 * Import Workplace users into Moodle.
 */
class user_importer {

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
     * Import or map every user in the package, including mapping-only stubs.
     */
    public function import_all(): void {
        foreach ($this->package->iterate_entities('user') as $oldid => $data) {
            $this->import_user($oldid, $data);
        }
        foreach ($this->package->iterate_entities('user', 'mappings') as $oldid => $data) {
            if ($this->mapping->get('user', $oldid)) {
                continue;
            }
            $this->map_existing_user($oldid, $data, true);
        }
    }

    /**
     * @param int $oldid
     * @param array $data
     */
    public function import_user(int $oldid, array $data): void {
        global $DB, $CFG;

        $username = \core_text::strtolower(trim((string)xml::value($data['username'] ?? '')));
        if ($username === '' || $username === 'guest' || !empty($data['deleted'])) {
            $this->skip($oldid, $username ?: (string)$oldid);
            return;
        }

        $existing = $this->find_existing($oldid, $data);
        if ($existing) {
            if (!empty($this->options['updateusers']) && !is_siteadmin($existing) && $existing->id != $CFG->siteguest) {
                $this->update_user($existing, $data);
                $this->job->bump_count('user', 'updated');
                $this->job->log('info', get_string('log:userupdated', 'tool_centricmigrate', [
                    'username' => $existing->username,
                    'newid' => $existing->id,
                ]), 'user', $oldid);
            } else {
                $this->job->bump_count('user', 'mapped');
                $this->job->log('info', get_string('log:usermapped', 'tool_centricmigrate', [
                    'username' => $existing->username,
                    'newid' => $existing->id,
                ]), 'user', $oldid);
            }
            $this->mapping->set('user', $oldid, (int)$existing->id);
            $this->import_user_files((int)$existing->id, $data);
            return;
        }

        $auth = $this->resolve_auth((string)xml::value($data['auth'] ?? 'manual'));
        $user = (object)[
            'auth' => $auth,
            'confirmed' => xml::int($data['confirmed'] ?? 1, 1),
            'policyagreed' => 0,
            'deleted' => 0,
            'suspended' => xml::int($data['suspended'] ?? 0),
            'mnethostid' => $CFG->mnet_localhost_id,
            'username' => $username,
            'password' => generate_password(12) . 'Aa1!',
            'firstname' => (string)xml::value($data['firstname'] ?? ''),
            'lastname' => (string)xml::value($data['lastname'] ?? ''),
            'email' => (string)xml::value($data['email'] ?? ''),
            'emailstop' => xml::int($data['emailstop'] ?? 0),
            'lang' => $this->resolve_lang((string)xml::value($data['lang'] ?? '')),
            'calendartype' => (string)(xml::value($data['calendartype'] ?? '') ?: 'gregorian'),
            'timezone' => (string)(xml::value($data['timezone'] ?? '99') ?: '99'),
            'mailformat' => xml::int($data['mailformat'] ?? 1, 1),
            'maildigest' => xml::int($data['maildigest'] ?? 0),
            'maildisplay' => xml::int($data['maildisplay'] ?? 1, 1),
            'autosubscribe' => xml::int($data['autosubscribe'] ?? 1, 1),
            'trackforums' => xml::int($data['trackforums'] ?? 0),
            'description' => (string)xml::value($data['description'] ?? ''),
            'descriptionformat' => xml::int($data['descriptionformat'] ?? FORMAT_HTML, FORMAT_HTML),
            'imagealt' => (string)xml::value($data['imagealt'] ?? ''),
            'lastnamephonetic' => (string)xml::value($data['lastnamephonetic'] ?? ''),
            'firstnamephonetic' => (string)xml::value($data['firstnamephonetic'] ?? ''),
            'middlename' => (string)xml::value($data['middlename'] ?? ''),
            'alternatename' => (string)xml::value($data['alternatename'] ?? ''),
        ];

        if ($user->firstname === '') {
            $user->firstname = $username;
        }
        if ($user->lastname === '') {
            $user->lastname = $username;
        }
        if ($user->email === '' || !validate_email($user->email)) {
            $user->email = 'imported+' . $oldid . '@example.invalid';
        }

        try {
            $newid = user_create_user($user, true, false);
        } catch (\Throwable $e) {
            $this->job->bump_count('user', 'failed');
            $this->job->log('error', $e->getMessage(), 'user', $oldid);
            return;
        }

        $this->mapping->set('user', $oldid, (int)$newid);
        $this->import_user_files((int)$newid, $data);
        $this->job->bump_count('user', 'created');
        $this->job->log('info', get_string('log:usercreated', 'tool_centricmigrate', [
            'username' => $username,
            'newid' => $newid,
        ]), 'user', $oldid);
    }

    /**
     * @param int $oldid
     * @param array $data
     * @param bool $logskip
     * @return \stdClass|null
     */
    public function map_existing_user(int $oldid, array $data, bool $logskip = false): ?\stdClass {
        $existing = $this->find_existing($oldid, $data);
        if (!$existing) {
            if ($logskip) {
                $this->skip($oldid, (string)xml::value($data['username'] ?? $oldid));
            }
            return null;
        }
        $this->mapping->set('user', $oldid, (int)$existing->id);
        $this->job->bump_count('user', 'mapped');
        $this->job->log('info', get_string('log:usermapped', 'tool_centricmigrate', [
            'username' => $existing->username,
            'newid' => $existing->id,
        ]), 'user', $oldid);
        return $existing;
    }

    /**
     * @param int $oldid
     * @param array $data
     * @return \stdClass|null
     */
    protected function find_existing(int $oldid, array $data): ?\stdClass {
        global $DB, $CFG;

        $mapped = $this->mapping->get('user', $oldid);
        if ($mapped) {
            $user = $DB->get_record('user', ['id' => $mapped, 'deleted' => 0]);
            if ($user) {
                return $user;
            }
        }

        $username = \core_text::strtolower(trim((string)xml::value($data['username'] ?? '')));
        if ($username !== '') {
            $user = $DB->get_record('user', [
                'username' => $username,
                'mnethostid' => $CFG->mnet_localhost_id,
                'deleted' => 0,
            ]);
            if ($user) {
                return $user;
            }
        }

        $email = trim((string)xml::value($data['email'] ?? ''));
        if ($email !== '' && validate_email($email)) {
            $users = $DB->get_records('user', ['email' => $email, 'deleted' => 0], 'id ASC', '*', 0, 2);
            if (count($users) === 1) {
                return reset($users);
            }
        }

        return null;
    }

    /**
     * @param \stdClass $user
     * @param array $data
     */
    protected function update_user(\stdClass $user, array $data): void {
        $user->firstname = (string)xml::value($data['firstname'] ?? $user->firstname);
        $user->lastname = (string)xml::value($data['lastname'] ?? $user->lastname);
        $email = (string)xml::value($data['email'] ?? '');
        if ($email !== '' && validate_email($email)) {
            $user->email = $email;
        }
        $user->suspended = xml::int($data['suspended'] ?? $user->suspended);
        user_update_user($user, false, false);
    }

    /**
     * @param int $userid
     * @param array $data
     */
    protected function import_user_files(int $userid, array $data): void {
        $files = xml::files($data);
        if (empty($files)) {
            return;
        }

        $fs = get_file_storage();
        $context = \context_user::instance($userid);
        $importedicon = false;

        foreach ($files as $file) {
            $hash = (string)xml::value($file['contenthash'] ?? '');
            $filename = (string)xml::value($file['filename'] ?? '');
            $component = (string)xml::value($file['component'] ?? '');
            $filearea = (string)xml::value($file['filearea'] ?? '');
            if ($hash === '' || $filename === '' || $component !== 'user' || $filearea !== 'icon') {
                continue;
            }
            try {
                $source = $this->package->extract_content($hash);
            } catch (\Throwable $e) {
                continue;
            }
            if ($fs->file_exists($context->id, 'user', 'icon', 0, '/', $filename)) {
                $existing = $fs->get_file($context->id, 'user', 'icon', 0, '/', $filename);
                $existing->delete();
            }
            $fs->create_file_from_pathname([
                'contextid' => $context->id,
                'component' => 'user',
                'filearea' => 'icon',
                'itemid' => 0,
                'filepath' => '/',
                'filename' => $filename,
            ], $source);
            $importedicon = true;
        }

        if ($importedicon) {
            global $DB;
            $DB->set_field('user', 'picture', 1, ['id' => $userid]);
        }
    }

    /**
     * @param string $auth
     * @return string
     */
    protected function resolve_auth(string $auth): string {
        $auth = $auth ?: 'manual';
        if (exists_auth_plugin($auth)) {
            return $auth;
        }
        $fallback = $this->options['authfallback'] ?? 'manual';
        if (exists_auth_plugin($fallback)) {
            return $fallback;
        }
        return 'manual';
    }

    /**
     * @param string $lang
     * @return string
     */
    protected function resolve_lang(string $lang): string {
        global $CFG;
        if ($lang === '') {
            return $CFG->lang ?? 'en';
        }
        if (get_string_manager()->translation_exists($lang, false)) {
            return $lang;
        }
        $base = preg_replace('/_.*$/', '', $lang);
        if ($base && get_string_manager()->translation_exists($base, false)) {
            return $base;
        }
        return $CFG->lang ?? 'en';
    }

    /**
     * @param int $oldid
     * @param string $label
     */
    protected function skip(int $oldid, string $label): void {
        $this->job->bump_count('user', 'skipped');
        $this->job->log('info', get_string('log:userskipped', 'tool_centricmigrate', $label), 'user', $oldid);
    }
}
