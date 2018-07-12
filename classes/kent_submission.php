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
     * @int Number of groups in activity - or user part of if student view
     */
    protected $group_count = 0;

    /**
     * @var Group name if one group
     */
    protected $group_name = "";

    /**
     * @var Array of group ids attached to peer assessed activity and user has permissions to
     */
    protected $mygroups = array();

    /**
     * @int Number of responses completed
     */
    protected $response_count = 0;

    /**
     * @int Number of responses expected
     */
    protected $response_total = 0;

    /**
     * @var Peer assessment object
     */
    protected $pa_cm;

    /**
     * @int Peer assessment total sql query rowcount
     */
    protected $pa_total = 0;

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

        $coursecontext = context_course::instance($COURSE->id);

        /*$enrolsql =
        SELECT DISTINCT eu2_u.id FROM {user} eu2_u
        JOIN {user_enrolments} ej2_ue ON ej2_ue.userid = eu2_u.id
        JOIN {enrol} ej2_e ON (ej2_e.id = ej2_ue.enrolid AND ej2_e.courseid = :ej2_courseid)
        WHERE 1 = 1 AND eu2_u.deleted = 0
        */
        if (!$canviewhiddenactivities) {
            list($enrolsql, $eparams) = get_enrolled_sql($coursecontext);
            $sql = 'SELECT COUNT(eu.id)
                FROM (' . $enrolsql . ') eu';

            // If there are no enrolled people on the course, give up!
            if (!$DB->count_records_sql($sql, $eparams)) {
                return array("", array('userid' => -1));
            }
        }

        $whereparams = array();

        $uSql = "";
        if (!$canviewhiddenactivities) {
            $uSql = "
                               AND u.deleted = 0
            INNER JOIN {user_enrolments} ej ON ej.userid = u.id
            INNER JOIN {enrol} en ON en.id = ej.enrolid
                                 AND en.courseid = cm.course";
        }

        $u1Sql = "";
        if (!$canviewhiddenactivities) {
            $u1Sql = "
                               AND u1.deleted = 0
            INNER JOIN {user_enrolments} ej1 ON ej1.userid = u1.id
            INNER JOIN {enrol} en1 ON en1.id = ej1.enrolid
                                 AND en1.courseid = cm.course";
        }



        if (!count($this->mygroups)) { // User is not in any group.
                // This is a student that has not been added to any group.
                // The sql needs to return an empty set.
            return array("", array('userid' => -1));
        }
        else {
            $gpSql = " AND gg.groupid in (".implode(",", $this->mygroups).")";
        }

        if($this->self_assessed_flag) {
            $saSql = "";
        }
        else {
            $saSql = "u1.id != u.id AND";
        }

        //This is the default part of main SQL if no search made. Note left join here as want non-submissions as well
        //If a search is made, assumming it is a search on a submission made so left joins become inner joins as won't
        // be showing student assessments waiting to be submitted
        $searchSql = "
             LEFT JOIN {surveypro_submission} ss ON ss.userid = u.id
                                               AND ss.surveyproid = sp.id
                                               AND ss.id in (SELECT an.submissionid
                                                               FROM {surveypro_answer} an
                                                              WHERE an.submissionid = ss.id
                                                                AND an.itemid = si.id
                                                                AND an.content = u1.id)
             LEFT JOIN {surveypro_answer} sa ON sa.submissionid = ss.id
                                            AND sa.itemid = si.id";
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
            $sqlanswer .= " WHERE (".implode(' OR ', $userquery).")";

            $sqlanswer .= " GROUP BY a.submissionid";
            $sqlanswer .= " HAVING matchcount = :matchcount";
            $whereparams['matchcount'] = count($userquery);

            //$searchSql will be added in main sql
            $searchSql = "
             INNER JOIN {surveypro_submission} ss ON ss.userid = u.id
                                               AND ss.surveyproid = sp.id
             INNER JOIN {surveypro_answer} sa ON sa.submissionid = ss.id
                                             AND sa.itemid = si.id     
                                             AND sa.content = u1.id
             INNER JOIN (".$sqlanswer.") a ON a.submissionid = sa.submissionid";
        }

        $sqlSelect = "SELECT DISTINCT ". user_picture::fields("u") .", cm.groupingid, cm.module, cm.instance, mo.name as module_name,
                        g.name as grouping_name, gg.groupid, gr.name as group_name, si.plugin, si.hidden, spa.variable, spa.options as fixed_value,
                        ss.id as submissionid, ss.surveyproid, ss.status, ss.userid, ss.timecreated, ss.timemodified,
                        sa.itemid as answer_id, sa.content, 
                        u1.id as pa_id,u1.picture as pa_picture,u1.firstname as pa_firstname,
                        u1.lastname as pa_lastname,u1.firstnamephonetic as pa_firstnamephonetic,
                        u1.lastnamephonetic as pa_lastnamephonetic,u1.middlename as pa_middlename,
                        u1.alternatename as pa_alternatename,u1.imagealt as pa_imagealt,u1.email as pa_email";
        $sqlSelectCount = "SELECT COUNT(u.id) as pa_total";


        $sql = $sqlSelect."
                  FROM {course_modules} cm
            INNER JOIN {modules} mo ON mo.id = cm.module
            INNER JOIN {groupings} g ON g.id = cm.groupingid
            INNER JOIN {groupings_groups} gg ON gg.groupingid = g.id ".$gpSql."
            INNER JOIN {groups} gr ON gr.id = gg.groupid
                                  AND gr.courseid = cm.course
            INNER JOIN {groups_members} gm ON gm.groupid = gg.groupid
            INNER JOIN {user} u ON u.id = gm.userid ".$uSql."
            INNER JOIN {groups_members} gm1 ON gm1.groupid = gm.groupid
            INNER JOIN {user} u1 ON ".$saSql." u1.id = gm1.userid ".$u1Sql."                             
            INNER JOIN {surveypro} sp ON sp.id = :surveyproid
            INNER JOIN {surveypro_item} si ON si.surveyproid = sp.id
                                          AND si.type = :type
            INNER JOIN {surveyprofield_paselect} spa ON spa.itemid = si.id
                                                    AND si.plugin = :plugin
                                                    AND spa.variable = :pa_select_student ".$searchSql."       
                 WHERE cm.id = :peer_assessed_activity
                   AND cm.course = :course";

        /*
         * Creation of where parameters - first fixed ones
         */
        $whereparams['surveyproid'] = $this->surveypro->id;
        $whereparams['type'] = 'field';
        $whereparams['plugin'] = 'paselect';
        $whereparams['pa_select_student'] = 'pa_select_student';
        $whereparams['peer_assessed_activity'] = $this->peer_assessed_activity;
        $whereparams['course'] = $COURSE->id;

        //This will be standard Student view.
        if (!$canseeotherssubmissions) {
            // Restrict to your submissions only.
            $sql .= ' AND u.id = :userid';
            $whereparams['userid'] = $USER->id;
        }

        // Manage table alphabetical filter.
        list($wherefilter, $wherefilterparams) = $table->get_sql_where();
        if ($wherefilter) {
            $replace = array('firstname'=>'u1.firstname', 'lastname'=>'u1.lastname');
            $sql .= ' AND '.str_replace(array_keys($replace), array_values($replace), $wherefilter);
            $whereparams = $whereparams + $wherefilterparams;
        }


        $sqlCount = str_replace($sqlSelect, $sqlSelectCount, $sql);

        $total = $DB->get_record_sql($sqlCount, $whereparams);
        $this->pa_total = $total->pa_total;

        /*
         * SQL Order by group, creation
         *
         */
        $sortOrder = array();

        //This should now be Ok as setting table columns to not be sortable
        if ($table->get_sql_sort()) {
            $sortFields = explode(',', $table->get_sql_sort());
            foreach($sortFields as $sort) {
                if(strstr($sort, "assessor") !== false) {
                    $sortOrder[] = "u.lastname".strtok($sort, "assessor");
                    $sortOrder[] = "u.firstname".strtok($sort, "assessor");
                }
                elseif(strstr($sort, "lastname")) {
                    $sortOrder[] = str_replace("lastname", "pa_lastname", $sort);
                }
                elseif(strstr($sort, "firstname")) {
                    $sortOrder[] = str_replace("firstname", "pa_firstname", $sort);
                }
                elseif(strpos($sort, "ns_") === false) {
                    $sortOrder[] = $sort;
                }
            }
        }

        if(count($sortOrder)){
            $sql .= " ORDER BY ".implode(',', $sortOrder);
        } else {
            $sql .= " ORDER BY group_name, u.lastname, u.firstname, pa_lastname, pa_firstname";
        }

        #echo "<p>$sql</p>";print_r($whereparams);

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

        if($this->peer_assessed_activity) {
            $sql = "SELECT m.name
                  FROM {course_modules} c
            INNER JOIN {modules} m ON m.id = c.module
                 WHERE c.id = :cmid";

            $params = array("cmid" => $this->peer_assessed_activity);
            $rows = $DB->get_records_sql($sql, $params);

            foreach ($rows as $row) {
                $module_name = $row->name;
            }

            $this->pa_cm = get_coursemodule_from_id($module_name, $this->peer_assessed_activity, 0, false, MUST_EXIST);
            $this->set_group_details();
        }
        return $this->peer_assessed_activity;
    }


    /**
     * Set group_count of Peer Assessed activity
     *
     * @param $groups array of group codes - if student using belonging to  a single group
     *
     * @return void
     */
    public function set_group_details($groups = array()) {
        global $DB, $COURSE, $USER;

        $this->mygroups = array();

        $sql = "SELECT gr.id, gr.name
                  FROM {course_modules} cm
            INNER JOIN {groupings_groups} gg ON gg.groupingid = cm.groupingid
            INNER JOIN {groups} gr ON gr.id = gg.groupid
                                  AND gr.courseid = cm.course 
                 WHERE cm.id = :cmid";

        $params = array('cmid' => $this->peer_assessed_activity);

        foreach($groups as $key=>$group) {
            if(is_int($group)) {
                $groups[$key] = $group;
            }
            else {
                $groups[$key] = 0;
            }
        }

        if(count($groups)) {
            $sql .= "AND gr.id in (". implode(',', $groups).")";
        }

        $rows = $DB->get_records_sql($sql, $params);

        foreach ($rows as $row) {
            $this->mygroups[] = $row->id;
            $this->group_name = $row->name;
        }

        $canaccessallgroups = has_capability('moodle/site:accessallgroups', $this->context);

        //We want groups relating to the activity that is being assessed, not current surveypro one
        //So switch $this->cm for $this->pa_cm in groups_get_activity_group_mode
        $groupmode = groups_get_activity_groupmode($this->pa_cm, $COURSE);
        if (($groupmode == SEPARATEGROUPS) && (!$canaccessallgroups)) {
            $mygroups = groups_get_all_groups($COURSE->id, $USER->id, $this->pa_cm->groupingid);
            $this->mygroups = array_keys($mygroups);
        }
        $this->group_count = count($this->mygroups);
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
        $tableheaders = array();
        if($this->group_count > 1) {
            $tablecolumns[] = 'group_name';
            $table->column_class('group_name', 'group_name');
            $tableheaders[] = get_string('group');
        }
        if ($canseeotherssubmissions && ($canalwaysseeowner || empty($this->surveypro->anonymous))) {
            $tablecolumns[] = 'a_picture';
            $tablecolumns[] = 'assessor';
            $table->column_class('a_picture', 'picture');
            $table->column_class('assessor', 'fullname');
            $tableheaders[] = '';
            $tableheaders[] = "Assessor";
        }
        if(empty($this->surveypro->anonymous)) {
            $tablecolumns[] = 'picture';
            $tablecolumns[] = 'fullname';
            $table->column_class('picture', 'picture');
            $table->column_class('fullname', 'fullname');
            $tableheaders[] = '';
            $tableheaders[] = get_string('fullname');
        }
        $tablecolumns[] = 'status';
        $table->column_class('status', 'status');
        $tableheaders[] = get_string('status');

        $tablecolumns[] = 'timecreated';
        $table->column_class('timecreated', 'timecreated');
        $tableheaders[] = get_string('timecreated', 'mod_surveypro');

        if (!$this->surveypro->history) {
            $tablecolumns[] = 'timemodified';
            $table->column_class('modified', 'timemodified');
            $tableheaders[] = get_string('timemodified', 'mod_surveypro');
        }
        $tablecolumns[] = 'actions';
        $table->column_class('actions', 'actions');
        $tableheaders[] = get_string('actions');

        $table->define_columns($tablecolumns);
        $table->define_headers($tableheaders);

        $table->sortable(true, 'sortindex', 'ASC'); // Sorted by sortindex by default.
        $table->no_sorting('actions');
        $table->no_sorting('a_picture');

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



        /*
        $this->display_submissions_overview($counter['allusers'],
                                            $counter['closedsubmissions'], $counter['closedusers'],
                                            $counter['inprogresssubmissions'], $counter['inprogressusers']);
        */
        list($sql, $whereparams) = $this->get_pa_submissions_sql($table);

        if(!$sql) {
            if ($this->searchquery) {
                echo '<div class="alert alert-danger">No details with this query for this Peer assessment can be found!</div>';
                $url = new moodle_url('/mod/surveypro/view.php', array('id' => $this->cm->id));
                $label = get_string('showallsubmissions', 'mod_surveypro');
                echo $OUTPUT->box($OUTPUT->single_button($url, $label, 'get'), 'clearfix mdl-align');
            }
            else {
                echo '<div class="alert alert-danger">No details for this Peer assessment can be found!</div>';
            }
            echo '
            <script type="text/javascript">
            $(document).ready( function() {
                $(\'ul.nav-tabs\').hide();
                $(\'button[type="submit"]:contains("' . get_string("deleteallsubmissions", "mod_surveypro") . '")\').hide();           
                $(\'button[type="submit"]:contains("' . get_string("addnewsubmission", "mod_surveypro") . '")\').hide();
            });
            </script>';

            return;
        }

        if($canseeotherssubmissions || $canaccessallgroups) {}
        else {
            echo '
            <script type="text/javascript">
            $(document).ready( function() {
                $(\'a.nav-link[title="' . get_string("tabsubmissionspage1", "mod_surveypro") . '"]\').hide();
            });
            </script>';
        }


        echo "<h3>Peer assessment for " . $this->peer_assessed_name . "</h3>";
            if ($this->group_count < 2) {
                if($canseeotherssubmissions) {
                    echo "<h4>Assessments for group " . $this->group_name . "</h4>";
                }
                else {
                    echo "<h4>My assessments for group " . $this->group_name . "</h4>";
                }
            }

        $table->pagesize(20, $this->pa_total);

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
            $this->response_total = 0;

            $paramurlbase = array('id' => $this->cm->id);

            $pa_submission = new stdClass();
            $prev_assessor = "";

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
                if($this->group_count > 1) {
                    $tablerow[] = $submission->group_name;
                }

                // Assessor
                if($canseeotherssubmissions && $prev_assessor == $submission->id) {
                    $tablerow[] = "";
                    $tablerow[] = "";
                }
                elseif ($canseeotherssubmissions && ($canalwaysseeowner || empty($this->surveypro->anonymous))) {
                    $tablerow[] = $OUTPUT->user_picture($submission, array('courseid' => $COURSE->id));

                    // User fullname.
                    $paramurl = array('id' => $submission->id, 'course' => $COURSE->id);
                    $url = new moodle_url('/user/view.php', $paramurl);
                    $tablerow[] = '<a href="'.$url->out().'">'.fullname($submission).'</a>';
                }
                $prev_assessor = $submission->id;

                // Assessed user.
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

                if(empty($this->surveypro->anonymous)) {
                    $tablerow[] = $OUTPUT->user_picture($pa_submission, array('courseid' => $COURSE->id));

                    // User fullname.
                    $paramurl = array('id' => $pa_submission->id, 'course' => $COURSE->id);
                    $url = new moodle_url('/user/view.php', $paramurl);
                    $tablerow[] = '<a href="' . $url->out() . '">' . fullname($pa_submission) . '</a>';
                }

                // Surveypro status.
                if(!isset($status[$submission->status])) {
                    $tablerow[] = $submission->status;
                }
                else {
                    $tablerow[] = $status[$submission->status];
                    if($submission->status == 0) {
                        $this->response_total++;
                    }
                }

                $this->response_count = $tablerowcounter;



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

                #echo "<p> status ".$submission->status." AND ".SURVEYPRO_STATUSINPROGRESS." AND $ismine AND $caneditownsubmissions</p>";

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
                elseif($submission->id == $USER->id) {
                    $paramurl = $paramurlbase;
                    $paramurl['paid'] = $pa_submission->id;
                    $paramurl['view'] = 1;
                    $link = new moodle_url('/mod/surveypro/view_form.php', $paramurl);
                    $paramlink = array('id' => 'new_submission_'.$this->surveypro->id, 'title' => 'New submission');
                    $icons = $OUTPUT->action_icon($link, $attributeicn, null, $paramlink);
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
                /*
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
                */

                // Download to pdf.
                /*
                if ($cansavesubmissiontopdf && $submission->submissionid) {
                    $paramurl = $paramurlbase;
                    $paramurl['submissionid'] = $submission->submissionid;
                    $paramurl['view'] = SURVEYPRO_RESPONSETOPDF;

                    $link = new moodle_url('/mod/surveypro/view.php', $paramurl);
                    $paramlink = array('id' => 'pdfdownload_submission_'.$submissionsuffix, 'title' => $downloadpdfstr);
                    $icons .= $OUTPUT->action_icon($link, $downloadpdficn, null, $paramlink);
                }
                */
                $tablerow[] = $icons;

                #print_r($tablerow);echo "<br>-----<br>";

                // Add row to the table.
                $table->add_data($tablerow);
            }
        }
        $submissions->close();

        $table->summary = get_string('submissionslist', 'mod_surveypro');
        $table->print_html();

        if($this->response_total >= $this->response_count) {
            if ($this->searchquery){
                echo '
            <script type="text/javascript">
            $(document).ready( function() {
                $(\'button[type="submit"]:contains("' . get_string("addnewsubmission", "mod_surveypro") . '")\').hide();
            });
            </script>';
            } else {
                echo '
            <script type="text/javascript">
            $(document).ready( function() {
                $(\'button[type="submit"]:contains("' . get_string("addnewsubmission", "mod_surveypro") . '")\').replaceWith(\'<div class="alert alert-success">All ' . $this->response_total . ' responses are done</div>\');
            });
            </script>';
            }
        }

        // If this is the output of a search add a way to show all submissions.
        if ($this->searchquery) {
            $url = new moodle_url('/mod/surveypro/view.php', array('id' => $this->cm->id));
            $label = get_string('showallsubmissions', 'mod_surveypro');
            echo $OUTPUT->box($OUTPUT->single_button($url, $label, 'get'), 'clearfix mdl-align');
        }
    }
}
