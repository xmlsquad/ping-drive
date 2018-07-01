# ping-drive

Symfony Console command that reports its attempts at locating and reading the contents of a Google Drive folder or file.

Inspired by the terminal command of the same name: [ping](https://en.wikipedia.org/wiki/Ping_(networking_utility))

Designed be used in the context of the Symfony Console application at https://github.com/xmlsquad/xml-authoring-tools which, in turn, is used in the context of a known directory structure which is based on [xml-authoring-project](https://github.com/xmlsquad/xml-authoring-project).

This simple command should be used to test our connection and access to a specific Google Drive entity (file or folder). To that end it should do as little as possible other than provide feedback on its progress.

## Requirements

* PHP ≥7.1
* [Composer](http://getcomposer.org) (required for installation)
* Google API Key 

### How to get a Google API client secret file

The following installation and usage instructions assume that you have created an O Auth Google API key and stored it locally on your workstation.

1. Open [Google API console](http://console.developers.google.com).
2. Create a project or select an existing project.
3. Enable the following APIs in the project: [Drive](https://console.developers.google.com/apis/api/drive.googleapis.com) and [Sheets](https://console.developers.google.com/apis/api/sheets.googleapis.com).
4. Go to the "Credentials" section, "Credentials" tab and [create an "OAuth client ID" credential](https://console.developers.google.com/apis/credentials/oauthclient). Or use an existing OAuth credential.
5. Click the download button (⬇️) at the right of the "OAuth 2.0 client IDs" list.


## Installation


### Installing as a standalone project

Open a terminal and run:

```bash
# Using composer's create-project command
composer create-project xmlsquad/ping-drive <directoryName>
```

or

```bash
# Cloning the git project 
git clone https://github.com/xmlsquad/ping-drive.git <directoryName>
composer install -d <directoryName>
```

Where `<directoryName>` is the directory where the command must be installed. In this case the command is started by running:

```bash
php <directoryName>/bin/ping-drive  --help
```

Further options and arguments are described in the *Usage* section below.

### Installing as a project dependency

Open a terminal, go to the project root directory and run:

```bash
composer require xmlsquad/ping-drive
```

In this case the command is started by running:

```bash
php vendor/bin/ping-drive  --help
```

## Usage

The command operates on behalf a Google user. So, you will need to authenticate during, at least, the first run. I say, 'at least' because, if `--access-token-file` is provided, the authentication token can be stored for following invocations.

To use it, run the command and follow its instructions.

### The command signature:

```bash
ping-drive -v --gApiOAuthSecretFile=GAPIOAUTHSECRETFILE --access-token-file=ACCESS-TOKEN-FILE --force-authenticate -q URL
```

* `URL` specifies a URL to ping. This argument is required.
* `-v` or `--verbose` turns the verbose mode on. In this mode a detailed progress information is printed. Otherwise only the key information is printed.
* `--gApiOAuthSecretFile` specifies a path to a JSON file with a Google API client secret. See below how to get it.
* `--access-token-file` specifies a path to a JSON file with a Google API access token. Access token file is optional. If a file path is set, the access token will be saved and subsequent executions will not prompt for authorization. If the given file doesn't exist, it will be created.
* `--force-authenticate` makes the command prompt for Google authentication even if an access token is presented. You can use it when you face an authorization problem or need to authenticate to another account.
* `-q` or `--quiet` makes the command print nothing.

### Behaviours

The command prints the URL information:

* If it is [a Google Folder](https://drive.google.com/drive/folders/1ffBMTmpMZrqTGzq4e8vwdey40rupjfyz?usp=sharing), lists the contents of the folder.
* If it is [a Google Sheet](https://docs.google.com/spreadsheets/d/1hdKksm6Xj6SiL3r8paCzlW2gBMRnm445ZBflUL-591M/edit?usp=sharing), prints the name of the Sheet and some of the contents of the sheet.
* If it is [another type of file hosted on Google Drive](https://drive.google.com/file/d/1jfmnrKM49-Wq5v6JLQHB3nEizz0RyUYu/view?usp=sharing), prints the name and type of the file.
* If it is [an inaccessible Google Drive file](https://docs.google.com/spreadsheets/d/12j2CrvWbZUU2_OJiIIr-sRkut2N-Gid4uwA0ZpkVks0/edit?usp=sharing), prints the corresponding message. 
* Otherwise prints an error message.

([See examples of each type with screenshots and the expected output from the command line](https://github.com/xmlsquad/ping-drive/issues/1#issuecomment-394171911))

### Usage example:

```bash
php vendor/bin/ping-drive -v https://drive.google.com/drive/u/0/folders/0B5q9i2h-vGaCQXhLZFNLT2JyV0U
# Prints the folder content
```

The command exists with status code 0 if the file is accessible and status code 1 if the file is not accessible.
So the command can be used in complex bash scripts, for example:

```bash
if [[ ping-drive -q ... ]]; then echo "Success!"; else echo "Fail"; fi
```



### Using with configuration file

The client secret and the access token paths can be read from a configuration file. 
In this case you don't need to set this options while starting the command.

The configuration file must be named `scapesettings.yaml` and located in the directory where the command is run or in one of the parent directories.
[scapesettings.yaml.dist](scapesettings.yaml.dist) is an example of the configuration file with required parameters.

### Troubleshooting

If you have an error message starting with `Failed to authenticate to Google:` and the rest of the message doesn't give much information, do the following:

1. Double check to ensure you followed the [API Key instructons](https://github.com/xmlsquad/ping-drive#how-to-get-a-google-api-client-secret-file) and are using the correct _type_ of key.
2. Try to authenticate from scratch by running the command with the `--force-authenticate` option.
3. If it doesn't help, create a Google API client secret file again and make sure the command uses the new file. You can see what secret file is used by running the command with the `-v` option.

## Contribution

1. Clone the repository.
2. Install the dependencies by running `composer install` in a console.
3. Make a change. Make sure the code follows the [PSR-2 standard](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-2-coding-style-guide.md).
4. Test the code by running `composer test`. If the test fails, fix the code.
5. Commit and push the changes.
