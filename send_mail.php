<?php
// Function to load .env.local file
function loadEnv($path)
{
    if (!file_exists($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0)
            continue;
        $parts = explode('=', $line, 2);
        if (count($parts) == 2) {
            $_ENV[trim($parts[0])] = trim($parts[1]);
        }
    }
}

// Load credentials from .env.local
loadEnv(__DIR__ . '/.env.local');

// Set JSON response header
header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// Get form fields
$name = $_POST['name'] ?? '';
$email = $_POST['email'] ?? '';
$message = $_POST['message'] ?? '';

if (empty($name) || empty($email) || empty($message)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing fields']);
    exit;
}

// Read variables from environment
$smtp_host = $_ENV['MAIL_HOST'] ?? '';
$smtp_port = $_ENV['MAIL_PORT'] ?? 587;
$smtp_user = $_ENV['MAIL_USERNAME'] ?? '';
$smtp_pass = $_ENV['MAIL_PASSWORD'] ?? '';
$to_email = $_ENV['RECEIVER_EMAIL'] ?? $smtp_user; // Default to sending to yourself
$from_email = $smtp_user; // Hostinger requires the 'From' address to match the authenticated user

$subject = "New Contact from: $name";
$body = "Name: $name\nEmail: $email\n\nMessage:\n$message";

// If SMTP host and credentials are configured, use authenticated SMTP sockets
if ($smtp_host) {
    if (!$smtp_user || !$smtp_pass) {
        http_response_code(500);
        echo json_encode(['error' => 'SMTP_HOST is set, but MAIL_USERNAME or MAIL_PASSWORD is missing in .env.local']);
        exit;
    }

    $crlf = "\r\n";
    $headers = "From: $name <$from_email>" . $crlf;
    $headers .= "Reply-To: $email" . $crlf;
    $headers .= "Subject: $subject" . $crlf;
    $headers .= "Content-Type: text/plain; charset=UTF-8" . $crlf . $crlf;

    $body .= $crlf . ".";

    // Connect to SMTP server
    $socket = @fsockopen($smtp_host, $smtp_port, $errno, $errstr, 15);
    if (!$socket) {
        http_response_code(500);
        echo json_encode(['error' => "Could not connect to SMTP server: $smtp_host on port $smtp_port"]);
        exit;
    }

    function server_parse($socket, $expected_response)
    {
        $server_response = '';
        while (substr($server_response, 3, 1) != ' ') {
            if (!($server_response = fgets($socket, 256))) {
                return false;
            }
        }
        if (!(substr($server_response, 0, 3) == $expected_response)) {
            return false;
        }
        return true;
    }

    server_parse($socket, '220');

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    fputs($socket, "EHLO " . $host . $crlf);
    server_parse($socket, '250');

    fputs($socket, "AUTH LOGIN" . $crlf);
    server_parse($socket, '334');
    fputs($socket, base64_encode($smtp_user) . $crlf);
    server_parse($socket, '334');
    fputs($socket, base64_encode($smtp_pass) . $crlf);
    server_parse($socket, '235');

    fputs($socket, "MAIL FROM: <$from_email>" . $crlf);
    server_parse($socket, '250');

    fputs($socket, "RCPT TO: <$to_email>" . $crlf);
    server_parse($socket, '250');

    fputs($socket, "DATA" . $crlf);
    server_parse($socket, '354');

    fputs($socket, $headers . $body . $crlf);
    server_parse($socket, '250');

    fputs($socket, "QUIT" . $crlf);
    fclose($socket);

    echo json_encode(['success' => true]);
} else {
    // Fallback to PHP's built-in mail() function for standard shared hosting
    $headers = "From: $from_email\r\n";
    $headers .= "Reply-To: $email\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    if (mail($to_email, $subject, $body, $headers)) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to send via PHP mail(). Ensure .env.local is uploaded and credentials are set.']);
    }
}
