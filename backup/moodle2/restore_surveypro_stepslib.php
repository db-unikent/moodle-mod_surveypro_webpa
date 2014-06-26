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
 * @package    mod_surveypro
 * @subpackage backup-moodle2
 * @copyright 2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Define all the restore steps that will be used by the restore_surveypro_activity_task
 */

/**
 * Structure step to restore one surveypro activity
 */
class restore_surveypro_activity_structure_step extends restore_activity_structure_step {

    protected function define_structure() {

        $paths = array();
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('surveypro', '/activity/surveypro');
        $item = new restore_path_element('item', '/activity/surveypro/items/item');
        $paths[] = $item;
        if ($userinfo) {
            $paths[] = new restore_path_element('submission', '/activity/surveypro/submissions/submission');
            $paths[] = new restore_path_element('answer', '/activity/surveypro/answers/answer');
        }

        // Apply for 'surveyprofield' and 'surveyproformat' subplugins optional paths at surveypro_item level
        $this->add_subplugin_structure('surveyprofield', $item);
        $this->add_subplugin_structure('surveyproformat', $item);

        // Return the paths wrapped into standard activity structure
        return $this->prepare_activity_structure($paths);
    }

    protected function process_surveypro($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        $data->timeopen = $this->apply_date_offset($data->timeopen);
        $data->timeclose = $this->apply_date_offset($data->timeclose);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        // insert the surveypro record
        $newitemid = $DB->insert_record('surveypro', $data);

        // immediately after inserting "activity" record, call this
        $this->apply_activity_instance($newitemid);
    }

    protected function process_item($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->surveyproid = $this->get_new_parentid('surveypro');
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newitemid = $DB->insert_record('surveypro_item', $data);
        $this->set_mapping('item', $oldid, $newitemid, true); // We need the mapping to be able to restore files from filearea 'itemcontent'
    }

    protected function process_submission($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->surveyproid = $this->get_new_parentid('surveypro');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newitemid = $DB->insert_record('surveypro_submission', $data);
        $this->set_mapping('submission', $oldid, $newitemid);
    }

    protected function process_answer($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->submissionid = $this->get_mappingid('submission', $data->submissionid);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newitemid = $DB->insert_record('surveypro_answers', $data);
        $this->set_mapping('answer', $oldid, $newitemid);
        // needed because attachment files are 'children' of answers
    }

    protected function after_execute() {
        global $DB;

        // Add surveypro related files, no need to match by itemname (just internally handled context)
        $this->add_related_files('mod_surveypro', 'intro', null);
        $this->add_related_files('mod_surveypro', 'thankshtml', null);
        $this->add_related_files('mod_surveypro', 'userstyle', null);

        // Add item content files, matching by assignment_submission itemname
        $this->add_related_files('mod_surveypro', 'itemcontent', 'item');
        // add_related_files($component, $filearea, $mappingitemname, $filesctxid = null, $olditemid = null)

        // 1) get all the item->parentids belonging to the surveypro you are restoring.
        // 2) iterate over them, and when a parentid is found, look in item mappings and perform the set_field.
        $itemrecords = $DB->get_recordset('surveypro_item', array('surveyproid' => $this->get_new_parentid('surveypro')), '', 'id, parentid');
        if ($itemrecords->valid()) {
            foreach ($itemrecords as $itemrecord) {
                if ($itemrecord->parentid) {
                    $newparentid = $this->get_mappingid('item', $itemrecord->parentid);
                    $DB->set_field('surveypro_item', 'parentid', $newparentid, array('id' => $itemrecord->id));
                }
            }
        }
        $itemrecords->close();
    }
}