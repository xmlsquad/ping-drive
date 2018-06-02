<?php

namespace Forikal\PingDrive\Command;

use Forikal\Library\Console\ConsoleLogger;
use Forikal\Library\GoogleAPI\GoogleAPIClient;
use Forikal\Library\GoogleAPI\GoogleAPIFactory;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml;

/**
 * Pings a Google Drive file or folder URL
 *
 * @author Surgie Finesse
 */
class PingDriveCommand extends Command
{
    /**
     * The name of fallback configuration file for a case when the API files paths are not specified in the input
     */
    const CONFIG_FILE_NAME = 'scapesettings.yaml';

    /**
     * @var GoogleAPIFactory Google API factory
     */
    protected $googleAPIFactory;

    /**
     * @var Filesystem Symfony filesystem helper
     */
    protected $filesystem;

    /**
     * {@inheritDoc}
     *
     * @param GoogleAPIFactory|null $googleAPIFactory Google API factory
     */
    public function __construct(GoogleAPIFactory $googleAPIFactory = null)
    {
        parent::__construct();

        $this->googleAPIFactory = $googleAPIFactory ?? new GoogleAPIFactory();
        $this->filesystem = new Filesystem();
    }

    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('ping-drive')
            ->setDescription('Pings a Google Drive folder, Google Docs, Google Sheets or Google Slides URL')
            ->setHelp('See the readme')

            ->addArgument('url', InputArgument::REQUIRED, 'The target item URL')

            ->addOption('client-secret-file', null, InputOption::VALUE_REQUIRED, 'The path to an application client'
                . ' secret file. If not specified, the command will try to get a path from a scapesettings.yaml file.'
                . ' A client secret is required.')

            ->addOption('access-token-file', null, InputOption::VALUE_REQUIRED, 'The path to an access token file. The'
                . ' file may not exists. If an access token file is used, the command remembers user credentials and'
                . ' doesn\'t require a Google authentication next time.')

            ->addOption('force-authenticate', null, InputOption::VALUE_NONE, 'If set, you will be asked to authenticate'
                . ' even if an access token exist.');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->getFormatter()->setStyle('plain', new OutputFormatterStyle());

        $options = $this->parseInitialInput($input, $output);
        if ($options === null) {
            return 1;
        }

        $urlData = $this->getURLData($options['url']);

