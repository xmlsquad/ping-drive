<?php

namespace ForikalUK\PingDrive\Commands;

use ForikalUK\PingDrive\GoogleAPI\GoogleAPIClient;
use ForikalUK\PingDrive\GoogleAPI\GoogleAPIFactory;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml;

/**
 * Pings a Google Drive folder, Google Docs, Google Sheets or Google Slides URL
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
     * @var GoogleAPIClient Google API factory
     */
    protected $googleAPIFactory;

    /**
     * @var Filesystem Symfony filesystem helper
     */
    protected $filesystem;

    /**
     * @inheritDoc
     *
     * @param \ForikalUK\PingDrive\GoogleAPI\GoogleAPIClient|null $googleAPIFactory Google API factory
     */
    public function __construct(GoogleAPIFactory $googleAPIFactory = null)
    {
        parent::__construct();

        $this->googleAPIFactory = $googleAPIFactory !== null ? $googleAPIFactory : new GoogleAPIFactory();
        $this->filesystem = new Filesystem();
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this
            ->setName('ping-drive')
            ->setDescription('Pings a Google Drive folder, Google Docs, Google Sheets or Google Slides URL')
            ->setHelp('See the readme')

            // Have to use option instead of argument because arguments are not supported in default commands
            ->addOption('url', 'u', InputOption::VALUE_REQUIRED, 'The target item URL')

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
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->getFormatter()->setStyle('plain', new OutputFormatterStyle());

        $options = $this->parseInitialInput($input, $output);
        if ($options === null) {
            return 1;
        }

        $googleAPIClient = $this->getAuthenticatedGoogleAPIClient($input, $output, $options);
        if ($googleAPIClient === null) {
            return 1;
        }

        return 0;
    }

    /**
     * Parses and checks the initial user input
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return array[]|null If the input is incorrect, null is returned. Otherwise an options array is returned. It has
     *    the following keys:
     *     - url (string)
     *     - forceAuthenticate (bool)
     *     - clientSecretFile (string)
     *     - accessTokenFile (string|null)
     */
    protected function parseInitialInput(InputInterface $input, OutputInterface $output)
    {
        $options = [
            'url' => trim($input->getOption('url')),
            'forceAuthenticate' => (bool)$input->getOption('force-authenticate')
        ];

        if ($options['url'] === '') {
            $this->writeError($output, 'The required URL option is not given');
            return null;
        }

        $needToParseConfigFile = false;

        $options['clientSecretFile'] = $input->getOption('client-secret-file');
        if ($options['clientSecretFile'] === null) {
            $needToParseConfigFile = true;
            if ($output->isVerbose()) {
                $output->writeln('The client secret file path is not specified, will try to get it from a configuration file');
            }
        }

        $options['accessTokenFile'] = $input->getOption('access-token-file');
        if ($options['accessTokenFile'] === null) {
            $needToParseConfigFile = true;
            if ($output->isVerbose()) {
                $output->writeln('The access token file path is not specified, will try to get it from a configuration file');
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
     * Makes and authenticates an Google API client by interacting with the user
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param array $options Input options received from the parseInitialInput method
     * @return GoogleAPIClient|null If null, en error has happened (the message is printed)
     */
    protected function getAuthenticatedGoogleAPIClient(InputInterface $input, OutputInterface $output, array $options)
    {
        $googleAPIClient = $this->googleAPIFactory->make($this->makeConsoleLogger($output));

        try {
            $googleAPIClient->authenticate(
                $options['clientSecretFile'],
                $options['accessTokenFile'],
                [
                    \Google_Service_Drive::DRIVE_READONLY,
                    \Google_Service_Sheets::SPREADSHEETS_READONLY,
                    \Google_Service_Slides::PRESENTATIONS_READONLY
                ],
                function ($authURL) use ($input, $output) {
                    $output->writeln('<info>You need to authenticate to your Google account to proceed</info>');
                    $output->writeln('Open the following URL in a browser, get an auth code and paste it below:');
                    $output->writeln($authURL, OutputInterface::OUTPUT_PLAIN);

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
    protected function getDataFromConfigFile(OutputInterface $output)
    {
        $configFilePath = $this->findConfigFile();
        $configFileDir = dirname($configFilePath);
        if ($output->isVerbose()) {
            $output->writeln('Reading options from `' . $configFilePath . '`');
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
    protected function findConfigFile()
    {
        $directory = getcwd();
        if ($directory === false) {
            throw new \RuntimeException('Can\'t get the working directory path.'
                . ' Make sure the working directory is readable.');
        }

        for ($i = 0; $i < 10000; ++$i) { // for protects from an infinite loop
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
    protected function getFullPath($contextPath, $targetPath)
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
    protected function makeConsoleLogger(OutputInterface $output)
    {
        return new ConsoleLogger($output, [
            LogLevel::DEBUG  => OutputInterface::VERBOSITY_VERY_VERBOSE,
            LogLevel::INFO   => OutputInterface::VERBOSITY_VERBOSE,
            LogLevel::NOTICE => OutputInterface::VERBOSITY_NORMAL
        ], [
            LogLevel::DEBUG => 'plain',
            LogLevel::INFO  => 'plain',
        ]);
    }

    protected function writeError(OutputInterface $output, $message)
    {
        if ($output instanceof ConsoleOutputInterface) {
            $output = $output->getErrorOutput();
        }

        $output->writeln($this->getHelper('formatter')->formatBlock([$message], 'error'));
    }
}
