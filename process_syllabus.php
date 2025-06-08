<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once(__DIR__ . '/vendor/autoload.php'); // Tải thư viện Composer
require_once($CFG->dirroot . '/mod/lesson/lib.php');
require_once($CFG->dirroot . '/mod/lesson/locallib.php');

use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;

global $DB;

$courseid = required_param('courseid', PARAM_INT);
$filepath = required_param('filepath', PARAM_PATH);
$course = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/lessoncreator:manage', $context);
require_capability('moodle/course:manageactivities', $context);
require_capability('mod/lesson:addinstance', $context);
require_capability('mod/assign:addinstance', $context);
require_capability('mod/quiz:addinstance', $context);

$PAGE->set_url('/local/lessoncreator/process_syllabus.php', array('courseid' => $courseid));
$PAGE->set_context($context);
$PAGE->set_title(get_string('process_syllabus', 'local_lessoncreator'));

// Hàm đọc nội dung file PDF hoặc Word
function extract_file_content($filepath)
{
    $extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

    if ($extension === 'pdf') {
        $parser = new PdfParser();
        $pdf = $parser->parseFile($filepath);
        return $pdf->getText();
    } elseif ($extension === 'docx') {
        $phpWord = WordIOFactory::load($filepath);
        $text = '';
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $text .= $element->getText() . "\n";
                }
            }
        }
        return $text;
    }

    throw new Exception('Unsupported file type');
}

// Hàm gọi API Gemini
function call_gemini_api($content)
{
    $api_key = 'AIzaSyBokxNX3D5J1YixNQxTrDl_ODkmR-YUl6Y'; // Thay bằng API Key thực tế
    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';

    // Prompt yêu cầu Gemini trích xuất JSON
    $prompt = <<<EOD
    Phân tích nội dung đề cương môn học sau và trích xuất thông tin thành JSON với cấu trúc:
    [
        {
            "session": "Tên buổi học",
            "target": "Mục tiêu ...",
            "pages" => [
                {
                    "pageorder" => 1,
                    "title" => "Chapter 1"
                },
                {
                    "pageorder" => 2,
                    "title" => "Chapter 2"
                }
            ]
        }
    ]
    Nội dung đề cương:
    $content
    EOD;

    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ]
    ];

    $ch = curl_init($endpoint . '?key=' . $api_key);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        throw new Exception('API request failed: ' . curl_error($ch));
    }
    curl_close($ch);

    $result = json_decode($response, true);
    if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        throw new Exception('Invalid API response');
    }

    // Gemini trả về JSON trong text, cần parse lại
    $json_text = $result['candidates'][0]['content']['parts'][0]['text'];
    // Loại bỏ định dạng code block nếu có
    $json_text = preg_replace('/^```json\n|\n```$/', '', $json_text);
    // var_dump($json_text);
    // die();
    return json_decode($json_text, true);
}

// Đọc nội dung file
try {
    $file_content = extract_file_content($filepath);
} catch (Exception $e) {
    print_error('Lỗi khi đọc file: ' . $e->getMessage());
}

// Gọi API Gemini
try {
    $json_data = call_gemini_api($file_content);

    // $json_text = <<<EOD
    // [
    //     {
    //         "session": "Chương 1",
    //         "pages": [
    //             {
    //                 "pageorder": 1,
    //                 "title": "Thông tin và xử lý thông tin"
    //             },
    //             {
    //                 "pageorder": 2,
    //                 "title": "Hệ thống tính và biểu diễn thông tin trong máy"
    //             },
    //             {
    //                 "pageorder": 3,
    //                 "title": "Cấu trúc tổng quan phần cứng máy tính"
    //             },
    //             {
    //                 "pageorder": 4,
    //                 "title": "Tổng quan về phần mềm"
    //             },
    //             {
    //                 "pageorder": 5,
    //                 "title": "Khái niệm về mạng Internet"
    //             },
    //             {
    //                 "pageorder": 6,
    //                 "title": "Khái niệm hệ điều hành"
    //             }
    //         ]
    //     }
    // ]
    // EOD;
    // $json_data = json_decode($json_text, true);
    // var_dump($json_data);
    // die();
} catch (Exception $e) {
    print_error('Lỗi khi gọi API Gemini: ' . $e->getMessage());
}

