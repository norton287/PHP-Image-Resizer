<?php

date_default_timezone_set('America/Chicago');

$resp = '';
$doover = '';

function logMessage($message)
{
    $logFile = __DIR__ . '/logfile.log';
    if (!file_exists($logFile)) {
        touch($logFile);
        chown($logFile, 'www-data');
    }
    // Format the log message with a timestamp
    $logMessage = ('Log Entry [' . date('Y-m-d H:i:s') . '] ' . $message) . PHP_EOL;
    // Append the log message to the log file
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

$directories = ['uploads/', 'resized/', 'zip/']; // Array of directories
$owner = 'www-data';
$permissions = 0755; // Octal representation of 755

// Function to create and set permissions for directories
function createDirectory($directory, $owner, $permissions) {
	try {
		mkdir($directory, $permissions, true); // Recursive creation
		logMessage("Directory $directory created!");
		chown($directory, $owner);
		logMessage("Ownership of $directory changed to $owner");       
	} catch (Exception $e) {
		logMessage("Error creating/modifying $directory: " . $e->getMessage());
    	header("Location: error.php?error=" . urlencode("System Error Occured"));
	}
}

// Iterate over the array and create directories if needed
foreach ($directories as $directory) {
	if (!file_exists($directory)) {
    	createDirectory($directory, $owner, $permissions);
    	logMessage("$directory does not exist! Making it now!");
    }
}


logMessage("New Connection!");

function purgeOldZipFiles() {
	logMessage("Purging Old Zips from the Server!");

    $directory = '/var/www/html/resize/zip/';
    $maxAgeMinutes = 15;
    $now = time();

    // Get all files in the directory
    $files = scandir($directory);

    // Iterate through the files
    foreach ($files as $file) {
        // Skip "." and ".."
        if ($file == '.' || $file == '..') {
            continue;
        }

        // Check if it's a zip file
        if (substr($file, -4) == '.zip') {
            $filePath = $directory . $file;

            // Get the last modification time of the file
            $lastModified = filemtime($filePath);

            // Check if it's older than the specified max age
            if ($now - $lastModified > $maxAgeMinutes * 60) {
                // Delete the file
                unlink($filePath);
            }
        }
    }
}

// Call the function when index.php loads
purgeOldZipFiles();


function uploadImage($file, $uploadPath)
{
    try {
        $targetPath = $uploadPath . basename($file['name']);
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            logMessage("Image uploaded!");
            return $targetPath;
        }
        logMessage("Failed to Save Image!");
        return false;
    } catch (Exception $e) {
    	logMessage("Error Uploading Image: " . $e->getMessage());
        header("Location: error.php?error=" . urlencode("Error Uploading Image: " . $e->getMessage()));
    }
}


function resizeImage($imagePath, $width, $height, $maintainAspectRatio)
{
    try {
        // Load the original image
        $fileType = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));

        if ($fileType == 'jpg' || $fileType == 'jpeg') {
            $sourceImage = imagecreatefromjpeg($imagePath);
        } elseif ($fileType == 'png') {
            $sourceImage = imagecreatefrompng($imagePath);
        } elseif ($fileType == 'bmp') {
            $sourceImage = imagecreatefromwbmp($imagePath);
        } else {
            logMessage("File type was not supported!");
        	header("Location: error.php?error=" . urlencode('Unsupported file type. Only JPG, PNG, and BMP files are supported.'));
        }

        // Get the original image dimensions
        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);

        // Calculate the aspect ratio
        $aspectRatio = $sourceWidth / $sourceHeight;

        // Calculate the new dimensions while maintaining the aspect ratio
        if ($maintainAspectRatio) {
            if ($sourceWidth / $sourceHeight > $aspectRatio) {
                $newWidth = $height * $aspectRatio;
                $newHeight = $height;
            } else {
                $newWidth = $width;
                $newHeight = $width / $aspectRatio;
            }
        } else {
            $newWidth = $width;
            $newHeight = $height;
        }

        // Create a new blank image
        $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

        // Resize the original image to the new dimensions
        imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $sourceWidth, $sourceHeight);

        // Save the resized image to a file
        $resizedImagePath = 'resized/' . basename($imagePath);

        if ($fileType == 'jpg' || $fileType == 'jpeg') {
            imagejpeg($resizedImage, $resizedImagePath);
        } elseif ($fileType == 'png') {
            imagepng($resizedImage, $resizedImagePath);
        } elseif ($fileType == 'bmp') {
            imagewbmp($resizedImage, $resizedImagePath);
        }

        // Clean up memory
        imagedestroy($sourceImage);
        imagedestroy($resizedImage);
        logMessage("Source images removed from server!");

        return $resizedImagePath;
    } catch (Exception $e) {
        logMessage("Error ReSizing Image " . $e->getMessage());
        header("Location: error.php?error=" . urlencode('Error Resizing Image ' . $e->getMessage()));
    }
}

