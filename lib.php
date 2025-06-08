<?php
defined('MOODLE_INTERNAL') || die();

function local_lessoncreator_extend_navigation_course($navigation, $course, $context)
{
    if (has_capability('local/lessoncreator:manage', $context)) {
        $url = new moodle_url('/local/lessoncreator/upload_syllabus.php', array('courseid' => $course->id));
        $navigation->add(
            get_string('createmenu', 'local_lessoncreator'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            null,
            new pix_icon('i/settings', '')
        );
    }
}