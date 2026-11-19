<?php
declare(strict_types=1);

function enquiryRedirect(string $status): void
{
    header('Location: /cms/Enquiry?' . $status, true, 303);
    exit;
}

function enquiryField(string $name, int $maxLength, bool $multiline = false): ?string
{
    $value = $_POST[$name] ?? '';
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    if ($value === null) {
        return null;
    }

    if ($multiline) {
        $value = preg_replace("/\n{4,}/", "\n\n\n", $value);
        if ($value === null) {
            return null;
        }
    } else {
        $value = str_replace(["\n", "\t"], ' ', $value);
    }

    return strlen($value) <= $maxLength ? $value : null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Method not allowed.';
    exit;
}

$honeypot = $_POST['website'] ?? '';
if (!is_string($honeypot)) {
    enquiryRedirect('err=invalid');
}
if (trim($honeypot) !== '') {
    enquiryRedirect('err=spam');
}

$startedAt = $_POST['t'] ?? '';
if (!is_string($startedAt) || !ctype_digit($startedAt)) {
    enquiryRedirect('err=retry');
}
$startedAt = (int) $startedAt;
$now = (int) floor(microtime(true) * 1000);
// Visitor clocks can differ from the server's, so only reject submissions that are clearly too fast.
if ($startedAt <= 0 || ($now - $startedAt >= 0 && $now - $startedAt < 3000)) {
    enquiryRedirect('err=retry');
}

$fields = [
    'name' => enquiryField('name', 200),
    'company' => enquiryField('company', 200),
    'country' => enquiryField('country', 100),
    'address' => enquiryField('address', 1000),
    'postal' => enquiryField('postal', 20),
    'tel' => enquiryField('tel', 100),
    'email' => enquiryField('email', 254),
    'comments' => enquiryField('comments', 10000, true),
];
if (in_array(null, $fields, true)) {
    enquiryRedirect('err=invalid');
}

foreach (['name', 'tel', 'email', 'comments'] as $requiredField) {
    if ($fields[$requiredField] === '') {
        enquiryRedirect('err=missing');
    }
}

$email = str_replace(["\r", "\n"], '', $fields['email']);
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    enquiryRedirect('err=email');
}

$attachment = null;
if (isset($_FILES['attachment'])) {
    $upload = $_FILES['attachment'];
    if (!is_array($upload) || !isset($upload['error'], $upload['tmp_name'], $upload['size'], $upload['name'])) {
        enquiryRedirect('err=file');
    }
    if ($upload['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($upload['error'] !== UPLOAD_ERR_OK
            || !is_string($upload['tmp_name'])
            || !is_uploaded_file($upload['tmp_name'])
            || !is_string($upload['name'])
            || strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'pdf') {
            enquiryRedirect('err=file');
        }

        $fileSize = filesize($upload['tmp_name']);
        if ($fileSize === false || $fileSize < 1 || $fileSize > 4 * 1024 * 1024) {
            enquiryRedirect('err=file');
        }
        if (!class_exists('finfo')) {
            error_log('Enquiry upload rejected: PHP fileinfo extension is unavailable.');
            enquiryRedirect('err=file');
        }

        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        if ($fileInfo->file($upload['tmp_name']) !== 'application/pdf') {
            enquiryRedirect('err=file');
        }

        $attachment = file_get_contents($upload['tmp_name']);
        if ($attachment === false) {
            error_log('Enquiry upload could not be read.');
            enquiryRedirect('err=file');
        }
    }
}

$message = implode("\r\n", [
    'Name: ' . $fields['name'],
    'Company: ' . $fields['company'],
    'Country: ' . $fields['country'],
    'Address: ' . $fields['address'],
    'Postal Code: ' . $fields['postal'],
    'Telephone: ' . $fields['tel'],
    'Email: ' . $email,
    '',
    'Enquiry:',
    $fields['comments'],
]);

$headers = [
    'From: Teck Leong Industries Pte Ltd <no-reply@copper.asia>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
];
if ($attachment === null) {
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
} else {
    $boundary = '=_copper_asia_' . bin2hex(random_bytes(16));
    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
    $message = '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $message . "\r\n"
        . '--' . $boundary . "\r\n"
        . "Content-Type: application/pdf; name=\"enquiry.pdf\"\r\n"
        . "Content-Disposition: attachment; filename=\"enquiry.pdf\"\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($attachment))
        . '--' . $boundary . "--\r\n";
}

if (!function_exists('mail')
    || !mail(
        'sales@copper.asia',
        'Website enquiry - copper.asia',
        $message,
        implode("\r\n", $headers),
        '-fno-reply@copper.asia'
    )) {
    error_log('Enquiry mail could not be accepted by the configured mail transport.');
    enquiryRedirect('err=mail');
}

enquiryRedirect('sent=1');
