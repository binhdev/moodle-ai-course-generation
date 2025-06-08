<?php
namespace local_lessoncreator\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class generate_content_form extends \moodleform {
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('textarea', 'prompt', get_string('prompt', 'local_lessoncreator'), array('rows' => 5, 'cols' => 60));
        $mform->setType('prompt', PARAM_TEXT);
        $mform->addRule('prompt', null, 'required', null, 'client');
        $mform->addHelpButton('prompt', 'prompt_help', 'local_lessoncreator');

        $this->add_action_buttons(true, get_string('generate', 'local_lessoncreator'));
    }
}
?>