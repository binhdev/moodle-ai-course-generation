<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/lesson/lib.php');
require_once($CFG->dirroot . '/mod/lesson/locallib.php');

$cmid = required_param('cmid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$pageid = optional_param('pageid', 0, PARAM_INT);
$prompt = optional_param('prompt', '', PARAM_TEXT);

$course = get_course($courseid);
$cm = get_coursemodule_from_id('lesson', $cmid, $courseid, false, MUST_EXIST);
$context = context_module::instance($cm->id);
$lesson = new lesson($cm);

require_login($course);
require_capability('local/lessoncreator:manage', $context);

$PAGE->set_url('/local/lessoncreator/ai_generate_page_content.php', ['cmid' => $cmid, 'courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('aigenerate', 'local_lessoncreator'));
$PAGE->set_heading($course->fullname);

// Lấy danh sách content pages (qtype = LESSON_PAGE_CONTENT)
$content_pages = $DB->get_records_select('lesson_pages', 'lessonid = ? AND qtype = ?', [$lesson->id, LESSON_PAGE_CONTENT]);

// Form chọn page và nhập prompt
$mform = new moodleform(null, ['cmid' => $cmid, 'courseid' => $courseid]);
$select = $mform->addElement('select', 'pageid', 'Select Content Page');
foreach ($content_pages as $page) {
    $select->addOption(format_string($page->title), $page->id);
}
$mform->addElement('textarea', 'prompt', 'AI Prompt', ['rows' => 5, 'cols' => 60]);
$mform->addElement('submit', 'submitbutton', 'Generate Content');

// Xử lý form
if ($data = $mform->get_data()) {
    $pageid = $data->pageid;
    $prompt = $data->prompt;

    // Lấy API Key
    $api_key = !empty($CFG->gemini_api_key) ? $CFG->gemini_api_key : 'YOUR_GEMINI_API_KEY';

    // Gọi Gemini API
    try {
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $api_key);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $payload = json_encode([
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ]
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code != 200) {
            throw new Exception('API request failed with HTTP code ' . $http_code);
        }

        $result = json_decode($response, true);
        $generated_content = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

        // Cập nhật nội dung page
        if ($pageid && $generated_content) {
            $page = $DB->get_record('lesson_pages', ['id' => $pageid, 'lessonid' => $lesson->id]);
            if ($page) {
                $page->contents = $generated_content;
                $page->timemodified = time();
                $DB->update_record('lesson_pages', $page);
                echo $OUTPUT->notification('Content updated successfully for page: ' . format_string($page->title), 'notifysuccess');
            }
        }
    } catch (Exception $e) {
        echo $OUTPUT->notification('Error generating content: ' . $e->getMessage(), 'notifyerror');
    }
}

// Xuất nội dung
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('aigenerate', 'local_lessoncreator'));
echo '<p>Generate AI content for a content page in Lesson: ' . format_string($cm->name) . '</p>';
$mform->display();
echo $OUTPUT->footer();
?>