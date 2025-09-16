<?php

const DEFAULT_FILE = 'measurements.txt';
$cores = intval(shell_exec("nproc 2> /dev/null")) ?: intval(shell_exec("sysctl -n hw.ncpu 2> /dev/null")) ?: 1;

if (count($argv) < 2 || count($argv) > 3)
{
    printf("\nUsage: php %s <num_procs> [file]\n\n", basename(__FILE__));
    printf("  num_procs  Number of processes [1..%d] (cpu reports %d cores)\n", $cores * 3, $cores);
    printf("  file       Input file (default: %s)\n", DEFAULT_FILE);
    exit(1);
}

if (!extension_loaded('pcntl')) die("Error: The pcntl extension is required.\n");

$num_procs = intval($argv[1]);
if ($num_procs <= 0 || $num_procs > $cores * 3)
    die(printf("Error: Number of processes must in the range [1..%d].\n", $cores * 3));

define('FILE', $argv[2] ?? DEFAULT_FILE);

function chunks(int $num_procs): array
{
    $result = [];
    if (!($_fp = fopen(FILE, 'rb'))) die("Error: Could not open file for reading.\n");
    $f_size = filesize(FILE);
    $c_size = intval($f_size / $num_procs);
    $start = 0;
    while ($start < $f_size) // split file into chunks...
    {
        $end = min($f_size, $start + $c_size);
        if ($end < $f_size) { fseek($_fp, $end); fgets($_fp); $end = ftell($_fp); }
        $result[] = [$start, $end];
        $start = $end;
    }
    fclose($_fp);
    return $result;
}

function process(int $start, int $end): array
{
    $result = [];
    $_fp = fopen(FILE, 'rb');
    fseek($_fp, $start);
    while ($start < $end)
    {
        $k = stream_get_line($_fp, 101, ';');
        $v = stream_get_line($_fp, 6, "\n");
        $start += strlen($k) + strlen($v) + 2;
        $v = (float)$v;
        if (($s = &$result[$k]) !== null)
        {
            if ($v < $s[0]) $s[0] = $v;
            if ($v > $s[1]) $s[1] = $v;
            $s[2] += $v;
            $s[3] += 1;
        }
        else $s = [$v, $v, $v, 1]; // ...min, max, sum, count
    }
    return $result;
}

$chunks = chunks($num_procs);
$streams = $data = $result = [];
$null = null;

foreach ($chunks as $_ch) // fork children...
{
    if (($pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP)) === false)
        die("\nError: Could not create socket pair.\n");
    $pid = pcntl_fork();
    // error...
    if ($pid === -1) die("\nError: Could not fork process.\n");
    // child...
    if ($pid === 0)
    {
        fclose($pair[0]);
        @fwrite($pair[1], serialize(process(...$_ch)));
        fclose($pair[1]);
        exit(0);
    }
    // parent...
    fclose($pair[1]);
    stream_set_blocking($pair[0], false);
    $rid = get_resource_id($pair[0]);
    $streams[$rid] = $pair[0];
    $data[$rid] = '';
}

while ($streams) // read from children...
{
    $read = array_values($streams);
    if (stream_select($read, $null, $null, 2) > 0)
    {
        foreach ($read as $_rs)
        {
            $rid = get_resource_id($_rs);
            $d = stream_get_contents($_rs);
            if ($d === "" || $d === false) // aggregate results...
            {
                foreach (unserialize($data[$rid]) as $k => $v)
                {
                    if (($s = &$result[$k]) !== null)
                    {
                        if ($v[0] < $s[0]) $s[0] = $v[0];
                        if ($v[1] > $s[1]) $s[1] = $v[1];
                        $s[2] += $v[2];
                        $s[3] += $v[3];
                    }
                    else $s = $v;
                }
                fclose($_rs);
                unset($data[$rid]);
                unset($streams[$rid]);
            }
            else $data[$rid] .= $d;
        }
    }
}

ksort($result);

echo "{";
foreach ($result as $k => $v)
    printf("%s=%0.1f/%0.1f/%0.1f,", $k, $v[0],
        round($v[2] / $v[3], 1, RoundingMode::PositiveInfinity), $v[1]);
echo chr(8)."}\n";

