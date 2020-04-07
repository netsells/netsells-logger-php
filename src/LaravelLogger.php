<?php

namespace Netsells\Logger;

use Throwable;
use Carbon\Carbon;
use Illuminate\Support\Facades\Request;
use Monolog\Formatter\FormatterInterface;

class LaravelLogger implements FormatterInterface
{
    protected $includeStacktraces = true;

    protected $project;
    protected $component;
    protected $subComponent;

    public function __construct($project, $component = 'core', $subComponent = 'php')
    {
        $this->project = $project;
        $this->component = $component;
        $this->subComponent = $subComponent;
    }

    /**
     * Formats a log record.
     *
     * @param  array $record A record to format
     * @return mixed The formatted record
     */
    public function format(array $record): string
    {
        $normalized = (array) $this->normalize($record);
        if (isset($normalized['context']) && $normalized['context'] === []) {
            $normalized['context'] = new \stdClass;
        }
        if (isset($normalized['extra']) && $normalized['extra'] === []) {
            $normalized['extra'] = new \stdClass;
        }

        // Take the "ready to log" output from laravel and process it further
        $normalized = $this->formatForNetsells($normalized);

        return $this->toJson($normalized) . "\n";
    }

    /**
     * Formats a set of log records.
     *
     * @param  array $records A set of records to format
     * @return mixed The formatted set of records
     */
    public function formatBatch(array $records)
    {
        $instance = $this;

        $oldNewline = $this->appendNewline;
        $this->appendNewline = false;
        array_walk($records, function (&$value, $key) use ($instance) {
            $value = $instance->format($value);
        });
        $this->appendNewline = $oldNewline;

        return implode("\n", $records);
    }

    private function toJson($data)
    {
        // JSON encoding errors should not break the app, suppress these!
        return @json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Formats the exception into the Netsells format
     *
     * @param array $normalized
     * @return array
     * @throws \Exception
     */
    private function formatForNetsells(array $normalized) : array
    {
        $data = [
            'app' => [
                'hostname' => gethostname(),
                'project' => $this->project,
                'component' => $this->component,
                'sub-component' => $this->subComponent,
            ],
            'event' => [
                'created' => $this->formatEventTime($normalized['datetime']),
                'type' => 'log',
            ],
            'level' => $this->convertLevel($normalized['level_name']),
            'message' => $normalized['message'],
            'request' => [
                'id' => app('request_id'),
                'client_id' => Request::ip(),
                'uri' => app()->runningInConsole() ? 'console' : Request::fullUrl(),
            ]
        ];

        $normalized['context'] = (array) $normalized['context'];

        if (isset($normalized['context']['exception'])) {
            $data['exception']['message'] = $normalized['context']['exception']['message'];
            $data['exception']['data'] = $normalized['context']['exception'];
            $data['event']['type'] = 'exception';
            unset($normalized['context']['exception']);
        }

        $data['context'] = $normalized['context'];

        return $data;
    }

    /**
     * Normalizes given $data.
     *
     * @param mixed $data
     *
     * @return mixed
     */
    protected function normalize($data, $depth = 0)
    {
        if ($depth > 9) {
            return 'Over 9 levels deep, aborting normalization';
        }

        if (is_array($data) || $data instanceof \Traversable) {
            $normalized = array();

            $count = 1;
            foreach ($data as $key => $value) {
                if ($count++ > 1000) {
                    $normalized['...'] = 'Over 1000 items ('.count($data).' total), aborting normalization';
                    break;
                }

                $normalized[$key] = $this->normalize($value, $depth+1);
            }

            return $normalized;
        }

        if ($data instanceof Exception || $data instanceof Throwable) {
            return $this->normalizeException($data);
        }

        return $data;
    }

    /**
     * Normalizes given exception with or without its own stack trace based on
     * `includeStacktraces` property.
     *
     * @param Exception|Throwable $e
     *
     * @param int $depth
     * @return array
     */
    protected function normalizeException($e)
    {
        if (!$e instanceof \Exception && !$e instanceof \Throwable) {
            throw new \InvalidArgumentException('Exception/Throwable expected, got '.gettype($e).' / '.get_class($e));
        }

        $data = array(
            'type' => get_class($e),
            'message' => $e->getMessage(),
            'code' => (int) $e->getCode(),
            'source' => $e->getFile().':'.$e->getLine(),
        );

        if ($this->includeStacktraces) {
            $trace = $e->getTrace();
            foreach ($trace as $frame) {
                if (isset($frame['file'])) {
                    // Matches sentry formatting as close as possible
                    $data['stacktrace'][] = "{$frame['file']} in {$frame['function']} at line {$frame['line']}";
                }
            }

            if (isset($data['stacktrace'])) {
                $data['stacktrace'] = implode("\n", $data['stacktrace']);
            }
        }

        if ($previous = $e->getPrevious()) {
            $data['previous'] = $this->normalizeException($previous);
        }

        return $data;
    }

    /**
     * Reduces the log levels to only the ones Netsells uses
     *
     * @param $level
     * @return string
     */
    private function convertLevel($level): string
    {
        switch ($level) {
            case 'EMERGENCY':
            case 'ALERT':
            case 'CRITICAL':
                return 'CRITICAL';
            case 'ERROR':
                return 'ERROR';
            case 'WARNING':
            case 'NOTICE':
                return 'WARN';
            case 'INFO':
                return 'INFO';
            case 'DEBUG':
                return 'DEBUG';
            default:
                return 'WARN';
        }
    }

    /**
     * Attempts to handle a variety of datetime formats and outputs an ISO8601 string
     *
     * @param $datetime
     * @return string
     */
    private function formatEventTime($datetime)
    {
        if (is_string($datetime)) {
            $carbonInstance = Carbon::parse($datetime);
        } elseif ($datetime instanceof \DateTime) {
            $carbonInstance = Carbon::instance($datetime);
        } else {
            // If all fails, we'll just use the current datetime
            $carbonInstance = Carbon::now();
        }

        return $carbonInstance->toAtomString();
    }
}
