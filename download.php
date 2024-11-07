<?php

date_default_timezone_set('America/Chicago');

    // Check if the 'file' parameter is set in the URL
    if(isset($_GET['file']) && !empty($_GET['file'])) {
        $filePath = urldecode($_GET['file']);

        // Check if the file exists
        if(file_exists($filePath)) {
            // Set headers to force download
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="'.basename($filePath).'"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filePath));

            // Clear output buffer
            ob_clean();

            // Flush the output buffer
            flush();

            // Read the file content and send it to the output
            readfile($filePath);

            // Exit to prevent any additional output
            exit;
        } else {
            echo 'File not found.';
        }
    } else {
        echo 'Invalid request.';
    }
?>
