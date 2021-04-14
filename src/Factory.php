<?php

namespace Clue\React\Redis;

use Clue\Redis\Protocol\Factory as ProtocolFactory;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\Timer\TimeoutException;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;

class Factory
{
    /** @var LoopInterface */
    private $loop;

    /** @var ConnectorInterface */
    private $connector;

    /** @var ProtocolFactory */
    private $protocol;

    /**
     * @param ?LoopInterface $loop
     * @param ?ConnectorInterface $connector
     * @param ?ProtocolFactory $protocol
     */
    public function __construct(LoopInterface $loop = null, ConnectorInterface $connector = null, ProtocolFactory $protocol = null)
    {
        $this->loop = $loop ?: Loop::get();
        $this->connector = $connector ?: new Connector(array(), $this->loop);
        $this->protocol = $protocol ?: new ProtocolFactory();
    }

    /**
     * Create Redis client connected to address of given redis instance
     *
     * @param string $uri Redis server URI to connect to
     * @return \React\Promise\PromiseInterface<Client,\Exception> Promise that will
     *     be fulfilled with `Client` on success or rejects with `\Exception` on error.
     */
    public function createClient($uri)
    {
        // support `redis+unix://` scheme for Unix domain socket (UDS) paths
        if (preg_match('/^(redis\+unix:\/\/(?:[^:]*:[^@]*@)?)(.+?)?$/', $uri, $match)) {
            $parts = parse_url($match[1] . 'localhost/' . $match[2]);
        } else {
            if (strpos($uri, '://') === false) {
                $uri = 'redis://' . $uri;
            }

            $parts = parse_url($uri);
        }

        $uri = preg_replace(array('/(:)[^:\/]*(@)/', '/([?&]password=).*?($|&)/'), '$1***$2', $uri);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], array('redis', 'rediss', 'redis+unix'))) {
            return \React\Promise\reject(new \InvalidArgumentException('Invalid Redis URI given'));
        }

        $args = array();
        parse_str(isset($parts['query']) ? $parts['query'] : '', $args);

        $authority = $parts['host'] . ':' . (isset($parts['port']) ? $parts['port'] : 6379);
        if ($parts['scheme'] === 'rediss') {
            $authority = 'tls://' . $authority;
        } elseif ($parts['scheme'] === 'redis+unix') {
            $authority = 'unix://' . substr($parts['path'], 1);
            unset($parts['path']);
        }
        $connecting = $this->connector->connect($authority);

        $deferred = new Deferred(function ($_, $reject) use ($connecting, $uri) {
            // connection cancelled, start with rejecting attempt, then clean up
            $reject(new \RuntimeException('Connection to ' . $uri . ' cancelled'));

            // either close successful connection or cancel pending connection attempt
            $connecting->then(function (ConnectionInterface $connection) {
                $connection->close();
            });
            $connecting->cancel();
        });

        $protocol = $this->protocol;
        $promise = $connecting->then(function (ConnectionInterface $stream) use ($protocol) {
            return new StreamingClient($stream, $protocol->createResponseParser(), $protocol->createSerializer());
        }, function (\Exception $e) use ($uri) {
            throw new \RuntimeException(
                'Connection to ' . $uri . ' failed: ' . $e->getMessage(),
                0,
                $e
            );
        });

        // use `?password=secret` query or `user:secret@host` password form URL
        $pass = isset($args['password']) ? $args['password'] : (isset($parts['pass']) ? rawurldecode($parts['pass']) : null);
        if (isset($args['password']) || isset($parts['pass'])) {
            $pass = isset($args['password']) ? $args['password'] : rawurldecode($parts['pass']);
            $promise = $promise->then(function (StreamingClient $client) use ($pass, $uri) {
                return $client->auth($pass)->then(
                    function () use ($client) {
                        return $client;
                    },
                    function (\Exception $e) use ($client, $uri) {
                        $client->close();

                        throw new \RuntimeException(
                            'Connection to ' . $uri . ' failed during AUTH command: ' . $e->getMessage(),
                            0,
                            $e
                        );
                    }
                );
            });
        }

        // use `?db=1` query or `/1` path (skip first slash)
        if (isset($args['db']) || (isset($parts['path']) && $parts['path'] !== '/')) {
            $db = isset($args['db']) ? $args['db'] : substr($parts['path'], 1);
            $promise = $promise->then(function (StreamingClient $client) use ($db, $uri) {
                return $client->select($db)->then(
                    function () use ($client) {
                        return $client;
                    },
                    function (\Exception $e) use ($client, $uri) {
                        $client->close();

                        throw new \RuntimeException(
                            'Connection to ' . $uri . ' failed during SELECT command: ' . $e->getMessage(),
                            0,
                            $e
                        );
                    }
                );
            });
        }

        $promise->then(array($deferred, 'resolve'), array($deferred, 'reject'));

        // use timeout from explicit ?timeout=x parameter or default to PHP's default_socket_timeout (60)
        $timeout = isset($args['timeout']) ? (float) $args['timeout'] : (int) ini_get("default_socket_timeout");
        if ($timeout < 0) {
            return $deferred->promise();
        }

        return \React\Promise\Timer\timeout($deferred->promise(), $timeout, $this->loop)->then(null, function ($e) use ($uri) {
            if ($e instanceof TimeoutException) {
                throw new \RuntimeException(
                    'Connection to ' . $uri . ' timed out after ' . $e->getTimeout() . ' seconds'
                );
            }
            throw $e;
        });
    }

    /**
     * Create Redis client connected to address of given redis instance
     *
     * @param string $target
     * @return Client
     */
    public function createLazyClient($target)
    {
        return new LazyClient($target, $this, $this->loop);
    }
}
