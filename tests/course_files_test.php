<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

namespace tool_centricmigrate;

defined('MOODLE_INTERNAL') || die();

use tool_centricmigrate\local\course_importer;

/**
 * Tests for copying Workplace file blobs into course restores.
 *
 * @covers \tool_centricmigrate\local\course_importer
 */
final class course_files_test extends \advanced_testcase {

    public function test_inject_package_files_uses_backup_pool_layout(): void {
        $this->resetAfterTest();

        $content = 'png-bytes-from-workplace';
        $hash = sha1($content);
        $empty = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $packagepath = $this->make_workplace_zip([$hash => $content]);
        $importer = $this->make_importer($packagepath);

        $tempdir = make_temp_directory('centricmigrate_tests/backup');
        file_put_contents($tempdir . '/files.xml', '<?xml version="1.0" encoding="UTF-8"?>
<files>
  <file id="1">
    <contenthash>' . $hash . '</contenthash>
    <filename>מנדל צה״ל-04.png</filename>
  </file>
  <file id="2">
    <contenthash>' . $empty . '</contenthash>
    <filename>.</filename>
  </file>
</files>');

        $copied = $importer->inject_package_files($tempdir, 2);
        $this->assertSame(1, $copied);

        $pooldest = $tempdir . '/files/' . substr($hash, 0, 2) . '/' . $hash;
        $this->assertFileExists($pooldest);
        $this->assertSame($content, file_get_contents($pooldest));
        $this->assertFileDoesNotExist($tempdir . '/files/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash);

        $this->assertSame(0, $importer->inject_package_files($tempdir, 2));
    }

    public function test_copy_backup_files_into_existing_label(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $content = 'label-intro-image';
        $hash = sha1($content);
        $filename = 'מנדל צה״ל-04.png';
        $packagelabel = 'מה צריך אדם';

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $label = $generator->create_module('label', [
            'course' => $course->id,
            'name' => $packagelabel,
            'intro' => '<p>image</p>',
        ]);

        $packagepath = $this->make_workplace_zip([$hash => $content]);
        $importer = $this->make_importer($packagepath);

        $tempdir = make_temp_directory('centricmigrate_tests/backup2');
        check_dir_exists($tempdir . '/activities/label_277', true, true);
        file_put_contents($tempdir . '/activities/label_277/label.xml', '<?xml version="1.0" encoding="UTF-8"?>
<activity id="40" moduleid="277" modulename="label" contextid="645">
  <label id="40">
    <name>' . $packagelabel . '</name>
    <intro>&lt;p&gt;image&lt;/p&gt;</intro>
  </label>
</activity>');
        file_put_contents($tempdir . '/files.xml', '<?xml version="1.0" encoding="UTF-8"?>
<files>
  <file id="9738">
    <contenthash>' . $hash . '</contenthash>
    <contextid>645</contextid>
    <component>mod_label</component>
    <filearea>intro</filearea>
    <itemid>0</itemid>
    <filepath>/</filepath>
    <filename>' . $filename . '</filename>
    <userid>31</userid>
    <filesize>' . strlen($content) . '</filesize>
    <mimetype>image/png</mimetype>
    <status>0</status>
    <timecreated>1764571432</timecreated>
    <timemodified>1764571432</timemodified>
    <source>' . $filename . '</source>
    <author>$@NULL@$</author>
    <license>unknown</license>
    <sortorder>0</sortorder>
  </file>
</files>');

        $this->assertSame(1, $importer->inject_package_files($tempdir, 2));
        $created = $importer->copy_backup_files_into_course($course, $tempdir);
        $this->assertSame(1, $created);

        $ctx = \context_module::instance($label->cmid);
        $fs = get_file_storage();
        $file = $fs->get_file($ctx->id, 'mod_label', 'intro', 0, '/', $filename);
        $this->assertNotFalse($file);
        $this->assertSame($content, $file->get_content());

        $this->assertSame(0, $importer->copy_backup_files_into_course($course, $tempdir));
    }

    /**
     * @param array $blobs hash => content
     * @return string
     */
    protected function make_workplace_zip(array $blobs): string {
        $path = make_temp_directory('centricmigrate_tests') . '/wp-' . random_int(1000, 9999) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('workplace.xml', '<workplace>
            <exporter>test</exporter>
            <siteidentifier>filesite</siteidentifier>
        </workplace>');
        foreach ($blobs as $hash => $content) {
            $zip->addFromString('files/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash, $content);
        }
        $zip->close();
        return $path;
    }

    /**
     * @param string $packagepath
     * @return course_importer
     */
    protected function make_importer(string $packagepath): course_importer {
        global $USER;
        $job = job::create([
            'userid' => $USER->id,
            'filename' => basename($packagepath),
            'sourcepath' => $packagepath,
            'siteidentifier' => 'filesite',
            'exporter' => 'test',
            'options' => ['importcourses' => 1],
        ]);
        return new course_importer(new package($packagepath), new mapping('filesite'), $job, ['importcourses' => 1]);
    }
}
