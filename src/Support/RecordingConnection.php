<?php declare(strict_types=1);

namespace Mavinoo\Batch\Support;

use Closure;
use Illuminate\Database\Connection;
use LogicException;

/**
 * Records the statements Batch would run instead of running them. Used by Batch::pretend().
 *
 * It shares the real connection's grammar, prefix and config, so the recorded SQL is exactly
 * what would be sent, but it never opens a connection to the database.
 *
 * @internal
 */
class RecordingConnection extends Connection
{
    /**
     * @var Connection
     */
    private $real;

    /**
     * @var Closure receives (string $sql, array $bindings, string $connectionName)
     */
    private $recorder;

    public function __construct(Connection $real, Closure $recorder)
    {
        $config = $real->getConfig();

        parent::__construct(function () {
            throw new LogicException('Batch::pretend() must not connect to the database.');
        }, $real->getDatabaseName(), $real->getTablePrefix(), is_array($config) ? $config : []);

        $this->real = $real;
        $this->recorder = $recorder;
        $this->setQueryGrammar($real->getQueryGrammar());
        $this->setPostProcessor($real->getPostProcessor());
    }

    /**
     * @param string $query
     * @param array<mixed> $bindings
     */
    public function insert($query, $bindings = [])
    {
        $this->record($query, $bindings);

        return true;
    }

    /**
     * @param string $query
     * @param array<mixed> $bindings
     */
    public function update($query, $bindings = [])
    {
        $this->record($query, $bindings);

        return 0;
    }

    /**
     * @param string $query
     * @param array<mixed> $bindings
     */
    public function statement($query, $bindings = [])
    {
        $this->record($query, $bindings);

        return true;
    }

    /**
     * @param string $query
     * @param array<mixed> $bindings
     */
    public function affectingStatement($query, $bindings = [])
    {
        $this->record($query, $bindings);

        return 0;
    }

    /**
     * @param array<mixed> $bindings
     */
    private function record(string $query, array $bindings): void
    {
        // prepareBindings() formats dates and booleans the way the real connection would send them.
        ($this->recorder)($query, $this->real->prepareBindings($bindings), (string) $this->real->getName());
    }
}
