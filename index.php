<?php
require_once(__DIR__ . '/../../config.php');

$PAGE->set_url('/local/lessoncreator/index.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('pluginname', 'local_lessoncreator'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_lessoncreator'));
echo $OUTPUT->footer();
?>