<?php
namespace local_lessoncreator\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class upload_syllabus_form extends \moodleform {
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);
        $courseid = optional_param('courseid', 0, PARAM_INT);
        $mform->setDefault('courseid', $courseid);

        $mform->addElement('filepicker', 'syllabusfile', get_string('syllabusfile', 'local_lessoncreator'), null, array('accepted_types' => array('.pdf', '.docx')));
        $mform->addRule('syllabusfile', null, 'required');

        $this->add_action_buttons(false, get_string('upload', 'local_lessoncreator'));
    }
}
?>