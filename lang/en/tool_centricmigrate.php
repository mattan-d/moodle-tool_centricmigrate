<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Workplace import';
$string['centricmigrate:import'] = 'Import Moodle Workplace backup files';
$string['privacy:metadata'] = 'The Workplace import tool stores import jobs and id mappings created during migration.';
$string['privacy:metadata:job'] = 'Import jobs started by the user.';
$string['privacy:metadata:job:userid'] = 'The user who started the import.';
$string['privacy:metadata:job:filename'] = 'The uploaded backup file name.';
$string['privacy:metadata:map'] = 'Mappings from Workplace ids to local ids.';
$string['privacy:metadata:map:newid'] = 'The local user id when the mapped entity is a user.';

$string['uploadheading'] = 'Import a Moodle Workplace backup';
$string['uploadintro'] = 'Upload a Workplace migration zip (users, system cohorts, programs, or courses). Programs are imported into the local Programs plugin when it is installed.';
$string['backupfile'] = 'Workplace backup file';
$string['backupfile_help'] = 'A zip file exported from Moodle Workplace (contains workplace.xml). Large program exports should be imported from the command line.';

$string['previewheading'] = 'Review import';
$string['sourceinfo'] = 'Source site';
$string['exporter'] = 'Exporter';
$string['releasedata'] = 'Workplace release';
$string['createdby'] = 'Exported by';
$string['contents'] = 'Package contents';
$string['countusers'] = 'Users';
$string['countcohorts'] = 'Cohorts';
$string['countcohortmembers'] = 'Cohort members';
$string['countcourses'] = 'Courses';
$string['countprograms'] = 'Programs';
$string['countprogramusers'] = 'Program allocations';
$string['countdynamicrules'] = 'Dynamic rules';
$string['skippedunsupported'] = 'Not imported (no equivalent on this site)';

$string['importoptions'] = 'Import options';
$string['importusers'] = 'Import users';
$string['updateusers'] = 'Update existing users';
$string['updateusers_help'] = 'If a user already exists (matched by username or email), update name and email from the backup.';
$string['authfallback'] = 'Authentication method if the original plugin is missing';
$string['importcohorts'] = 'Import cohorts';
$string['importcohortmembers'] = 'Import cohort members';
$string['importcourses'] = 'Restore missing courses from embedded backups';
$string['importcourses_help'] = 'Courses that already exist (matched by id number or short name) are reused, and missing images or files are copied from the zip. New courses are restored from the embedded .mbz. Workplace keeps file binaries in the outer zip, not inside the .mbz. Restoring many large courses can take a long time.';
$string['coursecategory'] = 'Category for restored courses';
$string['importprograms'] = 'Import programs into local Programs';
$string['importprogramusers'] = 'Import program allocations';
$string['enrolprogramusers'] = 'Enrol allocated users into program courses';
$string['localprogrammissing'] = 'The local Programs plugin (local_program) is not installed. Program data in this file will be skipped.';

$string['invalidpackage'] = 'This file is not a Moodle Workplace export (workplace.xml is missing or invalid).';
$string['packagenotfound'] = 'The import package file could not be found. Upload the zip again.';
$string['jobnotfound'] = 'Import job not found.';
$string['confirminport'] = 'Start import';
$string['processing'] = 'Importing...';
$string['progress'] = 'Progress';
$string['importcomplete'] = 'Import finished';
$string['importerror'] = 'Import failed';
$string['continueimport'] = 'Continue';
$string['viewresults'] = 'View results';
$string['newimport'] = 'Import another file';
$string['clihelp'] = 'Recommended for large program backups: php admin/tool/centricmigrate/cli/import.php --file=/path/to/export.zip';

$string['statuscreated'] = 'Created';
$string['statusupdated'] = 'Updated';
$string['statusmapped'] = 'Matched existing';
$string['statusskipped'] = 'Skipped';
$string['statusfailed'] = 'Failed';
$string['loglevel'] = 'Level';
$string['logmessage'] = 'Message';
$string['logentity'] = 'Type';
$string['nologs'] = 'No messages.';
$string['summary'] = 'Summary';

$string['steppreview'] = 'Preview';
$string['stepusers'] = 'Users';
$string['stepcohorts'] = 'Cohorts';
$string['stepmembers'] = 'Cohort members';
$string['stepcourses'] = 'Courses';
$string['stepprograms'] = 'Programs';
$string['stepallocations'] = 'Program allocations';
$string['stepdone'] = 'Done';

$string['error:capability'] = 'You do not have permission to import Workplace backups.';
$string['error:unzip'] = 'Could not open the zip package.';
$string['error:localprogram'] = 'Cannot import programs because local_program is not available.';
$string['error:restorefailed'] = 'Course restore failed: {$a}';
$string['error:usermissing'] = 'User not found (old id {$a}). Import users first or create a matching account.';
$string['error:cohortmissing'] = 'Cohort not found (old id {$a}). Import cohorts first.';
$string['error:coursemissing'] = 'Course not found (old id {$a}). Restore courses or create a matching short name.';
$string['error:programmissing'] = 'Program not found (old id {$a}).';
$string['error:jobfailed'] = '{$a}';

$string['log:usercreated'] = 'Created user {$a->username} (id {$a->newid})';
$string['log:usermapped'] = 'Matched user {$a->username} to existing id {$a->newid}';
$string['log:userupdated'] = 'Updated user {$a->username} (id {$a->newid})';
$string['log:userskipped'] = 'Skipped user {$a}';
$string['log:cohortcreated'] = 'Created cohort {$a->name} (id {$a->newid})';
$string['log:cohortmapped'] = 'Matched cohort {$a->name} to existing id {$a->newid}';
$string['log:memberadded'] = 'Added user {$a->userid} to cohort {$a->cohortid}';
$string['log:memberskipped'] = 'Skipped cohort member {$a}';
$string['log:courserestored'] = 'Restored course {$a->shortname} (id {$a->newid})';
$string['log:coursemapped'] = 'Matched course {$a->shortname} to existing id {$a->newid}';
$string['log:coursefilesrestored'] = 'Copied {$a->count} missing files into course {$a->shortname} (id {$a->newid})';
$string['log:coursefilesmissing'] = '{$a} course file(s) from the backup were not found in the Workplace zip';
$string['log:courseskipped'] = 'Skipped course {$a}';
$string['log:programcreated'] = 'Created program {$a->name} (id {$a->newid})';
$string['log:programmapped'] = 'Matched program {$a->name} to existing id {$a->newid}';
$string['log:allocationcreated'] = 'Allocated user {$a->userid} to program {$a->programid}';
$string['log:allocationskipped'] = 'Skipped allocation {$a}';
$string['log:dynamicruleskipped'] = 'Skipped dynamic rule "{$a}" (not supported)';
$string['log:courseunlinked'] = 'Program course old id {$a} could not be linked';
