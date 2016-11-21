<?php


namespace React\Socket;



use React\EventLoop\LoopInterface;

class ConnectionFactory
{
    /**
     * @var string The default connection class to use if no entry is found in the map.
     */
    protected $defaultDefinition;

    /**
     * @var array A mapping from stream type to definition. A definition is either a class name or a closure.
     */
    protected $map;

    /**
     * ConnectionFactory constructor.
     * @param array $map A map, mapping socket types to a connection class. The class may be given as string or closure.
     * @param string|Closure $defaultDefinition A default class to use if no mapping is found.
     */
    public function __construct(array $map = [], $defaultDefinition = Connection::class)
    {
        $this->map = $map;
        $this->defaultDefinition = $defaultDefinition;
    }

    /**
     * @inheritdoc
     */
    public function createConnection($socket, LoopInterface $loop)
    {
        if (!is_resource($socket)) {
            throw new \InvalidArgumentException("Socket must be of type resource.");
        }

        $meta = stream_get_meta_data($socket);
        $key = $meta['stream_type'];
        $definition = isset($this->map[$key]) ? $this->map[$key] : $this->defaultDefinition;
        return $this->constructConnection($definition, $socket, $loop);

    }

    /**
     * @param string|Closure $definition A class name or \Closure that constructs the desired class.
     * @param $socket A PHP stream resource.
     * @param LoopInterface $loop The loop to use as the second argument for the connection constructor / Closure.
     * @throws \InvalidArgumentException when the definition cannot be resolved.
     * @throws \UnexpectedValueException when the definition doesn't return an instance of ConnectionInterface.
     */
    protected function constructConnection($definition, $socket, LoopInterface $loop)
    {
        if (is_string($definition) && class_exists($definition)) {
            $result = new $definition($socket, $loop);
        } elseif ($definition instanceof \Closure) {
            $result = $definition($socket, $loop);
        } else {
            throw new \InvalidArgumentException("Definition must either be a class name or a closure.");
        }

        if (!$result instanceof ConnectionInterface) {
            throw new \UnexpectedValueException("Result must implement ConnectionInterface");
        }

        return $result;
    }

}