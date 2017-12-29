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
 * Contains class mod_surveypro\mod_surveypro_usertemplate_name
 *
 * @package   mod_surveypro
 * @copyright 2013 onwards kordan <kordan@mclink.it>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Class to prepare a usertemplate name for display and in-place editing
 *
 * @package   mod_surveypro
 * @copyright 2013 onwards kordan <kordan@mclink.it>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_surveypro_usertemplate_name extends \core\output\inplace_editable {
    /**
     * Constructor.
     *
     * @param stdClass $virtualtablerow
     */
    public function __construct($xmlfileid, $templatename) {
        $templatename = format_string($templatename);
        parent::__construct('mod_surveypro', 'usertemplate_name', $xmlfileid, true, $templatename, $templatename);
    }

    /**
     * Updates usertemplate name and returns instance of this object
     *
     * @param int $templateid
     * @param string $newtemplatename
     * @return static
     */
    public static function update($xmlfileid, $newtemplatename) {
        global $DB;

        $fs = get_file_storage();
        $xmlfile = $fs->get_file_by_id($xmlfileid);
        $filepath = $xmlfile->get_filepath();
        $oldtemplatename = $xmlfile->get_filename();
        if ( ($newtemplatename != $oldtemplatename) && (strlen($newtemplatename) > 0) ) {
            $xmlfile->rename($filepath, $newtemplatename);
        }

        $filerecord = $DB->get_record('files', array('id' => $xmlfileid), 'id, contextid', MUST_EXIST);
        $context = \context::instance_by_id($filerecord->contextid);
        \external_api::validate_context($context);

        return new static($xmlfileid, $newtemplatename);
    }
}