        switch ($urlData['type']) {
            case 'google-drive':
                if ($output->isVerbose()) {
                    $output->writeln('The URL points to Google Drive, will get more information from Google');
                }
                return $this->processGoogleDriveId($input, $output, $options, $urlData['id']) ? 0 : 1;

            case 'http':
                $this->writeError($output, 'The URL does NOT point to a file or folder on Google Drive');
                return 1;

            default:
                $this->writeError($output, 'The given URL is not a URL');
                return 1;
        }
    }

    /**
     * Parses and checks the initial user input
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return array|null If the input is incorrect, null is returned. Otherwise an options array is returned. It has
     *    the following keys:
     *     - url (string)
     *     - forceAuthenticate (bool)
     *     - clientSecretFile (string)
     *     - accessTokenFile (string|null)
     */
    protected function parseInitialInput(InputInterface $input, OutputInterface $output): ?array
    {
        $options = [
            'url' => $input->getArgument('url'),
            'forceAuthenticate' => (bool)$input->getOption('force-authenticate')
        ];

        $needToParseConfigFile = false;

        $options['clientSecretFile'] = $input->getOption('client-secret-file');
        if ($options['clientSecretFile'] === null) {
            $needToParseConfigFile = true;
            if ($output->isVerbose()) {
                $output->writeln('The client secret file path is not specified, will try to get the path from a configuration file');
            }
        }

        $options['accessTokenFile'] = $input->getOption('access-token-file');
        if ($options['accessTokenFile'] === null) {
            $needToParseConfigFile = true;
            if ($output->isVerbose()) {
                $output->writeln('The access token file path is not specified, will try to get the path from a configuration file');
            }
        }

        // If the API file paths are not specified, find and read a configuration file
        if ($needToParseConfigFile) {
            try {
                $dataFromConfigFile = $this->getDataFromConfigFile($output);
            } catch (\RuntimeException $exception) {
                $this->writeError($output, 'Couldn\'t read a configuration file: '.$exception->getMessage());
                return null;
            }

            if ($options['clientSecretFile'] === null) {
                if (($file = $dataFromConfigFile['clientSecretFile']) !== null) {
                    $options['clientSecretFile'] = $file;
                } else {
                    $this->writeError($output, 'The client secret file is specified neither in the CLI arguments nor in'
                        . ' the configuration file');
                    return null;
                }
            }

            if ($options['accessTokenFile'] === null && ($file = $dataFromConfigFile['accessTokenFile']) !== null) {
                $options['accessTokenFile'] = $file;
            }
        }

        return $options;
    }

    /**
     * Gets a URL type and data
     *
     * @param string $url The url
     * @return array The `type` key contains the type name. Other keys depend on the type.
     */
    protected function getURLData(string $url): array
    {
        /*
         * Known URL types:
         * https://drive.google.com/file/d/0B5q9i2h-vGaCc1ZBVnFhRzN2a3c/view?ths=true A Word file from Google Drive
         * https://docs.google.com/document/d/1-phrJPVDf1SpQYU5SzJY00Ke0c6QJmILHrtm_pcQk4w/edit A Google Docs file
         * https://drive.google.com/drive/u/0/folders/0B5q9i2h-vGaCQXhLZFNLT2JyV0U A Google Drive folder
         * https://drive.google.com/open?id=0B5q9i2h-vGaCYUE0ZTM4MDhseTQ A Google Drive shared file
         * https://docs.google.com/spreadsheets/d/13QGip-d_Z88Xru64pEFRyFVDKujDOUjciy35Qytw-Qc/edit A Google Sheets file
         * https://docs.google.com/presentation/d/1R1oF7zmnSDX3w3FBpyCTJnjvOu68C7Q2MH4kI4xMFWc/edit A Google Slides file
         */

        // Is it a Google Drive|Docs URL?
        if (preg_match(
            '~^(https?://)?(drive|docs)\.google\.[a-z]+/.*(/d/|/folders/|[\?&]id=)([a-z0-9\-_]{20,})([/?&#]|$)~i',
            $url,
            $matches
        )) {
            // Any Google Drive item can be obtained using a single API method: https://developers.google.com/drive/api/v3/reference/files/get
            return [
                'type' => 'google-drive',
                'id' => $matches[4]
            ];
        }

        // Is it a URL?
        if (preg_match('~^(https?://)?[a-z0-9.@\-_\x{0080}-\x{FFFF}]+(:[0-9]+)?(/|$)~iu', $url, $matches)) {
            return [
                'type' => 'http',
                'url' => ($matches[1] ? '' : 'http://').$url
            ];
        }

        return ['type' => 'unknown'];
    }

    /**
     * Gets a Google Drive item (file or folder) information and prints it to the output
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param array $options Input options received from the parseInitialInput method
     * @param string $id The item id
     * @return bool True, if the item is found and accessible, and false, if not
     * @link https://developers.google.com/drive/api/v3/reference/files/get
     */
    protected function processGoogleDriveId(
        InputInterface $input,
        OutputInterface $output,
        array $options,
        string $id
    ): bool {
        $googleAPIClient = $this->getAuthenticatedGoogleAPIClient($input, $output, $options);
        if ($googleAPIClient === null) {
            return false;
        }

        $drive = $googleAPIClient->driveService;

        try {
            $file = $drive->files->get($id);
        } catch (\Google_Service_Exception $exception) {
            switch ($exception->getCode()) {
                case 403:
                    $this->writeError($output, 'The Google Drive URL is no accessible: access denied');
                    break;
                case 404:
                    $this->writeError($output, 'The Google Drive URL is no accessible: file not found');
                    break;
                default:
                    $this->writeError($output, 'The Google Drive URL is no accessible: '.$exception->getMessage());
            }
            return false;
        }

        switch ($file->getMimeType()) {
            case GoogleAPIClient::MIME_TYPE_DRIVE_FOLDER:
                $this->writeGoogleDriveFolderData($output, $drive, $file);
                break;
            case GoogleAPIClient::MIME_TYPE_GOOGLE_SPREADSHEET:
                $this->writeGoogleSheetData($output, $googleAPIClient->sheetsService, $file);
                break;
            case GoogleAPIClient::MIME_TYPE_GOOGLE_PRESENTATION:
                $output->writeln('<info>The URL is a Google Slides file</info>');
                $output->writeln('Name: '.$file->getName());
                break;
            default:
                $output->writeln('<info>The URL is a Google Drive file</info>');
                $output->writeln('Name: '.$file->getName());
                $output->writeln('Type: '.$file->getMimeType());
        }

        return true;
    }

    /**
     * Prints a URL ping result as if the result is a Google Drive folder
     *
     * @param OutputInterface $output
     * @param \Google_Service_Drive $drive The Drive API service
     * @param \Google_Service_Drive_DriveFile $folder The folder
     * @link https://stackoverflow.com/a/42055925/1118709
     */
    protected function writeGoogleDriveFolderData(
        OutputInterface $output,
        \Google_Service_Drive $drive,
        \Google_Service_Drive_DriveFile $folder
    ) {
        $output->writeln('<info>The URL is a Google Drive folder</info>');
        $output->writeln('Name: '.$folder->getName());
        $output->writeln('Content:');

        $pageToken = null;

        do {
            $children = $drive->files->listFiles([
                'pageSize' => 1000,
                'pageToken' => $pageToken,
                'q' => "'{$folder->getId()}' in parents",
                'fields' => 'nextPageToken, files(id,name,mimeType)'
            ]);

            foreach ($children as $child) {
                /** @var \Google_Service_Drive_DriveFile $child */
                $output->writeln(
                    ' - A '.($child->getMimeType() === GoogleAPIClient::MIME_TYPE_DRIVE_FOLDER ? 'folder' : 'file')
                    .'. Name: '.$child->getName()
                );
            }

            $pageToken = $children->getNextPageToken();
        } while ($pageToken);
    }

    /**
     * Prints a URL ping result as if the result is a Google Sheets file
     *
     * @param OutputInterface $output
     * @param \Google_Service_Sheets $service The Sheets API service
     * @param \Google_Service_Drive_DriveFile $spreadsheet The file
     * @link https://developers.google.com/sheets/api/reference/rest/v4/spreadsheets/get
     */
    protected function writeGoogleSheetData(
        OutputInterface $output,
        \Google_Service_Sheets $service,
        \Google_Service_Drive_DriveFile $spreadsheet
    ) {
        $output->writeln('<info>The URL is a Google Sheets file</info>');
        $output->writeln('Name: '.$spreadsheet->getName());

        $spreadsheetData = $service->spreadsheets->get($spreadsheet->getId(), [
            'includeGridData' => true,
            'ranges' => ['A1:E5']
        ]);

        $sheetsNames = [];
        foreach ($spreadsheetData->getSheets() as $sheet) {
            /** @var \Google_Service_Sheets_Sheet $sheet (the SDK type hint is incorrect) */
            $sheetsNames[] = $sheet->getProperties()->getTitle();
        }

        if (!$sheetsNames) {
            $output->writeln('The file has no sheets');
            return;
        }

        $output->writeln('Sheets: '.implode(', ', $sheetsNames));
        $output->writeln('A piece of the first sheet content:');
        $this->writeGoogleSheetTable($output, $spreadsheetData->getSheets()[0]);
    }

    /**
     * Makes and authenticates a Google API client by interacting with the user
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param array $options Input options received from the parseInitialInput method
     * @return GoogleAPIClient|null If null, en error has happened (the message is printed)
     */
    protected function getAuthenticatedGoogleAPIClient(
        InputInterface $input,
        OutputInterface $output,
        array $options
    ): ?GoogleAPIClient {
        $googleAPIClient = $this->googleAPIFactory->make($this->makeConsoleLogger($output));

        try {
            $googleAPIClient->authenticate(
                $options['clientSecretFile'],
                $options['accessTokenFile'],
                [
                    \Google_Service_Drive::DRIVE_READONLY,
                    \Google_Service_Sheets::SPREADSHEETS_READONLY
                ],
                function ($authURL) use ($input, $output) {
                    $output->writeln('<info>You need to authenticate to your Google account to proceed</info>');
                    $output->writeln('Open the following URL in a browser, get an auth code and paste it below:');
                    $output->writeln('');
                    $output->writeln($authURL, OutputInterface::OUTPUT_PLAIN);
                    $output->writeln('');

                    $helper = $this->getHelper('question');
                    $question = new Question('Auth code: ');
                    $question->setValidator(function ($answer) {
                        $answer = trim($answer);

                        if ($answer === '') {
                            throw new \RuntimeException('Please enter an auth code');
                        }

                        return $answer;
                    });
                    return $helper->ask($input, $output, $question);
                },
                $options['forceAuthenticate']
            );
        } catch (\Exception $exception) {
            $this->writeError($output, 'Failed to authenticate to Google: '.$exception->getMessage());
            return null;
        }

        return $googleAPIClient;
    }

    /**
     * Gets options values from a configuration file
     *
     * @param OutputInterface $output
     * @return string[] Options values. The keys are:
     *  - clientSecretFile (string)
     *  - accessTokenFile (string|null)
     * @throws \RuntimeException If a configuration file can't be found, read or parsed
     */
    protected function getDataFromConfigFile(OutputInterface $output): array
    {
        $configFilePath = $this->findConfigFile();
        $configFileDir = dirname($configFilePath);
        if ($output->isVerbose()) {
            $output->writeln('Reading options from the `' . $configFilePath . '` configuration file');
        }

        try {
            $configData = Yaml\Yaml::parseFile($configFilePath);
        } catch (Yaml\Exception\ParseException $exception) {
            throw new \RuntimeException('Couldn\'t parse The configuration file YAML');
        }

        $options = [];

        // Parsing paths
        foreach (array('clientSecretFile', 'accessTokenFile') as $option) {
            if (!isset($configData['google'][$option])) {
                $options[$option] = null;
                continue;
            }
            if (!is_string($path = $configData['google'][$option])) {
                $this->writeError($output, 'The google.'.$option.' option value from the configuration file is not a string');
                $options[$option] = null;
                continue;
            }

            $options[$option] = $this->getFullPath($configFileDir, $path);
        }

        return $options;
    }

    /**
     * Finds a configuration file within the current working directory and its parents
     *
     * @return string The file path
     * @throws \RuntimeException If a file can't be found or read
     */
    protected function findConfigFile(): string
    {
        $directory = getcwd();
        if ($directory === false) {
            throw new \RuntimeException('Can\'t get the working directory path.'
                . ' Make sure the working directory is readable.');
        }

        for ($i = 0; $i < 10000; ++$i) { // `for` protects from an infinite loop
            $file = $directory.DIRECTORY_SEPARATOR.static::CONFIG_FILE_NAME;

            if (is_file($file)) {
                if (!is_readable($file)) {
                    throw new \RuntimeException('The `'.$file.'` configuration file is not readable');
                }

                return $file;
            }

            $parentDirectory = realpath($directory.DIRECTORY_SEPARATOR.'..'); // Gets the parent directory path
            if ($parentDirectory === $directory) break; // Check whether the current directory is a root directory
            $directory = $parentDirectory;
        }

        throw new \RuntimeException('The `'.static::CONFIG_FILE_NAME.'` exists neither in the current directory nor in'
            . ' any parent directory');
    }

    /**
     * Converts a relative file path to a full path
     *
     * @param string $contextPath A directory path from where the relative path is given
     * @param string $targetPath The relative path
     * @return string The full path
     */
    protected function getFullPath($contextPath, $targetPath): string
    {
        if ($this->filesystem->isAbsolutePath($targetPath)) {
            return $targetPath;
        }

        return rtrim($contextPath, '/\\').DIRECTORY_SEPARATOR.$targetPath;
    }

    /**
     * Creates a PSR logger instance which prints messages to the command output
     *
     * @param OutputInterface $output
     * @return ConsoleLogger
     */
    protected function makeConsoleLogger(OutputInterface $output): ConsoleLogger
    {
        return new ConsoleLogger($output, [
            LogLevel::DEBUG  => OutputInterface::VERBOSITY_VERY_VERBOSE,
            LogLevel::INFO   => OutputInterface::VERBOSITY_VERBOSE,
            LogLevel::NOTICE => OutputInterface::VERBOSITY_NORMAL
        ], [
            LogLevel::DEBUG  => '',
            LogLevel::INFO   => '',
            LogLevel::NOTICE => 'info'
        ]);
    }

    /**
     * Prints an error to an output
     *
     * @param OutputInterface $output
     * @param string $message The error message
     */
    protected function writeError(OutputInterface $output, string $message)
    {
        if ($output instanceof ConsoleOutputInterface) {
            $output = $output->getErrorOutput();
        }

        $output->writeln($this->getHelper('formatter')->formatBlock($message, 'error'));
    }

    /**
     * Prints a Google Sheet to a console output as a table
     *
     * @param OutputInterface $output
     * @param \Google_Service_Sheets_Sheet $sheet
     * @link http://symfony.com/doc/3.4/components/console/helpers/table.html
     */
    protected function writeGoogleSheetTable(OutputInterface $output, \Google_Service_Sheets_Sheet $sheet)
    {
        $table = new Table($output);
        $merges = $sheet->getMerges(); /** @var \Google_Service_Sheets_GridRange[] $merges (the SDK type hint is incorrect) */

        foreach ($sheet->getData()[0]->getRowData() as $rowIndex => $sheetRow) {
            /** @var \Google_Service_Sheets_RowData $sheetRow */
            // TableSeparator is not used here because rowspans break when it is used

            $tableRow = [];

            foreach ($sheetRow->getValues() as $columnIndex => $sheetCell) {
                $colspan = $rowspan = 1;

                /** @var \Google_Service_Sheets_CellData $sheetCell (the SDK type hint is incorrect) */
                foreach ($merges as $merge) {
                    // The end indexes are not inclusive
                    if (
                        $columnIndex >= $merge->getStartColumnIndex() && $columnIndex < $merge->getEndColumnIndex() &&
                        $rowIndex >= $merge->getStartRowIndex() && $rowIndex < $merge->getEndRowIndex()
                    ) {
                        if ($columnIndex === $merge->getStartColumnIndex() && $rowIndex === $merge->getStartRowIndex()) {
                            $colspan = $merge->getEndColumnIndex() - $columnIndex;
                            $rowspan = $merge->getEndRowIndex() - $rowIndex;
                            break;
                        } else {
                            continue 2;
                        }
                    }
                }

                $tableRow[] = new TableCell((string)$sheetCell->getFormattedValue(), [
                    'colspan' => $colspan,
                    'rowspan' => $rowspan
                ]);
            }

            $table->addRow($tableRow);
        }

        $table->render();
    }
}
