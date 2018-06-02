<?php

namespace Forikal\PingDrive\Tests\Command;

use Forikal\Library\GoogleAPI\GoogleAPIClient;
use Forikal\Library\GoogleAPI\GoogleAPIFactory;
use Forikal\PingDrive\Command\PingDriveCommand;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use org\bovigo\vfs\vfsStreamWrapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @link http://symfony.com/doc/current/console.html#testing-commands
 * @link http://symfony.com/doc/current/components/console/helpers/questionhelper.html#testing-a-command-that-expects-input
 */
class PingDriveCommandTest extends TestCase
{
    /**
     * @var GoogleAPIFactory|\PHPUnit\Framework\MockObject\MockObject The Google API factory mock charged to the command
     */
    protected $googleAPIFactoryMock;

    /**
     * @var PingDriveCommand|\PHPUnit\Framework\MockObject\MockObject A command instance charged with the mock Google API
     */
    protected $command;

    /**
     * {@inheritdoc}
     */
    public function setUp()
    {
        parent::setUp();

        $this->googleAPIFactoryMock = $this->createMock(GoogleAPIFactory::class);
        $this->command = new PingDriveCommand($this->googleAPIFactoryMock);

        $application = new Application();
        $application->add($this->command);

        vfsStreamWrapper::register();
        vfsStreamWrapper::setRoot(new vfsStreamDirectory('test'));
        file_put_contents(vfsStream::url('test/secret.json'), '{"secret": "top-secret-dont-see-it"}');
        file_put_contents(vfsStream::url('test/token.json'), '{"token": "an-access-token-123"}');
    }

