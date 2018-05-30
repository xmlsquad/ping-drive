<?php

namespace ForikalUK\PingDrive\GoogleAPI;

use Psr\Log\LoggerInterface;

/**
 * Creates GoogleAPIClient objects
 *
 * @ignore A factory class is made for easy Google API mocking in tests
 *
 * @author Surgie Finesse
 */
class GoogleAPIFactory
{
    /**
     * Creates a GoogleAPIClient object
     *
     * @param LoggerInterface|null $logger A place where to write the client activity logs
     * @return GoogleAPIClient
     */
    public function make(LoggerInterface $logger = null)
    {
        return new GoogleAPIClient(null, $logger);
    }
}
