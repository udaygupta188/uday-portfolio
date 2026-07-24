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

// Try to find .env.local in common root locations
$env_paths = [
    __DIR__ . '/.env.local',                            // Current folder (freelance)
    __DIR__ . '/../.env.local',                         // Parent folder (public_html)
    __DIR__ . '/../../.env.local',                      // Grandparent folder (user root)
    $_SERVER['DOCUMENT_ROOT'] . '/.env.local',          // Document Root
    dirname($_SERVER['DOCUMENT_ROOT']) . '/.env.local'  // One level above Document Root
];

$env_loaded = false;
foreach ($env_paths as $path) {
    if (file_exists($path)) {
        loadEnv($path);
        $env_loaded = true;
        break;
    }
}

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

$subject = "Portfolio Contact: $name";

// Create beautiful HTML template
$html_body = "
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f7f6; margin: 0; padding: 40px 20px; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .header { background: #101319; color: #ffffff; padding: 24px 30px; }
        .header h2 { margin: 0; font-weight: 500; font-size: 18px; letter-spacing: 0.5px; display: flex; align-items: center; gap: 10px; }
        .badge { background: #37e0d6; color: #04140a; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }
        .content { padding: 30px; }
        .field { margin-bottom: 24px; }
        .label { font-size: 12px; text-transform: uppercase; color: #8991a3; font-weight: 600; letter-spacing: 1px; margin-bottom: 8px; }
        .value { font-size: 16px; color: #161a22; font-weight: 500; }
        .message-box { font-size: 15px; color: #161a22; background: #f8fafc; padding: 20px; border-radius: 8px; border: 1px solid #eef1f8; white-space: pre-wrap; line-height: 1.7; margin-top: 10px; }
        .footer { text-align: center; padding: 20px; font-size: 13px; color: #8991a3; background: #fafafa; border-top: 1px solid #eef1f8; }
        a { color: #0066cc; text-decoration: none; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h2><span class='badge'>New</span> Message from Portfolio</h2>
        </div>
        <div class='content'>
            <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom: 24px;'>
                <tr>
                    <td width='50%' valign='top'>
                        <div class='label'>Name</div>
                        <div class='value'>" . htmlspecialchars($name) . "</div>
                    </td>
                    <td width='50%' valign='top'>
                        <div class='label'>Email</div>
                        <div class='value'><a href='mailto:" . htmlspecialchars($email) . "'>" . htmlspecialchars($email) . "</a></div>
                    </td>
                </tr>
            </table>
            
            <div class='field'>
                <div class='label'>Project Details / Message</div>
                <div class='message-box'>" . htmlspecialchars($message) . "</div>
            </div>
            
            <div style='margin-top: 30px;'>
                <a href='mailto:" . htmlspecialchars($email) . "' style='background: #101319; color: #ffffff; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: 500; font-size: 14px; display: inline-block;'>Reply to " . explode(' ', htmlspecialchars($name))[0] . "</a>
            </div>
        </div>
        <div class='footer'>
            Sent securely from your portfolio (wipzent.com)
        </div>
    </div>
</body>
</html>
";

$body = $html_body;

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
    $headers .= "Content-Type: text/html; charset=UTF-8" . $crlf . $crlf;

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
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    if (mail($to_email, $subject, $body, $headers)) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to send via PHP mail(). Ensure .env.local is uploaded and credentials are set.']);
    }
}
