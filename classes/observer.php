<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Event observers.
 *
 * @package    local_quick_course_completion
 * @copyright  2020 Michael Gardener <mgardener@cissq.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_quick_course_completion;

defined('MOODLE_INTERNAL') || die();

use completion_info;
use completion_criteria_completion;

require_once($CFG->libdir . '/completionlib.php');

/**
 * Class observer
 *
 * @package    local_quick_course_completion
 * @copyright  2020 Michael Gardener <mgardener@cissq.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class observer {

    /**
     * @param \core\event\user_graded $event
     * @return false
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function user_graded(\core\event\user_graded $event) {
        global $DB;

        if (!get_config('local_quick_course_completion', 'enable')) {
            return false;
        }

        $eventdata = $event->get_data();
        $userid = $event->relateduserid;
        $courseid = $event->contextinstanceid;

        if ($event->contextlevel !== CONTEXT_COURSE) {
            return false;
        }

        if (!$DB->record_exists('grade_items', ['id' => $eventdata['other']['itemid'], 'itemtype' => 'course'])) {
            return false;
        }

        $course = $DB->get_record('course', ['id' => $courseid]);

        $completion = new completion_info($course);
        if (!$completion->is_enabled() && $completion->is_course_complete($userid)) {
            return false;
        }

        self::completion_criteria_grade($course->id, $userid);
        self::course_completion($course->id, $userid);

        return true;
    }

    /**
     * @param \core\event\course_module_completion_updated $event
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function course_module_completion_updated(\core\event\course_module_completion_updated $event) {
        global $DB;

        if (!get_config('local_quick_course_completion', 'enable')) {
            return false;
        }

        $eventdata = $event->get_record_snapshot('course_modules_completion', $event->objectid);
        $userid = $event->relateduserid;
        $cmid = $event->contextinstanceid;

        $cm = get_coursemodule_from_id('', $cmid);
        $course = $DB->get_record('course', ['id' => $cm->course]);

        $completion = new completion_info($course);
        if (!$completion->is_enabled() && $completion->is_course_complete($userid)) {
            return false;
        }

        if ($eventdata->completionstate == COMPLETION_COMPLETE
            || $eventdata->completionstate == COMPLETION_COMPLETE_PASS
            || $eventdata->completionstate == COMPLETION_COMPLETE_FAIL) {
            self::criteria_completion_activity($course->id, $userid);
            self::completion_criteria_grade($course->id, $userid);
            self::course_completion($course->id, $userid);
        }
        return true;
    }

    /**
     * @param $courseid
     * @param $userid
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function criteria_completion_activity($courseid, $userid) {
        global $DB;

        $sql = '
            SELECT DISTINCT
                c.id AS course,
                cr.id AS criteriaid,
                ra.userid AS userid,
                mc.timemodified AS timecompleted
            FROM
                {course_completion_criteria} cr
            INNER JOIN
                {course} c
             ON cr.course = c.id
            INNER JOIN
                {context} con
             ON con.instanceid = c.id
            INNER JOIN
                {role_assignments} ra
              ON ra.contextid = con.id
            INNER JOIN
                {course_modules_completion} mc
             ON mc.coursemoduleid = cr.moduleinstance
            AND mc.userid = ra.userid
            LEFT JOIN
                {course_completion_crit_compl} cc
             ON cc.criteriaid = cr.id
            AND cc.userid = ra.userid
            WHERE
                cr.criteriatype = '.COMPLETION_CRITERIA_TYPE_ACTIVITY.'
            AND con.contextlevel = '.CONTEXT_COURSE.'
            AND c.enablecompletion = 1
            AND cc.id IS NULL
            AND (
                mc.completionstate = '.COMPLETION_COMPLETE.'
             OR mc.completionstate = '.COMPLETION_COMPLETE_PASS.'
             OR mc.completionstate = '.COMPLETION_COMPLETE_FAIL.'
                )
             AND c.id = ?   
             AND ra.userid = ?   
        ';

        // Loop through completions, and mark as complete
        $rs = $DB->get_recordset_sql($sql, [$courseid, $userid]);
        foreach ($rs as $record) {
            $completion = new completion_criteria_completion((array) $record, DATA_OBJECT_FETCH_BY_KEY);
            $completion->mark_complete($record->timecompleted);
        }
        $rs->close();
    }

    /**
     * @param $courseid
     * @param $userid
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function completion_criteria_grade($courseid, $userid) {
        global $DB;

        // Get all users who meet this criteria
        $sql = '
            SELECT DISTINCT
                c.id AS course,
                cr.id AS criteriaid,
                ra.userid AS userid,
                gg.finalgrade AS gradefinal,
                gg.timemodified AS timecompleted
            FROM
                {course_completion_criteria} cr
            INNER JOIN
                {course} c
             ON cr.course = c.id
            INNER JOIN
                {context} con
             ON con.instanceid = c.id
            INNER JOIN
                {role_assignments} ra
              ON ra.contextid = con.id
            INNER JOIN
                {grade_items} gi
             ON gi.courseid = c.id
            AND gi.itemtype = \'course\'
            INNER JOIN
                {grade_grades} gg
             ON gg.itemid = gi.id
            AND gg.userid = ra.userid
            LEFT JOIN
                {course_completion_crit_compl} cc
             ON cc.criteriaid = cr.id
            AND cc.userid = ra.userid
            WHERE
                cr.criteriatype = '.COMPLETION_CRITERIA_TYPE_GRADE.'
            AND con.contextlevel = '.CONTEXT_COURSE.'
            AND c.enablecompletion = 1
            AND cc.id IS NULL
            AND gg.finalgrade >= cr.gradepass
            AND c.id = ?   
            AND ra.userid = ?   
        ';

        // Loop through completions, and mark as complete
        $rs = $DB->get_recordset_sql($sql, [$courseid, $userid]);
        foreach ($rs as $record) {
            $completion = new completion_criteria_completion((array) $record, DATA_OBJECT_FETCH_BY_KEY);
            $completion->mark_complete($record->timecompleted);
        }
        $rs->close();
    }

    /**
     * @param $courseid
     * @param $userid
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function course_completion($courseid, $userid) {
        global $CFG, $DB;

        if ($CFG->enablecompletion) {
            if (!$DB->record_exists('course_completions', ['userid' => $userid, 'course' => $courseid])) {
                $completion = new \completion_completion();
                $completion->userid = $userid;
                $completion->course = $courseid;
                $completion->timeenrolled = 0;
                $completion->timestarted = 0;
                $completion->reaggregate = time();
                $completion->mark_enrolled();
            }

            // Save time started.
            $timestarted = time() + 1; // +1 otherwise It cannot process criteria completions

            // Grab all criteria and their associated criteria completions.
            $sql = 'SELECT DISTINCT c.id AS course, cr.id AS criteriaid, crc.userid AS userid,
                                    cr.criteriatype AS criteriatype, cc.timecompleted AS timecompleted
                      FROM {course_completion_criteria} cr
                INNER JOIN {course} c ON cr.course = c.id
                INNER JOIN {course_completions} crc ON crc.course = c.id
                 LEFT JOIN {course_completion_crit_compl} cc ON cc.criteriaid = cr.id AND crc.userid = cc.userid
                     WHERE c.enablecompletion = 1
                       AND crc.timecompleted IS NULL
                       AND crc.reaggregate > 0
                       AND crc.reaggregate < :timestarted
			           AND c.id = :courseid
					   AND crc.userid = :userid
                  ORDER BY course, userid';
            $rs = $DB->get_recordset_sql($sql, ['timestarted' => $timestarted, 'courseid' => $courseid, 'userid' => $userid]);

            // Check if result is empty.
            if (!$rs->valid()) {
                $rs->close();
                return;
            }

            $currentuser = null;
            $currentcourse = null;
            $completions = [];
            while (1) {
                // Grab records for current user/course.
                foreach ($rs as $record) {
                    // If we are still grabbing the same users completions.
                    if ($record->userid === $currentuser && $record->course === $currentcourse) {
                        $completions[$record->criteriaid] = $record;
                    } else {
                        break;
                    }
                }

                // Aggregate.
                if (!empty($completions)) {
                    // Get course info object.
                    $info = new \completion_info((object)['id' => $currentcourse]);

                    // Setup aggregation.
                    $overall = $info->get_aggregation_method();
                    $activity = $info->get_aggregation_method(COMPLETION_CRITERIA_TYPE_ACTIVITY);
                    $prerequisite = $info->get_aggregation_method(COMPLETION_CRITERIA_TYPE_COURSE);
                    $role = $info->get_aggregation_method(COMPLETION_CRITERIA_TYPE_ROLE);

                    $overallstatus = null;
                    $activitystatus = null;
                    $prerequisitestatus = null;
                    $rolestatus = null;

                    // Get latest timecompleted.
                    $timecompleted = null;

                    // Check each of the criteria.
                    foreach ($completions as $params) {
                        $timecompleted = max($timecompleted, $params->timecompleted);
                        $completion = new \completion_criteria_completion((array)$params, false);

                        // Handle aggregation special cases.
                        if ($params->criteriatype == COMPLETION_CRITERIA_TYPE_ACTIVITY) {
                            completion_cron_aggregate($activity, $completion->is_complete(), $activitystatus);
                        } else if ($params->criteriatype == COMPLETION_CRITERIA_TYPE_COURSE) {
                            completion_cron_aggregate($prerequisite, $completion->is_complete(), $prerequisitestatus);
                        } else if ($params->criteriatype == COMPLETION_CRITERIA_TYPE_ROLE) {
                            completion_cron_aggregate($role, $completion->is_complete(), $rolestatus);
                        } else {
                            completion_cron_aggregate($overall, $completion->is_complete(), $overallstatus);
                        }
                    }

                    // Include role criteria aggregation in overall aggregation.
                    if ($rolestatus !== null) {
                        completion_cron_aggregate($overall, $rolestatus, $overallstatus);
                    }

                    // Include activity criteria aggregation in overall aggregation.
                    if ($activitystatus !== null) {
                        completion_cron_aggregate($overall, $activitystatus, $overallstatus);
                    }

                    // Include prerequisite criteria aggregation in overall aggregation.
                    if ($prerequisitestatus !== null) {
                        completion_cron_aggregate($overall, $prerequisitestatus, $overallstatus);
                    }

                    // If aggregation status is true, mark course complete for user.
                    if ($overallstatus) {
                        $ccompletion = new \completion_completion([
                            'course' => $params->course,
                            'userid' => $params->userid
                        ]);
                        $ccompletion->mark_complete($timecompleted);
                    }
                }

                // If this is the end of the recordset, break the loop.
                if (!$rs->valid()) {
                    $rs->close();
                    break;
                }

                // New/next user, update user details, reset completions.
                $currentuser = $record->userid;
                $currentcourse = $record->course;
                $completions = [];
                $completions[$record->criteriaid] = $record;
            }
        }
    }
}