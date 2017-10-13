# Before you start
Update the `private static` properties in `WoltlabTarHandler.php` to be relevant to your environment.
# Usage
1. Enter your plugin's base directory: `cd /path/to/plugin`
1. Execute WoltlabTarHandler via the command line: `php /path/to/WoltlabTarHandler/WoltLabTarHandler.php`
   1. You can create an alias in your `.bashrc` to make this easier: `alias woltlabcreatetar="php /path/to/WoltlabTarHandler/WoltlabTarHandler.php"`
   1. Use the `--upload` option to  upload the plugin in addition to tarring it: `php /path/to/WoltlabTarHandler/WoltLabTarHandler.php --upload`
1. Check the output of the command for errors
