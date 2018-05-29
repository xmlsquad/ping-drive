<?php

namespace ForikalUK\PingDrive\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
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
                . ' doesn\'t require a Google authentication next time.');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $inputData = $this->parseInput($input, $output);
        if ($inputData === null) {
            return 1;
        }

        var_dump($inputData);
        $output->writeln('To be continued...');

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
     *     - clientSecretFile (string)
     *     -  accessTokenFile (string|null)
     */
    protected function parseInput(InputInterface $input, OutputInterface $output)
    {
        $options = [
            'url' => trim($input->getOption('url'))
        ];

        if ($options['url'] === '') {
            $output->writeln('<error>The required URL option is not given</error>');
            return null;
        }

        $needToParseConfigFile = false;

        $options['clientSecretFile'] = $input->getOption('client-secret-file');
        if ($options['clientSecretFile'] === null) {
            $needToParseConfigFile = true;
            $output->writeln('The client secret file path is not specified, will try to get it from a configuration file');
        }

        $options['accessTokenFile'] = $input->getOption('access-token-file');
        if ($options['accessTokenFile'] === null) {
            $needToParseConfigFile = true;
            $output->writeln('The access token file path is not specified, will try to get it from a configuration file');
        }

        // If the API file paths are not specified, find and read a configuration file
        if ($needToParseConfigFile) {
            try {
                $dataFromConfigFile = $this->getDataFromConfigFile($output);
            } catch (\RuntimeException $exception) {
                $output->writeln('<error>Couldn\'t read a configuration file: '.$exception->getMessage().'</error>');
                return null;
            }

            foreach ($dataFromConfigFile as $key => $value) {
                if ($options[$key] === null) {
                    $options[$key] = $value;
                }
            }
        }

        return $options;
    }

    /**
     * Gets options values from a configuration file
     *
     * @param OutputInterface $output
     * @return string[] Options values. The keys are:
     *  - clientSecretFile (string)
     *  - accessTokenFile (string|null)
     */
    protected function getDataFromConfigFile(OutputInterface $output)
    {
        $filePath = $this->findConfigFile();
        $output->writeln('Reading options from `'.$filePath.'`');

        try {
            $configData = Yaml\Yaml::parseFile($filePath);
        } catch (Yaml\Exception\ParseException $exception) {
            throw new \RuntimeException('Couldn\'t parse The configuration file YAML');
        }

        $options = [];

        if (!isset($configData['google']['clientSecretFile'])) {
            throw new \RuntimeException('The google.clientSecretFile option is not presented in the configuration file');
        }
        if (!is_string($options['clientSecretFile'] = $configData['google']['clientSecretFile'])) {
            throw new \RuntimeException('The google.clientSecretFile option value from the configuration file is not a string');
        }

        if (isset($configData['google']['accessTokenFile'])) {
            if (!is_string($options['accessTokenFile'] = $configData['google']['accessTokenFile'])) {
                throw new \RuntimeException('The google.accessTokenFile option value from the configuration file is not a string');
            }
        } else {
            $options['accessTokenFile'] = null;
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
            $file = $directory.'/'.static::CONFIG_FILE_NAME;

            if (is_file($file)) {
                if (!is_readable($file)) {
                    throw new \RuntimeException('The `'.$file.'` configuration file is not readable');
                }

                return $file;
            }

            $parentDirectory = realpath($directory.'/..'); // Gets the parent directory path
            if ($parentDirectory === $directory) break; // Check whether the current directory is a root directory
            $directory = $parentDirectory;
        }

        throw new \RuntimeException('The `'.static::CONFIG_FILE_NAME.'` exists neither in the current directory nor in'
            . ' any parent directory');
    }
}
