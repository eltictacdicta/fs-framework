<?php

/**
 * PHPMailer Compatibility Layer
 * Map global classes to namespaced PHPMailer 6.x classes
 */

if (!class_exists('PHPMailer')) {
    class_alias('PHPMailer\PHPMailer\PHPMailer', 'PHPMailer');
}

if (!class_exists('SMTP')) {
    class_alias('PHPMailer\PHPMailer\SMTP', 'SMTP');
}

if (!class_exists('phpmailerException')) {
    class_alias('PHPMailer\PHPMailer\Exception', 'phpmailerException');
}