    /**
     * Checks a Drive folder ping output
     *
     * @dataProvider pingDriveFolderProvider
     */
    public function testPingDriveFolder($url, $id, $name, $content)
    {
        $contentItemToAPIFile = function ($item) {
            list($type, $name) = $item;
            return [
                'mimeType' => $type === 'folder' ? GoogleAPIClient::MIME_TYPE_DRIVE_FOLDER : 'application/pdf',
                'name' => $name
            ];
        };

        $driveFile = new \Google_Service_Drive_DriveFile([
            'id' => $id,
            'mimeType' => GoogleAPIClient::MIME_TYPE_DRIVE_FOLDER,
            'name' => $name
        ]);

        $driveFilesMock = $this->createMock('Google_Service_Drive_Resource_Files');
        $driveFilesMock->method('get')->with($id)->willReturn($driveFile);
        if (count($content) > 1000) { // If there are too many files, turn the pagination on
            $driveFilesMock->method('listFiles')
                ->withConsecutive(
                    [[
                        'pageSize'  => 1000,
                        'pageToken' => null,
                        'q'         => "'$id' in parents",
                        'fields'    => 'nextPageToken, files(id,name,mimeType)'
                    ]],
                    [[
                        'pageSize'  => 1000,
                        'pageToken' => 'next-page-token-4',
                        'q'         => "'$id' in parents",
                        'fields'    => 'nextPageToken, files(id,name,mimeType)'
                    ]]
                )
                ->will($this->onConsecutiveCalls(
                    new \Google_Service_Drive_FileList([
                        'files' => array_map($contentItemToAPIFile, array_slice($content, 0, 1000)),
                        'nextPageToken' => 'next-page-token-4'
                    ]),
                    new \Google_Service_Drive_FileList([
                        'files' => array_map($contentItemToAPIFile, array_slice($content, 1000)),
                        'nextPageToken' => null
                    ])
                ));
        } else {
            $driveFilesMock->method('listFiles')
                ->with([
                    'pageSize'  => 1000,
                    'pageToken' => null,
                    'q'         => "'$id' in parents",
                    'fields'    => 'nextPageToken, files(id,name,mimeType)'
                ])
                ->willReturn(new \Google_Service_Drive_FileList([
                    'files' => array_map($contentItemToAPIFile, $content),
                    'nextPageToken' => null
                ]));
        }

        $driveServiceMock = $this->createMock('Google_Service_Drive');
        $driveServiceMock->files = $driveFilesMock;

        $googleAPIClientMock = $this->createMock(GoogleAPIClient::class);
        $googleAPIClientMock->method('authenticate');
        $googleAPIClientMock->driveService = $driveServiceMock;

        $this->googleAPIFactoryMock->method('make')->willReturn($googleAPIClientMock);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
            'url' => $url,
            '--client-secret-file' => vfsStream::url('test/secret.json'),
            '--access-token-file' => vfsStream::url('test/token.json')
        ]);
        $output = $commandTester->getDisplay();
        $this->assertContains('The URL is a Google Drive folder', $output);
        $this->assertContains('Name: '.$name, $output);
        $this->assertEquals(count($content), substr_count($output, "\n - A "));
        foreach ($content as list($type, $name)) {
            $this->assertContains("A $type. Name: $name", $output);
        }
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function pingDriveFolderProvider()
    {
        return [
            ['https://drive.google.com/drive/u/0/folders/0B5q9i2h-vGaCR1BvbXAzNEtmeTQ/?foo=bar', '0B5q9i2h-vGaCR1BvbXAzNEtmeTQ', 'Test 1', [
                ['file', 'Foo'],
                ['folder', 'Bar']
            ]],
            ['https://drive.google.com/open?id=0B5q9i2h-vGaCQXhLZFNLT2JyV0U', '0B5q9i2h-vGaCQXhLZFNLT2JyV0U', 'Many files, should page', array_map(function ($number) {
                return ['file', 'Test '.$number];
            }, range(0, 1100))],
            ['https://drive.google.com/drive/u/0/folders/0B5q9i2h-vGbXAzNEtmaCR1BveTQ', '0B5q9i2h-vGbXAzNEtmaCR1BveTQ', 'Empty', []]
        ];
    }

    /**
     * Checks a Google Sheets file ping output
     *
     * @dataProvider pingGoogleSheetProvider
     */
    public function testPingGoogleSheet($url, $id, $name, $sheets, $table = [])
    {
        $driveFile = new \Google_Service_Drive_DriveFile([
            'id' => $id,
            'mimeType' => GoogleAPIClient::MIME_TYPE_GOOGLE_SPREADSHEET,
            'name' => $name
        ]);

        $driveFilesMock = $this->createMock('Google_Service_Drive_Resource_Files');
        $driveFilesMock->method('get')->with($id)->willReturn($driveFile);

        $driveServiceMock = $this->createMock('Google_Service_Drive');
        $driveServiceMock->files = $driveFilesMock;

        $sheetsData = array_map(function ($name) {
            return ['properties' => ['title' => $name]];
        }, $sheets);
        if ($sheetsData) {
            $sheetsData[0]['data'] = [
                [
                    'rowData' => array_map(function ($row) {
                        return [
                            'values' => array_map(function ($value) {
                                return ['formattedValue' => $value];
                            }, $row)
                        ];
                    }, $table)
                ]
            ];
            $sheetsData[0]['merges'] = [];
        }
        $spreadsheet = new \Google_Service_Sheets_Spreadsheet(['sheets' => $sheetsData]);

        $sheetsSpreadsheetsMock = $this->createMock('Google_Service_Sheets_Resource_Spreadsheets');
        $sheetsSpreadsheetsMock->method('get')->with($id, ['includeGridData' => true, 'ranges' => ['A1:E5']])->willReturn($spreadsheet);

        $sheetsServiceMock = $this->createMock('Google_Service_Sheets');
        $sheetsServiceMock->spreadsheets = $sheetsSpreadsheetsMock;

        $googleAPIClientMock = $this->createMock(GoogleAPIClient::class);
        $googleAPIClientMock->method('authenticate');
        $googleAPIClientMock->driveService = $driveServiceMock;
        $googleAPIClientMock->sheetsService = $sheetsServiceMock;

        $this->googleAPIFactoryMock->method('make')->willReturn($googleAPIClientMock);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
            'url' => $url,
            '--client-secret-file' => vfsStream::url('test/secret.json'),
            '--access-token-file' => vfsStream::url('test/token.json')
        ]);
        $output = $commandTester->getDisplay();
        $this->assertContains('The URL is a Google Sheets file', $output);
        $this->assertContains('Name: '.$name, $output);
        if (!$sheets) {
            $this->assertContains('The file has no sheets', $output);
            return;
        }
        $this->assertContains('Sheets: '.implode(', ', $sheets), $output);
        foreach ($table as $row) {
            foreach ($row as $value) {
                $this->assertContains((string)$value, $output);
            }
        }
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function pingGoogleSheetProvider()
    {
        return [
            ['https://docs.google.com/spreadsheets/d/13QGip-d_Z88Xru64pEFRyFVDKujDOUjciy35Qytw-Qc/edit', '13QGip-d_Z88Xru64pEFRyFVDKujDOUjciy35Qytw-Qc', 'Spreadsheet 1', ['Sheet 1'], [
                ['Foo', 'Bar', 'Baz'],
                [1, 2, 3],
                [null, false, true]
            ]],
            ['https://drive.google.com/open?id=FsSdfsAdfiauh34fa-sad&action=foo', 'FsSdfsAdfiauh34fa-sad', 'Testtt', ['Sheet A', 'Sheet B', 'Sheet C'], [
                ['Hello']
            ]],
            ['https://docs.google.com/spreadsheets/d/13QGip-d_Z88Xru64pEFRVq34', '13QGip-d_Z88Xru64pEFRVq34', 'Empty', []]
        ];
    }

    /**
     * Checks a Google Slides file ping output
     *
     * @dataProvider pingGoogleSlideProvider
     */
    public function testPingGoogleSlide($url, $id, $name)
    {
        $driveFile = new \Google_Service_Drive_DriveFile([
            'id' => $id,
            'mimeType' => GoogleAPIClient::MIME_TYPE_GOOGLE_PRESENTATION,
            'name' => $name
        ]);

        $driveFilesMock = $this->createMock('Google_Service_Drive_Resource_Files');
        $driveFilesMock->method('get')->with($id)->willReturn($driveFile);

        $driveServiceMock = $this->createMock('Google_Service_Drive');
        $driveServiceMock->files = $driveFilesMock;

        $googleAPIClientMock = $this->createMock(GoogleAPIClient::class);
        $googleAPIClientMock->method('authenticate');
        $googleAPIClientMock->driveService = $driveServiceMock;

        $this->googleAPIFactoryMock->method('make')->willReturn($googleAPIClientMock);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
            'url' => $url,
            '--client-secret-file' => vfsStream::url('test/secret.json'),
            '--access-token-file' => vfsStream::url('test/token.json')
        ]);
        $output = $commandTester->getDisplay();
        $this->assertContains('The URL is a Google Slides file', $output);
        $this->assertContains('Name: '.$name, $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }
    
    public function pingGoogleSlideProvider()
    {
        return [
            ['https://docs.google.com/presentation/d/1R1oF7zmnSDX3w3FBpyCTJnjvOu67Q2MH4kI4xMFWc/edit', '1R1oF7zmnSDX3w3FBpyCTJnjvOu67Q2MH4kI4xMFWc', 'A presentation'],
            ['http://docs.google.com/presentation/d/1R1oF7zm2nSDX3w3FBpyCnjvOu67Q2MH4kAI4xMFWc?action=edit', '1R1oF7zm2nSDX3w3FBpyCnjvOu67Q2MH4kAI4xMFWc', ''],
            ['https://drive.google.com/open?id=1R1oFzm2nSD5X3w3FBpyCnjvOu6Q2MH4-kAI4xMFWc', '1R1oFzm2nSD5X3w3FBpyCnjvOu6Q2MH4-kAI4xMFWc', 'Hey ho']
        ];
    }

    /**
     * Checks another Google Drive file ping output
     *
     * @dataProvider pingOtherDriveFileProvider
     */
    public function testPingOtherDriveFile($url, $id, $name, $mimeType)
    {
        $driveFile = new \Google_Service_Drive_DriveFile([
            'id' => $id,
            'mimeType' => $mimeType,
            'name' => $name
        ]);

        $driveFilesMock = $this->createMock('Google_Service_Drive_Resource_Files');
        $driveFilesMock->method('get')->with($id)->willReturn($driveFile);

        $driveServiceMock = $this->createMock('Google_Service_Drive');
        $driveServiceMock->files = $driveFilesMock;

        $googleAPIClientMock = $this->createMock(GoogleAPIClient::class);
        $googleAPIClientMock->method('authenticate');
        $googleAPIClientMock->driveService = $driveServiceMock;

        $this->googleAPIFactoryMock->method('make')->willReturn($googleAPIClientMock);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
            'url' => $url,
            '--client-secret-file' => vfsStream::url('test/secret.json'),
            '--access-token-file' => vfsStream::url('test/token.json')
        ]);
        $output = $commandTester->getDisplay();
        $this->assertContains('The URL is a Google Drive file', $output);
        $this->assertContains('Name: '.$name, $output);
        $this->assertContains('Type: '.$mimeType, $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function pingOtherDriveFileProvider()
    {
        return [
            ['http://drive.google.com/file/d/0B5q9i2h-vGaCc1ZBVnFhR-32a3c/view?ths=true', '0B5q9i2h-vGaCc1ZBVnFhR-32a3c', 'Example.pdf', 'application.pdf'],
            ['https://drive.google.com/open?id=1x2J8-UrfHFZUsxOUX_jHbPdFe1xGB4FwxTenH58yA', '1x2J8-UrfHFZUsxOUX_jHbPdFe1xGB4FwxTenH58yA', 'Test.txt', 'text/txt']
        ];
    }

    /**
     * Checks inaccessible Google Drive file ping output
     *
     * @dataProvider pingInaccessibleDriveFileProvider
     */
    public function testPingInaccessibleDriveFile($url, $code, $expectedMessage)
    {
        $driveFilesMock = $this->createMock('Google_Service_Drive_Resource_Files');
        $driveFilesMock->method('get')->willThrowException(new \Google_Service_Exception('Test', $code));

        $driveServiceMock = $this->createMock('Google_Service_Drive');
        $driveServiceMock->files = $driveFilesMock;

        $googleAPIClientMock = $this->createMock(GoogleAPIClient::class);
        $googleAPIClientMock->method('authenticate');
        $googleAPIClientMock->driveService = $driveServiceMock;

        $this->googleAPIFactoryMock->method('make')->willReturn($googleAPIClientMock);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
            'url' => $url,
            '--client-secret-file' => vfsStream::url('test/secret.json'),
            '--access-token-file' => vfsStream::url('test/token.json')
        ]);
        $output = $commandTester->getDisplay();
        $this->assertContains($expectedMessage, $output);
        $this->assertNotEquals(0, $commandTester->getStatusCode());
    }

    public function pingInaccessibleDriveFileProvider()
    {
        return [
            ['https://drive.google.com/file/d/0B5q9i2h-vGaCc1ZBVnFhR-32a3c/view?ths=true', 404, 'The Google Drive URL is no accessible: file not found'],
            ['https://drive.google.com/open?id=1x2J8-UrfHFZUsxOUX_jHbPdFe1xGB4FwxTenH58yA', 403, 'The Google Drive URL is no accessible: access denied']
        ];
    }

    /**
     * Checks inaccessible Google Drive file ping output
     *
     * @dataProvider notAGoogleDriveURLProvider
     */
    public function pingNotAGoogleDriveURL($url, $expectedMessage)
    {
        $this->googleAPIFactoryMock->expects($this->never())->method('make');

        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
            'url' => $url,
            '--client-secret-file' => vfsStream::url('test/secret.json'),
            '--access-token-file' => vfsStream::url('test/token.json')
        ]);
        $output = $commandTester->getDisplay();
        $this->assertContains($expectedMessage, $output);
        $this->assertNotEquals(0, $commandTester->getStatusCode());
    }

    public function notAGoogleDriveURLProvider()
    {
        return [
            ['http://google.com/imghp', 'The URL does NOT point to a file or folder on Google Drive'],
            ['https://www.google.com/intl/en/drive/', 'The URL does NOT point to a file or folder on Google Drive'],
            ['wikipedia.org/Foo', 'The URL does NOT point to a file or folder on Google Drive'],
            ['http://забег.рф', 'The URL does NOT point to a file or folder on Google Drive'],
            ['Hello, world', 'The given URL is not a URL'],
            ['1+2', 'The given URL is not a URL']
        ];
    }
}
