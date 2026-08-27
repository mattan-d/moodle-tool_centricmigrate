<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

namespace tool_centricmigrate;

defined('MOODLE_INTERNAL') || die();

use local_program\program_constants;
use local_program\program_content;
use tool_centricmigrate\local\program_importer;

/**
 * Tests for Workplace to local_program field mapping.
 *
 * @covers \tool_centricmigrate\local\program_importer
 */
final class program_mapping_test extends \advanced_testcase {

    public function test_map_date_not_set(): void {
        if (!program_importer::is_available()) {
            $this->markTestSkipped('local_program is not installed');
        }
        $mapped = program_importer::map_date(0, 123, '3 day');
        $this->assertSame(program_constants::DATE_NOT_SET, $mapped['type']);
        $this->assertSame(0, $mapped['date']);
    }

    public function test_map_date_absolute(): void {
        if (!program_importer::is_available()) {
            $this->markTestSkipped('local_program is not installed');
        }
        $mapped = program_importer::map_date(1, 1632222660, null);
        $this->assertSame(program_constants::DATE_ABSOLUTE, $mapped['type']);
        $this->assertSame(1632222660, $mapped['date']);
    }

    public function test_map_date_relative(): void {
        if (!program_importer::is_available()) {
            $this->markTestSkipped('local_program is not installed');
        }
        $mapped = program_importer::map_date(2, 0, '3 week');
        $this->assertSame(program_constants::DATE_AFTER_ALLOCATION, $mapped['type']);
        $this->assertSame(3, $mapped['offset']);
        $this->assertSame(program_constants::UNIT_WEEKS, $mapped['unit']);
    }

    public function test_parse_relative_variants(): void {
        if (!program_importer::is_available()) {
            $this->markTestSkipped('local_program is not installed');
        }
        $this->assertSame([0, program_constants::UNIT_HOURS], program_importer::parse_relative('0 hour'));
        $this->assertSame([2, program_constants::UNIT_DAYS], program_importer::parse_relative('2 day'));
        $this->assertSame([1, program_constants::UNIT_MONTHS], program_importer::parse_relative('1 month'));
        $this->assertSame([0, program_constants::UNIT_DAYS], program_importer::parse_relative('$@NULL@$'));
    }

    public function test_map_completion(): void {
        if (!program_importer::is_available()) {
            $this->markTestSkipped('local_program is not installed');
        }
        $any = program_importer::map_completion(0);
        $this->assertSame(program_content::COMPLETION_ALL_ANY, $any['completiontype']);
        $this->assertSame(program_constants::SEQUENCING_PARALLEL, $any['sequencing']);

        $order = program_importer::map_completion(1);
        $this->assertSame(program_content::COMPLETION_ALL_ORDER, $order['completiontype']);
        $this->assertSame(program_constants::SEQUENCING_SEQUENTIAL, $order['sequencing']);

        $least = program_importer::map_completion(2, 3);
        $this->assertSame(program_content::COMPLETION_AT_LEAST, $least['completiontype']);
        $this->assertSame(3, $least['completionvalue']);
        $this->assertSame(program_constants::RULE_COUNT, $least['completionrule']);
    }
}
