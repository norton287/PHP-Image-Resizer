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

function isAdvancedFormat($fileType) {
    return in_array($fileType, ['webp', 'tiff', 'heic']);
}

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
    
        if (isAdvancedFormat($fileType) && extension_loaded('imagick')) {
            $image = new Imagick($imagePath);
            if ($maintainAspectRatio) {
                $image->thumbnailImage($width, $height, true);
            } else {
                $image->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);
            }
            $resizedImagePath = 'resized/' . basename($imagePath);
            $image->writeImage($resizedImagePath);
            $image->destroy();
         } else {
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
        }

        logMessage("Image resized to $width x $height.");
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

            // Sanitize input for width and height
            $resizeWidth = filter_var($_POST['width'], FILTER_SANITIZE_NUMBER_INT);
            $resizeHeight = filter_var($_POST['height'], FILTER_SANITIZE_NUMBER_INT);

            // Error handling for invalid width and height
            if ($resizeWidth === false || $resizeHeight === false || $resizeWidth <= 0 || $resizeHeight <= 0) {
                logMessage("Invalid width or height provided.");
                header("Location: error.php?error=" . urlencode('Invalid width or height provided.'));
                exit; // Terminate the script
            }
            
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

                    // Check if "maintainAspectRatio" key exists, default to false if not set
                    $maintainAspectRatio = isset($_POST['maintainAspectRatio']) ? $_POST['maintainAspectRatio'] === 'on' : false;

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
            $resp .= '<div class="mb-4 flex items-center justify-center"> <button data-href="download.php?file=' . urlencode($zipPath) . '" id="downloadButton" class="inline-flex items-center px-4 py-2 font-semibold leading-6 text-sm shadow rounded-md text-white bg-indigo-500 hover:bg-indigo-400 transition ease-in-out duration-150">
             <div class="animate-bounce bg-ingigo-800 dark:bg-slate-800 mr-3 p-2 w-10 h-10 ring-1 ring-slate-900/5 dark:ring-slate-200/20 shadow-lg rounded-full flex items-center justify-center"><svg class="w-6 h-6 text-white" fill="none" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24" stroke="currentColor"> <path d="M19 14l-7 7m0 0l-7-7m7 7V3"></path></svg></div> Download Zip File </button></div>';

    

            // $resp .= '<button data-href="download.php?file=' . urlencode($zipPath) . '" id="downloadButton" class="button-class flex flex-col  p-4 bg-blue-500 text-white rounded-md transform transition duration-500 ease-in-out hover:scale-105">Download ZIP file</button>';
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
	<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Image Resizer</title>
    <meta name="description" content="Resize your images to different sizes for social media platforms or custom dimensions. Supports JPG, PNG, BMP, WebP, TIFF, and HEIC formats.">
    <meta name="keywords" content="Image Resize, Social Media Presets, WebP, TIFF, HEIC, JPG, PNG">
    <meta property="og:title" content="Advanced Image Resizer">
    <meta property="og:description" content="Quickly resize images for social media or custom sizes.">
    <script defer src="https://umami.spindlecrank.com/script.js" data-website-id="4ff08c05-7f4a-4ba7-9b1a-31c2e7df23f9"></script>
	<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.16/dist/tailwind.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <style>
        * {
            font-family: Arial, sans-serif, ui-sans-serif, ui-serif, serif;
        }

        @keyframes bounce {
            0%, 100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-10px);
            }
        }

        /* #waiting {
            display: none;
            font-size: 1.5em;
            font-weight: bold;
	        color: orange;
            animation: bounce 1s infinite;
        } */
    </style>