function rotateImage($imagePath, $degrees)
{
    try {
        $fileType = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));

        if ($fileType == 'jpg' || $fileType == 'jpeg') {
            $sourceImage = imagecreatefromjpeg($imagePath);
        } elseif ($fileType == 'png') {
            $sourceImage = imagecreatefrompng($imagePath);
        } elseif ($fileType == 'bmp') {
            $sourceImage = imagecreatefromwbmp($imagePath);
        } else {
            logMessage("File type was not supported!");
        	header("Location: error.php?error=" . urlencode('Unsupported file type. Only JPG, PNG, and BMP files are supported.'));
        }

        $rotatedImage = imagerotate($sourceImage, $degrees, 0);

        $rotatedImagePath = 'rotated/' . basename($imagePath);

        if ($fileType == 'jpg' || $fileType == 'jpeg') {
            imagejpeg($rotatedImage, $rotatedImagePath);
        } elseif ($fileType == 'png') {
            imagepng($rotatedImage, $rotatedImagePath);
        } elseif ($fileType == 'bmp') {
            imagewbmp($rotatedImage, $rotatedImagePath);
        }
        
        logMessage("Image rotated!");

        imagedestroy($sourceImage);
        imagedestroy($rotatedImage);
        logMessage("Source image for rotated image destroyed!");

        return $rotatedImagePath;
    } catch (Exception $e) {
		logMessage("Error Rotating Files!");
        header("Location: error.php?error=" . urlencode('Error Rotating Files ' . $e->getMessage()));
    }
}