// Hàm tạo trang trong Lesson
function add_lesson_page($lesson, $page_data)
{
    global $DB;

    $page = new stdClass();
    $page->lessonid = $lesson->id;
    $page->prevpageid = 0;
    $page->nextpageid = 0;
    $page->qtype = 20; // Giá trị của LESSON_PAGE_CONTENT
    $page->timecreated = time();
    $page->title = $page_data['title']; // Kết hợp pageorder và title
    $page->contents = '<p>' . htmlspecialchars($page_data['title']) . '</p>'; // Nội dung tạm thời
    $page->pageorder = $page_data['pageorder'];

    // Lưu trang vào DB
    $pageid = $DB->insert_record('lesson_pages', $page);

    return $pageid;
}

// Lấy ID của module Lesson từ bảng mdl_modules
$moduleLesson = $DB->get_record('modules', ['name' => 'lesson']);
if (!$moduleLesson) {
    print_error('Module Lesson không tồn tại trong bảng mdl_modules. Vui lòng kiểm tra cài đặt module Lesson.');
}
$moduleLessonId = $moduleLesson->id;

// Lấy ID của module Page từ bảng mdl_modules
$modulePage = $DB->get_record('modules', ['name' => 'page']);
if (!$modulePage) {
    print_error('Module Page không tồn tại trong bảng mdl_modules. Vui lòng kiểm tra cài đặt module Page.');
}
$modulePageId = $modulePage->id;

// Lấy ID của module Assign từ bảng mdl_modules
$moduleAssign = $DB->get_record('modules', ['name' => 'assign']);
if (!$moduleAssign) {
    print_error('Module assign không tồn tại trong bảng mdl_modules. Vui lòng kiểm tra cài đặt module assign.');
}
$moduleAssignId = $moduleAssign->id;

// Lấy ID của module Quiz từ bảng mdl_modules
$moduleQuiz = $DB->get_record('modules', ['name' => 'quiz']);
if (!$moduleQuiz) {
    print_error('Module quiz không tồn tại trong bảng mdl_modules. Vui lòng kiểm tra cài đặt module quiz.');
}
$moduleQuizId = $moduleQuiz->id;

