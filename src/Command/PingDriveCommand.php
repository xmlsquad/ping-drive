<?php

namespace XmlSquad\PingDrive\Command;

use XmlSquad\Library\Command\AbstractCommand;
use XmlSquad\Library\Console\ConsoleLogger;
use XmlSquad\Library\GoogleAPI\GoogleAPIClient;
use XmlSquad\Library\GoogleAPI\GoogleAPIFactory;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Pings a Google Drive file or folder URL
 *
 * @author Surgie Finesse
 */
class PingDriveCommand extends AbstractCommand
{

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

            ->doConfigureDriveUrlArgument('The target item URL')
            ->configureGApiOAuthSecretFileOption(
                InputOption::VALUE_OPTIONAL, 'The path to an application client'
                . ' secret file. If not specified, the command will try to get a path from a '.$this->getCommandStaticConfigFilename()
                . ' file. A client secret is required.')

            ->configureGApiAccessTokenFileOption()

            ->configureForceAuthenticateOption();
    }



    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {

        if ((!$this->findGApiOAuthSecretFileValue($input, $output) || (!$this->findGApiAccessTokenFileValue($input, $output)))) {
            return 1;
        }

        $driveUrlData = $this->getURLData($this->getDriveUrlArgument($input));

        switch ($driveUrlData['type']) {
            case 'google-drive':
                if ($output->isVerbose()) {
                    $output->writeln('The URL points to Google Drive, will get more information from Google');
                }
                return $this->processGoogleDriveId(
                    $input,
                    $output,
                    $this->findGApiOAuthSecretFileValue($input, $output),
                    $this->findGApiAccessTokenFileValue($input, $output),
                    (bool) $this->getForceAuthenticateOption($input),
                    $driveUrlData['id']) ? 0 : 1;
            case 'http':
                $this->writeError($output, 'The URL does NOT point to a file or folder on Google Drive');
                return 1;
            default:
                $this->writeError($output, 'The given URL is not a URL');
                return 1;
        }
    }



    /**
     * Gets a URL type and data
     *
     * @param string $driveUrl The driveUrl
     * @return array The `type` key contains the type name. Other keys depend on the type.
     */
    protected function getURLData(string $driveUrl): array
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
            $driveUrl,
            $matches
        )) {
            // Any Google Drive item can be obtained using a single API method: https://developers.google.com/drive/api/v3/reference/files/get
            return [
                'type' => 'google-drive',
                'id' => $matches[4]
            ];
        }

        // Is it a URL?
        if (preg_match('~^(https?://)?[a-z0-9.@\-_\x{0080}-\x{FFFF}]+(:[0-9]+)?(/|$)~iu', $driveUrl, $matches)) {
            return [
                'type' => 'http',
                'driveUrl' => ($matches[1] ? '' : 'http://').$driveUrl
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
        $gApiOAuthSecretFile,
        $gApiAccessTokenFile,
        $forceAuthenticate,
        string $id
    ): bool {
        $googleAPIClient = $this->googleAPIFactory->make($this->makeConsoleLogger($output));

        // Authenticate
        if (!$googleAPIClient->authenticateFromCommand(
            $input,
            $output,
            $gApiOAuthSecretFile,
            $gApiAccessTokenFile,
            [\Google_Service_Drive::DRIVE_READONLY, \Google_Service_Sheets::SPREADSHEETS_READONLY],
            $forceAuthenticate
        )) {
            return false;
        }

        $drive = $googleAPIClient->driveService;

        // Try to get the file information
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

        // Print the file information
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

        // Getting the list of sheets
        $spreadsheetData = $service->spreadsheets->get($spreadsheet->getId(), [
            'includeGridData' => false
        ]);
        $sheetsNames = [];
        foreach ($spreadsheetData->getSheets() as $sheet) {
            /** @var \Google_Service_Sheets_Sheet $sheet (the SDK type hint is incorrect) */
            $sheetsNames[] = $sheet->getProperties()->getTitle();
        }
        if ($sheetsNames) {
            $output->writeln('Sheets: '.implode(', ', $sheetsNames));
        } else {
            $output->writeln('The file has no sheets');
            return;
        }

        // Getting a piece of the first sheet content
        $spreadsheetData = $service->spreadsheets->get($spreadsheet->getId(), [
            'includeGridData' => true,
            'ranges' => ['A1:E5'] // Google returns only the data of the first sheet for this request
        ]);
        $output->writeln('A piece of the first sheet content:');
        $this->writeGoogleSheetTable($output, $spreadsheetData->getSheets()[0]);
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
            // TableSeparator is not used here because it is not compatible with rowspan

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