function deleteImage($imagePath)
{
    if (file_exists($imagePath)) {
        unlink($imagePath);
	logMessage("Source image deleted!");
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (extension_loaded('gd')) {
		logMessage("In Post!");
        try {
            $uploadDirectory = 'uploads/';
            $zipPath = 'zip/' . time() . '.zip';

            $totalFiles = count($_FILES['images']['tmp_name']);

            $resizeWidth = $_POST['width'];
            $resizeHeight = $_POST['height'];

            // Get selected rotation degree
            $rotationDegree = $_POST['rotation'];

            foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
				$zip = new ZipArchive();
				try {
					if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
						logMessage("Zip Not Created!");
			        	header("Location: error.php?error=" . urlencode('Zip Not Created!'));			}
				} catch (Exception $e) {
					logMessage("Zip Not Created! " . $e->getMessage());
        			header("Location: error.php?error=" . urlencode('Zip Not Created or Other Error Occured ' . $e->getMessage()));
				}

				logMessage("In foreach loop processing images!");
                $uploadedFile = [
                    'name' => $_FILES['images']['name'][$key],
                    'type' => $_FILES['images']['type'][$key],
                    'tmp_name' => $_FILES['images']['tmp_name'][$key],
                    'error' => $_FILES['images']['error'][$key],
                    'size' => $_FILES['images']['size'][$key]
                ];

                $allowedExtensions = ['jpg', 'jpeg', 'png'];
                $fileExtension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));

                if (!in_array($fileExtension, $allowedExtensions)) {
                    logMessage("File type was not supported!");
        			header("Location: error.php?error=" . urlencode('Unsupported file type. Only JPG, PNG, and BMP files are supported.'));
                }

                if ($uploadedFile['error'] === 0) {
                    $uploadedImagePath = uploadImage($uploadedFile, $uploadDirectory);
                    list($width, $height) = getimagesize($uploadedImagePath);

                    if ($_POST['maintainAspectRatio'] == 'on') {
                        $maintainAspectRatio = true;
                    } else {
                        $maintainAspectRatio = false;
                    }

                    $resizedImagePath = resizeImage($uploadedImagePath, $resizeWidth, $resizeHeight, $maintainAspectRatio);

                    if ($resizedImagePath) {
						logMessage("Resizing image now!");
						if ($rotationDegree !== 'none') {
							logMessage("Image was rotated!");
							// Rotate the image
							$rotatedImagePath = rotateImage($resizedImagePath, $rotationDegree);

							if ($rotatedImagePath) {
								try {
									$zip->addFile($rotatedImagePath, basename($rotatedImagePath));
									$zip->close();
									logMessage("Adding rotated image to zip file!");
								} catch (Exception $e) {
									logMessage("Error Rotating Image!");
        							header("Location: error.php?error=" . urlencode('Error Rotating Image ' . $e->getMessage()));
								}
							} else {
                                logMessage("Error Rotating Image!");
        						header("Location: error.php?error=" . urlencode('Error Rotating Image!'));
							}
							deleteImage($rotatedImagePath);
						} else {
							logMessage("Image was not rotated!");
							if ($resizedImagePath) {
								logMessage("Resizing image and adding to zip!");
								try {
									$zip->addFile($resizedImagePath, basename($resizedImagePath));
									$zip->close();
									logMessage("Image added to zip file");
								} catch (Exception $e) {
									logMessage("Could Not Update Zip File!");
        							header("Location: error.php?error=" . urlencode('Could Not Update Zip File ' . $e->getMessage()));
								}
							} else {
                                logMessage("Image Conversion Failed!");
        						header("Location: error.php?error=" . urlencode('Image Conversion Failed!'));
							}
							deleteImage($resizedImagePath);
						}
                    } else {
                        logMessage("Image Was Never Resized!");
        				header("Location: error.php?error=" . urlencode('Image Was Never Resized!'));
                    }

                    deleteImage($uploadedImagePath);
                } else {
                    logMessage("File Upload Error!");
        			header("Location: error.php?error=" . urlencode('File Upload Error!'));
                }
            }

            //$zip->close();

            $resp .= '<button data-href="download.php?file=' . urlencode($zipPath) . '" id="downloadButton" class="button-class flex flex-wrap justify-center p-4 bg-blue-500 text-white rounded-md transform transition duration-500 ease-in-out hover:scale-105">Download ZIP file</button>';
        	logMessage("Zip was stored at: " . $zipPath);
        } catch (Exception $e) {
            logMessage("System Error!");
        	header("Location: error.php?error=" . urlencode('System Error ' . $e->getMessage()));
        }
    } else {
        logMessage("GD Library Not Enabled On Server!");
        header("Location: error.php?error=" . urlencode('GD Library Not Instaled On Server!'));
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
	<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
	<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
	<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
	<link rel="manifest" href="/site.webmanifest">
	<link rel="mask-icon" href="/safari-pinned-tab.svg" color="#5bbad5">
 	<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
	<meta http-equiv="Expires" content="Sat, 01 Jan 2000 00:00:00 GMT">
	<meta http-equiv="Pragma" content="no-cache">
	<meta name="msapplication-TileColor" content="#da532c">
	<meta name="theme-color" content="#ffffff">
	<meta name="google-site-verification" content="gu3duYB5OEsqTehyFOA1M1OOzJ--AfbTsk4dt_CVJTU" />
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Image Resizer</title>
	<meta name="description" content="Welcome to Image Resizer Hosted By Spindlecrank.com! Resize your images to different sizes quickly and easily.">
	<meta name="keywords" content="Image Resize, Spindlecrank, JPG, PNG, BMP, GIF, ICO, resize, rotate image, image editor">
	<script defer src="https://umami.spindlecrank.com/script.js" data-website-id="4ff08c05-7f4a-4ba7-9b1a-31c2e7df23f9"></script>
	<script src="https://cdn.tailwindcss.com"></script>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <style>
        @keyframes bounce {
            0%, 100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-10px);
            }
        }

        #waiting {
            display: none;
            font-size: 1.5em;
            font-weight: bold;
	    color: orange;
            animation: bounce 1s infinite;
        }
    </style>
