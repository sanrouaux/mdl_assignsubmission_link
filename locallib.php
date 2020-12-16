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
 * This file contains the definition for the library class for onlinetext submission plugin
 *
 * This class provides all the functionality for the new assign module.
 *
 * @package assignsubmission_onlinetext
 * @copyright 2012 NetSpot {@link http://www.netspot.com.au}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * library class for onlinetext submission plugin extending submission plugin base class
 *
 * @package assignsubmission_onlinetext
 * @copyright 2012 NetSpot {@link http://www.netspot.com.au}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_submission_link extends assign_submission_plugin {

    /**
     * Get the name of the online text submission plugin
     * @return string
     */
    public function get_name() {
        return get_string('pluginname', 'assignsubmission_link');
    }


    /**
     * Get link submission information from the database
     *
     * @param  int $submissionid
     * @return mixed
     */
    private function get_link_submission($submissionid) {
        global $DB;

        return $DB->get_record('assignsubmission_link', array('submission'=>$submissionid));
    }

    /**
     * Remove a submission.
     *
     * @param stdClass $submission The submission
     * @return boolean
     */
    public function remove(stdClass $submission) {
        global $DB;

        $submissionid = $submission ? $submission->id : 0;
        if ($submissionid) {
            $DB->delete_records('assignsubmission_link', array('submission' => $submissionid));
        }
        return true;
    }

    /**
     * Add form elements for settings. Form to create the submission
     *
     * @param mixed $submission can be null
     * @param MoodleQuickForm $mform
     * @param stdClass $data
     * @return true if elements were added to the form
     */
    public function get_form_elements($submission, MoodleQuickForm $mform, stdClass $data) {
       
        $elements = array();

        $submissionid = $submission ? $submission->id : 0;
        
        if (!isset($data->link)) {
            $data->link = '';
        }

        if ($submission) {
            $linksubmission = $this->get_link_submission($submission->id);
            if ($linksubmission) {
                $data->link = $linksubmission->link;
            }
        }
               
        $attributes = array('value' => $data->link);
        $mform->addElement('text', 'link_editor', $this->get_name(), $attributes);

        return true;
    }

    /**
     * Save data to the database and trigger plagiarism plugin,
     * if enabled, to scan the uploaded content via events trigger
     *
     * @param stdClass $submission object
     * @param stdClass $data
     * 
     * @return bool
     */
    public function save(stdClass $submission, stdClass $data) {
        global $USER, $DB;
                      
        $linksubmission = $this->get_link_submission($submission->id);

        // Here I have to include the link format validation
        $link = trim($data->link_editor);
        $validated = $this->validate_link_format($link);
        if (!$validated) {
            $this->set_error(get_string('formaterrormessage', 'assignsubmission_link'));
            return false;
        }
        
        //Prepare params for event trigger
        $params = array(
            'context' => context_module::instance($this->assignment->get_course_module()->id),
            'courseid' => $this->assignment->get_course()->id,
            'objectid' => $submission->id,
            'other' => array(
                'pathnamehashes' => array(),
                'content' => trim($data->link_editor)
            )
        );
        if (!empty($submission->userid) && ($submission->userid != $USER->id)) {
            $params['relateduserid'] = $submission->userid;
        }
        if ($this->assignment->is_blind_marking()) {
            $params['anonymous'] = 1;
        }
        $event = \assignsubmission_onlinetext\event\assessable_uploaded::create($params);
        $event->trigger();

        $groupname = null;
        $groupid = 0;
        // Get the group name as other fields are not transcribed in the logs and this information is important.
        if (empty($submission->userid) && !empty($submission->groupid)) {
            $groupname = $DB->get_field('groups', 'name', array('id' => $submission->groupid), MUST_EXIST);
            $groupid = $submission->groupid;
        } else {
            $params['relateduserid'] = $submission->userid;
        }
       
        $count = count_words($data->link_editor);

        // Unset the objectid and other field from params for use in submission events.
        unset($params['objectid']);
        unset($params['other']);
        $params['other'] = array(
            'submissionid' => $submission->id,
            'submissionattempt' => $submission->attemptnumber,
            'submissionstatus' => $submission->status,
            'onlinetextwordcount' => $count,
            'groupid' => $groupid,
            'groupname' => $groupname
        );
        
        if ($linksubmission) {
            $linksubmission->link = $data->link_editor;
            $params['objectid'] = $linksubmission->id;
            $updatestatus = $DB->update_record('assignsubmission_link', $linksubmission);
            $event = \assignsubmission_onlinetext\event\submission_updated::create($params);
            $event->set_assign($this->assignment);
            $event->trigger();
            return $updatestatus;
        } else {
            $linksubmission = new stdClass();
            $linksubmission->submission = $submission->id;
            $linksubmission->assignment = $this->assignment->get_instance()->id;
            $linksubmission->link = $data->link_editor;
            $linksubmission->id = $DB->insert_record('assignsubmission_link', $linksubmission);
            $params['objectid'] = $linksubmission->id;
            $event = \assignsubmission_onlinetext\event\submission_created::create($params);
            $event->set_assign($this->assignment);
            $event->trigger();
            return $linksubmission->id > 0;
        }
    }

    /**
     * Return a list of the text fields that can be imported/exported by this plugin
     *
     * @return array An array of field names and descriptions. (name=>description, ...)
     */
    public function get_editor_fields() {
        return array('link' => get_string('pluginname', 'assignsubmission_link'));
    }

    /**
     * Get the saved text content from the editor
     *
     * @param string $name
     * @param int $submissionid
     * @return string
     */
    public function get_editor_text($name, $submissionid) {
        if ($name == 'link') {
            $linksubmission = $this->get_link_submission($submissionid);
            if ($linksubmission) {
                return $linksubmission->link;
            }
        }
        return '';
    }

     /**
      * Display onlinetext word count in the submission status table
      *
      * @param stdClass $submission
      * @param bool $showviewlink - If the summary has been truncated set this to true
      * @return string
      */
    public function view_summary(stdClass $submission, & $showviewlink) {
        global $CFG;

        $linksubmission = $this->get_link_submission($submission->id);
        // Always show the view link.
        $showviewlink = true;

        if ($linksubmission) {
            // This contains the shortened version of the text plus an optional 'Export to portfolio' button.
            $text = $this->assignment->render_editor_content(ASSIGNSUBMISSION_ONLINETEXT_FILEAREA,
                                                             $linksubmission->submission,
                                                             $this->get_type(),
                                                             'link',
                                                             'assignsubmission_link', true);

            // The actual submission text.
            $link = trim($linksubmission->link);
            // The shortened version of the submission text.
            $shortlink = shorten_text($link, 140);

            $plagiarismlinks = '';

            if (!empty($CFG->enableplagiarism)) {
                require_once($CFG->libdir . '/plagiarismlib.php');

                $plagiarismlinks .= plagiarism_get_links(array('userid' => $submission->userid,
                    'content' => $link,
                    'cmid' => $this->assignment->get_course_module()->id,
                    'course' => $this->assignment->get_course()->id,
                    'assignment' => $submission->assignment));
            }
            // We compare the actual text submission and the shortened version. If they are not equal, we show the word count.
            if ($link != $shortlink) {
                $wordcount = get_string('numwords', 'assignsubmission_link', count_words($link));

                return $plagiarismlinks . $wordcount . $text;
            } else {
                return $plagiarismlinks . $text;
            }
        }
        return '';
    }

    /**
     * Display the saved text content from the editor in the view table
     *
     * @param stdClass $submission
     * @return string
     */
    public function view(stdClass $submission) {
        global $CFG;
        $result = '';

        $linksubmission = $this->get_link_submission($submission->id);

        if ($linksubmission) {

            // Render for portfolio API.
            $result .= $this->assignment->render_editor_content(ASSIGNSUBMISSION_ONLINETEXT_FILEAREA,
                                                                $linksubmission->submission,
                                                                $this->get_type(),
                                                                'link',
                                                                'assignsubmission_link');

            $plagiarismlinks = '';

            if (!empty($CFG->enableplagiarism)) {
                require_once($CFG->libdir . '/plagiarismlib.php');

                $plagiarismlinks .= plagiarism_get_links(array('userid' => $submission->userid,
                    'content' => trim($linksubmission->link),
                    'cmid' => $this->assignment->get_course_module()->id,
                    'course' => $this->assignment->get_course()->id,
                    'assignment' => $submission->assignment));
            }
        }

        return $plagiarismlinks . $result;
    }

    /**
     * Return true if this plugin can upgrade an old Moodle 2.2 assignment of this type and version.
     *
     * @param string $type old assignment subtype
     * @param int $version old assignment version
     * @return bool True if upgrade is possible
     */
    public function can_upgrade($type, $version) {
        if ($type == 'online' && $version >= 2011112900) {
            return true;
        }
        return false;
    }


    /**
     * Upgrade the settings from the old assignment to the new plugin based one
     *
     * @param context $oldcontext - the database for the old assignment context
     * @param stdClass $oldassignment - the database for the old assignment instance
     * @param string $log record log events here
     * @return bool Was it a success?
     */
    public function upgrade_settings(context $oldcontext, stdClass $oldassignment, & $log) {
        // No settings to upgrade.
        return true;
    }

    /**
     * Upgrade the submission from the old assignment to the new one
     *
     * @param context $oldcontext - the database for the old assignment context
     * @param stdClass $oldassignment The data record for the old assignment
     * @param stdClass $oldsubmission The data record for the old submission
     * @param stdClass $submission The data record for the new submission
     * @param string $log Record upgrade messages in the log
     * @return bool true or false - false will trigger a rollback
     */
    public function upgrade(context $oldcontext,
                            stdClass $oldassignment,
                            stdClass $oldsubmission,
                            stdClass $submission,
                            & $log) {
        global $DB;

        $onlinetextsubmission = new stdClass();
        $onlinetextsubmission->onlinetext = $oldsubmission->data1;
        $onlinetextsubmission->onlineformat = $oldsubmission->data2;

        $onlinetextsubmission->submission = $submission->id;
        $onlinetextsubmission->assignment = $this->assignment->get_instance()->id;

        if ($onlinetextsubmission->onlinetext === null) {
            $onlinetextsubmission->onlinetext = '';
        }

        if ($onlinetextsubmission->onlineformat === null) {
            $onlinetextsubmission->onlineformat = editors_get_preferred_format();
        }

        if (!$DB->insert_record('assignsubmission_onlinetext', $onlinetextsubmission) > 0) {
            $log .= get_string('couldnotconvertsubmission', 'mod_assign', $submission->userid);
            return false;
        }

        // Now copy the area files.
        $this->assignment->copy_area_files_for_upgrade($oldcontext->id,
                                                        'mod_assignment',
                                                        'submission',
                                                        $oldsubmission->id,
                                                        $this->assignment->get_context()->id,
                                                        'assignsubmission_onlinetext',
                                                        ASSIGNSUBMISSION_ONLINETEXT_FILEAREA,
                                                        $submission->id);
        return true;
    }

    /**
     * Formatting for log info
     *
     * @param stdClass $submission The new submission
     * @return string
     */
    public function format_for_log(stdClass $submission) {
        // Format the info for each submission plugin (will be logged).
        $linksubmission = $this->get_link_submission($submission->id);
        $linkloginfo = '';
        $linkloginfo .= get_string('numwordsforlog',
                                         'assignsubmission_link',
                                         count_words($linksubmission->link));

        return $linkloginfo;
    }

    /**
     * The assignment has been deleted - cleanup
     *
     * @return bool
     */
    public function delete_instance() {
        global $DB;
        $DB->delete_records('assignsubmission_link',
                            array('assignment'=>$this->assignment->get_instance()->id));

        return true;
    }

    /**
     * No text is set for this plugin
     *
     * @param stdClass $submission
     * @return bool
     */
    public function is_empty(stdClass $submission) {
        $linksubmission = $this->get_link_submission($submission->id);
        $wordcount = 0;

        if (isset($linksubmission->link)) {
            $wordcount = count_words(trim($linksubmission->link));
        }

        return $wordcount == 0;
    }

    /**
     * Determine if a submission is empty
     *
     * This is distinct from is_empty in that it is intended to be used to
     * determine if a submission made before saving is empty.
     *
     * @param stdClass $data The submission data
     * @return bool
     */
    public function submission_is_empty(stdClass $data) {
        if (!isset($data->link_editor)) {
            return false;
        }
        $wordcount = 0;

        if (isset($data->link_editor)) {
            $wordcount = count_words(trim((string)$data->link_editor));
        }
        return $wordcount == 0;
    }

    /**
     * Copy the student's submission from a previous submission. Used when a student opts to base their resubmission
     * on the last submission.
     * @param stdClass $sourcesubmission
     * @param stdClass $destsubmission
     */
    public function copy_submission(stdClass $sourcesubmission, stdClass $destsubmission) {
        global $DB;

        // Copy the files across (attached via the text editor).
        $contextid = $this->assignment->get_context()->id;
        $fs = get_file_storage();
        $files = $fs->get_area_files($contextid, 'assignsubmission_onlinetext',
                                     ASSIGNSUBMISSION_ONLINETEXT_FILEAREA, $sourcesubmission->id, 'id', false);
        foreach ($files as $file) {
            $fieldupdates = array('itemid' => $destsubmission->id);
            $fs->create_file_from_storedfile($fieldupdates, $file);
        }

        // Copy the assignsubmission_onlinetext record.
        $onlinetextsubmission = $this->get_link_submission($sourcesubmission->id);
        if ($onlinetextsubmission) {
            unset($onlinetextsubmission->id);
            $onlinetextsubmission->submission = $destsubmission->id;
            $DB->insert_record('assignsubmission_onlinetext', $onlinetextsubmission);
        }
        return true;
    }

    /**
     * Validate the link format 
     *
     * @param string $linktext Link submission text from editor
     * @return boolean 1 if link has an apropiatte format. Otherwise, 0
     */
    public function validate_link_format ($link) {
        $regexp = '/(https?:\/\/)(www\.)?[-a-zA-Z0-9@:%._\+~#=]{1,256}\.[a-zA-Z0-9()]{3,3}/';
        $result = preg_match($regexp, $link);
        return $result;
    }
}


