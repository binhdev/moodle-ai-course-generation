<?php
require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);

$course = get_course($courseid);
$cm = get_coursemodule_from_id('lesson', $cmid, $courseid, false, MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course);
require_capability('local/lessoncreator:manage', $context);

$PAGE->set_url('/local/lessoncreator/custom_lesson_settings.php', ['cmid' => $cmid, 'courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('customlessonsettings', 'local_lessoncreator'));
$PAGE->set_heading($course->fullname);

// Bắt đầu xuất nội dung
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('customlessonsettings', 'local_lessoncreator'));

// Nội dung mẫu
echo '<p>Đây là trang cài đặt tùy chỉnh cho Lesson: ' . format_string($cm->name) . '</p>';
echo '<p>Course ID: ' . $courseid . '</p>';
echo '<p>Module ID: ' . $cmid . '</p>';

// Ví dụ: Form tùy chỉnh (có thể mở rộng)
$mform = new \moodleform(null, ['cmid' => $cmid, 'courseid' => $courseid]);
$mform->addElement('text', 'customfield', 'Custom Field');
$mform->setType('customfield', PARAM_TEXT);
$mform->addElement('submit', 'submitbutton', 'Save');
$mform->display();

// Kết thúc
echo $OUTPUT->footer();
?>