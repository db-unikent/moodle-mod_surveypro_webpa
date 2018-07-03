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
 * The Kent submissionmanager class
 *
 * @package   mod_surveypro
 * @copyright 2018 University of Kent
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/mod/surveypro/classes/submission.php');

/**
 * The class managing users submissions
 *
 * @package   mod_surveypro
 * @copyright 2018 University of Kent
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_surveypro_kent_submission extends mod_surveypro_submission {
    /**
     * @int Peer assessed activity course_module id
     */
    protected $peer_assessed_activity = 0;
    /**
     * @var Name of peer assessed activity
     */
    protected $peer_assessed_name;

    /**
     * @boolean Can students assess themselves?
     */
    protected $self_assessed_flag = false;

    /**
     * Get submissions sql.
     *
     * Teachers is the role of users usually accessing reports.
     * They are "teachers" so they care about "students" and nothing more.
     * If, at import time, some records go under the admin ownership
     * the teacher is not supposed to see them because admin is not a student.
     * In this case, if the teacher wants to see submissions owned by admin, HE HAS TO ENROLL ADMIN with some role.
     *
     * Different is the story for the admin.
     * If an admin wants to make a report, he will see EACH RESPONSE SUBMITTED
     * without care to the role of the owner of the submission.
     *
     * @param flexible_table $table
     * @return array($sql, $whereparams);
     */
    public function get_pa_submissions_sql($table) {
        global $DB, $COURSE, $USER;

        $canviewhiddenactivities = has_capability('moodle/course:viewhiddenactivities', $this->context);
        $canseeotherssubmissions = has_capability('mod/surveypro:seeotherssubmissions', $this->context);
        $canaccessallgroups = has_capability('moodle/site:accessallgroups', $this->context);

        $emptysql = 'SELECT DISTINCT s.*, s.id as submissionid, '.user_picture::fields('u').'
                     FROM {surveypro_submission} s
                         JOIN {user} u ON u.id = s.userid
                     WHERE u.id = :userid';

        $coursecontext = context_course::instance($COURSE->id);
        list($enrolsql, $eparams) = get_enrolled_sql($coursecontext);

        $sql = 'SELECT COUNT(eu.id)
                FROM ('.$enrolsql.') eu';
        // If there are no enrolled people, give up!
        if (!$DB->count_records_sql($sql, $eparams)) {
            if (!$canviewhiddenactivities) {
                return array($emptysql, array('userid' => -1));
            }
        }

        $groupmode = groups_get_activity_groupmode($this->cm, $COURSE);
        if (($groupmode == SEPARATEGROUPS) && (!$canaccessallgroups)) {
            $mygroups = groups_get_all_groups($COURSE->id, $USER->id, $this->cm->groupingid);
            $mygroups = array_keys($mygroups);
            if (!count($mygroups)) { // User is not in any group.
                // This is a student that has not been added to any group.
                // The sql needs to return an empty set.
                return array($emptysql, array('userid' => -1));
            }
        }


        if($this->self_assessed_flag) {
            $saSql = "";
        }
        else {
            $saSql = "u1.id != u.id AND";
        }

        $sql = 'SELECT DISTINCT ' . user_picture::fields('u') . ', cm.groupingid, cm.module, cm.instance, mo.name as module_name,
                        g.name as grouping_name, gg.groupid, gr.name as group_name, si.plugin, si.hidden, spa.variable, spa.options as fixed_value,
                        ss.id as submissionid, ss.surveyproid, ss.status, ss.userid, ss.timecreated, ss.timemodified,
                        sa.itemid as answer_id, sa.content, 
                        u1.id as pa_id,u1.picture as pa_picture,u1.firstname as pa_firstname,
                        u1.lastname as pa_lastname,u1.firstnamephonetic as pa_firstnamephonetic,
                        u1.lastnamephonetic as pa_lastnamephonetic,u1.middlename as pa_middlename,
                        u1.alternatename as pa_alternatename,u1.imagealt as pa_imagealt,u1.email as pa_email
                  FROM {course_modules} cm
            INNER JOIN {modules} mo ON mo.id = cm.module
            INNER JOIN {groupings} g ON g.id = cm.groupingid
            INNER JOIN {groupings_groups} gg ON gg.groupingid = g.id
            INNER JOIN {groups} gr ON gr.id = gg.groupid
            INNER JOIN {groups_members} gm ON gm.groupid = gg.groupid
            INNER JOIN {user} u ON u.id = gm.userid
                               AND u.deleted = 0
            INNER JOIN {user_enrolments} ej ON ej.userid = u.id
            INNER JOIN {enrol} en ON en.id = ej.enrolid
                                 AND en.courseid = :courseid
            INNER JOIN {groups_members} gm1 ON gm1.groupid = gm.groupid
            INNER JOIN {user} u1 ON '.$saSql.' u1.id = gm1.userid
                                AND u1.deleted = 0  
            INNER JOIN {user_enrolments} ej1 ON ej1.userid = u1.id
            INNER JOIN {enrol} en1 ON en1.id = ej1.enrolid
                                  AND en1.courseid = en.courseid                                   
            INNER JOIN {surveypro} sp ON sp.id = :surveyproid
            INNER JOIN {surveypro_item} si ON si.surveyproid = sp.id
                                          AND si.type = :type
            INNER JOIN {surveyprofield_paselect} spa ON spa.itemid = si.id
                                                    AND si.plugin = :plugin
                                                    AND spa.variable = :pa_select_student
             LEFT JOIN {surveypro_submission} ss ON ss.userid = u.id
                                               AND ss.surveyproid = sp.id
                                               AND ss.id in (SELECT an.submissionid
                                                               FROM {surveypro_answer} an
                                                              WHERE an.submissionid = ss.id
                                                                AND an.itemid = si.id
                                                                AND an.content = u1.id)
             LEFT JOIN {surveypro_answer} sa ON sa.submissionid = ss.id
                                             AND sa.itemid = si.id            
                 WHERE cm.id = :peer_assessed_activity';


        /*
         * Creation of where parameters - first fixed ones
         */
        $whereparams = array('courseid' => $COURSE->id,
            'surveyproid' => $this->surveypro->id, 'type' => 'field', 'plugin' => 'paselect',
            'pa_select_student' => 'pa_select_student', 'peer_assessed_activity' => $this->peer_assessed_activity);

        if (($groupmode == SEPARATEGROUPS) && (!$canaccessallgroups)) {
            $sql .= ' AND EXISTS (SELECT gm2.groupid
                                    FROM {groups_members} gm2
                                   WHERE gm2.groupid = gm.groupid  
                                     AND gm2.userid = :gm_userid)';
            $whereparams['gm_userid'] = $USER->id;
        }

        if (!$canseeotherssubmissions) {
            // Restrict to your submissions only.
            $sql .= ' AND u.id = :userid';
            $whereparams['userid'] = $USER->id;
        }

        if (isset($this->outputtable)) {
            list($where, $filterparams) = $this->outputtable->get_sql_where();
            if ($where) {
                $sql .= ' AND '.$where;
                $whereparams = array_merge($whereparams,  $filterparams);
            }
        }

        /*
         * SQL Order by creation
         *
         */
        $sortOrder = array();

        //This should now be Ok as setting table columns to not be sortable
        if ($table->get_sql_sort()) {
            $sortFields = explode(',', $table->get_sql_sort());
            foreach($sortFields as $sort) {
                if (strpos($sort, "ns_") === false) {
                    $sortOrder[] = $sort;
                }
            }

        }

        if(count($sortOrder)){
            $sql .= " ORDER BY ".implode(',', $sortOrder);
        } else {
            $sql .= ' ORDER BY group_name, u.lastname ASC';
        }

        return array($sql, $whereparams);


        if ($this->searchquery) {
            // This will be re-send to URL for next page reload, whether requested with a sort, for instance.
            $whereparams['searchquery'] = $this->searchquery;

            $searchrestrictions = unserialize($this->searchquery);

            $sqlanswer = 'SELECT a.submissionid, COUNT(a.submissionid) as matchcount
              FROM {surveypro_answer} a';

            // (a.itemid = 7720 AND a.content = 0) OR (a.itemid = 7722 AND a.content = 1))
            // (a.itemid = 1219 AND $DB->sql_like('a.content', ':content_1219', false)).
            $userquery = array();
            foreach ($searchrestrictions as $itemid => $searchrestriction) {
                $itemseed = $DB->get_record('surveypro_item', array('id' => $itemid), 'type, plugin', MUST_EXIST);
                $classname = 'surveypro'.$itemseed->type.'_'.$itemseed->plugin.'_'.$itemseed->type;
                // Ask to the item class how to write the query.
                list($whereclause, $whereparam) = $classname::response_get_whereclause($itemid, $searchrestriction);
                $userquery[] = '(a.itemid = '.$itemid.' AND '.$whereclause.')';
                $whereparams['content_'.$itemid] = $whereparam;
            }
            $sqlanswer .= ' WHERE ('.implode(' OR ', $userquery).')';

            $sqlanswer .= ' GROUP BY a.submissionid';
            $sqlanswer .= ' HAVING matchcount = :matchcount';
            $whereparams['matchcount'] = count($userquery);

            // Finally, continue writing $sql.
            $sql .= ' JOIN ('.$sqlanswer.') a ON a.submissionid = s.id';
        }



        // Manage table alphabetical filter.
        list($wherefilter, $wherefilterparams) = $table->get_sql_where();
        if ($wherefilter) {
            $sql .= ' AND '.$wherefilter;
            $whereparams = $whereparams + $wherefilterparams;
        }

        if (($groupmode == SEPARATEGROUPS) && (!$canaccessallgroups)) {
            // Restrict to your groups only.
            list($insql, $subparams) = $DB->get_in_or_equal($mygroups, SQL_PARAMS_NAMED, 'groupid');
            $whereparams = array_merge($whereparams, $subparams);
            $sql .= ' AND gm.groupid '.$insql;
        }

        if ($table->get_sql_sort()) {
            // Sort coming from $table->get_sql_sort().
            $sql .= ' ORDER BY '.$table->get_sql_sort();
        } else {
            $sql .= ' ORDER BY s.timecreated';
        }

        if (!$canviewhiddenactivities) {
            $whereparams = array_merge($whereparams, $eparams);
        }

        return array($sql, $whereparams);
    }


    /**
     * Get Peer Assessed activity id if there is one
     *
     * @return int Peer assessed activity course module id or 0
     */
    public function set_peer_assessed_activity() {
        global $DB;

        $sql = "SELECT sp.variable, sp.options
                FROM {surveypro_item} si
                INNER JOIN {surveyprofield_paselect} sp ON sp.itemid = si.id
                WHERE si.surveyproid = ?
                  AND si.type = 'field'
                  AND si.plugin = 'paselect'";

        $params = array("surveyproid" => $this->surveypro->id);
        $rows = $DB->get_records_sql($sql, $params);

        foreach ($rows as $row) {
            $option = $row->options;
            if ($row->variable == "pa_select_activity" && strstr($option, "::")) {
                $option = explode("::", $option);
                $this->peer_assessed_activity = $option[0];
                $this->peer_assessed_name = $option[1];
            }
            elseif($row->variable == "pa_select_self" && strtoupper($row->options{0}) == "Y") {
                $this->self_assessed_flag = true;
            }
        }

       return $this->peer_assessed_activity;
    }


    /**
     * Display the submissions table.
     *
     * @return void
     */
    public function display_kent_submissions_table() {
        if(0 == $this->set_peer_assessed_activity()) {
            return $this->display_submissions_table();
        }

        echo "<h3>Peer assessment for ".$this->peer_assessed_name."</h3>";

        global $CFG, $OUTPUT, $DB, $COURSE, $USER;

        require_once($CFG->libdir.'/tablelib.php');

        $canalwaysseeowner = has_capability('mod/surveypro:alwaysseeowner', $this->context);
        $canseeotherssubmissions = has_capability('mod/surveypro:seeotherssubmissions', $this->context);
        $caneditownsubmissions = has_capability('mod/surveypro:editownsubmissions', $this->context);
        $caneditotherssubmissions = has_capability('mod/surveypro:editotherssubmissions', $this->context);
        $canduplicateownsubmissions = has_capability('mod/surveypro:duplicateownsubmissions', $this->context);
        $canduplicateotherssubmissions = has_capability('mod/surveypro:duplicateotherssubmissions', $this->context);
        $candeleteownsubmissions = has_capability('mod/surveypro:deleteownsubmissions', $this->context);
        $candeleteotherssubmissions = has_capability('mod/surveypro:deleteotherssubmissions', $this->context);
        $cansavesubmissiontopdf = has_capability('mod/surveypro:savesubmissiontopdf', $this->context);
        $canaccessallgroups = has_capability('moodle/site:accessallgroups', $this->context);

        $table = new flexible_table('submissionslist');

        if ($canseeotherssubmissions) {
            $table->initialbars(true);
        }

        $paramurl = array();
        $paramurl['id'] = $this->cm->id;
        if ($this->searchquery) {
            $paramurl['searchquery'] = $this->searchquery;
        }
        $baseurl = new moodle_url('/mod/surveypro/view.php', $paramurl);
        $table->define_baseurl($baseurl);

        $tablecolumns = array();
        $tablecolumns[] = 'group_name';
        if ($canalwaysseeowner || empty($this->surveypro->anonymous)) {
            $tablecolumns[] = 'picture';
            $tablecolumns[] = 'fullname';
        }
        if(empty($this->surveypro->anonymous)) {
            $tablecolumns[] = 'pa_picture';
            $tablecolumns[] = 'pa_lastname';
        }
        $tablecolumns[] = 'status';
        $tablecolumns[] = 'timecreated';
        if (!$this->surveypro->history) {
            $tablecolumns[] = 'timemodified';
        }
        $tablecolumns[] = 'actions';
        $table->define_columns($tablecolumns);

        $tableheaders = array();
        $tableheaders[] = get_string('group');
        if ($canalwaysseeowner || empty($this->surveypro->anonymous)) {
            $tableheaders[] = '';
            $tableheaders[] = get_string('fullname');
        }
        if(empty($this->surveypro->anonymous)) {
            $tableheaders[] = 'Assessed';
            $tableheaders[] = get_string('fullname');
        }
        $tableheaders[] = get_string('status');
        $tableheaders[] = get_string('timecreated', 'mod_surveypro');
        if (!$this->surveypro->history) {
            $tableheaders[] = get_string('timemodified', 'mod_surveypro');
        }
        $tableheaders[] = get_string('actions');
        $table->define_headers($tableheaders);

        $table->sortable(true, 'sortindex', 'ASC'); // Sorted by sortindex by default.
        $table->no_sorting('actions');
        $table->no_sorting('pa_picture');

        $table->column_class('group_name', 'group_name');
        $table->column_class('picture', 'picture');
        $table->column_class('fullname', 'fullname');
        $table->column_class('pa_picture', 'picture');
        $table->column_class('pa_fullname', 'fullname');
        $table->column_class('status', 'status');
        $table->column_class('timecreated', 'timecreated');
        if (!$this->surveypro->history) {
            $table->column_class('timemodified', 'timemodified');
        }
        $table->column_class('actions', 'actions');

        // Hide the same info whether in two consecutive rows.
        if ($canalwaysseeowner || empty($this->surveypro->anonymous)) {
            $table->column_suppress('picture');
            $table->column_suppress('fullname');
        }

        // General properties for the whole table.
        $table->set_attribute('cellpadding', 5);
        $table->set_attribute('id', 'submissions');
        $table->set_attribute('class', 'generaltable');
        $table->set_attribute('align', 'center');
        $table->setup();

        $status = array();
        $status[SURVEYPRO_STATUSINPROGRESS] = get_string('statusinprogress', 'mod_surveypro');
        $status[SURVEYPRO_STATUSCLOSED] = get_string('statusclosed', 'mod_surveypro');

        $neverstr = get_string('never');

        $counter = $this->get_counter($table);
        $table->pagesize(20, $counter['closedsubmissions'] + $counter['inprogresssubmissions']);

        $this->display_submissions_overview($counter['allusers'],
                                            $counter['closedsubmissions'], $counter['closedusers'],
                                            $counter['inprogresssubmissions'], $counter['inprogressusers']);

        list($sql, $whereparams) = $this->get_pa_submissions_sql($table);

        $submissions = $DB->get_recordset_sql($sql, $whereparams, $table->get_page_start(), $table->get_page_size());
        if ($submissions->valid()) {

            $iconparams = array();

            $nonhistoryeditstr = get_string('edit');
            $iconparams['title'] = $nonhistoryeditstr;
            $nonhistoryediticn = new pix_icon('t/edit', $nonhistoryeditstr, 'moodle', $iconparams);

            $readonlyaccessstr = get_string('readonlyaccess', 'mod_surveypro');
            $iconparams['title'] = $readonlyaccessstr;
            $readonlyicn = new pix_icon('readonly', $readonlyaccessstr, 'surveypro', $iconparams);

            $duplicatestr = get_string('duplicate');
            $iconparams['title'] = $duplicatestr;
            $duplicateicn = new pix_icon('t/copy', $duplicatestr, 'moodle', $iconparams);

            if ($this->surveypro->history) {
                $attributestr = get_string('editcopy', 'mod_surveypro');
                $linkidprefix = 'editcopy_submission_';
            } else {
                $attributestr = $nonhistoryeditstr;
                $linkidprefix = 'edit_submission_';
            }
            $iconparams['title'] = $attributestr;
            $attributeicn = new pix_icon('t/edit', $attributestr, 'moodle', $iconparams);

            $deletestr = get_string('delete');
            $iconparams['title'] = $deletestr;
            $deleteicn = new pix_icon('t/delete', $deletestr, 'moodle', $iconparams);

            $downloadpdfstr = get_string('downloadpdf', 'mod_surveypro');
            $iconparams['title'] = $downloadpdfstr;
            $downloadpdficn = new pix_icon('i/export', $downloadpdfstr, 'moodle', $iconparams);

            if ($groupmode = groups_get_activity_groupmode($this->cm, $COURSE)) {
                if ($groupmode == SEPARATEGROUPS) {
                    $mygroupmates = surveypro_groupmates($this->cm);
                }
            }

            $tablerowcounter = 0;
            $paramurlbase = array('id' => $this->cm->id);

            $pa_submission = new stdClass();

            foreach ($submissions as $submission) {

                // Count submissions per each user.
                $tablerowcounter++;
                $submissionsuffix = 'row_'.$tablerowcounter;

                // Before starting, just set some information.
                if (!$ismine = ($submission->id == $USER->id)) {
                    if (!$canseeotherssubmissions) {
                        continue;
                    }
                    if ($groupmode == SEPARATEGROUPS) {
                        if ($canaccessallgroups) {
                            $groupuser = true;
                        } else {
                            $groupuser = in_array($submission->id, $mygroupmates);
                        }
                    } else {
                        $groupuser = true;
                    }
                }

                $tablerow = array();

                // Group
                $tablerow[] = $submission->group_name;

                // Icon.
                if ($canalwaysseeowner || empty($this->surveypro->anonymous)) {
                    $tablerow[] = $OUTPUT->user_picture($submission, array('courseid' => $COURSE->id));

                    // User fullname.
                    $paramurl = array('id' => $submission->id, 'course' => $COURSE->id);
                    $url = new moodle_url('/user/view.php', $paramurl);
                    $tablerow[] = '<a href="'.$url->out().'">'.fullname($submission).'</a>';
                }


                $pa_submission->id = $submission->pa_id;
                $pa_submission->picture = $submission->pa_picture;
                $pa_submission->firstname = $submission->pa_firstname;
                $pa_submission->lastname = $submission->pa_lastname;
                $pa_submission->firstnamephonetic = $submission->pa_firstnamephonetic;
                $pa_submission->lastnamephonetic = $submission->pa_lastnamephonetic;
                $pa_submission->middlename = $submission->pa_middlename;
                $pa_submission->alternatename = $submission->pa_alternatename;
                $pa_submission->imagealt = $submission->pa_imagealt;
                $pa_submission->email = $submission->pa_email;

                // Assessed user.
                if(empty($this->surveypro->anonymous)) {
                    $tablerow[] = $OUTPUT->user_picture($pa_submission, array('courseid' => $COURSE->id));

                    // User fullname.
                    $paramurl = array('id' => $pa_submission->id, 'course' => $COURSE->id);
                    $url = new moodle_url('/user/view.php', $paramurl);
                    $tablerow[] = '<a href="'.$url->out().'">'.fullname($pa_submission).'</a>';
                }

                // Surveypro status.
                if(!isset($status[$submission->status])) {
                    $tablerow[] = $submission->status;
                }
                else {
                    $tablerow[] = $status[$submission->status];
                }
                // Creation time.
                if($submission->timecreated) {
                    $tablerow[] = userdate($submission->timecreated);
                }
                else {
                    $tablerow[] = "";
                }

                // Timemodified.
                if (!$this->surveypro->history) {
                    // Modification time.
                    if ($submission->timemodified) {
                        $tablerow[] = userdate($submission->timemodified);
                    } else {
                        $tablerow[] = $neverstr;
                    }
                }

                // Actions.
                $icons = "";
                $paramurl = $paramurlbase;
                $paramurl['submissionid'] = $submission->submissionid;

                // Edit.
                if ($ismine) { // I am the owner.
                    if ($submission->status == SURVEYPRO_STATUSINPROGRESS) {
                        $displayediticon = true;
                    } else {
                        $displayediticon = $caneditownsubmissions;
                    }
                } else { // I am not the owner.
                    if ($groupmode == SEPARATEGROUPS) {
                        $displayediticon = $groupuser && $caneditotherssubmissions;
                    } else { // NOGROUPS || VISIBLEGROUPS.
                        $displayediticon = $caneditotherssubmissions;
                    }
                }
                if ($displayediticon && $submission->submissionid) {
                    $paramurl['view'] = SURVEYPRO_EDITRESPONSE;
                    if ($submission->status == SURVEYPRO_STATUSINPROGRESS) {
                        // Here title and alt are ALWAYS $nonhistoryeditstr.
                        $link = new moodle_url('/mod/surveypro/view_form.php', $paramurl);
                        $paramlink = array('id' => 'edit_submission_'.$submissionsuffix, 'title' => $nonhistoryeditstr);
                        $icons = $OUTPUT->action_icon($link, $nonhistoryediticn, null, $paramlink);
                    } else {
                        // Here title and alt depend from $this->surveypro->history.
                        $link = new moodle_url('/mod/surveypro/view_form.php', $paramurl);
                        $paramlink = array('id' => $linkidprefix.$submissionsuffix, 'title' => $attributestr);
                        $icons = $OUTPUT->action_icon($link, $attributeicn, null, $paramlink);
                    }
                } elseif ($submission->submissionid) {
                    $paramurl['view'] = SURVEYPRO_READONLYRESPONSE;

                    $link = new moodle_url('/mod/surveypro/view_form.php', $paramurl);
                    $paramlink = array('id' => 'view_submission_'.$submissionsuffix, 'title' => $readonlyaccessstr);
                    $icons = $OUTPUT->action_icon($link, $readonlyicn, null, $paramlink);
                }

                // Duplicate.
                /* Don't think we want to duplicate peer assessments giving someone more than one assessment from same person
                if ($ismine) { // I am the owner.
                    $displayduplicateicon = $canduplicateownsubmissions;
                } else { // I am not the owner.
                    if ($groupmode == SEPARATEGROUPS) {
                        $displayduplicateicon = $groupuser && $canduplicateotherssubmissions;
                    } else { // NOGROUPS || VISIBLEGROUPS.
                        $displayduplicateicon = $canduplicateotherssubmissions;
                    }
                }
                if ($displayduplicateicon && $submission->submissionid) { // I am the owner or a groupmate.
                    $utilityman = new mod_surveypro_utility($this->cm, $this->surveypro);
                    $cansubmitmore = $utilityman->can_submit_more($submission->id);
                    if ($cansubmitmore) { // The copy will be assigned to the same owner.
                        $paramurl = $paramurlbase;
                        $paramurl['submissionid'] = $submission->submissionid;
                        $paramurl['sesskey'] = sesskey();
                        $paramurl['act'] = SURVEYPRO_DUPLICATERESPONSE;

                        $link = new moodle_url('/mod/surveypro/view.php', $paramurl);
                        $paramlink = array('id' => 'duplicate_submission_'.$submissionsuffix, 'title' => $duplicatestr);
                        $icons .= $OUTPUT->action_icon($link, $duplicateicn, null, $paramlink);
                    }
                }
                */

                // Delete.
                $paramurl = $paramurlbase;
                $paramurl['submissionid'] = $submission->submissionid;
                if ($ismine) { // I am the owner.
                    $displaydeleteicon = $candeleteownsubmissions;
                } else {
                    if ($groupmode == SEPARATEGROUPS) {
                        $displaydeleteicon = $groupuser && $candeleteotherssubmissions;
                    } else { // NOGROUPS || VISIBLEGROUPS.
                        $displaydeleteicon = $candeleteotherssubmissions;
                    }
                }
                if ($displaydeleteicon && $submission->submissionid) {
                    $paramurl['sesskey'] = sesskey();
                    $paramurl['act'] = SURVEYPRO_DELETERESPONSE;

                    $link = new moodle_url('/mod/surveypro/view.php', $paramurl);
                    $paramlink = array('id' => 'delete_submission_'.$submissionsuffix, 'title' => $deletestr);
                    $icons .= $OUTPUT->action_icon($link, $deleteicn, null, $paramlink);
                }

                // Download to pdf.
                if ($cansavesubmissiontopdf && $submission->submissionid) {
                    $paramurl = $paramurlbase;
                    $paramurl['submissionid'] = $submission->submissionid;
                    $paramurl['view'] = SURVEYPRO_RESPONSETOPDF;

                    $link = new moodle_url('/mod/surveypro/view.php', $paramurl);
                    $paramlink = array('id' => 'pdfdownload_submission_'.$submissionsuffix, 'title' => $downloadpdfstr);
                    $icons .= $OUTPUT->action_icon($link, $downloadpdficn, null, $paramlink);
                }

                $tablerow[] = $icons;

                // Add row to the table.
                $table->add_data($tablerow);
            }
        }
        $submissions->close();

        $table->summary = get_string('submissionslist', 'mod_surveypro');
        $table->print_html();

        // If this is the output of a search and nothing has been found add a way to show all submissions.
        if (!isset($tablerow) && ($this->searchquery)) {
            $url = new moodle_url('/mod/surveypro/view.php', array('id' => $this->cm->id));
            $label = get_string('showallsubmissions', 'mod_surveypro');
            echo $OUTPUT->box($OUTPUT->single_button($url, $label, 'get'), 'clearfix mdl-align');
        }
    }
}
