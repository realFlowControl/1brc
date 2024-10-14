<?php

$file = 'measurements.txt';

// Usage example, passing argument from command line
if ($argc !== 2) {
    echo "Usage: ", __FILE__, " <number of threads>\n";
    exit(1);
}

$threads_cnt = (int) $argv[1];

/**
 * Get the chunks that each thread needs to process with start and end position.
 * These positions are aligned to \n chars (full lines).
 *
 * @return array<int, array{0: int, 1: int}>
 */
function get_file_chunks(string $file, int $cpu_count): array {
    $size = filesize($file);
    $chunk_size = (int) ($size / $cpu_count);

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

/**
 * This function will open the file passed in `$file` and read and process the
 * data from `$chunk_start` to `$chunk_end`.
 *
 * The returned array has the name of the city as the key and an array as the
 * value, containing the min temp in key 0, the max temp in key 1, the sum of
 * all temperatures in key 2 and count of temperatures in key 3.
 *
 * @return array<string, array{0: float, 1: float, 2: float, 3: int}>
 */ 
$process_chunk = function (string $file, int $chunk_start, int $chunk_end): array {
    $stations = [];
    $fp = fopen($file, 'rb');
    fseek($fp, $chunk_start);
    while ($data = fgets($fp)) {
        $chunk_start += strlen($data);
        if ($chunk_start > $chunk_end) {
            break;
        }
        $pos2 = strpos($data, ';');
        $city = substr($data, 0, $pos2);
        $temp = (float)substr($data, $pos2+1, -1);
        if (isset($stations[$city])) {
            $station = &$stations[$city];
            $station[3] ++;
            $station[2] += $temp;
            if ($temp < $station[0]) {
                $station[0] = $temp;
            } elseif ($temp > $station[1]) {
                $station[1] = $temp;
            }
        } else {
            $stations[$city] = [
                $temp,
                $temp,
                $temp,
                1
            ];
        }
    }
    return $stations;
};

$chunks = get_file_chunks($file, $threads_cnt);

$futures = [];

for ($i = 0; $i < $threads_cnt; $i++) {
    $futures[$i] = \parallel\run(
        $process_chunk,
        [
            $file,
            $chunks[$i][0],
            $chunks[$i][1]
        ]
    );
}


$results = [];

for ($i = 0; $i < $threads_cnt; $i++) {
    // `value()` blocks until a result is available, so the main thread waits
    // for the thread to finish
    $chunk_result = $futures[$i]->value();
    foreach ($chunk_result as $city => $measurement) {
        if (isset($results[$city])) {
            $result = &$results[$city];
            $result[2] += $measurement[2];
            $result[3] += $measurement[3];
            if ($measurement[0] < $result[0]) {
                $result[0] = $measurement[0];
            } elseif ($measurement[1] > $result[1]) {
                $result[1] = $measurement[1];
            }
        } else {
            $results[$city] = $measurement;
        }
    }
}

ksort($results);

echo '{', PHP_EOL;
foreach($results as $k=>&$station) {
    echo "\t", $k, '=', $station[0], '/', number_format($station[2]/$station[3], 1), '/', $station[1], ',', PHP_EOL;
}
echo '}', PHP_EOL;
