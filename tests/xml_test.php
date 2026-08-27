<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

namespace tool_centricmigrate;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for Workplace XML helpers.
 *
 * @covers \tool_centricmigrate\xml
 */
final class xml_test extends \advanced_testcase {

    public function test_null_token_and_scalars(): void {
        $xml = '<user><id>10</id><username>jane</username><moodlenetprofile>$@NULL@$</moodlenetprofile></user>';
        $data = xml::to_array($xml);
        $this->assertSame('10', $data['id']);
        $this->assertSame('jane', $data['username']);
        $this->assertNull($data['moodlenetprofile']);
        $this->assertSame(10, xml::int($data['id']));
        $this->assertSame(0, xml::int($data['moodlenetprofile']));
    }

    public function test_repeating_children(): void {
        $xml = '<tool_program>
            <tool_program_sets>
                <tool_program_set><id>1</id><parent>0</parent></tool_program_set>
                <tool_program_set><id>2</id><parent>1</parent></tool_program_set>
            </tool_program_sets>
        </tool_program>';
        $data = xml::to_array($xml);
        $items = xml::items($data, 'tool_program_sets', 'tool_program_set');
        $this->assertCount(2, $items);
        $this->assertSame('1', $items[0]['id']);
        $this->assertSame('2', $items[1]['id']);
    }

    public function test_files_list(): void {
        $xml = '<course>
            <_files>
                <_file><filename>backup.mbz</filename><contenthash>abc</contenthash></_file>
            </_files>
        </course>';
        $data = xml::to_array($xml);
        $files = xml::files($data);
        $this->assertCount(1, $files);
        $this->assertSame('backup.mbz', $files[0]['filename']);
    }
}
