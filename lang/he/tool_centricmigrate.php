<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'ייבוא Workplace';
$string['centricmigrate:import'] = 'ייבוא קבצי גיבוי של Moodle Workplace';
$string['privacy:metadata'] = 'כלי הייבוא שומר משימות ייבוא ומיפויי מזהים שנוצרים במהלך ההעברה.';
$string['privacy:metadata:job'] = 'משימות ייבוא שהמשתמש התחיל.';
$string['privacy:metadata:job:userid'] = 'המשתמש שהתחיל את הייבוא.';
$string['privacy:metadata:job:filename'] = 'שם קובץ הגיבוי שהועלה.';
$string['privacy:metadata:map'] = 'מיפוי מזהים מ-Workplace למזהים מקומיים.';
$string['privacy:metadata:map:newid'] = 'מזהה המשתמש המקומי כאשר הישות הממופה היא משתמש.';

$string['uploadheading'] = 'ייבוא גיבוי Moodle Workplace';
$string['uploadintro'] = 'העלו קובץ zip של מיגרציית Workplace (משתמשים, קבוצות מערכתיות, תוכניות או קורסים). תוכניות מיובאות לתוסף התוכניות המקומי כשהוא מותקן.';
$string['backupfile'] = 'קובץ גיבוי Workplace';
$string['backupfile_help'] = 'קובץ zip שיוצא מ-Moodle Workplace (מכיל workplace.xml). ייצוא תוכניות גדול מומלץ לייבא משורת הפקודה.';

$string['previewheading'] = 'סקירת הייבוא';
$string['sourceinfo'] = 'אתר המקור';
$string['exporter'] = 'מייצא';
$string['releasedata'] = 'גרסת Workplace';
$string['createdby'] = 'יוצא על ידי';
$string['contents'] = 'תוכן החבילה';
$string['countusers'] = 'משתמשים';
$string['countcohorts'] = 'קבוצות מערכתיות';
$string['countcohortmembers'] = 'חברי קבוצה';
$string['countcourses'] = 'קורסים';
$string['countprograms'] = 'תוכניות';
$string['countprogramusers'] = 'שיוכים לתוכניות';
$string['countdynamicrules'] = 'כללים דינמיים';
$string['skippedunsupported'] = 'לא מיובא (אין מקבילה באתר זה)';

$string['importoptions'] = 'אפשרויות ייבוא';
$string['importusers'] = 'ייבוא משתמשים';
$string['updateusers'] = 'עדכון משתמשים קיימים';
$string['updateusers_help'] = 'אם המשתמש כבר קיים (לפי שם משתמש או דוא״ל), יעודכנו השם והדוא״ל מהגיבוי.';
$string['authfallback'] = 'שיטת אימות אם התוסף המקורי חסר';
$string['importcohorts'] = 'ייבוא קבוצות מערכתיות';
$string['importcohortmembers'] = 'ייבוא חברי קבוצה';
$string['importcourses'] = 'שחזור קורסים חסרים מגיבויים משובצים';
$string['importcourses_help'] = 'קורסים שכבר קיימים (לפי מספר מזהה או שם מקוצר) ישויכו מחדש, וקבצים או תמונות חסרים יועתקו מה-zip. קורסים חדשים ישוחזרו מקבצי .mbz המשובצים. Workplace שומר את הקבצים ב-zip החיצוני, לא בתוך ה-.mbz. שחזור קורסים גדולים עלול לקחת זמן רב.';
$string['coursecategory'] = 'קטגוריה לקורסים משוחזרים';
$string['importprograms'] = 'ייבוא תוכניות לתוסף התוכניות המקומי';
$string['importprogramusers'] = 'ייבוא שיוכים לתוכניות';
$string['enrolprogramusers'] = 'רישום משתמשים משובצים לקורסי התוכנית';
$string['localprogrammissing'] = 'תוסף התוכניות המקומי (local_program) אינו מותקן. נתוני תוכניות בקובץ זה ידולגו.';