</head>
<body class="bg-blue-500 flex flex-col items-center justify-center min-h-screen w-3/4 sm:w-3/4 md:w-1/2 lg:w-1/2 xl:w-1/2 2xl:w-1/2 mx-auto">
    <div>
        <div class="shadow-lg p-6 bg-indigo-500 rounded-lg flex flex-col items-center">
			<h1 class="text-m sm:text-l md:text-xl lg:text-xl xl:text-xl text-white font-bold mb-3 animate__animated animate__rubberBand">Image Resizer</h1>
			<h3 class="text-s sm:text-m md:text-m lg:text-m xl:text-m text-white font-bold mb-2">Works for BMP, JPG, and PNG for now.</h3>
			<div id="form-div" class="shadow-lg rounded-lg bg-white p-4 flex flex-col items-left space-y-4">
				<form id="resizerForm" method="POST" enctype="multipart/form-data">
					<div class="mb-1 p-4 flex flex-col items-left">
						<input type="file" name="images[]" accept="image/*" multiple required class="p-2 border border-gray-300 rounded-md">
					</div>
					<div class="mb-1 p-1 flex items-left">
						<label for="maintain-aspect-ratio" class="text-s sm:text-s md:text-s lg:text-m xl:text-m mr-2">Maintain Aspect Ratio</label>
						<input type="checkbox" id="maintain-aspect-ratio" name="maintainAspectRatio">
					</div>
					<div class="mb-1 p-1 flex flex-col items-left"> <!-- Added container div for height and width inputs -->
						<input type="number" name="width" placeholder="Width" required
							class="p-2 border border-gray-300 rounded-md m-1 text-s sm:text-s md:text-s lg:text-m xl:text-m">
						<input type="number" name="height" placeholder="Height" required
							class="p-2 border border-gray-300 rounded-md m-1 text-s sm:text-s md:text-s lg:text-m xl:text-m">
					</div>
					 <div class="mb-2 p-1 flex flex-col items-left">
						<label for="rotation" class="mr-2 text-s sm:text-s md:text-s lg:text-m xl:text-m">Rotation:</label>
						<select id="rotation" name="rotation">
							<option value="none" selected="none">None</option>
							<option value="45">45 degrees</option>
							<option value="90">90 degrees</option>
							<option value="180">180 degrees</option>
						</select>
					</div>
					<div class="ml-1 mr-4 mt-4 mb-2 flex flex-wrap justify-center w-full">
						<button type="submit" id="submit" class="flex flex-wrap justify-center p-4 text-base bg-blue-500 text-white rounded-lg transform transition duration-500 ease-in-out hover:scale-110">Upload and Resize</button>
					</div>
				</form>
			</div>
			<div id="result" class="ml-6 mr-6 mb-6 mt-6 flex flex-col items-center w-full"><?php echo $resp; ?></div>
			<div id="waiting" class="ml-6 mr-6 mb-6 mt-6 flex flex-col items-center w-full text-center"></div>
		</div>
	</div>
</body>
<script>
  document.getElementById('submit').addEventListener('click', function(e) {
    var waitingDiv = document.getElementById('waiting');
    var resultDiv = document.getElementById('result');
    waitingDiv.innerHTML = "Resizing Files!";
    waitingDiv.style.display = 'block'; // Display the waiting message

    var checkResult = setInterval(function() {
      if (resultDiv.innerHTML.trim() !== "") {
        waitingDiv.style.display = 'none'; // Hide the waiting message
	resultDiv.style.display = 'block'; // Show the Button
        clearInterval(checkResult);
      }
    }, 1000); // checks every second
  });

  document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('resizerForm');
    const downloadButton = document.getElementById('downloadButton');
    const fileInput = document.getElementById('images');

    downloadButton.addEventListener('click', function(event) { // Added event parameter
      window.location.href = event.target.getAttribute('data-href');
      var resultDiv = document.getElementById('result');
      resultDiv.innerHTML = "";
      const formData = new FormData(form);
      formData.delete('images[]'); 
      form.reset();
    });
  });
</script>

</html>
