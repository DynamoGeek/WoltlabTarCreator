<?php
WoltlabTarHandler::execute(getopt("", array(
	"upload"
)));

class WoltlabTarHandler{
	const ENDS_WITH = "endsWith";
	const IS_EXACTLY = "isExactly";

	private static $_subFoldersToTar = array("files", "templates", "acpTemplates"); // Relative to baseDir
	private static $_subDirectoriesToInclude = array("language"); // Must be flat directories relative to baseDir
	private static $_filesToInclude = array( // Relative to baseDir
		array(self::ENDS_WITH => ".xml"),
		array(self::IS_EXACTLY => "files.tar"),
		array(self::IS_EXACTLY => "templates.tar"),
		array(self::IS_EXACTLY => "install.sql"),
		array(self::IS_EXACTLY => "update.sql"),
	);
	private static $_baseDir;
	private static $_packageName;
	private static $_packageVersion;
	private static $_beVerbose = true;

	private static $_uploadToPackageServer = false;
	private static $_packageServerSSHUsername = "ubuntu";
	private static $_packageServerSSHPassword = "";
	// Use this for PEM authentication (such as AWS) -- it'll require this script to be run as root
	private static $_packageServerSSHPemFileLocation = "/home/ubuntu/.ssh/wcf-package-server.pem";
	private static $_packageServerHostname = "wcfpackages.dynamogeek.com";
	private static $_packageServerPackageDirectory = "/var/www/node/Tims-PackageServer/packages/";


	/* Don't modify anything below this line */

	public static function execute($options){
		self::_validate();
		self::_init();
		self::_createTar();
		if(array_key_exists("upload", $options)){
			if(!empty(self::$_packageServerSSHPemFileLocation)){
				set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__) . "/phpseclib1.0.5");
				require_once("Net/SFTP.php");
				require_once("Crypt/RSA.php");
			}
			self::$_uploadToPackageServer = true;
		}
		if(self::$_uploadToPackageServer === true){
			self::_uploadTarToPackageServer();
		}
		if(self::$_beVerbose){
			echo "Complete!\n";
		}
	}

	private static function _validate(){
		if(!is_file("package.xml")){
			throw new Exception("'package.xml' not found.");
		}
		self::$_baseDir = dirname("package.xml");

		$packageXml = new SimpleXMLElement(file_get_contents(self::$_baseDir . "/package.xml"));
		self::$_packageName = (string)$packageXml["name"];
		self::$_packageVersion = (string)$packageXml->packageinformation->version;

		if(self::$_uploadToPackageServer === true){
			if(
				(empty(self::$_packageServerSSHPassword) && empty(self::$_packageServerSSHPemFileLocation)) ||
				empty(self::$_packageServerSSHUsername) ||
				empty(self::$_packageServerHostname)){

				throw new Exception("'\$_packageServerSSHUser', '\$_packageServerHostname', and '\$_packageServerSSHPassword' or '\$_packageServerSSHPemFileLocation' are required if '\$_uploadToPackageServer' is true.");
			}
		}
	}

	private static function _init(){
		chdir(self::$_baseDir);
	}

	private static function _createTar(){
		$tar = new PharData(self::$_packageName . ".tar");
		if(self::$_beVerbose){
			echo "Created " . self::$_packageName . ".tar...\n";
		}
		foreach(self::$_subFoldersToTar as $folderToTar){
			if(!is_dir($folderToTar)){
				continue;
			}
			chdir($folderToTar);
			$subtar = new PharData("$folderToTar.tar");
			if(self::$_beVerbose){
				echo "Created $folderToTar.tar...\n";
			}
			$subtar->buildFromDirectory("../$folderToTar", "/.*(?<!.tar)$/");

			if(self::$_beVerbose){
				echo "Added all sub-directories of $folderToTar/ to $folderToTar.tar...\n";
			}
			foreach(scandir("." . DIRECTORY_SEPARATOR) as $itemInDir){
				if(strpos($itemInDir, ".") === 0){
					// Skip ".", "..", and all hidden files
					continue;
				}
				if(is_file($itemInDir) && substr($itemInDir, -4) !== ".tar"){
					$subtar->addFile($itemInDir);
					if(self::$_beVerbose){
						echo "Added $itemInDir to $folderToTar.tar...\n";
					}
				}
			}
			rename("$folderToTar.tar", ".." . DIRECTORY_SEPARATOR . "$folderToTar.tar");
			chdir(".." . DIRECTORY_SEPARATOR);
			$tar->addFile("$folderToTar.tar");
		}
		foreach(self::$_subDirectoriesToInclude as $subDirectoryToInclude){
			if(is_dir($subDirectoryToInclude)){
				foreach(scandir($subDirectoryToInclude) as $fileToInclude){
					if(strpos($fileToInclude, ".") === 0){
						// Skip ".", "..", and all hidden files
						continue;
					}
					$tar->addFile($subDirectoryToInclude . DIRECTORY_SEPARATOR . $fileToInclude);
					if(self::$_beVerbose){
						echo "Added $fileToInclude to sub-directory $subDirectoryToInclude...\n";
					}
				}
			}
		}
		foreach(scandir(self::$_baseDir) as $possibleFileToInclude){
			if(strpos($possibleFileToInclude, ".") === 0){
				// Skip ".", "..", and all hidden files
				continue;
			}
			foreach(self::$_filesToInclude as $index => $fileToIncludeCheck){
				foreach($fileToIncludeCheck as $condition => $fileToInclude) {
					if ($condition === self::ENDS_WITH) {
						$fileToIncludeStrLength = strlen($fileToInclude);
						if (strpos($possibleFileToInclude, $fileToInclude) === strlen($possibleFileToInclude) - $fileToIncludeStrLength) {
							$tar->addFile($possibleFileToInclude);
							if(self::$_beVerbose){
								echo "Added $possibleFileToInclude to " . self::$_packageName . ".tar  because it matched '" . SELF::ENDS_WITH . " $fileToInclude'...\n";
							}
							break;
						}
					} elseif ($condition === self::IS_EXACTLY){
						if ($possibleFileToInclude === $fileToInclude) {
							$tar->addFile($possibleFileToInclude);
							if(self::$_beVerbose) {
								echo "Added $possibleFileToInclude to " . self::$_packageName . ".tar because it matched '" . SELF::IS_EXACTLY . " $fileToInclude'...\n";
							}
							unset(self::$_filesToInclude[$index]);
							break;
						}
					}
				}
			}
		}
		if(self::$_beVerbose){
			echo "Plugin tar created!\n";
		}
	}

	private static function _uploadTarToPackageServer(){
		$sftp = new Net_SFTP(self::$_packageServerHostname);

		$authResult = false;
		if($authResult === false && !empty(self::$_packageServerSSHPassword)){
			$authResult = $sftp->login(self::$_packageServerSSHUsername, self::$_packageServerSSHPassword);
		}
		if($authResult === false && !empty(self::$_packageServerSSHPemFileLocation)){
			$key = new Crypt_RSA();
			$key->loadKey(file_get_contents(self::$_packageServerSSHPemFileLocation));
			$authResult = $sftp->login(self::$_packageServerSSHUsername, $key);
		}
		$sftp->put(self::$_packageServerPackageDirectory . self::$_packageName . "/" . self::$_packageVersion . ".tar", "./" . self::$_packageName . ".tar", NET_SFTP_LOCAL_FILE);

		if($authResult === false){
			throw new Exception("Unable to connect to package server via SFTP with any of the given credentials.");
		}

		if(self::$_beVerbose){
			echo "Plugin tar uploaded to package server!\n";
		}
	}
}