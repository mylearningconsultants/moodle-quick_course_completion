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
 * Tests for the \local_quick_course_completion\observer class.
 *
 * @package    local_quick_course_completion
 * @copyright  2020 Michael Gardener <mgardener@cissq.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir.'/completionlib.php');
require_once($CFG->dirroot . '/completion/criteria/completion_criteria.php');
require_once($CFG->dirroot . '/completion/criteria/completion_criteria_activity.php');
require_once($CFG->dirroot . '/completion/criteria/completion_criteria_role.php');

/**
 * Tests for the \local_quick_course_completion\observer class.
 *
 * @package    local_quick_course_completion
 * @copyright  2020 Michael Gardener <mgardener@cissq.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_quick_course_completion_observer_test extends advanced_testcase {

    /**
     * Create completion information.
     */
    public function setup_data() {
        global $DB, $CFG;

        $this->resetAfterTest();

        set_config('enable', 1, 'local_quick_course_completion');

        // Enable completion before creating modules, otherwise the completion data is not written in DB.
        $CFG->enablecompletion = true;

        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->user->id, $this->course->id);

        $this->coursecontext = context_course::instance($this->course->id);

        $this->module = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $this->cm = get_coursemodule_from_id('assign', $this->module->cmid);

        // Set completion rules.
        $completion = new \completion_info($this->course);

        $criteriadata = (object) [
            'id' => $this->course->id,
            'criteria_activity' => [
                $this->cm->id => 1
            ]
        ];
        $criterion = new \completion_criteria_activity();
        $criterion->update_config($criteriadata);

        // Handle overall aggregation.
        $aggdata = array(
            'course'        => $this->course->id,
            'criteriatype'  => COMPLETION_CRITERIA_TYPE_ACTIVITY
        );
        $aggregation = new \completion_aggregation($aggdata);
        $aggregation->setMethod(COMPLETION_AGGREGATION_ALL);
        $aggregation->save();
    }

    /**
     * Test course module completion update event.
     */
    public function test_course_module_completion_updated_event() {
        $this->setup_data();

        $this->setAdminUser();

        $completioninfo = new completion_info($this->course);
        $activities = $completioninfo->get_activities();
        $this->assertEquals(1, count($activities));
        $this->assertTrue(isset($activities[$this->module->cmid]));
        $this->assertEquals($activities[$this->module->cmid]->name, $this->module->name);

        $current = $completioninfo->get_data($activities[$this->module->cmid], false, $this->user->id);
        $current->completionstate = COMPLETION_COMPLETE;
        $current->timemodified = time();
        $completioninfo->internal_set_data($activities[$this->module->cmid], $current);

        $result = core_completion_external::get_course_completion_status($this->course->id, $this->user->id);
        // We need to execute the return values cleaning process to simulate the web service server.
        $studentresult = external_api::clean_returnvalue(
            core_completion_external::get_course_completion_status_returns(), $result);

        $this->assertCount(1, $studentresult['completionstatus']['completions']);
        $this->assertEquals(COMPLETION_AGGREGATION_ALL, $studentresult['completionstatus']['aggregation']);
        $this->assertTrue($studentresult['completionstatus']['completed']);
    }
}