$string['invalidpackage'] = 'הקובץ אינו ייצוא של Moodle Workplace (workplace.xml חסר או לא תקין).';
$string['packagenotfound'] = 'קובץ חבילת הייבוא לא נמצא. העלו את ה-zip שוב.';
$string['jobnotfound'] = 'משימת הייבוא לא נמצאה.';
$string['confirminport'] = 'התחלת ייבוא';
$string['processing'] = 'מייבא...';
$string['progress'] = 'התקדמות';
$string['importcomplete'] = 'הייבוא הסתיים';
$string['importerror'] = 'הייבוא נכשל';
$string['continueimport'] = 'המשך';
$string['viewresults'] = 'הצגת תוצאות';
$string['newimport'] = 'ייבוא קובץ נוסף';
$string['clihelp'] = 'מומלץ לגיבויי תוכניות גדולים: php admin/tool/centricmigrate/cli/import.php --file=/path/to/export.zip';

$string['statuscreated'] = 'נוצר';
$string['statusupdated'] = 'עודכן';
$string['statusmapped'] = 'שויך לקיים';
$string['statusskipped'] = 'דולג';
$string['statusfailed'] = 'נכשל';
$string['loglevel'] = 'רמה';
$string['logmessage'] = 'הודעה';
$string['logentity'] = 'סוג';
$string['nologs'] = 'אין הודעות.';
$string['summary'] = 'סיכום';

$string['steppreview'] = 'סקירה';
$string['stepusers'] = 'משתמשים';
$string['stepcohorts'] = 'קבוצות';
$string['stepmembers'] = 'חברי קבוצה';
$string['stepcourses'] = 'קורסים';
$string['stepprograms'] = 'תוכניות';
$string['stepallocations'] = 'שיוכים';
$string['stepdone'] = 'הושלם';

$string['error:capability'] = 'אין לך הרשאה לייבא גיבויי Workplace.';
$string['error:unzip'] = 'לא ניתן לפתוח את קובץ ה-zip.';
$string['error:localprogram'] = 'לא ניתן לייבא תוכניות כי local_program אינו זמין.';
$string['error:restorefailed'] = 'שחזור הקורס נכשל: {$a}';
$string['error:usermissing'] = 'המשתמש לא נמצא (מזהה ישן {$a}). ייבאו משתמשים תחילה או צרו חשבון תואם.';
$string['error:cohortmissing'] = 'הקבוצה לא נמצאה (מזהה ישן {$a}). ייבאו קבוצות תחילה.';
$string['error:coursemissing'] = 'הקורס לא נמצא (מזהה ישן {$a}). שחזרו קורסים או צרו שם מקוצר תואם.';
$string['error:programmissing'] = 'התוכנית לא נמצאה (מזהה ישן {$a}).';
$string['error:jobfailed'] = '{$a}';

$string['log:usercreated'] = 'נוצר משתמש {$a->username} (מזהה {$a->newid})';
$string['log:usermapped'] = 'המשתמש {$a->username} שויך למזהה קיים {$a->newid}';
$string['log:userupdated'] = 'עודכן משתמש {$a->username} (מזהה {$a->newid})';
$string['log:userskipped'] = 'דולג משתמש {$a}';
$string['log:cohortcreated'] = 'נוצרה קבוצה {$a->name} (מזהה {$a->newid})';
$string['log:cohortmapped'] = 'הקבוצה {$a->name} שויכה למזהה קיים {$a->newid}';
$string['log:memberadded'] = 'נוסף משתמש {$a->userid} לקבוצה {$a->cohortid}';
$string['log:memberskipped'] = 'דולג חבר קבוצה {$a}';
$string['log:courserestored'] = 'שוחזר קורס {$a->shortname} (מזהה {$a->newid})';
$string['log:coursemapped'] = 'הקורס {$a->shortname} שויך למזהה קיים {$a->newid}';
$string['log:coursefilesrestored'] = 'הועתקו {$a->count} קבצים חסרים לקורס {$a->shortname} (מזהה {$a->newid})';
$string['log:coursefilesmissing'] = '{$a} קבצים מהגיבוי לא נמצאו ב-zip של Workplace';
$string['log:courseskipped'] = 'דולג קורס {$a}';
$string['log:programcreated'] = 'נוצרה תוכנית {$a->name} (מזהה {$a->newid})';
$string['log:programmapped'] = 'התוכנית {$a->name} שויכה למזהה קיים {$a->newid}';
$string['log:allocationcreated'] = 'שויך משתמש {$a->userid} לתוכנית {$a->programid}';
$string['log:allocationskipped'] = 'דולג שיוך {$a}';
$string['log:dynamicruleskipped'] = 'דולג כלל דינמי "{$a}" (לא נתמך)';
$string['log:courseunlinked'] = 'לא ניתן לקשר קורס בתוכנית (מזהה ישן {$a})';
