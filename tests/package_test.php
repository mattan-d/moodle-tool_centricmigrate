<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

namespace tool_centricmigrate;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for Workplace zip package indexing.
 *
 * @covers \tool_centricmigrate\package
 */
final class package_test extends \advanced_testcase {

    public function test_summarize_fixture(): void {
        $path = $this->make_fixture_zip();
        $package = new package($path);
        $summary = $package->summarize();
        $package->close();

        $this->assertSame('abc123', $summary['siteidentifier']);
        $this->assertSame('tool_wp\\tool_wp\\exporter\\users', $summary['exporter']);
        $this->assertSame(1, $summary['users']);
        $this->assertSame(0, $summary['programs']);

        $package = new package($path);
        $user = $package->read_entity('user', 10);
        $package->close();
        $this->assertSame('jane.doe', $user['username']);
        $this->assertSame(10, $user['_oldid']);
    }

    public function test_extract_content_to_filedir_layout(): void {
        $content = 'workplace-file-blob';
        $hash = sha1($content);
        $path = make_temp_directory('centricmigrate_tests') . '/files.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('workplace.xml', '<workplace>
            <exporter>test</exporter>
            <siteidentifier>abc123</siteidentifier>
        </workplace>');
        $zip->addFromString('files/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash, $content);
        $zip->close();

        $package = new package($path);
        $this->assertTrue($package->has_content($hash));
        $this->assertFalse($package->has_content(str_repeat('0', 40)));

        $target = make_temp_directory('centricmigrate_tests') . '/out/' . $hash;
        $package->extract_content_to($hash, $target);
        $package->close();
        $this->assertSame($content, file_get_contents($target));
    }

    public function test_summarize_user_csv(): void {
        $path = $this->make_fixture_csv();
        $package = new package($path);
        $summary = $package->summarize();

        $this->assertTrue($package->is_csv());
        $this->assertSame(package::FORMAT_CSV, $summary['format']);
        $this->assertSame('csv_users', $summary['exporter']);
        $this->assertSame(2, $summary['users']);
        $this->assertSame(0, $summary['courses']);
        $this->assertStringStartsWith('csv:', $summary['siteidentifier']);

        $user = $package->read_entity('user', 15004);
        $package->close();
        $this->assertSame('3218797778', $user['username']);
        $this->assertSame('h654@gmail.com', $user['email']);
        $this->assertSame('Bar Asher', $user['lastname']);
        $this->assertSame('IL', $user['country']);
        $this->assertSame('054-1234567', $user['phone1']);
        $this->assertSame(15004, $user['_oldid']);
    }

    public function test_csv_requires_username_column(): void {
        $path = make_temp_directory('centricmigrate_tests') . '/bad.csv';
        file_put_contents($path, "id,email\n1,a@b.c\n");
        $this->expectException(\moodle_exception::class);
        new package($path);
    }

    public function test_summarize_category_csv(): void {
        $path = $this->make_fixture_category_csv();
        $package = new package($path);
        $summary = $package->summarize();

        $this->assertTrue($package->is_csv());
        $this->assertSame('csv_categories', $summary['exporter']);
        $this->assertSame(3, $summary['categories']);
        $this->assertSame(0, $summary['users']);

        $ceh = $package->read_entity('coursecategory', 3);
        $package->close();
        $this->assertSame('CEH', $ceh['name']);
        $this->assertSame('0276', $ceh['idnumber']);
        $this->assertSame(['TESI', 'הייטק', 'CEH'], $ceh['segments']);
    }

    public function test_split_category_path_keeps_slash_in_names(): void {
        $this->assertSame(
            ['TESI', 'הייטק', 'UX/UI'],
            package::split_category_path('TESI / הייטק / UX/UI', 'UX/UI')
        );
        $this->assertSame(['בניין', 'בטיחות', 'ממונה בטיחות'], package::split_category_path('בניין / בטיחות  / ממונה בטיחות'));
        $this->assertSame(['Miscellaneous'], package::split_category_path('Miscellaneous', 'Miscellaneous'));
    }

    /**
     * @return string
     */
    protected function make_fixture_category_csv(): string {
        $path = make_temp_directory('centricmigrate_tests') . '/categories.csv';
        $csv = "\"שם הקטגוריה\",\"מספר זיהוי של קטגוריה\",\"Course count\",\"נתיב קטגוריה\"\n";
        $csv .= "TESI,38,13,TESI\n";
        $csv .= "CEH,0276,1,\"TESI / הייטק / CEH\"\n";
        $csv .= "UX/UI,0275,5,\"TESI / הייטק / UX/UI\"\n";
        file_put_contents($path, $csv);
        return $path;
    }

    /**
     * @return string
     */
    protected function make_fixture_csv(): string {
        $path = make_temp_directory('centricmigrate_tests') . '/users.csv';
        $csv = "id,username,email,firstname,lastname,idnumber,institution,department,phone1,phone2,city,country,profile_field_skype,profile_field_yahoo,profile_field_url\n";
        $csv .= "15004,3218797778,h654@gmail.com,Maor,\"Bar Asher\",3218797778,,,054-1234567,,,IL,,,\n";
        $csv .= "16132,345433825,achevlen@gmail.com,Abraham,Chevlen,345433825,,,053-8488282,,,IL,,,\n";
        file_put_contents($path, $csv);
        return $path;
    }

    /**
     * @return string
     */
    protected function make_fixture_zip(): string {
        $path = make_temp_directory('centricmigrate_tests') . '/mini.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('workplace.xml', '<workplace>
            <exporter>tool_wp\\tool_wp\\exporter\\users</exporter>
            <wwwroot>https://example.test</wwwroot>
            <siteidentifier>abc123</siteidentifier>
            <release>4.5.4</release>
            <createdbyname>Tester</createdbyname>
        </workplace>');
        $zip->addFromString('data/user/10.xml', '<user>
            <id>10</id>
            <username>jane.doe</username>
            <email>jane@example.test</email>
            <firstname>Jane</firstname>
            <lastname>Doe</lastname>
        </user>');
        $zip->close();
        return $path;
    }
}
