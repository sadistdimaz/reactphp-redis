<?php

namespace Clue\React\Redis;

use React\SocketClient\ConnectorInterface;
use React\Stream\Stream;
use Clue\React\Redis\Client;
use Clue\Redis\Protocol\Factory as ProtocolFactory;
use InvalidArgumentException;
use BadMethodCallException;
use Exception;

class Factory
{
    private $connector;
    private $protocol;

    public function __construct(ConnectorInterface $connector, ProtocolFactory $protocol = null)
    {
        $this->connector = $connector;

        if ($protocol === null) {
            $protocol = new ProtocolFactory();
        }
        $this->protocol = $protocol;
    }

    /**
     * create redis client connected to address of given redis instance
     *
     * @param string|null $target
     * @return \React\Promise\PromiseInterface resolves with Client or rejects with \Exception
     */
    public function createClient($target = null)
    {
        $auth = $this->getAuthFromTarget($target);
        $db   = $this->getDatabaseFromTarget($target);
        $protocol = $this->protocol;

        $promise = $this->connect($target)->then(function (Stream $stream) use ($protocol) {
            return new Client($stream, $protocol->createResponseParser(), $protocol->createSerializer());
        });

        if ($auth !== null) {
            $promise = $promise->then(function (Client $client) use ($auth) {
                return $client->auth($auth)->then(
                    function () use ($client) {
                        return $client;
                    },
                    function ($error) use ($client) {
                        $client->close();
                        throw $error;
                    }
                );
            });
        }

        if ($db !== null) {
            $promise = $promise->then(function (Client $client) use ($db) {
                return $client->select($db)->then(
                    function () use ($client) {
                        return $client;
                    },
                    function ($error) use ($client) {
                        $client->close();
                        throw $error;
                    }
                );
            });
        }

        return $promise;
    }

    private function parseUrl($target)
    {
        if ($target === null) {
            $target = 'tcp://127.0.0.1';
        }
        if (strpos($target, '://') === false) {
            $target = 'tcp://' . $target;
        }

        $parts = parse_url($target);
        if ($parts === false || !isset($parts['host']) || $parts['scheme'] !== 'tcp') {
            throw new Exception('Given URL can not be parsed');
        }

        if (!isset($parts['port'])) {
            $parts['port'] = '6379';
        }

        if ($parts['host'] === 'localhost') {
            $parts['host'] = '127.0.0.1';
        }

        return $parts;
    }

    private function connect($target)
    {
        try {
            $parts = $this->parseUrl($target);
        }
        catch (Exception $e) {
            if (class_exists('React\Promise\When')) {
                return \React\Promise\When::reject($e);
            } else {
                return \React\Promise\reject($e);
            }
        }

        return $this->connector->create($parts['host'], $parts['port']);
    }

    private function getAuthFromTarget($target)
    {
        $auth = null;
        $parts = parse_url($target);
        if (isset($parts['user'])) {
            $auth = $parts['user'];
        }
        if (isset($parts['pass'])) {
            $auth .= ':' . $parts['pass'];
        }

        return $auth;
    }

    private function getDatabaseFromTarget($target)
    {
        $db   = null;
        $path = parse_url($target, PHP_URL_PATH);
        if ($path !== null) {
            // skip first slash
            $db = substr($path, 1);
        }

        return $db;
    }
}
