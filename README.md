# ping-drive

Symfony Console command that reports its attempts at locating and reading the contents of a Google Drive folder or file.

Inspired by the terminal command of the same name: [ping](https://en.wikipedia.org/wiki/Ping_(networking_utility))

Designed be used in the context of the Symfony Console application at https://github.com/forikal-uk/xml-authoring-tools which, in turn, is used in the context of a known directory structure which is based on [xml-authoring-project](https://github.com/forikal-uk/xml-authoring-project).

This simple command should be used to test our connection and access to a specific Google Drive entity (file or folder). To that end it should do as little as possible other than provide feedback on its progress.

## Requirements

* PHP 5.5.9 or newer
* [Composer](http://getcomposer.org) (required for installation)

## Installation

You can install the command as a standalone application. Open a terminal and run:

```bash
composer create-project forikal-uk/ping-drive command
```

Replace `command` with a path to a directory where the command must be installed. In this case the command is started by running:

```bash
php command/ping-drive ...
```

Also you can install the command as a project dependency. Open a terminal, go to the project root directory and run:

```bash
composer require forikal-uk/ping-drive
```

In this case the command is started by running:

```bash
php vendor/bin/ping-drive ...
```

## Usage

The command operates behalf a Google user so you will need to authenticate during the first run. 
To do it, run the command and follow its instructions.

The command has the following signature:

```bash
ping-drive -v -u=URL --client-secret-file=CLIENT-SECRET-FILE --access-token-file=ACCESS-TOKEN-FILE --force-authenticate -q
```

* `-v` or `--verbose` turns the verbose mode on. In this mode a detailed progress information is printed. Otherwise only the key information is printed.
* `-u` or `--url` specifies a URL to ping. This option is required.
* `--client-secret-file` specifies a path to a JSON file with a Google API client secret. See below how to get it.
* `--access-token-file` specifies a path to a JSON file with a Google API access token. Access token file is optional. If a file path is set, the access token will be saved and subsequent executions will not prompt for authorization. If the given file doesn't exist, it will be created.
* `--force-authenticate` makes the command prompt for Google authentication even if an access token is presented. You can use it when you face an authorization problem or need to authenticate to another account.
* `-q` or `--quiet` makes the command print nothing.

The command prints the URL information:

* If it is a Google Folder, lists the contents of the folder
* If it is a Google Sheet, prints the name of the Sheet and some of the contents of the sheet.
* If it is another type of file hosted on Google Drive, prints the name and type of the file.
* If it is inaccessible Google Drive file, prints the corresponding message. 
* If it is not a Google Drive URL, prints HTTP response code.

Usage example:

```bash
$ php vendor/bin/ping-drive -v --url="https://drive.google.com/drive/u/0/folders/0B5q9i2h-vGaCQXhLZFNLT2JyV0U"
# Prints the folder content
```

The command exists with status code 0 if the file is accessible and status code 1 if the file is not accessible.
So the command can be used in complex bash scripts, for example:

```bash
if [[ ping-drive -q -u="..." ]]; then echo "Success!"; else echo "Fail"; fi
```

### How to get a Google API client secret file

1. Open [Google API console](http://console.developers.google.com).
2. Create a project or select an existing project.
3. Enable the following APIs in the project: [Drive](https://console.developers.google.com/apis/api/drive.googleapis.com) and [Sheets](https://console.developers.google.com/apis/api/sheets.googleapis.com).
4. Go to the "Credentials" section, "Credentials" tab, and create an "OAuth client ID" credential. Or use an existing OAuth credentials.
5. Click the download button (⬇️) at the right in the "OAuth 2.0 client IDs" list.

### Using with configuration file

The client secret and the access token paths can be read from a configuration file. 
In this case you don't need to set this options while starting the command.

The configuration file must be names `scapesettings.yaml` and be located in the directory where the command is run or
in one of the parent directories. [scapesettings.yaml.sample](scapesettings.yaml.sample) is an example of the configuration file with required parameters.

## Contribution

To be continued...
