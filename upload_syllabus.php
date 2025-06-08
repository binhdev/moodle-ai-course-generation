<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');

$courseid = required_param('courseid', PARAM_INT);

$course = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/lessoncreator:manage', $context);

$PAGE->set_url('/local/lessoncreator/upload_syllabus.php', array('courseid' => $courseid));
$PAGE->set_context($context);
$PAGE->set_title(get_string('upload_syllabus', 'local_lessoncreator'));
$PAGE->set_heading($course->fullname);

$mform = new \local_lessoncreator\form\upload_syllabus_form();

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', array('id' => $courseid)));
} else if ($data = $mform->get_data()) {
    $fs = get_file_storage();
    $file = $mform->get_file_content('syllabusfile');
    $filename = $mform->get_new_filename('syllabusfile');

    // Lưu file tạm thời để xử lý
    $tempfile = tempnam(sys_get_temp_dir(), 'syllabus_') . '.docx';

    file_put_contents($tempfile, $file);

    // Chuyển hướng đến trang xử lý
    redirect(new moodle_url('/local/lessoncreator/process_syllabus.php', array('courseid' => $courseid, 'filepath' => $tempfile)));
}

echo $OUTPUT->header();
$mform->display();
echo $OUTPUT->footer();
?>