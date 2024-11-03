<?php

// Usage example, passing argument from command line
if ($argc !== 2) {
    echo "Usage: ", __FILE__, " <number of processes>\n";
    exit(1);
}

//$time = microtime(true);
$file = 'measurements.txt';
$processCount = (int) $argv[1];
$sharedMemoryKey = ftok(__FILE__, 't'); // Create a unique key
$sharedMemoryId = shmop_open($sharedMemoryKey, 'c', 0644, (1024 * 1024 * $processCount));
$pids = [];

/**
 * Get the chunks that each process needs to process with start and end position.
 * These positions are aligned to \n chars because we use `fgets()` to read
 * which itself reads till a \n character.
 *
 * @return array<int, array{0: int, 1: int}>
 */
function get_file_chunks(string $file, int $cpu_count): array {
    $size = filesize($file);

    if ($cpu_count == 1) {
        $chunk_size = $size;
    } else {
        $chunk_size = (int) ($size / $cpu_count);
    }

    $fp = fopen($file, 'rb');

    $chunks = [];
    $chunk_start = 0;
    while ($chunk_start < $size) {
        $chunk_end = min($size, $chunk_start + $chunk_size);

        if ($chunk_end < $size) {
            fseek($fp, $chunk_end);
            fgets($fp);
            $chunk_end = ftell($fp);
        }

        $chunks[] = [
            $chunk_start,
            $chunk_end
        ];

        $chunk_start = $chunk_end;
    }

    fclose($fp);
    return $chunks;
}


// Function to perform a task in child process
function performTask(string $file, int $chunk_start, int $chunk_end, $processId, $sharedMemoryId): void
{
    $stations = [];
    $fp = fopen($file, 'rb');
    fseek($fp, $chunk_start);

    while (($line = fgets($fp)) !== false && ftell($fp) <= $chunk_end) {
        // Extract city and temperature
        $city = strtok($line, ";");
        $temp = (float) strtok(PHP_EOL);

        // Update the stations array
        if ($station = &$stations[$city]) {
            $station[3]++;           // Increment count
            $station[2] += $temp;    // Add temperature to sum
            if ($temp < $station[0]) {
                $station[0] = $temp; // Update min temperature
            } elseif ($temp > $station[1]) {
                $station[1] = $temp; // Update max temperature
            }
        } else {
            $stations[$city] = [
                $temp,  // Min temperature
                $temp,  // Max temperature
                $temp,  // Sum temperature
                1       // Count
            ];
        }
    }
    $result = '';
    foreach($stations as $k => $station) {
        $result .=  $k . '#' . implode('#', $station) . PHP_EOL;
    }

    shmop_write($sharedMemoryId, $result, $processId * 1024 * 1024);
}

$chunks = get_file_chunks($file, $processCount);

// Fork processes
for ($i = 0; $i < $processCount; $i++) {
    $pid = pcntl_fork();
    if ($pid == -1) {
        die("Could not fork process\n");
    } elseif ($pid) {
        // Parent process
        $pids[] = $pid;
    } else {
        // Child process
        performTask($file, $chunks[$i][0], $chunks[$i][1], $i, $sharedMemoryId);
        exit(0); // Exit child process
    }
}

// Wait for child processes and read results
foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
}

//$finish_parse = microtime(true);

$results = [];
for ($i = 0; $i < $processCount; $i++) {
    $lines = explode(PHP_EOL, shmop_read($sharedMemoryId, $i * 1024 * 1024, 1024 * 1024));

    foreach ($lines as $v) {
        $line = explode('#', $v);
        if (empty($line[1])) {
            continue;
        }
        [$city, $min, $max, $sum, $count] = $line;
        if ($result = &$results[$city]) {
            $result[2] += (float) $sum;
            $result[3] += (int) $count;
            if ($min < $result[0]) {
                $result[0] = $min;
            }
            if ($max > $result[1]) {
                $result[1] = $max;
            }
        } else {
            $results[$city] = [
                (float) $min,
                (float) $max,
                (float) $sum,
                (int) $count,
            ];
        }
    }
}

// Clean up shared memory
shmop_delete($sharedMemoryId);

ksort($results);

echo '{';
foreach($results as $k=>&$station) {
    echo $k, '=', $station[0], '/', number_format($station[2]/$station[3], 1), '/', $station[1], ',';
}
echo '}', PHP_EOL;

//echo sprintf('Finished calculating averages in %.4fs and parsing response in %.4fs, total %.4fs', ($finish_parse - $time), (microtime(true) - $finish_parse), microtime(true) - $time) . PHP_EOL;