</head>
<body class="bg-gradient-to-r from-indigo-800 via-purple-500 to-blue-500 min-h-screen flex items-center justify-center">
    <div class="mainContainer border-gray-800 bg-gray-600 rounded-lg shadow-lg w-5/6  sm:w-5/6 md:w-5/6 lg:w-5/6 xl:w-1/2 2xl:w-1/2 p-3 animate__animated animate__slideInLeft">
        <h1 class="text-2xl text-white text-center mb-4 animate__animated animate__delay-1s animate__fadeInDown">Advanced Image Resizer</h1>
        <p class="text-center text-xs sm:text-xs md:text-xs lg:text-base xl:text-l 2xl:text-l text-white">Resize your images with custom dimensions or presets for social media platforms.</p>
        <div id="form-div" class="flex flex-col w-3/4 p-4 justify-self-center">
            <form id="resizerForm" method="POST" enctype="multipart/form-data" class="space-y-4">
                <!-- File Upload -->
                <div id="file-selector-div" class="flex flex-col w-full">
                    <label class="block text-white font-sm mb-1 justify-left">Select Image:</label>
                    <input id="file-selector-inut" type="file" name="images[]" accept="image/*" multiple required
                       class="border border-gray-300 p-2 rounded-md w-full text-white text-xs cursor-pointer">
                </div>
            
                <!-- Preset Dropdown -->
                <div id="preset-div" class="flex flex-col space-y-1 w-full">
                    <label id="preset-label" for="preset" class="text-white text-sm">Choose a Social Media Preset</label>
                    <select id="preset" name="preset" onchange="applyPreset()"
                        class="border border-gray-300 rounded-md text-black p-2 w-full text-xs">
                        <option value="">Select Platform</option>
                        <option value="1200x630">Facebook (1200x630)</option>
                        <option value="1080x1080">Instagram (1080x1080)</option>
                        <option value="1024x512">Twitter (1024x512)</option>
                    </select>
                </div>
            
                <!-- Custom Dimensions -->
                <div id="dimensions-div" class="flex flex-col w-full space-y-2 sm:space-y-0 sm:space-x-2 items-center">
                    <div id="width-div" class="flex flex-col mb-2 w-full">
                        <label id="width-label" for="width" class="text-sm text-white mr-2">Width:</label>
                        <input type="number" id="width" name="width" placeholder="Enter width"
                           class="border border-gray-300 rounded-md p-1 text-black text-xs">
                    </div>
                    <div id="height-div" class="flex flex-col mt-2 w-full">
                        <label id="height-label" for="height" class="text-sm text-white mr-2">Height:</label>
                        <input type="number" id="height" name="height" placeholder="Enter height"
                           class="border border-gray-300 rounded-md p-1 text-black text-xs">
                    </div>
                </div>
                <!-- Rotation Selector -->
                <div id="rotation-div" class="mb-2 p-1 flex flex-col items-left">
                    <label id="rotation-label" for="rotation" class="rounded-md text-white text-sm sm:text-sm md:text-sm lg:text-base xl:text-base">Rotation:</label>
                    <select id="rotation-select" name="rotation" class="rounded-md text-black text-xs w-full p-1">
                        <option value="none" selected="none">None</option>
                        <option value="45">45 degrees</option>
                        <option value="90">90 degrees</option>
                        <option value="180">180 degrees</option>
                    </select>
                </div>
     
                <!-- Aspect Ratio Checkbox -->
                <label id="aspect-ration-label" class="flex items-center space-x-2 text-white">
                    <input id="aspect-box" type="checkbox" name="maintainAspectRatio" class="form-checkbox h-5 w-5 text-white">
                    <span class="text-xs sm:text-xs md:text-xs lg:text-xs xl:text-base 2xl:text-base">Maintain Aspect Ratio</span>
                </label>

                <!-- Submit Button -->
                <div id="button-div" class="w-full flex flex-col items-center">
                    <button type="submit" id="submit" class="w-3/8 flex flex-col items-center bg-indigo-500 hover:bg-indigo-600 text-white text-xs lg:text-base xl:text-base 2xl:text-base font-medium py-2 px-4 rounded-md transform transition duration-500 ease-in-out hover:scale-105">
                        Resize Image
                    </button>
                </div>
            </form>
        </div>

        <!-- Result Section -->
        <div id="result" class="mt-4 flex flex-col items-center w-full text-center"><?php echo $resp; ?></div>
        <div id='waiting' class="flex flex-row w-full items-center justify-center hidden">
            <div class="flex items-center justify-center"> 
                <button type="button" class="inline-flex items-center p-4 font-semibold leading-6 text-sm shadow rounded-md text-white bg-indigo-500 hover:bg-indigo-400 transition ease-in-out duration-150 cursor-not-allowed" disabled="">
                    <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Processing...
                </button>
            </div>
        </div>
		<!-- <div id="waiting" class="mb-6 mt-6 flex flex-col items-center w-full text-center"></div> -->
        <div class="mt-18 mb-8 flex flex-col items-center">
	    	<p class="mt-1 text-l hover:text-green-500 text-white italic animate__animated animate__delay-3s animate__zoomInUp"><span id="powered">Proudly Powered By spindlecrank.com</span></p>
	    </div>
	</div>
</body>
<script>
  document.getElementById('submit').addEventListener('click', function(e) {
    var waitingDiv = document.getElementById('waiting');
    var resultDiv = document.getElementById('result');
    // waitingDiv.innerHTML = "Resizing Files!";
    waitingDiv.style.display = 'block'; // Display the waiting message

    var checkResult = setInterval(function() {
      if (resultDiv.innerHTML.trim() !== "") {
         waitingDiv.style.display = 'none'; // Hide the waiting message
	     resultDiv.style.display = 'block'; // Show the Button
         clearInterval(checkResult);
      }
    }, 1000); // checks every second
  });

  function applyPreset() {
      const preset = document.getElementById('preset').value;
      if (preset) {
          const [width, height] = preset.split('x');
          document.getElementById('width').value = width;
          document.getElementById('height').value = height;
      }
  }

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
