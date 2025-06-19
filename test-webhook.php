<?php
// Replace this with your Discord webhook URL
$webhookUrl = '';

// Test message
$message = array(
    'content' => '',
    'embeds' => array(
        array(
            'title' => 'Test Announcement',
            'description' => 'This is a test announcement to verify the webhook integration is working.',
            'color' => 7506394,
            'timestamp' => date('c')
        )
    )
);

// Send to Discord
$ch = curl_init($webhookUrl);

// Set cURL options
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Verify SSL cert
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); // Verify SSL host
curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Set timeout to 10 seconds

// Execute the request
$response = curl_exec($ch);

// Check for errors
if ($response === false) {
    echo "cURL Error: " . curl_error($ch) . "\n";
    echo "cURL Error Number: " . curl_errno($ch) . "\n";
} else {
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpCode === 204) {
        echo "Success! Check your Discord channel for the test message.\n";
    } else {
        echo "Error: Received HTTP code $httpCode\n";
        echo "Response: $response\n";
    }
}

curl_close($ch);
