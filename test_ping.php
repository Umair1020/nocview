<?php
$output = [];
$returnCode = 0;
exec('ping -c 4 103.246.108.50 2>&1', $output, $returnCode);
echo "<pre>";
echo "Return Code: $returnCode\n";
echo "Output:\n";
print_r($output);
echo "</pre>";