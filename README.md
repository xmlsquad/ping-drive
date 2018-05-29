# ping-drive
Symfony Console command that reports its attempts at locating and reading the contents of a Google Drive folder or file.

Inspired by the terminal command of the same name: [ping](https://en.wikipedia.org/wiki/Ping_(networking_utility))

Designed be used in the context of the Symfony Console application at https://github.com/forikal-uk/xml-authoring-tools which, in turn, is used in the context of a known directory structure which is based on [xml-authoring-project](https://github.com/forikal-uk/xml-authoring-project).

This simple command should be used to test our connection and access to a specific Google Drive entity (file or folder). To that end it should do as little as possible other than provide feedback on its progress.

# Input

drive-url: The URL of the Google Drive entity that is to be `ping`'d.

# Output

STD_OUT

Streamed report on the application's progress as it attempts to connect to and read the Google Drive entity defined by the URL passed as Input.





