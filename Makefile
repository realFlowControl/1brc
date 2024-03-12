measurements.txt:
	php createMeasurements.php 1000000000

average.txt: measurements.txt
	php -d extension=parallel calculateAverage.php 16 > average.txt

average: average.txt

.PHONY: average average.txt
