# Image Resizer
- This is a PHP-based web application that allows users to upload images, resize them to specified dimensions, and download the resized images as a ZIP archive. The application supports JPG, PNG, and BMP image formats and provides options to maintain aspect ratio and rotate images.

## Features
- Image Resizing: Resize images to custom width and height.
- Aspect Ratio Control: Choose to maintain the original aspect ratio or not.
- Image Rotation: Rotate images by 45, 90, or 180 degrees.
- Batch Processing: Upload and resize multiple images at once.
- ZIP Download: Download all resized images in a convenient ZIP archive.
- Error Handling: Provides user-friendly error messages for common issues.
- Logging: Logs server actions and errors for debugging.
## Installation
### Clone the Repository:

```Bash
git clone https://github.com/norton287/PHP-Image-Resizer.git)
```
### Install Dependencies:

## This project requires PHP's GD library for image manipulation. You can install it using the following command (if you don't have it already):
```Bash
sudo apt-get install php-gd
```
- Ensure your web server (e.g., Apache, Nginx) is configured to serve PHP files.
## Set Up File Permissions:
- Create the necessary directories (uploads/, resized/, zip/) if they don't exist.
- Grant write permissions to the web server user (e.g., www-data) for these directories:
```Bash
sudo chown -R www-data:www-data uploads/ resized/ zip/
sudo chmod -R 755 uploads/ resized/ zip/
```
## Usage
- Access the Application: Open the resize.php file in your web browser.
- Upload Images: Select the images you want to resize (you can choose multiple files).
### Set Resize Options:
- Enter the desired width and height.
- Check the "Maintain Aspect Ratio" box if needed.
- Select the rotation angle if you want to rotate the images.
- Click "Upload and Resize": The application will process the images and provide a download link for the ZIP archive containing the resized images.
### Configuration
- Log File: The application logs messages to logfile.log in the same directory. You can customize the log file path in the logMessage function.
- Error Page: The error.php file is used to display error messages. You can customize this file to match your website's design.
- Zip File Expiration: The application automatically purges zip files older than 15 minutes from the server. You can adjust this duration in the purgeOldZipFiles function.
## Additional Notes
- The application includes basic security measures, but it's recommended to implement additional security practices for production environments.
- Consider adding input validation to prevent malicious uploads.
- You can enhance the application by adding support for more image formats, advanced resizing options, or integration with cloud storage.