// Tạo Lesson từ JSON
foreach ($json_data as $session_data) {
    // Tạo section (topic)
    $section = course_create_section($course->id);
    $sectionid = $section->section;

    // Cập nhật tên section
    course_update_section($course->id, $section, ['name' => $session_data['session']]);

    // Thêm Page module làm trang Giới thiệu cho section
    $page = new stdClass();
    $page->course = $course->id;
    $page->name = 'Giới thiệu ';
    $page->intro = 'Chào mừng đến với. Đây là trang giới thiệu chung cho chủ đề.';
    $page->content = '<h3>Mục tiêu</h3><p>' . $session_data['target'] . '</p>'; // Nội dung HTML
    $page->introformat = FORMAT_HTML;
    $page->contentformat = FORMAT_HTML;
    $page->modulename = 'page';
    if ($page->modulename !== 'page') {
        print_error('Lỗi: Modulename không hợp lệ, chỉ hỗ trợ page, nhận được: ' . $page->modulename);
    }
    $page->module = $modulePageId;
    $page->section = $sectionid; // Gán vào section
    $page->visible = 1;
    // Nạp thư viện module Lesson
    include_modulelib($page->modulename);
    
    try {
        add_moduleinfo($page, $course);
    } catch (\Throwable $th) {
        var_dump($th);
        die();
    }
    

    /**
     * Tạo Lesson cho session
     *  */ 
    $lesson = new stdClass();
    $lesson->course = $course->id;
    $lesson->name = 'Nội dung';
    $lesson->intro = 'Nội dung cho ' . htmlspecialchars($session_data['session']);
    $lesson->introformat = FORMAT_HTML;
    $lesson->modulename = 'lesson';
    if ($lesson->modulename !== 'lesson') {
        print_error('Lỗi: Modulename không hợp lệ, chỉ hỗ trợ lesson, nhận được: ' . $lesson->modulename);
    }
    $lesson->module = $moduleLessonId;
    $lesson->section = $sectionid;
    $lesson->visible = 1;

    // Các trường bắt buộc để tránh lỗi undefined property
    $lesson->mediafile = 0; // Không sử dụng media file
    $lesson->available = 0; // Có sẵn ngay lập tức
    $lesson->deadline = 0; // Không có deadline
    $lesson->practice = 0; // Không phải practice lesson
    $lesson->grade = 0; // Không chấm điểm
    $lesson->timemodified = time();
    // Các trường bổ sung để đảm bảo tương thích
    $lesson->maxattempts = 0; // Số lần thử tối đa (0 = không giới hạn)
    $lesson->password = ''; // Không có mật khẩu
    $lesson->dependency = 0; // Không phụ thuộc lesson khác
    $lesson->conditions = ''; // Không có điều kiện
    $lesson->maxanswers = 4; // Số câu trả lời tối đa mặc định
    $lesson->maxpages = 0; // Không giới hạn số trang
    $lesson->retake = 1; // Cho phép làm lại
    $lesson->slideshow = 0; // Không dùng slideshow
    $lesson->progression = 0; // Không dùng progression bar
    $lesson->custom = 0; // Không dùng điểm tùy chỉnh
    $lesson->ongoing = 0; // Không hiển thị điểm liên tục
    $lesson->usemaxgrade = 0; // Dùng điểm cao nhất
    $lesson->maxtime = 0; // Không giới hạn thời gian
    $lesson->allowofflineattempts = 0; // Không cho phép offline

    // Nạp thư viện module Lesson
    include_modulelib($lesson->modulename);

    // Thêm Lesson vào khóa học
    try {
        $lesson_module = add_moduleinfo($lesson, $course);
    } catch (Throwable $e) {
        var_dump($e);
        die();
    }

    // Lấy bản ghi Lesson
    $lesson_record = $DB->get_record('lesson', ['id' => $lesson_module->instance]);
    if (!$lesson_record) {
        print_error('Lỗi khi lấy thông tin Lesson: ' . $session_data['session']);
    }

    // Sắp xếp pages theo pageorder
    usort($session_data['pages'], function ($a, $b) {
        return $a['pageorder'] - $b['pageorder'];
    });

    // Thêm các trang vào Lesson
    $prev_pageid = 0;
    foreach ($session_data['pages'] as $page_data) {
        $pageid = add_lesson_page($lesson_record, $page_data);

        // Cập nhật liên kết prev/next
        if ($prev_pageid) {
            $DB->set_field('lesson_pages', 'nextpageid', $pageid, ['id' => $prev_pageid]);
            $DB->set_field('lesson_pages', 'prevpageid', $prev_pageid, ['id' => $pageid]);
        }
        $prev_pageid = $pageid;
    }

    /**
     * Thêm assign từ JSON vào section
     * */
    $assign = new stdClass();
    $assign->course = $course->id;
    $assign->name = 'Bài tập';
    $assign->intro = 'Bài tập';
    $assign->introformat = FORMAT_HTML;
    $assign->modulename = 'assign';
    if ($assign->modulename !== 'assign') {
        print_error('Lỗi: Modulename không hợp lệ, chỉ hỗ trợ assign, nhận được: ' . $assign->modulename);
    }
    $assign->module = $moduleAssignId;
    $assign->section = $sectionid;
    $assign->visible = 1;
    $assign->duedate = time() + (7 * 24 * 60 * 60); // Hạn nộp: 7 ngày sau
    $assign->allowsubmissionsfromdate = time(); // Cho phép nộp ngay
    $assign->submissiondrafts = 0; // Không lưu bản nháp
    $assign->requiresubmissionstatement = 0; // Không yêu cầu xác nhận
    $assign->sendnotifications = 0; // Không gửi thông báo đến người chấm
    $assign->sendlatenotifications = 0; // Không gửi thông báo nộp muộn
    $assign->sendstudentnotifications = 1; // Gửi thông báo đến học viên
    $assign->grade = 100; // Điểm tối đa
    $assign->timemodified = time();
    $assign->completionsubmit = 0; // Không yêu cầu nộp để hoàn thành
    $assign->cutoffdate = 0; // Không giới hạn thời gian nộp
    $assign->gradingduedate = 0; // Không có hạn chấm bài
    $assign->teamsubmission = 0; // Không nộp theo nhóm
    $assign->requireallteammemberssubmit = 0; // Không yêu cầu tất cả thành viên nhóm nộp
    $assign->blindmarking = 0; // Không ẩn danh
    $assign->markingworkflow = 0; // Không dùng quy trình chấm điểm
    $assign->markingallocation = 0; // Không dùng phân bổ chấm điểm

    // Nạp thư viện module assign
    include_modulelib($assign->modulename);
    try {
        $assign = add_moduleinfo($assign, $course);
        if (empty($assign)) {
            debugging('add_moduleinfo trả về null cho assign module', DEBUG_DEVELOPER);
        }
    } catch (Exception $e) {
        debugging('Lỗi khi thêm assign module: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }

    /**
     * Thêm Quiz vào section
    */ 
    $quiz = new stdClass();
    $quiz->course = $course->id;
    $quiz->name = 'Kiểm tra';
    $quiz->intro = 'Kiểm tra cho ';
    $quiz->introformat = FORMAT_HTML;
    $quiz->modulename = 'quiz';
    if ($quiz->modulename !== 'quiz') {
        print_error('Lỗi: Modulename không hợp lệ, chỉ hỗ trợ quiz, nhận được: ' . $quiz->modulename);
    }
    $quiz->module = $moduleQuizId;
    $quiz->section = $sectionid;
    $quiz->visible = 1;
    $quiz->timeopen = 0; // Không giới hạn thời gian mở
    $quiz->timeclose = 0; // Không giới hạn thời gian đóng
    $quiz->timelimit = 0; // Không giới hạn thời gian làm bài
    $quiz->overduehandling = 'autosubmit'; // Tự động nộp khi quá hạn
    $quiz->graceperiod = 0; // Không có thời gian ân hạn
    $quiz->preferredbehaviour = 'deferredfeedback'; // Phản hồi sau khi nộp
    $quiz->canredoquestions = 0; // Không cho phép làm lại câu hỏi
    $quiz->attempts = 1; // Chỉ cho phép làm 1 lần
    $quiz->attemptonlast = 0; // Không dựa trên lần làm trước
    $quiz->grademethod = 1; // Điểm cao nhất
    $quiz->decimalpoints = 2; // 2 chữ số thập phân cho điểm
    $quiz->questiondecimalpoints = -1; // Dùng decimalpoints
    $quiz->reviewattempt = 0; // Không xem lại bài làm
    $quiz->reviewcorrectness = 0; // Không xem lại tính đúng sai
    $quiz->reviewmarks = 0; // Không xem lại điểm
    $quiz->reviewspecificfeedback = 0; // Không xem lại phản hồi cụ thể
    $quiz->reviewgeneralfeedback = 0; // Không xem lại phản hồi chung
    $quiz->reviewrightanswer = 0; // Không xem lại đáp án đúng
    $quiz->reviewoverallfeedback = 0; // Không xem lại phản hồi tổng thể
    $quiz->questionsperpage = 0; // Không giới hạn câu hỏi mỗi trang
    $quiz->navmethod = 'free'; // Điều hướng tự do
    $quiz->shuffleanswers = 1; // Trộn đáp án
    $quiz->sumgrades = 0; // Tổng điểm (sẽ cập nhật sau khi thêm câu hỏi)
    $quiz->grade = 10; // Điểm tối đa của Quiz
    $quiz->timecreated = time();
    $quiz->timemodified = time();
    $quiz->quizpassword = ''; // Không yêu cầu mật khẩu
    $quiz->subnet = ''; // Không giới hạn mạng
    $quiz->browsersecurity = '-'; // Không yêu cầu bảo mật trình duyệt
    $quiz->delay1 = 0; // Không có độ trễ giữa lần 1 và 2
    $quiz->delay2 = 0; // Không có độ trễ giữa các lần sau
    $quiz->showuserpicture = 0; // Không hiển thị ảnh người dùng
    $quiz->showblocks = 0; // Không hiển thị block

    // Nạp thư viện module Quiz
    include_modulelib($quiz->modulename);

    try {
        $quiz = add_moduleinfo($quiz, $course);
        if (empty($quiz)) {
            debugging('add_moduleinfo trả về null cho Quiz module', DEBUG_DEVELOPER);
        }
    } catch (Exception $e) {
        debugging('Lỗi khi thêm Quiz module: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
}

// Xóa file tạm
unlink($filepath);

purge_caches();

redirect(new moodle_url('/course/view.php', ['id' => $course->id]), get_string('success', 'local_lessoncreator'));
