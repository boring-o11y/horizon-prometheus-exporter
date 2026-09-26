<?php

namespace BoringO11y\HorizonPrometheusExporter;

use InvalidArgumentException;

/**
 * Collects samples and renders them in the Prometheus text exposition format.
 *
 * Samples are grouped into families by metric name so that each name emits a
 * single HELP/TYPE header followed by all of its label permutations, which is
 * what the format requires.
 */
class MetricRegistry
{
    /**
     * The prefix prepended to every metric name.
     *
     * @var string
     */
    protected $prefix;

    /**
     * The collected metric families keyed by their full name.
     *
     * @var array<string, array{type: string, help: string, samples: array<int, array{labels: array, value: float|int, suffix: string}>}>
     */
    protected $families = [];

    /**
     * Create a new registry instance.
     *
     * @param  string  $prefix
     * @return void
     */
    public function __construct($prefix = 'horizon')
    {
        $this->prefix = trim((string) $prefix);
    }

    /**
     * Record a gauge sample.
     *
     * @param  string  $name
     * @param  string  $help
     * @param  float|int|null  $value
     * @param  array<string, string|int|null>  $labels
     * @return $this
     */
    public function gauge($name, $help, $value, array $labels = [])
    {
        return $this->sample('gauge', $name, $help, $value, $labels);
    }

    /**
     * Record a counter sample.
     *
     * @param  string  $name
     * @param  string  $help
     * @param  float|int|null  $value
     * @param  array<string, string|int|null>  $labels
     * @return $this
     */
    public function counter($name, $help, $value, array $labels = [])
    {
        return $this->sample('counter', $name, $help, $value, $labels);
    }

    /**
     * Record a summary made of a running sum and count, without quantiles.
     *
     * This is how an average is exported so it stays correct over any range:
     * `rate(x_sum[5m]) / rate(x_count[5m])` is the average over those five
     * minutes, where a gauge would only say what the average was at the moment
     * of each scrape.
     *
     * @param  string  $name
     * @param  string  $help
     * @param  float|int|null  $sum
     * @param  int|null  $count
     * @param  array<string, string|int|null>  $labels
     * @return $this
     */
    public function summary($name, $help, $sum, $count, array $labels = [])
    {
        if (is_null($sum) || is_null($count)) {
            return $this;
        }

        $this->sample('summary', $name, $help, $sum, $labels, '_sum');

        return $this->sample('summary', $name, $help, $count, $labels, '_count');
    }

    /**
     * Record a sample of the given type.
     *
     * Null values are skipped: an unknown value is better left absent than
     * reported as a zero the dashboards would average in.
     *
     * @param  string  $type
     * @param  string  $name
     * @param  string  $help
     * @param  float|int|null  $value
     * @param  array<string, string|int|null>  $labels
     * @param  string  $suffix
     * @return $this
     */
    protected function sample($type, $name, $help, $value, array $labels, $suffix = '')
    {
        if (is_null($value)) {
            return $this;
        }

        $name = $this->qualify($name);

        if (! isset($this->families[$name])) {
            $this->families[$name] = ['type' => $type, 'help' => $help, 'samples' => []];
        }

        $this->families[$name]['samples'][] = [
            'labels' => array_filter($labels, fn ($label) => ! is_null($label) && $label !== ''),
            'value' => $value,
            'suffix' => $suffix,
        ];

        return $this;
    }

    /**
     * Get the full, validated name for a metric.
     *
     * @param  string  $name
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    protected function qualify($name)
    {
        $name = $this->prefix === '' ? $name : $this->prefix.'_'.$name;

        if (! preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:]*$/', $name)) {
            throw new InvalidArgumentException("[{$name}] is not a valid Prometheus metric name.");
        }

        return $name;
    }

    /**
     * Determine if any samples have been recorded.
     *
     * @return bool
     */
    public function isEmpty()
    {
        return empty($this->families);
    }

    /**
     * Render the collected metrics in the text exposition format.
     *
     * @return string
     */
    public function render()
    {
        $lines = [];

        foreach ($this->families as $name => $family) {
            $lines[] = '# HELP '.$name.' '.$this->escapeHelp($family['help']);
            $lines[] = '# TYPE '.$name.' '.$family['type'];

            foreach ($family['samples'] as $sample) {
                $lines[] = $name.$sample['suffix'].$this->renderLabels($sample['labels']).' '.$this->renderValue($sample['value']);
            }
        }

        return $lines ? implode("\n", $lines)."\n" : '';
    }

    /**
     * Render a label set, including the surrounding braces.
     *
     * @param  array<string, string|int>  $labels
     * @return string
     */
    protected function renderLabels(array $labels)
    {
        if (empty($labels)) {
            return '';
        }

        $pairs = [];

        foreach ($labels as $key => $value) {
            $pairs[] = $key.'="'.$this->escapeLabelValue((string) $value).'"';
        }

        return '{'.implode(',', $pairs).'}';
    }

    /**
     * Escape a label value.
     *
     * Job class names are backslash separated, so the backslash escape is not
     * an edge case here — it applies to nearly every job label.
     *
     * @param  string  $value
     * @return string
     */
    protected function escapeLabelValue($value)
    {
        return str_replace(['\\', "\n", '"'], ['\\\\', '\\n', '\\"'], $value);
    }

    /**
     * Escape the help text of a metric family.
     *
     * @param  string  $help
     * @return string
     */
    protected function escapeHelp($help)
    {
        return str_replace(['\\', "\n"], ['\\\\', '\\n'], $help);
    }

    /**
     * Render a sample value.
     *
     * @param  float|int|bool  $value
     * @return string
     */
    protected function renderValue($value)
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        $value = (float) $value;

        if (is_nan($value)) {
            return 'NaN';
        }

        if (is_infinite($value)) {
            return $value > 0 ? '+Inf' : '-Inf';
        }

        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') ?: '0';
    }
}
