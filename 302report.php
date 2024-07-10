<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';
require '/home/vonwallace/config.php';

// Configuration
$logFile = '/var/log/virtualmin/vonwallace.com_access_log';
$reportFile = 'top_302_errors.html';
$topErrorsCount = 100;
$ipstackApiKey = '';

// Get current date and time
$currentDate = date('Y-m-d H:i:s');

// Function to parse log lines and extract 302 errors
function parse302Errors($line) {
    if (strpos($line, '" 302 ') !== false) {
        preg_match('/^(\S+) .* "([^"]+)" 302 /', $line, $matches);
        if (count($matches) >= 3) {
            return [
                'ip' => $matches[1],
                'request' => $matches[2]
            ];
        }
    }
    return null;
}

// Function to get geolocation data using ipstack
function get_geolocation_data($ip, $apiKey) {
    $url = "http://api.ipstack.com/{$ip}?access_key={$apiKey}";
    $response = file_get_contents($url);
    $data = json_decode($response, true);
    if ($data && isset($data['city']) && isset($data['region_name']) && isset($data['country_name'])) {
        return [
            'city' => $data['city'],
            'region' => $data['region_name'],
            'country' => $data['country_name']
        ];
    }
    return [
        'city' => 'Unknown',
        'region' => 'Unknown',
        'country' => 'Unknown'
    ];
}

// Read the log file
$errors = [];
$requestCounts = [];
$handle = fopen($logFile, 'r');
if ($handle) {
    while (($line = fgets($handle)) !== false) {
        $error = parse302Errors($line);
        if ($error) {
            $key = $error['ip'] . ' - ' . $error['request'];
            if (!isset($errors[$key])) {
                $errors[$key] = 1;
            } else {
                $errors[$key]++;
            }
            if (!isset($requestCounts[$error['request']])) {
                $requestCounts[$error['request']] = 1;
            } else {
                $requestCounts[$error['request']]++;
            }
        }
    }
    fclose($handle);
}

// Sort errors by frequency
arsort($errors);

// Get the top errors and their unique IPs
$topErrors = array_slice($errors, 0, $topErrorsCount, true);
$uniqueIPs = array_unique(array_map(function($key) {
    return explode(' - ', $key)[0];
}, array_keys($topErrors)));

// Get geolocation data for unique IPs in top errors
$geolocations = [];
foreach ($uniqueIPs as $ip) {
    $geolocations[$ip] = get_geolocation_data($ip, $ipstackApiKey);
}

// Generate HTML report
$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Top {$topErrorsCount} 302 Errors Report</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        h1, h2 {
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #3498db;
            color: white;
        }
        tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        tr:hover {
            background-color: #e0e0e0;
        }
    </style>
</head>
<body>
    <h1>Top {$topErrorsCount} 302 Errors</h1>
    <p><strong>Explanation of columns:</strong></p>
    <ul>
        <li><strong>Occurrences:</strong> Number of times this specific IP made this specific request and got a 302 error.</li>
        <li><strong>Request Count:</strong> Total number of times this specific request resulted in a 302 error from any IP address.</li>
    </ul>
    <table>
        <tr>
            <th>Rank</th>
            <th>Occurrences</th>
            <th>IP Address</th>
            <th>Location</th>
            <th>Request</th>
            <th>Request Count</th>
        </tr>
HTML;

$count = 0;
foreach ($topErrors as $key => $frequency) {
    $count++;
    list($ip, $request) = explode(' - ', $key, 2);
    $location = $geolocations[$ip];
    $locationString = "{$location['city']}, {$location['region']}, {$location['country']}";
    $html .= "<tr>
                <td>{$count}</td>
                <td>{$frequency}</td>
                <td>" . htmlspecialchars($ip) . "</td>
                <td>{$locationString}</td>
                <td>" . htmlspecialchars($request) . "</td>
                <td>{$requestCounts[$request]}</td>
              </tr>";
}

$html .= <<<HTML
    </table>
    <p>Report generated on: {$currentDate}</p>
</body>
</html>
HTML;

// Write HTML report to file
file_put_contents($reportFile, $html);

// Create a new PHPMailer instance
$mail = new PHPMailer(true);

try {
    // Server settings
    $mail->isSMTP();
    $mail->Host = 'smtp.office365.com';
    $mail->SMTPAuth = true;
    $mail->AuthType = 'LOGIN';
    $mail->Username = $config['username'];
    $mail->Password = $config['password'];
    $mail->SMTPSecure = 'tls';
    $mail->Port = 587;

    // Recipients
    $mail->setFrom('from@vonwallace.com', 'Contact Form');
    $mail->addAddress('add@vonwallace.com');
    $mail->addReplyTo('add@vonwallace.com', 'Contact');

    // Content
    $mail->isHTML(true);
    $mail->Subject = 'Top 302 Errors Report - ' . date('Y-m-d');
    $mail->Body = $html;

    $mail->send();
    echo "302 error report has been generated and emailed successfully.";
} catch (Exception $e) {
    echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
}

?>
