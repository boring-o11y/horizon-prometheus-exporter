<?php

namespace BoringO11y\HorizonPrometheusExporter;

class LuaScripts
{
    /**
     * Record how long a job waited to be picked up.
     *
     * KEYS[1] - Horizon's hash for the job
     * KEYS[2] - The per-class count of measured waits
     * KEYS[3] - The per-class sum of waits
     * KEYS[4] - The per-queue count of measured waits
     * KEYS[5] - The per-queue sum of waits
     * ARGV[1] - The current time, in seconds
     * ARGV[2] - The job class
     * ARGV[3] - The queue name
     *
     * A job whose hash has expired, or was never written, has no ready time to
     * measure from. Counting it anyway would add a zero wait to the average, so
     * it is left out of the sum and the count together.
     *
     * @return string
     */
    public static function recordWait()
    {
        return <<<'LUA'
local updated = tonumber(redis.call('hget', KEYS[1], 'updated_at'))

if not updated then
    return 0
end

local wait = tonumber(ARGV[1]) - updated

if wait < 0 then
    wait = 0
end

wait = string.format('%.6f', wait)

redis.call('hincrby', KEYS[2], ARGV[2], 1)
redis.call('hincrbyfloat', KEYS[3], ARGV[2], wait)
redis.call('hincrby', KEYS[4], ARGV[3], 1)
redis.call('hincrbyfloat', KEYS[5], ARGV[3], wait)

return 1
LUA;
    }
}
